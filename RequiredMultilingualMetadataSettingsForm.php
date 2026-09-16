<?php

/**
 * @file plugins/generic/requiredMultilingualMetadata/RequiredMultilingualMetadataSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredMultilingualMetadataSettingsForm
 *
 * @brief The languages in which the title, the abstract and the keywords become
 *        required, besides the language of each submission.
 */

namespace APP\plugins\generic\requiredMultilingualMetadata;

use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class RequiredMultilingualMetadataSettingsForm extends Form
{
    public function __construct(private RequiredMultilingualMetadataPlugin $plugin, private Context $context)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * The names of the form fields (titleLocales, abstractLocales, keywordsLocales).
     *
     * @return array<int, string>
     */
    private function fieldNames(): array
    {
        return array_map(
            fn (string $field): string => $field . 'Locales',
            RequiredMultilingualMetadataPlugin::FIELDS
        );
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        foreach (RequiredMultilingualMetadataPlugin::FIELDS as $field) {
            $this->setData(
                $field . 'Locales',
                $this->plugin->getConfiguredLocales($this->context->getId(), $field)
            );
        }
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars($this->fieldNames());
        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = TemplateManager::getManager($request);

        $selected = [];
        foreach (RequiredMultilingualMetadataPlugin::FIELDS as $field) {
            $value = $this->getData($field . 'Locales');
            $selected[$field] = is_array($value) ? $value : [];
        }

        // One row per metadata language enabled in the journal or press.
        $rows = [];
        foreach ($this->plugin->getActiveLocales($this->context) as $locale) {
            $row = [
                'locale' => $locale,
                'name' => $this->plugin->getLocaleName($locale),
                'isDefaultSubmissionLocale' => $locale === $this->context->getData('supportedDefaultSubmissionLocale'),
            ];
            foreach (RequiredMultilingualMetadataPlugin::FIELDS as $field) {
                $row[$field] = in_array($locale, $selected[$field], true);
            }
            $rows[] = $row;
        }

        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'settingsStyleUrl' => $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/settingsForm.css',
            'localeRows' => $rows,
            // The keywords column only has an effect where keywords are required.
            'keywordsRequired' => $this->plugin->isKeywordsRequired($this->context),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $active = $this->plugin->getActiveLocales($this->context);

        foreach (RequiredMultilingualMetadataPlugin::FIELDS as $field) {
            $value = $this->getData($field . 'Locales');
            $value = is_array($value) ? $value : [];

            // Only language codes the journal or press really has enabled are saved.
            $value = array_values(array_unique(array_filter(
                $value,
                fn ($locale): bool => is_string($locale) && in_array($locale, $active, true)
            )));

            $this->plugin->updateSetting($this->context->getId(), $field . 'Locales', $value, 'object');
        }

        parent::execute(...$functionArgs);
    }
}
