<?php

/**
 * @file plugins/generic/requiredMultilingualMetadata/tests/RequiredMultilingualMetadataTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredMultilingualMetadataTest
 *
 * @brief Which languages are required of which field, when a metadata value counts
 *        as empty, who the rule applies to, and the tabs opened in the wizard.
 */

namespace APP\plugins\generic\requiredMultilingualMetadata\tests;

use APP\plugins\generic\requiredMultilingualMetadata\RequiredMultilingualMetadataPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\context\Context;
use PKP\plugins\Hook;
use PKP\tests\PKPTestCase;
use ReflectionMethod;

#[CoversClass(RequiredMultilingualMetadataPlugin::class)]
class RequiredMultilingualMetadataTest extends PKPTestCase
{
    /**
     * A plugin with the given settings, exempting nobody unless told to.
     */
    protected function plugin(array $settings, bool $exempt = false): RequiredMultilingualMetadataPlugin
    {
        return new class ($settings, $exempt) extends RequiredMultilingualMetadataPlugin {
            public function __construct(private array $settings, private bool $exempt)
            {
                parent::__construct();
            }

            public function getSetting($contextId, $name)
            {
                return $this->settings[$name] ?? null;
            }

            public function isExempt(Submission $submission, Context $context): bool
            {
                return $this->exempt;
            }

            public function getLocaleName(string $locale): string
            {
                return strtoupper($locale);
            }
        };
    }

    /**
     * A journal with the given metadata languages, requiring keywords or not.
     */
    protected function context(array $locales, bool $requireKeywords = false): Context
    {
        $context = new class () extends Context {
            public function getAssocType(): int
            {
                return 0;
            }

            public function getDAO(): \PKP\db\DAO
            {
                throw new \Exception('not needed');
            }
        };
        $context->setId(1);
        $context->setData('supportedSubmissionMetadataLocales', $locales);
        $context->setData('keywords', $requireKeywords ? Context::METADATA_REQUIRE : Context::METADATA_REQUEST);
        return $context;
    }

    protected function submission(string $locale, array $publicationData = []): Submission
    {
        $publication = new Publication();
        foreach ($publicationData as $field => $values) {
            foreach ($values as $valueLocale => $value) {
                $publication->setData($field, $value, $valueLocale);
            }
        }
        $submission = new class () extends Submission {
            public ?Publication $current = null;

            public function getCurrentPublication(): ?Publication
            {
                return $this->current;
            }
        };
        $submission->setId(7);
        $submission->setData('locale', $locale);
        $submission->current = $publication;
        return $submission;
    }

    public function testOnlyConfiguredLanguagesThatAreStillEnabledAreRequired(): void
    {
        $plugin = $this->plugin(['titleLocales' => ['en', 'es', 'de'], 'abstractLocales' => 'not an array']);
        $context = $this->context(['pt_BR', 'en', 'es']);

        // The submission's own language is left out (the core asks for it), and so is a
        // language the journal no longer has.
        $this->assertSame(['es'], $plugin->getEnforceableLocales($context, 'title', 'en'));
        $this->assertSame(['en', 'es'], $plugin->getEnforceableLocales($context, 'title', 'pt_BR'));
        $this->assertSame([], $plugin->getEnforceableLocales($context, 'abstract', 'pt_BR'));
        $this->assertSame([], $plugin->getEnforceableLocales($context, 'keywords', 'pt_BR'));
    }

    public function testKeywordsAreOnlyRequiredWhereTheyAreRequiredAtAll(): void
    {
        $plugin = $this->plugin([]);
        $this->assertFalse($plugin->isKeywordsRequired($this->context(['en'])));
        $this->assertTrue($plugin->isKeywordsRequired($this->context(['en'], true)));

        $this->assertSame(['title', 'abstract'], $plugin->getApplicableFields($this->context(['en']), null));
        $this->assertSame(['title', 'abstract', 'keywords'], $plugin->getApplicableFields($this->context(['en'], true), null));
    }

    public function testAnErrorIsAddedForEachMissingLanguageWithoutTouchingTheCoreErrors(): void
    {
        $plugin = $this->plugin(['titleLocales' => ['en', 'es'], 'abstractLocales' => ['en']]);
        $context = $this->context(['pt_BR', 'en', 'es']);
        $submission = $this->submission('pt_BR', [
            'title' => ['pt_BR' => 'Título', 'en' => '   ', 'es' => 'Título'],
            'abstract' => ['pt_BR' => '<p>Resumo</p>', 'en' => '<p> </p>'],
        ]);

        $errors = ['files' => ['A file is needed.']];
        $plugin->validateSubmit('Submission::validateSubmit', [&$errors, $submission, $context]);

        // Blank in English, filled in Spanish.
        $this->assertArrayHasKey('en', $errors['title']);
        $this->assertArrayNotHasKey('es', $errors['title']);
        // An abstract of empty markup counts as missing.
        $this->assertArrayHasKey('en', $errors['abstract']);
        // What the core had put there is untouched.
        $this->assertSame(['A file is needed.'], $errors['files']);
    }

