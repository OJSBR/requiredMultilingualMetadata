{**
 * templates/settingsForm.tpl
 *
 * Plugin autoral OJSBR.
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Escolha dos idiomas em que título e resumo passam a ser obrigatórios.
 *
 * NOTA: os checkboxes são escritos à mão de propósito. O {fbvElement type="checkbox"}
 * da PKP renderiza um <li> cru (lib/pkp/templates/form/checkbox.tpl) que só é válido
 * dentro de {fbvFormSection list=true}; numa tabela ele seria expulso pelo parser.
 *}
<script>
	$(function() {ldelim}
		$('#requiredMultilingualMetadataSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<style>
	.rmmTable {ldelim} width:100%; border-collapse:collapse; margin:1em 0; {rdelim}
	.rmmTable th, .rmmTable td {ldelim} padding:.7em .9em; border-bottom:1px solid #e3e9ef; text-align:left; {rdelim}
	.rmmTable thead th {ldelim} background:#f4f7fb; color:#33414e; font-size:.92em; {rdelim}
	.rmmTable tbody tr:hover {ldelim} background:#fafbfc; {rdelim}
	.rmmTable td.rmmCheck, .rmmTable th.rmmCheck {ldelim} text-align:center; width:9em; {rdelim}
	.rmmTable input[type="checkbox"] {ldelim} width:16px; height:16px; margin:0; cursor:pointer; {rdelim}
	.rmmLocaleName {ldelim} font-weight:700; color:#16232f; {rdelim}
	.rmmLocaleCode {ldelim} display:block; font-size:.82em; color:#61707e; margin-top:.15em; {rdelim}
	.rmmTag {ldelim}
		display:inline-block; margin-left:.5em; padding:.15em .6em; border-radius:999px;
		font-size:.75em; background:#eef5ef; color:#4a6b4d; border:1px solid #cfe3d1; font-weight:600;
	{rdelim}
	.rmmNotice {ldelim}
		margin:1em 0; padding:.9em 1.1em; border:1px solid #e6cf6a; border-left:4px solid #d8b520;
		background:#fffbe9; border-radius:6px; line-height:1.5;
	{rdelim}
	.rmmNotice strong {ldelim} color:#6b5600; {rdelim}
	.rmmHint {ldelim} color:#61707e; margin:.6em 0 0; font-size:.93em; line-height:1.5; {rdelim}
</style>

<form
	class="pkp_form"
	id="requiredMultilingualMetadataSettingsForm"
	method="post"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="requiredMultilingualMetadataSettingsFormNotification"}

	<div id="description">{translate key="plugins.generic.requiredMultilingualMetadata.settings.description"}</div>

	<div class="rmmNotice">
		<strong>{translate key="plugins.generic.requiredMultilingualMetadata.settings.notice.title"}</strong><br />
		{translate key="plugins.generic.requiredMultilingualMetadata.settings.notice.body"}
	</div>

	{fbvFormArea id="requiredMultilingualMetadataArea"}
		{if $localeRows}
			<table class="rmmTable">
				<thead>
					<tr>
						<th>{translate key="plugins.generic.requiredMultilingualMetadata.settings.column.language"}</th>
						<th class="rmmCheck">{translate key="common.title"}</th>
						<th class="rmmCheck">{translate key="common.abstract"}</th>
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
