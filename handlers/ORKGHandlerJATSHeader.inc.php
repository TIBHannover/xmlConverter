<?php

class ORKGHandlerJATSHeader
{
    private $dom;
    private $xpath;
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    public function __construct(string $content)
    {
        if (trim($content) === '') {
            throw new RuntimeException('ORKGHandlerJATSHeader: input is empty.');
        }
        $content = str_replace('<!-->X</!-->', '', $content);
        $content = preg_replace('/<!-+>[^<]*<\/!-+>/', '', $content) ?? $content;

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput = true;
        if (!@$this->dom->loadXML($content)) {
            throw new RuntimeException('ORKGHandlerJATSHeader: input is not valid XML.');
        }
        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('xlink', self::XLINK_NS);
    }

    public function process(): string
    {
        $this->removePlaceholders();
        $this->dropByPath('//article-meta/article-id');
        $this->dropByPath('//article-meta/permissions');
        $this->dropByPath('//article-meta/pub-date');
        $this->dropByPath('//article-meta/history');
        $this->normalizeAuthorNames();
        $this->ensureJournalMetaFirst();
        $this->ensureBackElement();
        return $this->dom->saveXML();
    }

    private function removePlaceholders(): void
    {
        foreach ($this->xpath->query('//comment()') ?: [] as $c) {
            $v = trim($c->nodeValue);
            if ($v === '' || $v === 'X' || preg_match('/^[<>!\-\sX]+$/', $v)) {
                $c->parentNode->removeChild($c);
            }
        }
        foreach ($this->xpath->query('//contrib-group/text()') ?: [] as $t) {
            if (trim($t->nodeValue) === 'X') $t->parentNode->removeChild($t);
        }
    }

    private function dropByPath(string $path): void
    {
        foreach ($this->xpath->query($path) ?: [] as $n) {
            $n->parentNode->removeChild($n);
        }
    }

    private function normalizeAuthorNames(): void
    {
        foreach ($this->xpath->query('//contrib-group/contrib/string-name') ?: [] as $sn) {
            $label = trim($sn->textContent ?? '');
            if ($label === '') continue;

            $surname = $given = null;
            if (strpos($label, ',') !== false) {
                [$surname, $given] = array_map('trim', explode(',', $label, 2));
            } else {
                $parts = preg_split('/\s+/', $label);
                if (count($parts) >= 2) {
                    $surname = array_pop($parts);
                    $given = implode(' ', $parts);
                }
            }
            if (!$surname || !$given) continue;

            $name = $this->dom->createElement('name');
            $name->appendChild($this->dom->createElement('surname', $surname));
            $name->appendChild($this->dom->createElement('given-names', $given));
            $sn->parentNode->replaceChild($name, $sn);
        }
    }

    private function ensureJournalMetaFirst(): void
    {
        $front = $this->xpath->query('//front')->item(0);
        if (!$front) throw new RuntimeException('ORKGHandlerJATSHeader: missing <front>.');
        $articleMeta = $this->xpath->query('./article-meta', $front)->item(0);
        if (!$articleMeta) throw new RuntimeException('ORKGHandlerJATSHeader: missing <article-meta>.');
        $journalMeta = $this->xpath->query('./journal-meta', $front)->item(0);
        if ($journalMeta && $journalMeta->previousSibling !== null) {
            $front->insertBefore($journalMeta, $articleMeta);
        }
    }

    private function ensureBackElement(): void
    {
        if ($this->xpath->query('//back')->length === 0) {
            $article = $this->xpath->query('/article')->item(0);
            if ($article) $article->appendChild($this->dom->createElement('back'));
        }
    }
}
