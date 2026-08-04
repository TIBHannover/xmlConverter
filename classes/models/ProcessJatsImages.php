<?php

/**
 * @file plugins/generic/xmlConverter/classes/models/ProcessJatsImages.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class ProcessJatsImages
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Download images referenced in a JATS file and attach them as dependent files.
 */

namespace APP\plugins\generic\xmlConverter\classes\models;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\submission\Submission;
use DOMDocument;
use DOMXPath;
use Exception;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use PKP\db\DAORegistry;
use PKP\file\PrivateFileManager;
use PKP\submissionFile\SubmissionFile;

class ProcessJatsImages
{
    private const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

    private const VALID_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff', 'bmp', 'svg', 'webp'];

    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/tiff' => 'tif',
        'image/bmp' => 'bmp',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
    ];

    private const EXTENSION_TO_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    private Submission $submission;
    private SubmissionFile $submissionFile;

    public function __construct(Submission $submission, SubmissionFile $submissionFile)
    {
        $this->submission = $submission;
        $this->submissionFile = $submissionFile;
    }

    /**
     * Downloads every referenced graphic and attaches it as a dependent file.
     */
    public function execute(): JsonResponse
    {
        $request = Application::get()->getRequest();
        $fileManager = new PrivateFileManager();
        $filePath = $fileManager->getBasePath() . DIRECTORY_SEPARATOR . $this->submissionFile->getData('path');

        if (!file_exists($filePath)) {
            return response()->json([
                'error' => __('plugins.generic.xmlConverter.error.fileNotFound')
            ], Response::HTTP_NOT_FOUND);
        }

        $origDocument = new DOMDocument();
        if (!@$origDocument->loadXML(file_get_contents($filePath))) {
            return response()->json([
                'error' => __('plugins.generic.xmlConverter.generate.error.invalidXml')
            ], Response::HTTP_BAD_REQUEST);
        }

        $xpath = new DOMXPath($origDocument);
        $xpath->registerNamespace('xlink', self::XLINK_NAMESPACE);
        $graphicNodes = $xpath->query('//graphic[@xlink:href] | //inline-graphic[@xlink:href]');

        $submissionId = $this->submission->getId();
        $contextId = $this->submission->getData('contextId');
        $submissionDir = Repo::submissionFile()->getSubmissionDir($contextId, $submissionId);
        $imageGenreId = $this->getImageGenreId($contextId);
        $parentSubmissionFileId = $this->submissionFile->getId();
        $uploaderUserId = $request->getUser() ? $request->getUser()->getId() : null;

        $downloadedCount = 0;
        $failedDownloads = [];

        foreach ($graphicNodes as $graphicNode) {
            $imageUrl = $graphicNode->getAttributeNS(self::XLINK_NAMESPACE, 'href')
                ?: $graphicNode->getAttribute('xlink:href');

            if (empty($imageUrl)) {
                continue;
            }

            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $failedDownloads[] = ['url' => $imageUrl, 'reason' => 'Relative URL or invalid format'];
                continue;
            }

            $tmpFile = null;

            try {
                $imageContent = $this->downloadImage($imageUrl);
                if ($imageContent === false) {
                    $failedDownloads[] = ['url' => $imageUrl, 'reason' => 'Failed to download'];
                    continue;
                }

                $tmpFile = tempnam(sys_get_temp_dir(), 'jatsimg_');
                file_put_contents($tmpFile, $imageContent);

                $extension = $this->getImageExtension($imageUrl, $imageContent);
                $filename = pathinfo((string)parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
                $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
                if (empty($filename)) {
                    $filename = 'image_' . uniqid();
                }

                $newFileId = Services::get('file')->add(
                    $tmpFile, $submissionDir . DIRECTORY_SEPARATOR . $filename . '.' . $extension
                );

                $newSubmissionFile = Repo::submissionFile()->newDataObject();
                $newSubmissionFile->setAllData([
                    'fileId' => $newFileId,
                    'assocType' => Application::ASSOC_TYPE_SUBMISSION_FILE,
                    'assocId' => $parentSubmissionFileId,
                    'fileStage' => SubmissionFile::SUBMISSION_FILE_DEPENDENT,
                    'mimetype' => self::EXTENSION_TO_MIME[$extension] ?? 'application/octet-stream',
                    'locale' => $this->submissionFile->getData('locale'),
                    'genreId' => $imageGenreId,
                    'name' => [$this->submissionFile->getData('locale') => $filename . '.' . $extension],
                    'submissionId' => $submissionId,
                    'uploaderUserId' => $uploaderUserId,
                ]);
                Repo::submissionFile()->add($newSubmissionFile);

                $downloadedCount++;
            } catch (Exception $e) {
                $failedDownloads[] = ['url' => $imageUrl, 'reason' => $e->getMessage()];
            } finally {
                if ($tmpFile && file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        }

        return response()->json([
            'downloaded' => $downloadedCount,
            'failed' => $failedDownloads,
        ], Response::HTTP_OK);
    }

    /**
     * Resolves the genre used for images, falling back to the source file genre.
     */
    private function getImageGenreId(int $contextId): int
    {
        $genres = DAORegistry::getDAO('GenreDAO')->getByContextId($contextId);

        while ($genre = $genres->next()) {
            $genreKey = (string)$genre->getKey();
            $genreName = $genre->getName(null);

            if (stripos($genreKey, 'image') !== false
                || stripos($genreKey, 'figure') !== false
                || stripos($genreKey, 'artwork') !== false
                || (is_string($genreName) && (stripos($genreName, 'image') !== false || stripos($genreName, 'figure') !== false))) {
                return (int)$genre->getId();
            }
        }

        return (int)$this->submissionFile->getData('genreId');
    }

    /**
     * Downloads an image and returns its content, or false on a non 2xx response.
     *
     * @return string|false
     */
    private function downloadImage(string $url)
    {
        $curlHandle = curl_init($url);
        curl_setopt_array($curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'OJS-XMLConverter-Plugin/1.0',
        ]);

        $result = curl_exec($curlHandle);
        $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        curl_close($curlHandle);

        if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
            return $result;
        }

        return false;
    }

    /**
     * Determines the image extension from the url, falling back to content inspection.
     */
    private function getImageExtension(string $url, string $content): string
    {
        $extension = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($extension, self::VALID_EXTENSIONS)) {
            return $extension;
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        return self::MIME_TO_EXTENSION[$mimeType] ?? 'jpg';
    }
}
