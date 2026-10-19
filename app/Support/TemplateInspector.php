<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** @phpstan-type Inspection array{status: string, inspector_revision: int, catalog_revision: int, errors: list<string>, warnings: list<string>, entries: int, expanded_bytes: int, detected_variables: list<string>, detected_markers: list<string>} */
class TemplateInspector
{
    public const REVISION = 6;

    public const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_EXPANDED_BYTES = 50 * 1024 * 1024;

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function __construct(private TemplateVariableCatalog $catalog) {}

    public static function errorLabel(string $code): string
    {
        return match ($code) {
            'unknown_unavailable_or_incompatible_variable' => 'O documento usa variáveis desconhecidas ou ainda reservadas. Confira os nomes disponíveis no Guia de variáveis e corrija o arquivo antes de reenviar.',
            'invalid_placeholder_syntax' => 'Uma ou mais variáveis estão escritas em um formato inválido. Use ${NOME_DA_VARIAVEL} ou ${NOME_DA_VARIAVEL|MODIFICADOR}, conforme o Guia de variáveis.',
            'unsupported_placeholder_location' => 'Há variáveis em uma área do documento que o sistema não consegue preencher. Coloque-as no texto de parágrafos, tabelas, notas, cabeçalhos ou rodapés.',
            'detected_variables_changed' => 'As variáveis do original armazenado diferem das recebidas no upload.',
            'stored_file_invalid' => 'O original armazenado falhou na inspeção de segurança ou na verificação de identidade.',
            default => 'A inspeção identificou um erro que impede o envio do arquivo.',
        };
    }

