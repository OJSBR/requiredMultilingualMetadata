# Casos de teste — requiredMultilingualMetadata

Bateria de regressão do plugin. Reexecutar **a cada upgrade do OJS** e a cada alteração do
plugin: os ganchos usados (`Submission::validateSubmit`, `TemplateManager::display`) e a
estrutura do estado do assistente são pontos que a PKP já mudou entre versões menores.

## Como rodar

```bash
su -s /bin/bash ojsbrcom -c 'php plugins/generic/requiredMultilingualMetadata/tests/regressao.php'
```

```bash
su -s /bin/bash ojsbrcom -c 'php plugins/generic/requiredMultilingualMetadata/tests/regressao_http.php'
```

O primeiro cobre regra, configuração, papéis e não-regressão do núcleo, chamando a validação
real do OJS (`Repo::submission()->validateSubmit()`). O segundo cobre interface e ponta a
ponta, logando de verdade no site e usando os mesmos endpoints do assistente.

Ambos **restauram tudo o que tocam** (configuração do plugin, seção, idiomas da revista,
senhas, usuários e submissões criadas) e imprimem PASS/FAIL por caso, com código de saída
diferente de zero se algo falhar.

> **Rode só em instalação de teste.** As baterias criam e apagam submissões, uma revista
> temporária e um gestor temporário, e trocam (restaurando depois) a senha do autor de teste.

As constantes do topo de cada arquivo são do ambiente de referência e precisam ser ajustadas
para outra instalação: `CONTEXT_ID`, `SECTION_ID`, o grupo de Autor (`UG_AUTOR`) e o de Gestor
(`UG_GESTOR`), o login do autor de teste (`AUTOR`), e os ids dos gêneros de arquivo
obrigatórios usados no caso ponta a ponta.

## Convenções dos cenários

- Revista: `Treinamento OJSBR`, idiomas de metadados `pt_BR`, `en_US`, `es@formal`.
- "Idioma da submissão" = `submissions.locale`, escolhido no passo 1 do assistente.
- "Idioma extra" = idioma marcado na configuração do plugin.
- Erros de `files` e `contributors` são ruído do ambiente e ficam fora das asserções, salvo
  quando o caso é justamente sobre eles.

---

## A — Configuração

| # | Caso | Esperado |
|---|---|---|
| A01 | Plugin habilitado, configuração vazia | Nenhum erro do plugin; comportamento idêntico ao OJS puro |
| A02 | Só `title` configurado (en_US) | Bloqueia título; resumo livre |
| A03 | Só `abstract` configurado (en_US) | Bloqueia resumo; título livre |
| A04 | Ambos configurados, 1 idioma | Bloqueia os dois nesse idioma |
| A05 | Ambos configurados, 2 idiomas | Um erro por campo por idioma |
| A06 | Configuração inclui o próprio idioma da submissão | Ignorado — sem erro duplicado no locale que o núcleo já cobra |
| A07 | Salvar configuração com idioma inativo na revista | Descartado na gravação |
| A08 | Idioma desativado na revista **depois** de configurado | Ignorado em tempo de execução, sem reconfigurar |
| A09 | Round-trip da tela: gravar → reler | Mesmos idiomas, mesma ordem de campos |
| A10 | POST malformado (string em vez de array, valor não-string) | Não lança exceção; grava lista limpa |
| A11 | Plugin **desabilitado** | Nenhum gancho ativo; validação volta a ser a do núcleo |

## B — Regra de validação (autor submetendo)

| # | Caso | Esperado |
|---|---|---|
| B01 | Nada preenchido | Erro do núcleo no idioma da submissão + erro do plugin nos extras |
| B02 | Só o idioma da submissão | Só erros do plugin, nos extras |
| B03 | Só o idioma extra | Só erro do núcleo, no idioma da submissão |
| B04 | Idioma da submissão + extras | Nenhum erro de metadado |
| B05 | Título traduzido, resumo não | Erro só em `abstract` |
| B06 | Resumo traduzido, título não | Erro só em `title` |
| B07 | Campo com só espaços em branco no idioma extra | Conta como vazio → bloqueia |
| B08 | Resumo com HTML vazio (`<p></p>`, `<p>&nbsp;</p>`) no extra | Conta como vazio → bloqueia |
| B09 | Submissão em `en_US` com `en_US` exigido | Sem erro do plugin; não cobra pt_BR |
| B10 | Submissão em `es@formal` | Regra segue o locale da submissão |
| B11 | Três idiomas exigidos simultaneamente | Três erros por campo |
| B12 | Rodar a validação duas vezes seguidas | Resultado idêntico (idempotente, não acumula) |

## C — Papéis e isenção

| # | Caso | Esperado |
|---|---|---|
| C01 | Autor comum | **Bloqueia** |
| C02 | Gestor da revista em submissão de terceiro | **Isento** |
| C03 | Gestor designado como Autor na submissão | **Bloqueia** |
| C04 | Editor de seção em submissão de terceiro | **Isento** |
| C05 | Editor de seção designado como Autor | **Bloqueia** |
| C06 | Administrador do site sem papel na revista | **Isento** |
| C07 | Assistente de edição | **Bloqueia** (não está na lista de isenção — decisão consciente) |
| C08 | Sem usuário na sessão (fila, cron) | **Bloqueia** (fail-safe) |

