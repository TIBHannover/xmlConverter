<?php

/**
 * @file plugins/generic/xmlConverter/classes/SettingsForm.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Form for journal managers to enable or disable individual plugin features.
 */

namespace APP\plugins\generic\xmlConverter\classes;

use APP\plugins\generic\xmlConverter\XmlConverterPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    private XmlConverterPlugin $plugin;
    private $contextId;

    public function __construct(XmlConverterPlugin $plugin, $contextId)
    {
        $this->plugin = $plugin;
        $this->contextId = $contextId;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $data = [];
        foreach (XmlConverterPlugin::FEATURE_SETTINGS as $setting) {
            $data[$setting] = $this->plugin->isFeatureEnabled($setting, $this->contextId);
        }
        $this->_data = $data;

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(XmlConverterPlugin::FEATURE_SETTINGS);

        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch()
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'javaAvailable' => $this->plugin->isJavaAvailable(),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        foreach (XmlConverterPlugin::FEATURE_SETTINGS as $setting) {
            $this->plugin->updateSetting($this->contextId, $setting, (bool)$this->getData($setting), 'bool');
        }

        return parent::execute(...$functionArgs);
    }
}
