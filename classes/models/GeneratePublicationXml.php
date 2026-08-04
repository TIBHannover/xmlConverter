<?php

/**
 * @file plugins/generic/xmlConverter/classes/models/GeneratePublicationXml.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class GeneratePublicationXml
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Generate a publication ready JATS XML file from an existing file.
 */

namespace APP\plugins\generic\xmlConverter\classes\models;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\plugins\generic\xmlConverter\classes\helpers\Jats;
use APP\plugins\generic\xmlConverter\classes\helpers\JatsHeaderCleaner;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use PKP\config\Config;
use PKP\submissionFile\SubmissionFile;

class GeneratePublicationXml
{
    private Submission $submission;
    private Publication $publication;
    private SubmissionFile $submissionFile;

    private array $fields = [
        'datePublishedOverride' => '',
        'licenseUrlOverride' => ''
    ];

    public function __construct(array $params, Submission $submission, Publication $publication, SubmissionFile $submissionFile)
    {
        $this->submission = $submission;
        $this->publication = $publication;
        $this->submissionFile = $submissionFile;

        foreach ($this->fields as $key => $value) {
            $newValue = strip_tags($params[$key] ?? '');
            $this->fields[$key] = empty($newValue) ? '' : $newValue;
        }
    }

    /**
     * Builds the publication XML and stores it as a new production ready submission file.
     */
    public function execute(): JsonResponse
    {
        $request = Application::get()->getRequest();
        $context = $request->getJournal();

        if (!Services::get('file')->fs->fileExists($this->submissionFile->getData('path'))) {
            return response()->json([
                'error' => __('plugins.generic.xmlConverter.error.fileNotFound')
            ], Response::HTTP_NOT_FOUND);
        }

        $sourceContent = Services::get('file')->fs->read($this->submissionFile->getData('path'));

        try {
            $cleanedXml = (new JatsHeaderCleaner($sourceContent))->process();
        } catch (Exception $e) {
            return response()->json([
                'error' => __('plugins.generic.xmlConverter.generate.error.cleaning', ['msg' => $e->getMessage()])
            ], Response::HTTP_BAD_REQUEST);
        }

        $origDocument = new DOMDocument('1.0', 'UTF-8');
        $origDocument->preserveWhiteSpace = false;
        $origDocument->formatOutput = true;
        if (!@$origDocument->loadXML($cleanedXml)) {
            return response()->json([
                'error' => __('plugins.generic.xmlConverter.generate.error.invalidXml')
            ], Response::HTTP_BAD_REQUEST);
        }

        $datePublished = $this->fields['datePublishedOverride'] ?: $this->publication->getData('datePublished');
        $copyrightYear = $datePublished ? (int)date('Y', strtotime($datePublished)) : (int)date('Y');
        $licenseUrl = $this->fields['licenseUrlOverride'] ?: trim((string)$this->publication->getData('licenseUrl'));

        [$firstPage, $lastPage] = $this->getPageRange();

        Jats::updateJournalMeta($origDocument, $context);
        Jats::updateArticleTitle($origDocument, $this->publication);
        if ($datePublished) {
            Jats::updateJournalMetaPubDate(
                $origDocument, $context, $this->submission, $datePublished, $firstPage, $lastPage
            );
        }
        Jats::updateArticleMetaHistory($origDocument, $this->submission, $datePublished);
        Jats::updateArticleMetaCCBYLicense($origDocument, $context, $copyrightYear, $licenseUrl);
        Jats::updateContribGroup($origDocument, $this->publication);

        $submissionDir = Repo::submissionFile()->getSubmissionDir(
            $this->submission->getData('contextId'), $this->submission->getId()
        );
        $filesDir = Config::getVar('files', 'files_dir') . DIRECTORY_SEPARATOR;

        $tmpFile = tempnam(sys_get_temp_dir(), 'publication-xml-');
        file_put_contents($tmpFile, $origDocument->saveXML());
        $newFileId = Services::get('file')->add(
            $tmpFile, $filesDir . $submissionDir . DIRECTORY_SEPARATOR . uniqid() . '.xml'
        );

        $newSubmissionFile = Repo::submissionFile()->newDataObject();
        $newSubmissionFile->setAllData([
            'fileId' => $newFileId,
            'assocType' => $this->submissionFile->getData('assocType'),
            'assocId' => $this->submissionFile->getData('assocId'),
            'fileStage' => SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
            'mimetype' => 'application/xml',
            'locale' => $this->submissionFile->getData('locale'),
            'genreId' => $this->submissionFile->getData('genreId'),
            'name' => $this->buildPublicationFileName(),
            'submissionId' => $this->submission->getId(),
        ]);
        $newSubmissionFileId = Repo::submissionFile()->add($newSubmissionFile);
        $newSubmissionFile = Repo::submissionFile()->get($newSubmissionFileId);

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        return response()->json($newSubmissionFile->getAllData(), Response::HTTP_OK);
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
     * Builds the localized file name based on the author naming convention.
     */
    private function buildPublicationFileName(): array
    {
        $base = $this->buildAuthorBaseName();
        $names = $this->submissionFile->getData('name');

        $newName = [];
        if (is_array($names)) {
            foreach (array_keys($names) as $locale) {
                $newName[$locale] = $base . '.xml';
            }
        } else {
            $newName[$this->submissionFile->getData('locale')] = $base . '.xml';
        }

        return $newName;
    }

    /**
     * Builds the file base name from the submission id and the author family names.
     */
    private function buildAuthorBaseName(): string
    {
        $submissionId = $this->submission->getId();
        $authors = $this->publication->getData('authors');

        $lastNames = [];
        if ($authors && (is_array($authors) || $authors instanceof \Traversable)) {
            foreach ($authors as $author) {
                $familyName = $this->sanitizeNamePart((string)$author->getLocalizedFamilyName());
                if ($familyName === '') {
                    $familyName = $this->sanitizeNamePart((string)$author->getLocalizedGivenName());
                }
                if ($familyName !== '') {
                    $lastNames[] = $familyName;
                }
            }
        }

        $count = count($lastNames);
        if ($count === 0) {
            return (string)$submissionId;
        }
        if ($count === 1) {
            return $submissionId . '_' . $lastNames[0];
        }
        if ($count === 2) {
            return $submissionId . '_' . $lastNames[0] . '_and_' . $lastNames[1];
        }

        return $submissionId . '_' . $lastNames[0] . '_et_al';
    }

    /**
     * Transliterates and strips a name to filename safe characters.
     */
    private function sanitizeNamePart(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
            if ($transliterated !== false) {
                $name = $transliterated;
            }
        }

        $name = preg_replace('/\s+/', '_', $name);
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', $name);

        return trim($name, '_');
    }
}
