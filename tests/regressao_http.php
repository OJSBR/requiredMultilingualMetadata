<?php

/**
 * @file tests/regressao_http.php
 *
 * Plugin autoral OJSBR.
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Bateria de interface e ponta a ponta (bloco F + E06). Loga de verdade no
 *        site e usa os mesmos endpoints do assistente de submissão e da grade de
 *        plugins. Cria e apaga um gestor temporário para abrir a tela de
 *        configuração; troca e restaura a senha do autor de teste.
 *
 * Uso: php plugins/generic/requiredMultilingualMetadata/tests/regressao_http.php [--manter]
 */

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;
use AltchaOrg\Altcha\Hasher\Algorithm;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\userGroup\UserGroup;

$root = dirname(__DIR__, 4);
chdir($root);
define('INDEX_FILE_LOCATION', $root . '/index.php');
require $root . '/lib/pkp/includes/bootstrap.php';

const CONTEXT_ID = 1;
const SECTION_ID = 1;
const UG_GESTOR = 2;
const PLUGIN = 'requiredmultilingualmetadataplugin';
const AUTOR = 'autorteste';
// Senha efemera: o proprio script grava o hash e o restaura no fim. Da para fixar
// com RMM_TEST_PASSWORD se for preciso repetir o login manualmente.
define('SENHA', getenv('RMM_TEST_PASSWORD') ?: ('Rmm!' . bin2hex(random_bytes(9)) . '#Aa1'));
const GESTOR = 'rmmtest_gestor';

$manter = in_array('--manter', $argv, true);

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
    exit("FALHA FATAL: plugin nao carregado.\n");
}

$BASE = Config::getVar('general', 'base_url');
$JOURNAL = $context->getPath();
$API = "{$BASE}/index.php/{$JOURNAL}/api/v1";
$PAGE = "{$BASE}/index.php/{$JOURNAL}";
$GRID = "{$BASE}/index.php/{$JOURNAL}/\$\$\$call\$\$\$/grid/settings/plugins/settings-plugin-grid/manage";

//
// Mini-framework
//
$RESULTADOS = [];

function bloco(string $t): void
{
    echo "\n" . str_repeat('=', 78) . "\n{$t}\n" . str_repeat('=', 78) . "\n";
}

function caso(string $id, string $titulo, callable $fn): void
{
    global $RESULTADOS;
    try {
        $fn();
        $RESULTADOS[] = ['id' => $id, 'titulo' => $titulo, 'ok' => true, 'msg' => ''];
        printf("  [ PASS  ] %-4s %s\n", $id, $titulo);
    } catch (Throwable $e) {
        $RESULTADOS[] = ['id' => $id, 'titulo' => $titulo, 'ok' => false, 'msg' => $e->getMessage()];
        printf("  [ FALHA ] %-4s %s\n           -> %s\n", $id, $titulo, $e->getMessage());
    }
}

function ok(bool $c, string $m): void
{
    if (!$c) {
        throw new RuntimeException($m);
    }
}

function igual($esp, $obt, string $m): void
{
    if ($esp !== $obt) {
        throw new RuntimeException($m . ' | esperado=' . json_encode($esp, JSON_UNESCAPED_SLASHES)
            . ' obtido=' . json_encode($obt, JSON_UNESCAPED_SLASHES));
    }
}

//
// Cliente HTTP com sessão
//
function altchaPayload(): string
{
    $altcha = new Altcha(Config::getVar('captcha', 'altcha_hmackey'));
    $max = (int) (Config::getVar('captcha', 'altcha_encrypt_number') ?: 10000);
    $desafio = $altcha->createChallenge(new ChallengeOptions(algorithm: Algorithm::SHA256, maxNumber: $max));
    for ($n = 0; $n <= $max; $n++) {
        if (hash('sha256', $desafio->salt . $n) === $desafio->challenge) {
            return base64_encode(json_encode([
                'algorithm' => $desafio->algorithm, 'challenge' => $desafio->challenge,
                'number' => $n, 'salt' => $desafio->salt, 'signature' => $desafio->signature,
            ]));
        }
    }
    throw new RuntimeException('nao resolvi o desafio altcha');
}