    /**
     * @return Inspection
     */
    public function inspect(string $path): array
    {
        $this->ensure(is_file($path) && filesize($path) > 0 && filesize($path) <= self::MAX_BYTES, 'O arquivo está vazio ou ultrapassa o limite de 10 MiB. Selecione um DOCX com conteúdo e dentro desse limite.');
        $this->ensure((new \finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'O arquivo não foi reconhecido como um documento DOCX. Abra-o no Word ou LibreOffice e salve uma cópia no formato .docx; renomear a extensão não é suficiente.');
        $handle = fopen($path, 'rb');
        $this->ensure($handle !== false, 'Não foi possível ler o arquivo enviado. Selecione-o novamente e tente reenviar.');
        try {
            $this->ensure(fread($handle, 4) === "PK\x03\x04", 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
        } finally {
            fclose($handle);
        }
        $zip = new ZipArchive;
        $this->ensure($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) === true, 'Não foi possível abrir o documento. Ele pode estar danificado ou protegido por senha. Salve uma nova cópia em .docx sem senha e envie novamente.');
        try {
            return $this->inspectPackage($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return Inspection
     */
    private function inspectPackage(ZipArchive $zip): array
    {
        $this->ensure($zip->numFiles > 0 && $zip->numFiles <= 1000, 'O documento está vazio ou contém uma quantidade de recursos internos acima do limite aceito. Salve uma cópia simplificada em .docx e tente novamente.');
        $parts = [];
        $folded = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $this->ensure(is_array($stat), 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
            $name = $stat['name'];
            $this->ensure(preg_match('~\A(?:\[Content_Types\]\.xml|[a-zA-Z0-9_][a-zA-Z0-9_. /-]*)\z~D', $name) === 1
                && ! str_contains($name, '//') && ! preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $name), 'O documento contém uma estrutura interna que esta versão do sistema não aceita. Salve uma nova cópia em .docx no Word ou LibreOffice; se o erro continuar, peça ao suporte para verificar o arquivo.');
            $this->ensure(! isset($folded[strtolower($name)]), 'O documento contém partes internas duplicadas. Salve uma nova cópia em .docx e tente novamente.');
            $folded[strtolower($name)] = true;
            $this->ensure($stat['encryption_method'] === ZipArchive::EM_NONE
                && in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true), 'O documento está protegido por senha ou foi salvo com uma compactação que o sistema não aceita. Salve uma cópia em .docx sem senha e tente novamente.');
            $opsys = $attributes = 0;
            $this->ensure($zip->getExternalAttributesIndex($index, $opsys, $attributes), 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
            $this->ensure(($attributes >> 16 & 0170000) !== 0120000, 'O documento contém uma estrutura interna que esta versão do sistema não aceita. Salve uma nova cópia em .docx no Word ou LibreOffice; se o erro continuar, peça ao suporte para verificar o arquivo.');
            $total += $stat['size'];
            $this->ensure($stat['size'] <= self::MAX_BYTES && $total <= self::MAX_EXPANDED_BYTES, 'O documento contém imagens ou outros recursos que ultrapassam o limite interno de tamanho, mesmo que o arquivo tenha menos de 10 MiB. Reduza esses recursos e envie uma nova cópia.');
            if (str_ends_with($name, '/')) {
                $this->ensure($stat['size'] === 0, 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');

                continue;
            }
            $this->ensure($this->allowedPart($name), $this->unsupportedPartMessage($name));
            $data = $zip->getFromIndex($index, $stat['size'] + 1);
            $this->ensure(is_string($data) && strlen($data) === $stat['size']
                && sprintf('%u', crc32($data)) === sprintf('%u', $stat['crc']), 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
            $parts[$name] = $data;
        }
        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml'] as $requiredPart) {
            $this->ensure(isset($parts[$requiredPart]), 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
        }
        $fontKeys = $this->fontKeys($parts);
        $variables = [];
        $errors = [];
        foreach ($parts as $name => $data) {
            if (preg_match('~\Aword/fonts/[^/]+\.(?:odttf|ttf|otf)\z~i', $name)) {
                $this->validateFont($name, $data, $fontKeys);

                continue;
            }
            if (preg_match('/\.(png|jpe?g|gif)$/i', $name)) {
                $image = @getimagesizefromstring($data);
                $this->ensure(is_array($image) && in_array($image['mime'], ['image/png', 'image/jpeg', 'image/gif'], true), 'O documento contém uma imagem danificada ou em formato não aceito. Substitua as imagens por PNG, JPEG ou GIF e envie uma nova cópia.');

                continue;
            }
            $document = $this->xml($data);
            $outside = new DOMXPath($document);
            $outside->registerNamespace('w', self::WORD_NS);
            foreach ($this->nodes($outside, '//text()[not(ancestor::w:p) and not(parent::w:t)] | //@*') as $node) {
                if (str_contains($node->textContent, '${') || str_contains($node->textContent, '{{')) {
                    $errors[] = 'unsupported_placeholder_location';
                }
            }
            if ($name === '[Content_Types].xml') {
                $this->contentTypes($document, $parts);
            } elseif (str_ends_with($name, '.rels')) {
                $this->relationships($name, $document, $parts);
            } elseif (str_starts_with($name, 'word/')) {
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('w', self::WORD_NS);
                $this->ensure($name !== 'word/document.xml' || ($document->documentElement?->localName === 'document'
                    && $document->documentElement->namespaceURI === self::WORD_NS && count($this->nodes($xpath, '/w:document/w:body')) === 1), 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
                $this->ensure(count($this->nodes($xpath, '//w:object | //w:control | //w:altChunk | //w:instrText | //w:fldSimple')) === 0, 'O documento contém campos automáticos ou objetos incorporados que o sistema ainda não aceita. Converta os campos em texto comum e substitua objetos, como planilhas anexadas, por tabelas ou imagens.');
                foreach ($this->nodes($xpath, '//w:p') as $paragraph) {
                    $text = '';
                    foreach ($this->nodes($xpath, './/w:t | .//w:delText | .//w:tab | .//w:br', $paragraph) as $node) {
                        $ancestor = $node->parentNode;
                        while ($ancestor !== null && ! ($ancestor->namespaceURI === self::WORD_NS && $ancestor->localName === 'p')) {
                            $ancestor = $ancestor->parentNode;
                        }
                        if ($ancestor === $paragraph) {
                            $text .= in_array($node->localName, ['tab', 'br'], true) ? "\n" : $node->textContent;
                        }
                    }
                    $this->markers($text, $variables, $errors);
                }
                foreach ($this->nodes($xpath, '//w:t[not(ancestor::w:p)]') as $node) {
                    $this->markers($node->textContent, $variables, $errors);
                }
            }
        }
        $this->ensure($this->hasMainRelationship($this->xml($parts['_rels/.rels'])), 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
        $allowed = $this->catalog->available();
        $detected = [];
        $markers = [];
        foreach (array_keys($variables) as $expression) {
            $parsed = TemplateTextFormatter::parse($expression);
            if ($parsed === null || ! isset($allowed[$parsed['name']])) {
                $errors[] = 'unknown_unavailable_or_incompatible_variable';
            } else {
                $detected[] = $parsed['name'];
                $markers[] = $expression;
            }
        }
        $detected = array_values(array_unique($detected));
        sort($markers);
        sort($detected);

        return ['status' => $errors === [] ? 'validated' : 'invalid', 'inspector_revision' => self::REVISION,
            'catalog_revision' => TemplateVariableCatalog::REVISION, 'errors' => array_values(array_unique($errors)),
            'warnings' => [], 'entries' => $zip->numFiles, 'expanded_bytes' => $total,
            'detected_variables' => $detected, 'detected_markers' => $markers];
    }

    private function unsupportedPartMessage(string $name): string
    {
        if (str_starts_with($name, 'word/embeddings/')) {
            return 'O documento contém um arquivo anexado dentro dele, como uma planilha ou outro documento. Esta versão do sistema não aceita esses anexos. Substitua-os por texto, tabelas ou imagens e envie uma nova cópia em .docx.';
        }
        if (preg_match('~(?:^|/)(?:vba[^/]*|activeX)(?:/|\.)~i', $name)) {
            return 'O documento contém recursos de macros ou controles que o sistema não aceita. Remova esses recursos e salve uma cópia em .docx sem macros.';
        }

        return 'O documento contém um recurso que esta versão do sistema não aceita. Use imagens PNG, JPEG ou GIF, remova arquivos anexados e salve uma nova cópia em .docx. Se o erro continuar, peça ao suporte para verificar o arquivo.';
    }

    private function allowedPart(string $name): bool
    {
        return $name === '[Content_Types].xml'
            || (preg_match('~\A(?:_rels/\.rels|(?:word|docProps|customXml)/[a-zA-Z0-9_. /-]+\.(?:xml|rels))\z~D', $name) === 1)
            || (preg_match('~\Aword/fonts/[a-zA-Z0-9_. -]+\.(?:odttf|ttf|otf)\z~iD', $name) === 1)
            || (preg_match('~\Aword/media/[a-zA-Z0-9_. -]+\.(?:png|jpe?g|gif)\z~iD', $name) === 1);
    }

    private function xml(string $data): DOMDocument
    {
        $this->ensure(! preg_match('/<!DOCTYPE|<!ENTITY/i', $data) && ! str_contains($data, "\0"), 'O documento contém uma estrutura interna que esta versão do sistema não aceita. Salve uma nova cópia em .docx no Word ou LibreOffice; se o erro continuar, peça ao suporte para verificar o arquivo.');
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $this->ensure($document->loadXML($data, LIBXML_NONET | LIBXML_COMPACT)
                && $document->doctype === null && $document->documentElement !== null, 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
            $xpath = new DOMXPath($document);
            $this->ensure(count($this->nodes($xpath, '//processing-instruction() | //*[namespace-uri()="http://www.w3.org/2001/XInclude"]')) === 0, 'O documento contém uma estrutura interna que esta versão do sistema não aceita. Salve uma nova cópia em .docx no Word ou LibreOffice; se o erro continuar, peça ao suporte para verificar o arquivo.');

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array<string, string> $parts */
    private function contentTypes(DOMDocument $document, array $parts): void
    {
        $this->ensure($document->documentElement?->namespaceURI === 'http://schemas.openxmlformats.org/package/2006/content-types'
            && $document->documentElement->localName === 'Types', 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
        $main = false;
        foreach ($document->documentElement->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $mime = $node->getAttribute('ContentType');
            $this->ensure(in_array($mime, ['application/xml', 'text/xml', 'application/vnd.openxmlformats-package.relationships+xml',
                'application/vnd.openxmlformats-package.core-properties+xml', 'image/png', 'image/jpeg', 'image/gif',
                'application/vnd.openxmlformats-officedocument.obfuscatedFont', 'application/x-font-ttf', 'application/x-fontdata', 'font/ttf', 'font/otf'], true)
                || preg_match('~\Aapplication/vnd\.openxmlformats-officedocument\.(?:wordprocessingml\.(?:document\.main|styles|numbering|settings|webSettings|fontTable|header|footer|footnotes|endnotes|comments)|theme|extended-properties|custom-properties|customXmlProperties)\+xml\z~D', $mime) === 1, 'O documento contém uma estrutura interna que esta versão do sistema não aceita. Salve uma nova cópia em .docx no Word ou LibreOffice; se o erro continuar, peça ao suporte para verificar o arquivo.');
            if ($node->getAttribute('PartName') === '/word/document.xml') {
                $main = $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
            }
            if ($node->localName === 'Override') {
                $this->ensure(isset($parts[ltrim($node->getAttribute('PartName'), '/')]), 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
            }
        }
        $this->ensure($main, 'O arquivo não foi reconhecido como um DOCX sem macros. Salve-o no formato Documento do Word (.docx), sem macros, e tente novamente.');
    }

    /** @param array<string, string> $parts */
    private function relationships(string $name, DOMDocument $document, array $parts): void
    {
        $this->ensure($document->documentElement?->namespaceURI === self::REL_NS
            && $document->documentElement->localName === 'Relationships', 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
        $ids = [];
        foreach ($document->documentElement->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $target = $node->getAttribute('Target');
            $type = $node->getAttribute('Type');
            $id = $node->getAttribute('Id');
            $this->ensure($id !== '' && ! isset($ids[$id]) && $node->localName === 'Relationship'
                && $node->namespaceURI === self::REL_NS, 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
            $ids[$id] = true;
            $this->ensure(! preg_match('~/(?:oleObject|package|attachedTemplate|vba[^/]*|activeX[^/]*|control|aFChunk)\z~i', $type), 'O documento contém objetos ou recursos automáticos que o sistema não aceita. Remova arquivos anexados, macros e modelos externos antes de reenviar.');
            if ($node->getAttribute('TargetMode') === 'External') {
                $this->ensure($type === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink', 'O documento carrega uma imagem, fonte ou outro recurso de um arquivo externo. Insira esse recurso no próprio DOCX antes de reenviar. Links comuns para sites e e-mails são permitidos.');
                $this->ensure($this->allowedHyperlink($target), 'O documento contém um link com endereço inválido ou não aceito. Use links HTTP, HTTPS ou de e-mail; links para arquivos locais e comandos não são permitidos.');

                continue;
            }
            $this->ensure(in_array($node->getAttribute('TargetMode'), ['', 'Internal'], true), 'O documento contém uma referência em formato não aceito. Salve uma nova cópia em .docx e tente novamente.');
            $resolved = $this->internalTarget($name, $target);
            $this->ensure(isset($parts[$resolved]), 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
        }
    }

    private function allowedHyperlink(string $target): bool
    {
        if ($target === '' || preg_match('/[\x00-\x20\x7f]/', $target)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return filter_var($target, FILTER_VALIDATE_URL) !== false;
        }

        return $scheme === 'mailto'
            && filter_var(rawurldecode((string) parse_url($target, PHP_URL_PATH)), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function internalTarget(string $name, string $target): string
    {
        $this->ensure($target !== '' && ! preg_match('~[\\\\:%?#\x00-\x20]~', $target)
            && ! str_starts_with($target, '/'), 'O documento contém uma referência a um arquivo em um local que o sistema não aceita. Remova os vínculos externos e envie uma nova cópia em .docx.');
        $base = $name === '_rels/.rels' ? [] : explode('/', substr($name, 0, (int) strrpos($name, '/_rels/')));
        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                $this->ensure($base !== [], 'O documento tenta acessar um arquivo fora do próprio DOCX. Remova os vínculos externos antes de reenviar.');
                array_pop($base);
            } elseif ($segment !== '.') {
                $base[] = $segment;
            }
        }

        return implode('/', $base);
    }

    /**
     * @param  array<string, string>  $parts
     * @return array<string, string>
     */
    private function fontKeys(array $parts): array
    {
        if (! isset($parts['word/fontTable.xml'], $parts['word/_rels/fontTable.xml.rels'])) {
            return [];
        }
        $relationships = $this->xml($parts['word/_rels/fontTable.xml.rels']);
        $targets = [];
        foreach ($relationships->getElementsByTagNameNS(self::REL_NS, 'Relationship') as $relationship) {
            if ($relationship->getAttribute('Type') === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/font'
                && $relationship->getAttribute('TargetMode') !== 'External') {
                $targets[$relationship->getAttribute('Id')] = $this->internalTarget('word/_rels/fontTable.xml.rels', $relationship->getAttribute('Target'));
            }
        }
        $xpath = new DOMXPath($this->xml($parts['word/fontTable.xml']));
        $xpath->registerNamespace('w', self::WORD_NS);
        $keys = [];
        foreach ($this->nodes($xpath, '//w:embedRegular | //w:embedBold | //w:embedItalic | //w:embedBoldItalic') as $node) {
            if ($node instanceof DOMElement) {
                $id = $node->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if (isset($targets[$id])) {
                    $keys[$targets[$id]] = $node->getAttributeNS(self::WORD_NS, 'fontKey');
                }
            }
        }

        return $keys;
    }

    /** @param array<string, string> $fontKeys */
    private function validateFont(string $name, string $data, array $fontKeys): void
    {
        $message = 'Uma fonte incorporada está danificada ou em formato não aceito. Salve uma nova cópia em .docx; se necessário, desative a incorporação de fontes nas opções de salvamento.';
        $this->ensure(strlen($data) >= 32, $message);
        $header = substr($data, 0, 32);
        if (str_ends_with(strtolower($name), '.odttf')) {
            $key = $fontKeys[$name] ?? '';
            $this->ensure(preg_match('/\A\{?[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\}?\z/i', $key) === 1, $message);
            $bytes = hex2bin(str_replace(['{', '}', '-'], '', $key));
            $this->ensure($bytes !== false, $message);
            $header = $header ^ str_repeat(strrev($bytes), 2);
        }
        $this->ensure(in_array(substr($header, 0, 4), ["\x00\x01\x00\x00", 'OTTO', 'ttcf', 'true'], true), $message);
    }

    private function hasMainRelationship(DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagNameNS(self::REL_NS, 'Relationship') as $node) {
            if ($node->getAttribute('Type') === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument'
                && $node->getAttribute('Target') === 'word/document.xml') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, true>  $variables
     * @param  list<string>  $errors
     */
    private function markers(string $text, array &$variables, array &$errors): void
    {
        preg_match_all('/\$\{([^{}]*)\}/u', $text, $matches);
        foreach ($matches[1] as $expression) {
            if (TemplateTextFormatter::parse($expression) === null) {
                $errors[] = 'invalid_placeholder_syntax';
            } else {
                $variables[$expression] = true;
            }
        }
        $remaining = preg_replace('/\$\{[^{}]*\}/u', '', $text);
        if (str_contains($remaining, '${') || str_contains($remaining, '{{') || str_contains($remaining, '}}')
            || preg_match('/\$\s+\{/', $remaining)) {
            $errors[] = 'invalid_placeholder_syntax';
        }
    }

    /** @return list<DOMNode> */
    private function nodes(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $nodes = $xpath->query($query, $context);
        $this->ensure($nodes !== false, 'O documento não pôde ser lido corretamente. Abra-o no Word ou LibreOffice, salve uma nova cópia em .docx e tente novamente.');
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMNode) {
                $result[] = $node;
            }
        }

        return $result;
    }

    /** @phpstan-assert true $condition */
    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['file' => $message]);
        }
    }
}
