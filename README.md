# Required Multilingual Metadata — OJS and OMP plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.1.1.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS / OMP 3.5](https://github.com/OJSBR/requiredMultilingualMetadata/releases/download/1.1.1.0/requiredMultilingualMetadata-1.1.1.0.tar.gz) — one package for both — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** and **Open Monograph Press (OMP)** that lets
a journal or a press require the **title**, the **abstract** and the **keywords** in metadata
languages **beyond the submission's own language** — something PKP 3.5 cannot do on its own —
**without patching the core** and **without blocking editorial staff** working on legacy
submissions.

> Since 1.1.1.0 the OJS and the OMP editions are the same code, in this repository. The former
> `requiredMultilingualMetadataOmp` repository is archived; its releases stay available there.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| Application | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x and OMP 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.1.0 |

## What it does

In PKP 3.5 the title, the abstract and the keywords are required in **one language only: the
submission's primary language**, chosen by the author on the first step of the wizard. Neither
the journal's default language nor the language the author is browsing in has any effect, and
there is no native setting to require a translation. This plugin adds that.

- **Per-journal settings screen**: one row per active metadata language, with an independent
  checkbox for **Title**, **Abstract** and **Keywords**.
- **Keywords are conditional**: the rule only reaches them when the journal already makes
  keywords mandatory (*Workflow → Metadata → Keywords = Require*). Under *Request*, *Enable* or
  disabled, whatever is ticked in that column is kept but blocks no one — and the settings
  screen says so.
- On **Submit**, adds the errors for the selected languages, in the same shape the wizard
  already renders (`$errors['title']['en_US']`).
- In the wizard, **opens the tabs** for the required languages and notes on the field which
  languages it is required in — so the author is never shown an error for a field they cannot
  see.

### Who is affected

| Situation | Blocked? |
|---|---|
| Author submitting | Yes |
| Manager/editor submitting **their own** article (assigned as Author) | Yes |
| Manager/editor working on someone else's submission | **No** |
| Editing metadata later in the editorial workflow (including incomplete legacy) | **No** |
| Section set to "Abstract not required" | Abstract stays optional in every language |
| Journal not set to *Keywords = Require* | Keywords stay optional in every language |

The editorial exemption is deliberate: migrated archives usually have metadata missing, and the
rule must not stop an editor from saving what is already there.

## Installation

Install under **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
into `plugins/generic/` (ending up as `plugins/generic/requiredMultilingualMetadata/`). Then
enable **Required Multilingual Metadata** in the *Generic* plugins list.

## Configuration

Open the plugin's **Settings** and tick the languages per field. The journal's own metadata
languages come from `Context::getSupportedSubmissionMetadataLocales()`.

The submission's own language is always excluded — OJS already requires it — and languages that
are no longer active in the journal are ignored at runtime, with no need to reconfigure.

> **A detail that trips people up.** The **interface** locale code and the **metadata** locale
> code are different: the interface uses `en`, submission metadata uses `en_US`. The settings
> screen always stores the metadata code, otherwise the rule would never match.

## How it works (technical)

| Hook | Purpose |
|---|---|
| `Submission::validateSubmit` | adds the errors for the extra languages |
| `TemplateManager::display` (`submission/wizard.tpl`) | opens the language tabs and writes the field notice |

No core file is modified. The wizard hook is needed because
`PKPSubmissionHandler::getLocalizedForm()` pins `visibleLocales` to the submission language, so
without it the author would be shown an error on a field they cannot see.

**Known behaviour.** When the abstract is empty in the submission's own language, the plugin's
message about the extra languages does not show in that round: the OJS subclass of
`Repository::validateSubmit()` assigns `$errors['abstract']` **after** the hook runs, replacing
the array. The submission is still blocked (by the core's own error) and the field notice is
still displayed; the author simply sees the two messages in different rounds. Title and
keywords are not affected, because the core writes those **before** the hook.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): the classes against the installed
  PKP, the plugin found by PKP's plugin registry, only configured languages that are still
  enabled being required, keywords only where they are required at all, an error added per
  missing language without touching the core's own errors, nothing added for an exempt user,
  when a value counts as empty (including a controlled vocabulary and empty markup), the tabs
  the wizard opens and the notice on the field, the hooks used, the templates and the 38
  translations. From the application root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/requiredMultilingualMetadata/tests"
  ```

- **Cypress** (`cypress/tests/functional/RequiredMultilingualMetadata.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on OJS and OMP on every push):
  enables the plugin and, given an author account (`authorUser`, `authorPassword`), requires the
  title in a second metadata language, creates a submission as that author through the API and
  checks that the submit endpoint refuses it naming the missing language, and accepts it once
  the title is filled in. The submission is deleted and the settings are put back after the run;
  the check is skipped where the journal or press has a single metadata language. It fails with
  the rule's hook removed.
