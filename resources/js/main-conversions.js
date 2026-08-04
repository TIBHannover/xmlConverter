/**
 * @file plugins/generic/xmlConverter/resources/js/main-conversions.js
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Vite main file
 */

pkp.registry.storeExtend(
	'fileManager_PRODUCTION_READY_FILES',
	(piniaContext) => {
		const fileStore = piniaContext.store;

		const supportedMimetypes = ['text/xml', 'application/xml', 'text/html'];

		const {useLocalize} = pkp.modules.useLocalize;
		const {useModal} = pkp.modules.useModal;
		const {useFetch} = pkp.modules.useFetch;
		const {useUrl} = pkp.modules.useUrl;
		const {useDataChanged} = pkp.modules.useDataChanged;

		const {openDialog} = useModal();
		const {t} = useLocalize();
		const {triggerDataChange} = useDataChanged();

		function dataUpdateCallback() {
			triggerDataChange();
		}

		fileStore.extender.extendFn('getItemActions', (itemActions, args) => {
			if (!supportedMimetypes.includes(args.file.mimetype)) {
				return itemActions;
			}

			let newActions = itemActions;
			const {apiUrl} = useUrl(`submissions/xmlConverter/convert/${args.file.id}`);

			newActions.push({
				label: t('plugins.generic.xmlConverter.button.teiToJats'),
				name: 'teiToJats',
				icon: 'FileExcel',
				actionFn: async ({file}) => {
					openDialog({
						title: t('plugins.generic.xmlConverter.button.teiToJats'),
						message: t('plugins.generic.xmlConverter.button.teiToJats.dialog'),
						actions: [
							{
								label: 'Yes',
								isPrimary: true,
								callback: async (close) => {
									close();
									const {fetch} = useFetch(`${apiUrl.value}/teiToJats`, {
										method: 'GET',
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
								label: 'No',
								isWarnable: true,
								callback: (close) => {
									close();
								}
							}
						]
					});
				},
			});

			newActions.push({
				label: t('plugins.generic.xmlConverter.button.jatsToTei'),
				name: 'jatsToTei',
				icon: 'FileExcel',
				actionFn: async ({file}) => {
					openDialog({
						title: t('plugins.generic.xmlConverter.button.jatsToTei'),
						message: t('plugins.generic.xmlConverter.button.jatsToTei.dialog'),
						actions: [
							{
								label: 'Yes',
								isPrimary: true,
								callback: async (close) => {
									close();
									const {fetch} = useFetch(`${apiUrl.value}/jatsToTei`, {
										method: 'GET',
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
								label: 'No',
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
	},
);

/*
// This is needed for extracting localised texts by the plugin i18nExtractKeys
const localeKeys = [
	// common
	t("common.cancel"),
	t("common.language"),
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

	// conversions
	t("plugins.generic.xmlConverter.button.jatsToTei"),
	t("plugins.generic.xmlConverter.button.jatsToTei.dialog"),
	t("plugins.generic.xmlConverter.button.teiToJats"),
	t("plugins.generic.xmlConverter.button.teiToJats.dialog"),
	t("plugins.generic.xmlConverter.mimetypeError.message"),
	t("plugins.generic.xmlConverter.mimetypeError.title"
];
*/
