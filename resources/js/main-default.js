/**
 * @file plugins/generic/xmlConverter/resources/js/main-default.js
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Vite main file
 */
import CreateGalleyModal from './Components/CreateGalleyModal.vue';
import AddExternalFileModal from './Components/AddExternalFileModal.vue';
import GeneratePublicationXmlModal from './Components/GeneratePublicationXmlModal.vue';

pkp.registry.registerComponent('CreateGalleyModal', CreateGalleyModal);
pkp.registry.registerComponent('AddExternalFileModal', AddExternalFileModal);
pkp.registry.registerComponent('GeneratePublicationXmlModal', GeneratePublicationXmlModal);

const fileManagerStores = [
	'fileManager_PRODUCTION_READY_FILES',
	'fileManager_COPYEDITED_FILES',
];

fileManagerStores.forEach((fileManagerStore) =>
	pkp.registry.storeExtend(fileManagerStore, (piniaContext) => {
		const dashboardStore = pkp.registry.getPiniaStore('dashboard');
		const fileStore = piniaContext.store;

		const allowedWorkflowStages = [
			pkp.const.WORKFLOW_STAGE_ID_EDITING,
			pkp.const.WORKFLOW_STAGE_ID_PRODUCTION,
		];

		if (
			dashboardStore.dashboardPage !== 'editorialDashboard' ||
			!allowedWorkflowStages.includes(fileStore.props.submissionStageId)
		) {
			return;
		}

		const supportedExtensions = ['.xml', '.html'];

		const {useModal} = pkp.modules.useModal;
		const {useLocalize} = pkp.modules.useLocalize;
		const {useFetch} = pkp.modules.useFetch;
		const {useUrl} = pkp.modules.useUrl;
		const {useDataChanged} = pkp.modules.useDataChanged;

		const {openSideModal, openDialog} = useModal();
		const {t, localize} = useLocalize();
		const {triggerDataChange} = useDataChanged();

		function dataUpdateCallback() {
			triggerDataChange();
		}

		const {submission} = fileStore.props;

		fileStore['addExternalFileAction'] = function (args) {
			openSideModal(AddExternalFileModal, {
				url: args.url,
				onClose: dataUpdateCallback,
			});
		};

		fileStore.extender.extendFn('getTopItems', (topItems, args) => {
			let newItems = topItems;
			const {apiUrl} = useUrl(
				`submissions/xmlConverter/createServiceFile/${submission.id}`
			);
			newItems.unshift({
				component: 'FileManagerActionButton',
				props: {
					label: t('plugins.generic.xmlConverter.createServiceFile.addFile'),
					name: 'addExternalFile',
					action: 'addExternalFileAction',
					actionArgs: {
						url: apiUrl.value,
						onClose: dataUpdateCallback
					}
				}
			});
			return newItems;
		});

		fileStore.extender.extendFn('getItemActions', (itemActions, args) => {
			const localizedName = localize(args.file.name);
			if (!supportedExtensions.some((extension) => localizedName.endsWith(extension))) {
				return itemActions;
			}

			let newActions = itemActions;
			newActions.push({
				label: t('plugins.generic.xmlConverter.links.createGalley'),
				name: 'createGalley',
				icon: 'FileText',
				actionFn: ({file}) => {
					const {apiUrl} = useUrl(
						`submissions/xmlConverter/createGalley/${file.submissionId}/${file.id}`
					);
					openSideModal(CreateGalleyModal, {
						url: apiUrl.value,
						onClose: dataUpdateCallback
					});
				}
			});

			newActions.push({
				label: t('plugins.generic.xmlConverter.generate.linkLabel'),
				name: 'generatePublicationXml',
				icon: 'FileText',
				actionFn: ({file}) => {
					const {apiUrl} = useUrl(
						`submissions/xmlConverter/generatePublicationXml/${file.submissionId}/${file.id}`
					);
					const {apiUrl: previewApiUrl} = useUrl(
						`submissions/xmlConverter/generatePublicationXmlPreview/${file.submissionId}`
					);
					openSideModal(GeneratePublicationXmlModal, {
						url: apiUrl.value,
						previewUrl: previewApiUrl.value,
						onClose: dataUpdateCallback
					});
				}
			});

			newActions.push({
				label: t('plugins.generic.xmlConverter.button.processJatsImages'),
				name: 'processJatsImages',
				icon: 'Image',
				actionFn: ({file}) => {
					const {apiUrl} = useUrl(
						`submissions/xmlConverter/processJatsImages/${file.submissionId}/${file.id}`
					);
					openDialog({
						title: t('plugins.generic.xmlConverter.button.processJatsImages'),
						message: t('plugins.generic.xmlConverter.button.processJatsImages.dialog'),
						actions: [
							{
								label: t('common.ok'),
								isPrimary: true,
								callback: async (close) => {
									close();
									const {fetch} = useFetch(apiUrl.value, {
										method: 'POST',
										headers: {
											'Content-Type': 'application/json',
											'X-Csrf-Token': pkp.currentUser.csrfToken
										}
									});
									await fetch().then(() => {
										dataUpdateCallback();
									});
								}
							},
							{
								label: t('common.cancel'),
								isWarnable: true,
								callback: (close) => {
									close();
								}
							}
						]
					});
				}
			});

			return newActions;
		});
	})
);

