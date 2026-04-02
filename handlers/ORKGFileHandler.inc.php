<?php

/**
 * @file plugins/generic/xmlConverter/handlers/ORKGFileHandler.inc.php
 *
 * @class ORKGFileHandler
 *
 * @brief Handler for downloading files from ORKG (Open Research Knowledge Graph)
 */

import('plugins.generic.xmlConverter.handlers.ServiceFileHandler');
import('plugins.generic.xmlConverter.handlers.ORKGHandlerJATSHeader');

class ORKGFileHandler extends ServiceFileHandler
{
    private const ORKG_EXPORT_URL = 'https://orkg.org/api/smart-reviews/';
    private const MIME_TYPE = 'text/xml';
    private string $fileType = 'xml';


    public function setAdditionalFileMetadata(array &$metadata): void
    {
        $metadata['mimetype'] = self::MIME_TYPE;
        $metadata['name'] = parent::getServiceFile() . '.' . $this->getFileTyle();

    }

    public function modifyContent(string &$content): void
    {
        import('plugins.generic.xmlConverter.handlers.ORKGHandlerJATSHeader');

        import('plugins.generic.xmlConverter.classes.XMLAmpersandEscaper');
        $content = XMLAmpersandEscaper::escapeAmpersands($content);

        try {
            $processor = new ORKGHandlerJATSHeader($content);
            $content = $processor->process();
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }

    public function cleanServiceFilePath(&$serviceFile): string
    {

        $serviceFile = trim($serviceFile);

        if (empty($serviceFile)) {
            $this->handle404('Empty file path provided.');
        }

        if (filter_var($serviceFile, FILTER_VALIDATE_URL)) {
            $path = parse_url($serviceFile, PHP_URL_PATH);

            if ($path === null) {
                $this->handle404('Invalid URL path: ' . $serviceFile);
            }

            $parts = explode('/', trim($path, '/'));
            $lastPart = end($parts);

            if (preg_match('/^R\d{6}$/', $lastPart)) {  // Assuming 6-digit R numbers
                return $lastPart;
            }

            $this->handle404($serviceFile . ' is not a valid ORKG R-number.');
        }

        return $serviceFile;
    }

    protected function downloadServiceFile(): string
    {
        $tempFile = parent::createTempFilePath();
        $downloadUrl = self::ORKG_EXPORT_URL . parent::getServiceFile();

        if (!$this->downloadFileWithHeaders($downloadUrl, $tempFile, ['Accept: application/xml'])) {
            throw new RuntimeException('Failed to download file from ORKG service. URL: ' . $downloadUrl);
        }

        return $tempFile;
    }

    protected function downloadFileWithHeaders(string $url, string $savePath, array $headers): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $fileContent = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('CURL error: ' . $error . ' URL: ' . $url);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException('HTTP error ' . $httpCode . ' downloading from: ' . $url);
        }

        $this->modifyContent($fileContent);
        $this->writeContentToFile($fileContent, $savePath);

        return true;
    }

    function getFileTyle(): string
    {
        return $this->fileType;
    }



    protected function validateDownloadedFile(string $filePath): void
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            throw new RuntimeException('Downloaded file is invalid or empty');
        }

        if (@simplexml_load_file($filePath) === false) {
            throw new RuntimeException('Downloaded file is not valid XML');
        }
    }

    protected function validateServiceFileId(): void
    {
        $serviceFile = parent::getServiceFile();
        if (!$serviceFile || !preg_match('/^[a-zA-Z0-9_-]+$/', $serviceFile)) {
            throw new InvalidArgumentException('Invalid service file identifier');
        }
    }
}
