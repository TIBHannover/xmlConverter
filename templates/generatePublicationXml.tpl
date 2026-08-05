<script type="text/javascript">
	$(function() {ldelim}
		$('#generatePublicationXmlForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="generatePublicationXmlForm" method="post"
      action="{url op="generatePublicationXml" submissionId=$submissionId stageId=$stageId fileStage=$fileStage submissionFileId=$submissionFileId}">

	{csrf}

	{fbvFormArea id="generatePublicationXmlArea"}

		{foreach from=$preview key=blockKey item=block}
			<div style="margin-bottom:1em; padding:0.5em 0.75em; border-left:3px solid {if $block.missing}#c0392b{else}#27ae60{/if}; background:#fafafa;">
				<div style="font-weight:600; margin-bottom:0.25em;">{$block.label|escape}</div>
				{if $block.missing}
					<div style="color:#c0392b;">
						{translate key="plugins.generic.xmlConverter.generate.preview.skipped"}
					</div>
				{else}
					<ul style="margin:0; padding-left:1.25em;">
						{foreach from=$block.lines item=line}
							<li><code>{$line|escape}</code></li>
						{/foreach}
					</ul>
				{/if}
			</div>
		{/foreach}

		{fbvFormSection title="plugins.generic.xmlConverter.generate.datePublishedOverride.label"}
			{fbvElement type="text" id="datePublishedOverride" name="datePublishedOverride" value=$datePublished maxlength="10" size=$fbvStyles.size.SMALL placeholder="YYYY-MM-DD"}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.xmlConverter.generate.pagesOverride.label" description="plugins.generic.xmlConverter.generate.pagesOverride.help"}
			{fbvElement type="text" id="pagesOverride" name="pagesOverride" value=$pages maxlength="25" size=$fbvStyles.size.SMALL placeholder="1-14"}
		{/fbvFormSection}

	{/fbvFormArea}

	{fbvFormButtons submitText="plugins.generic.xmlConverter.generate.submit"}
</form>
