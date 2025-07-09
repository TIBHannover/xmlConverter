<?php

namespace APP\plugins\generic\xmlConverter;

use APP\core\Application;
use APP\plugins\generic\xmlConverter\handlers\XmlConverterHandler;
use APP\template\TemplateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use PKP\core\PKPBaseController;
use PKP\handler\APIHandler;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use Exception;
use Illuminate\Http\Request as IlluminateRequest;

/**
 * Class XmlConverterPlugin
 *
 * This plugin provides XML conversion capabilities within the application.
 * It supports specific file conversions (e.g., TEI to JATS and JATS to TEI)
 * and integrates into the application's workflow through hooks and custom handlers.
 */
class XmlConverterPlugin extends GenericPlugin
{
    /**
     * Register the plugin and initialize its hooks and resources.
     *
     * @copydoc GenericPlugin::register()
     *
     * @param string $category The category to register the plugin in.
     * @param string $path The path to the plugin.
     * @param mixed $mainContextId The context ID, if applicable.
     *
     * @return bool
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            // Check if Java is available on the system
            $javaChecker = exec('command java --version >/dev/null && echo "yes" || echo "no"');

            if ('yes' == $javaChecker) {
                $request = Application::get()->getRequest();
                $templateMgr = TemplateManager::getManager($request);
                $this->addJavaScript($request, $templateMgr);
                $this->addRoute(); // add an API route to xmlConverter
            }
        }

        return $success;
    }

    /**
     * Get the display name of the plugin.
     * The name will appear in the Plugin Gallery where editors can install, enable and disable plugins.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.xmlConverter.displayName');
    }

    /**
     * Get the description of the plugin.
     * The name will appear in the Plugin Gallery where editors can install, enable and disable plugins.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return __('plugins.generic.xmlConverter.description');
    }

    /**
     * Get the list of allowed workflow stage IDs.
     *
     * @return array
     */
    public function getAllowedWorkflowStages(): array
    {
        return [
            WORKFLOW_STAGE_ID_EDITING,
            WORKFLOW_STAGE_ID_PRODUCTION,
        ];
    }

    public function addJavaScript($request, $templateMgr)
    {
        $templateMgr->addJavaScript(
            'xmlConverter',
            "{$request->getBaseUrl()}/{$this->getPluginPath()}/public/build/build.iife.js",
            [
                'inline' => false,
                'contexts' => ['backend'],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST
            ]
        );
    }

    /**
     * Add/override new api endpoints to existing list of api endpoints
     */
    public function addRoute(): void
    {

        Hook::add('APIHandler::endpoints::submissions', function (string $hookName, PKPBaseController &$apiController, APIHandler $apiHandler): bool {

            $apiHandler->addRoute(
                'GET',
                'file/{type}/convert',
                function (IlluminateRequest $request): JsonResponse {
                    try {
                        $conversion = (new XmlConverterHandler())
                            ->convert(
                                $request->get('fileId'),
                                $request->route()->parameter('type')
                            );
                        return response()->json([
                            'message' => $conversion,
                        ], Response::HTTP_OK);
                    } catch (Exception $exception) {
                        return response()->json([
                            'message' => $exception,
                        ], Response::HTTP_OK);
                    }
                },
                'submission.convert',
                [
                    Role::ROLE_ID_MANAGER,
                    Role::ROLE_ID_SUB_EDITOR,
                    Role::ROLE_ID_ASSISTANT,
                ]
            );
            return Hook::CONTINUE;
        });
    }

}

if (!PKP_STRICT_MODE) {
    // Allow legacy aliasing for backward compatibility
    class_alias('\APP\plugins\generic\xmlConverter\XmlConverterPlugin', '\XmlConverterPlugin');
}
