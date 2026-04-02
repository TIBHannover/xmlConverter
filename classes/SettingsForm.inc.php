<?php

/**
 * @file plugins/generic/xmlConverter/classes/SettingsForm.inc.php
 *
 * @class SettingsForm
 * @ingroup plugins_generic_xmlConverter
 *
 * @brief Form for XML Converter plugin settings
 */

import('lib.pkp.classes.form.Form');

class SettingsForm extends Form
{
    /** @var int Context ID */
    private $contextId;

    /** @var xmlConverterPlugin */
    private $plugin;

    /**
     * Constructor
     *
     * @param xmlConverterPlugin $plugin
     * @param int $contextId
     */
    public function __construct($plugin, int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->plugin = $plugin;
        $this->contextId = $contextId;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $this->_data = [
            'enableCreateGalley' => (bool) $this->plugin->getSetting($this->contextId, 'enableCreateGalley'),
            'enableAddExternalFile' => (bool) $this->plugin->getSetting($this->contextId, 'enableAddExternalFile'),
            'enableTeiConversion' => (bool) $this->plugin->getSetting($this->contextId, 'enableTeiConversion'),
            'enableJatsConversion' => (bool) $this->plugin->getSetting($this->contextId, 'enableJatsConversion'),
            'enableImageProcessing' => (bool) $this->plugin->getSetting($this->contextId, 'enableImageProcessing'),
        ];

        // Set defaults if not configured
        if ($this->plugin->getSetting($this->contextId, 'enableCreateGalley') === null) {
            $this->_data['enableCreateGalley'] = true;
        }
        if ($this->plugin->getSetting($this->contextId, 'enableAddExternalFile') === null) {
            $this->_data['enableAddExternalFile'] = true;
        }
        if ($this->plugin->getSetting($this->contextId, 'enableTeiConversion') === null) {
            $this->_data['enableTeiConversion'] = true;
        }
        if ($this->plugin->getSetting($this->contextId, 'enableJatsConversion') === null) {
            $this->_data['enableJatsConversion'] = true;
        }
        if ($this->plugin->getSetting($this->contextId, 'enableImageProcessing') === null) {
            $this->_data['enableImageProcessing'] = true;
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars([
            'enableCreateGalley',
            'enableAddExternalFile',
            'enableTeiConversion',
            'enableJatsConversion',
            'enableImageProcessing',
        ]);
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
        $this->plugin->updateSetting(
            $this->contextId,
            'enableCreateGalley',
            (bool) $this->getData('enableCreateGalley')
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'enableAddExternalFile',
            (bool) $this->getData('enableAddExternalFile')
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'enableTeiConversion',
            (bool) $this->getData('enableTeiConversion')
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'enableJatsConversion',
            (bool) $this->getData('enableJatsConversion')
        );
        $this->plugin->updateSetting(
            $this->contextId,
            'enableImageProcessing',
            (bool) $this->getData('enableImageProcessing')
        );

        parent::execute(...$functionArgs);
    }
}
