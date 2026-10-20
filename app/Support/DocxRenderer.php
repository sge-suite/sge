<?php

namespace App\Support;

use App\Models\TemplateVersion;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\XMLWriter;
use PhpOffice\PhpWord\Writer\Word2007\Element\TextRun as TextRunWriter;
use Throwable;
use ZipArchive;

class DocxRenderer
{
    public const REVISION = 2;

    public const SUPPORTED_REVISIONS = [1, 2];

    public const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function __construct(private TemplateInspector $inspector) {}

    public function original(TemplateVersion $version): string
    {
        $media = $version->getFirstMedia('template_file');
        $path = $media?->disk === 'local' ? $media->getPath() : null;
        if (($version->validation_report['status'] ?? null) !== 'validated'
            || $path === null || ! is_file($path) || filesize($path) !== $version->file_size
            || hash_file('sha256', $path) !== $version->file_sha256) {
            throw ValidationException::withMessages(['document' => 'O arquivo do template está indisponível ou não corresponde à versão registrada.']);
        }

        return $path;
    }

    /** @return list<string> */
    public function markers(TemplateVersion $version): array
    {
        $report = $this->inspector->inspect($this->original($version));
        if ($report['errors'] !== []) {
            throw ValidationException::withMessages(['document' => 'O template contém marcadores ou recursos que não podem ser usados na geração. Confira o guia de variáveis.']);
        }

        return $report['detected_markers'];
    }

