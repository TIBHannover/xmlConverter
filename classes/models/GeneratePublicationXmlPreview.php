<?php

/**
 * @file plugins/generic/xmlConverter/classes/models/GeneratePublicationXmlPreview.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class GeneratePublicationXmlPreview
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Summarises the metadata that will be written into the publication XML,
 *        so an editor can spot missing values before generating the file.
 */

namespace APP\plugins\generic\xmlConverter\classes\models;

use APP\facades\Repo;
use APP\plugins\generic\xmlConverter\classes\helpers\Jats;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use PKP\context\Context;
use PKP\facades\Locale;

class GeneratePublicationXmlPreview
{
    private Submission $submission;
    private Publication $publication;
    private ?Context $context;

    public function __construct(Submission $submission, Publication $publication, ?Context $context)
    {
        $this->submission = $submission;
        $this->publication = $publication;
        $this->context = $context;
    }

    /**
     * Returns the preview sections keyed by section name.
     */
    public function execute(): JsonResponse
    {
        return response()->json(['sections' => $this->buildSections()], Response::HTTP_OK);
    }

    /**
     * Builds every preview section in the order they appear in the generated XML.
     */
    public function buildSections(): array
    {
        $datePublished = $this->publication->getData('datePublished');
        $dateSubmitted = $this->submission->getData('dateSubmitted');
        $dateAccepted = $this->getAcceptedDate();
        $copyrightYear = $datePublished ? date('Y', strtotime($datePublished)) : date('Y');

        return [
            'journalMeta' => $this->buildJournalMeta(),
            'articleTitle' => $this->buildArticleTitle(),
            'pubDate' => $this->buildPubDate($datePublished),
            'history' => $this->buildHistory($dateSubmitted, $dateAccepted, $datePublished),
            'permissions' => $this->buildPermissions($copyrightYear),
            'contribGroup' => $this->buildContribGroup(),
        ];
    }

    /**
     * Journal level metadata taken from the context.
     */
    private function buildJournalMeta(): array
    {
        $journalId = $this->context ? trim((string)$this->context->getLocalizedAcronym()) : '';
        $journalTitle = $this->context ? trim((string)$this->context->getLocalizedName()) : '';
        $issn = $this->context ? trim((string)$this->context->getData('onlineIssn')) : '';
        $publisher = $this->context ? trim((string)$this->context->getData('publisherInstitution')) : '';

        return $this->section('journalMeta', array_filter([
            $journalId !== '' ? 'journal-id: ' . $journalId : null,
            $journalTitle !== '' ? 'journal-title: ' . $journalTitle : null,
            $issn !== '' ? 'ISSN: ' . $issn : null,
            $publisher !== '' ? 'Publisher: ' . $publisher : null,
        ]));
    }

    /**
     * The localized article title.
     */
    private function buildArticleTitle(): array
    {
        $articleTitle = trim((string)$this->publication->getLocalizedTitle());

        return $this->section('articleTitle', $articleTitle !== ''
            ? ['article-title: ' . $articleTitle]
            : []);
    }

    /**
     * Publication date and page range.
     */
    private function buildPubDate(?string $datePublished): array
    {
        [$firstPage, $lastPage] = $this->getPageRange();

        return $this->section('pubDate', array_filter([
            $datePublished ? 'date-published: ' . date('Y-m-d', strtotime($datePublished)) : null,
            $firstPage !== null ? 'fpage: ' . $firstPage : null,
            $lastPage !== null ? 'lpage: ' . $lastPage : null,
        ]));
    }

    /**
     * The <history> block dates.
     */
    private function buildHistory(?string $dateSubmitted, ?string $dateAccepted, ?string $datePublished): array
    {
        return $this->section('history', array_filter([
            $dateSubmitted ? 'received: ' . date('Y-m-d', strtotime($dateSubmitted)) : null,
            $dateAccepted ? 'accepted: ' . date('Y-m-d', strtotime($dateAccepted)) : null,
            $datePublished ? 'published: ' . date('Y-m-d', strtotime($datePublished)) : null,
        ]));
    }

    /**
     * Licensing information. Always written, so it is never reported as missing.
     */
    private function buildPermissions(string $copyrightYear): array
    {
        $licenseUrl = trim((string)$this->publication->getData('licenseUrl'))
            ?: trim((string)($this->context ? $this->context->getData('licenseUrl') : ''))
            ?: 'https://creativecommons.org/licenses/by/4.0';

        return [
            'label' => __('plugins.generic.xmlConverter.generate.preview.permissions'),
            'missing' => false,
            'lines' => [
                'license: ' . $licenseUrl,
                'copyright-year: ' . $copyrightYear,
            ],
        ];
    }

    /**
     * One line per contributor, including affiliation and ORCID when present.
     */
    private function buildContribGroup(): array
    {
        $authors = $this->publication->getData('authors');
        $locale = $this->publication->getData('locale') ?: Locale::getLocale();

        $lines = [];
        if ($authors && (is_array($authors) || $authors instanceof \Traversable)) {
            foreach ($authors as $author) {
                $line = trim(
                    $author->getLocalizedGivenName($locale) . ' ' . $author->getLocalizedFamilyName($locale)
                );
                if ($email = $author->getEmail()) {
                    $line .= ' <' . $email . '>';
                }
                if ($affiliation = $author->getLocalizedAffiliationNamesAsString($locale)) {
                    $line .= ' — ' . $affiliation;
                }
                if ($orcid = $author->getOrcid()) {
                    $line .= ' (ORCID: ' . $orcid . ')';
                }
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $this->section('contribGroup', $lines);
    }

    /**
     * Resolves the accepted date using the same rules as the generated XML.
     */
    private function getAcceptedDate(): ?string
    {
        $decisions = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$this->submission->getId()])
            ->getMany();

        return Jats::selectAcceptedDate($decisions);
    }

    /**
     * Extracts the first and last page from the publication page range.
     */
    private function getPageRange(): array
    {
        $pagesRaw = trim((string)$this->publication->getData('pages'));

        if (preg_match('/^(\d+)\s*[-\x{2013}\x{2014}]\s*(\d+)/u', $pagesRaw, $matches)) {
            return [$matches[1], $matches[2]];
        }
        if (preg_match('/^(\d+)/', $pagesRaw, $matches)) {
            return [$matches[1], null];
        }

        return [null, null];
    }

    /**
     * Wraps a set of lines into a labelled section, flagged as missing when empty.
     */
    private function section(string $key, array $lines): array
    {
        return [
            'label' => __('plugins.generic.xmlConverter.generate.preview.' . $key),
            'missing' => empty($lines),
            'lines' => array_values($lines),
        ];
    }
}
