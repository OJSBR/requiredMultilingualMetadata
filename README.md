# Required Multilingual Metadata — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.1.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

A generic plugin for **Open Journal Systems (OJS)** that lets a journal require the
**title**, the **abstract** and the **keywords** in metadata languages **beyond the
submission's own language** — something OJS 3.5 cannot do on its own — **without patching OJS
core** and **without blocking editorial staff** working on legacy submissions.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.0.0 |

## Why it exists

In OJS 3.5 the title, the abstract and the keywords are required in **one language only:
the submission's primary language**, chosen by the author on the first step of the wizard.
Neither the journal's default language nor the language the author is browsing in has any
effect:

```php
// lib/pkp/classes/submission/Repository.php :: validateSubmit()
$locale = $submission->getData('locale');
if (!$publication->getData('title', $locale)) { … }
```

This was verified empirically on OJS 3.5.0-3 with 28 real submissions: a Portuguese journal
with a Portuguese submission, an English journal with a Portuguese submission, and an English
journal with an English-browsing author and a Portuguese submission all produced **exactly the
same result** — only `pt_BR` was required. A submission whose title exists only in `en_US` is
rejected as if the field were empty.

There is no native setting to require the translation. That is what this plugin adds.

## What it does

- **Per-journal settings screen**: one row per active metadata language, with an independent
  checkbox for **Title**, **Abstract** and **Keywords**.
- **Keywords are conditional**: the rule only reaches them when the journal already makes
  keywords mandatory (*Workflow → Metadata → Keywords = Require*). Under *Request*, *Enable*
  or disabled, whatever is ticked in that column is kept but blocks no one — and the settings
  screen says so.
- On **Submit**, adds the errors for the selected languages, in the same shape the wizard
  already renders (`$errors['title']['en_US']`).
- In the wizard, **opens the tabs** for the required languages and notes on the field which
  languages it is required in — so the author is never shown an error for a field they cannot
  see.

## Who is affected

| Situation | Blocked? |
|---|---|
| Author submitting | Yes |
| Manager/editor submitting **their own** article (assigned as Author) | Yes |
| Manager/editor working on someone else's submission | **No** |
| Editing metadata later in the editorial workflow (including incomplete legacy) | **No** |
| Section set to "Abstract not required" | Abstract stays optional in every language |
| Journal not set to *Keywords = Require* | Keywords stay optional in every language |

The editorial exemption is deliberate: migrated archives usually have metadata missing, and
the rule must not stop an editor from saving what is already there.

## Configuration

Enable the plugin under **Settings → Website → Plugins**, then open its **Settings** and tick
the languages. The journal's own metadata languages come from
`Context::getSupportedSubmissionMetadataLocales()`.

The submission's own language is always excluded — OJS already requires it — and languages
that are no longer active in the journal are ignored at runtime, with no need to reconfigure.

## How it hooks in

| Hook | Purpose |
|---|---|
| `Submission::validateSubmit` | adds the errors for the extra languages |
| `TemplateManager::display` (`submission/wizard.tpl`) | opens the language tabs and writes the field notice |

No core file is modified.

## A detail that trips people up

The **interface** locale code and the **metadata** locale code are different: the interface
uses `en`, submission metadata uses `en_US`. The settings screen always stores the metadata
code, otherwise the rule would never match.

## Known behaviour

When the abstract is empty in the submission's own language, the plugin's message about the
extra languages does not show in that round: the OJS subclass of
`Repository::validateSubmit()` assigns `$errors['abstract']` **after** the hook runs, replacing
the array. The submission is still blocked (by the core's own error) and the field notice is
still displayed; the author simply sees the two messages in different rounds. The title is not
affected, because the core writes `$errors['title']` **before** the hook.

Cases `E09` and `E10` in the test suite lock this invariant down: if a future OJS release
changes that order, the suite reports it. Keywords are **not** affected: the core writes
`$errors['keywords']` inside the `getRequiredMetadata()` loop in the PKP class, before the
hook, so both locales coexist there (case `K10`).

## Testing

`tests/CASOS.md` (Portuguese) lists the full catalogue of 74 cases and what each suite covers.

```bash
php plugins/generic/requiredMultilingualMetadata/tests/regressao.php
```

```bash
php plugins/generic/requiredMultilingualMetadata/tests/regressao_http.php
```

The first suite calls the real OJS validation for settings, rule, roles, section handling and
core non-regression. The second logs into the site for real and covers the wizard, the
settings screen and the end-to-end submission.

Both restore everything they touch — plugin settings, section, journal languages, passwords,
users and the submissions they create — and exit non-zero if any case fails. **Run them on a
test installation, never in production**: they create and delete submissions, a temporary
journal and a temporary manager account.

Last run on OJS 3.5.0.3: **60/60** and **14/14**.

## Installation

Download the package from [Releases](../../releases) and install it through
**Settings → Website → Plugins → Upload A New Plugin**, or drop the folder into
`plugins/generic/` and enable it.

## Credits & authorship

Original plugin developed and maintained by **[OJSBR](https://ojsbr.com)**.

## License

GNU General Public License v3.0. See [LICENSE](LICENSE) and `docs/COPYING`.
