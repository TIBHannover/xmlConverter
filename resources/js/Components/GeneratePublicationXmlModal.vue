<template>
	<PkpSideModalBody>
		<template #title>
			{{ t('plugins.generic.xmlConverter.generate.modalTitle') }}
		</template>
		<PkpSideModalLayoutBasic>
			<div class="preview">
				<h3 class="preview__title">
					{{ t('plugins.generic.xmlConverter.generate.preview.title') }}
				</h3>
				<p class="preview__intro">
					{{ t('plugins.generic.xmlConverter.generate.preview.intro') }}
				</p>
				<p class="preview__intro">
					{{ t('plugins.generic.xmlConverter.generate.preview.note') }}
				</p>
				<p v-if="isPreviewLoading">
					{{ t('plugins.generic.xmlConverter.generate.preview.loading') }}
				</p>
				<p v-else-if="previewError">
					{{ t('plugins.generic.xmlConverter.generate.preview.error') }}
				</p>
				<dl v-else class="preview__list">
					<template v-for="(section, key) in previewSections" :key="key">
						<dt class="preview__label">{{ section.label }}</dt>
						<dd class="preview__value">
							<span v-if="section.missing" class="preview__skipped">
								{{
									t(
										'plugins.generic.xmlConverter.generate.preview.skipped',
									)
								}}
							</span>
							<ul v-else class="preview__lines">
								<li v-for="(line, index) in section.lines" :key="index">
									{{ line }}
								</li>
							</ul>
						</dd>
					</template>
				</dl>
			</div>
			<PkpFieldText
				:allErrors="errors"
				:isRequired="false"
				:label="t('plugins.generic.xmlConverter.generate.datePublishedOverride')"
				:description="t('plugins.generic.xmlConverter.generate.datePublishedOverride.description')"
				:value="currentValue.datePublishedOverride"
				class="mb-8"
				component="field-text"
				formId="default"
				groupId="generatePublicationXmlForm"
				inputType="date"
				name="datePublishedOverride"
				@change="
					(fieldName, propName, newValue, localeKey) =>
						updateCurrentValueData(fieldName, newValue)
				"
			/>
			<PkpFieldText
				:allErrors="errors"
				:isRequired="false"
				:label="t('plugins.generic.xmlConverter.generate.licenseUrlOverride')"
				:description="t('plugins.generic.xmlConverter.generate.licenseUrlOverride.description')"
				:value="currentValue.licenseUrlOverride"
				class="mb-8"
				component="field-text"
				formId="default"
				groupId="generatePublicationXmlForm"
				inputType="text"
				name="licenseUrlOverride"
				@change="
					(fieldName, propName, newValue, localeKey) =>
						updateCurrentValueData(fieldName, newValue)
				"
			/>
			<div>
				<PkpButton @click="handleSubmit">{{ t('common.save') }}</PkpButton>
				&nbsp;
				<PkpButton @click="handleCancel">{{ t('common.cancel') }}</PkpButton>
			</div>
		</PkpSideModalLayoutBasic>
	</PkpSideModalBody>
</template>

<script setup>
import {inject, onMounted, ref} from 'vue';

const {useFetch} = pkp.modules.useFetch;
const {useLocalize} = pkp.modules.useLocalize;
const {t} = useLocalize();

const emit = defineEmits(['close']);
const errors = ref({});
const closeModal = inject('closeModal');

const props = defineProps({
	url: {type: String, required: true},
	previewUrl: {type: String, required: true},
	onClose: {type: Function, required: true}
});

const previewSections = ref({});
const isPreviewLoading = ref(true);
const previewError = ref(false);

onMounted(async () => {
	const {fetch, data} = useFetch(props.previewUrl);

	try {
		await fetch();
		if (data.value && data.value.sections) {
			previewSections.value = data.value.sections;
		} else {
			previewError.value = true;
		}
	} catch (e) {
		previewError.value = true;
	} finally {
		isPreviewLoading.value = false;
	}
});

const currentValue = ref({
	datePublishedOverride: '',
	licenseUrlOverride: ''
});

function updateCurrentValueData(fieldName, newValue) {
	currentValue.value[fieldName] = newValue;
}

const handleSubmit = async () => {
	const {fetch, data} = useFetch(props.url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-Csrf-Token': pkp.currentUser.csrfToken,
		},
		body: currentValue.value
	});

	await fetch().then(() => {
		if (data.value['validationErrors']) {
			errors.value = data.value['validationErrors'];
		} else {
			emit('close');
			closeModal();
		}
	});
};

function handleCancel() {
	emit('close');
	closeModal();
}
</script>

<style scoped>
.mb-8 {
	margin-bottom: 2rem;
}

.preview {
	margin-bottom: 2rem;
}

.preview__title {
	margin: 0 0 0.5rem;
	font-size: 1rem;
	font-weight: 700;
}

.preview__intro {
	margin: 0 0 0.5rem;
	font-size: 0.875rem;
}

.preview__list {
	margin: 1rem 0 0;
	border-inline-start: 2px solid currentColor;
	padding-inline-start: 1rem;
}

.preview__label {
	font-weight: 700;
	font-size: 0.875rem;
}

.preview__value {
	margin: 0 0 0.75rem;
	font-size: 0.875rem;
}

.preview__lines {
	margin: 0;
	padding-inline-start: 1rem;
	overflow-wrap: anywhere;
}

.preview__skipped {
	font-style: italic;
	opacity: 0.75;
}
</style>
