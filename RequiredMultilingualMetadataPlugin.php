<?php

/**
 * @file plugins/generic/requiredMultilingualMetadata/RequiredMultilingualMetadataPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredMultilingualMetadataPlugin
 *
 * @brief Requires the title, the abstract and the keywords in languages BESIDES the
 *        submission's own. PKP 3.5 asks for this metadata in the submission language
 *        only (Repo::submission()->validateSubmit()); the plugin adds the languages the
 *        journal or press chooses, without patching the core.
 *
 *        The rule applies to whoever is submitting. Editorial staff working on someone
 *        else's submission is not blocked — legacy material often lacks metadata — but a
 *        manager submitting their own work (that is, with an AUTHOR assignment on the
 *        submission) is bound by it like any other author.
 */

namespace APP\plugins\generic\requiredMultilingualMetadata;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

class RequiredMultilingualMetadataPlugin extends GenericPlugin
{
    /** The fields the plugin covers. Each one has its own list of languages. */
    public const FIELDS = ['title', 'abstract', 'keywords'];

    /** The submission wizard template, where the language tabs are opened. */
    public const WIZARD_TEMPLATE = 'submission/wizard.tpl';

    /** Id of the title/abstract form inside the wizard's state. */
    public const TITLE_ABSTRACT_FORM = 'titleAbstract';

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if (Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return true;
        }

        // The rule itself: more errors on the validation of the "Submit" button.
        Hook::add('Submission::validateSubmit', [$this, 'validateSubmit']);

        // Usability: the tabs of the required languages are opened in the wizard.
        Hook::add('TemplateManager::display', [$this, 'openRequiredLocales']);