class Sessao
{
    public string $jar;
    public ?string $csrf = null;

    public function __construct(string $tag)
    {
        $this->jar = sys_get_temp_dir() . '/rmm_' . $tag . '_' . getmypid() . '.cookies';
        @unlink($this->jar);
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    private function exec(string $metodo, string $url, $corpo = null, array $headers = [], bool $json = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36',
        ]);
        if ($corpo !== null) {
            if ($json) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
                $headers[] = 'Content-Type: application/json';
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($corpo) ? http_build_query($corpo) : $corpo);
            }
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => (string) $body];
    }

    public function get(string $url): array
    {
        return $this->exec('GET', $url);
    }

    public function postForm(string $url, array $d): array
    {
        return $this->exec('POST', $url, $d);
    }

    public function api(string $metodo, string $url, ?array $d = null): array
    {
        $r = $this->exec($metodo, $url, $d, ['X-Csrf-Token: ' . $this->csrf, 'Accept: application/json'], true);
        $r['json'] = json_decode($r['body'], true);

        return $r;
    }

    public function login(string $usuario, string $senha, string $uiLocale = 'pt_BR'): void
    {
        global $PAGE;
        $this->get("{$PAGE}/{$uiLocale}/index");
        $p = $this->get("{$PAGE}/{$uiLocale}/login");
        if (!preg_match('/name="csrfToken"\s+value="([^"]+)"/', $p['body'], $m)) {
            throw new RuntimeException('csrfToken do login nao encontrado');
        }
        $r = $this->postForm("{$PAGE}/{$uiLocale}/login/signIn", [
            'username' => $usuario, 'password' => $senha, 'csrfToken' => $m[1],
            'altcha' => altchaPayload(), 'remember' => 0, 'source' => '',
        ]);
        if (str_contains($r['body'], 'name="password"')) {
            throw new RuntimeException("login falhou para {$usuario}");
        }
        $this->pegarCsrf("{$PAGE}/{$uiLocale}/dashboard");
    }

    public function pegarCsrf(string $url): void
    {
        $r = $this->get($url);
        if (!preg_match('/"csrfToken":"([^"]+)"/', $r['body'], $m)) {
            throw new RuntimeException("csrf nao encontrado em {$url} (http {$r['code']})");
        }
        $this->csrf = $m[1];
    }

    /** Estado Vue injetado na página (pkp.registry.init) */
    public function estado(string $url): array
    {
        $r = $this->get($url);
        if (!preg_match('/pkp\.registry\.init\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*,\s*(\{.*\})\s*\);/s', $r['body'], $m)) {
            throw new RuntimeException("estado nao encontrado em {$url} (http {$r['code']})");
        }
        $estado = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true) ?? json_decode($m[1], true);
        if ($estado === null) {
            throw new RuntimeException('estado da pagina nao e JSON valido');
        }

        return $estado;
    }
}

//
// Auxiliares
//
function configurar(array $titulo, array $resumo, array $palavrasChave = []): void
{
    global $plugin;
    $plugin->updateSetting(CONTEXT_ID, 'titleLocales', $titulo, 'object');
    $plugin->updateSetting(CONTEXT_ID, 'abstractLocales', $resumo, 'object');
    $plugin->updateSetting(CONTEXT_ID, 'keywordsLocales', $palavrasChave, 'object');
}

/** Muda Fluxo de Trabalho > Metadados > Palavras-chave e recarrega o contexto global. */
function definirModoKeywords(string $modo): void
{
    global $context;
    $context->setData('keywords', $modo);
    Application::getContextDAO()->updateObject($context);
    $context = Application::getContextDAO()->getById(CONTEXT_ID);
}

/** Formulário de título/resumo dentro do estado do assistente. */
function formTituloResumo(array $estado): array
{
    foreach ($estado['steps'] as $passo) {
        foreach ($passo['sections'] as $secao) {
            if (($secao['form']['id'] ?? '') === 'titleAbstract') {
                return $secao['form'];
            }
        }
    }
    throw new RuntimeException('formulario titleAbstract nao encontrado no assistente');
}

