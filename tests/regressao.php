<?php

/**
 * @file tests/regressao.php
 *
 * Plugin autoral OJSBR.
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Bateria de regressão do requiredMultilingualMetadata (blocos A a E, G).
 *        Chama a validação real do OJS (Repo::submission()->validateSubmit()),
 *        trocando o usuário corrente no Registry — a mesma fonte que
 *        PKPRequest::getUser() consulta.
 *
 *        Tudo o que o script toca é restaurado no final, inclusive as submissões
 *        que ele mesmo cria. Ver tests/CASOS.md.
 *
 * Uso: php plugins/generic/requiredMultilingualMetadata/tests/regressao.php [--manter]
 */

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\core\Registry;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;

$root = dirname(__DIR__, 4);
chdir($root);
define('INDEX_FILE_LOCATION', $root . '/index.php');
require $root . '/lib/pkp/includes/bootstrap.php';

const CONTEXT_ID = 1;
const UG_AUTOR = 14;
const SECTION_ID = 1;
const PLUGIN = 'requiredmultilingualmetadataplugin';

$manter = in_array('--manter', $argv, true);

//
// Harness: o PageRouter padrão deriva o journal da URL, que no CLI não existe.
//
class RouterComContexto extends PageRouter
{
    private $ctx;
    public function fixarContexto($c) { $this->ctx = $c; }
    public function getContext(\PKP\core\PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context { return $this->ctx; }
}

$request = Application::get()->getRequest();
$context = Application::getContextDAO()->getById(CONTEXT_ID);
$router = new RouterComContexto();
$router->setApplication(Application::get());
$router->fixarContexto($context);
$request->setRouter($router);

$plugins = PluginRegistry::loadCategory('generic', true, CONTEXT_ID);
$plugin = $plugins[PLUGIN] ?? null;
if (!$plugin) {
    exit("FALHA FATAL: plugin nao carregado. Ele esta habilitado nesta revista?\n");
}

//
// Mini-framework
//
$RESULTADOS = [];
$BLOCO = '';

function bloco(string $titulo): void
{
    global $BLOCO;
    $BLOCO = $titulo;
    echo "\n" . str_repeat('=', 78) . "\n{$titulo}\n" . str_repeat('=', 78) . "\n";
}

function caso(string $id, string $titulo, callable $fn): void
{
    global $RESULTADOS, $BLOCO;
    try {
        $fn();
        $RESULTADOS[] = ['id' => $id, 'bloco' => $BLOCO, 'titulo' => $titulo, 'ok' => true, 'msg' => ''];
        printf("  [ PASS  ] %-4s %s\n", $id, $titulo);
    } catch (Throwable $e) {
        $RESULTADOS[] = ['id' => $id, 'bloco' => $BLOCO, 'titulo' => $titulo, 'ok' => false, 'msg' => $e->getMessage()];
        printf("  [ FALHA ] %-4s %s\n           -> %s\n", $id, $titulo, $e->getMessage());
    }
}

function ok(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function igual($esperado, $obtido, string $msg): void
{
    if ($esperado !== $obtido) {
        throw new RuntimeException($msg . "\n              esperado: " . json_encode($esperado, JSON_UNESCAPED_SLASHES)
            . "\n              obtido  : " . json_encode($obtido, JSON_UNESCAPED_SLASHES));
    }
}

//
// Auxiliares de domínio
//
$CRIADAS = [];

function novaSubmissao(string $locale, int $userId = 2, int $sectionId = SECTION_ID): array
{
    global $context, $CRIADAS;

    $submission = Repo::submission()->newDataObject([
        'contextId' => CONTEXT_ID,
        'locale' => $locale,
        'submissionProgress' => 'start',
        'stageId' => WORKFLOW_STAGE_ID_SUBMISSION,
    ]);
    $publication = Repo::publication()->newDataObject(['sectionId' => $sectionId]);
    $id = Repo::submission()->add($submission, $publication, $context);
    $CRIADAS[] = $id;

    Repo::stageAssignment()->build($id, UG_AUTOR, $userId, false, true);

    $submission = Repo::submission()->get($id);

    return [$submission, $submission->getCurrentPublication()];
}

function definirMetadados(Publication $publication, array $props): Publication
{
    Repo::publication()->edit($publication, $props);

    return Repo::publication()->get($publication->getId());
}

/** Recarrega a submissão para que getCurrentPublication() traga os dados novos. */
function recarregar(Submission $submission): Submission
{
    return Repo::submission()->get($submission->getId());
}

/**
 * Roda a validação real e devolve só o que interessa: campo => idiomas com erro.
 * $userId null = ninguém logado (fila, cron).
 */
function errosMeta(Submission $submission, ?int $userId): array
{
    global $context;

    Registry::delete('user');
    if ($userId !== null) {
        $user = Repo::user()->get($userId);
        Registry::set('user', $user);
    }

    $errors = Repo::submission()->validateSubmit($submission, $context);
    Registry::delete('user');

    $out = [];
    foreach (['title', 'abstract', 'keywords'] as $campo) {
        if (!isset($errors[$campo])) {
            continue;
        }
        $locales = array_keys((array) $errors[$campo]);
        sort($locales);
        $out[$campo] = $locales;
    }
    ksort($out);

    return $out;
}

function configurar(array $titulo, array $resumo, array $palavrasChave = []): void
{
    global $plugin;
    $plugin->updateSetting(CONTEXT_ID, 'titleLocales', $titulo, 'object');
    $plugin->updateSetting(CONTEXT_ID, 'abstractLocales', $resumo, 'object');
    $plugin->updateSetting(CONTEXT_ID, 'keywordsLocales', $palavrasChave, 'object');
}

/**
 * Muda Fluxo de Trabalho > Metadados > Palavras-chave e recarrega o contexto global,
 * porque é o objeto que a validação recebe.
 */
function definirModoKeywords(string $modo): void
{
    global $context;
    $context->setData('keywords', $modo);
    Application::getContextDAO()->updateObject($context);
    $context = Application::getContextDAO()->getById(CONTEXT_ID);
}

/** Papel temporário para um usuário nesta revista; devolve o id da linha criada. */
function darPapel(int $userId, int $userGroupId): int
{
    return DB::table('user_user_groups')->insertGetId([
        'user_id' => $userId,
        'user_group_id' => $userGroupId,
    ]);
}

function tirarPapel(int $rowId): void
{
    DB::table('user_user_groups')->where('user_user_group_id', $rowId)->delete();
}

//
// Estado original, para restaurar no fim
//
$ORIGINAL = [
    'titleLocales' => $plugin->getSetting(CONTEXT_ID, 'titleLocales'),
    'abstractLocales' => $plugin->getSetting(CONTEXT_ID, 'abstractLocales'),
    'keywordsLocales' => $plugin->getSetting(CONTEXT_ID, 'keywordsLocales'),
    'keywords' => $context->getData('keywords'),
    'metadataLocales' => $context->getSupportedSubmissionMetadataLocales(),
    'abstractsNotRequired' => (int) DB::table('sections')->where('section_id', SECTION_ID)->value('abstracts_not_required'),
];

echo "requiredMultilingualMetadata — regressao\n";
echo 'OJS ' . Application::get()->getCurrentVersion()->getVersionString() . " | revista {$context->getPath()}\n";
echo 'idiomas de metadados: ' . implode(', ', $ORIGINAL['metadataLocales']) . "\n";

$falhaFatal = null;

try {
    //
    // ------------------------------------------------------------------ A
    //
    bloco('A — Configuracao');

    [$subA, $pubA] = novaSubmissao('pt_BR');
    $pubA = definirMetadados($pubA, [
        'title' => ['pt_BR' => 'Titulo'],
        'abstract' => ['pt_BR' => '<p>Resumo</p>'],
    ]);
    $subA = recarregar($subA);

    caso('A01', 'configuracao vazia = plugin inerte', function () use ($subA) {
        configurar([], []);
        igual([], errosMeta($subA, 2), 'nao deveria haver erro de metadado');
    });

    caso('A02', 'so titulo configurado', function () use ($subA) {
        configurar(['en_US'], []);
        igual(['title' => ['en_US']], errosMeta($subA, 2), 'so o titulo deveria ser cobrado');
    });

    caso('A03', 'so resumo configurado', function () use ($subA) {
        configurar([], ['en_US']);
        igual(['abstract' => ['en_US']], errosMeta($subA, 2), 'so o resumo deveria ser cobrado');
    });

    caso('A04', 'ambos, um idioma', function () use ($subA) {
        configurar(['en_US'], ['en_US']);
        igual(['abstract' => ['en_US'], 'title' => ['en_US']], errosMeta($subA, 2), 'os dois campos em en_US');
    });

    caso('A05', 'ambos, dois idiomas', function () use ($subA) {
        configurar(['en_US', 'es@formal'], ['en_US', 'es@formal']);
        igual(
            ['abstract' => ['en_US', 'es@formal'], 'title' => ['en_US', 'es@formal']],
            errosMeta($subA, 2),
            'um erro por campo por idioma'
        );
    });

    caso('A06', 'configuracao inclui o idioma da propria submissao', function () use ($subA) {
        configurar(['pt_BR', 'en_US'], ['pt_BR']);
        // pt_BR esta preenchido; o plugin nao pode inventar erro nele nem duplicar o do nucleo
        igual(['title' => ['en_US']], errosMeta($subA, 2), 'pt_BR deveria ser ignorado pelo plugin');
    });

    caso('A07', 'idioma inativo na revista e descartado ao gravar', function () use ($plugin, $context) {
        $_POST['titleLocales'] = ['en_US', 'fr_CA', 'zz_ZZ'];
        $_POST['abstractLocales'] = ['en_US'];
        $form = new \APP\plugins\generic\requiredMultilingualMetadata\RequiredMultilingualMetadataSettingsForm($plugin, $context);
        // readInputData usa o cache de getUserVars(); aqui alimentamos o form direto
        $form->setData('titleLocales', $_POST['titleLocales']);
        $form->setData('abstractLocales', $_POST['abstractLocales']);
        $form->execute();
        igual(['en_US'], $plugin->getConfiguredLocales(CONTEXT_ID, 'title'), 'fr_CA e zz_ZZ deveriam sumir');
    });

    caso('A08', 'idioma desativado na revista DEPOIS de configurado e ignorado', function () use ($subA, $context, $plugin) {
        configurar(['en_US', 'es@formal'], []);
        // remove es@formal dos idiomas de metadados da revista
        $context->setData('supportedSubmissionMetadataLocales', ['en_US', 'pt_BR']);
        Application::getContextDAO()->updateObject($context);
        $ctxNovo = Application::getContextDAO()->getById(CONTEXT_ID);
        igual(['en_US'], $plugin->getEnforceableLocales($ctxNovo, 'title', 'pt_BR'), 'es@formal deveria ter saido');
    });

    caso('A09', 'round-trip: grava e rele na mesma ordem', function () use ($plugin) {
        configurar(['es@formal', 'en_US'], ['en_US']);
        igual(['es@formal', 'en_US'], $plugin->getConfiguredLocales(CONTEXT_ID, 'title'), 'ordem preservada');
        igual(['en_US'], $plugin->getConfiguredLocales(CONTEXT_ID, 'abstract'), 'resumo preservado');
    });

    caso('A10', 'POST malformado nao quebra', function () use ($plugin, $context) {
        $form = new \APP\plugins\generic\requiredMultilingualMetadata\RequiredMultilingualMetadataSettingsForm($plugin, $context);
        $form->setData('titleLocales', 'en_US');            // string em vez de array
        $form->setData('abstractLocales', ['en_US', 123, ['x'], null]);
        $form->execute();
        igual([], $plugin->getConfiguredLocales(CONTEXT_ID, 'title'), 'string vira lista vazia');
        igual(['en_US'], $plugin->getConfiguredLocales(CONTEXT_ID, 'abstract'), 'lixo descartado');
    });

    caso('A11', 'plugin desabilitado = validacao do nucleo pura', function () use ($subA, $plugin) {
        configurar(['en_US'], ['en_US']);
        $antes = errosMeta($subA, 2);
        Hook::clear('Submission::validateSubmit');
        $semPlugin = errosMeta($subA, 2);
        Hook::add('Submission::validateSubmit', [$plugin, 'validateSubmit']);
        $depois = errosMeta($subA, 2);

        igual(['abstract' => ['en_US'], 'title' => ['en_US']], $antes, 'com plugin deveria cobrar');
        igual([], $semPlugin, 'sem plugin nao pode cobrar nada');
        igual($antes, $depois, 'gancho religado deveria voltar ao mesmo resultado');
    });

    //
    // ------------------------------------------------------------------ B
    //
    bloco('B — Regra de validacao');

    configurar(['en_US'], ['en_US']);

    caso('B01', 'nada preenchido', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        // O titulo acumula os dois locales. O resumo fica so com pt_BR porque a subclasse do
        // OJS sobrescreve $errors['abstract'] depois do hook — ver E09/E10 e o comentario
        // em RequiredMultilingualMetadataPlugin::validateSubmit().
        igual(
            ['abstract' => ['pt_BR'], 'title' => ['en_US', 'pt_BR']],
            errosMeta(recarregar($s), 2),
            'titulo acumula; resumo e sobrescrito pelo nucleo'
        );
    });

    caso('B02', 'so o idioma da submissao', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        igual(['abstract' => ['en_US'], 'title' => ['en_US']], errosMeta(recarregar($s), 2), 'so os extras');
    });