/*
// This is needed for extracting localised texts by the plugin i18nExtractKeys
const localeKeys = [
	// common
	t("common.cancel"),
	t("common.language"),
	t("common.ok"),
	t("common.save"),
	t("common.type"),
	t("submission.layout.galleyLabel"),
	t("submission.layout.galleyLabelInstructions"),
	t("submission.layout.newGalley"),
	t("validator.required"),

	// default
	t("plugins.generic.xmlConverter.createGalley.createArticleMetaHistory"),
	t("plugins.generic.xmlConverter.createGalley.createArticleMetaLicense"),
	t("plugins.generic.xmlConverter.createGalley.customModifications"),
	t("plugins.generic.xmlConverter.createGalley.datePublished"),
	t("plugins.generic.xmlConverter.createGalley.firstPage"),
	t("plugins.generic.xmlConverter.createGalley.createJournalMeta"),
	t("plugins.generic.xmlConverter.createGalley.lastPage"),
	t("plugins.generic.xmlConverter.createServiceFile.addFile"),
	t("plugins.generic.xmlConverter.createServiceFile.file.description"),
	t("plugins.generic.xmlConverter.createServiceFile.file.name"),
	t("plugins.generic.xmlConverter.createServiceFile.orkg"),
	t("plugins.generic.xmlConverter.createServiceFile.upload"),
	t("plugins.generic.xmlConverter.links.createGalley"),
	t("plugins.generic.xmlConverter.button.processJatsImages"),
	t("plugins.generic.xmlConverter.button.processJatsImages.dialog"),
	t("plugins.generic.xmlConverter.generate.linkLabel"),
	t("plugins.generic.xmlConverter.generate.modalTitle"),
	t("plugins.generic.xmlConverter.generate.datePublishedOverride"),
	t("plugins.generic.xmlConverter.generate.datePublishedOverride.description"),
	t("plugins.generic.xmlConverter.generate.licenseUrlOverride"),
	t("plugins.generic.xmlConverter.generate.licenseUrlOverride.description"),

	// conversions
	t("plugins.generic.xmlConverter.button.jatsToTei"),
	t("plugins.generic.xmlConverter.button.jatsToTei.dialog"),
	t("plugins.generic.xmlConverter.button.teiToJats"),
	t("plugins.generic.xmlConverter.button.teiToJats.dialog"),
	t("plugins.generic.xmlConverter.mimetypeError.message"),
	t("plugins.generic.xmlConverter.mimetypeError.title"
];
*/
