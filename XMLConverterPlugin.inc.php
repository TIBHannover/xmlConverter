<?php

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.linkAction.request.AjaxAction');
import('lib.pkp.classes.linkAction.request.AjaxModal');

class xmlConverterPlugin extends GenericPlugin
{

	function register($category, $path, $mainContextId = null)
	{

		if (parent::register($category, $path, $mainContextId)) {
			if ($this->getEnabled()) {
				// Register hooks that don't require Java
				HookRegistry::register('LoadHandler', array($this, 'callbackLoadHandler'));
				HookRegistry::register('TemplateManager::fetch', array($this, 'templateFetchCallback'));
				HookRegistry::register('editorsubmissiondetailsfilesgridhandler::initfeatures', [$this, 'addActionsToFileGrid']);
				HookRegistry::register('editorreviewfilesgridhandler::initfeatures', [$this, 'addActionsToFileGrid']);
				HookRegistry::register('copyeditfilesgridhandler::initfeatures', [$this, 'addActionsToFileGrid']);
				HookRegistry::register('productionreadyfilesgridhandler::initfeatures', [$this, 'addActionsToFileGrid']);

				$this->_registerTemplateResource();
			}
			return true;
		}
		return false;
	}

	/**
	 * Add "Add external File" action to file grids
	 */
	function addActionsToFileGrid()
	{
		$request = Application::get()->getRequest();
		$dispatcher = $request->getDispatcher();
		$request->getRouter()->getHandler()->addAction(
			new LinkAction(
				'services_add_file',
				new AjaxModal(
					$dispatcher->url($request, ROUTE_PAGE, null, 'xmlConverterConverter', 'createServiceFileForm', null, $request->getUserVars()),
					__('plugins.generic.xmlConverter.createServiceFile.upload'),
					'modals_services_add_file'
				),
				__('plugins.generic.xmlConverter.createServiceFile.add_file'),
				''
			)
		);
	}

	/**
	 * Check if Java is available for XML conversion features
	 * @return bool
	 */
	function isJavaAvailable()
	{
		$javaChecker = exec('command java --version >/dev/null && echo "yes" || echo "no"');
		return $javaChecker == 'yes';
	}

	function getPluginUrl($request) {
		return $request->getBaseUrl() . '/' . $this->getPluginPath();
	}
	public function getDisplayName()
	{
		return __('plugins.generic.xmlConverter.displayName');
	}

	public function getDescription()
	{
		return __('plugins.generic.xmlConverter.description');
	}

	public function templateFetchCallback($hookName, $params)
	{
		$request = $this->getRequest();
		$dispatcher = $request->getDispatcher();

		$templateMgr = $params[0];
		$resourceName = $params[1];
		$allowedRoles = [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT, ROLE_ID_SITE_ADMIN];

		if ($resourceName == 'controllers/grid/gridRow.tpl') {

			$row = $templateMgr->getTemplateVars('row');
			$data = $row->getData();

			if (is_array($data) && (isset($data['submissionFile']))) {
				$submissionFile = $data['submissionFile'];
				$fileExtension = strtolower($submissionFile->getData('mimetype'));

				// Ensure that the conversion is run on the appropriate workflow stage
				$stageId = (int)$request->getUserVar('stageId');
				$submissionId = $submissionFile->getData('submissionId');
				$submission = Services::get('submission')->get($submissionId);
				$submissionStageId = $submission->getData('stageId');
				$fileStage = SUBMISSION_FILE_PRODUCTION_READY;

				$roles = $request->getUser()->getRoles($request->getContext()->getId());
				$extensionSupported = in_array(strtolower($fileExtension), static::getSupportedMimetypes());
				$stageAllowed = in_array($stageId, $this->getAllowedWorkflowStages());
				$workflowAllowed = in_array($submissionStageId, $this->getAllowedWorkflowStages());

				$accessAllowed = false;
				foreach ($roles as $role) {
					if (in_array($role->getId(), $allowedRoles)) {
						$accessAllowed = true;
						break;
					}
				}

				if ($extensionSupported && $accessAllowed && $stageAllowed && $workflowAllowed)
				{
					// Add Create Galley button for XML files
					if (strtolower($fileExtension) == 'text/xml' || strtolower($fileExtension) == 'application/xml') {
						$this->_createGalleyAction($row, $dispatcher, $request, $submissionFile, $stageId, $fileStage);
						$this->_generatePublicationXmlAction($row, $dispatcher, $request, $submissionFile, $stageId, $fileStage);
					}

					// Add conversion buttons (only if Java is available)
					if ($this->isJavaAvailable()) {
						$this->createJatsToTeiButton($dispatcher, $request, $submissionId, $submissionFile, $stageId, $row);
						$this->creatTEIToJatsButton($dispatcher, $request, $submissionId, $submissionFile, $stageId, $row);
					}
					$this->createProcessJatsImagesButton($dispatcher, $request, $submissionId, $submissionFile, $stageId, $row);
				}

				}

			}
		}



