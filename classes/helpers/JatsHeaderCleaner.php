<?php

/**
 * @file plugins/generic/xmlConverter/classes/helpers/JatsHeaderCleaner.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class JatsHeaderCleaner
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Strips placeholder metadata from a JATS file before the publication metadata is written.
 */

namespace APP\plugins\generic\xmlConverter\classes\helpers;

use DOMDocument;
use DOMXPath;
use RuntimeException;

class JatsHeaderCleaner
{
    private const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

    private DOMDocument $dom;
    private DOMXPath $xpath;

    public function __construct(string $content)
    {
        if (trim($content) === '') {
            throw new RuntimeException('JatsHeaderCleaner: input is empty.');
        }

        $content = str_replace('<!-->X</!-->', '', $content);
        $content = preg_replace('/<!-+>[^<]*<\/!-+>/', '', $content) ?? $content;

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput = true;
        if (!@$this->dom->loadXML($content)) {
            throw new RuntimeException('JatsHeaderCleaner: input is not valid XML.');
        }

        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('xlink', self::XLINK_NAMESPACE);
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
        foreach ($this->xpath->query('//comment()') ?: [] as $comment) {
            $value = trim($comment->nodeValue);
            if ($value === '' || $value === 'X' || preg_match('/^[<>!\-\sX]+$/', $value)) {
                $comment->parentNode->removeChild($comment);
            }
        }

        foreach ($this->xpath->query('//contrib-group/text()') ?: [] as $text) {
            if (trim($text->nodeValue) === 'X') {
                $text->parentNode->removeChild($text);
            }
        }
    }

    private function dropByPath(string $path): void
    {
        foreach ($this->xpath->query($path) ?: [] as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    private function normalizeAuthorNames(): void
    {
        foreach ($this->xpath->query('//contrib-group/contrib/string-name') ?: [] as $stringName) {
            $label = trim($stringName->textContent ?? '');
            if ($label === '') {
                continue;
            }

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

            if (!$surname || !$given) {
                continue;
            }

            $name = $this->dom->createElement('name');
            $name->appendChild($this->dom->createElement('surname', $surname));
            $name->appendChild($this->dom->createElement('given-names', $given));
            $stringName->parentNode->replaceChild($name, $stringName);
        }
    }

    private function ensureJournalMetaFirst(): void
    {
        $front = $this->xpath->query('//front')->item(0);
        if (!$front) {
            throw new RuntimeException('JatsHeaderCleaner: missing <front>.');
        }

        $articleMeta = $this->xpath->query('./article-meta', $front)->item(0);
        if (!$articleMeta) {
            throw new RuntimeException('JatsHeaderCleaner: missing <article-meta>.');
        }

        $journalMeta = $this->xpath->query('./journal-meta', $front)->item(0);
        if ($journalMeta && $journalMeta->previousSibling !== null) {
            $front->insertBefore($journalMeta, $articleMeta);
        }
    }

    private function ensureBackElement(): void
    {
        if ($this->xpath->query('//back')->length === 0) {
            $article = $this->xpath->query('/article')->item(0);
            if ($article) {
                $article->appendChild($this->dom->createElement('back'));
            }
        }
    }
}
