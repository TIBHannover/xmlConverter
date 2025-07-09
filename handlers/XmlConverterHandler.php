<?php

namespace APP\plugins\generic\xmlConverter\handlers;

use APP\core\Services;
use APP\facades\Repo;
use APP\plugins\generic\xmlConverter\XmlConverterPlugin;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\file\PrivateFileManager;
use PKP\plugins\PluginRegistry;

/**
 * Class XmlConverterHandler
 *
 * Handles XML file conversion between TEI and JATS formats.
 * This class integrates with the OJS plugin system and performs XSLT transformations.
 */
class XmlConverterHandler
{
	/** @var XmlConverterPlugin $plugin */
	protected XmlConverterPlugin $plugin;

	/** Constants representing the conversion types. */
	private const CONVERSION_TEI_TO_JATS = 'teiToJats';
	private const CONVERSION_JATS_TO_TEI = 'jatsToTei';

	/**
	 * Mapping of conversion types to their respective XSLT files.
	 *
	 * @var array $styleSheets
	 */
	protected array $styleSheets = [
		self::CONVERSION_TEI_TO_JATS => [
			'TEI-Commons_to_JATS-Publishing.xsl',
		],
		self::CONVERSION_JATS_TO_TEI => [
			'jats_2_commons1.xsl',
			'jats_2_commons2.xsl',
		],
	];

	/**
	 * Initializes the plugin and assigns roles for the allowed methods.
	 *
	 * @return void
	 */
	public function __construct()
	{
		// Load the plugin instance from the plugin registry.
		$this->plugin = PluginRegistry::getPlugin('generic', 'xmlconverterplugin');
	}

    /**
     * Handles the conversion process for XML files.
     *
     * @param int $fileId
     * @param string $convertionType
     * @return JSONMessage
     * @throws \Exception
     */
	public function convert(int $fileId, string $convertionType)
    {
		// Retrieve file information based on the file ID.
		$submissionFile = Repo::submissionFile()
			->getCollector()
			->filterByFileIds([$fileId])
			->getMany()
			->getIterator()
			->current();

		$fileName = $submissionFile->getData('name');
		$genreId = $submissionFile->getData('genreId');
		$locale = $submissionFile->getData('locale');
		$submissionId = $submissionFile->getData('submissionId');
		$submission = Repo::submission()->get($submissionId);
		$contextId = $submission->getData('contextId');
		$fileManager = new PrivateFileManager();
		$filePath = $fileManager->getBasePath() . '/' . $submissionFile->getData('path');

		 // Determine the conversion type based on the requested page.
		switch ($convertionType) {
			case self::CONVERSION_TEI_TO_JATS:
				$newFile = $this->conversion($filePath, self::CONVERSION_TEI_TO_JATS);
				$ext = '-jats.xml';
				break;
			case self::CONVERSION_JATS_TO_TEI:
				$newFile = $this->conversion($filePath, self::CONVERSION_JATS_TO_TEI);
				$ext = '-tei.xml';
				break;
			default:
				return new JSONMessage(false);
		}

		// Prepare the new file metadata and add it to the submission repository.
		$newSubmissionFile = Repo::submissionFile()->dao->newDataObject();
		$newName = [];
		if (is_array($fileName)) {
			foreach ($fileName as $localeKey => $name) {
				$newName[$localeKey] = pathinfo($name)['filename'] . $ext;
			}
		} else {
			$newName[$locale] = pathinfo($fileName)['filename'] . $ext;
		}

		$submissionDir = Repo::submissionFile()->getSubmissionDir($contextId, $submissionId);
		$newFileId = Services::get('file')->add($newFile, $submissionDir . DIRECTORY_SEPARATOR . uniqid() . '.xml');
		$newSubmissionFile->setAllData([
			'fileId' => $newFileId,
			'assocType' => $submissionFile->getData('assocType'),
			'assocId' => $submissionFile->getData('assocId'),
			'fileStage' => $submissionFile->getData('fileStage'),
			'mimetype' => 'application/xml',
			'locale' => $locale,
			'genreId' => $genreId,
			'name' => $newName,
			'submissionId' => $submissionId,
		]);
		$newSubmissionFile = Repo::submissionFile()->add($newSubmissionFile);
        return new JSONMessage(false);
	}

	/**
	 * Executes the XSLT conversion process for the given input file.
	 *
	 * @param string $filePath   Path to the input XML file.
	 * @param string $conversion Type of conversion to perform.
	 *
	 * @return string Path to the final transformed XML file.
	 *
	 * @throws \RuntimeException If the transformation process fails.
	 */
	private function conversion(string $filePath, string $conversion): string
	{
		try {
			$tmpInput = $filePath;
			$tmpOutput = null;

			// Apply each XSLT stylesheet in sequence
			foreach ($this->styleSheets[$conversion] as $index => $stylesheet) {
				$tmpOutput = tempnam(sys_get_temp_dir(), $conversion);
				$command = $this->command($tmpInput, $tmpOutput, 'xslt/' . $conversion . '/' . $stylesheet);
				exec($command, $output, $result);

				if ($result !== 0) {
					error_log("Command failed: " . implode("\n", $output));
					throw new \RuntimeException("XSLT transformation failed.");
				}

				// Delete the previous temporary file, except for the initial input
				if ($index > 0 && file_exists($tmpInput)) {
					unlink($tmpInput);
				}

				// The output file becomes the input for the next transformation
				$tmpInput = $tmpOutput;
			}
		} catch (\RuntimeException $e) {
			throw new \RuntimeException("Conversion failed: " . $e->getMessage());
		}

		return $tmpOutput;
	}

	/**
	 * Constructs a shell command for an XSLT transformation.
	 *
	 * @param string $input  Path to the input file.
	 * @param string $output Path to the output file.
	 * @param string $xslt   Path to the XSLT stylesheet.
	 *
	 * @return string
	 */
	private function command(string $input, string $output, string $xslt): string
	{
		$pluginPath = Core::getBaseDir() . '/' . $this->plugin->getPluginPath();

		return sprintf(
			"cd %s && java -jar %s/bin/saxon-he-10.6.jar %s %s/%s -o:%s",
			$pluginPath,
			$pluginPath,
			$input,
			$pluginPath,
			$xslt,
			$output,
		);
	}
}

if (!PKP_STRICT_MODE) {
	// Allow legacy aliasing for backward compatibility
    class_alias('\APP\plugins\generic\xmlConverter\handlers\XmlConverterHandler', '\XmlConverterHandler');
}