    caso('B03', 'so o idioma extra', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['en_US' => 'T'], 'abstract' => ['en_US' => '<p>A</p>']]);
        igual(['abstract' => ['pt_BR'], 'title' => ['pt_BR']], errosMeta(recarregar($s), 2), 'so o nucleo');
    });

    caso('B04', 'idioma da submissao + extras', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, [
            'title' => ['pt_BR' => 'T', 'en_US' => 'T'],
            'abstract' => ['pt_BR' => '<p>R</p>', 'en_US' => '<p>A</p>'],
        ]);
        igual([], errosMeta(recarregar($s), 2), 'nenhum erro de metadado');
    });

    caso('B05', 'titulo traduzido, resumo nao', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, [
            'title' => ['pt_BR' => 'T', 'en_US' => 'T'],
            'abstract' => ['pt_BR' => '<p>R</p>'],
        ]);
        igual(['abstract' => ['en_US']], errosMeta(recarregar($s), 2), 'so o resumo');
    });

    caso('B06', 'resumo traduzido, titulo nao', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, [
            'title' => ['pt_BR' => 'T'],
            'abstract' => ['pt_BR' => '<p>R</p>', 'en_US' => '<p>A</p>'],
        ]);
        igual(['title' => ['en_US']], errosMeta(recarregar($s), 2), 'so o titulo');
    });

    caso('B07', 'campo so com espacos conta como vazio', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, [
            'title' => ['pt_BR' => 'T', 'en_US' => '   '],
            'abstract' => ['pt_BR' => '<p>R</p>', 'en_US' => "\n\t "],
        ]);
        igual(['abstract' => ['en_US'], 'title' => ['en_US']], errosMeta(recarregar($s), 2), 'espaco nao preenche');
    });

    caso('B08', 'resumo com HTML vazio conta como vazio', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, [
            'title' => ['pt_BR' => 'T', 'en_US' => 'T'],
            'abstract' => ['pt_BR' => '<p>R</p>', 'en_US' => '<p></p>'],
        ]);
        igual(['abstract' => ['en_US']], errosMeta(recarregar($s), 2), '<p></p> nao preenche');
    });

    caso('B09', 'submissao em en_US com en_US exigido', function () {
        [$s, $p] = novaSubmissao('en_US');
        definirMetadados($p, ['title' => ['en_US' => 'T'], 'abstract' => ['en_US' => '<p>A</p>']]);
        igual([], errosMeta(recarregar($s), 2), 'nao pode cobrar pt_BR nem duplicar en_US');
    });

    caso('B10', 'submissao em es@formal', function () {
        [$s, $p] = novaSubmissao('es@formal');
        definirMetadados($p, ['title' => ['es@formal' => 'T'], 'abstract' => ['es@formal' => '<p>A</p>']]);
        igual(['abstract' => ['en_US'], 'title' => ['en_US']], errosMeta(recarregar($s), 2), 'extra continua sendo en_US');
    });

    caso('B11', 'tres idiomas exigidos', function () use ($context) {
        // devolve es@formal aos idiomas da revista (A08 tirou)
        $context->setData('supportedSubmissionMetadataLocales', ['en_US', 'es@formal', 'pt_BR']);
        Application::getContextDAO()->updateObject($context);
        configurar(['en_US', 'es@formal', 'pt_BR'], []);

        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        igual(['title' => ['en_US', 'es@formal']], errosMeta(recarregar($s), 2), 'pt_BR sai por ser o da submissao');
        configurar(['en_US'], ['en_US']);
    });

    caso('B12', 'validar duas vezes da o mesmo resultado', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        $s = recarregar($s);
        igual(errosMeta($s, 2), errosMeta($s, 2), 'nao pode acumular erro entre chamadas');
    });

    //
    // ------------------------------------------------------------------ C
    //
    bloco('C — Papeis e isencao');

    configurar(['en_US'], ['en_US']);
    [$subC, $pubC] = novaSubmissao('pt_BR', 2);
    definirMetadados($pubC, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
    $subC = recarregar($subC);
    $ESPERADO_BLOQUEIO = ['abstract' => ['en_US'], 'title' => ['en_US']];

    caso('C01', 'autor comum: bloqueia', function () use ($subC, $ESPERADO_BLOQUEIO) {
        igual($ESPERADO_BLOQUEIO, errosMeta($subC, 2), 'autor tem de ser bloqueado');
    });

    caso('C02', 'gestor em submissao de terceiro: isento', function () use ($subC) {
        igual([], errosMeta($subC, 1), 'gestor nao pode ser bloqueado');
    });

    caso('C03', 'gestor designado como Autor: bloqueia', function () use ($subC, $ESPERADO_BLOQUEIO) {
        Repo::stageAssignment()->build($subC->getId(), UG_AUTOR, 1, false, true);
        $r = errosMeta($subC, 1);
        DB::table('stage_assignments')->where('submission_id', $subC->getId())->where('user_id', 1)->delete();
        igual($ESPERADO_BLOQUEIO, $r, 'gestor-autor tem de ser bloqueado');
    });

    caso('C04', 'editor de secao em submissao de terceiro: isento', function () use ($subC) {
        $row = darPapel(3, 5); // ug 5 = Editor de secao (SUB_EDITOR)
        $r = errosMeta($subC, 3);
        tirarPapel($row);
        igual([], $r, 'editor de secao nao pode ser bloqueado');
    });

    caso('C05', 'editor de secao designado como Autor: bloqueia', function () use ($subC, $ESPERADO_BLOQUEIO) {
        $row = darPapel(3, 5);
        Repo::stageAssignment()->build($subC->getId(), UG_AUTOR, 3, false, true);
        $r = errosMeta($subC, 3);
        DB::table('stage_assignments')->where('submission_id', $subC->getId())->where('user_id', 3)->delete();
        tirarPapel($row);
        igual($ESPERADO_BLOQUEIO, $r, 'editor-autor tem de ser bloqueado');
    });

    caso('C06', 'admin do site sem papel na revista: isento', function () use ($subC) {
        // user 1 e admin; removemos os papeis dele NESTA revista para isolar o teste
        $papeis = DB::table('user_user_groups as uug')
            ->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('ug.context_id', CONTEXT_ID)->where('uug.user_id', 1)
            ->pluck('uug.user_user_group_id')->toArray();
        $backup = DB::table('user_user_groups')->whereIn('user_user_group_id', $papeis)->get()->toArray();
        DB::table('user_user_groups')->whereIn('user_user_group_id', $papeis)->delete();

        $r = errosMeta($subC, 1);

        foreach ($backup as $b) {
            DB::table('user_user_groups')->insert((array) $b);
        }
        igual([], $r, 'admin do site deve ser isento');
    });

    caso('C07', 'assistente de edicao: bloqueia (nao esta na lista de isencao)', function () use ($subC, $ESPERADO_BLOQUEIO) {
        $row = darPapel(4, 7); // ug 7 = Editor de texto (ASSISTANT)
        $r = errosMeta($subC, 4);
        tirarPapel($row);
        igual($ESPERADO_BLOQUEIO, $r, 'assistente nao e isento por decisao de projeto');
    });

    caso('C08', 'sem usuario na sessao: bloqueia (fail-safe)', function () use ($subC, $ESPERADO_BLOQUEIO) {
        igual($ESPERADO_BLOQUEIO, errosMeta($subC, null), 'sem usuario a regra tem de valer');
    });

    //
    // ------------------------------------------------------------------ D
    //
    bloco('D — Secao');

    caso('D01', 'secao normal: titulo e resumo na regra', function () use ($ESPERADO_BLOQUEIO) {
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        igual($ESPERADO_BLOQUEIO, errosMeta(recarregar($s), 2), 'ambos cobrados');
    });

    caso('D02', 'secao com "Resumo nao obrigatorio": resumo sai da regra', function () {
        $section = Repo::section()->get(SECTION_ID, CONTEXT_ID);
        Repo::section()->edit($section, ['abstractsNotRequired' => true]);

        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['pt_BR' => 'T']]);
        $r = errosMeta(recarregar($s), 2);

        $section = Repo::section()->get(SECTION_ID, CONTEXT_ID);
        Repo::section()->edit($section, ['abstractsNotRequired' => false]);

        igual(['title' => ['en_US']], $r, 'resumo nao pode ser cobrado em idioma nenhum');
    });

    caso('D03', 'publicacao sem secao nao quebra', function () use ($plugin, $context) {
        ok($plugin->isAbstractRequired(null, $context) === true, 'sem secao deve assumir que o resumo e exigido');
        ok($plugin->isAbstractRequired(999999, $context) === true, 'secao inexistente nao pode lancar excecao');
    });

    //
    // ------------------------------------------------------------------ E
    //
    bloco('E — Nao-regressao do nucleo');

    caso('E01', 'config vazia: erros nativos intactos', function () {
        configurar([], []);
        [$s, $p] = novaSubmissao('pt_BR');
        igual(
            ['abstract' => ['pt_BR'], 'title' => ['pt_BR']],
            errosMeta(recarregar($s), 2),
            'o nucleo continua cobrando o idioma da submissao'
        );
        configurar(['en_US'], ['en_US']);
    });

    caso('E02', 'titulo: erro nativo e do plugin coexistem no mesmo campo', function () {
        [$s, $p] = novaSubmissao('pt_BR');
        $r = errosMeta(recarregar($s), 2);
        igual(['en_US', 'pt_BR'], $r['title'], 'title tem de ter os dois locales');
    });

    caso('E09', 'resumo: quando o nucleo sobrescreve, ele mesmo ja bloqueia', function () {
        // Invariante que garante que nenhuma submissao passa sem traducao do resumo:
        // se a mensagem do plugin sumiu de 'abstract', o nucleo pos a dele no lugar.
        [$s, $p] = novaSubmissao('pt_BR');           // resumo vazio em tudo
        $semResumo = errosMeta(recarregar($s), 2);
        ok(isset($semResumo['abstract']), 'com resumo vazio o nucleo tem de bloquear');

        [$s2, $p2] = novaSubmissao('pt_BR');         // resumo so no idioma da submissao
        definirMetadados($p2, ['title' => ['pt_BR' => 'T', 'en_US' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        $comResumoPt = errosMeta(recarregar($s2), 2);
        igual(['abstract' => ['en_US']], $comResumoPt, 'sem colisao, a mensagem do plugin aparece e bloqueia');
    });

    caso('E10', 'limite de palavras do resumo tambem sobrescreve, mas segue bloqueando', function () {
        $section = Repo::section()->get(SECTION_ID, CONTEXT_ID);
        Repo::section()->edit($section, ['wordCount' => 3]);

        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, [
            'title' => ['pt_BR' => 'T', 'en_US' => 'T'],
            'abstract' => ['pt_BR' => '<p>um resumo bem maior que o limite de tres palavras</p>'],
        ]);
        $r = errosMeta(recarregar($s), 2);

        $section = Repo::section()->get(SECTION_ID, CONTEXT_ID);
        Repo::section()->edit($section, ['wordCount' => 0]);

        ok(isset($r['abstract']), 'o erro de limite de palavras do nucleo tem de bloquear');
    });

    caso('E03', 'keywords=require continua funcionando', function () use ($context) {
        $context->setData('keywords', 'require');
        Application::getContextDAO()->updateObject($context);
        $ctx = Application::getContextDAO()->getById(CONTEXT_ID);

        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        Registry::delete('user');
        $u = Repo::user()->get(2);
        Registry::set('user', $u);
        $errors = Repo::submission()->validateSubmit(recarregar($s), $ctx);
        Registry::delete('user');

        $context->setData('keywords', 'request');
        Application::getContextDAO()->updateObject($context);

        ok(isset($errors['keywords']['pt_BR']), 'keywords do nucleo deveria continuar sendo cobrado em pt_BR');
        ok(isset($errors['title']['en_US']), 'plugin deveria continuar cobrando o titulo');
    });

    caso('E04', 'erro de arquivos obrigatorios permanece', function () use ($context) {
        [$s, $p] = novaSubmissao('pt_BR');
        Registry::delete('user');
        $u = Repo::user()->get(2);
        Registry::set('user', $u);
        $errors = Repo::submission()->validateSubmit(recarregar($s), $context);
        Registry::delete('user');
        ok(isset($errors['files']), 'validacao de arquivos do nucleo nao pode sumir');
    });

    caso('E07', 'editor salva metadados incompletos depois de submetido', function () use ($context) {
        [$s, $p] = novaSubmissao('pt_BR');
        $p = definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        $s = recarregar($s);
        $errors = Repo::publication()->validate($p, ['title' => ['pt_BR' => 'Editado pelo editor']], $s, $context);
        ok(empty($errors), 'Publication::validate nao pode ser tocado pelo plugin: ' . json_encode($errors));
    });

    caso('E08', 'publicar sem os idiomas extras nao bloqueia', function () use ($context) {
        [$s, $p] = novaSubmissao('pt_BR');
        $p = definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        $s = recarregar($s);
        $errors = Repo::publication()->validatePublish($p, $s, $context->getData('supportedSubmissionLocales'), 'pt_BR');
        foreach (['title', 'abstract'] as $campo) {
            ok(!isset($errors[$campo]), "validatePublish nao pode cobrar {$campo}: " . json_encode($errors));
        }
    });

    //
    // ------------------------------------------------------------------ K
    //
    bloco('K — Palavras-chave (so quando a revista as exige)');

    /** Cria uma submissao com titulo e resumo prontos, variando so as palavras-chave. */
    $subKeywords = function (array $keywords = []): Submission {
        [$s, $p] = novaSubmissao('pt_BR');
        $props = ['title' => ['pt_BR' => 'T', 'en_US' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>', 'en_US' => '<p>A</p>']];
        if ($keywords) {
            $props['keywords'] = $keywords;
        }
        definirMetadados($p, $props);

        return recarregar($s);
    };

    caso('K01', "revista em 'request': plugin nao encosta nas palavras-chave", function () use ($subKeywords) {
        definirModoKeywords('request');
        configurar([], [], ['en_US']);
        igual([], errosMeta($subKeywords(['pt_BR' => ['alfa']]), 2), 'sem exigencia da revista, nada e cobrado');
    });

    caso('K02', "revista em 'enable': idem", function () use ($subKeywords) {
        definirModoKeywords('enable');
        configurar([], [], ['en_US']);
        igual([], errosMeta($subKeywords(['pt_BR' => ['alfa']]), 2), 'enable nao e require');
    });

    caso('K03', 'palavras-chave desabilitadas na revista: idem', function () use ($subKeywords) {
        definirModoKeywords('0');
        configurar([], [], ['en_US']);
        igual([], errosMeta($subKeywords(), 2), 'desabilitado nao pode cobrar nada');
    });

    caso('K04', "revista em 'require' e plugin sem idioma: so o nucleo cobra", function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], []);
        igual(['keywords' => ['pt_BR']], errosMeta($subKeywords(), 2), 'so o idioma da submissao');
    });

    caso('K05', "revista em 'require' e plugin en_US: cobra a traducao", function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], ['en_US']);
        igual(['keywords' => ['en_US']], errosMeta($subKeywords(['pt_BR' => ['alfa', 'beta']]), 2), 'falta en_US');
    });

    caso('K06', 'preenchido nos dois idiomas: sem erro', function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], ['en_US']);
        igual([], errosMeta($subKeywords(['pt_BR' => ['alfa'], 'en_US' => ['alpha']]), 2), 'nada a cobrar');
    });

    caso('K07', 'lista vazia e lista so com espacos contam como vazio', function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], ['en_US']);
        igual(['keywords' => ['en_US']], errosMeta($subKeywords(['pt_BR' => ['alfa'], 'en_US' => []]), 2), 'lista vazia nao preenche');
        igual(['keywords' => ['en_US']], errosMeta($subKeywords(['pt_BR' => ['alfa'], 'en_US' => ['   ', '']]), 2), 'so espacos nao preenche');
    });

    caso('K08', 'idioma da submissao na configuracao e ignorado', function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], ['pt_BR', 'en_US']);
        igual(
            ['keywords' => ['en_US']],
            errosMeta($subKeywords(['pt_BR' => ['alfa']]), 2),
            'pt_BR ja preenchido nao pode gerar erro duplicado'
        );
    });

    caso('K09', 'dois idiomas extras exigidos', function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], ['en_US', 'es@formal']);
        igual(
            ['keywords' => ['en_US', 'es@formal']],
            errosMeta($subKeywords(['pt_BR' => ['alfa']]), 2),
            'um erro por idioma'
        );
    });

    caso('K10', 'erro nativo e do plugin coexistem em keywords', function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], ['en_US']);
        // nada preenchido: nucleo cobra pt_BR (escreve antes do hook) e plugin cobra en_US
        igual(['keywords' => ['en_US', 'pt_BR']], errosMeta($subKeywords(), 2), 'os dois locales');
    });

    caso('K11', 'gestor em submissao de terceiro: isento tambem nas palavras-chave', function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], ['en_US']);
        igual([], errosMeta($subKeywords(['pt_BR' => ['alfa']]), 1), 'gestor nao pode ser bloqueado');
    });

    caso('K12', 'gestor designado como Autor: bloqueia', function () use ($subKeywords) {
        definirModoKeywords('require');
        configurar([], [], ['en_US']);
        $s = $subKeywords(['pt_BR' => ['alfa']]);
        Repo::stageAssignment()->build($s->getId(), UG_AUTOR, 1, false, true);
        $r = errosMeta($s, 1);
        DB::table('stage_assignments')->where('submission_id', $s->getId())->where('user_id', 1)->delete();
        igual(['keywords' => ['en_US']], $r, 'gestor-autor tem de ser bloqueado');
    });

    caso('K13', 'plugin desabilitado: volta a validacao do nucleo', function () use ($subKeywords, $plugin) {
        definirModoKeywords('require');
        configurar([], [], ['en_US']);
        $s = $subKeywords(['pt_BR' => ['alfa']]);

        $comPlugin = errosMeta($s, 2);
        Hook::clear('Submission::validateSubmit');
        $semPlugin = errosMeta($s, 2);
        Hook::add('Submission::validateSubmit', [$plugin, 'validateSubmit']);

        igual(['keywords' => ['en_US']], $comPlugin, 'com plugin deveria cobrar');
        igual([], $semPlugin, 'sem plugin nao pode cobrar nada');
    });

    caso('K14', 'titulo, resumo e palavras-chave juntos', function () {
        definirModoKeywords('require');
        configurar(['en_US'], ['en_US'], ['en_US']);
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, [
            'title' => ['pt_BR' => 'T'],
            'abstract' => ['pt_BR' => '<p>R</p>'],
            'keywords' => ['pt_BR' => ['alfa']],
        ]);
        igual(
            ['abstract' => ['en_US'], 'keywords' => ['en_US'], 'title' => ['en_US']],
            errosMeta(recarregar($s), 2),
            'os tres campos cobrados em en_US'
        );
    });

    caso('K15', 'campos aplicaveis mudam com o ajuste da revista', function () use ($plugin) {
        // Recarrega o contexto a cada troca: o objeto e imutavel para quem ja o tinha em maos.
        definirModoKeywords('require');
        $ctx = Application::getContextDAO()->getById(CONTEXT_ID);
        igual(['title', 'abstract', 'keywords'], $plugin->getApplicableFields($ctx, SECTION_ID), 'com require entram os tres');

        definirModoKeywords('request');
        $ctx = Application::getContextDAO()->getById(CONTEXT_ID);
        igual(['title', 'abstract'], $plugin->getApplicableFields($ctx, SECTION_ID), 'sem require, palavras-chave saem');
    });

    definirModoKeywords($ORIGINAL['keywords']);
    configurar(['en_US'], ['en_US'], []);

    //
    // ------------------------------------------------------------------ G
    //
    bloco('G — Robustez');

    caso('G01', 'configuracao e por revista (revista de teste de verdade)', function () use ($plugin, $request) {
        configurar(['en_US'], ['en_US']);

        // plugin_settings.context_id tem FK para journals: nao da para testar com id ficticio.
        // ContextService::add() atribui o papel de gestor ao usuario corrente, entao
        // precisamos de um usuario no Registry (no CLI nao ha sessao).
        $admin = Repo::user()->get(1);
        Registry::set('user', $admin);

        $servico = app()->get('context');
        $caminho = 'rmmtest' . substr(md5((string) getmypid()), 0, 6);
        $outra = new Journal();
        $outra->setData('urlPath', $caminho);
        $outra->setData('name', ['pt_BR' => 'Revista de teste do requiredMultilingualMetadata']);
        $outra->setData('primaryLocale', 'pt_BR');
        $outra->setData('supportedLocales', ['pt_BR']);
        $outra->setData('enabled', false);

        $novoId = null;
        try {
            $novoId = $servico->add($outra, $request)->getId(); // add() devolve o objeto, nao o id
            $outra = Application::getContextDAO()->getById($novoId);
            $outra->setData('supportedSubmissionMetadataLocales', ['es@formal', 'pt_BR']);
            Application::getContextDAO()->updateObject($outra);
            $outra = Application::getContextDAO()->getById($novoId);

            $plugin->updateSetting($novoId, 'titleLocales', ['es@formal'], 'object');

            igual(['es@formal'], $plugin->getEnforceableLocales($outra, 'title', 'pt_BR'), 'revista nova usa a config dela');
            igual(['en_US'], $plugin->getConfiguredLocales(CONTEXT_ID, 'title'), 'revista 1 nao pode ter sido afetada');
            igual([], $plugin->getConfiguredLocales($novoId, 'abstract'), 'revista nova comeca sem config de resumo');
        } finally {
            // Busca pelo caminho, e nao pelo id devolvido: se o add() morrer no meio,
            // a revista pode ter sido gravada mesmo sem $novoId ter sido atribuido.
            $criada = DB::table('journals')->where('path', $caminho)->first();
            if ($criada) {
                $servico->delete(Application::getContextDAO()->getById($criada->journal_id));
            }
            Registry::delete('user');
        }
    });

    caso('G03', 'idioma configurado que sumiu da revista nao gera erro fantasma', function () use ($plugin, $context) {
        configurar(['zz_ZZ'], ['zz_ZZ']);   // gravado direto, sem passar pelo form
        igual([], $plugin->getEnforceableLocales($context, 'title', 'pt_BR'), 'idioma inexistente e filtrado');

        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        igual([], errosMeta(recarregar($s), 2), 'nao pode aparecer erro em zz_ZZ');
        configurar(['en_US'], ['en_US']);
    });

    caso('G04', 'todos os idiomas ativos configurados', function () use ($context) {
        configurar($context->getSupportedSubmissionMetadataLocales(), $context->getSupportedSubmissionMetadataLocales());
        [$s, $p] = novaSubmissao('pt_BR');
        definirMetadados($p, ['title' => ['pt_BR' => 'T'], 'abstract' => ['pt_BR' => '<p>R</p>']]);
        igual(
            ['abstract' => ['en_US', 'es@formal'], 'title' => ['en_US', 'es@formal']],
            errosMeta(recarregar($s), 2),
            'pt_BR sai, os outros dois entram'
        );
        configurar(['en_US'], ['en_US']);
    });
} catch (Throwable $e) {
    $falhaFatal = $e;
}

