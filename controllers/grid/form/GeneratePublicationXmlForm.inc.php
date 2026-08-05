<?php

import('lib.pkp.classes.form.Form');
import('plugins.generic.xmlConverter.classes.JATS');

class GeneratePublicationXmlForm extends Form
{
    private $submission;
    private $publication;
    private $plugin;

    public function __construct($request, $plugin, $publication, $submission)
    {
        $this->plugin = $plugin;
        $this->submission = $submission;
        $this->publication = $publication;

        parent::__construct($plugin->getTemplateResource('generatePublicationXml.tpl'));

        AppLocale::requireComponents(LOCALE_COMPONENT_APP_EDITOR, LOCALE_COMPONENT_PKP_SUBMISSION);
        $locale = AppLocale::getLocale();
        $localeFile = $plugin->getPluginPath() . '/locale/' . $locale . '/locale.po';
        if (!file_exists($localeFile)) {
            $localeFile = $plugin->getPluginPath() . '/locale/en_US/locale.po';
        }
        if (file_exists($localeFile)) {
            AppLocale::registerLocaleFile($locale, $localeFile);
        }

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function fetch($request, $template = null, $display = false)
    {
        $context = $request->getJournal();
        $templateMgr = TemplateManager::getManager($request);
        $datePublished = $this->publication ? $this->publication->getData('datePublished') : null;
        $templateMgr->assign([
            'submissionId'     => $this->submission->getId(),
            'stageId'          => $request->getUserVar('stageId'),
            'fileStage'        => $request->getUserVar('fileStage'),
            'submissionFileId' => $request->getUserVar('submissionFileId'),
            'preview'          => $this->buildPreview($context),
            'datePublished'    => $datePublished ? date('Y-m-d', strtotime($datePublished)) : '',
            'pages'            => $this->publication ? trim((string)$this->publication->getData('pages')) : '',
        ]);
        return parent::fetch($request, $template, $display);
    }

    private function buildPreview($context): array
    {
        $journalId    = $context ? trim((string)$context->getLocalizedAcronym()) : '';
        $issn         = $context ? trim((string)$context->getData('onlineIssn')) : '';
        $publisher    = $context ? trim((string)$context->getData('publisherInstitution')) : '';
        $journalTitle = $context ? trim((string)$context->getLocalizedName()) : '';
        $articleTitle = $this->publication ? trim((string)$this->publication->getLocalizedTitle()) : '';

        $datePublished = $this->publication ? $this->publication->getData('datePublished') : null;
        $dateSubmitted = $this->submission ? $this->submission->getData('dateSubmitted') : null;
        $copyrightYear = $datePublished ? date('Y', strtotime($datePublished)) : date('Y');

        $dateAccepted = null;
        if ($this->submission) {
            import('plugins.generic.xmlConverter.classes.JATS');
            $editDecisionDao = DAORegistry::getDAO('EditDecisionDAO');
            $dateAccepted = JATS::selectAcceptedDate($editDecisionDao->getEditorDecisions($this->submission->getId()));
        }

        $pagesRaw = $this->publication ? trim((string)$this->publication->getData('pages')) : '';
        [$fpage, $lpage] = JATS::parsePages($pagesRaw);
        $fpage = $fpage ?? '';
        $lpage = $lpage ?? '';

        $authors = $this->publication ? $this->publication->getData('authors') : [];
        $authorList = [];
        if (is_array($authors) || $authors instanceof Traversable) {
            $locale = $this->publication->getData('locale') ?: AppLocale::getLocale();
            foreach ($authors as $a) {
                $line = trim($a->getLocalizedGivenName($locale) . ' ' . $a->getLocalizedFamilyName($locale));
                if ($email = $a->getEmail()) $line .= ' <' . $email . '>';
                if ($aff = $a->getLocalizedAffiliation($locale)) $line .= ' — ' . $aff;
                if ($orcid = $a->getOrcid()) $line .= ' (ORCID: ' . $orcid . ')';
                $authorList[] = $line;
            }
        }

        return [
            'journalMeta' => [
                'label'   => __('plugins.generic.xmlConverter.generate.preview.journalMeta'),
                'missing' => ($issn === '' && $journalId === '' && $publisher === '' && $journalTitle === ''),
                'lines'   => array_filter([
                    $journalId    !== '' ? 'journal-id: ' . $journalId : null,
                    $journalTitle !== '' ? 'journal-title: ' . $journalTitle : null,
                    $issn         !== '' ? 'ISSN: ' . $issn : null,
                    $publisher    !== '' ? 'Publisher: ' . $publisher : null,
                ]),
            ],
            'articleTitle' => [
                'label'   => __('plugins.generic.xmlConverter.generate.preview.articleTitle'),
                'missing' => $articleTitle === '',
                'lines'   => $articleTitle !== '' ? ['article-title: ' . $articleTitle] : [],
            ],
            'pubDate' => [
                'label'   => __('plugins.generic.xmlConverter.generate.preview.pubDate'),
                'missing' => empty($datePublished) && $fpage === '' && $lpage === '',
                'lines'   => array_filter([
                    $datePublished ? 'date-published: ' . date('Y-m-d', strtotime($datePublished)) : null,
                    $fpage !== '' ? 'fpage: ' . $fpage : null,
                    $lpage !== '' ? 'lpage: ' . $lpage : null,
                ]),
            ],
            'history' => [
                'label'   => __('plugins.generic.xmlConverter.generate.preview.history'),
                'missing' => empty($dateSubmitted) && empty($dateAccepted) && empty($datePublished),
                'lines'   => array_filter([
                    $dateSubmitted ? 'received: ' . date('Y-m-d', strtotime($dateSubmitted)) : null,
                    $dateAccepted ? 'accepted: ' . date('Y-m-d', strtotime($dateAccepted)) : null,
                    $datePublished ? 'published: ' . date('Y-m-d', strtotime($datePublished)) : null,
                ]),
            ],
            'permissions' => [
                'label'   => __('plugins.generic.xmlConverter.generate.preview.permissions'),
                'missing' => false,
                'lines'   => [
                    'license: https://creativecommons.org/licenses/by/4.0',
                    'copyright-year: ' . $copyrightYear,
                ],
            ],
            'contribGroup' => [
                'label'   => __('plugins.generic.xmlConverter.generate.preview.contribGroup'),
                'missing' => empty($authorList),
                'lines'   => $authorList,
            ],
        ];
    }

    public function readInputData() { $this->readUserVars(['datePublishedOverride', 'pagesOverride']); }
    public function getSubmission()  { return $this->submission; }
    public function getPublication() { return $this->publication; }
    public function getPlugin()      { return $this->plugin; }
}