function avisoDoCampo(array $form, string $campo): string
{
    foreach ($form['fields'] as $f) {
        if ($f['name'] === $campo) {
            return (string) ($f['description'] ?? '');
        }
    }
    throw new RuntimeException("campo {$campo} nao encontrado");
}

//
// Preparação: senha do autor e gestor temporário
//
$ORIGINAL = [
    'titleLocales' => $plugin->getSetting(CONTEXT_ID, 'titleLocales'),
    'abstractLocales' => $plugin->getSetting(CONTEXT_ID, 'abstractLocales'),
    'keywordsLocales' => $plugin->getSetting(CONTEXT_ID, 'keywordsLocales'),
    'keywords' => $context->getData('keywords'),
];
$autor = Repo::user()->getByUsername(AUTOR);
if (!$autor) {
    exit('FALHA FATAL: usuario ' . AUTOR . " nao existe\n");
}
$hashOriginal = DB::table('users')->where('user_id', $autor->getId())->value('password');
DB::table('users')->where('user_id', $autor->getId())
    ->update(['password' => Validation::encryptCredentials(AUTOR, SENHA)]);

$gestorId = null;
$CRIADAS = [];

echo "requiredMultilingualMetadata — regressao de interface\n";
echo "site: {$BASE} | revista: {$JOURNAL}\n";

$falhaFatal = null;

