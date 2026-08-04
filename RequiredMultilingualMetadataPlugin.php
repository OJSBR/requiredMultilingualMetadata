<?php

/**
 * @file RequiredMultilingualMetadataPlugin.php
 *
 * Plugin autoral OJSBR.
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredMultilingualMetadataPlugin
 *
 * @brief Permite exigir título e resumo em idiomas ALÉM do idioma principal da
 *        submissão. O OJS 3.5 só cobra esses metadados no idioma da submissão
 *        (Repo::submission()->validateSubmit()); este plugin acrescenta os
 *        idiomas que a revista escolher, sem alterar o núcleo.
 *
 *        A exigência vale para quem está submetendo. O corpo editorial (gestor
 *        e editor) não é bloqueado ao trabalhar em submissões de terceiros —
 *        legado costuma ter metadados faltando —, mas um gestor que submete o
 *        próprio artigo (isto é, com designação de AUTOR na submissão) entra
 *        na regra como qualquer outro autor.
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
    /** Campos cobertos pelo plugin. Cada um tem sua própria lista de idiomas. */
    public const FIELDS = ['title', 'abstract', 'keywords'];

    /** Template do assistente de submissão, onde as abas de idioma são abertas. */
    public const WIZARD_TEMPLATE = 'submission/wizard.tpl';

    /** Id do formulário de título/resumo dentro do estado do assistente. */
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

        // Regra de fato: acrescenta erros na validação do botão "Enviar".
        Hook::add('Submission::validateSubmit', [$this, 'validateSubmit']);

        // Usabilidade: abre as abas dos idiomas exigidos já no assistente.
        Hook::add('TemplateManager::display', [$this, 'openRequiredLocales']);

        return true;
    }

    //
    // Configuração
    //

    /**
     * Idiomas de metadados ativos na revista (códigos de submissão, ex.: en_US).
     *
     * @return array<int, string>
     */
    public function getActiveLocales(Context $context): array
    {
        return (array) $context->getSupportedSubmissionMetadataLocales();
    }

    /**
     * Idiomas configurados como obrigatórios para um campo, sem filtro algum.
     *
     * @return array<int, string>
     */
    public function getConfiguredLocales(int $contextId, string $field): array
    {
        $value = $this->getSetting($contextId, $field . 'Locales');
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Idiomas realmente exigíveis para um campo nesta submissão: os configurados,
     * menos o idioma da própria submissão (que o núcleo já cobra) e menos os que
     * deixaram de estar ativos na revista.
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
    // Regra
    //

    /**
     * Hook Submission::validateSubmit — acrescenta os erros dos idiomas extras.
     *
     * O núcleo já preencheu $errors com o idioma da submissão; aqui só somamos
     * chaves de locale, nunca sobrescrevemos o que já existe.
     *
     * ATENÇÃO (comportamento do OJS, não do plugin): este hook é disparado no fim de
     * PKP\submission\Repository::validateSubmit(), e a subclasse do OJS
     * (APP\submission\Repository::validateSubmit()) roda DEPOIS e faz
     * `$errors['abstract'] = [$locale => ...]` — atribuição, não merge. Então, quando o
     * resumo está vazio no idioma da submissão (ou estoura o limite de palavras da seção),
     * a mensagem do plugin para os idiomas extras é descartada naquela rodada.
     *
     * Isso não abre buraco na regra: o núcleo só sobrescreve quando ele mesmo colocou um
     * erro bloqueante em `abstract`. A submissão continua barrada; o autor só vê as duas
     * mensagens em rodadas diferentes. O aviso no campo ("Obrigatório também em: …"), que o
     * plugin escreve no assistente, cobre a lacuna visual. O título não sofre disso, porque
     * o núcleo escreve `$errors['title']` antes do hook.
     *
     * Coberto por E02, E09 e E10 em tests/regressao.php.
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
     * A regra não vale para o corpo editorial trabalhando em submissão alheia
     * (legado com metadados faltando não pode travar o editor). Vale para todo
     * mundo que está submetendo como autor, inclusive gestor e editor.
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

        // Designação de AUTOR nesta submissão => está submetendo como autor.
        $isAuthorHere = StageAssignment::withSubmissionIds([$submission->getId()])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->withUserId($user->getId())
            ->get()
            ->isNotEmpty();

        return !$isAuthorHere;
    }

    /**
     * Campos em que a regra do plugin pode incidir agora, nesta revista e nesta seção.
     *
     * - título: sempre;
     * - resumo: a não ser que a seção esteja marcada como "Resumo não obrigatório";
     * - palavras-chave: SÓ quando a revista as exige (Fluxo de Trabalho > Metadados >
     *   Palavras-chave = "Exigir"). Se estiverem como "Solicitar", "Habilitar" ou
     *   desabilitadas, o plugin não encosta nelas, mesmo que haja idioma configurado.
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
     * A revista exige palavras-chave? (só 'require' conta; 'request' e 'enable' não)
     */
    public function isKeywordsRequired(Context $context): bool
    {
        return $context->getData('keywords') === Context::METADATA_REQUIRE;
    }

    /**
     * Um metadado está vazio neste idioma?
     *
     * Título e resumo são texto; palavras-chave são vocabulário controlado, e voltam como
     * lista de strings (ou null quando não há nenhuma). Uma lista só com strings em branco
     * também conta como vazia.
     */
    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                // O vocabulário controlado pode vir como string ou como entry-data.
                $texto = is_array($item) ? ($item['name'] ?? '') : $item;
                if (trim((string) $texto) !== '') {
                    return false;
                }
            }

            return true;
        }

        return trim(strip_tags((string) $value)) === '';
    }

    /**
     * O resumo é obrigatório nesta seção? (respeita "Resumo não obrigatório")
     */
    public function isAbstractRequired(?int $sectionId, Context $context): bool
    {
        if (!$sectionId) {
            return true;
        }
        $section = Repo::section()->get($sectionId, $context->getId());

        return $section ? !$section->getAbstractsNotRequired() : true;
    }

    /**
     * Nome do idioma como o OJS o exibe nos metadados de submissão.
     */
    public function getLocaleName(string $locale): string
    {
        return Locale::getSubmissionLocaleDisplayNames([$locale])[$locale] ?? $locale;
    }

    //
    // Usabilidade no assistente
    //

    /**
     * Hook TemplateManager::display — no assistente de submissão, o núcleo fixa
     * visibleLocales = [idioma da submissão] (PKPSubmissionHandler::getLocalizedForm),
     * então o autor levaria erro num campo que nem está vendo. Aqui abrimos as abas
     * dos idiomas exigidos e anotamos isso na descrição do campo.
     *
     * O hook roda antes de TemplateManager::display() copiar o estado para o template.
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

        $sectionId = $submission->getCurrentPublication()?->getData('sectionId');

        // Idiomas exigidos por campo, já filtrados.
        $byField = [];
        foreach ($this->getApplicableFields($context, $sectionId) as $field) {
            $locales = $this->getEnforceableLocales($context, $field, $submissionLocale);
            if ($locales) {
                $byField[$field] = $locales;
            }
        }

        if (!$byField) {
            return Hook::CONTINUE;
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

                // Abre as abas: idioma da submissão primeiro, depois os exigidos.
                $supported = array_column($section['form']['supportedFormLocales'] ?? [], 'key');
                $visible = [$submissionLocale];
                foreach ($extraLocales as $locale) {
                    if (in_array($locale, $supported, true)) {
                        $visible[] = $locale;
                    }
                }
                $section['form']['visibleLocales'] = array_values(array_unique($visible));

                // Avisa no próprio campo em quais idiomas ele é exigido.
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

        $templateMgr->setState(['steps' => $steps]);

        return Hook::CONTINUE;
    }

    //
    // Boilerplate do plugin
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
