<?php

namespace App\Support;

use App\Models\TemplateVersion;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class DocumentTemplatePreview
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var array<string, DOMElement> */
    private array $styles = [];

    private ?DOMElement $defaults = null;

    private int $remainingNodes = 5000;

    private int $renderedBytes = 0;

    /** @var array<string, array<string, string>> */
    private array $relationshipCache = [];

    public function __construct(private TemplateInspector $inspector) {}

    /** @return array{header: list<array<string, mixed>>, body: list<array<string, mixed>>, footer: list<array<string, mixed>>, page_style: string} */
    public function build(TemplateVersion $version): array
    {
        $version->loadMissing('media');
        $media = $version->getFirstMedia('template_file');
        if ($media === null || $media->disk !== 'local' || ! is_file($media->getPath())
            || filesize($media->getPath()) !== $version->file_size
            || hash_file('sha256', $media->getPath()) !== $version->file_sha256) {
            $this->fail('O original desta versão não está disponível ou sua integridade foi alterada.');
        }
        $this->inspector->inspect($media->getPath());
        $zip = new ZipArchive;
        if ($zip->open($media->getPath(), ZipArchive::RDONLY) !== true) {
            $this->fail('Não foi possível abrir o original desta versão.');
        }
        try {
            $this->remainingNodes = 5000;
            $this->renderedBytes = 0;
            $this->relationshipCache = [];
            $this->styles = [];
            $this->defaults = null;
            if ($zip->locateName('word/styles.xml') !== false) {
                $styleDocument = $this->xml($zip, 'word/styles.xml');
                $xpath = $this->xpath($styleDocument);
                $this->defaults = $this->first($xpath, '/w:styles/w:docDefaults/w:rPrDefault/w:rPr');
                foreach ($xpath->query('/w:styles/w:style') ?: [] as $style) {
                    if ($style instanceof DOMElement) {
                        $this->styles[$style->getAttributeNS(self::WORD_NS, 'styleId')] = $style;
                    }
                }
            }
            $document = $this->xml($zip, 'word/document.xml');
            $xpath = $this->xpath($document);
            $body = $this->first($xpath, '/w:document/w:body');
            if ($body === null) {
                $this->fail('Não foi possível localizar o conteúdo do template.');
            }
            $relationships = $this->relationships($zip, 'word/document.xml');
            $stories = ['header' => [], 'footer' => []];
            foreach (['header', 'footer'] as $story) {
                $reference = $this->first($xpath, '//w:sectPr/w:'.$story.'Reference[@w:type="default"]')
                    ?? $this->first($xpath, '//w:sectPr/w:'.$story.'Reference');
                $target = $reference === null ? null : ($relationships[$reference->getAttributeNS(self::REL_NS, 'id')] ?? null);
                if ($target !== null) {
                    $root = $this->xml($zip, $target)->documentElement;
                    $stories[$story] = $root === null ? [] : $this->blocks($zip, $target, $root);
                }
            }
            $page = ['width' => '595.3pt', 'min-height' => '841.9pt', 'padding' => '48pt 56pt'];
            $section = $this->first($xpath, '//w:sectPr');
            if ($section !== null) {
                $size = $this->child($section, 'pgSz');
                if ($size !== null) {
                    $page['width'] = $this->points($size->getAttributeNS(self::WORD_NS, 'w'), 20, 300, 1000) ?? $page['width'];
                    $page['min-height'] = $this->points($size->getAttributeNS(self::WORD_NS, 'h'), 20, 300, 1500) ?? $page['min-height'];
                }
            }

            return [...$stories, 'body' => $this->blocks($zip, 'word/document.xml', $body), 'page_style' => $this->css($page)];
        } finally {
            $zip->close();
        }
    }

    /** @return list<array<string, mixed>> */
    private function blocks(ZipArchive $zip, string $part, DOMNode $parent, int $depth = 0): array
    {
        if ($depth > 12) {
            $this->fail('O template é complexo demais para esta prévia. Use o download do original.');
        }
        $blocks = [];
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::WORD_NS) {
                continue;
            }
            $this->consumeNode();
            if ($node->localName === 'p') {
                $properties = $this->child($node, 'pPr');
                $styleId = $this->child($properties, 'pStyle')?->getAttributeNS(self::WORD_NS, 'val') ?? 'Normal';
                $style = $this->runStyle($this->defaults);
                $paragraphStyle = [];
                foreach ($this->styleChain($styleId) as $definition) {
                    $style = [...$style, ...$this->runStyle($this->child($definition, 'rPr'))];
                    $paragraphStyle = [...$paragraphStyle, ...$this->paragraphStyle($this->child($definition, 'pPr'))];
                }
                $style = [...$style, ...$paragraphStyle, ...$this->paragraphStyle($properties)];
                $blocks[] = ['type' => 'paragraph', 'style' => $this->css($style), 'children' => $this->inline($zip, $part, $node)];
            } elseif ($node->localName === 'tbl') {
                $rows = [];
                foreach ($node->childNodes as $row) {
                    if (! $row instanceof DOMElement || $row->localName !== 'tr') {
                        continue;
                    }
                    $cells = [];
                    foreach ($row->childNodes as $cell) {
                        if (! $cell instanceof DOMElement || $cell->localName !== 'tc') {
                            continue;
                        }
                        $properties = $this->child($cell, 'tcPr');
                        $style = [];
                        $fill = $this->child($properties, 'shd')?->getAttributeNS(self::WORD_NS, 'fill') ?? '';
                        if (preg_match('/\A[0-9a-f]{6}\z/i', $fill)) {
                            $style['background-color'] = '#'.$fill;
                        }
                        $width = $this->child($properties, 'tcW');
                        if ($width?->getAttributeNS(self::WORD_NS, 'type') === 'dxa') {
                            $style['width'] = $this->points($width->getAttributeNS(self::WORD_NS, 'w')) ?? 'auto';
                        }
                        $borders = $this->child($properties, 'tcBorders');
                        foreach ($borders === null ? [] : $borders->childNodes as $border) {
                            if ($border instanceof DOMElement && in_array($border->localName, ['top', 'right', 'bottom', 'left'], true)) {
                                $style['border-'.$border->localName] = $this->borderStyle($border);
                            }
                        }
                        $span = (int) ($this->child($properties, 'gridSpan')?->getAttributeNS(self::WORD_NS, 'val') ?? 1);
                        $cells[] = ['type' => 'cell', 'style' => $this->css($style), 'colspan' => max(1, min(20, $span)), 'children' => $this->blocks($zip, $part, $cell, $depth + 1)];
                    }
                    $rows[] = ['type' => 'row', 'style' => '', 'children' => $cells];
                }
                $blocks[] = ['type' => 'table', 'style' => '', 'children' => $rows];
            } elseif (in_array($node->localName, ['sdt', 'sdtContent', 'customXml'], true)) {
                $blocks = [...$blocks, ...$this->blocks($zip, $part, $node, $depth + 1)];
            }
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function inline(ZipArchive $zip, string $part, DOMNode $parent, int $depth = 0): array
    {
        if ($depth > 20) {
            $this->fail('O template é complexo demais para esta prévia. Use o download do original.');
        }
        $runs = [];
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $this->consumeNode();
            if ($node->namespaceURI === self::WORD_NS && $node->localName === 'r') {
                $properties = $this->child($node, 'rPr');
                $style = [];
                $id = $this->child($properties, 'rStyle')?->getAttributeNS(self::WORD_NS, 'val') ?? '';
                foreach ($this->styleChain($id) as $definition) {
                    $style = [...$style, ...$this->runStyle($this->child($definition, 'rPr'))];
                }
                $runs[] = ['type' => 'run', 'style' => $this->css([...$style, ...$this->runStyle($properties)]), 'children' => $this->inline($zip, $part, $node, $depth + 1)];
            } elseif ($node->namespaceURI === self::WORD_NS && $node->localName === 't') {
                $this->consumeBytes(strlen($node->textContent));
                $runs[] = ['type' => 'text', 'style' => '', 'text' => $node->textContent];
            } elseif ($node->namespaceURI === self::WORD_NS && in_array($node->localName, ['br', 'cr'], true)) {
                $runs[] = ['type' => 'break', 'style' => ''];
            } elseif ($node->namespaceURI === self::WORD_NS && $node->localName === 'tab') {
                $runs[] = ['type' => 'text', 'style' => '', 'text' => "\t"];
            } elseif ($node->localName === 'blip' && $node->namespaceURI === 'http://schemas.openxmlformats.org/drawingml/2006/main') {
                $target = $this->relationships($zip, $part)[$node->getAttributeNS(self::REL_NS, 'embed')] ?? null;
                if ($target !== null && preg_match('~\Aword/media/[^/]+\.(?:png|jpe?g|gif)\z~i', $target)) {
                    $data = $this->part($zip, $target);
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
                    if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                        $imageStyle = '';
                        $ancestor = $node->parentNode;
                        while ($ancestor !== null && ! ($ancestor->namespaceURI === self::WORD_NS && $ancestor->localName === 'drawing')) {
                            $ancestor = $ancestor->parentNode;
                        }
                        if ($ancestor instanceof DOMElement) {
                            $extent = $ancestor->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'extent')->item(0);
                            if ($extent instanceof DOMElement) {
                                $imageStyle = 'width:'.($this->points($extent->getAttribute('cx'), 12700, 1, 500) ?? 'auto');
                            }
                        }
                        $source = 'data:'.$mime.';base64,'.base64_encode($data);
                        $this->consumeBytes(strlen($source));
                        $runs[] = ['type' => 'image', 'style' => $imageStyle, 'src' => $source];
                    }
                }
            } elseif (! in_array($node->localName, ['pPr', 'rPr', 'del'], true)) {
                $runs = [...$runs, ...$this->inline($zip, $part, $node, $depth + 1)];
            }
        }

        return $runs;
    }

    /** @return array<string, string> */
    private function runStyle(?DOMElement $properties): array
    {
        $style = [];
        foreach (['b' => 'font-weight', 'i' => 'font-style'] as $element => $property) {
            $node = $this->child($properties, $element);
            if ($node !== null) {
                $on = ! in_array($node->getAttributeNS(self::WORD_NS, 'val'), ['0', 'false', 'off'], true);
                $style[$property] = $on ? ($element === 'b' ? 'bold' : 'italic') : 'normal';
            }
        }
        $size = $this->child($properties, 'sz')?->getAttributeNS(self::WORD_NS, 'val') ?? '';
        if (($points = $this->points($size, 2, 6, 96)) !== null) {
            $style['font-size'] = $points;
        }
        $font = $this->child($properties, 'rFonts')?->getAttributeNS(self::WORD_NS, 'ascii') ?? '';
        if (preg_match('/\A[\pL\pN _,\-]{1,100}\z/u', $font)) {
            $style['font-family'] = $font.', serif';
        }
        $color = $this->child($properties, 'color')?->getAttributeNS(self::WORD_NS, 'val') ?? '';
        if (preg_match('/\A[0-9a-f]{6}\z/i', $color)) {
            $style['color'] = '#'.$color;
        }
        if (($underline = $this->child($properties, 'u')) !== null) {
            $style['text-decoration'] = $underline->getAttributeNS(self::WORD_NS, 'val') === 'none' ? 'none' : 'underline';
        }

        return $style;
    }

    /** @return array<string, string> */
    private function paragraphStyle(?DOMElement $properties): array
    {
        $style = [];
        $alignment = $this->child($properties, 'jc')?->getAttributeNS(self::WORD_NS, 'val') ?? '';
        if (in_array($alignment, ['left', 'right', 'center', 'both', 'start', 'end'], true)) {
            $style['text-align'] = $alignment === 'both' ? 'justify' : $alignment;
        }
        $borders = $this->child($properties, 'pBdr');
        foreach ($borders === null ? [] : $borders->childNodes as $border) {
            if ($border instanceof DOMElement && in_array($border->localName, ['top', 'right', 'bottom', 'left'], true)) {
                $style['border-'.$border->localName] = $this->borderStyle($border);
            }
        }
        $spacing = $this->child($properties, 'spacing');
        foreach (['before' => 'margin-top', 'after' => 'margin-bottom'] as $attribute => $property) {
            $value = $this->points($spacing?->getAttributeNS(self::WORD_NS, $attribute) ?? '');
            if ($value !== null) {
                $style[$property] = $value;
            }
        }
        $indent = $this->child($properties, 'ind');
        foreach (['left' => 'margin-left', 'right' => 'margin-right', 'firstLine' => 'text-indent'] as $attribute => $property) {
            $value = $this->points($indent?->getAttributeNS(self::WORD_NS, $attribute) ?? '');
            if ($value !== null) {
                $style[$property] = $value;
            }
        }

        return $style;
    }

    /** @return list<DOMElement> */
    private function styleChain(string $id): array
    {
        $chain = [];
        while (isset($this->styles[$id]) && count($chain) < 10) {
            $definition = $this->styles[$id];
            $chain[] = $definition;
            $id = $this->child($definition, 'basedOn')?->getAttributeNS(self::WORD_NS, 'val') ?? '';
        }

        return array_reverse($chain);
    }

    /** @return array<string, string> */
    private function relationships(ZipArchive $zip, string $part): array
    {
        if (isset($this->relationshipCache[$part])) {
            return $this->relationshipCache[$part];
        }
        $path = dirname($part).'/_rels/'.basename($part).'.rels';
        if ($zip->locateName($path) === false) {
            return [];
        }
        $targets = [];
        $root = $this->xml($zip, $path)->documentElement;
        foreach ($root === null ? [] : $root->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $segments = explode('/', dirname($part));
            foreach (explode('/', $node->getAttribute('Target')) as $segment) {
                if ($segment === '..') {
                    array_pop($segments);
                } elseif ($segment !== '.') {
                    $segments[] = $segment;
                }
            }
            $targets[$node->getAttribute('Id')] = implode('/', $segments);
        }

        return $this->relationshipCache[$part] = $targets;
    }

    private function xml(ZipArchive $zip, string $part): DOMDocument
    {
        $data = $this->part($zip, $part);
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $data)) {
            $this->fail('Não foi possível ler a estrutura do template com segurança.');
        }
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($data, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->fail('Não foi possível ler a estrutura do template.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function part(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > TemplateInspector::MAX_BYTES) {
            $this->fail('Uma parte do template não está disponível para visualização.');
        }
        $data = $zip->getFromName($name, $stat['size'] + 1);
        if ($data === false) {
            $this->fail('Não foi possível ler uma parte do template.');
        }

        return $data;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NS);

        return $xpath;
    }

    private function first(DOMXPath $xpath, string $query): ?DOMElement
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function child(?DOMElement $parent, string $name): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::WORD_NS && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    private function points(string $value, int $divider = 20, int $minimum = 0, int $maximum = 600): ?string
    {
        return preg_match('/\A\d+\z/', $value) ? (string) max($minimum, min($maximum, (int) $value / $divider)).'pt' : null;
    }

    /** @param array<string, string> $style */
    private function css(array $style): string
    {
        $declarations = [];
        foreach ($style as $property => $value) {
            $declarations[] = $property.':'.$value;
        }

        return implode(';', $declarations);
    }

    private function borderStyle(DOMElement $border): string
    {
        $kind = $border->getAttributeNS(self::WORD_NS, 'val');
        $size = $border->getAttributeNS(self::WORD_NS, 'sz');
        if (in_array($kind, ['nil', 'none'], true) || $size === '0') {
            return 'none';
        }
        $width = $this->points($size, 8, 0, 5) ?? '0.5pt';
        $color = $border->getAttributeNS(self::WORD_NS, 'color');
        $color = preg_match('/\A[0-9a-f]{6}\z/i', $color) ? '#'.$color : '#d9d9d9';
        $line = match ($kind) {
            'dotted' => 'dotted',
            'dashed' => 'dashed',
            'double' => 'double',
            default => 'solid',
        };

        return $width.' '.$line.' '.$color;
    }

    private function consumeBytes(int $bytes): void
    {
        $this->renderedBytes += $bytes;
        if ($this->renderedBytes > 8 * 1024 * 1024) {
            $this->fail('O conteúdo é grande demais para esta prévia. Use o download do original.');
        }
    }

    private function consumeNode(): void
    {
        if (--$this->remainingNodes < 0) {
            $this->fail('O template é grande demais para esta prévia. Use o download do original.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['preview' => $message]);
    }
}