	public static function getSupportedMimetypes()
	{
		return ['text/xml', 'text/html', 'application/xml'];
	}

	public function getAllowedWorkflowStages()
	{
		return [
			WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION];
	}

	public function callbackLoadHandler(string $hookName, array $args): bool
	{
		$page = $args[0];
		$op = $args[1];
		if($page && $args) {
			$pageOperator = "$page/$op";
			switch ($pageOperator) {
				case "xmlConverterConverter/convertToJats":
				case "xmlConverterConverter/convertToTei":
				case "xmlConverterConverter/processJatsImages":
				case "xmlConverterConverter/createGalleyForm":
				case "xmlConverterConverter/createGalley":
				case "xmlConverterConverter/createServiceFileForm":
				case "xmlConverterConverter/generatePublicationXmlForm":
				case "xmlConverterConverter/generatePublicationXml":
					$this->import('handlers/XMLConverterHandler');
					define('HANDLER_CLASS', 'xmlConverterHandler');
					return true;
				default:
					break;
			}
		}

		return false;
	}

	/**
	 * @param Dispatcher|null $dispatcher
	 * @param PKPRequest $request
	 * @param $submissionId
	 * @param mixed $submissionFile
	 * @param int $stageId
	 * @param $row
	 * @return void
	 */
	public function creatTEIToJatsButton(?Dispatcher $dispatcher, PKPRequest $request, $submissionId, mixed $submissionFile, int $stageId, $row): void
	{
		$jatsDispatcherPath = $dispatcher->url($request, ROUTE_PAGE, null, 'xmlConverterConverter', 'convertToJats', null,
			array(
				'submissionId' => $submissionId,
				'fileId' => $submissionFile->getData('fileId'),
				'stageId' => $stageId
			));
		$pathRedirect = $dispatcher->url($request, ROUTE_PAGE, null, 'workflow', 'access',
			array(
				'submissionId' => $submissionId,
				'fileId' => $submissionFile->getData('fileId'),
				'stageId' => $stageId
			));

		$linkAction = new LinkAction(
			'convertteiConverter',
			new PostAndRedirectAction($jatsDispatcherPath, $pathRedirect),
			__('plugins.generic.xmlConverter.button.convertToJats')
		);
		$row->addAction($linkAction);
	}

	public function createJatsToTeiButton(?Dispatcher $dispatcher, PKPRequest $request, $submissionId, mixed $submissionFile, int $stageId, $row): void
	{
		$teiDispatcherPath = $dispatcher->url($request, ROUTE_PAGE, null, 'xmlConverterConverter', 'convertToTei', null,
			array(
				'submissionId' => $submissionId,
				'fileId' => $submissionFile->getData('fileId'),
				'stageId' => $stageId
			));
		$pathRedirect = $dispatcher->url($request, ROUTE_PAGE, null, 'workflow', 'access',
			array(
				'submissionId' => $submissionId,
				'fileId' => $submissionFile->getData('fileId'),
				'stageId' => $stageId
			));


		$linkAction = new LinkAction(
			'convertJATSConverter',
			new PostAndRedirectAction($teiDispatcherPath, $pathRedirect),
			__('plugins.generic.xmlConverter.button.convertToTei')
		);

		$row->addAction($linkAction);


	}