//
// Restauracao
//
echo "\n" . str_repeat('=', 78) . "\nRESTAURACAO\n" . str_repeat('=', 78) . "\n";

$plugin->updateSetting(CONTEXT_ID, 'titleLocales', $ORIGINAL['titleLocales'] ?? [], 'object');
$plugin->updateSetting(CONTEXT_ID, 'abstractLocales', $ORIGINAL['abstractLocales'] ?? [], 'object');
$plugin->updateSetting(CONTEXT_ID, 'keywordsLocales', $ORIGINAL['keywordsLocales'] ?? [], 'object');
echo '  config do plugin restaurada: title=' . json_encode($plugin->getConfiguredLocales(CONTEXT_ID, 'title'))
    . ' abstract=' . json_encode($plugin->getConfiguredLocales(CONTEXT_ID, 'abstract'))
    . ' keywords=' . json_encode($plugin->getConfiguredLocales(CONTEXT_ID, 'keywords')) . "\n";

$ctx = Application::getContextDAO()->getById(CONTEXT_ID);
$ctx->setData('keywords', $ORIGINAL['keywords']);
$ctx->setData('supportedSubmissionMetadataLocales', $ORIGINAL['metadataLocales']);
Application::getContextDAO()->updateObject($ctx);
echo '  revista restaurada: keywords=' . var_export($ORIGINAL['keywords'], true)
    . ' idiomas=' . implode(',', $ORIGINAL['metadataLocales']) . "\n";