try {
    // gestor temporário
    $jaExiste = Repo::user()->getByUsername(GESTOR);
    if ($jaExiste) {
        $gestorId = $jaExiste->getId();
    } else {
        $novo = Repo::user()->newDataObject();
        $novo->setUsername(GESTOR);
        $novo->setEmail('rmmtest_gestor@example.invalid');
        $novo->setGivenName('Gestor', 'pt_BR');
        $novo->setFamilyName('de Teste', 'pt_BR');
        $novo->setPassword(Validation::encryptCredentials(GESTOR, SENHA));
        $novo->setDateRegistered(\Core::getCurrentDate());
        $novo->setInlineHelp(1);
        $novo->setMustChangePassword(false);
        $gestorId = Repo::user()->add($novo);
        Repo::userGroup()->assignUserToGroup($gestorId, UG_GESTOR);
    }
    echo 'gestor temporario: ' . GESTOR . " (user_id={$gestorId})\n";

    $sessaoAutor = new Sessao('autor');
    $sessaoAutor->login(AUTOR, SENHA);

    $sessaoGestor = new Sessao('gestor');
    $sessaoGestor->login(GESTOR, SENHA);

    /** Cria uma submissão pelo mesmo endpoint do assistente. */
    $novaSubmissao = function (string $locale = 'pt_BR') use ($sessaoAutor, $API, &$CRIADAS): array {
        $r = $sessaoAutor->api('POST', "{$API}/submissions", ['locale' => $locale, 'sectionId' => SECTION_ID]);
        if ($r['code'] !== 200) {
            throw new RuntimeException('nao consegui criar submissao: ' . substr($r['body'], 0, 200));
        }
        $CRIADAS[] = $r['json']['id'];

        return [$r['json']['id'], $r['json']['currentPublicationId']];
    };

    //
    // ------------------------------------------------------------------ F
    //
    bloco('F — Interface');

    [$sid, $pid] = $novaSubmissao('pt_BR');
    $sessaoAutor->api('PUT', "{$API}/submissions/{$sid}/publications/{$pid}", [
        'title' => ['pt_BR' => 'Titulo'],
        'abstract' => ['pt_BR' => '<p>Resumo</p>'],
    ]);

    caso('F01', 'assistente abre as abas dos idiomas exigidos', function () use ($sessaoAutor, $PAGE, $sid) {
        configurar(['en_US'], ['en_US']);
        $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
        igual(['pt_BR', 'en_US'], $form['visibleLocales'], 'abas abertas');
        igual('pt_BR', $form['primaryLocale'], 'locale primario continua o da submissao');
    });

    caso('F02', 'aviso no campo lista os idiomas certos', function () use ($sessaoAutor, $PAGE, $sid) {
        configurar(['en_US', 'es@formal'], ['en_US']);
        $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
        $t = avisoDoCampo($form, 'title');
        $a = avisoDoCampo($form, 'abstract');
        ok(str_contains($t, 'rmmNotice'), 'titulo deveria ter aviso');
        ok(str_contains($t, 'Spanish') || str_contains($t, 'spanhol') || str_contains($t, 'espanhol'), "titulo deveria citar o espanhol: {$t}");
        ok(str_contains($a, 'rmmNotice'), 'resumo deveria ter aviso');
        ok(!str_contains($a, 'Spanish'), "resumo NAO deveria citar o espanhol: {$a}");
    });

    caso('F03', 'config vazia: sem aba extra e sem aviso', function () use ($sessaoAutor, $PAGE, $sid) {
        configurar([], []);
        $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
        igual(['pt_BR'], $form['visibleLocales'], 'so a aba do idioma da submissao');
        ok(!str_contains(avisoDoCampo($form, 'title'), 'rmmNotice'), 'nao pode haver aviso no titulo');
        ok(!str_contains(avisoDoCampo($form, 'abstract'), 'rmmNotice'), 'nao pode haver aviso no resumo');
    });

    caso('F04', 'so titulo configurado: aviso so no titulo', function () use ($sessaoAutor, $PAGE, $sid) {
        configurar(['en_US'], []);
        $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
        igual(['pt_BR', 'en_US'], $form['visibleLocales'], 'aba abre pela uniao');
        ok(str_contains(avisoDoCampo($form, 'title'), 'rmmNotice'), 'titulo com aviso');
        ok(!str_contains(avisoDoCampo($form, 'abstract'), 'rmmNotice'), 'resumo sem aviso');
    });

    caso('F05', 'idioma que saiu da revista nao vira aba fantasma', function () use ($sessaoAutor, $PAGE, $sid, $context) {
        configurar(['en_US', 'es@formal'], []);
        $original = $context->getSupportedSubmissionMetadataLocales();
        $context->setData('supportedSubmissionMetadataLocales', ['en_US', 'pt_BR']);
        Application::getContextDAO()->updateObject($context);
        try {
            $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
            igual(['pt_BR', 'en_US'], $form['visibleLocales'], 'es@formal nao pode abrir aba');
            ok(!str_contains(avisoDoCampo($form, 'title'), 'Spanish'), 'aviso nao pode citar idioma desativado');
        } finally {
            $context->setData('supportedSubmissionMetadataLocales', $original);
            Application::getContextDAO()->updateObject($context);
        }
    });

    caso('F06', 'tela de configuracao abre para o gestor', function () use ($sessaoGestor, $GRID) {
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic';
        $r = $sessaoGestor->get($url);
        ok($r['code'] === 200, "esperava HTTP 200, veio {$r['code']}");
        $json = json_decode($r['body'], true);
        ok(is_array($json) && !empty($json['content']), 'resposta deveria trazer o HTML do formulario');
        $html = $json['content'];
        ok(str_contains($html, 'requiredMultilingualMetadataSettingsForm'), 'form do plugin ausente');
        ok(str_contains($html, 'name="titleLocales[]"'), 'checkbox de titulo ausente');
        ok(str_contains($html, 'name="abstractLocales[]"'), 'checkbox de resumo ausente');
        foreach (['en_US', 'es@formal', 'pt_BR'] as $loc) {
            ok(str_contains($html, 'value="' . htmlspecialchars($loc, ENT_QUOTES) . '"'), "faltou a linha do idioma {$loc}");
        }
    });

    caso('F07', 'salvar pela tela persiste e reabre marcado', function () use ($sessaoGestor, $GRID, $plugin) {
        configurar([], []);
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic';

        $r = $sessaoGestor->get($url);
        $html = json_decode($r['body'], true)['content'];
        ok(preg_match('/name="csrfToken"\s+value="([^"]+)"/', $html, $m) === 1, 'csrf do form nao encontrado');

        $salvar = $sessaoGestor->postForm($url . '&save=true', [
            'csrfToken' => $m[1],
            'titleLocales' => ['en_US', 'es@formal'],
            'abstractLocales' => ['en_US'],
        ]);
        ok($salvar['code'] === 200, "salvar deveria devolver 200, veio {$salvar['code']}");

        igual(['en_US', 'es@formal'], $plugin->getConfiguredLocales(CONTEXT_ID, 'title'), 'titulo gravado pela tela');
        igual(['en_US'], $plugin->getConfiguredLocales(CONTEXT_ID, 'abstract'), 'resumo gravado pela tela');

        $r2 = $sessaoGestor->get($url);
        $html2 = json_decode($r2['body'], true)['content'];
        ok(preg_match('/id="rmmTitle-en_US"[^>]*checked/', $html2) === 1, 'en_US deveria reabrir marcado no titulo');
        ok(preg_match('/id="rmmAbstract-es@formal"[^>]*checked/', $html2) !== 1, 'es@formal nao deveria estar marcado no resumo');
    });

    caso('F09', 'aviso entra escapado e nao quebra o HTML do campo', function () use ($sessaoAutor, $PAGE, $sid) {
        configurar(['en_US'], ['en_US']);
        $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
        $t = avisoDoCampo($form, 'title');
        igual(1, substr_count($t, 'rmmNotice'), 'o aviso nao pode ser inserido mais de uma vez');
        $interno = strip_tags($t);
        ok(!str_contains($interno, '<'), 'o texto do aviso nao pode conter marcacao crua');
        $doc = new DOMDocument();
        ok(@$doc->loadHTML('<div>' . $t . '</div>') === true, 'o aviso deveria ser HTML valido');
    });

    caso('F08', 'plugin desabilitado: assistente volta ao normal', function () use ($sessaoAutor, $PAGE, $sid, $plugin) {
        configurar(['en_US'], ['en_US']);
        $plugin->updateSetting(CONTEXT_ID, 'enabled', false, 'bool');
        try {
            $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
            igual(['pt_BR'], $form['visibleLocales'], 'sem plugin, so a aba do idioma da submissao');
            ok(!str_contains(avisoDoCampo($form, 'title'), 'rmmNotice'), 'sem plugin nao pode haver aviso');
        } finally {
            $plugin->updateSetting(CONTEXT_ID, 'enabled', true, 'bool');
        }
    });

    caso('F10', 'palavras-chave: aba e aviso quando a revista exige', function () use ($sessaoAutor, $PAGE, $sid) {
        definirModoKeywords('require');
        configurar([], [], ['en_US']);
        $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
        igual(['pt_BR', 'en_US'], $form['visibleLocales'], 'aba do idioma exigido aberta');
        ok(str_contains(avisoDoCampo($form, 'keywords'), 'rmmNotice'), 'palavras-chave deveriam ter aviso');
        ok(!str_contains(avisoDoCampo($form, 'title'), 'rmmNotice'), 'titulo nao esta configurado, nao pode ter aviso');
    });

    caso('F11', 'palavras-chave: sem aviso quando a revista nao exige', function () use ($sessaoAutor, $PAGE, $sid) {
        definirModoKeywords('request');
        configurar([], [], ['en_US']);
        $form = formTituloResumo($sessaoAutor->estado("{$PAGE}/pt_BR/submission?id={$sid}"));
        igual(['pt_BR'], $form['visibleLocales'], 'sem exigencia da revista, nenhuma aba extra');
        ok(!str_contains(avisoDoCampo($form, 'keywords'), 'rmmNotice'), 'nao pode haver aviso em palavras-chave');
    });

    caso('F12', 'tela de configuracao traz a coluna de palavras-chave e o aviso de inativa', function () use ($sessaoGestor, $GRID) {
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic';

        // Procura a DIV do aviso, nao a classe solta: o seletor CSS 'rmmNotice--info'
        // aparece sempre no bloco <style> da tela.
        $aviso = 'class="rmmNotice rmmNotice--info"';
        $esmaecida = 'class="rmmCheck rmmCheck--off"';

        definirModoKeywords('request');
        $html = json_decode($sessaoGestor->get($url)['body'], true)['content'];
        ok(str_contains($html, 'name="keywordsLocales[]"'), 'coluna de palavras-chave ausente');
        ok(str_contains($html, $aviso), 'faltou o aviso de coluna inativa');
        ok(str_contains($html, $esmaecida), 'a coluna deveria estar marcada como inativa');

        definirModoKeywords('require');
        $html2 = json_decode($sessaoGestor->get($url)['body'], true)['content'];
        ok(str_contains($html2, 'name="keywordsLocales[]"'), 'coluna de palavras-chave ausente');
        ok(!str_contains($html2, $aviso), 'com require nao pode haver aviso de inativa');
        ok(!str_contains($html2, $esmaecida), 'com require a coluna nao pode estar esmaecida');
    });

    caso('F13', 'salvar palavras-chave pela tela persiste', function () use ($sessaoGestor, $GRID, $plugin) {
        definirModoKeywords('require');
        configurar([], [], []);
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic';

        $html = json_decode($sessaoGestor->get($url)['body'], true)['content'];
        ok(preg_match('/name="csrfToken"\s+value="([^"]+)"/', $html, $m) === 1, 'csrf do form nao encontrado');

        $salvar = $sessaoGestor->postForm($url . '&save=true', [
            'csrfToken' => $m[1],
            'titleLocales' => [],
            'abstractLocales' => [],
            'keywordsLocales' => ['en_US', 'es@formal'],
        ]);
        ok($salvar['code'] === 200, "salvar deveria devolver 200, veio {$salvar['code']}");
        igual(['en_US', 'es@formal'], $plugin->getConfiguredLocales(CONTEXT_ID, 'keywords'), 'palavras-chave gravadas');

        $html2 = json_decode($sessaoGestor->get($url)['body'], true)['content'];
        ok(preg_match('/id="rmmKeywords-en_US"[^>]*checked/', $html2) === 1, 'en_US deveria reabrir marcado');
    });

    //
    // ------------------------------------------------------------------ E06
    //
    bloco('E06 — Ponta a ponta');

    caso('E06', 'submissao completa com traducoes e aceita', function () use ($sessaoAutor, $API, $novaSubmissao) {
        // pior caso: os tres metadados exigidos em en_US, com a revista exigindo palavras-chave
        definirModoKeywords('require');
        configurar(['en_US'], ['en_US'], ['en_US']);
        [$s, $p] = $novaSubmissao('pt_BR');

        // 1) so pt_BR: tem de barrar
        $sessaoAutor->api('PUT', "{$API}/submissions/{$s}/publications/{$p}", [
            'title' => ['pt_BR' => 'Titulo'],
            'abstract' => ['pt_BR' => '<p>Resumo</p>'],
            'keywords' => ['pt_BR' => ['alfa', 'beta']],
        ]);
        $r1 = $sessaoAutor->api('PUT', "{$API}/submissions/{$s}/submit", ['_validateOnly' => true]);
        ok($r1['code'] === 400, 'deveria barrar sem a traducao');
        ok(isset($r1['json']['title']['en_US']), 'faltou o erro de titulo em en_US');
        ok(isset($r1['json']['keywords']['en_US']), 'faltou o erro de palavras-chave em en_US');

        // 2) com traducao + arquivos: tem de passar
        $sessaoAutor->api('PUT', "{$API}/submissions/{$s}/publications/{$p}", [
            'title' => ['pt_BR' => 'Titulo', 'en_US' => 'Title'],
            'abstract' => ['pt_BR' => '<p>Resumo</p>', 'en_US' => '<p>Abstract</p>'],
            'keywords' => ['pt_BR' => ['alfa', 'beta'], 'en_US' => ['alpha', 'beta']],
        ]);
        foreach ([1 => 'artigo.txt', 13 => 'etica.txt'] as $genero => $nome) {
            $tmp = sys_get_temp_dir() . '/' . $nome;
            file_put_contents($tmp, "arquivo de teste\n");
            $ch = curl_init("{$API}/submissions/{$s}/files");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $sessaoAutor->jar,
                CURLOPT_COOKIEFILE => $sessaoAutor->jar, CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['X-Csrf-Token: ' . $sessaoAutor->csrf],
                CURLOPT_POSTFIELDS => [
                    'file' => new CURLFile($tmp, 'text/plain', $nome),
                    'fileStage' => 2, 'genreId' => $genero, 'name[pt_BR]' => $nome,
                ],
            ]);
            curl_exec($ch);
            $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            @unlink($tmp);
            ok($codigo === 200, "upload do genero {$genero} falhou ({$codigo})");
        }

        $r2 = $sessaoAutor->api('PUT', "{$API}/submissions/{$s}/submit", ['confirmCopyright' => true]);
        ok($r2['code'] === 200, 'deveria aceitar: ' . substr($r2['body'], 0, 300));
        ok(!empty($r2['json']['dateSubmitted']), 'dateSubmitted deveria estar preenchido');
    });
} catch (Throwable $e) {
    $falhaFatal = $e;
}

