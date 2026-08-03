<?php

/**
 * @file RequiredMultilingualMetadataSettingsForm.php
 *
 * Plugin autoral OJSBR.
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredMultilingualMetadataSettingsForm
 *
 * @brief Escolha dos idiomas em que título e resumo passam a ser obrigatórios,
 *        além do idioma principal de cada submissão.
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
     * Nomes dos campos do formulário (titleLocales, abstractLocales).
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

        // Uma linha por idioma de metadados ativo na revista.
        $rows = [];
        foreach ($this->plugin->getActiveLocales($this->context) as $locale) {
            $rows[] = [
                'locale' => $locale,
                'name' => $this->plugin->getLocaleName($locale),
                'title' => in_array($locale, $selected['title'], true),
                'abstract' => in_array($locale, $selected['abstract'], true),
                'isDefaultSubmissionLocale' => $locale === $this->context->getData('supportedDefaultSubmissionLocale'),
            ];
        }

        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'localeRows' => $rows,
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

            // Só grava códigos de idioma que a revista realmente tem ativos.
            $value = array_values(array_unique(array_filter(
                $value,
                fn ($locale): bool => is_string($locale) && in_array($locale, $active, true)
            )));

            $this->plugin->updateSetting($this->context->getId(), $field . 'Locales', $value, 'object');
        }

        parent::execute(...$functionArgs);
    }
}