    /**
     * @param  array<string, list<array{text: string, italic?: bool}>>  $replacements
     */
    public function render(TemplateVersion $version, array $replacements, int $revision = self::REVISION): string
    {
        if (! in_array($revision, self::SUPPORTED_REVISIONS, true)) {
            throw ValidationException::withMessages(['document' => 'A revisão de geração deste documento não é suportada.']);
        }
        $markers = $this->markers($version);
        if (array_diff($markers, array_keys($replacements)) !== []) {
            throw ValidationException::withMessages(['document' => 'Existem variáveis sem dados para preencher o documento.']);
        }
        $directory = storage_path('app/private/generated-documents-tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages(['document' => 'Não foi possível preparar o documento temporário.']);
        }
        if (! is_writable($directory)) {
            throw ValidationException::withMessages(['document' => 'O servidor não tem permissão para gravar na pasta de documentos temporários. Solicite a correção ao administrador do sistema.']);
        }
        $path = $directory.'/'.Str::uuid().'.docx';
        $source = new ZipArchive;
        $output = new ZipArchive;
        $sourceOpen = $outputOpen = false;
        try {
            $sourceOpen = $source->open($this->original($version), ZipArchive::RDONLY) === true;
            $outputOpen = $output->open($path, ZipArchive::CREATE | ZipArchive::EXCL) === true;
            if (! $sourceOpen || ! $outputOpen) {
                throw ValidationException::withMessages(['document' => 'Não foi possível abrir o arquivo para geração.']);
            }
            $overrides = $revision >= 2 ? $this->justificationSettings($source) : [];
            $expandedBytes = 0;
            for ($index = 0; $index < $source->numFiles; $index++) {
                $name = $source->getNameIndex($index);
                $content = $source->getFromIndex($index);
                if ($name === false || $content === false) {
                    throw ValidationException::withMessages(['document' => 'Não foi possível ler o template.']);
                }
                $content = $overrides[$name] ?? $content;
                unset($overrides[$name]);
                if (str_starts_with($name, 'word/') && str_ends_with($name, '.xml')) {
                    $content = $this->replaceXml($content, $replacements);
                }
                $expandedBytes += strlen($content);
                if (strlen($content) > 10 * 1024 * 1024 || $expandedBytes > 50 * 1024 * 1024) {
                    throw ValidationException::withMessages(['document' => 'Os dados preenchidos ultrapassam o limite do documento.']);
                }
                if (! $output->addFromString($name, $content) || ! $output->setMtimeName($name, 315532800)) {
                    throw ValidationException::withMessages(['document' => 'Não foi possível escrever o documento.']);
                }
            }
            foreach ($overrides as $name => $content) {
                if (! $output->addFromString($name, $content) || ! $output->setMtimeName($name, 315532800)) {
                    throw ValidationException::withMessages(['document' => 'Não foi possível escrever as configurações do documento.']);
                }
            }
            if (! $output->close()) {
                throw ValidationException::withMessages(['document' => 'Não foi possível concluir o documento.']);
            }
            $outputOpen = false;
            if (! chmod($path, 0600)) {
                throw ValidationException::withMessages(['document' => 'Não foi possível proteger o documento temporário.']);
            }
            clearstatcache(true, $path);
            if (! is_file($path) || filesize($path) === 0 || filesize($path) > 50 * 1024 * 1024) {
                throw ValidationException::withMessages(['document' => 'O documento gerado ultrapassa o limite permitido.']);
            }

            return $path;
        } catch (Throwable $exception) {
            if ($outputOpen) {
                $output->close();
                $outputOpen = false;
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        } finally {
            if ($sourceOpen) {
                $source->close();
            }
        }
    }

    /** @return array<string, string> */
    private function justificationSettings(ZipArchive $source): array
    {
        $xml = $source->getFromName('word/settings.xml');
        $settings = new DOMDocument;
        $settings->loadXML($xml === false ? '<w:settings xmlns:w="'.self::WORD_NS.'"/>' : $xml, LIBXML_NONET);
        $xpath = new DOMXPath($settings);
        $xpath->registerNamespace('w', self::WORD_NS);
        $compat = $this->elements($xpath, '/w:settings/w:compat')[0] ?? null;
        if ($compat === null) {
            $compat = $settings->createElementNS(self::WORD_NS, 'w:compat');
            $settings->documentElement->appendChild($compat);
        }
        $option = $this->elements($xpath, './w:doNotExpandShiftReturn', $compat)[0] ?? null;
        if ($option === null) {
            $option = $settings->createElementNS(self::WORD_NS, 'w:doNotExpandShiftReturn');
            $compat->insertBefore($option, $compat->firstChild);
        }
        $option->setAttributeNS(self::WORD_NS, 'w:val', '1');
        $parts = ['word/settings.xml' => (string) $settings->saveXML()];
        if ($xml !== false) {
            return $parts;
        }
        $relationshipNamespace = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $relationships = new DOMDocument;
        $relationships->loadXML($source->getFromName('word/_rels/document.xml.rels') ?: '<Relationships xmlns="'.$relationshipNamespace.'"/>', LIBXML_NONET);
        $ids = [];
        foreach ($relationships->getElementsByTagNameNS($relationshipNamespace, 'Relationship') as $relationship) {
            $ids[] = $relationship->getAttribute('Id');
        }
        $id = 'sgeDocumentSettings';
        while (in_array($id, $ids, true)) {
            $id .= '_';
        }
        $relationship = $relationships->createElementNS($relationshipNamespace, 'Relationship');
        $relationship->setAttribute('Id', $id);
        $relationship->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings');
        $relationship->setAttribute('Target', 'settings.xml');
        $relationships->documentElement->appendChild($relationship);
        $parts['word/_rels/document.xml.rels'] = (string) $relationships->saveXML();
        $contentTypesXml = $source->getFromName('[Content_Types].xml');
        if ($contentTypesXml === false) {
            throw ValidationException::withMessages(['document' => 'Não foi possível ler os tipos de conteúdo do template.']);
        }
        $contentTypes = new DOMDocument;
        $contentTypes->loadXML($contentTypesXml, LIBXML_NONET);
        $override = $contentTypes->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Override');
        $override->setAttribute('PartName', '/word/settings.xml');
        $override->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml');
        $contentTypes->documentElement->appendChild($override);
        $parts['[Content_Types].xml'] = (string) $contentTypes->saveXML();

        return $parts;
    }

    /** @param array<string, list<array{text: string, italic?: bool}>> $replacements */
    public function replaceXml(string $xml, array $replacements): string
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        if (! $document->loadXML($xml, LIBXML_NONET)) {
            throw ValidationException::withMessages(['document' => 'Não foi possível ler a estrutura do template.']);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NS);
        $changed = false;
        foreach ($this->elements($xpath, '//w:p | //w:t[not(ancestor::w:p)]') as $paragraph) {
            $nodes = [];
            $text = '';
            $candidates = $paragraph->localName === 't'
                ? [$paragraph] : $this->elements($xpath, './/w:t | .//w:delText | .//w:tab | .//w:br', $paragraph);
            foreach ($candidates as $node) {
                if ($paragraph !== $node && $this->ancestor($node, 'p') !== $paragraph) {
                    continue;
                }
                $value = in_array($node->localName, ['tab', 'br'], true) ? "\n" : $node->textContent;
                $nodes[] = ['node' => $node, 'start' => strlen($text), 'length' => strlen($value)];
                $text .= $value;
            }
            preg_match_all('/\$\{([^{}]+)\}/', $text, $matches, PREG_OFFSET_CAPTURE);
            foreach (array_reverse($matches[0], true) as $key => [$marker, $offset]) {
                $expression = $matches[1][$key][0];
                if (! array_key_exists($expression, $replacements)) {
                    throw ValidationException::withMessages(['document' => 'Uma variável do template não pôde ser resolvida.']);
                }
                $affected = array_values(array_filter($nodes, fn (array $entry): bool => $entry['start'] < $offset + strlen($marker)
                    && $offset < $entry['start'] + $entry['length']));
                if ($affected === []) {
                    throw ValidationException::withMessages(['document' => 'Não foi possível localizar um marcador do template.']);
                }
                $first = $affected[0]['node'];
                $lastEntry = $affected[array_key_last($affected)];
                $last = $lastEntry['node'];
                $run = $this->ancestor($first, 'r');
                if (! $run instanceof DOMElement || $first->parentNode !== $run || $run->parentNode === null) {
                    throw ValidationException::withMessages(['document' => 'Um marcador está em uma estrutura de texto não suportada.']);
                }
                $prefix = substr($first->textContent, 0, $offset - $affected[0]['start']);
                $suffix = substr($last->textContent, $offset + strlen($marker) - $lastEntry['start']);
                foreach ($affected as $entry) {
                    $entry['node']->textContent = '';
                }
                $first->textContent = $prefix;
                $first->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $tail = $run->cloneNode(false);
                $properties = $this->elements($xpath, './w:rPr', $run)[0] ?? null;
                if ($properties !== null) {
                    $tail->appendChild($properties->cloneNode(true));
                }
                while ($first->nextSibling !== null) {
                    $tail->appendChild($first->nextSibling);
                }
                if ($first === $last) {
                    $tailText = $first->cloneNode(false);
                    $tailText->textContent = $suffix;
                    $tail->insertBefore($tailText, $properties === null ? $tail->firstChild : $tail->firstChild?->nextSibling);
                } else {
                    $last->textContent = $suffix;
                    $last->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                }
                $next = $run->nextSibling;
                foreach ($replacements[$expression] as $segment) {
                    $style = $properties?->cloneNode(true) ?? $document->createElementNS(self::WORD_NS, 'w:rPr');
                    if (array_key_exists('italic', $segment)) {
                        foreach (['i', 'iCs'] as $property) {
                            foreach ($this->elements($xpath, './w:'.$property, $style) as $existing) {
                                $style->removeChild($existing);
                            }
                            $italic = $document->createElementNS(self::WORD_NS, 'w:'.$property);
                            $italic->setAttributeNS(self::WORD_NS, 'w:val', $segment['italic'] ? '1' : '0');
                            $style->appendChild($italic);
                        }
                    }
                    foreach ($this->textRuns($document, $segment['text']) as $replacement) {
                        $replacement->insertBefore($style->cloneNode(true), $replacement->firstChild);
                        $run->parentNode->insertBefore($replacement, $next);
                    }
                }
                $run->parentNode->insertBefore($tail, $next);
                $changed = true;
            }
        }

        return $changed ? (string) $document->saveXML() : $xml;
    }