    public function testNothingIsAddedForAnExemptUserOrWithoutAPublication(): void
    {
        $context = $this->context(['pt_BR', 'en']);
        $submission = $this->submission('pt_BR', ['title' => ['pt_BR' => 'Título']]);

        $errors = [];
        $this->plugin(['titleLocales' => ['en']], true)->validateSubmit('Submission::validateSubmit', [&$errors, $submission, $context]);
        $this->assertSame([], $errors);

        $withoutPublication = $this->submission('pt_BR');
        $withoutPublication->current = null;
        $this->plugin(['titleLocales' => ['en']])->validateSubmit('Submission::validateSubmit', [&$errors, $withoutPublication, $context]);
        $this->assertSame([], $errors);
    }

    public function testKeywordsCountAsFilledOnlyWithAWordInThem(): void
    {
        $isEmptyValue = new ReflectionMethod(RequiredMultilingualMetadataPlugin::class, 'isEmptyValue');
        $isEmptyValue->setAccessible(true);
        $plugin = $this->plugin([]);

        $this->assertTrue($isEmptyValue->invoke($plugin, null));
        $this->assertTrue($isEmptyValue->invoke($plugin, []));
        $this->assertTrue($isEmptyValue->invoke($plugin, ['  ', '']));
        $this->assertTrue($isEmptyValue->invoke($plugin, [['name' => ' ']]));
        $this->assertFalse($isEmptyValue->invoke($plugin, ['keyword']));
        // The controlled vocabulary also comes as entry data.
        $this->assertFalse($isEmptyValue->invoke($plugin, [['name' => 'keyword']]));
        $this->assertTrue($isEmptyValue->invoke($plugin, '<p> </p>'));
        $this->assertFalse($isEmptyValue->invoke($plugin, '<p>text</p>'));
    }

    public function testTheWizardOpensTheTabsOfTheRequiredLanguagesAndSaysSoOnTheField(): void
    {
        $plugin = $this->plugin(['titleLocales' => ['en']]);
        $steps = [[
            'sections' => [[
                'form' => [
                    'id' => RequiredMultilingualMetadataPlugin::TITLE_ABSTRACT_FORM,
                    'supportedFormLocales' => [['key' => 'pt_BR'], ['key' => 'en']],
                    'visibleLocales' => ['pt_BR'],
                    'fields' => [['name' => 'title', 'description' => 'Original.'], ['name' => 'abstract']],
                ],
            ]],
        ]];

        $state = $plugin->wizardSteps($steps, $this->context(['pt_BR', 'en']), $this->submission('pt_BR'), 'pt_BR');

        $form = $state[0]['sections'][0]['form'];
        $this->assertSame(['pt_BR', 'en'], $form['visibleLocales']);
        $this->assertStringContainsString('Original.', $form['fields'][0]['description']);
        $this->assertStringContainsString('rmmNotice', $form['fields'][0]['description']);
        // The command line has no locale loaded, so the key itself comes back; what matters
        // here is that the notice carries the languages of that field.
        $this->assertStringContainsString(
            __('plugins.generic.requiredMultilingualMetadata.field.notice', ['languages' => 'EN']),
            $form['fields'][0]['description']
        );
        // The abstract has no language of its own configured, so it gets no notice.
        $this->assertArrayNotHasKey('description', $form['fields'][1]);
    }

    public function testTheWizardIsLeftAloneWithNothingToRequire(): void
    {
        $steps = [['sections' => [['form' => ['id' => RequiredMultilingualMetadataPlugin::TITLE_ABSTRACT_FORM, 'visibleLocales' => ['pt_BR'], 'fields' => []]]]]];
        $plugin = $this->plugin([]);

        $this->assertSame($steps, $plugin->wizardSteps($steps, $this->context(['pt_BR', 'en']), $this->submission('pt_BR'), 'pt_BR'));
    }

    public function testOnlyTheHooksOfTheRuleAndOfTheWizardAreUsed(): void
    {
        preg_match_all("/Hook::add\('([^']+)'/", (string) file_get_contents(dirname(__DIR__) . '/RequiredMultilingualMetadataPlugin.php'), $matches);
        $this->assertSame(['Submission::validateSubmit', 'TemplateManager::display'], $matches[1]);
        $this->assertSame(Hook::CONTINUE, false);
    }
}