- Verified on OJS 3.5.0.3 and OMP 3.5.0.5, the same package on both.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome. Please target the branch matching the OJS version you are
working against. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que permite à revista exigir o **título**,
o **resumo** e as **palavras-chave** em idiomas de metadados **além do idioma principal da
submissão** — algo que o OJS 3.5 não faz sozinho — **sem alterar o núcleo do OJS** e **sem
bloquear o corpo editorial** que trabalha em submissões de acervo.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | `stable-3_5_0` *(padrão)* | 1.1.0.1 |

### O que faz

No OJS 3.5, título, resumo e palavras-chave são exigidos em **um único idioma: o idioma
principal da submissão**, escolhido pelo autor no primeiro passo do assistente. Nem o idioma
padrão da revista nem o idioma em que o autor está navegando têm qualquer efeito, e não existe
configuração nativa para exigir a tradução. É isso que o plugin acrescenta.

- **Tela de configuração por revista**: uma linha por idioma de metadados ativo, com um
  checkbox independente para **Título**, **Resumo** e **Palavras-chave**.
- **Palavras-chave são condicionais**: a regra só as alcança quando a revista já as torna
  obrigatórias (*Fluxo de Trabalho → Metadados → Palavras-chave = Exigir*). Em *Solicitar*,
  *Habilitar* ou desabilitado, o que estiver marcado nessa coluna fica guardado, mas não
  bloqueia ninguém — e a tela de configuração avisa isso.
- No **Enviar**, acrescenta os erros dos idiomas marcados, no mesmo formato que o assistente já
  sabe exibir (`$errors['title']['en_US']`).
- No assistente, **abre as abas** dos idiomas exigidos e anota no campo em quais idiomas ele é
  obrigatório — assim o autor nunca recebe erro num campo que não está vendo.

#### Quem é afetado

| Situação | Bloqueia? |
|---|---|
| Autor submetendo | Sim |
| Gestor/editor submetendo o **próprio** artigo (designado como Autor) | Sim |
| Gestor/editor trabalhando em submissão de terceiro | **Não** |
| Edição de metadados no fluxo editorial (inclusive acervo incompleto) | **Não** |
| Seção marcada como "Resumo não obrigatório" | Resumo continua opcional em todo idioma |
| Revista sem *Palavras-chave = Exigir* | Palavras-chave continuam opcionais em todo idioma |

A isenção do corpo editorial é deliberada: acervo migrado costuma ter metadados faltando, e a
regra não pode impedir o editor de salvar o que já existe.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta em
`plugins/generic/` (ficando `plugins/generic/requiredMultilingualMetadata/`). Depois ative o
**Metadados Obrigatórios em Vários Idiomas** na lista de plugins *Genéricos*.

### Configuração

Nas configurações do plugin, marque os idiomas por campo. Os idiomas de metadados da revista
vêm de `Context::getSupportedSubmissionMetadataLocales()`.

O idioma da própria submissão é sempre excluído — o OJS já o exige — e idiomas que deixaram de
estar ativos na revista são ignorados em tempo de execução, sem precisar reconfigurar.

> **Detalhe que engana.** O código do idioma da **interface** e o do idioma de **metadados** são
> diferentes: a interface usa `en`, os metadados usam `en_US`. A tela grava sempre o código de
> metadados, senão a regra nunca casaria.

### Testes

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`) e Cypress em `cypress/tests/functional/`
(rodado pelo [pkp-github-actions](https://github.com/pkp/pkp-github-actions) no OJS e no OMP a
cada push), com o comando da seção em inglês. A suíte cobre as classes contra o PKP instalado, o
plugin encontrado pelo registro de plugins, só os idiomas configurados que continuam ativos sendo
exigidos, palavras-chave só onde são exigidas, um erro por idioma faltando sem tocar nos erros do
núcleo, nada acrescentado para quem é isento, quando um valor conta como vazio (inclusive
vocabulário controlado e HTML vazio), as abas que o assistente abre e o aviso no campo, os hooks
usados, os templates e as 38 traduções. O Cypress liga o plugin e, com uma conta de autor
(`authorUser`, `authorPassword`), exige o título num segundo idioma de metadados, cria uma
submissão como esse autor pela API e confere que o envio é recusado nomeando o idioma que falta e
aceito depois que o título é preenchido; a submissão é apagada e a configuração volta ao que era no
fim. Verificado no OJS 3.5.0.3 e no OMP 3.5.0.5, com o mesmo pacote.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
