<?php

import('classes.handler.Handler');


class xmlConverterHandler extends Handler
{
	protected object $plugin;

	/** @var Submission */
	public $submission;

	/** @var Publication */
	public $publication;

	protected array $allowedMethods = ['convertToJats', 'convertToTei', 'processJatsImages', 'createGalleyForm', 'createGalley', 'createServiceFileForm', 'generatePublicationXmlForm', 'generatePublicationXml'];

	function __construct()
	{

		parent::__construct();

		$this->plugin = PluginRegistry::getPlugin('generic', 'xmlconverterplugin');;
		$this->addRoleAssignment([ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT, ROLE_ID_SITE_ADMIN, ROLE_ID_AUTHOR], $this->allowedMethods);

	}

	/**
	 * @copydoc PKPHandler::initialize()
	 */
	function initialize($request)
	{
		parent::initialize($request);
		$this->submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
		$this->publication = $this->submission->getLatestPublication();
		$this->setupTemplate($request);
	}

	function authorize($request, &$args, $roleAssignments): bool
	{
		import('lib.pkp.classes.security.authorization.WorkflowStageAccessPolicy');
		$this->addPolicy(new WorkflowStageAccessPolicy($request, $args, $roleAssignments,
			'submissionId', (int)$request->getUserVar('stageId')));
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Get the plugin.
	 * @return xmlConverterPlugin
	 */
	function getPlugin()
	{
		return $this->plugin;
	}

	/**
	 * Create galley form
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage JSON object
	 */
	public function createGalleyForm($args, $request)
	{
		import('plugins.generic.xmlConverter.controllers.grid.form.TextureArticleGalleyForm');
		$galleyForm = new TextureArticleGalleyForm($request, $this->getPlugin(), $this->publication, $this->submission);

		$galleyForm->initData();
		return new JSONMessage(true, $galleyForm->fetch($request));
	}

	/**
	 * @param $args
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	public function createGalley($args, $request)
	{
		import('plugins.generic.xmlConverter.controllers.grid.form.TextureArticleGalleyForm');
		$galleyForm = new TextureArticleGalleyForm($request, $this->getPlugin(), $this->publication, $this->submission);
		$galleyForm->readInputData();

		if ($galleyForm->validate()) {
			$galleyForm->execute();
			return $request->redirectUrlJson($request->getDispatcher()->url(
				$request,
				ROUTE_PAGE,
				null,
				'workflow',
				'access',
				null,
				array(
					'submissionId' => $request->getUserVar('submissionId'),
					'stageId' => $request->getUserVar('stageId')
				)
			));
		}

		return new JSONMessage(false);
	}

	/**
	 * @param $args
	 * @param $request
	 * @return JSONMessage
	 */
	public function createServiceFileForm($args, $request)
	{
		import('plugins.generic.xmlConverter.controllers.grid.form.CreateServiceFileForm');
		$serviceFileForm = new CreateServiceFileForm($request, $this->getPlugin(), $this->publication, $this->submission);

		$serviceFileForm->readInputData();
		if ($serviceFileForm->validate()) {
			$serviceFileForm->execute();

		} else {
			$serviceFileForm->initData();
			return new JSONMessage(true, $serviceFileForm->fetch($request));
		}
	}

	public function extractExecute($args, $request): JSONMessage
	{
		$action = new Extract($this->plugin, $request, $args);

		$action->readInputData();

		$action->process();

		return $request->redirectUrlJson($request->getDispatcher()->url($request, ROUTE_PAGE, null, 'workflow', 'access', null,
			array(
				'submissionId' => $request->getUserVar('submissionId'),
				'stageId' => $request->getUserVar('stageId')
			)
		));
	}

	public function convertToJats($args, $request): JSONMessage
	{
		$fileId = (int)$request->getUserVar('fileId');
		$submissionFiles = Services::get('submissionFile')->getMany([
			'fileIds' => [$fileId],
		]);
		$submissionFile = $submissionFiles->current();


		$submissionId = $submissionFile->getData('submissionId');
		$submission = Services::get('submission')->get($submissionId);

		//TODO move saxon to config.inc.php

		import('lib.pkp.classes.file.PrivateFileManager');
		$fileManager = new PrivateFileManager();
		$filePath = $fileManager->getBasePath() . '/' . $submissionFile->getData('path');
		$pluginPath = Core::getBaseDir() . '/' . $this->plugin->getPluginPath();
		$tmpfname = tempnam(sys_get_temp_dir(), 'tei2jats');
		$xmlConverter = "cd $pluginPath && java -jar  $pluginPath/bin/saxon-he-10.6.jar $filePath $pluginPath/xslt/TEI-Commons_2_TEI-Metopes.xsl -o:$tmpfname";
		shell_exec($xmlConverter);
		$tmpfname2 = tempnam(sys_get_temp_dir(), 'tei2jats2');
		$xmlConverter2 = "cd $pluginPath && java -jar  $pluginPath/bin/saxon-he-10.6.jar $tmpfname $pluginPath/xslt/TEI-Metopes_2_JATS-Publishing1-3.xsl -o:$tmpfname2";
		shell_exec($xmlConverter2);
		$genreId = $submissionFile->getData('genreId');
		// Add new JATS XML file
		$submissionDir = Services::get('submissionFile')->getSubmissionDir($submission->getData('contextId'), $submissionId);
		$newFileId = Services::get('file')->add(
			$tmpfname2,
			$submissionDir . DIRECTORY_SEPARATOR . uniqid() . '.xml'
		);

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$newSubmissionFile = $submissionFileDao->newDataObject();
		$newName = [];
		if (gettype($submissionFile->getData('name')) == 'array') {
			foreach ($submissionFile->getData('name') as $localeKey => $name) {
				$newName[$localeKey] = pathinfo($name)['filename'] . '-jats.xml';
			}
		} else {
			$newName[$submissionFile->getData('locale')] = pathinfo($submissionFile->getData('name'))['filename'] . '-jats.xml';
		}

		$newSubmissionFile->setAllData(
			[
				'fileId' => $newFileId,
				'assocType' => $submissionFile->getData('assocType'),
				'assocId' => $submissionFile->getData('assocId'),
				'fileStage' => $submissionFile->getData('fileStage'),
				'mimetype' => 'application/xml',
				'locale' => $submissionFile->getData('locale'),
				'genreId' => $genreId,
				'name' => $newName,
				'submissionId' => $submissionId,
			]
		);

		$newSubmissionFile = Services::get('submissionFile')->add($newSubmissionFile, $request);

		unlink($tmpfname);

		$json = new JSONMessage(true);
		return $json;
	}

	public function convertToTei($args, $request): JSONMessage
	{
		$fileId = (int)$request->getUserVar('fileId');
		$submissionFiles = Services::get('submissionFile')->getMany([
			'fileIds' => [$fileId],
		]);
		$submissionFile = $submissionFiles->current();


		$submissionId = $submissionFile->getData('submissionId');
		$submission = Services::get('submission')->get($submissionId);

		//TODO move saxon to config.inc.php

		import('lib.pkp.classes.file.PrivateFileManager');
		$fileManager = new PrivateFileManager();
		$filePath = $fileManager->getBasePath() . '/' . $submissionFile->getData('path');
		$pluginPath = Core::getBaseDir() . '/' . $this->plugin->getPluginPath();
		$tmpfname = tempnam(sys_get_temp_dir(), 'jatstotei');
		$xmlConverter = "cd $pluginPath && java -jar  $pluginPath/bin/saxon-he-10.6.jar $filePath $pluginPath/xslt/jats_2_commons1.xsl -o:$tmpfname";
		shell_exec($xmlConverter);
		$tmpfname2 = tempnam(sys_get_temp_dir(), 'tei2jats2');
		$xmlConverter2 = "cd $pluginPath && java -jar  $pluginPath/bin/saxon-he-10.6.jar $tmpfname $pluginPath/xslt/jats_2_commons2.xsl -o:$tmpfname2";
		shell_exec($xmlConverter2);
		$genreId = $submissionFile->getData('genreId');
		// Add new JATS XML file
		$submissionDir = Services::get('submissionFile')->getSubmissionDir($submission->getData('contextId'), $submissionId);
		$newFileId = Services::get('file')->add(
			$tmpfname2,
			$submissionDir . DIRECTORY_SEPARATOR . uniqid() . '.xml'
		);

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$newSubmissionFile = $submissionFileDao->newDataObject();
		$newName = [];
		if (gettype($submissionFile->getData('name')) == 'array') {
			foreach ($submissionFile->getData('name') as $localeKey => $name) {
				$newName[$localeKey] = pathinfo($name)['filename'] . '-tei.xml';
			}
		} else {
			$newName[$submissionFile->getData('locale')] = pathinfo($submissionFile->getData('name'))['filename'] . '-tei.xml';
		}

		$newSubmissionFile->setAllData(
			[
				'fileId' => $newFileId,
				'assocType' => $submissionFile->getData('assocType'),
				'assocId' => $submissionFile->getData('assocId'),
				'fileStage' => $submissionFile->getData('fileStage'),
				'mimetype' => 'application/xml',
				'locale' => $submissionFile->getData('locale'),
				'genreId' => $genreId,
				'name' => $newName,
				'submissionId' => $submissionId,
			]
		);

		$newSubmissionFile = Services::get('submissionFile')->add($newSubmissionFile, $request);

		unlink($tmpfname);

		$json = new JSONMessage(true);
		return $json;
	}

	public function processJatsImages($args, $request): JSONMessage
	{
		$fileId = (int)$request->getUserVar('fileId');
		$submissionFiles = Services::get('submissionFile')->getMany([
			'fileIds' => [$fileId],
		]);
		$submissionFile = $submissionFiles->current();
		$submissionId = $submissionFile->getData('submissionId');
		$submission = Services::get('submission')->get($submissionId);

		import('lib.pkp.classes.file.PrivateFileManager');
		$fileManager = new PrivateFileManager();
		$filePath = $fileManager->getBasePath() . '/' . $submissionFile->getData('path');

		// Load and parse the JATS XML file
		$xmlContent = file_get_contents($filePath);
		$xml = new DOMDocument();
		$xml->loadXML($xmlContent);

		// Find all graphic elements (JATS uses <graphic> tags for images)
		$xpath = new DOMXPath($xml);
		$xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

		// Search for graphic elements with xlink:href attributes
		$graphicNodes = $xpath->query('//graphic[@xlink:href] | //inline-graphic[@xlink:href]');

		$downloadedCount = 0;
		$failedDownloads = [];

		// Get the genre ID for figures/images
		$genreDao = DAORegistry::getDAO('GenreDAO');
		$genres = $genreDao->getByContextId($submission->getData('contextId'));
		$imageGenreId = null;

		// Try to find an appropriate genre for images/artwork
		while ($genre = $genres->next()) {
			// Check for common genre keys/names for images
			$genreKey = $genre->getKey();
			$genreName = $genre->getName(null); // Get name in any locale

			// Look for genres commonly used for images
			if (stripos($genreKey, 'image') !== false ||
				stripos($genreKey, 'figure') !== false ||
				stripos($genreKey, 'artwork') !== false ||
				(is_string($genreName) && (
						stripos($genreName, 'image') !== false ||
						stripos($genreName, 'figure') !== false
					))) {
				$imageGenreId = $genre->getId();
				break;
			}
		}

		// If no artwork genre found, use the same genre as the XML file
		if (!$imageGenreId) {
			$imageGenreId = $submissionFile->getData('genreId');
		}

		$submissionDir = Services::get('submissionFile')->getSubmissionDir(
			$submission->getData('contextId'),
			$submissionId
		);

		// Get the parent file's ID for dependent file association
		$parentSubmissionFileId = $submissionFile->getId();

		foreach ($graphicNodes as $graphicNode) {
			$imageUrl = $graphicNode->getAttribute('xlink:href');

			// Skip if URL is empty
			if (empty($imageUrl)) {
				continue;
			}

			// Handle relative URLs - convert to absolute if needed
			if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
				$failedDownloads[] = [
					'url' => $imageUrl,
					'reason' => 'Relative URL or invalid format'
				];
				continue;
			}

			try {
				// Download the image to a temporary file
				$imageContent = $this->downloadImage($imageUrl);

				if ($imageContent === false) {
					$failedDownloads[] = [
						'url' => $imageUrl,
						'reason' => 'Failed to download'
					];
					continue;
				}

				// Create temporary file
				$tmpFile = tempnam(sys_get_temp_dir(), 'jatsimg_');
				file_put_contents($tmpFile, $imageContent);

				// Determine file extension from URL or content type
				$extension = $this->getImageExtension($imageUrl, $imageContent);
				$filename = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
				$filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename); // Sanitize filename

				if (empty($filename)) {
					$filename = 'image_' . uniqid();
				}

				// Add file to OJS
				$newFileId = Services::get('file')->add(
					$tmpFile,
					$submissionDir . DIRECTORY_SEPARATOR . $filename . '.' . $extension
				);

				// Create submission file entry as a DEPENDENT file
				$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
				$newSubmissionFile = $submissionFileDao->newDataObject();

				$mimeType = $this->getMimeTypeFromExtension($extension);#
				$currentUser = $request->getUser();
				$uploaderId = $currentUser->getId();


				// Set as dependent file by using ASSOC_TYPE_SUBMISSION_FILE
				// and setting assocId to the parent XML file's ID
				$newSubmissionFile->setAllData([
					'fileId' => $newFileId,
					'assocType' => ASSOC_TYPE_SUBMISSION_FILE, // This makes it a dependent file
					'assocId' => $parentSubmissionFileId,
					'fileStage' => SUBMISSION_FILE_DEPENDENT, // Use dependent file stage
					'mimetype' => $mimeType,
					'locale' => $submissionFile->getData('locale'),
					'genreId' => $imageGenreId,
					'name' => [$submissionFile->getData('locale') => $filename . '.' . $extension],
					'submissionId' => $submissionId,
					'uploaderUserId' => $uploaderId,
				]);

				$newSubmissionFile = Services::get('submissionFile')->add($newSubmissionFile, $request);

				// Clean up temporary file
				unlink($tmpFile);

				$downloadedCount++;

			} catch (Exception $e) {
				$failedDownloads[] = [
					'url' => $imageUrl,
					'reason' => $e->getMessage()
				];
			}
		}

