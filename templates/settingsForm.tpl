{**
 * templates/settingsForm.tpl
 *
 * XML Converter plugin settings form
 *}

<script>
	$(function() {ldelim}
		$('#xmlConverterSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="xmlConverterSettings" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}

	{if !$javaAvailable}
		<div class="pkp_notification">
			<span class="notifyWarning">{translate key="plugins.generic.xmlConverter.settings.javaNotAvailable"}</span>
		</div>
	{/if}

	{fbvFormArea id="xmlConverterFeaturesArea" title="plugins.generic.xmlConverter.settings.features"}
		{fbvFormSection list="true" title="plugins.generic.xmlConverter.settings.galleyFeatures"}
			{fbvElement type="checkbox" id="enableCreateGalley" value="1" checked=$enableCreateGalley label="plugins.generic.xmlConverter.settings.enableCreateGalley"}
			{fbvElement type="checkbox" id="enableAddExternalFile" value="1" checked=$enableAddExternalFile label="plugins.generic.xmlConverter.settings.enableAddExternalFile"}
		{/fbvFormSection}

		{fbvFormSection list="true" title="plugins.generic.xmlConverter.settings.conversionFeatures"}
			{fbvElement type="checkbox" id="enableJatsConversion" value="1" checked=$enableJatsConversion label="plugins.generic.xmlConverter.settings.enableJatsConversion" disabled=!$javaAvailable}
			{fbvElement type="checkbox" id="enableTeiConversion" value="1" checked=$enableTeiConversion label="plugins.generic.xmlConverter.settings.enableTeiConversion" disabled=!$javaAvailable}
			{fbvElement type="checkbox" id="enableImageProcessing" value="1" checked=$enableImageProcessing label="plugins.generic.xmlConverter.settings.enableImageProcessing"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