    /** @return list<DOMElement> */
    private function textRuns(DOMDocument $document, string $text): array
    {
        $run = new TextRun;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $index => $line) {
            if ($index > 0) {
                $run->addTextBreak();
            }
            $run->addText($line);
        }
        $escaping = Settings::isOutputEscapingEnabled();
        try {
            Settings::setOutputEscapingEnabled(true);
            $writer = new XMLWriter(XMLWriter::STORAGE_MEMORY, null, true);
            (new TextRunWriter($writer, $run, true))->write();
            $fragment = new DOMDocument;
            $fragment->loadXML('<root xmlns:w="'.self::WORD_NS.'">'.$writer->getData().'</root>', LIBXML_NONET);
        } finally {
            Settings::setOutputEscapingEnabled($escaping);
        }
        $runs = [];
        foreach ($fragment->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement) {
                foreach (iterator_to_array($node->childNodes) as $child) {
                    if ($child instanceof DOMElement && $child->namespaceURI === self::WORD_NS && $child->localName === 'rPr') {
                        $node->removeChild($child);
                    }
                }
                $imported = $document->importNode($node, true);
                if ($imported instanceof DOMElement) {
                    $runs[] = $imported;
                }
            }
        }

        return $runs;
    }

    /** @return list<DOMElement> */
    private function elements(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $elements = [];
        foreach ($xpath->query($query, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function ancestor(DOMNode $node, string $name): ?DOMNode
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->namespaceURI === self::WORD_NS && $parent->localName === $name) {
                return $parent;
            }
        }

        return null;
    }
}