        return true;
    }

    //
    // Settings
    //

    /**
     * The metadata languages enabled in the journal or press (submission codes).
     *
     * @return array<int, string>
     */
    public function getActiveLocales(Context $context): array
    {
        return (array) $context->getSupportedSubmissionMetadataLocales();
    }

    /**
     * The languages configured as required for a field, unfiltered.
     *
     * @return array<int, string>
     */
    public function getConfiguredLocales(int $contextId, string $field): array
    {
        $value = $this->getSetting($contextId, $field . 'Locales');
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * The languages a field can really be required in for this submission: the configured
     * ones, without the submission's own language (the core already asks for it) and
     * without those no longer enabled in the journal or press.
     *
     * @return array<int, string>
     */
    public function getEnforceableLocales(Context $context, string $field, string $submissionLocale): array
    {
        $active = $this->getActiveLocales($context);

        return array_values(array_filter(
            $this->getConfiguredLocales($context->getId(), $field),
            fn (string $locale): bool => $locale !== $submissionLocale && in_array($locale, $active, true)
        ));
    }

    //
    // The rule
    //

    /**
     * Hook Submission::validateSubmit — adds the errors of the extra languages.
     *
     * The core has already filled $errors for the submission language; only locale keys
     * are added here, and nothing that is already there is overwritten.
     *
     * Note, and this is the core's behaviour, not the plugin's: the hook is called at the
     * end of PKP\submission\Repository::validateSubmit(), and the application's subclass
     * (APP\submission\Repository::validateSubmit()) runs AFTERWARDS and does
     * `$errors['abstract'] = [$locale => ...]` — an assignment, not a merge. So when the
     * abstract is empty in the submission language (or, in OJS, longer than the section's
     * word limit), the plugin's message for the extra languages is dropped in that round.
     *
     * The rule is not weakened by it: the core only overwrites when it has put a blocking
     * error on `abstract` itself, so the submission stays blocked; the author simply sees
     * the two messages in different rounds. The notice the plugin writes on the field in
     * the wizard ("Also required in: …") covers the visual gap. The title is not affected,
     * because the core writes `$errors['title']` before the hook.
     */
    public function validateSubmit(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $submission = $args[1]; /** @var Submission $submission */
        $context = $args[2]; /** @var Context $context */

        if ($this->isExempt($submission, $context)) {
            return Hook::CONTINUE;
        }

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return Hook::CONTINUE;
        }

        $submissionLocale = (string) $submission->getData('locale');

        foreach ($this->getApplicableFields($context, $publication->getData('sectionId')) as $field) {
            foreach ($this->getEnforceableLocales($context, $field, $submissionLocale) as $locale) {
                if (!$this->isEmptyValue($publication->getData($field, $locale))) {
                    continue;
                }
                $errors[$field][$locale] = [
                    __('plugins.generic.requiredMultilingualMetadata.error.required', [
                        'language' => $this->getLocaleName($locale),
                    ]),
                ];
            }
        }

        return Hook::CONTINUE;
    }

    /**
     * The rule does not apply to editorial staff working on someone else's submission
     * (legacy material with missing metadata must not block an editor). It applies to
     * everyone submitting as an author, managers and editors included.
     */
    public function isExempt(Submission $submission, Context $context): bool
    {
        $user = Application::get()->getRequest()->getUser();
        if (!$user) {
            return false;
        }

        $isEditorial = $user->hasRole(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            $context->getId()
        ) || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID);

        if (!$isEditorial) {
            return false;
        }

        // An AUTHOR assignment on this submission means they are submitting as an author.
        $isAuthorHere = StageAssignment::withSubmissionIds([$submission->getId()])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->withUserId($user->getId())
            ->get()
            ->isNotEmpty();

        return !$isAuthorHere;
    }

    /**
     * The fields the rule can apply to now, in this journal or press and this section.
     *
     * - title: always;
     * - abstract: unless the section is marked "Abstracts not required" (OJS only: the
     *   series of a press have no such flag);
     * - keywords: ONLY when the journal or press requires them (Workflow > Metadata >
     *   Keywords = "Require"). With "Request", "Enable" or disabled, the plugin leaves
     *   them alone, even when a language is configured.
     *
     * @return array<int, string>
     */
    public function getApplicableFields(Context $context, ?int $sectionId): array
    {
        return array_values(array_filter(
            self::FIELDS,
            fn (string $field): bool => match ($field) {
                'abstract' => $this->isAbstractRequired($sectionId, $context),
                'keywords' => $this->isKeywordsRequired($context),
                default => true,
            }
        ));
    }

    /**
     * Does the journal or press require keywords? ('request' and 'enable' do not count.)
     */
    public function isKeywordsRequired(Context $context): bool
    {
        return $context->getData('keywords') === Context::METADATA_REQUIRE;
    }

    /**
     * Is a metadata field empty in this language?
     *
     * Title and abstract are text; keywords are a controlled vocabulary and come back as a
     * list of strings (or null when there is none). A list of blank strings counts as empty.
     */
    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                // The controlled vocabulary comes as a string or as entry data.
                $text = is_array($item) ? ($item['name'] ?? '') : $item;
                if (trim((string) $text) !== '') {
                    return false;
                }
            }

            return true;
        }

        return trim(strip_tags((string) $value)) === '';
    }

    /**
     * Is the abstract required in this section? In OJS a section can be marked "Abstracts
     * not required"; the series of a press have no such flag, so there the abstract is
     * always required.
     */
    public function isAbstractRequired(?int $sectionId, Context $context): bool
    {
        if (!$sectionId) {
            return true;
        }
        $section = Repo::section()->get($sectionId, $context->getId());

        return !$section || !method_exists($section, 'getAbstractsNotRequired') || !$section->getAbstractsNotRequired();
    }

    /**
     * The language name as the application shows it in submission metadata.
     */
    public function getLocaleName(string $locale): string
    {
        return Locale::getSubmissionLocaleDisplayNames([$locale])[$locale] ?? $locale;
    }

    //
    // Usability in the wizard
    //

    /**
     * Hook TemplateManager::display — in the submission wizard the core sets
     * visibleLocales = [the submission language] (PKPSubmissionHandler::getLocalizedForm),
     * so the author would be given an error on a field they cannot even see. The tabs of
     * the required languages are opened here, and the field says so in its description.
     *
     * The hook runs before TemplateManager::display() copies the state to the template.
     */
    public function openRequiredLocales(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        $template = $args[1];

        if ($template !== self::WIZARD_TEMPLATE) {
            return Hook::CONTINUE;
        }

        $context = Application::get()->getRequest()->getContext();
        $steps = $templateMgr->getState('steps');
        $submissionState = $templateMgr->getState('submission');

        if (!$context || !is_array($steps) || !is_array($submissionState)) {
            return Hook::CONTINUE;
        }

        $submission = Repo::submission()->get((int) ($submissionState['id'] ?? 0));
        if (!$submission || $this->isExempt($submission, $context)) {
            return Hook::CONTINUE;
        }

        $submissionLocale = (string) ($submissionState['locale'] ?? '');
        if ($submissionLocale === '') {
            return Hook::CONTINUE;
        }

        $changed = $this->wizardSteps($steps, $context, $submission, $submissionLocale);
        if ($changed === $steps) {
            return Hook::CONTINUE;
        }
        $steps = $changed;

        // The notice written on the field is styled by the plugin's stylesheet.
        $templateMgr->addStyleSheet(
            'requiredMultilingualMetadata',
            Application::get()->getRequest()->getBaseUrl() . '/' . $this->getPluginPath() . '/css/settingsForm.css',
            ['contexts' => 'backend']
        );
        $templateMgr->setState(['steps' => $steps]);

        return Hook::CONTINUE;
    }


    /**
     * The steps of the wizard with the tabs of the required languages open and the notice
     * on each field, or the steps unchanged when there is nothing to require.
     *
     * @param array $steps The `steps` state of the wizard
     *
     * @return array The steps, changed or not
     */
    public function wizardSteps(array $steps, Context $context, Submission $submission, string $submissionLocale): array
    {
        $sectionId = $submission->getCurrentPublication()?->getData('sectionId');

        // The required languages of each field, already filtered.
        $byField = [];
        foreach ($this->getApplicableFields($context, $sectionId) as $field) {
            $locales = $this->getEnforceableLocales($context, $field, $submissionLocale);
            if ($locales) {
                $byField[$field] = $locales;
            }
        }

        if (!$byField) {
            return $steps;
        }

        $extraLocales = array_values(array_unique(array_merge(...array_values($byField))));

        foreach ($steps as &$step) {
            if (!isset($step['sections']) || !is_array($step['sections'])) {
                continue;
            }
            foreach ($step['sections'] as &$section) {
                if (($section['form']['id'] ?? '') !== self::TITLE_ABSTRACT_FORM) {
                    continue;
                }

                // The tabs: the submission language first, then the required ones.
                $supported = array_column($section['form']['supportedFormLocales'] ?? [], 'key');
                $visible = [$submissionLocale];
                foreach ($extraLocales as $locale) {
                    if (in_array($locale, $supported, true)) {
                        $visible[] = $locale;
                    }
                }
                $section['form']['visibleLocales'] = array_values(array_unique($visible));

                // The field itself says in which languages it is required.
                foreach ($section['form']['fields'] as &$field) {
                    $name = $field['name'] ?? '';
                    if (!isset($byField[$name])) {
                        continue;
                    }
                    $names = array_map([$this, 'getLocaleName'], $byField[$name]);
                    $notice = '<p class="rmmNotice"><strong>'
                        . htmlspecialchars(__('plugins.generic.requiredMultilingualMetadata.field.notice', [
                            'languages' => implode(', ', $names),
                        ]), ENT_QUOTES, 'UTF-8')
                        . '</strong></p>';
                    $field['description'] = ($field['description'] ?? '') . $notice;
                }
                unset($field);
            }
            unset($section);
        }
        unset($step);

        return $steps;
    }

    //
    // Plugin boilerplate
    //

    /**
     * @copydoc Plugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        $url = $router->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $form = new RequiredMultilingualMetadataSettingsForm($this, $request->getContext());
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();
        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.requiredMultilingualMetadata.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.requiredMultilingualMetadata.description');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\requiredMultilingualMetadata\RequiredMultilingualMetadataPlugin', '\RequiredMultilingualMetadataPlugin');
}
