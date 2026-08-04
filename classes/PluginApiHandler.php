<?php

/**
 * @file plugins/generic/xmlConverter/classes/PluginApiHandler.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PluginApiHandler
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Api handler for this plugin.
 */

namespace APP\plugins\generic\xmlConverter\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\xmlConverter\classes\models\ConvertFile;
use APP\plugins\generic\xmlConverter\classes\models\CreateGalley;
use APP\plugins\generic\xmlConverter\classes\models\CreateServiceFile;
use APP\plugins\generic\xmlConverter\classes\models\GeneratePublicationXml;
use APP\plugins\generic\xmlConverter\classes\models\GeneratePublicationXmlPreview;
use APP\plugins\generic\xmlConverter\classes\models\ProcessJatsImages;
use APP\plugins\generic\xmlConverter\XmlConverterPlugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\Response;
use PKP\core\PKPBaseController;
use PKP\handler\APIHandler;
use PKP\plugins\Hook;
use PKP\security\Role;

class PluginApiHandler
{
    private XmlConverterPlugin $plugin;

    private const AUTHORIZED_ROLES = [
        Role::ROLE_ID_MANAGER,
        Role::ROLE_ID_SUB_EDITOR,
        Role::ROLE_ID_ASSISTANT
    ];

    public function __construct(XmlConverterPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * This allows adding a route on the fly without defining an api controller.
     * Hook: APIHandler::endpoints::submissions
     * e.g. api/v1/submissions/xmlConverter/createGalley
     */
    public function addRouteDefault(string $hookName, PKPBaseController $apiController, APIHandler $apiHandler): bool
    {
        if (!$this->isSubmissionEndpoint($apiController)) {
            return Hook::CONTINUE;
        }

        $apiHandler->addRoute(
            'POST',
            XmlConverterPlugin::PLUGIN_URL . '/createGalley/{submissionId}/{submissionFileId}',
            fn(IlluminateRequest $illuminateRequest): JsonResponse => $this->createGalley($illuminateRequest),
            'xmlConverter.createGalley',
            self::AUTHORIZED_ROLES
        );

        $apiHandler->addRoute(
            'POST',
            XmlConverterPlugin::PLUGIN_URL . '/createServiceFile/{submissionId}',
            fn(IlluminateRequest $illuminateRequest): JsonResponse => $this->createServiceFile($illuminateRequest),
            'xmlConverter.createServiceFile',
            self::AUTHORIZED_ROLES
        );

        $apiHandler->addRoute(
            'POST',
            XmlConverterPlugin::PLUGIN_URL . '/generatePublicationXml/{submissionId}/{submissionFileId}',
            fn(IlluminateRequest $illuminateRequest): JsonResponse => $this->generatePublicationXml($illuminateRequest),
            'xmlConverter.generatePublicationXml',
            self::AUTHORIZED_ROLES
        );

        $apiHandler->addRoute(
            'GET',
            XmlConverterPlugin::PLUGIN_URL . '/generatePublicationXmlPreview/{submissionId}',
            fn(IlluminateRequest $illuminateRequest): JsonResponse => $this->generatePublicationXmlPreview($illuminateRequest),
            'xmlConverter.generatePublicationXmlPreview',
            self::AUTHORIZED_ROLES
        );

        $apiHandler->addRoute(
            'POST',
            XmlConverterPlugin::PLUGIN_URL . '/processJatsImages/{submissionId}/{submissionFileId}',
            fn(IlluminateRequest $illuminateRequest): JsonResponse => $this->processJatsImages($illuminateRequest),
            'xmlConverter.processJatsImages',
            self::AUTHORIZED_ROLES
        );

        return Hook::CONTINUE;
    }

    /**
     * This allows adding a route on the fly without defining an api controller.
     * Hook: APIHandler::endpoints::submissions
     * e.g. api/v1/submissions/xmlConverter/convert/teiToJats/{submissionFileId}
     */
    public function addRouteConversions(string $hookName, PKPBaseController $apiController, APIHandler $apiHandler): bool
    {
        if (!$this->isSubmissionEndpoint($apiController)) {
            return Hook::CONTINUE;
        }

        $apiHandler->addRoute(
            'GET',
            XmlConverterPlugin::PLUGIN_URL . '/convert/{submissionFileId}/{conversionType}',
            fn(IlluminateRequest $illuminateRequest): JsonResponse => $this->convert($illuminateRequest),
            'xmlConverter.convert',
            self::AUTHORIZED_ROLES
        );

        return Hook::CONTINUE;
    }

    /**
     * Whether the controller is the plain submissions endpoint.
     */
    private function isSubmissionEndpoint(PKPBaseController $apiController): bool
    {
        return $apiController->getHandlerPath() === 'submissions';
    }

    /**
     * Create a galley from an existing file.
     */
    public function createGalley(IlluminateRequest $illuminateRequest): JsonResponse
    {
        if (!$this->plugin->isFeatureEnabled('enableCreateGalley')) {
            return response()->json([
                'error' => __('api.403.unauthorized')
            ], Response::HTTP_FORBIDDEN);
        }

        $submission = Repo::submission()->get((int)$illuminateRequest->route('submissionId'));
        $publication = $submission->getLatestPublication();
        $submissionFile = Repo::submissionFile()->get((int)$illuminateRequest->route('submissionFileId'));

        if (!$publication || !$submissionFile) {
            return response()->json([
                'error' => __('api.404.resourceNotFound')
            ], Response::HTTP_NOT_FOUND);
        }

        $createGalley = new CreateGalley($illuminateRequest->input(), $submission, $publication, $submissionFile);

        if (!$createGalley->validate()) {
            return response()->json([
                'validationErrors' => $createGalley->getValidationErrors()
            ], Response::HTTP_OK);
        }

        return $createGalley->execute();
    }

    /**
     * Create a service or external file.
     */
    public function createServiceFile(IlluminateRequest $illuminateRequest): JsonResponse
    {
        if (!$this->plugin->isFeatureEnabled('enableAddExternalFile')) {
            return response()->json([
                'error' => __('api.403.unauthorized')
            ], Response::HTTP_FORBIDDEN);
        }

        $submission = Repo::submission()->get((int)$illuminateRequest->route('submissionId'));
        $publication = $submission->getLatestPublication();

        if (!$publication) {
            return response()->json([
                'error' => __('api.404.resourceNotFound')
            ], Response::HTTP_NOT_FOUND);
        }

        $createServiceFile = new CreateServiceFile($illuminateRequest->input(), $submission, $publication);

        if (!$createServiceFile->validate()) {
            return response()->json([
                'validationErrors' => $createServiceFile->getValidationErrors()
            ], Response::HTTP_OK);
        }

        return $createServiceFile->execute();
    }

    /**
     * Generate a publication ready JATS file from an existing file.
     */
    public function generatePublicationXml(IlluminateRequest $illuminateRequest): JsonResponse
    {
        if (!$this->plugin->isFeatureEnabled('enableGeneratePublicationXml')) {
            return response()->json([
                'error' => __('api.403.unauthorized')
            ], Response::HTTP_FORBIDDEN);
        }

        $submission = Repo::submission()->get((int)$illuminateRequest->route('submissionId'));
        $publication = $submission ? $submission->getLatestPublication() : null;
        $submissionFile = Repo::submissionFile()->get((int)$illuminateRequest->route('submissionFileId'));

        if (!$submission || !$publication || !$submissionFile) {
            return response()->json([
                'error' => __('api.404.resourceNotFound')
            ], Response::HTTP_NOT_FOUND);
        }

        $generatePublicationXml = new GeneratePublicationXml(
            $illuminateRequest->input(), $submission, $publication, $submissionFile
        );

        return $generatePublicationXml->execute();
    }

    /**
     * Summarise the metadata that will be written into the publication XML.
     */
    public function generatePublicationXmlPreview(IlluminateRequest $illuminateRequest): JsonResponse
    {
        if (!$this->plugin->isFeatureEnabled('enableGeneratePublicationXml')) {
            return response()->json([
                'error' => __('api.403.unauthorized')
            ], Response::HTTP_FORBIDDEN);
        }

        $submission = Repo::submission()->get((int)$illuminateRequest->route('submissionId'));
        $publication = $submission ? $submission->getLatestPublication() : null;

        if (!$submission || !$publication) {
            return response()->json([
                'error' => __('api.404.resourceNotFound')
            ], Response::HTTP_NOT_FOUND);
        }

        $preview = new GeneratePublicationXmlPreview(
            $submission, $publication, Application::get()->getRequest()->getJournal()
        );

        return $preview->execute();
    }

    /**
     * Download the images referenced in a JATS file and attach them as dependent files.
     */
    public function processJatsImages(IlluminateRequest $illuminateRequest): JsonResponse
    {
        if (!$this->plugin->isFeatureEnabled('enableImageProcessing')) {
            return response()->json([
                'error' => __('api.403.unauthorized')
            ], Response::HTTP_FORBIDDEN);
        }

        $submission = Repo::submission()->get((int)$illuminateRequest->route('submissionId'));
        $submissionFile = Repo::submissionFile()->get((int)$illuminateRequest->route('submissionFileId'));

        if (!$submission || !$submissionFile) {
            return response()->json([
                'error' => __('api.404.resourceNotFound')
            ], Response::HTTP_NOT_FOUND);
        }

        $processJatsImages = new ProcessJatsImages($submission, $submissionFile);

        return $processJatsImages->execute();
    }

    /**
     * Handles the conversion process for XML files.
     */
    public function convert(IlluminateRequest $illuminateRequest): JsonResponse
    {
        $submissionFile = Repo::submissionFile()->get((int)$illuminateRequest->route('submissionFileId'));
        $conversionType = $illuminateRequest->route('conversionType');

        $conversionSetting = $conversionType === 'jatsToTei' ? 'enableJatsConversion' : 'enableTeiConversion';
        if (!$this->plugin->isFeatureEnabled($conversionSetting)) {
            return response()->json([
                'error' => __('api.403.unauthorized')
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$submissionFile) {
            return response()->json(
                ['error' => __('api.404.resourceNotFound')],
                Response::HTTP_NOT_FOUND
            );
        }

        $convertFile = new ConvertFile($this->plugin, $submissionFile, $conversionType);

        return $convertFile->execute();
    }
}