		// Prepare response message
		$message = sprintf(
			'Successfully downloaded and attached %d image(s) as dependent files.',
			$downloadedCount
		);

		if (!empty($failedDownloads)) {
			$message .= sprintf(' Failed to download %d image(s).', count($failedDownloads));
		}

		$json = new JSONMessage(true, $message);
		return $json;
	}

	/**
	 * Download image from URL using cURL with proper headers
	 */
	private function downloadImage($url)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'OJS-XMLConverter-Plugin/1.0');

		$result = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode >= 200 && $httpCode < 300) {
			return $result;
		}

		return false;
	}

	/**
	 * Get image extension from URL or content
	 */
	private function getImageExtension($url, $content)
	{
		// Try to get extension from URL
		$path = parse_url($url, PHP_URL_PATH);
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		// Validate extension
		$validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff', 'bmp', 'svg', 'webp'];
		if (in_array($extension, $validExtensions)) {
			return $extension;
		}

		// Try to detect from content
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mimeType = $finfo->buffer($content);

		$mimeToExt = [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/tiff' => 'tif',
			'image/bmp' => 'bmp',
			'image/svg+xml' => 'svg',
			'image/webp' => 'webp',
		];

		return $mimeToExt[$mimeType] ?? 'jpg'; // Default to jpg
	}

	/**
	 * Get MIME type from extension
	 */
	private function getMimeTypeFromExtension($extension)
	{
		$extToMime = [
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

		return $extToMime[$extension] ?? 'application/octet-stream';
	}

	public function generatePublicationXmlForm($args, $request): JSONMessage
	{
		import('plugins.generic.xmlConverter.controllers.grid.form.GeneratePublicationXmlForm');
		$form = new GeneratePublicationXmlForm($request, $this->getPlugin(), $this->publication, $this->submission);
		$form->initData();
		return new JSONMessage(true, $form->fetch($request));
	}

	public function generatePublicationXml($args, $request): JSONMessage
	{
		import('plugins.generic.xmlConverter.handlers.ORKGHandlerJATSHeader');
		import('plugins.generic.xmlConverter.classes.JATS');
		import('plugins.generic.xmlConverter.controllers.grid.form.GeneratePublicationXmlForm');

		$form = new GeneratePublicationXmlForm($request, $this->getPlugin(), $this->publication, $this->submission);
		$form->readInputData();

		$context = $request->getJournal();
		$sourceFile = Services::get('submissionFile')->get((int)$request->getUserVar('submissionFileId'));
		if (!$sourceFile) {
			return new JSONMessage(false, __('plugins.generic.xmlConverter.generate.error.noSource'));
		}

		$sourceContent = Services::get('file')->fs->read($sourceFile->getData('path'));

		try {
			$cleanedXml = (new ORKGHandlerJATSHeader($sourceContent))->process();
		} catch (Exception $e) {
			return new JSONMessage(false, __('plugins.generic.xmlConverter.generate.error.cleaning', ['msg' => $e->getMessage()]));
		}

		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		if (!@$dom->loadXML($cleanedXml)) {
			return new JSONMessage(false, __('plugins.generic.xmlConverter.generate.error.invalidXml'));
		}

		$dateOverride  = trim((string)$form->getData('datePublishedOverride'));
		$datePublished = $dateOverride !== ''
			? $dateOverride
			: ($this->publication ? $this->publication->getData('datePublished') : null);
		$copyrightYear = $datePublished ? date('Y', strtotime($datePublished)) : date('Y');
		$licenseUrl    = $this->publication ? trim((string)$this->publication->getData('licenseUrl')) : '';

		$fpage = $lpage = null;
		if ($this->publication) {
			$pagesRaw = trim((string)$this->publication->getData('pages'));
			if (preg_match('/^(\d+)\s*[-\x{2013}\x{2014}]\s*(\d+)/u', $pagesRaw, $m)) {
				$fpage = $m[1]; $lpage = $m[2];
			} elseif (preg_match('/^(\d+)/', $pagesRaw, $m)) {
				$fpage = $m[1];
			}
		}

		JATS::getJournalMeta($dom, $context);
		if ($this->publication) JATS::getArticleTitle($dom, $this->publication);
		if ($datePublished) JATS::getJournalMetaPubDate($dom, $context, $this->submission, $datePublished, $fpage, $lpage);
		JATS::getArticleMetaHistory($dom, $this->submission, $datePublished);
		JATS::getArticleMetaCCBYLicense($dom, $context, $copyrightYear, $licenseUrl);
		if ($this->publication) JATS::getContribGroup($dom, $this->publication);

		$submissionDir = Services::get('submissionFile')->getSubmissionDir(
			$this->submission->getData('contextId'),
			$this->submission->getId()
		);
		$filesDir = Config::getVar('files', 'files_dir') . DIRECTORY_SEPARATOR;
		$tmpFile = tempnam(sys_get_temp_dir(), 'publication-xml-');
		file_put_contents($tmpFile, $dom->saveXML());

		$newFileId = Services::get('file')->add(
			$tmpFile,
			$filesDir . $submissionDir . DIRECTORY_SEPARATOR . uniqid() . '.xml'
		);

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$newSubmissionFile = $submissionFileDao->newDataObject();
		$newSubmissionFile->setAllData([
			'fileId'       => $newFileId,
			'assocType'    => $sourceFile->getData('assocType'),
			'assocId'      => $sourceFile->getData('assocId'),
			'fileStage'    => SUBMISSION_FILE_PRODUCTION_READY,
			'mimetype'     => 'application/xml',
			'locale'       => $sourceFile->getData('locale'),
			'genreId'      => $sourceFile->getData('genreId'),
			'name'         => $this->buildPublicationFileName($sourceFile),
			'submissionId' => $this->submission->getId(),
		]);
		Services::get('submissionFile')->add($newSubmissionFile, $request);
		@unlink($tmpFile);

		return $request->redirectUrlJson($request->getDispatcher()->url(
			$request, ROUTE_PAGE, null, 'workflow', 'access', null,
			[
				'submissionId' => $this->submission->getId(),
				'stageId'      => $request->getUserVar('stageId'),
			]
		));
	}

	private function buildPublicationFileName($sourceFile): array
	{
		$submissionId = $this->submission->getId();
		$base = $this->buildAuthorBaseName($submissionId);

		$names = $sourceFile->getData('name');
		$out = [];
		if (is_array($names)) {
			foreach (array_keys($names) as $locale) {
				$out[$locale] = $base . '.xml';
			}
		} else {
			$out[$sourceFile->getData('locale')] = $base . '.xml';
		}
		return $out;
	}

	private function buildAuthorBaseName($submissionId): string
	{
		$authors = $this->publication ? $this->publication->getData('authors') : null;

		$lastNames = [];
		if ($authors && (is_array($authors) || $authors instanceof Traversable)) {
			foreach ($authors as $a) {
				$fam = $this->sanitizeNamePart((string)$a->getLocalizedFamilyName());
				if ($fam === '') {
					$fam = $this->sanitizeNamePart((string)$a->getLocalizedGivenName());
				}
				if ($fam !== '') $lastNames[] = $fam;
			}
		}

		$count = count($lastNames);
		if ($count === 0) return (string)$submissionId;
		if ($count === 1) return $submissionId . '_' . $lastNames[0];
		if ($count === 2) return $submissionId . '_' . $lastNames[0] . '_and_' . $lastNames[1];
		return $submissionId . '_' . $lastNames[0] . '_et_al';
	}

	private function sanitizeNamePart(string $name): string
	{
		$name = trim($name);
		if ($name === '') return '';
		if (function_exists('iconv')) {
			$t = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
			if ($t !== false) $name = $t;
		}
		$name = preg_replace('/\s+/', '_', $name);
		$name = preg_replace('/[^A-Za-z0-9_-]/', '', $name);
		return trim($name, '_');
	}
}