//
// Restauração
//
bloco('RESTAURACAO');

$plugin->updateSetting(CONTEXT_ID, 'titleLocales', $ORIGINAL['titleLocales'] ?? [], 'object');
$plugin->updateSetting(CONTEXT_ID, 'abstractLocales', $ORIGINAL['abstractLocales'] ?? [], 'object');
$plugin->updateSetting(CONTEXT_ID, 'keywordsLocales', $ORIGINAL['keywordsLocales'] ?? [], 'object');
$plugin->updateSetting(CONTEXT_ID, 'enabled', true, 'bool');
definirModoKeywords((string) $ORIGINAL['keywords']);
echo '  config do plugin restaurada: title=' . json_encode($plugin->getConfiguredLocales(CONTEXT_ID, 'title'))
    . ' abstract=' . json_encode($plugin->getConfiguredLocales(CONTEXT_ID, 'abstract'))
    . ' keywords=' . json_encode($plugin->getConfiguredLocales(CONTEXT_ID, 'keywords')) . "\n";
echo '  revista: keywords=' . var_export($ORIGINAL['keywords'], true) . "\n";

DB::table('users')->where('user_id', $autor->getId())->update(['password' => $hashOriginal]);
echo '  senha original de ' . AUTOR . " restaurada\n";

if ($manter) {
    echo '  gestor temporario e submissoes MANTIDOS (--manter)\n';
} else {
    $apagadas = 0;
    foreach ($CRIADAS as $id) {
        if ($s = Repo::submission()->get($id)) {
            Repo::submission()->delete($s);
            $apagadas++;
        }
    }
    echo "  submissoes de teste removidas: {$apagadas} (ids " . implode(', ', $CRIADAS) . ")\n";

    if ($gestorId) {
        Repo::userGroup()->deleteAssignmentsByUserId($gestorId);
        Repo::user()->delete(Repo::user()->get($gestorId));
        echo '  gestor temporario removido (' . GESTOR . ", user_id={$gestorId})\n";
    }
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
    printf("  FALHOU %-4s %s\n         %s\n", $f['id'], $f['titulo'], $f['msg']);
}
if ($falhaFatal) {
    echo "\nFALHA FATAL: " . $falhaFatal->getMessage() . "\n"
        . $falhaFatal->getFile() . ':' . $falhaFatal->getLine() . "\n";
}

file_put_contents(__DIR__ . '/resultado_http.json', json_encode([
    'quando' => date('c'),
    'total' => $total,
    'falhas' => count($falhas),
    'casos' => $RESULTADOS,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

exit(count($falhas) || $falhaFatal ? 1 : 0);
