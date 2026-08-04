# Required Multilingual Metadata — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.1.0.1-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/requiredMultilingualMetadata/releases/download/1.1.0.1/requiredMultilingualMetadata-1.1.0.1.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that lets a journal require the **title**,
the **abstract** and the **keywords** in metadata languages **beyond the submission's own
language** — something OJS 3.5 cannot do on its own — **without patching OJS core** and
**without blocking editorial staff** working on legacy submissions.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.0.1 |

## What it does

In OJS 3.5 the title, the abstract and the keywords are required in **one language only: the
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
keywords are not affected, because the core writes those **before** the hook. Cases `E09`,
`E10` and `K10` in the test suite lock this invariant down.

## Tests

`tests/CASOS.md` (Portuguese) lists the full catalogue of 74 cases and what each suite covers.

```bash
php plugins/generic/requiredMultilingualMetadata/tests/regressao.php
```

```bash
php plugins/generic/requiredMultilingualMetadata/tests/regressao_http.php
```

The first suite calls the real OJS validation for settings, rule, roles, section handling, the
keywords condition and core non-regression. The second logs into the site for real and covers
the wizard, the settings screen and the end-to-end submission.

Both restore everything they touch — plugin settings, section, journal languages, passwords,
users and the submissions they create — and exit non-zero if any case fails. **Run them on a
test installation, never in production**: they create and delete submissions, a temporary
journal and a temporary manager account.

Last run on OJS 3.5.0.3: **60/60** and **14/14**.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

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

O catálogo completo, com 74 casos, está em [`tests/CASOS.md`](tests/CASOS.md). São duas
baterias: `tests/regressao.php` chama a validação real do OJS (configuração, regra, papéis,
seção, condição das palavras-chave e não-regressão do núcleo) e `tests/regressao_http.php` loga
de verdade no site e cobre o assistente, a tela de configuração e a submissão ponta a ponta.

As duas restauram tudo o que tocam e devolvem código de saída diferente de zero se algum caso
falhar. **Rode só em instalação de teste**: elas criam e apagam submissões, uma revista
temporária e um gestor temporário. Última execução no OJS 3.5.0.3: **60/60** e **14/14**.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