	public function createProcessJatsImagesButton(?Dispatcher $dispatcher, PKPRequest $request, $submissionId, mixed $submissionFile, int $stageId, $row): void
	{
		$processImagesPath = $dispatcher->url($request, ROUTE_PAGE, null, 'xmlConverterConverter', 'processJatsImages', null,
			array(
				'submissionId' => $submissionId,
				'fileId' => $submissionFile->getData('fileId'),
				'stageId' => $stageId
			));
		$pathRedirect = $dispatcher->url($request, ROUTE_PAGE, null, 'workflow', 'access',
			array(
				'submissionId' => $submissionId,
				'fileId' => $submissionFile->getData('fileId'),
				'stageId' => $stageId
			));

		$linkAction = new LinkAction(
			'processJatsImages',
			new PostAndRedirectAction($processImagesPath, $pathRedirect),
			__('plugins.generic.xmlConverter.button.processJatsImages')
		);
		$row->addAction($linkAction);
	}

	/**
	 * Adds create galley action to files grid
	 * @param $row SubmissionFilesGridRow
	 * @param Dispatcher $dispatcher
	 * @param PKPRequest $request
	 * @param $submissionFile SubmissionFile
	 * @param int $stageId
	 * @param int $fileStage
	 */
	private function _createGalleyAction($row, Dispatcher $dispatcher, PKPRequest $request, $submissionFile, int $stageId, int $fileStage): void
	{

		$actionArgs = array(
			'submissionId' => $submissionFile->getData('submissionId'),
			'stageId' => $stageId,
			'fileStage' => $fileStage,
			'submissionFileId' => $submissionFile->getData('id')
		);
		$row->addAction(new LinkAction(
			'createGalleyForm',
			new AjaxModal(
				$dispatcher->url(
					$request,
					ROUTE_PAGE,
					null,
					'xmlConverterConverter',
					'createGalleyForm',
					null,
					$actionArgs
				),
				__('submission.layout.newGalley')
			),
			__('plugins.generic.xmlConverter.links.createGalley'),
			null
		));

	}

	private function _generatePublicationXmlAction($row, Dispatcher $dispatcher, PKPRequest $request, $submissionFile, int $stageId, int $fileStage): void
	{
		$actionArgs = array(
			'submissionId' => $submissionFile->getData('submissionId'),
			'stageId' => $stageId,
			'fileStage' => $fileStage,
			'submissionFileId' => $submissionFile->getData('id')
		);
		$row->addAction(new LinkAction(
			'generatePublicationXmlForm',
			new AjaxModal(
				$dispatcher->url($request, ROUTE_PAGE, null, 'xmlConverterConverter', 'generatePublicationXmlForm', null, $actionArgs),
				__('plugins.generic.xmlConverter.generate.modalTitle')
			),
			__('plugins.generic.xmlConverter.generate.linkLabel'),
			null
		));
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $actionArgs): array
	{
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled() ? [
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, [
							'verb' => 'settings',
							'plugin' => $this->getName(),
							'category' => 'generic'
						]),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			] : [],
			parent::getActions($request, $actionArgs)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	public function manage($args, $request)
	{
		switch ($request->getUserVar('verb')) {
			case 'settings':
				$context = $request->getContext();
				$contextId = $context ? $context->getId() : CONTEXT_SITE;

				$this->import('classes/SettingsForm');
				$form = new SettingsForm($this, $contextId);

				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}
}