## D — Seção

| # | Caso | Esperado |
|---|---|---|
| D01 | Seção normal | Título e resumo na regra |
| D02 | Seção com "Resumo não obrigatório" | Resumo fora da regra em **todos** os idiomas; título continua |
| D03 | Publicação sem `sectionId` | Não quebra; trata como seção que exige resumo |

## E — Não-regressão do núcleo

| # | Caso | Esperado |
|---|---|---|
| E01 | Config vazia: erros nativos idênticos ao OJS puro | `title`/`abstract` no idioma da submissão |
| E02 | Título: erro nativo e do plugin no mesmo campo | Coexistem (`title.pt_BR` **e** `title.en_US`); o plugin não sobrescreve |
| E03 | `keywords = require` junto com o plugin | Erro de keywords do núcleo permanece |
| E04 | Erro de arquivos obrigatórios | Permanece |
| E06 | Fluxo feliz: tudo preenchido + arquivos | Submissão aceita (HTTP 200, `dateSubmitted` preenchido) |
| E07 | Editor salva metadados incompletos **depois** de submetido | Não bloqueia (`Publication::validate` não é tocado) |
| E08 | Publicar artigo sem os idiomas extras | Não bloqueia |
| E09 | Resumo: invariante da colisão (ver abaixo) | Quando a mensagem do plugin é descartada, o núcleo já pôs a dele; sem colisão, a do plugin aparece e bloqueia |
| E10 | Limite de palavras do resumo também sobrescreve | Continua bloqueando |

### Comportamento conhecido: o resumo e a ordem do OJS

O hook `Submission::validateSubmit` roda no fim de `PKP\submission\Repository::validateSubmit()`.
A subclasse do OJS roda **depois** e faz `$errors['abstract'] = [$locale => …]` — atribuição, não
merge. Resultado: quando o resumo está vazio no idioma da submissão (ou estoura o limite de
palavras da seção), a mensagem do plugin para os idiomas extras é descartada naquela rodada.

Isso **não abre buraco na regra**: o núcleo só sobrescreve quando ele mesmo colocou ali um erro
bloqueante. A submissão continua barrada; o autor vê as duas mensagens em rodadas diferentes, e
o aviso no campo ("Obrigatório também em: …") cobre a lacuna visual. O título não sofre disso,
porque o núcleo escreve `$errors['title']` **antes** do hook.

E09 e E10 existem justamente para travar essa invariante: se um upgrade do OJS mudar a ordem,
os dois casos mudam de comportamento e a bateria acusa.

## F — Interface

| # | Caso | Esperado |
|---|---|---|
| F01 | Assistente abre as abas dos idiomas exigidos | `visibleLocales` = idioma da submissão + extras |
| F02 | Aviso no campo lista os idiomas certos | "Obrigatório também em: …" só nos campos configurados |
| F03 | Config vazia | Sem abas extras e sem aviso — não polui a tela |
| F04 | Só título configurado | Aviso só no título; aba abre pela união |
| F05 | Idioma configurado que não está nas abas do formulário | Ignorado, sem aba fantasma |
| F06 | Tela de configuração abre para o gestor | HTTP 200, tabela com um `checkbox` por idioma |
| F07 | Salvar pela tela persiste | Marcações refletem o que foi salvo ao reabrir |
| F08 | Plugin desabilitado | Assistente volta ao normal (uma aba só) |
| F09 | Escape de HTML no aviso | Sem injeção no `description` do campo |

## G — Robustez

| # | Caso | Esperado |
|---|---|---|
| G01 | Configuração é por revista | Config da revista A não vale na revista B |
| G02 | Submissão sem publicação corrente | Não quebra |
| G03 | Config apontando para idioma que sumiu da revista | Sem exceção, sem erro fantasma |
| G04 | Muitos idiomas configurados (todos os ativos) | Sem exceção; erros coerentes |

---

## Última execução

OJS 3.5.0.3, treinamento.ojsbr.com, 03/08/2026:

| Bateria | Resultado |
|---|---|
| `regressao.php` (A, B, C, D, E, G) | **45/45** |
| `regressao_http.php` (F + E06) | **10/10** |

Rodadas duas vezes seguidas com o mesmo resultado e código de saída 0. Depois das duas,
o servidor voltou ao estado inicial: nenhuma revista de teste, nenhum usuário `rmmtest_*`,
nenhuma submissão de teste sobrando, configuração da revista e do plugin restauradas.

Os resultados por caso ficam em `tests/resultado.json` e `tests/resultado_http.json`.

## Cobertura declarada

Cobre: leitura e gravação da configuração, filtragem de idiomas, a regra em todos os
cruzamentos campo × idioma × papel, a interação com a seção, a coexistência com as validações
nativas e a montagem da tela.

**Não cobre** (e por quê): comportamento sob OMP/OPS (o plugin é OJS-only, usa
`Section::getAbstractsNotRequired()`); tradução dos textos além de pt_BR/en/es; e o
`changeLocale` de uma submissão já iniciada — se o idioma principal muda depois de metadados
preenchidos, a regra passa a valer para o novo idioma, o que é o comportamento desejado, mas
não há caso automatizado para isso.
