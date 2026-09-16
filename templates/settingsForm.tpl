{**
 * plugins/generic/requiredMultilingualMetadata/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The languages in which the title, the abstract and the keywords become required.
 *
 * The checkboxes are written by hand on purpose: PKP's {fbvElement type="checkbox"}
 * renders a bare <li> (lib/pkp/templates/form/checkbox.tpl) that is only valid inside
 * {fbvFormSection list=true}; inside a table the parser would throw it out.
 *}
<link rel="stylesheet" type="text/css" href="{$settingsStyleUrl|escape}">
<script>
	$(function() {ldelim}
		$('#requiredMultilingualMetadataSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="requiredMultilingualMetadataSettingsForm"
	method="post"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="requiredMultilingualMetadataSettingsFormNotification"}
	{include file="common/formErrors.tpl"}

	<div id="description">{translate key="plugins.generic.requiredMultilingualMetadata.settings.description"}</div>

	<div class="rmmNotice">
		<strong>{translate key="plugins.generic.requiredMultilingualMetadata.settings.notice.title"}</strong><br />
		{translate key="plugins.generic.requiredMultilingualMetadata.settings.notice.body"}
	</div>

	{fbvFormArea id="requiredMultilingualMetadataArea"}
		{if $localeRows}
			{if !$keywordsRequired}
				<div class="rmmNotice rmmNotice--info">
					<strong>{translate key="plugins.generic.requiredMultilingualMetadata.settings.keywordsOff.title"}</strong><br />
					{translate key="plugins.generic.requiredMultilingualMetadata.settings.keywordsOff.body"}
				</div>
			{/if}

			<table class="rmmTable">
				<thead>
					<tr>
						<th>{translate key="plugins.generic.requiredMultilingualMetadata.settings.column.language"}</th>
						<th class="rmmCheck">{translate key="common.title"}</th>
						<th class="rmmCheck">{translate key="common.abstract"}</th>
						<th class="rmmCheck{if !$keywordsRequired} rmmCheck--off{/if}">
							{translate key="common.keywords"}
							{if !$keywordsRequired}<span class="rmmOffTag">{translate key="plugins.generic.requiredMultilingualMetadata.settings.inactive"}</span>{/if}
						</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$localeRows item=row}
						<tr>
							<td>
								<span class="rmmLocaleName">{$row.name|escape}</span>
								{if $row.isDefaultSubmissionLocale}
									<span class="rmmTag">{translate key="plugins.generic.requiredMultilingualMetadata.settings.defaultTag"}</span>
								{/if}
								<span class="rmmLocaleCode">{$row.locale|escape}</span>
							</td>
							<td class="rmmCheck">
								<input
									type="checkbox"
									id="rmmTitle-{$row.locale|escape}"
									name="titleLocales[]"
									value="{$row.locale|escape}"
									{if $row.title}checked="checked"{/if}
								/>
								<label class="pkp_screen_reader" for="rmmTitle-{$row.locale|escape}">
									{translate key="common.title"} — {$row.name|escape}
								</label>
							</td>
							<td class="rmmCheck">
								<input
									type="checkbox"
									id="rmmAbstract-{$row.locale|escape}"
									name="abstractLocales[]"
									value="{$row.locale|escape}"
									{if $row.abstract}checked="checked"{/if}
								/>
								<label class="pkp_screen_reader" for="rmmAbstract-{$row.locale|escape}">
									{translate key="common.abstract"} — {$row.name|escape}
								</label>
							</td>
							<td class="rmmCheck{if !$keywordsRequired} rmmCheck--off{/if}">
								<input
									type="checkbox"
									id="rmmKeywords-{$row.locale|escape}"
									name="keywordsLocales[]"
									value="{$row.locale|escape}"
									{if $row.keywords}checked="checked"{/if}
								/>
								<label class="pkp_screen_reader" for="rmmKeywords-{$row.locale|escape}">
									{translate key="common.keywords"} — {$row.name|escape}
								</label>
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>

			<p class="rmmHint">{translate key="plugins.generic.requiredMultilingualMetadata.settings.hint"}</p>
		{else}
			<p class="rmmHint">{translate key="plugins.generic.requiredMultilingualMetadata.settings.noLocales"}</p>
		{/if}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