DB::table('sections')->where('section_id', SECTION_ID)->update(['abstracts_not_required' => $ORIGINAL['abstractsNotRequired']]);
echo "  secao restaurada: abstracts_not_required={$ORIGINAL['abstractsNotRequired']}\n";

if ($manter) {
    echo '  submissoes de teste MANTIDAS (--manter): ' . implode(', ', $CRIADAS) . "\n";
} else {
    $apagadas = 0;
    foreach ($CRIADAS as $id) {
        if ($s = Repo::submission()->get($id)) {
            Repo::submission()->delete($s);
            $apagadas++;
        }
    }
    echo "  submissoes de teste criadas e removidas: {$apagadas} (ids " . implode(', ', $CRIADAS) . ")\n";
}

//
// Placar
//
$total = count($RESULTADOS);
$falhas = array_values(array_filter($RESULTADOS, fn ($r) => !$r['ok']));

echo "\n" . str_repeat('=', 78) . "\n";
printf("PLACAR: %d/%d passaram\n", $total - count($falhas), $total);
echo str_repeat('=', 78) . "\n";
foreach ($falhas as $f) {
    printf("  FALHOU %-4s %s\n         %s\n", $f['id'], $f['titulo'], str_replace("\n", "\n         ", $f['msg']));
}
if ($falhaFatal) {
    echo "\nFALHA FATAL (bateria interrompida): " . $falhaFatal->getMessage() . "\n"
        . $falhaFatal->getFile() . ':' . $falhaFatal->getLine() . "\n";
}

file_put_contents(__DIR__ . '/resultado.json', json_encode([
    'quando' => date('c'),
    'ojs' => Application::get()->getCurrentVersion()->getVersionString(),
    'total' => $total,
    'falhas' => count($falhas),
    'casos' => $RESULTADOS,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

exit(count($falhas) || $falhaFatal ? 1 : 0);
