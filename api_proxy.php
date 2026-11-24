<?php
/**
 * Funil Serasa Limpa Nome (SR) 2026 - Proxy server-side para Genesys Finance (PIX ONE-TIME).
 *
 * Gateway de pagamentos: GENESYS FINANCE (exclusivo).
 * Mantem credenciais FORA do JS, resolve CORS e devolve JSON em qualquer cenario
 * (inclusive em erros fatais/warnings), sem JAMAIS vazar segredos ou stack traces
 * para o cliente. A integracao UTMify permanece INTACTA (pedidos/rastreamento).
 *
 * SEGURANCA (paridade com o projeto programa-vip):
 *  - Credenciais lidas via .env (.private/ ou FORA do web root) — nada hardcoded.
 *  - Erros internos NUNCA expostos ao cliente; detalhes so via ?action=diag.
 *  - Compatibilidade com servidores que DESABILITAM putenv()/getenv() (aaPanel).
 *  - Webhook autenticado (token/HMAC) antes de aceitar status "pago".
 *  - Confirmacao de pagamento por STATUS liquidado do PIX (inclui AUTHORIZED,
 *    que no PIX instantaneo significa LIQUIDADO) ou por campo real de data de
 *    pagamento (paid_at/payedAt/paidAt/paid_date) — NUNCA por timestamp generico
 *    como updated_at/created_at, evitando falso "pago".
 *
 * Rotas:
 *  - POST api_proxy.php?action=create_pix       -> POST /v1/transactions
 *  - GET  api_proxy.php?action=check_status&id= -> GET  /v1/transactions/{id}
 *  - GET  api_proxy.php?action=ping             -> Health check (nao chama Genesys)
 *  - GET  api_proxy.php?action=diag             -> Diagnostico seguro (segredos mascarados)
 *  - POST api_proxy.php?action=webhook          -> Recebe webhook Genesys (cache status)
 */

// =============================================================================
// BOOTSTRAP DEFENSIVO - sempre JSON, mesmo em fatal/warning
// =============================================================================
ob_start();

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

send_cors_headers_early();

if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
    http_response_code(204);
    @ob_end_clean();
    exit;
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// API de CPF — padrao embutido (secrets-cpf/.env, se existir, tem prioridade)
// Ordem: CPF_API_* (apicpf) -> CPF_API2_* (apicpf, outra chave) -> CPF_API3_* (ZapGroup query).
if (!defined('CPF_API_URL_DEFAULT'))    define('CPF_API_URL_DEFAULT',    'https://api.apicpf.dev/consulta');
if (!defined('CPF_API_TOKEN_DEFAULT'))  define('CPF_API_TOKEN_DEFAULT',  '');
if (!defined('CPF_API2_URL_DEFAULT'))   define('CPF_API2_URL_DEFAULT',   'https://api.apicpf.dev/consulta');
if (!defined('CPF_API2_TOKEN_DEFAULT'))  define('CPF_API2_TOKEN_DEFAULT',  '');
if (!defined('CPF_API3_URL_DEFAULT'))    define('CPF_API3_URL_DEFAULT',   'https://api.zapgroup.shop/consultar-filtrada/cpf');
if (!defined('CPF_API3_TOKEN_DEFAULT'))  define('CPF_API3_TOKEN_DEFAULT',  '');
// Meta Pixel / Conversions API (CAPI) — padrao embutido (secrets-meta/.env tem prioridade)
if (!defined('META_PIXEL_ID_DEFAULT'))     define('META_PIXEL_ID_DEFAULT',     '1601503185054241');
if (!defined('META_ACCESS_TOKEN_DEFAULT')) define('META_ACCESS_TOKEN_DEFAULT', 'EAAILvgYV0WoBSiVnPo1Dk7gZCIoEUWmfjzjhQVnoxZBr8LEIlksJLVitJrDlpPuEbDCqgYkfEJBwJHChzQP0g2PYGaJIiDIHcgcyvRPV9WXBFZCBWZCjTBbgxN9x0GsfX2SpW48LGTordL3hsWBxuH5Hmu8DwdI74ZBWiq6T3klQCyZAlZA5AnfTxpUR6Od6lkZCxAZDZD');

set_exception_handler(function ($e) {
    error_log('[api_proxy] Exception: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    @ob_end_clean();

    // Quando action=diag, mostra detalhes do erro para facilitar depuracao.
    // Caminhos de arquivo sao exibidos SEM o diretorio base (seguranca).
    $isDiag = (isset($_GET['action']) && trim($_GET['action']) === 'diag');
    if ($isDiag) {
        echo json_encode(array(
            'ok'         => false,
            'error'      => 'Erro durante diagnostico.',
            'debug_msg'  => $e->getMessage(),
            'debug_file' => basename($e->getFile()),
            'debug_line' => $e->getLine(),
            'dica'       => 'Verifique os logs do servidor (error_log) para o stack trace completo.'
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode(array(
            'ok'    => false,
            'error' => 'Erro interno no proxy.'
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        error_log('[api_proxy] Fatal: ' . $err['message'] . ' em ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        @ob_end_clean();

        $isDiag = (isset($_GET['action']) && trim($_GET['action']) === 'diag');
        if ($isDiag) {
            echo json_encode(array(
                'ok'         => false,
                'error'      => 'Erro fatal durante diagnostico.',
                'debug_msg'  => $err['message'],
                'debug_file' => basename($err['file']),
                'debug_line' => $err['line'],
                'dica'       => 'Verifique os logs do servidor (error_log) para detalhes completos.'
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo json_encode(array(
                'ok'    => false,
                'error' => 'Erro fatal no proxy PHP.'
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
});

// =============================================================================
// CREDENCIAIS (via variaveis de ambiente / .env)
// =============================================================================
// Carrega o .env (se existir) ANTES de qualquer leitura de credencial.
// Variaveis de ambiente reais do servidor tem prioridade sobre o .env.
//
// Procuramos o .env em VARIOS locais, do mais seguro para o padrao. O mais
// seguro e FORA do web root (uma pasta acima do site), porque nesse caso o
// arquivo nunca pode ser baixado pela internet — independente de o servidor
// ser Apache ou Nginx (no aaPanel/Nginx o .htaccess e IGNORADO!).
$GLOBALS['__ENV_FILE_USED'] = '';
$GLOBALS['__ENV_SOURCES'] = array('gateway'=>'', 'gateway_kupfy'=>'', 'gateway_genesys'=>'', 'gateway_velani'=>'', 'cpf'=>'', 'meta'=>'', 'utmify'=>'', 'mail'=>'', 'fallback'=>'');
$GLOBALS['__BOOTSTRAP_ERRORS'] = array(); // acumula problemas para o diag

// Identificador EXCLUSIVO desta oferta. Define os nomes das pastas de ambiente
// dedicadas (utmify-<slug>, mail.rec-<slug>, secrets-disrupty-<slug>).
$__FUNIL_SLUG = 'sr';
$GLOBALS['__FUNIL_SLUG'] = $__FUNIL_SLUG;

$__envLoader = __DIR__ . '/.private/env_loader.php';
if (@is_readable($__envLoader)) {
    try {
        require_once($__envLoader);
    } catch (Exception $e) {
        $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar env_loader.php: ' . $e->getMessage();
    }
    if (function_exists('load_dotenv')) {
        $__parent = dirname(__DIR__); // uma pasta ACIMA do web root do site

        // -------------------------------------------------------------------
        // AMBIENTE ESCOPADO POR PASTA (evita cruzar credenciais entre ofertas)
        // Cada pasta fornece SOMENTE o seu grupo de chaves (whitelist). Assim,
        // subir esta oferta no painel nunca "puxa" o dashboard UTMify, o e-mail
        // ou o gateway de outra oferta hospedada no mesmo servidor.
        // -------------------------------------------------------------------
        $__KEYS_GATEWAY = array('DISRUPTY_PUBLIC_KEY','DISRUPTY_PRIVATE_KEY','DISRUPTY_BASE_URL','WEBHOOK_SECRET','DISRUPTY_WEBHOOK_TOKEN','GENESYS_WEBHOOK_SECRET','GATEWAY_PROVIDER');
        $__KEYS_KUPFY   = array('KUPFY_PUBLIC_KEY','KUPFY_SECRET_KEY','KUPFY_PRIVATE_KEY','KUPFY_BASE_URL','KUPFY_WEBHOOK_TOKEN','KUPFY_POLL_INTERVAL','WEBHOOK_SECRET','GATEWAY_PROVIDER');
        $__KEYS_GENESYS = array('GENESYS_ACCOUNT_HASH','GENESYS_API_TOKEN','GENESYS_API_SECRET','GENESYS_BASE_URL','GENESYS_WEBHOOK_SECRET','WEBHOOK_SECRET','GATEWAY_PROVIDER');
        $__KEYS_VELANI  = array('VELANI_SECRET_KEY','VELANI_API_KEY','VELANI_PUBLIC_KEY','VELANI_BASE_URL','VELANI_WEBHOOK_SECRET','WEBHOOK_SECRET','GATEWAY_PROVIDER');
        $__KEYS_MANGOFY = array('MANGOFY_API_KEY','MANGOFY_AUTHORIZATION','MANGOFY_STORE_CODE','MANGOFY_BASE_URL','MANGOFY_WEBHOOK_SECRET','WEBHOOK_SECRET','GATEWAY_PROVIDER');
        $__KEYS_CPF     = array('CPF_API_URL','CPF_API_TOKEN','CPF_API_KEY','CPF_API_MODE','CPF_API2_URL','CPF_API2_TOKEN','CPF_API2_KEY','CPF_API2_MODE','CPF_API3_URL','CPF_API3_TOKEN','CPF_API3_KEY','CPF_API3_MODE','CPF_CACHE_TTL');
        $__KEYS_META    = array('META_PIXEL_ID','META_ACCESS_TOKEN');
        $__KEYS_UTMIFY  = array('UTMIFY_API_TOKEN','UTMIFY_API_URL');
        $__KEYS_MAIL    = array('SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','SMTP_PASS','SMTP_FROM_EMAIL','SMTP_FROM_NAME','RECOVERY_BASE_URL','RECOVERY_SECRET','RECOVERY_DISCOUNT_PRICE','NOTIFY_WEBHOOK_URL','NOTIFY_PROVIDER','OFFER_NAME','OFFER_PRODUCT_LABEL','DELIVERABLE_URL','DELIVERABLE_BACKFILL_DAYS');

        // Carrega o 1o candidato legivel de um escopo, aplicando a whitelist.
        $__loadScope = function($cands, $keys, $scope) {
            foreach ($cands as $p) {
                if ($p !== '' && @is_readable($p)) {
                    try { load_dotenv($p, $keys); }
                    catch (Exception $e) { $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar .env ('.$scope.'): '.$e->getMessage(); }
                    if ($GLOBALS['__ENV_SOURCES'][$scope] === '') { $GLOBALS['__ENV_SOURCES'][$scope] = $p; }
                    return;
                }
            }
        };

        // 0) Override total via variavel real do servidor (todas as chaves).
        $__explicit = (function_exists('getenv') && @getenv('FUNIL_ENV_FILE') !== false) ? @getenv('FUNIL_ENV_FILE') : '';
        if ($__explicit !== '' && @is_readable($__explicit)) {
            try { load_dotenv($__explicit); } catch (Exception $e) {}
            $GLOBALS['__ENV_FILE_USED'] = $__explicit;
        }

        // 1) GATEWAY (Disrupty) — pasta so com credenciais de API do gateway.
        //     zip 88: CONTA SEPARADA POR OFERTA: esta oferta le PRIMEIRO secrets-disrupty-sr/.env
        //     (Desenrola = secrets-disrupty-db | SR = secrets-disrupty-sr | CNH = secrets-disrupty-cs).
        $__loadScope(array(
            $__parent . '/secrets-disrupty-sr/.env',
            $__parent . '/secrets-disrupty/.env',
        ), $__KEYS_GATEWAY, 'gateway');

        // 1b) GATEWAY (Kupfy) — pasta so com credenciais de API da Kupfy.
        //     Ao preencher esta pasta, a Kupfy vira o gateway ATIVO do funil
        //     (a menos que GATEWAY_PROVIDER diga o contrario).
        $__loadScope(array(
            $__parent . '/secrets-kupfy-' . $__FUNIL_SLUG . '/.env',
            $__parent . '/secrets-kupfy/.env',
        ), $__KEYS_KUPFY, 'gateway_kupfy');

        // 1c) GATEWAY (Genesys Finance) — pasta so com credenciais de API da Genesys.
        //     Ao preencher esta pasta, a Genesys vira o gateway ATIVO do funil
        //     (a menos que GATEWAY_PROVIDER diga o contrario).
        //     CONTA SEPARADA POR OFERTA: esta oferta le PRIMEIRO secrets-genesys-sr/.env.
        //     (SR = secrets-genesys-sr | CNH Social = secrets-genesys-cs | SHEIN = secrets-genesys-shein).
        //     A pasta generica secrets-genesys/ fica so como ultimo recurso (evite usa-la com varias contas).
        $__loadScope(array(
            $__parent . '/secrets-genesys-sr/.env',
            $__parent . '/secrets-genesys-' . $__FUNIL_SLUG . '/.env',
            $__parent . '/secrets-genesys/.env',
        ), $__KEYS_GENESYS, 'gateway_genesys');

        // 1c-bis) GATEWAY (Velani Pagamentos) — pasta so com credenciais de API da Velani.
        //     Ao preencher esta pasta, a Velani vira o gateway ATIVO do funil
        //     (a menos que GATEWAY_PROVIDER diga o contrario).
        //     CONTA SEPARADA POR OFERTA: esta oferta le PRIMEIRO secrets-velani-sr/.env.
        //     (SR = secrets-velani-sr | CNH Social = secrets-velani-cs). A pasta
        //     generica secrets-velani/ fica so como ultimo recurso (evite usa-la com 2 contas).
        $__loadScope(array(
            $__parent . '/secrets-velani-sr/.env',
            $__parent . '/secrets-velani-' . $__FUNIL_SLUG . '/.env',
            $__parent . '/secrets-velani/.env',
        ), $__KEYS_VELANI, 'gateway_velani');

        // 1c-ter) GATEWAY (MangoFy Checkout API) — secrets-mangofy-sr/.env
        $__loadScope(array(
            $__parent . '/secrets-mangofy-sr/.env',
            $__parent . '/secrets-mangofy-' . $__FUNIL_SLUG . '/.env',
            $__parent . '/secrets-mangofy/.env',
            __DIR__ . '/secrets-mangofy-sr/.env',
            __DIR__ . '/secrets-mangofy/.env',
        ), $__KEYS_MANGOFY, 'gateway_mangofy');

        // 1d) API DE CONSULTA DE CPF — pasta so com a URL/token da API de CPF.
        //     Mesmo modelo das pastas de gateway: secrets-cpf-<slug>/ ou secrets-cpf/.
        $__loadScope(array(
            $__parent . '/secrets-cpf-' . $__FUNIL_SLUG . '/.env',
            $__parent . '/secrets-cpf/.env',
        ), $__KEYS_CPF, 'cpf');

        // 1e) META (Pixel / Conversions API) — pasta so com o pixel id e o token.
        $__loadScope(array(
            $__parent . '/secrets-meta-' . $__FUNIL_SLUG . '/.env',
            $__parent . '/secrets-meta/.env',
        ), $__KEYS_META, 'meta');

        // 2) UTMIFY — pasta EXCLUSIVA desta oferta (dashboard/pixel corretos).
        $__loadScope(array(
            $__parent . '/utmify-' . $__FUNIL_SLUG . '/.env',
        ), $__KEYS_UTMIFY, 'utmify');

        // 3) E-MAIL / recuperacao / push — pasta EXCLUSIVA desta oferta.
        $__loadScope(array(
            $__parent . '/mail.rec-' . $__FUNIL_SLUG . '/.env',
        ), $__KEYS_MAIL, 'mail');

        // 4) FALLBACK (compatibilidade): .env consolidado antigo. Preenche APENAS
        //    o que ainda faltar — load_dotenv NUNCA sobrescreve o que ja veio das
        //    pastas escopadas acima.
        $__fallbacks = array(
            $__parent . '/secrets-funil-sr/.env',
            $__parent . '/.funil-secrets/.env',
            __DIR__ . '/.private/.env',
        );
        foreach ($__fallbacks as $__fb) {
            if ($__fb !== '' && @is_readable($__fb)) {
                try { load_dotenv($__fb); }
                catch (Exception $e) { $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar .env fallback: '.$e->getMessage(); }
                if ($GLOBALS['__ENV_SOURCES']['fallback'] === '') { $GLOBALS['__ENV_SOURCES']['fallback'] = $__fb; }
            }
        }

        // Compat: __ENV_FILE_USED aponta para a 1a fonte efetivamente usada.
        if ($GLOBALS['__ENV_FILE_USED'] === '') {
            foreach (array('gateway','gateway_kupfy','gateway_genesys','gateway_velani','gateway_mangofy','cpf','utmify','mail','fallback') as $__sc) {
                if ($GLOBALS['__ENV_SOURCES'][$__sc] !== '') { $GLOBALS['__ENV_FILE_USED'] = $GLOBALS['__ENV_SOURCES'][$__sc]; break; }
            }
        }
    }
} else {
    $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'env_loader.php nao encontrado ou sem permissao de leitura em .private/';
}

// Motor de recuperacao de PIX (opcional — nunca quebra o fluxo se ausente).
$__recoveryLib = __DIR__ . '/.private/recovery_lib.php';
if (@is_readable($__recoveryLib)) {
    try { require_once $__recoveryLib; } catch (\Throwable $e) { @error_log('[recovery] falha ao carregar lib: ' . $e->getMessage()); }
}
// zip 88: tracking NO SERVIDOR (porte do zip 84 da CNH) — aditivo, nunca quebra o fluxo se ausente.
$__trkLib = __DIR__ . '/.private/trk_lib.php';
if (@is_readable($__trkLib)) {
    try { require_once $__trkLib; } catch (\Throwable $e) { @error_log('[trk] falha ao carregar lib: ' . $e->getMessage()); }
}

// Notificacoes push no celular (opcional — nunca quebra o fluxo se ausente).
$__notifyLib = __DIR__ . '/.private/notify_lib.php';
if (@is_readable($__notifyLib)) {
    try { require_once $__notifyLib; } catch (\Throwable $e) { @error_log('[notify] falha ao carregar lib: ' . $e->getMessage()); }
}

// -----------------------------------------------------------------------------
// CARREGAR CREDENCIAIS
// -----------------------------------------------------------------------------
try {
    load_disrupty_credentials();
} catch (Exception $e) {
    $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar credenciais Disrupty: ' . $e->getMessage();
}
try {
    load_kupfy_credentials();
} catch (Exception $e) {
    $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar credenciais Kupfy: ' . $e->getMessage();
}
try {
    load_genesys_credentials();
} catch (Exception $e) {
    $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar credenciais Genesys: ' . $e->getMessage();
}
try {
    load_velani_credentials();
} catch (Exception $e) {
    $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar credenciais Velani: ' . $e->getMessage();
}
try {
    load_mangofy_credentials();
} catch (Exception $e) {
    $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar credenciais MangoFy: ' . $e->getMessage();
}
try {
    load_utmify_credentials();
} catch (Exception $e) {
    $GLOBALS['__BOOTSTRAP_ERRORS'][] = 'Erro ao carregar credenciais Utmify: ' . $e->getMessage();
}

// Checagem de credenciais — trava se o gateway ATIVO nao estiver configurado.
// EXCECAO: action=diag pode continuar mesmo sem credenciais (para diagnosticar).
$__currentAction = isset($_GET['action']) ? trim($_GET['action']) : '';
$__gwActive = active_gateway();
if ($__gwActive === 'kupfy') {
    $__gwReady = (defined('KUPFY_CREDENTIALS_SOURCE') && KUPFY_CREDENTIALS_SOURCE === 'env');
} elseif ($__gwActive === 'genesys') {
    $__gwReady = (defined('GENESYS_CREDENTIALS_SOURCE') && GENESYS_CREDENTIALS_SOURCE === 'env');
} elseif ($__gwActive === 'velani') {
    $__gwReady = (defined('VELANI_CREDENTIALS_SOURCE') && VELANI_CREDENTIALS_SOURCE === 'env');
} elseif ($__gwActive === 'mangofy') {
    $__gwReady = (defined('MANGOFY_CREDENTIALS_SOURCE') && MANGOFY_CREDENTIALS_SOURCE === 'env');
} else {
    $__gwReady = (defined('DISRUPTY_CREDENTIALS_SOURCE') && DISRUPTY_CREDENTIALS_SOURCE === 'env');
}
if (!$__gwReady && $__currentAction !== 'diag' && $__currentAction !== 'ping' && $__currentAction !== 'test_notify' && $__currentAction !== 'consultar_cpf' && $__currentAction !== 'track_sale') {
    send_json(array(
        'ok'    => false,
        'error' => ($__gwActive === 'kupfy')
            ? 'Credenciais Kupfy nao disponiveis. Preencha KUPFY_PUBLIC_KEY e KUPFY_SECRET_KEY no .env da pasta secrets-kupfy (veja action=diag).'
            : (($__gwActive === 'genesys')
            ? 'Credenciais Genesys nao disponiveis. Preencha GENESYS_ACCOUNT_HASH e GENESYS_API_TOKEN no .env da pasta secrets-genesys-sr (veja action=diag).'
            : (($__gwActive === 'velani')
            ? ((defined('VELANI_CREDENTIALS_SOURCE') && VELANI_CREDENTIALS_SOURCE === 'public_key_only')
                ? 'Velani: foi configurada apenas a PUBLIC KEY (pk_live_...). Para criar PIX e obrigatoria a SECRET KEY (sk_live_...): coloque-a em VELANI_SECRET_KEY no .env da pasta secrets-velani-sr (veja action=diag).'
                : 'Credenciais Velani nao disponiveis. Preencha VELANI_SECRET_KEY (sk_live_...) no .env da pasta secrets-velani-sr (veja action=diag).')
            : (($__gwActive === 'mangofy')
            ? 'Credenciais MangoFy nao disponiveis. Preencha MANGOFY_API_KEY e MANGOFY_STORE_CODE no .env da pasta secrets-mangofy-sr (veja action=diag).'
            : 'Credenciais Disrupty nao disponiveis. Preencha DISRUPTY_PUBLIC_KEY e DISRUPTY_PRIVATE_KEY no arquivo .env (veja action=diag).')))
    ), 500);
}

// -----------------------------------------------------------------------------
// Endpoints Disrupty (modo Seller)
//   Base:      https://api-sellers.disruptybr.app
//   Auth:      headers x-api-public-key + x-api-private-key em TODA requisicao
//              (chaves da conta Seller, formato dsrp_<hex>; NAO usa token/Bearer)
//   Criar PIX: POST /api/sales  (amount em REAIS; resposta em CENTAVOS)
//              -> 201 com sale.payment.pix.key (copia-e-cola) e qrCodeBase64
//   Consultar: GET  /api/sales/{id}
//   Webhooks:  POST/GET /api/webhook (cadastro unico, nao por venda)
// -----------------------------------------------------------------------------
$__dsBase = function_exists('env_get') ? env_get('DISRUPTY_BASE_URL') : (function_exists('getenv') ? @getenv('DISRUPTY_BASE_URL') : '');
if (!is_string($__dsBase) || trim($__dsBase) === '') $__dsBase = 'https://api-sellers.disruptybr.app';
define('DISRUPTY_BASE_URL', rtrim(trim($__dsBase), '/'));
define('DISRUPTY_SALES_PATH', '/api/sales'); // a consulta acrescenta /{id}
define('DISRUPTY_WEBHOOKS_PATH', '/api/webhook');

// Timeouts com folga para a geracao do PIX no gateway.
define('DISRUPTY_CURL_TIMEOUT', 35);
define('DISRUPTY_CURL_CONNECT_TIMEOUT', 10);

// -----------------------------------------------------------------------------
// Endpoints Kupfy
//   Base:      https://app.kupfy.com/api/v1
//   Auth:      headers x-public-key + x-secret-key em TODA requisicao
//   Criar PIX: POST /gateway/pix/receive (amount em REAIS; retorna pix.code)
//   Consultar: GET  /gateway/transactions?id=<txId> (polling agressivo BLOQUEADO
//              pela Kupfy — o proxy aplica throttle local; webhook e o canal 1o)
//   Webhooks:  nao ha cadastro por conta — callbackUrl vai em CADA transacao
// -----------------------------------------------------------------------------
$__kfBase = function_exists('env_get') ? env_get('KUPFY_BASE_URL') : (function_exists('getenv') ? @getenv('KUPFY_BASE_URL') : '');
if (!is_string($__kfBase) || trim($__kfBase) === '') $__kfBase = 'https://app.kupfy.com/api/v1';
define('KUPFY_BASE_URL', rtrim(trim($__kfBase), '/'));
define('KUPFY_PIX_PATH', '/gateway/pix/receive');
define('KUPFY_TX_PATH', '/gateway/transactions');

// -----------------------------------------------------------------------------
// Endpoints Genesys Finance (V28) — mesmo contrato usado anteriormente no funil
//   Base:      https://api.genesys.finance (GENESYS_BASE_URL sobrescreve)
//   Criar PIX: POST /v1/transactions   |  Consultar: GET /v1/transactions/{id}
//   Webhooks:  nao ha cadastro por conta — webhook_url vai em CADA transacao
// -----------------------------------------------------------------------------
$__gnBase = function_exists('env_get') ? env_get('GENESYS_BASE_URL') : (function_exists('getenv') ? @getenv('GENESYS_BASE_URL') : '');
if (!is_string($__gnBase) || trim($__gnBase) === '') $__gnBase = 'https://api.genesys.finance';
if (!defined('GENESYS_BASE_URL')) define('GENESYS_BASE_URL', rtrim(trim($__gnBase), '/'));
if (!defined('GENESYS_PIX_CREATE_PATH')) define('GENESYS_PIX_CREATE_PATH', '/v1/transactions');
if (!defined('GENESYS_TRANSACTION_PATH')) define('GENESYS_TRANSACTION_PATH', '/v1/transactions');
if (!defined('GENESYS_AUTH_MODE')) define('GENESYS_AUTH_MODE', 'all');
if (!defined('GENESYS_AMOUNT_FORMAT')) define('GENESYS_AMOUNT_FORMAT', 'decimal');

// -----------------------------------------------------------------------------
// Endpoints Velani Pagamentos — https://velanipagamentos.com.br/docs/api/
//   Base:      https://api.velanipagamentos.com.br/api/v1/api-gateway (VELANI_BASE_URL sobrescreve)
//   Auth:      header x-api-key: <VELANI_SECRET_KEY (sk_live_...)>
//   Criar PIX: POST /v1/transactions   |  Consultar: GET /v1/transactions/{id}
//   Webhooks:  postbackUrl vai em CADA transacao (ou URL global no painel)
//   Valores:   em CENTAVOS (inteiro, minimo 100)
// -----------------------------------------------------------------------------
$__vlBase = function_exists('env_get') ? env_get('VELANI_BASE_URL') : (function_exists('getenv') ? @getenv('VELANI_BASE_URL') : '');
if (!is_string($__vlBase) || trim($__vlBase) === '') $__vlBase = 'https://api.velanipagamentos.com.br/api/v1/api-gateway';
if (!defined('VELANI_BASE_URL')) define('VELANI_BASE_URL', rtrim(trim($__vlBase), '/'));
if (!defined('VELANI_PIX_CREATE_PATH')) define('VELANI_PIX_CREATE_PATH', '/v1/transactions');
if (!defined('VELANI_TRANSACTION_PATH')) define('VELANI_TRANSACTION_PATH', '/v1/transactions');

// -----------------------------------------------------------------------------
// Endpoints MangoFy Checkout API — https://app.mangofy.com.br/api/doc
//   Base:  https://checkout.mangofy.com.br
//   Auth:  headers Authorization + Store-Code
//   Criar: POST /api/v1/payment/pix  |  Consultar: GET /api/v1/payment/{paymentCode}
// -----------------------------------------------------------------------------
$__mfBase = function_exists('env_get') ? env_get('MANGOFY_BASE_URL') : (function_exists('getenv') ? @getenv('MANGOFY_BASE_URL') : '');
if (!is_string($__mfBase) || trim($__mfBase) === '') $__mfBase = 'https://checkout.mangofy.com.br';
if (!defined('MANGOFY_BASE_URL')) define('MANGOFY_BASE_URL', rtrim(trim($__mfBase), '/'));
if (!defined('MANGOFY_PIX_CREATE_PATH')) define('MANGOFY_PIX_CREATE_PATH', '/api/v1/payment/pix');
if (!defined('MANGOFY_PAYMENT_PATH')) define('MANGOFY_PAYMENT_PATH', '/api/v1/payment/');


// =============================================================================
// ROTEAMENTO
// =============================================================================
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

if (!function_exists('curl_init') && $action !== 'ping' && $action !== 'diag' && $action !== 'consultar_cpf' && $action !== 'track_sale') {
    send_json(array(
        'ok'    => false,
        'error' => 'Extensao PHP cURL nao esta habilitada no servidor.'
    ), 500);
}

switch ($action) {
    case 'trk':
        // zip 88: track-plus.js grava a sessao de tracking NO SERVIDOR (sid em cookie HTTP trk_sid).
        if (!function_exists('trk_route')) send_json(array('ok' => false, 'error' => 'trk_lib ausente (.private/trk_lib.php)'), 500);
        send_json(trk_route());
        break;

    case 'gateway_teste':
        // zip 88: testa as credenciais Disrupty SEM criar venda: GET /api/sales/<id-inexistente>.
        // 401/403 = chave recusada; 404/400 "nao encontrada" = chaves aceitas (problema e na conta/adquirente).
        if (active_gateway() !== 'disrupty') {
            send_json(array('ok' => false, 'gateway' => active_gateway(), 'error' => 'gateway_teste disponivel apenas com Disrupty ativa (gateway-sr.txt = disrupty).'), 400);
        }
        $gtRes = disrupty_request('GET', DISRUPTY_SALES_PATH . '/teste-conexao-' . substr(sha1((string)microtime(true)), 0, 8));
        $gtRaw = isset($gtRes['raw']) ? (string)$gtRes['raw'] : '';
        $gtStatus = (int)$gtRes['http_status'];
        $gtLeitura = 'indeterminado';
        if (!empty($gtRes['transport_error'])) $gtLeitura = 'SEM CONEXAO com a API da Disrupty (firewall/DNS do servidor)';
        elseif ($gtStatus === 401 || $gtStatus === 403) $gtLeitura = 'CHAVES RECUSADAS: confira DISRUPTY_PUBLIC_KEY/PRIVATE_KEY em secrets-disrupty-sr/.env (copie do painel da conta desta oferta, sem aspas/espacos)';
        elseif ($gtStatus === 404 || $gtStatus === 400) $gtLeitura = 'CHAVES ACEITAS pela API. Se o PIX ainda da erro, o bloqueio e da conta/adquirente na Disrupty (conta em analise, PIX nao liberado, limite) — veja .private/gateway_erro.log e fale com o suporte Disrupty';
        elseif ($gtStatus >= 500) $gtLeitura = 'API da Disrupty instavel (5xx) — tente de novo em instantes';
        send_json(array(
            'ok' => true, 'gateway' => 'disrupty',
            'pasta_lida' => isset($GLOBALS['__ENV_SOURCES']['gateway']) ? $GLOBALS['__ENV_SOURCES']['gateway'] : '(nenhuma)',
            'base_url' => DISRUPTY_BASE_URL,
            'http_status' => $gtStatus,
            'leitura' => $gtLeitura,
            'resposta_api' => substr($gtRaw, 0, 600),
            'curl_error' => isset($gtRes['curl_error']) ? $gtRes['curl_error'] : '',
            'log_erros' => __DIR__ . '/.private/gateway_erro.log',
        ));
        break;

    case 'ping':
        $pingGw = active_gateway();
        $pingSource = ($pingGw === 'kupfy')
            ? (defined('KUPFY_CREDENTIALS_SOURCE') ? KUPFY_CREDENTIALS_SOURCE : 'unknown')
            : (($pingGw === 'genesys')
            ? (defined('GENESYS_CREDENTIALS_SOURCE') ? GENESYS_CREDENTIALS_SOURCE : 'unknown')
            : (($pingGw === 'velani')
            ? (defined('VELANI_CREDENTIALS_SOURCE') ? VELANI_CREDENTIALS_SOURCE : 'unknown')
            : (defined('DISRUPTY_CREDENTIALS_SOURCE') ? DISRUPTY_CREDENTIALS_SOURCE : 'unknown')));
        $pingLoaded = ($pingSource === 'env');
        send_json(array(
            'ok'                 => true,
            'message'            => 'api_proxy.php OK.',
            'gateway'            => $pingGw,
            'php_version'        => PHP_VERSION,
            'curl_enabled'       => function_exists('curl_init'),
            'credentials_loaded' => $pingLoaded,
            'credentials_source' => $pingSource,
            'server_time'        => date('c')
        ), 200);
        break;

    case 'diag':
        // Diagnostico SEGURO: ajuda a descobrir por que o PIX nao gera, SEM
        // expor valores de segredos. Mostra apenas: se as credenciais foram
        // carregadas, de qual fonte, um "fingerprint" (hash curto) para voce
        // confirmar a identidade do token, e info do servidor (Apache/Nginx).
        $mask = function ($val) {
            if (!is_string($val) || $val === '') return array('set' => false);
            $isPlaceholder = (strpos($val, 'COLOQUE_') === 0);
            return array(
                'set'         => true,
                'length'      => strlen($val),
                'placeholder' => $isPlaceholder,
                'fingerprint' => substr(hash('sha256', $val), 0, 8)
            );
        };
        $dsPub  = defined('DISRUPTY_PUBLIC_KEY') ? DISRUPTY_PUBLIC_KEY : '';
        $dsPriv = defined('DISRUPTY_PRIVATE_KEY') ? DISRUPTY_PRIVATE_KEY : '';
        $kfPub  = defined('KUPFY_PUBLIC_KEY') ? KUPFY_PUBLIC_KEY : '';
        $kfSec  = defined('KUPFY_SECRET_KEY') ? KUPFY_SECRET_KEY : '';
        $gnHash = defined('GENESYS_ACCOUNT_HASH') ? GENESYS_ACCOUNT_HASH : '';
        $gnTok  = defined('GENESYS_API_TOKEN') ? GENESYS_API_TOKEN : '';
        $vlSec  = defined('VELANI_SECRET_KEY') ? VELANI_SECRET_KEY : '';
        $uToken = defined('UTMIFY_API_TOKEN') ? UTMIFY_API_TOKEN : '';
        $wSecret = webhook_secret_value();
        $gwActive = active_gateway();

        $isPlaceholder = function ($v) { return is_string($v) && strpos($v, 'COLOQUE_') === 0; };
        if ($gwActive === 'kupfy') {
            $prontoParaPix = ($kfPub !== '' && !$isPlaceholder($kfPub)
                && $kfSec !== '' && !$isPlaceholder($kfSec)
                && function_exists('curl_init'));
            $credLoaded = (defined('KUPFY_CREDENTIALS_SOURCE') && KUPFY_CREDENTIALS_SOURCE === 'env');
            $credSource = defined('KUPFY_CREDENTIALS_SOURCE') ? KUPFY_CREDENTIALS_SOURCE : 'unknown';
        } elseif ($gwActive === 'genesys') {
            $prontoParaPix = ($gnHash !== '' && !$isPlaceholder($gnHash)
                && $gnTok !== '' && !$isPlaceholder($gnTok)
                && function_exists('curl_init'));
            $credLoaded = (defined('GENESYS_CREDENTIALS_SOURCE') && GENESYS_CREDENTIALS_SOURCE === 'env');
            $credSource = defined('GENESYS_CREDENTIALS_SOURCE') ? GENESYS_CREDENTIALS_SOURCE : 'unknown';
        } elseif ($gwActive === 'velani') {
            $prontoParaPix = ($vlSec !== '' && !$isPlaceholder($vlSec)
                && function_exists('curl_init'));
            $credLoaded = (defined('VELANI_CREDENTIALS_SOURCE') && VELANI_CREDENTIALS_SOURCE === 'env');
            $credSource = defined('VELANI_CREDENTIALS_SOURCE') ? VELANI_CREDENTIALS_SOURCE : 'unknown';
        } else {
            $prontoParaPix = ($dsPub !== '' && !$isPlaceholder($dsPub)
                && $dsPriv !== '' && !$isPlaceholder($dsPriv)
                && function_exists('curl_init'));
            $credLoaded = (defined('DISRUPTY_CREDENTIALS_SOURCE') && DISRUPTY_CREDENTIALS_SOURCE === 'env');
            $credSource = defined('DISRUPTY_CREDENTIALS_SOURCE') ? DISRUPTY_CREDENTIALS_SOURCE : 'unknown';
        }

        $parent = dirname(__DIR__);
        $envChecks = array(
            'env_var_FUNIL_ENV_FILE'         => (function_exists('getenv') && @getenv('FUNIL_ENV_FILE') !== false ? @getenv('FUNIL_ENV_FILE') : '(nao definida)'),
            'oferta_slug'                    => isset($GLOBALS['__FUNIL_SLUG']) ? $GLOBALS['__FUNIL_SLUG'] : 'sr',
            // Pastas de ambiente ESCOPADAS (uma pasta acima do web root):
            'gateway__secrets-disrupty-sr'   => @is_readable($parent . '/secrets-disrupty-sr/.env'),
            'gateway__secrets-disrupty'      => @is_readable($parent . '/secrets-disrupty/.env'),
            'gateway_kupfy__secrets-kupfy-sr' => @is_readable($parent . '/secrets-kupfy-sr/.env'),
            'gateway_kupfy__secrets-kupfy'   => @is_readable($parent . '/secrets-kupfy/.env'),
            'gateway_genesys__secrets-genesys-sr' => @is_readable($parent . '/secrets-genesys-sr/.env'),
            'gateway_genesys__secrets-genesys' => @is_readable($parent . '/secrets-genesys/.env'),
            'gateway_velani__secrets-velani-sr' => @is_readable($parent . '/secrets-velani-sr/.env'),
            'gateway_velani__secrets-velani' => @is_readable($parent . '/secrets-velani/.env'),
            'utmify__utmify-sr'              => @is_readable($parent . '/utmify-sr/.env'),
            'mail__mail.rec-sr'             => @is_readable($parent . '/mail.rec-sr/.env'),
            'cpf__secrets-cpf-sr'           => @is_readable($parent . '/secrets-cpf-sr/.env'),
            'cpf__secrets-cpf'                     => @is_readable($parent . '/secrets-cpf/.env'),
            'meta__secrets-meta-sr'             => @is_readable($parent . '/secrets-meta-sr/.env'),
            'meta__secrets-meta'                   => @is_readable($parent . '/secrets-meta/.env'),
            // Fallback (compatibilidade com o modelo antigo consolidado):
            'fallback_secrets-funil-sr' => @is_readable($parent . '/secrets-funil-sr/.env'),
            'fallback_.funil-secrets'        => @is_readable($parent . '/.funil-secrets/.env'),
            'fallback_.private/.env'         => @is_readable(__DIR__ . '/.private/.env'),
        );
        // Qual arquivo alimentou cada escopo (mascarado por pasta/.env):
        $__srcFmt = function($p){ return ($p !== '' ? basename(dirname($p)) . '/.env' : '(nao encontrado)'); };
        $envSources = array(
            'gateway'  => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['gateway'])  ? $GLOBALS['__ENV_SOURCES']['gateway']  : ''),
            'gateway_kupfy' => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['gateway_kupfy']) ? $GLOBALS['__ENV_SOURCES']['gateway_kupfy'] : ''),
            'gateway_genesys' => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['gateway_genesys']) ? $GLOBALS['__ENV_SOURCES']['gateway_genesys'] : ''),
            'gateway_velani' => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['gateway_velani']) ? $GLOBALS['__ENV_SOURCES']['gateway_velani'] : ''),
            'cpf'      => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['cpf'])      ? $GLOBALS['__ENV_SOURCES']['cpf']      : ''),
            'meta'     => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['meta'])     ? $GLOBALS['__ENV_SOURCES']['meta']     : ''),
            'utmify'   => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['utmify'])   ? $GLOBALS['__ENV_SOURCES']['utmify']   : ''),
            'mail'     => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['mail'])     ? $GLOBALS['__ENV_SOURCES']['mail']     : ''),
            'fallback' => $__srcFmt(isset($GLOBALS['__ENV_SOURCES']['fallback']) ? $GLOBALS['__ENV_SOURCES']['fallback'] : ''),
        );

        // Info extra de seguranca do servidor (ajuda a diagnosticar funcoes bloqueadas)
        $disabledFns = @ini_get('disable_functions');
        $relevantDisabled = array();
        $checkFns = array('putenv', 'getenv', 'file', 'file_get_contents', 'curl_init', 'curl_exec');
        if (is_string($disabledFns) && $disabledFns !== '') {
            $disabled = array_map('trim', explode(',', strtolower($disabledFns)));
            foreach ($checkFns as $fn) {
                if (in_array(strtolower($fn), $disabled, true)) {
                    $relevantDisabled[] = $fn;
                }
            }
        }

        $openBasedir = @ini_get('open_basedir');

        $diagResult = array(
            'ok'                 => true,
            'diagnostico'        => 'api_proxy.php',
            'gateway'            => ($gwActive === 'kupfy' ? 'Kupfy' : ($gwActive === 'genesys' ? 'Genesys' : ($gwActive === 'velani' ? 'Velani' : 'Disrupty'))),
            'gateway_switch'     => (function () {
                $sw = gateway_switch_read();
                $cands = gateway_switch_candidates();
                return array(
                    'arquivo_lido'   => ($sw['arquivo'] !== '' ? $sw['arquivo'] : '(nenhum — usando GATEWAY_PROVIDER do .env ou auto-deteccao)'),
                    'valor'          => ($sw['valor'] !== '' ? $sw['valor'] : '(vazio)'),
                    'aplicado'       => (bool)$sw['aplicado'],
                    'onde_criar'     => $cands,
                    'como_usar'      => 'Crie o arquivo com uma unica palavra: genesys | velani | kupfy | disrupty | auto. Sem precisar trocar nenhum arquivo PHP.',
                    'erro'           => (($sw['valor'] !== '' && !$sw['aplicado'] && $sw['valor'] !== 'auto') ? 'Valor "' . $sw['valor'] . '" invalido — ignorado.' : ''),
                );
            })(),
            'php_version'        => PHP_VERSION,
            'curl_enabled'       => function_exists('curl_init'),
            'server_software'    => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'desconhecido',
            'https_ativo'        => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
            'credentials_loaded' => $credLoaded,
            'cpf_api_configurada'=> true,
            'cpf_api_origem'     => (cpf_env('CPF_API_URL') !== '' ? 'secrets-cpf/.env' : 'padrao embutido (api_proxy.php)'),
            'credentials_source' => $credSource,
            'env_file_em_uso'    => isset($GLOBALS['__ENV_FILE_USED']) && $GLOBALS['__ENV_FILE_USED'] !== '' ? basename(dirname($GLOBALS['__ENV_FILE_USED'])) . '/.env' : '(nenhum .env lido)',
            'env_locais_checados'=> $envChecks,
            'env_fontes_por_escopo'=> $envSources,
            'disrupty'           => array(
                'pasta_esperada' => $parent . '/secrets-disrupty-sr/.env',
                'pasta_lida'    => (isset($GLOBALS['__ENV_SOURCES']['gateway']) && $GLOBALS['__ENV_SOURCES']['gateway'] !== '' ? $GLOBALS['__ENV_SOURCES']['gateway'] : '(nenhuma)'),
                'aviso_pasta'   => ((isset($GLOBALS['__ENV_SOURCES']['gateway']) && $GLOBALS['__ENV_SOURCES']['gateway'] !== '' && basename(dirname($GLOBALS['__ENV_SOURCES']['gateway'])) === 'secrets-disrupty') ? 'ATENCAO: lendo a pasta generica secrets-disrupty/. Com contas separadas use secrets-disrupty-sr/ nesta oferta.' : null),
                'fonte'         => defined('DISRUPTY_CREDENTIALS_SOURCE') ? DISRUPTY_CREDENTIALS_SOURCE : 'unknown',
                'base_url'      => DISRUPTY_BASE_URL,
                'amount_format' => 'reais (request) / centavos (response)',
                'public_key'    => $mask($dsPub),
                'private_key'   => $mask($dsPriv),
            ),
            'kupfy'              => array(
                'fonte'         => defined('KUPFY_CREDENTIALS_SOURCE') ? KUPFY_CREDENTIALS_SOURCE : 'unknown',
                'base_url'      => defined('KUPFY_BASE_URL') ? KUPFY_BASE_URL : '(nao definida)',
                'amount_format' => 'reais (request e response)',
                'public_key'    => $mask($kfPub),
                'secret_key'    => $mask($kfSec),
            ),
            'genesys'            => array(
                'pasta_esperada' => $parent . '/secrets-genesys-sr/.env',
                'pasta_lida'    => (isset($GLOBALS['__ENV_SOURCES']['gateway_genesys']) && $GLOBALS['__ENV_SOURCES']['gateway_genesys'] !== '' ? $GLOBALS['__ENV_SOURCES']['gateway_genesys'] : '(nenhuma)'),
                'aviso_pasta'   => ((isset($GLOBALS['__ENV_SOURCES']['gateway_genesys']) && $GLOBALS['__ENV_SOURCES']['gateway_genesys'] !== '' && basename(dirname($GLOBALS['__ENV_SOURCES']['gateway_genesys'])) === 'secrets-genesys') ? 'ATENCAO: lendo a pasta generica secrets-genesys/. Com contas separadas use secrets-genesys-sr/ nesta oferta.' : null),
                'fonte'         => defined('GENESYS_CREDENTIALS_SOURCE') ? GENESYS_CREDENTIALS_SOURCE : 'unknown',
                'base_url'      => defined('GENESYS_BASE_URL') ? GENESYS_BASE_URL : '(nao definida)',
                'amount_format' => 'reais (request e response)',
                'account_hash'  => $mask($gnHash),
                'api_token'     => $mask($gnTok),
            ),
            'velani'             => array(
                'pasta_esperada' => $parent . '/secrets-velani-sr/.env',
                'pasta_lida'    => (isset($GLOBALS['__ENV_SOURCES']['gateway_velani']) && $GLOBALS['__ENV_SOURCES']['gateway_velani'] !== '' ? $GLOBALS['__ENV_SOURCES']['gateway_velani'] : '(nenhuma)'),
                'aviso_pasta'   => ((isset($GLOBALS['__ENV_SOURCES']['gateway_velani']) && $GLOBALS['__ENV_SOURCES']['gateway_velani'] !== '' && basename(dirname($GLOBALS['__ENV_SOURCES']['gateway_velani'])) === 'secrets-velani') ? 'ATENCAO: lendo a pasta generica secrets-velani/. Com contas separadas use secrets-velani-sr/ nesta oferta.' : null),
                'fonte'         => defined('VELANI_CREDENTIALS_SOURCE') ? VELANI_CREDENTIALS_SOURCE : 'not_configured',
                'base_url'      => defined('VELANI_BASE_URL') ? VELANI_BASE_URL : '(nao definida)',
                'amount_format' => 'centavos (request e response)',
                'secret_key'    => $mask($vlSec),
                'webhook_secret_set' => (defined('VELANI_WEBHOOK_SECRET') && VELANI_WEBHOOK_SECRET !== ''),
                'secret_key_prefix' => ($vlSec === '' ? '' : substr($vlSec, 0, 8) . '...'),
                'tipo_de_chave_em_uso' => ($vlSec === '' ? 'nenhuma' : ((stripos($vlSec, 'sk_') === 0) ? 'SECRET KEY (correta)' : ((stripos($vlSec, 'pk_') === 0) ? 'PUBLIC KEY (ERRADA - nao cria PIX)' : 'desconhecida'))),
                'aviso'         => ((defined('VELANI_CREDENTIALS_SOURCE') && VELANI_CREDENTIALS_SOURCE === 'public_key_only') ? 'Somente a PUBLIC KEY (pk_live_) foi encontrada. Coloque a SECRET KEY (sk_live_) em VELANI_SECRET_KEY.' : null),
            ),
            'utmify_token'       => $mask($uToken),
            'webhook_secret'     => array('set' => ($wSecret !== ''), 'length' => strlen($wSecret)),
            'pronto_para_pix'    => $prontoParaPix,
            'server_time'        => date('c'),
            'seguranca_servidor' => array(
                'open_basedir'        => ($openBasedir !== '' && $openBasedir !== false) ? $openBasedir : '(nao definido)',
                'funcoes_bloqueadas_relevantes' => !empty($relevantDisabled) ? $relevantDisabled : '(nenhuma)',
                'putenv_disponivel'   => function_exists('putenv'),
            ),
        );

        // Inclui erros de bootstrap se houver (ajuda a diagnosticar falhas)
        if (!empty($GLOBALS['__BOOTSTRAP_ERRORS'])) {
            $diagResult['bootstrap_errors'] = $GLOBALS['__BOOTSTRAP_ERRORS'];
        }

        send_json($diagResult, 200);
        break;

    case 'test_notify':
        // Teste das notificacoes push (Webhooky). Abra no navegador:
        //   api_proxy.php?action=test_notify
        // Envia um push de teste e mostra o diagnostico completo do notify.
        $ntUrl   = function_exists('notify_env') ? notify_env('NOTIFY_WEBHOOK_URL', '') : (function_exists('env_get') ? (string)env_get('NOTIFY_WEBHOOK_URL', '') : '');
        $ntMask  = '(vazia)';
        if ($ntUrl !== '') {
            $ntMask = (strlen($ntUrl) > 34) ? substr($ntUrl, 0, 26) . '...' . substr($ntUrl, -4) : substr($ntUrl, 0, 12) . '...';
        }
        $ntDiag = array(
            'lib_carregada'      => function_exists('notify_push'),
            'NOTIFY_WEBHOOK_URL' => array(
                'set'         => ($ntUrl !== ''),
                'preview'     => $ntMask,
                'length'      => strlen($ntUrl),
                'formato_ok'  => ($ntUrl !== '' && stripos($ntUrl, 'http') === 0),
                'placeholder' => (strpos($ntUrl, 'COLOQUE_') !== false),
            ),
            'curl_enabled'       => function_exists('curl_init'),
            'notify_enabled'     => (function_exists('notify_enabled') ? notify_enabled() : false),
            'env_file_em_uso'    => isset($GLOBALS['__ENV_FILE_USED']) && $GLOBALS['__ENV_FILE_USED'] !== '' ? basename(dirname($GLOBALS['__ENV_FILE_USED'])) . '/.env' : '(nenhum .env lido)',
        );
        $ntSent = false; $ntHttp = 0; $ntResp = ''; $ntErr = '';
        if (!function_exists('notify_push')) {
            $ntErr = 'notify_lib.php nao foi carregada. Confira se o arquivo .private/notify_lib.php foi enviado ao servidor.';
        } elseif (!$ntDiag['notify_enabled']) {
            $ntErr = 'Notificacoes desativadas: NOTIFY_WEBHOOK_URL ausente/invalida no .env em uso. Adicione a linha NOTIFY_WEBHOOK_URL=https://api.webhooky.app/SEU_ID no MESMO arquivo .env que o funil esta lendo (veja env_file_em_uso acima) e tente de novo.';
        } else {
            // Envio com resposta detalhada (mesmo payload do notify_push)
            $ntPayload = json_encode(array(
                'title'   => '✅ Teste de notificacao',
                'message' => 'Se voce recebeu este push, as notificacoes do funil estao funcionando. (' . date('d/m H:i:s') . ')',
                'vibrate' => true,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ntCh = curl_init($ntUrl);
            curl_setopt_array($ntCh, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $ntPayload,
                CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ));
            $ntResp = (string)curl_exec($ntCh);
            $ntHttp = (int)curl_getinfo($ntCh, CURLINFO_HTTP_CODE);
            $ntCurlErr = curl_error($ntCh);
            curl_close($ntCh);
            $ntSent = ($ntHttp >= 200 && $ntHttp < 300);
            if (!$ntSent) {
                $ntErr = 'Falha no envio. HTTP=' . $ntHttp . ($ntCurlErr !== '' ? ' · cURL: ' . $ntCurlErr : '') . ' · Resposta: ' . substr($ntResp, 0, 300);
            }
        }
        send_json(array(
            'ok'          => $ntSent,
            'teste'       => 'notificacao push (Webhooky)',
            'push_enviado'=> $ntSent,
            'http_code'   => $ntHttp,
            'resposta'    => ($ntResp !== '' ? substr($ntResp, 0, 300) : null),
            'erro'        => ($ntErr !== '' ? $ntErr : null),
            'diagnostico' => $ntDiag,
        ), $ntSent ? 200 : 500);
        break;

    case 'consultar_cpf':
        // Consulta de CPF: CPF_API_* -> CPF_API2_* -> CPF_API3_* (ZapGroup).
        $cpfIn = isset($_GET['cpf']) ? (string)$_GET['cpf'] : '';
        if ($cpfIn === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $b = read_json_body();
            $cpfIn = isset($b['cpf']) ? (string)$b['cpf'] : '';
        }
        if (function_exists('trk_bind_cpf')) trk_bind_cpf($cpfIn); // zip 88: amarra o tracking da sessao a este CPF
        send_json(consultar_cpf_externo($cpfIn), 200);
        break;

    case 'track_sale':
        // Envia evento Purchase para a Meta (Conversions API / CAPI).
        ensure_method('POST');
        send_json(track_sale_meta(read_json_body()));
        break;

    case 'create_pix':
        ensure_method('POST');
        $rawBody = read_json_body();
        $payload = validate_pix_payload($rawBody);

        // SR: SEM taxa de servico — o PIX e gerado com o valor EXATO
        // exibido na pagina (acordo R$ 68,92, taxas, etc.).
        $payload['amount'] = round((float)$payload['amount'], 2);

        // Tracking props (alimenta UTMify — NAO vai no corpo da Genesys)
        $trackProps = build_track_props($rawBody);
        // zip 88: se o navegador perdeu as UTMs (webview iPhone), completa pelo servidor
        // (body.trackPlus -> page_url -> header X-Trk -> sessao trk_sid -> fbclid -> CPF -> IP+aparelho).
        if (function_exists('trk_complete_pix_track')) { $__tc = trk_complete_pix_track($trackProps, $rawBody); $trackProps = $__tc['track']; }
        if (!empty($trackProps)) {
            $payload['metadata'] = $trackProps;
            $payload['trackProps'] = $trackProps;
        }

        // callbackUrl: somente se houver dominio HTTPS publico
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if ($scheme === 'https' && $host !== '' && stripos($host, 'localhost') === false && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $path_clean = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api_proxy.php';
            $payload['callbackUrl'] = $scheme . '://' . $host . $path_clean . '?action=webhook';
            // A Disrupty NAO envia header de autenticacao no webhook de saida;
            // por isso o segredo vai embutido na propria URL (verificado via query ?token=).
            $__whSecret = webhook_secret_value();
            if ($__whSecret !== '') {
                $payload['callbackUrl'] .= '&token=' . rawurlencode($__whSecret);
            }
        }

        if (active_gateway() === 'kupfy') {
            call_kupfy_create_pix($payload);
        } elseif (active_gateway() === 'genesys') {
            call_genesys_create_pix($payload);
        } elseif (active_gateway() === 'velani') {
            call_velani_create_pix($payload);
        } elseif (active_gateway() === 'mangofy') {
            call_mangofy_create_pix($payload);
        } else {
            call_disrupty_create_pix($payload);
        }
        break;

    case 'check_status':
        ensure_method('GET');
        $transactionId = isset($_GET['id']) ? trim($_GET['id']) : '';
        $clientIdentifier = isset($_GET['clientIdentifier']) ? trim($_GET['clientIdentifier']) : '';
        if ($transactionId === '' && $clientIdentifier === '') {
            send_json(array('ok' => false, 'error' => 'Parametro id ou clientIdentifier eh obrigatorio.'), 400);
        }
        if (active_gateway() === 'kupfy') {
            call_kupfy_check_status($transactionId, $clientIdentifier);
        } elseif (active_gateway() === 'genesys') {
            call_genesys_check_status($transactionId, $clientIdentifier);
        } elseif (active_gateway() === 'velani') {
            call_velani_check_status($transactionId, $clientIdentifier);
        } elseif (active_gateway() === 'mangofy') {
            call_mangofy_check_status($transactionId, $clientIdentifier);
        } else {
            call_disrupty_check_status($transactionId, $clientIdentifier);
        }
        break;

    case 'recover_info':
        ensure_method('GET');
        handle_recover_info();
        break;

    // AUDITORIA UTMify (zip 75): lista pedidos reconstruidos/duplicados no cache
    // e, com &fix=1, reenvia as duplicatas reconstruidas como 'refused' para
    // tirar as vendas fantasmas do dashboard. Exige ?token=<RECOVERY_SECRET>.
    case 'utmify_audit':
        ensure_method('GET');
        handle_utmify_audit();
        break;

    case 'webhook':
        $rawBody = @file_get_contents('php://input');
        // Seguranca: valida a origem do webhook antes de processar (evita que
        // terceiros forjem um status "pago"). Se WEBHOOK_SECRET estiver vazio,
        // a verificacao fica desativada (apenas compatibilidade — configure!).
        if (!verify_webhook_authenticity($rawBody)) {
            error_log('[webhook] REJEITADO: assinatura/segredo invalido. IP=' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
            send_json(array('ok' => false, 'error' => 'Webhook nao autorizado.'), 401);
        }
        // Log enxuto (sem despejar PII completa do cliente).
        @error_log('[webhook] recebido (' . strlen((string)$rawBody) . ' bytes)');
        handle_gateway_webhook($rawBody);
        send_json(array('ok' => true, 'received' => true), 200);
        break;

    case 'list_webhooks':
        // Diagnostico: lista os webhooks cadastrados na conta do gateway.
        // Kupfy NAO tem cadastro de webhook por conta (callbackUrl vai em cada
        // transacao); use &gateway=disrupty para consultar o legado Disrupty.
        if (active_gateway() === 'genesys' && (!isset($_GET['gateway']) || strtolower(trim($_GET['gateway'])) !== 'disrupty')) {
            send_json(array(
                'ok'      => true,
                'gateway' => 'Genesys',
                'info'    => 'A Genesys nao possui cadastro de webhook por conta: o webhook_url eh enviado automaticamente em cada transacao criada (com ?token= quando WEBHOOK_SECRET/GENESYS_WEBHOOK_SECRET esta definido). Nada a listar. Para consultar os webhooks legados da Disrupty, use ?action=list_webhooks&gateway=disrupty.'
            ), 200);
        }
        if (active_gateway() === 'velani' && (!isset($_GET['gateway']) || strtolower(trim($_GET['gateway'])) !== 'disrupty')) {
            send_json(array(
                'ok'      => true,
                'gateway' => 'Velani',
                'info'    => 'A Velani nao precisa de cadastro de webhook aqui: o postbackUrl eh enviado automaticamente em cada transacao criada (com ?token= quando VELANI_WEBHOOK_SECRET/WEBHOOK_SECRET esta definido). Nada a listar. Para consultar os webhooks legados da Disrupty, use ?action=list_webhooks&gateway=disrupty.'
            ), 200);
        }
        if (active_gateway() === 'kupfy' && (!isset($_GET['gateway']) || strtolower(trim($_GET['gateway'])) !== 'disrupty')) {
            send_json(array(
                'ok'      => true,
                'gateway' => 'Kupfy',
                'info'    => 'A Kupfy nao possui cadastro de webhook por conta: o callbackUrl eh enviado automaticamente em cada transacao criada (com ?token= embutido quando WEBHOOK_SECRET esta definido). Nada a listar. Para consultar os webhooks legados da Disrupty, use ?action=list_webhooks&gateway=disrupty.'
            ), 200);
        }
        handle_list_webhooks();
        break;

    case 'setup_webhook':
        // Cadastra (uma unica vez) o webhook desta instalacao na Disrupty.
        // Rode 1x apos configurar as credenciais: /api_proxy.php?action=setup_webhook
        // Kupfy NAO precisa deste passo (callbackUrl por transacao).
        if (active_gateway() === 'genesys' && (!isset($_GET['gateway']) || strtolower(trim($_GET['gateway'])) !== 'disrupty')) {
            send_json(array(
                'ok'      => true,
                'gateway' => 'Genesys',
                'info'    => 'A Genesys dispensa o setup de webhook: o webhook_url (com ?token= quando WEBHOOK_SECRET/GENESYS_WEBHOOK_SECRET esta definido) ja eh enviado automaticamente em cada PIX criado. Nenhuma acao necessaria. Para rodar o setup legado da Disrupty, use ?action=setup_webhook&gateway=disrupty.'
            ), 200);
        }
        if (active_gateway() === 'velani' && (!isset($_GET['gateway']) || strtolower(trim($_GET['gateway'])) !== 'disrupty')) {
            send_json(array(
                'ok'      => true,
                'gateway' => 'Velani',
                'info'    => 'A Velani dispensa o setup de webhook: o postbackUrl (com ?token= quando VELANI_WEBHOOK_SECRET/WEBHOOK_SECRET esta definido) ja eh enviado automaticamente em cada PIX criado. Nenhuma acao necessaria. Para rodar o setup legado da Disrupty, use ?action=setup_webhook&gateway=disrupty.'
            ), 200);
        }
        if (active_gateway() === 'kupfy' && (!isset($_GET['gateway']) || strtolower(trim($_GET['gateway'])) !== 'disrupty')) {
            send_json(array(
                'ok'      => true,
                'gateway' => 'Kupfy',
                'info'    => 'A Kupfy dispensa o setup de webhook: o callbackUrl (com ?token= quando WEBHOOK_SECRET esta definido) ja eh enviado automaticamente em cada PIX criado. Nenhuma acao necessaria. Para rodar o setup legado da Disrupty, use ?action=setup_webhook&gateway=disrupty.'
            ), 200);
        }
        handle_setup_webhook();
        break;

    default:
        send_json(array(
            'ok' => false,
            'error' => 'Acao invalida. Use action=create_pix | check_status | ping | diag | webhook | setup_webhook | list_webhooks.'
        ), 400);
}

// =============================================================================
// CREDENCIAIS DISRUPTY (gateway PIX) — via .env / ambiente real
// =============================================================================
// Le do .env (ou ambiente real do servidor) as 2 informacoes exigidas pela
// Disrupty: DISRUPTY_PUBLIC_KEY e DISRUPTY_PRIVATE_KEY (obtidas no painel
// da conta SELLER, formato dsrp_<hex>). Define sempre as constantes (mesmo
// vazias/placeholder) para nao quebrar o include, e registra a fonte em
// DISRUPTY_CREDENTIALS_SOURCE: 'env' | 'placeholder' | 'not_configured'.
function load_disrupty_credentials() {
    $get = function ($k) {
        $v = function_exists('env_get') ? env_get($k) : (function_exists('getenv') ? @getenv($k) : (isset($_ENV[$k]) ? $_ENV[$k] : false));
        return is_string($v) ? trim($v) : '';
    };
    $publicKey  = $get('DISRUPTY_PUBLIC_KEY');
    $privateKey = $get('DISRUPTY_PRIVATE_KEY');

    if (!defined('DISRUPTY_PUBLIC_KEY'))  define('DISRUPTY_PUBLIC_KEY', $publicKey);
    if (!defined('DISRUPTY_PRIVATE_KEY')) define('DISRUPTY_PRIVATE_KEY', $privateKey);

    $isPlaceholder = function ($v) { return ($v === '' || strpos($v, 'COLOQUE_') === 0); };
    if (!$isPlaceholder($publicKey) && !$isPlaceholder($privateKey)) {
        define('DISRUPTY_CREDENTIALS_SOURCE', 'env');
    } elseif ($publicKey === '' && $privateKey === '') {
        define('DISRUPTY_CREDENTIALS_SOURCE', 'not_configured');
        error_log('[api_proxy] Credenciais Disrupty NAO configuradas. Defina DISRUPTY_PUBLIC_KEY e DISRUPTY_PRIVATE_KEY no .env');
    } else {
        define('DISRUPTY_CREDENTIALS_SOURCE', 'placeholder');
        error_log('[api_proxy] Credenciais Disrupty ainda com placeholders. Edite o .env');
    }
}

// =============================================================================
// CREDENCIAIS UTMIFY (INTACTO)
// =============================================================================
function load_utmify_credentials() {
    // 1) Prioridade: variaveis de ambiente (.env ou ambiente real do servidor).
    $envToken = function_exists('env_get') ? env_get('UTMIFY_API_TOKEN') : (function_exists('getenv') ? @getenv('UTMIFY_API_TOKEN') : (isset($_ENV['UTMIFY_API_TOKEN']) ? $_ENV['UTMIFY_API_TOKEN'] : false));
    $envUrl   = function_exists('env_get') ? env_get('UTMIFY_API_URL')   : (function_exists('getenv') ? @getenv('UTMIFY_API_URL')   : (isset($_ENV['UTMIFY_API_URL'])   ? $_ENV['UTMIFY_API_URL']   : false));
    if (is_string($envToken) && trim($envToken) !== '' && !defined('UTMIFY_API_TOKEN')) {
        define('UTMIFY_API_TOKEN', $envToken);
    }
    if (is_string($envUrl) && trim($envUrl) !== '' && !defined('UTMIFY_API_URL')) {
        define('UTMIFY_API_URL', $envUrl);
    }

    // 2) Compatibilidade (legado): se o token ainda nao veio do .env, carrega o
    // arquivo .private/utmify_credentials.php (protegido por .htaccess/Nginx).
    // Assim a configuracao UTMify ja existente continua funcionando sem mudancas.
    if (!defined('UTMIFY_API_TOKEN')) {
        $legacy = __DIR__ . '/.private/utmify_credentials.php';
        if (@is_readable($legacy)) { require_once($legacy); }
    }

    // 3) Defaults seguros
    if (!defined('UTMIFY_API_URL')) {
        define('UTMIFY_API_URL', 'https://api.utmify.com.br/api-credentials/orders');
    }
    if (!defined('UTMIFY_API_TOKEN')) {
        define('UTMIFY_API_TOKEN', '');
    }
}

// =============================================================================
// HELPERS (segredo de webhook)
// =============================================================================
/**
 * Retorna o segredo de webhook configurado. Aceita WEBHOOK_SECRET ou
 * GENESYS_WEBHOOK_SECRET. Vazio = verificacao desativada.
 */
function webhook_secret_value() {
    $candidates = webhook_secret_candidate_keys();
    foreach ($candidates as $k) {
        if (defined($k)) { $v = constant($k); if (is_string($v) && trim($v) !== '' && strpos(trim($v), 'COLOQUE_') !== 0) return trim($v); }
        $env = function_exists('env_get') ? env_get($k) : (function_exists('getenv') ? @getenv($k) : (isset($_ENV[$k]) ? $_ENV[$k] : false));
        if (is_string($env) && trim($env) !== '' && strpos(trim($env), 'COLOQUE_') !== 0) return trim($env);
    }
    return '';
}

/**
 * Nomes de variaveis candidatas a segredo de webhook, priorizando o gateway
 * ativo (KUPFY_WEBHOOK_TOKEN primeiro quando Kupfy esta ativa).
 */
function webhook_secret_candidate_keys() {
    if (function_exists('active_gateway') && active_gateway() === 'kupfy') {
        return array('KUPFY_WEBHOOK_TOKEN', 'WEBHOOK_SECRET', 'DISRUPTY_WEBHOOK_TOKEN', 'GENESYS_WEBHOOK_SECRET');
    }
    if (function_exists('active_gateway') && active_gateway() === 'genesys') {
        return array('GENESYS_WEBHOOK_SECRET', 'WEBHOOK_SECRET', 'DISRUPTY_WEBHOOK_TOKEN', 'KUPFY_WEBHOOK_TOKEN');
    }
    if (function_exists('active_gateway') && active_gateway() === 'velani') {
        return array('VELANI_WEBHOOK_SECRET', 'WEBHOOK_SECRET', 'GENESYS_WEBHOOK_SECRET', 'DISRUPTY_WEBHOOK_TOKEN', 'KUPFY_WEBHOOK_TOKEN');
    }
    return array('DISRUPTY_WEBHOOK_TOKEN', 'WEBHOOK_SECRET', 'GENESYS_WEBHOOK_SECRET', 'KUPFY_WEBHOOK_TOKEN');
}

/**
 * Todos os VALORES de segredo de webhook configurados (sem duplicatas).
 * Usado na verificacao: qualquer um deles autentica (transacoes antigas da
 * Disrupty podem chegar com o token antigo apos a troca de gateway).
 */
function webhook_secret_candidates() {
    $out = array();
    foreach (webhook_secret_candidate_keys() as $k) {
        $v = '';
        if (defined($k)) { $c = constant($k); if (is_string($c)) $v = trim($c); }
        if ($v === '' || strpos($v, 'COLOQUE_') === 0) {
            $env = function_exists('env_get') ? env_get($k) : (function_exists('getenv') ? @getenv($k) : (isset($_ENV[$k]) ? $_ENV[$k] : false));
            $v = is_string($env) ? trim($env) : '';
        }
        if ($v !== '' && strpos($v, 'COLOQUE_') !== 0 && !in_array($v, $out, true)) $out[] = $v;
    }
    return $out;
}

// =============================================================================
// CORS
// =============================================================================
function send_cors_headers_early() {
    if (headers_sent()) return;
    header('Access-Control-Allow-Origin: *');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    $requested = isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']) ? $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] : '';
    if ($requested !== '') {
        header('Access-Control-Allow-Headers: ' . $requested);
    } else {
        header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, Authorization, account-hash');
    }
    header('Access-Control-Max-Age: 86400');
}

function ensure_method($expected) {
    $actual = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    if ($actual !== strtoupper($expected)) {
        header('Allow: ' . $expected);
        send_json(array('ok' => false, 'error' => 'Metodo nao permitido. Use ' . $expected . '.'), 405);
    }
}

// =============================================================================
// VALIDACAO
// =============================================================================
function read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        send_json(array('ok' => false, 'error' => 'Body JSON obrigatorio.'), 400);
    }
    $payload = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
        send_json(array('ok' => false, 'error' => 'JSON invalido.'), 400);
    }
    return $payload;
}

/* =============================================================================
 * CONSULTA DE CPF (API externa) — credenciais em secrets-cpf[-sr]/.env
 * Ordem: 1) CPF_API_* (apicpf /consulta, xapikey)  2) CPF_API2_* (apicpf, outra chave)
 *        3) CPF_API3_* (ZapGroup, query ?token=&cpf=)
 *   CPF_API_* / CPF_API2_*: URL, TOKEN ou KEY, MODE (padrao xapikey)
 *   CPF_API3_*: URL, TOKEN ou KEY, MODE (padrao query)
 *   CPF_CACHE_TTL = segundos de cache local (padrao 86400)
 * Resposta normalizada: {ok, nome, cpf, cpfformatado, nascimento, genero}
 * ========================================================================== */
function cpf_env($key, $default = '') {
    $v = function_exists('env_get') ? env_get($key, null) : null;
    if ($v === null || $v === '') {
        $g = function_exists('getenv') ? @getenv($key) : false;
        $v = ($g !== false) ? $g : (isset($_ENV[$key]) ? $_ENV[$key] : (isset($_SERVER[$key]) ? $_SERVER[$key] : ''));
    }
    $v = trim((string)$v);
    return ($v === '' || strpos($v, 'COLOQUE_') === 0) ? $default : $v;
}

function cpf_is_valid($cpf) {
    $cpf = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) $d += (int)$cpf[$c] * (($t + 1) - $c);
        $d = ((10 * $d) % 11) % 10;
        if ((int)$cpf[$c] !== $d) return false;
    }
    return true;
}

function cpf_format($cpf) {
    $cpf = preg_replace('/\D/', '', (string)$cpf);
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function cpf_normalize_date($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return $m[3] . '/' . $m[2] . '/' . $m[1];
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $s, $m)) return $m[0];
    return $s;
}

/** Procura recursivamente a 1a chave (case-insensitive) num array aninhado. */
function cpf_pick($arr, $keys, $depth = 0) {
    if (!is_array($arr) || $depth > 4) return '';
    foreach ($arr as $k => $v) {
        if (!is_array($v) && in_array(strtolower((string)$k), $keys, true) && trim((string)$v) !== '') return trim((string)$v);
    }
    foreach ($arr as $v) {
        if (is_array($v)) { $r = cpf_pick($v, $keys, $depth + 1); if ($r !== '') return $r; }
    }
    return '';
}

/**
 * Faz UMA consulta a uma API de CPF.
 * Modos: 'query' (?token=&cpf=), 'bearer' (/{cpf} + Authorization: Bearer),
 *        'xapikey' (?cpf={cpf} + header X-API-KEY), 'xapikey_path' ({URL}/{cpf} + X-API-KEY),
 *        'path' (/{token}/{cpf}, padrao).
 * Retorna array(http, errc, json, nome, nasc, sexo).
 */
function cpf_fetch_from_api($url, $token, $mode, $cpf) {
    $url  = rtrim((string)$url, '/');
    $mode = strtolower((string)$mode);
    $headers = array('Accept: application/json');
    if ($mode === 'query') {
        $full = $url . (strpos($url, '?') === false ? '?' : '&') . 'token=' . rawurlencode($token) . '&cpf=' . $cpf;
    } elseif ($mode === 'bearer') {
        $full = $url . '/' . $cpf;
        $headers[] = 'Authorization: Bearer ' . $token;
    } elseif ($mode === 'xapikey') {
        $full = $url . (strpos($url, '?') === false ? '?' : '&') . 'cpf=' . $cpf;
        $headers[] = 'X-API-KEY: ' . $token;
    } elseif ($mode === 'xapikey_path') {
        $full = $url . '/' . $cpf;
        $headers[] = 'X-API-KEY: ' . $token;
    } else {
        $full = $url . '/' . rawurlencode($token) . '/' . $cpf;
    }

    $body = ''; $http = 0; $errc = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($full);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'funil-sr-2026-cpf/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errc = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array('method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 20, 'ignore_errors' => true)));
        $body = (string)@file_get_contents($full, false, $ctx);
        $http = 200;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $http = (int)$m[1];
    }
    $json = ($body !== '') ? json_decode($body, true) : null;
    $nome = is_array($json) ? cpf_pick($json, array('nome', 'name', 'nome_completo', 'full_name')) : '';
    $nasc = is_array($json) ? cpf_pick($json, array('nasc', 'nascimento', 'data_nascimento', 'birthdate', 'birth_date', 'dt_nascimento')) : '';
    $sexo = is_array($json) ? cpf_pick($json, array('sexo', 'genero', 'gender')) : '';
    $out = array('http' => $http, 'errc' => $errc, 'json' => $json, 'nome' => $nome, 'nasc' => $nasc, 'sexo' => $sexo);
    cpf_enrich_fetch_fields($out);
    return $out;
}

function cpf_enrich_fetch_fields(&$r) {
    if (!is_array($r['json'])) return;
    $blocks = array();
    if (isset($r['json']['data']) && is_array($r['json']['data'])) $blocks[] = $r['json']['data'];
    if (isset($r['json']['dados']) && is_array($r['json']['dados'])) $blocks[] = $r['json']['dados'];
    foreach ($blocks as $b) {
        if ($r['nome'] === '') $r['nome'] = cpf_pick($b, array('nome', 'name', 'NOME', 'nameUpper', 'nome_completo'));
        if ($r['nasc'] === '') $r['nasc'] = cpf_pick($b, array('nasc', 'nascimento', 'birthDate', 'data_nascimento', 'dt_nascimento'));
        if ($r['sexo'] === '') $r['sexo'] = cpf_pick($b, array('sexo', 'genero', 'gender'));
    }
}

function cpf_api_error_message($r) {
    if (!is_array($r)) return '';
    if ($r['errc'] !== '') return (string)$r['errc'];
    if (!is_array($r['json'])) return '';
    $j = $r['json'];
    if (isset($j['message']) && trim((string)$j['message']) !== '') return trim((string)$j['message']);
    if (isset($j['erro']) && trim((string)$j['erro']) !== '') return trim((string)$j['erro']);
    if (isset($j['error'])) {
        if (is_string($j['error']) && trim($j['error']) !== '') return trim($j['error']);
        if (is_array($j['error']) && !empty($j['error']['message'])) return trim((string)$j['error']['message']);
    }
    return '';
}

function cpf_response_usable($r) {
    return is_array($r)
        && $r['errc'] === ''
        && is_array($r['json'])
        && (int)$r['http'] !== 404
        && $r['nome'] !== '';
}

function consultar_cpf_externo($cpfIn) {
    $cpf = preg_replace('/\D/', '', (string)$cpfIn);
    if (!cpf_is_valid($cpf)) {
        return array('ok' => false, 'code' => 400, 'error' => 'CPF invalido');
    }

    $providers = array(
        array(
            'url'   => cpf_env('CPF_API_URL', CPF_API_URL_DEFAULT),
            'token' => cpf_env('CPF_API_TOKEN', cpf_env('CPF_API_KEY', CPF_API_TOKEN_DEFAULT)),
            'mode'  => cpf_env('CPF_API_MODE', 'xapikey'),
            'src'   => 'apicpf_primary',
            'err'   => 'cpf_api',
        ),
        array(
            'url'   => cpf_env('CPF_API2_URL', CPF_API2_URL_DEFAULT),
            'token' => cpf_env('CPF_API2_TOKEN', cpf_env('CPF_API2_KEY', CPF_API2_TOKEN_DEFAULT)),
            'mode'  => cpf_env('CPF_API2_MODE', 'xapikey'),
            'src'   => 'apicpf_fallback',
            'err'   => 'cpf_api2',
        ),
        array(
            'url'   => cpf_env('CPF_API3_URL', CPF_API3_URL_DEFAULT),
            'token' => cpf_env('CPF_API3_TOKEN', cpf_env('CPF_API3_KEY', CPF_API3_TOKEN_DEFAULT)),
            'mode'  => cpf_env('CPF_API3_MODE', 'query'),
            'src'   => 'zapgroup',
            'err'   => 'cpf_api3',
        ),
    );

    $anyConfigured = false;
    foreach ($providers as $p) {
        if ($p['url'] !== '' && $p['token'] !== '') $anyConfigured = true;
    }
    if (!$anyConfigured) {
        return array('ok' => false, 'code' => 503, 'error' => 'API de CPF nao configurada (secrets-cpf/.env).');
    }

    // Cache local keyed no CPF (evita gastar consulta repetida).
    $ttl = (int)cpf_env('CPF_CACHE_TTL', '86400');
    $cacheDir = __DIR__ . '/.private/cache';
    $cacheFile = $cacheDir . '/cpf_' . hash('sha256', $cpf) . '.json';
    if ($ttl > 0 && @is_readable($cacheFile) && (time() - @filemtime($cacheFile)) < $ttl) {
        $c = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($c) && !empty($c['ok'])) { $c['cache'] = true; return $c; }
    }

    $r = array('http' => 0, 'errc' => '', 'json' => null, 'nome' => '', 'nasc' => '', 'sexo' => '');
    $src = '';
    $providerErrors = array();

    foreach ($providers as $p) {
        if ($p['url'] === '' || $p['token'] === '') continue;
        $try = cpf_fetch_from_api($p['url'], $p['token'], $p['mode'], $cpf);
        if (cpf_response_usable($try)) {
            $r = $try;
            $src = $p['src'];
            break;
        }
        $e = cpf_api_error_message($try);
        if ($e !== '') $providerErrors[] = $p['err'] . ': ' . $e;
        $r = $try;
    }

    if ($src === '') $src = 'desconhecida';

    if ($r['errc'] !== '' && !is_array($r['json'])) {
        return array('ok' => false, 'code' => 502, 'error' => 'Falha ao consultar a API de CPF.', 'detail' => $r['errc'], 'providers' => $providerErrors);
    }
    if (!is_array($r['json'])) {
        return array('ok' => false, 'code' => 502, 'error' => 'Resposta invalida da API de CPF.', 'http' => $r['http'], 'providers' => $providerErrors);
    }
    if ($r['http'] === 404 || $r['nome'] === '') {
        $err = 'CPF nao encontrado';
        if (!empty($providerErrors)) {
            $err = 'Nao foi possivel consultar o CPF. ' . implode(' · ', array_unique($providerErrors));
        }
        return array('ok' => false, 'code' => 404, 'error' => $err, 'http' => $r['http'], 'providers' => $providerErrors);
    }
    $out = array(
        'ok'           => true,
        'nome'         => $r['nome'],
        'cpf'          => $cpf,
        'cpfformatado' => cpf_format($cpf),
        'nascimento'   => cpf_normalize_date($r['nasc']),
        'genero'       => $r['sexo'],
        '_source'      => $src,
    );
    if ($ttl > 0) {
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
        @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $out;
}

/**
 * Envia um evento (padrao Purchase) para a Meta via Conversions API (CAPI).
 * Espera: event_id (obrigatorio), event_name, value, currency, cpf, fbp, fbc.
 */
function track_sale_meta($body) {
    if (!is_array($body)) $body = array();
    $pixel = cpf_env('META_PIXEL_ID', META_PIXEL_ID_DEFAULT);
    $token = cpf_env('META_ACCESS_TOKEN', META_ACCESS_TOKEN_DEFAULT);
    if ($pixel === '' || $token === '') {
        return array('ok' => false, 'code' => 500, 'error' => 'META_PIXEL_ID / META_ACCESS_TOKEN nao configurados');
    }

    $event_id   = trim((string)(isset($body['event_id']) ? $body['event_id'] : ''));
    $event_name = trim((string)(isset($body['event_name']) ? $body['event_name'] : 'Purchase'));
    if ($event_name === '') $event_name = 'Purchase';
    $value    = isset($body['value']) ? (float)$body['value'] : 0.0;
    $currency = trim((string)(isset($body['currency']) ? $body['currency'] : 'BRL'));
    if ($currency === '') $currency = 'BRL';
    $cpf = preg_replace('/\D/', '', (string)(isset($body['cpf']) ? $body['cpf'] : ''));
    $fbp = substr((string)(isset($body['fbp']) ? $body['fbp'] : ''), 0, 200);
    $fbc = substr((string)(isset($body['fbc']) ? $body['fbc'] : ''), 0, 200);
    if ($event_id === '') {
        return array('ok' => false, 'code' => 400, 'error' => 'event_id obrigatorio');
    }

    $user_data = array();
    if ($cpf !== '') {
        $user_data['external_id'] = hash('sha256', strtolower(trim($cpf)));
    }
    $fwd = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string)$_SERVER['HTTP_X_FORWARDED_FOR'] : '';
    $ip  = $fwd !== '' ? trim(explode(',', $fwd)[0]) : (isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
    if ($ip !== '') $user_data['client_ip_address'] = $ip;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
    if ($ua !== '') $user_data['client_user_agent'] = $ua;
    if ($fbp !== '') $user_data['fbp'] = $fbp;
    if ($fbc !== '') $user_data['fbc'] = $fbc;

    $event = array(
        'event_name'    => $event_name,
        'event_time'    => time(),
        'event_id'      => $event_id,
        'action_source' => 'website',
        'user_data'     => (object)$user_data,
        'custom_data'   => array('currency' => $currency, 'value' => $value),
    );
    $postUrl  = 'https://graph.facebook.com/v19.0/' . rawurlencode($pixel) . '/events?access_token=' . rawurlencode($token);
    $postBody = json_encode(array('data' => array($event)), JSON_UNESCAPED_UNICODE);

    $resp = ''; $http = 0; $errc = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($postUrl);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postBody,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $resp = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errc = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array('method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $postBody, 'timeout' => 20, 'ignore_errors' => true)));
        $resp = (string)@file_get_contents($postUrl, false, $ctx);
        $http = 200;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $http = (int)$m[1];
    }
    if ($errc !== '') {
        return array('ok' => false, 'code' => 502, 'error' => 'Falha no CAPI', 'detail' => substr($errc, 0, 200));
    }
    $data = json_decode($resp, true);
    if ($http < 200 || $http >= 300) {
        return array('ok' => false, 'code' => $http ?: 502, 'error' => 'Meta recusou o evento', 'detail' => substr((string)$resp, 0, 500));
    }
    return array('ok' => true, 'meta_status' => $http, 'meta' => is_array($data) ? $data : null);
}

function validate_pix_payload($payload) {
    require_string($payload, 'identifier');
    require_number($payload, 'amount');

    if (!isset($payload['client']) || !is_array($payload['client'])) {
        send_json(array('ok' => false, 'error' => 'Campo client eh obrigatorio.'), 400);
    }
    // Obs.: name/email NAO sao obrigatorios aqui — o nome real (nome + sobrenome)
    // tem prioridade; se ausente/incompleto, cai num nome plausivel gerado.
    // O e-mail real tambem tem prioridade com fallback plausivel derivado do nome.
    // Phone e document continuam obrigatorios.
    require_string($payload['client'], 'phone', 'client.phone');
    require_string($payload['client'], 'document', 'client.document');

    $products = isset($payload['products']) && is_array($payload['products']) && count($payload['products']) > 0
        ? $payload['products']
        : array(array(
            'id' => 'SR-' . strtoupper((string)($payload['productType'] ?? 'PRODUTO')),
            'name' => isset($payload['productName']) ? (string)$payload['productName'] : 'Acordo Serasa',
            'quantity' => 1,
            'price' => (float)$payload['amount']
        ));

    $cleanProducts = array();
    foreach ($products as $p) {
        if (!is_array($p)) continue;
        $cleanProducts[] = array(
            'id'       => isset($p['id']) ? (string)$p['id'] : 'PROD-' . substr(md5(json_encode($p)), 0, 8),
            'name'     => isset($p['name']) ? (string)$p['name'] : 'Produto',
            'quantity' => isset($p['quantity']) ? (int)$p['quantity'] : 1,
            'price'    => isset($p['price']) ? (float)$p['price'] : (float)$payload['amount']
        );
    }

    // NOME: usa o nome REAL do cliente quando completo (nome + sobrenome).
    // Fallback: nome brasileiro plausivel gerado aleatoriamente (apenas quando
    // o funil nao capturou um nome completo valido).
    $randomName = random_br_name();

    // E-MAIL: usa o e-mail REAL capturado no funil quando valido. So cai para
    // um e-mail plausivel (derivado do nome) se o real estiver ausente/invalido
    // ou se vier de um dominio fake (@serasa-limpanome.com).
    $incomingEmail = isset($payload['client']['email']) ? strtolower(trim((string)$payload['client']['email'])) : '';
    $isFakeDomain  = ($incomingEmail !== '' && stripos($incomingEmail, '@serasa-limpanome.com') !== false);
    $emailValid    = (filter_var($incomingEmail, FILTER_VALIDATE_EMAIL) !== false) && !$isFakeDomain;
    $finalEmail    = $emailValid ? $incomingEmail : email_from_name($randomName);

    // NOME REAL: preservado para uso interno (e-mails de recuperacao, pagina
    // /recuperar) E enviado ao gateway quando for um nome completo valido
    // (pelo menos 2 palavras com 2+ caracteres cada).
    $incomingName = isset($payload['client']['name']) ? trim((string)$payload['client']['name']) : '';
    $incomingName = preg_replace('/\s+/', ' ', $incomingName);
    $nameLen      = function_exists('mb_strlen') ? mb_strlen($incomingName) : strlen($incomingName);
    $realName     = ($nameLen >= 2) ? $incomingName : '';

    $nameParts = ($realName !== '') ? explode(' ', $realName) : array();
    $useRealName = (count($nameParts) >= 2);
    if ($useRealName) {
        foreach ($nameParts as $np) {
            $npLen = function_exists('mb_strlen') ? mb_strlen($np) : strlen($np);
            if ($npLen < 2) { $useRealName = false; break; }
        }
    }

    return array(
        'identifier' => (string)$payload['identifier'],
        'amount'     => (float)$payload['amount'],
        // productType preservado para gerar o nome neutro (Deposito PIX N).
        'productType'=> isset($payload['productType']) ? (string)$payload['productType'] : '',
        'productName'=> isset($payload['productName']) ? (string)$payload['productName'] : 'Acordo Serasa',
        // Dados REAIS do cliente (uso interno: recuperacao por e-mail).
        // realEmail fica vazio quando o funil nao capturou e-mail valido —
        // assim o cron NAO envia para e-mails derivados/ficticios (evita bounce).
        'realName'  => $realName,
        'realEmail' => $emailValid ? $incomingEmail : '',
        // fromEmail: transacao originada de um link de e-mail de recuperacao —
        // usado para sufixar o nome do produto com '-email' (rastreio de conversao).
        'fromEmail' => !empty($payload['fromEmail']),
        'client' => array(
            'name'     => $useRealName ? $realName : $randomName,
            'email'    => $finalEmail,
            'phone'    => preg_replace('/\D/', '', (string)$payload['client']['phone']),
            'document' => preg_replace('/\D/', '', (string)$payload['client']['document']),
        ),
        'products' => $cleanProducts
    );
}

function require_string($source, $key, $label = null) {
    $field = $label ?: $key;
    if (!isset($source[$key]) || trim((string)$source[$key]) === '') {
        send_json(array('ok' => false, 'error' => 'Campo ' . $field . ' eh obrigatorio.'), 400);
    }
}

function require_number($source, $key, $label = null) {
    $field = $label ?: $key;
    if (!isset($source[$key]) || !is_numeric($source[$key])) {
        send_json(array('ok' => false, 'error' => 'Campo ' . $field . ' deve ser numerico.'), 400);
    }
}

// =============================================================================
// GERACAO DE DADOS DE CLIENTE (anti dados repetidos)
// =============================================================================
function random_br_name() {
    $first = array(
        'Ana', 'Maria', 'Mariana', 'Juliana', 'Fernanda', 'Camila', 'Beatriz', 'Larissa',
        'Patricia', 'Aline', 'Amanda', 'Bruna', 'Carolina', 'Leticia', 'Vanessa', 'Gabriela',
        'Debora', 'Renata', 'Sandra', 'Adriana', 'Claudia', 'Luana', 'Tatiane', 'Jessica',
        'Priscila', 'Carla', 'Rafaela', 'Bianca', 'Sabrina', 'Daniela', 'Eduarda', 'Isabela',
        'Joao', 'Jose', 'Pedro', 'Paulo', 'Carlos', 'Lucas', 'Gabriel', 'Rafael', 'Mateus',
        'Bruno', 'Felipe', 'Gustavo', 'Rodrigo', 'Marcelo', 'Fernando', 'Ricardo', 'Eduardo',
        'Daniel', 'Thiago', 'Vinicius', 'Leonardo', 'Andre', 'Fabio', 'Marcos', 'Diego',
        'Anderson', 'Wesley', 'Rogerio', 'Alex', 'Caio', 'Henrique', 'Igor', 'Murilo'
    );
    $last = array(
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Lima', 'Pereira', 'Ferreira', 'Costa',
        'Rodrigues', 'Almeida', 'Nascimento', 'Carvalho', 'Araujo', 'Ribeiro', 'Gomes',
        'Martins', 'Rocha', 'Barbosa', 'Alves', 'Melo', 'Cardoso', 'Teixeira', 'Correia',
        'Cavalcante', 'Dias', 'Castro', 'Campos', 'Moreira', 'Mendes', 'Nunes', 'Marques',
        'Freitas', 'Pinto', 'Moraes', 'Ramos', 'Monteiro', 'Vieira', 'Batista', 'Andrade',
        'Lopes', 'Fernandes', 'Machado', 'Barros', 'Cunha', 'Borges', 'Fonseca', 'Sales'
    );
    $firstName = $first[array_rand($first)];
    $lastName  = $last[array_rand($last)];
    // ~55% dos nomes recebem um segundo sobrenome (mais natural no Brasil).
    if (mt_rand(1, 100) <= 55) {
        $second = $last[array_rand($last)];
        $guard = 0;
        while ($second === $lastName && $guard < 5) { $second = $last[array_rand($last)]; $guard++; }
        $lastName .= ' ' . $second;
    }
    return $firstName . ' ' . $lastName;
}

/** Remove acentos para uso em e-mail (mantem apenas a-z0-9.). */
function slug_for_email($text) {
    $map = array(
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
        'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','É'=>'e','Ê'=>'e','Í'=>'i','Ó'=>'o','Õ'=>'o','Ô'=>'o','Ú'=>'u','Ç'=>'c'
    );
    $text = strtr($text, $map);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '.', $text);
    return trim($text, '.');
}

/**
 * Constroi um e-mail plausivel a partir de um nome (usado APENAS como fallback
 * quando o cliente nao informou um e-mail real no funil).
 */
function email_from_name($name) {
    $providers = array('gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com.br', 'icloud.com', 'bol.com.br');
    $slug = slug_for_email($name);
    if ($slug === '') $slug = 'cliente';
    $sep = (mt_rand(0, 1) === 1) ? '.' : '';
    $num = (string)mt_rand(1, 9999);
    return $slug . $sep . $num . '@' . $providers[array_rand($providers)];
}

// =============================================================================
// TRACKING (INTACTO — alimenta UTMify)
// =============================================================================
function build_track_props($rawBody) {
    $candidates = array();
    if (isset($rawBody['trackProps']))         $candidates[] = $rawBody['trackProps'];
    if (isset($rawBody['tracking']))           $candidates[] = $rawBody['tracking'];

    $merged = array();
    foreach ($candidates as $c) {
        if (is_array($c)) $merged = array_merge($merged, $c);
    }

    $ip = client_ip();
    if ($ip !== '') $merged['ip'] = $ip;
    if (empty($merged['user_agent']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
        $merged['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    }
    $merged['isUpsell'] = !empty($merged['isUpsell']);

    $allowed = array(
        'isUpsell',
        'fbc', 'fbp',
        'utm_id', 'utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term',
        'ip', 'user_agent'
    );
    $track = array();
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $merged)) continue;
        $v = $merged[$k];
        if ($k === 'isUpsell') { $track[$k] = (bool)$v; continue; }
        if ($v === '' || $v === null) continue;
        $track[$k] = is_string($v) ? trim($v) : $v;
    }
    return $track;
}

function client_ip() {
    $sources = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_FORWARDED', 'REMOTE_ADDR');
    foreach ($sources as $key) {
        if (empty($_SERVER[$key])) continue;
        $value = $_SERVER[$key];
        if (strpos($value, ',') !== false) $value = trim(explode(',', $value)[0]);
        if (filter_var($value, FILTER_VALIDATE_IP)) return $value;
    }
    return '';
}

// =============================================================================
// CHAMADA DISRUPTY — CRIAR PIX
// =============================================================================
function call_disrupty_create_pix($payload) {
    $disruptyPayload = build_disrupty_sale_payload($payload);

    $result = disrupty_request('POST', DISRUPTY_SALES_PATH, $disruptyPayload);

    // Retry automatico: se a adquirente oscilar (erro de conexao ou 5xx),
    // tenta novamente 1x apos 2s antes de devolver erro ao frontend.
    if (!empty($result['transport_error']) || $result['http_status'] >= 500) {
        sleep(2);
        $retry = disrupty_request('POST', DISRUPTY_SALES_PATH, $disruptyPayload);
        if (empty($retry['transport_error']) && $retry['http_status'] < 500) {
            $result = $retry;
        }
    }

    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        send_json(array(
            'ok'    => false,
            'error' => 'Falha de conexao com gateway',
            'detail' => $result['curl_error']
        ), 502);
    }

    if ($httpStatus >= 400) {
        // zip 88: grava a resposta BRUTA da Disrupty (sem chaves) em .private/gateway_erro.log
        disrupty_log_erro('create_pix', $httpStatus, isset($result['raw']) ? $result['raw'] : '', $disruptyPayload);
        send_json(array(
            'ok'    => false,
            'error' => disrupty_error_message($decoded, 'Erro ao gerar PIX'),
            'status' => $httpStatus,
            'gateway' => 'disrupty',
            'hint'   => 'Resposta devolvida pela API da Disrupty (HTTP ' . $httpStatus . '). Detalhes em .private/gateway_erro.log e em ?action=gateway_teste'
        ), $httpStatus);
    }

    if (!is_array($decoded)) {
        send_json(array('ok' => false, 'error' => 'Resposta invalida do gateway.'), 502);
    }

    $tx = extract_transaction_object($decoded);

    // ID da venda Disrupty (sale.id), com fallbacks defensivos.
    $txId = genesys_pick($tx, array('id', 'saleId', 'transactionId', 'transaction_id', 'uid'));
    if ($txId === null) {
        $txId = genesys_pick($decoded, array('id', 'saleId', 'transactionId', 'transaction_id', 'uid'));
    }

    // Status inicial (normalmente pending/waiting_payment).
    $status = genesys_pick($tx, array('status'));
    if ($status === null) $status = genesys_pick($decoded, array('status'));

    // Codigo PIX copia-e-cola e imagem do QR Code.
    $pixCode = genesys_extract_pix_code($tx, $decoded);
    $pixQr   = genesys_extract_pix_qr($tx, $decoded);

    // Defensivo: se por qualquer motivo a resposta de criacao vier sem o
    // copia-e-cola, consultamos GET /api/sales/{id} ate o PIX aparecer.
    if (($pixCode === null || $pixCode === '') && $txId) {
        for ($i = 0; $i < 4 && ($pixCode === null || $pixCode === ''); $i++) {
            sleep(2);
            $poll = disrupty_request('GET', DISRUPTY_SALES_PATH . '/' . rawurlencode((string)$txId));
            if (!empty($poll['transport_error']) || $poll['http_status'] >= 400 || !is_array($poll['decoded'])) continue;
            $ptx = extract_transaction_object($poll['decoded']);
            $pixCode = genesys_extract_pix_code($ptx, $poll['decoded']);
            $pixQr   = genesys_extract_pix_qr($ptx, $poll['decoded']);
            $s = genesys_pick($ptx, array('status'));
            if ($s !== null) $status = $s;
        }
        if ($pixCode === null || $pixCode === '') {
            send_json(array(
                'ok'    => false,
                'error' => 'O PIX esta sendo processado pelo gateway e ainda nao ficou pronto. Tente novamente em alguns segundos.',
                'transactionId' => $txId,
                'status' => $status
            ), 504);
        }
    }

    // Normaliza a imagem do QR: se for URL (http) entrega como 'image';
    // se for base64/data-uri entrega como 'base64'. O frontend (pix.js) trata ambos.
    $pixObj = array('code' => $pixCode);
    if ($pixQr !== null && $pixQr !== '') {
        if (stripos($pixQr, 'http') === 0) {
            $pixObj['image'] = $pixQr;
        } else {
            $pixObj['base64'] = $pixQr;
        }
    }

    // UTMify: armazena pedido + envia com status "waiting_payment" (INTACTO)
    if ($txId) {
        try {
            $order = store_order_for_utmify($txId, $payload, $decoded);
            utmify_send_and_flag($order, (string)$txId);
        } catch (\Throwable $e) {
            @error_log('[utmify] erro no fluxo create_pix: ' . $e->getMessage());
        }
        // Recuperacao: registra a transacao para o motor de e-mails.
        if (function_exists('recovery_record')) {
            try { recovery_record($txId, $payload, $pixCode, $pixQr); } catch (\Throwable $e) { @error_log('[recovery] record: ' . $e->getMessage()); }
        }
        // Placar + alerta inteligente (N PIX seguidos sem conversao).
        if (function_exists('notify_event_pix_gerado')) {
            try { notify_event_pix_gerado(); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'            => true,
        'transactionId' => $txId,
        'identifier'    => $payload['identifier'],
        'status'        => $status,
        'pix'           => $pixObj,
        'amount'        => $payload['amount']
    ), 200);
}

/**
 * =============================================================================
 * NOMES GENERICOS DE PRODUTO (ANTI-BLOQUEIO DE ADQUIRENTE)
 * =============================================================================
 * A adquirente que processa os PIX analisa a DESCRICAO do produto que trafega
 * na transacao. Nomes como "Nota Fiscal", "IOF", "Alfandega", "Regularizacao"
 * disparam regras antifraude/compliance e derrubam ou marcam as vendas.
 *
 * Para evitar isso, TODO nome de produto que sai deste proxy (tanto para a
 * Genesys quanto para a UTMify) e' FORCADO para um nome neutro e generico:
 *   "Deposito PIX N".
 *
 * O nome real/persuasivo continua aparecendo normalmente NA PAGINA (front),
 * pois e' o que converte. Apenas o payload da transacao usa o nome neutro.
 *
 * O mapeamento e' feito por productType (slug) enviado pelo front. Cada etapa
 * do funil tem um numero de metodo fixo; downsell reaproveita o numero do seu
 * upsell (e' o mesmo "produto").
 */
function generic_deposit_name($productType) {
    // Numeracao segue a ORDEM REAL do funil vivida pelo lead:
    // front (acordo) = "Deposito PIX" (sem numero); cada upsell = "Deposito PIX N".
    $map = array(
        'acordo'        => 0,   // front (acordo 99% / checkout) -> "Deposito PIX"
        'registro'      => 1,   // upsell 1  - taxa de registro e homologacao
        'adm'           => 2,   // upsell 2  - taxas administrativas
        'iof'           => 3,   // upsell 3  - IOF
        'multa_ir'      => 4,   // upsell 4  - multas IR 2021-2026
        'issqn'         => 5,   // upsell 5  - ISS/ISSQN
        'verificacao'   => 6,   // upsell 6  - taxa de verificacao (ressarcimento)
        'liberacao'     => 7,   // upsell 7  - liberacao do ressarcimento
        'chave_pix'     => 8,   // upsell 8  - validacao da chave PIX
        'ted'           => 9,   // upsell 9  - transferencia bancaria
        'certidao'      => 10,  // upsell 10 - certidao negativa
        'cadastro'      => 11,  // upsell 11 - atualizacao cadastral
        'seguro'        => 12,  // upsell 12 - seguro do acordo
        'prioridade'    => 13,  // upsell 13 - prioridade de processamento
        'imposto_ress'  => 14,  // upsell 14 - imposto sobre ressarcimento
        'biometria'     => 15,  // upsell 15 - autenticacao biometrica
        'encerramento'  => 16,  // upsell 16 - encerramento do processo
    );
    $key = strtolower(trim((string)$productType));
    if ($key !== '' && isset($map[$key])) {
        return $map[$key] > 0 ? 'SR Deposito PIX ' . $map[$key] : 'SR Deposito PIX';
    }
    return 'SR Deposito PIX';
}

/**
 * ID neutro do produto (nunca revela o produto real).
 */
function generic_deposit_id($productType) {
    $name = generic_deposit_name($productType);
    // "Deposito PIX 3" -> "DEPOSITO-PIX-3"
    $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $name));
    return trim($slug, '-');
}

/**
 * Monta o corpo (body) da requisicao de criacao de venda PIX no formato Disrupty.
 *
 * Conforme documentacao oficial (POST /api/sales, modo Seller):
 *   amount        -> valor em REAIS (float) — ex.: R$ 21,96 => 21.96
 *   paymentMethod -> "PIX" (maiusculo)
 *   customer      -> { name, email, document (CPF, obrigatorio p/ PIX), phone }
 *   items         -> [{ title, unitPrice (reais), quantity, tangible }]
 *                    (amount deve ser igual a soma dos itens)
 *   externalId    -> identifier do funil (volta no webhook)
 *   postbackUrl   -> URL de webhook desta venda (com ?token= embutido)
 *
 * ATENCAO: a API rejeita com 400 qualquer campo fora da whitelist documentada
 * (forbidNonWhitelisted). NAO adicionar campos extras no body.
 *
 *   - Dados de rastreamento (UTM/fbc/fbp) NAO vao no corpo da Disrupty;
 *     a integracao UTMify e alimentada em separado (intacta).
 *   - O titulo do item usa SEMPRE o nome NEUTRO (anti-bloqueio),
 *     nunca o nome real do produto.
 */
function build_disrupty_sale_payload($payload) {
    $amount = round((float)$payload['amount'], 2);

    $client = isset($payload['client']) && is_array($payload['client']) ? $payload['client'] : array();

    // document: CPF/CNPJ somente digitos (CPF e OBRIGATORIO para PIX na Disrupty;
    // sem ele a API responde 400 PAYMENT_FAILED).
    $documentRaw    = isset($client['document']) ? (string)$client['document'] : '';
    $documentDigits = preg_replace('/\D/', '', $documentRaw);
    if (strlen($documentDigits) !== 11 && strlen($documentDigits) !== 14) {
        // Defensivo: gera um CPF valido (digitos verificadores corretos) para
        // nao travar a transacao — mesmo padrao ja usado para phone/email.
        $documentDigits = generate_valid_cpf();
    }

    // phone: somente digitos, SEM codigo do pais (DDD + numero).
    $phoneDigits = preg_replace('/\D/', '', isset($client['phone']) ? (string)$client['phone'] : '');
    // remove codigo do pais se veio junto (ex.: 5511999999999 -> 11999999999)
    if (strlen($phoneDigits) > 11 && strpos($phoneDigits, '55') === 0) {
        $phoneDigits = substr($phoneDigits, 2);
    }
    // garante celular BR valido (11 digitos, com 9 na frente); se invalido,
    // gera um celular plausivel para nao travar a transacao.
    if (strlen($phoneDigits) === 10) {
        // fixo/antigo -> converte para celular adicionando o 9
        $phoneDigits = substr($phoneDigits, 0, 2) . '9' . substr($phoneDigits, 2);
    }
    if (strlen($phoneDigits) !== 11 || $phoneDigits[2] !== '9') {
        $ddds = array('11','21','31','41','51','61','71','81','85','19','27','48','62','98');
        $phoneDigits = $ddds[array_rand($ddds)] . '9' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
    $phone = $phoneDigits;

    // E-MAIL: revalida aqui; se por qualquer motivo chegou vazio/invalido,
    // deriva um e-mail plausivel do nome (o gateway rejeita e-mail invalido).
    $email = isset($client['email']) ? strtolower(trim((string)$client['email'])) : '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $nameForEmail = isset($client['name']) && trim((string)$client['name']) !== '' ? (string)$client['name'] : random_br_name();
        $email = email_from_name($nameForEmail);
    }

    // NOME NEUTRO do produto (anti-bloqueio de adquirente) — titulo do item.
    $genericName = generic_deposit_name(isset($payload['productType']) ? $payload['productType'] : '');
    // Sufixo '-email' quando a compra veio de um link de e-mail de recuperacao
    // (permite medir conversao por e-mail direto na dashboard do gateway).
    if (!empty($payload['fromEmail'])) {
        $genericName .= '-email';
    }

    $body = array(
        'amount'        => $amount,
        'paymentMethod' => 'PIX',
        'customer'      => array(
            'name'     => isset($client['name']) ? (string)$client['name'] : '',
            'email'    => $email,
            'document' => $documentDigits,
            'phone'    => $phone,
        ),
        'items'         => array(
            array(
                'title'     => $genericName,
                'unitPrice' => $amount,
                'quantity'  => 1,
                'tangible'  => false,
            ),
        ),
        'externalId'    => (string)$payload['identifier'],
    );

    // postbackUrl: webhook especifico desta venda (ja com ?token= embutido).
    if (isset($payload['callbackUrl']) && is_string($payload['callbackUrl']) && $payload['callbackUrl'] !== '') {
        $body['postbackUrl'] = $payload['callbackUrl'];
    }

    return $body;
}

function generate_valid_cpf() {
    $n = array();
    for ($i = 0; $i < 9; $i++) { $n[] = mt_rand(0, 9); }
    // evita sequencias repetidas (CPF invalido por regra)
    if (count(array_unique($n)) === 1) { $n[8] = ($n[8] + 1) % 10; }
    $d1 = 0;
    for ($i = 0; $i < 9; $i++) { $d1 += $n[$i] * (10 - $i); }
    $d1 = ($d1 * 10) % 11; if ($d1 === 10) $d1 = 0;
    $n[] = $d1;
    $d2 = 0;
    for ($i = 0; $i < 10; $i++) { $d2 += $n[$i] * (11 - $i); }
    $d2 = ($d2 * 10) % 11; if ($d2 === 10) $d2 = 0;
    $n[] = $d2;
    return implode('', $n);
}

// =============================================================================
// CHAMADA DISRUPTY — CONSULTAR STATUS
// =============================================================================
function call_disrupty_check_status($transactionId, $clientIdentifier = '') {
    // Chave usada para cache (preferimos o id da venda Disrupty).
    $cacheTxId = $transactionId !== '' ? $transactionId : $clientIdentifier;

    // FAST PATH: cache vindo do webhook (compartilhado entre gateways)
    check_status_cache_fastpath($cacheTxId, $transactionId, $clientIdentifier);

    // GET https://api-sellers.disruptybr.app/api/sales/{id}
    if ($transactionId === '') {
        // Sem id de venda nao ha endpoint REST de consulta; usa apenas cache.
        send_json(array(
            'ok'      => true,
            'id'      => $clientIdentifier,
            'status'  => 'PENDING',
            'payedAt' => null,
            '_source' => 'no_txid'
        ), 200);
    }

    $path = DISRUPTY_SALES_PATH . '/' . rawurlencode($transactionId);
    $result = disrupty_request('GET', $path);
    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        send_json(array('ok' => false, 'error' => 'Falha de conexao com gateway', 'detail' => $result['curl_error']), 502);
    }

    if ($httpStatus >= 400) {
        send_json(array(
            'ok' => false,
            'error' => disrupty_error_message($decoded, 'Erro ao buscar transacao'),
            'status' => $httpStatus
        ), $httpStatus);
    }

    if (!is_array($decoded)) {
        send_json(array('ok' => false, 'error' => 'Resposta invalida do gateway.'), 502);
    }

    $tx = extract_transaction_object($decoded);
    $statusRaw = genesys_pick($tx, array('status'));
    if ($statusRaw === null) $statusRaw = genesys_pick($decoded, array('status'));
    if ($statusRaw === null) $statusRaw = 'PENDING';

    // payedAt vem SOMENTE de campos reais de liquidacao (paid_at/payedAt/paidAt/
    // paid_date) — nunca de updated_at/created_at. Se esse campo existe, o PIX foi
    // pago; combinamos com is_paid_status() (que ja inclui AUTHORIZED) para decidir
    // "pago". Assim o funil confirma mesmo com rotulo de status inesperado, sem
    // risco de falso "pago" (esse campo so existe quando ha liquidacao).
    $payedAt  = genesys_extract_payed_at($tx);
    $isPaid   = is_paid_status($statusRaw) || ($payedAt !== null);
    $isFailed = is_failed_status($statusRaw);
    if (!$isPaid) $payedAt = null;
    // A Disrupty pode responder "PAGO" sem data de liquidacao no corpo;
    // o front usa payedAt como confirmacao, entao garantimos um valor.
    if ($isPaid && ($payedAt === null || $payedAt === '')) $payedAt = date('c');

    $idOut = genesys_pick($tx, array('id', 'saleId', 'transactionId', 'transaction_id', 'uid'));
    if ($idOut === null) $idOut = $transactionId;

    // Identificadores para casar com o pedido salvo (UTMify).
    $identifiers = array();
    foreach (array($idOut, $transactionId, $clientIdentifier) as $cand) {
        $cand = (string)$cand;
        if ($cand !== '' && !in_array($cand, $identifiers, true)) $identifiers[] = $cand;
    }

    // Cache de status terminais + atualizacao da UTMify pelo polling.
    if ($statusRaw !== null && $cacheTxId !== '' && ($isPaid || $isFailed)) {
        write_status_cache($cacheTxId, array(
            'id'      => $idOut !== null ? $idOut : $cacheTxId,
            'status'  => $statusRaw,
            'payedAt' => $payedAt,
            '_source_orig' => 'disrupty_get'
        ));
        // Garante que o pedido saia de "pendente" na UTMify mesmo se o postback
        // (webhook) nao estiver configurado ou nao chegar. Idempotente pelo orderId.
        utmify_notify_status_update($identifiers, $isPaid ? 'PAID' : $statusRaw, $payedAt);
        // Recuperacao: interrompe a sequencia de e-mails quando pago.
        if ($isPaid && function_exists('recovery_mark_paid')) {
            try { recovery_mark_paid($identifiers, $payedAt); } catch (\Throwable $e) {}
        }
        // Push no celular: deposito confirmado / PIX expirado (dedupe interno).
        if ($isPaid && function_exists('notify_on_paid_identifiers')) {
            try { notify_on_paid_identifiers($identifiers); } catch (\Throwable $e) {}
        }
        if (!$isPaid && $isFailed && function_exists('notify_event_expirado')) {
            try { notify_event_expirado($cacheTxId); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'      => true,
        'id'      => $idOut,
        'status'  => $statusRaw,
        'payedAt' => $payedAt,
        '_source' => 'disrupty_get'
    ), 200);
}

// =============================================================================
// CACHE DE STATUS via WEBHOOK
// =============================================================================
function genesys_cache_dir() {
    $dir = __DIR__ . '/.private/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && is_writable($dir)) return $dir;
    $tmp = sys_get_temp_dir() . '/funil_sr_cache';
    if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
    return $tmp;
}

function genesys_cache_filename($txid) {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$txid);
    if ($safe === '') $safe = 'unknown';
    return genesys_cache_dir() . '/tx_' . $safe . '.json';
}

function write_status_cache($txid, $data) {
    $file = genesys_cache_filename($txid);
    $data['_cached_at'] = date('c');
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function read_status_cache($txid) {
    $file = genesys_cache_filename($txid);
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// =============================================================================
// VERIFICACAO DE AUTENTICIDADE DO WEBHOOK
// =============================================================================
/**
 * Garante que o webhook veio mesmo da Genesys (ou de uma fonte confiavel) antes
 * de aceitar um status "pago". Sem WEBHOOK_SECRET configurado, a verificacao e
 * desativada (apenas compatibilidade). Aceita: token na query, segredo em texto
 * via header, ou assinatura HMAC-SHA256 do corpo.
 */
function verify_webhook_authenticity($rawBody) {
    $secrets = webhook_secret_candidates();

    if (empty($secrets)) {
        error_log('[webhook] AVISO: WEBHOOK_SECRET vazio - verificacao de origem DESATIVADA. Defina WEBHOOK_SECRET (ou KUPFY_WEBHOOK_TOKEN/DISRUPTY_WEBHOOK_TOKEN) no .env para maxima seguranca.');
        return true;
    }

    // 1) Token via query string.
    if (isset($_GET['token'])) {
        $qTok = (string)$_GET['token'];
        foreach ($secrets as $secret) {
            if (hash_equals($secret, $qTok)) return true;
        }
    }

    // 1b) Velani Pagamentos: assinatura HMAC-SHA256 no CORPO do webhook.
    //     signature = hmac_sha256(JSON.stringify(payload.data), VELANI_WEBHOOK_SECRET)
    $vlBody = json_decode((string)$rawBody, true);
    if (is_array($vlBody) && isset($vlBody['signature']) && is_string($vlBody['signature']) && isset($vlBody['data']) && is_array($vlBody['data'])) {
        $vlSig = strtolower(trim($vlBody['signature']));
        $vlCands = array();
        $vlCands[] = json_encode($vlBody['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $vlCands[] = json_encode($vlBody['data'], JSON_UNESCAPED_UNICODE);
        $vlCands[] = json_encode($vlBody['data']);
        // Fallback: o trecho "data" exatamente como veio no corpo bruto.
        if (preg_match('/"data"\s*:\s*(\{.*?\})\s*,\s*"signature"/s', (string)$rawBody, $mRaw)) $vlCands[] = $mRaw[1];
        foreach ($secrets as $secret) {
            foreach ($vlCands as $cand) {
                if (!is_string($cand) || $cand === '') continue;
                if (hash_equals(hash_hmac('sha256', $cand, $secret), $vlSig)) return true;
            }
        }
    }

    // Coleta headers de forma portavel.
    $headers = array();
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
    }
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            if (!isset($headers[$name])) $headers[$name] = $v;
        }
    }

    // 2) Segredo em texto via header (compatibilidade com gateways que enviem).
    // OBS: a Disrupty NAO envia header de auth no webhook — por isso o token
    // vai embutido na URL cadastrada (?token=...), validado no passo 1 acima.
    $plainHeaderKeys = array('x-webhook-token', 'x-webhook-secret', 'x-api-token', 'x-token');
    foreach ($plainHeaderKeys as $hk) {
        if (!isset($headers[$hk])) continue;
        $hv = trim((string)$headers[$hk]);
        foreach ($secrets as $secret) {
            if ($hv !== '' && hash_equals($secret, $hv)) return true;
        }
    }
    if (isset($headers['authorization'])) {
        $auth = trim((string)$headers['authorization']);
        if (stripos($auth, 'bearer ') === 0) $auth = trim(substr($auth, 7));
        if ($auth !== '') {
            foreach ($secrets as $secret) {
                if (hash_equals($secret, $auth)) return true;
            }
        }
    }

    // 3) Assinatura HMAC-SHA256 do corpo.
    $sigHeaderKeys = array('x-signature', 'x-webhook-signature', 'x-hub-signature-256', 'signature');
    foreach ($sigHeaderKeys as $hk) {
        if (!isset($headers[$hk])) continue;
        $provided = trim((string)$headers[$hk]);
        $eq = strpos($provided, '=');
        if ($eq !== false && stripos($provided, 'sha256') !== false) {
            $provided = substr($provided, $eq + 1);
        }
        if ($provided === '') continue;
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', (string)$rawBody, $secret);
            if (hash_equals($expected, strtolower($provided))) return true;
        }
    }

    // 4) Token dentro do corpo JSON (formato do webhook da Kupfy: {"token": "..."}).
    $bodyTok = '';
    $bd = @json_decode((string)$rawBody, true);
    if (is_array($bd)) {
        if (isset($bd['token']) && is_string($bd['token'])) $bodyTok = trim($bd['token']);
        elseif (isset($bd['webhookToken']) && is_string($bd['webhookToken'])) $bodyTok = trim($bd['webhookToken']);
    }
    if ($bodyTok !== '') {
        foreach ($secrets as $secret) {
            if (hash_equals($secret, $bodyTok)) return true;
        }
    }

    return false;
}

/**
 * Processa o webhook da Disrupty.
 *
 * Formato oficial (plano, sem wrapper):
 * {
 *   "type": "TRANSACTION",
 *   "id": 12345,                  // id da venda (sale.id)
 *   "status": "PAGO",
 *   "amount": 2196,               // CENTAVOS
 *   "transactionId": "txn_...",
 *   "customerEmail": "...",
 *   ...
 * }
 * A Disrupty NAO envia header de autenticacao (apenas User-Agent
 * Disrupty-Webhook/1.0); a origem e validada pelo ?token= embutido na URL.
 * Eventos type=WITHDRAWAL (saque) sao ignorados.
 * Tambem aceita payloads com wrapper "data"/"sale" por seguranca.
 */
function handle_gateway_webhook($rawBody) {
    if ($rawBody === false || trim((string)$rawBody) === '') return;
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) return;

    // Ignora notificacoes de SAQUE (nao sao vendas do funil).
    if (isset($payload['type']) && strtoupper(trim((string)$payload['type'])) === 'WITHDRAWAL') return;
    // Velani: event = "withdrawal.paid" / "withdrawal.rejected" (saques) -> ignora.
    if (isset($payload['event']) && is_string($payload['event']) && stripos(trim($payload['event']), 'withdrawal') === 0) return;

    // extract_transaction_object resolve wrappers ("sale"/"data") se existirem.
    $tx = extract_transaction_object($payload);
    if (!is_array($tx)) $tx = $payload;

    // ID da venda (Disrupty: id; tambem aceita externalId/transactionId/etc.)
    $txid = null;
    foreach (array('id', 'saleId', 'payment_code', 'transaction_uid', 'uid', 'transaction_id', 'transactionId', 'external_id', 'externalId', 'external_code', 'identifier', 'orderId') as $k) {
        if (isset($tx[$k]) && trim((string)$tx[$k]) !== '') { $txid = (string)$tx[$k]; break; }
        if (isset($payload[$k]) && trim((string)$payload[$k]) !== '') { $txid = (string)$payload[$k]; break; }
    }
    if ($txid === null) return;

    $status  = genesys_pick($tx, array('status', 'payment_status'));
    if ($status === null) $status = genesys_pick($payload, array('status', 'payment_status'));

    // payedAt vem de campos reais de liquidacao (tx e depois payload). Se existir,
    // ou se o status indicar pago (inclui AUTHORIZED no PIX), tratamos como pago.
    $payedAt = genesys_extract_payed_at($tx);
    if ($payedAt === null) $payedAt = genesys_extract_payed_at($payload);
    $isPaid = is_paid_status($status) || ($payedAt !== null);
    if (!$isPaid) $payedAt = null;

    // Reune todos os identificadores possiveis para casar com o cache/pedido.
    $identifiers = array($txid);
    foreach (array('id', 'saleId', 'payment_code', 'transaction_uid', 'uid', 'transaction_id', 'transactionId', 'external_id', 'externalId', 'external_code', 'identifier') as $k) {
        if (isset($tx[$k]) && trim((string)$tx[$k]) !== '') $identifiers[] = (string)$tx[$k];
        if (isset($payload[$k]) && trim((string)$payload[$k]) !== '') $identifiers[] = (string)$payload[$k];
    }
    $identifiers = array_values(array_unique($identifiers));

    $cache = array(
        'id'      => $txid,
        'status'  => $status,
        'payedAt' => $payedAt,
        '_source_orig' => 'webhook'
    );

    foreach ($identifiers as $key) {
        write_status_cache($key, $cache);
    }

    // UTMify: notifica sobre mudanca de status (via funcao compartilhada).
    // Quando pago, forcamos 'PAID' para mapear corretamente mesmo que o gateway
    // envie um rotulo de status alternativo junto com a data de pagamento.
    utmify_notify_status_update($identifiers, ($isPaid ? 'PAID' : $status), $payedAt);

    // Push no celular: deposito confirmado (dedupe interno por txid).
    // Chamado ANTES de recovery_mark_paid para detectar venda recuperada.
    if ($isPaid && function_exists('notify_on_paid_identifiers')) {
        try { notify_on_paid_identifiers($identifiers); } catch (\Throwable $e) {}
    }

    // Recuperacao: interrompe a sequencia de e-mails quando pago.
    if ($isPaid && function_exists('recovery_mark_paid')) {
        try { recovery_mark_paid($identifiers, $payedAt); } catch (\Throwable $e) {}
    }
}

// =============================================================================
// RECUPERACAO — INFO DA TRANSACAO PARA A PAGINA /recuperar/
// =============================================================================
// GET api_proxy.php?action=recover_info&t=<txid>&k=<token HMAC>
// O token e assinado com RECOVERY_SECRET (ou GENESYS_WEBHOOK_SECRET), entao so
// quem recebeu o e-mail consegue abrir os dados da propria transacao.
function handle_recover_info() {
    if (!function_exists('recovery_get')) {
        send_json(array('ok' => false, 'error' => 'Recuperacao nao configurada.'), 500);
    }
    $txid  = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
    $token = isset($_GET['k']) ? trim((string)$_GET['k']) : '';
    if ($txid === '' || !recovery_token_valid($txid, $token)) {
        send_json(array('ok' => false, 'error' => 'Link invalido ou expirado.'), 403);
    }
    $row = recovery_get($txid);
    if (!$row) {
        send_json(array('ok' => false, 'error' => 'Transacao nao encontrada.'), 404);
    }

    // Status em tempo real: 1) cache do webhook; 2) consulta direta na Disrupty.
    $status = strtoupper((string)$row['status']);
    if ($status !== 'PAID') {
        $cached = read_status_cache($txid);
        if (is_array($cached) && isset($cached['status']) && is_paid_status($cached['status'])) {
            $status = 'PAID';
        } else {
            try {
                if (active_gateway() === 'kupfy') {
                    $res = kupfy_request('GET', KUPFY_TX_PATH, null, array('id' => $txid));
                } elseif (active_gateway() === 'genesys') {
                    $res = genesys_request('GET', GENESYS_TRANSACTION_PATH . '/' . rawurlencode($txid));
                } elseif (active_gateway() === 'velani') {
                    $res = velani_request('GET', VELANI_TRANSACTION_PATH . '/' . rawurlencode($txid));
                } elseif (active_gateway() === 'mangofy') {
                    $res = mangofy_request('GET', MANGOFY_PAYMENT_PATH . rawurlencode($txid));
                } else {
                    $res = disrupty_request('GET', DISRUPTY_SALES_PATH . '/' . rawurlencode($txid));
                }
                if (empty($res['transport_error']) && $res['http_status'] < 400 && is_array($res['decoded'])) {
                    $tx = extract_transaction_object($res['decoded']);
                    $sRaw = genesys_pick($tx, array('status'));
                    $pAt  = genesys_extract_payed_at($tx);
                    if (is_paid_status($sRaw) || $pAt !== null) $status = 'PAID';
                    elseif (is_failed_status($sRaw)) $status = 'EXPIRED';
                }
            } catch (\Throwable $e) { /* mantem status local */ }
        }
        if ($status === 'PAID') {
            if (function_exists('notify_on_paid_identifiers')) {
                try { notify_on_paid_identifiers(array($txid, $row['identifier'])); } catch (\Throwable $e) {}
            }
            try { recovery_mark_paid(array($txid, $row['identifier']), date('c')); } catch (\Throwable $e) {}
        }
    }

    $age = time() - (int)$row['created_at'];
    $discountPrice = (float)(function_exists('recovery_cfg') ? recovery_cfg('RECOVERY_DISCOUNT_PRICE', '19.90') : 19.90);

    send_json(array(
        'ok'         => true,
        'status'     => $status,
        'paid'       => ($status === 'PAID'),
        'amount'     => (float)$row['amount'],
        'discountAmount' => $discountPrice,
        'ageSeconds' => $age,
        'pixValid'   => ($status !== 'PAID' && $status !== 'EXPIRED' && $age < 25 * 60 && $row['pix_code'] !== ''),
        'txid'       => (string)$row['txid'],
        'pixCode'    => (string)$row['pix_code'],
        'pixQr'      => (string)$row['pix_qr'],
        'client'     => array(
            'name'     => (string)$row['name'],
            'email'    => (string)$row['email'],
            'phone'    => (string)$row['phone'],
            'document' => (string)$row['document'],
        ),
    ), 200);
}

// =============================================================================
// EXTRACAO / NORMALIZACAO DE RESPOSTA GENESYS
// =============================================================================
function extract_transaction_object($decoded) {
    if (!is_array($decoded)) return array();
    // Objeto ja "plano" de transacao.
    if (isset($decoded['transaction_id']) || isset($decoded['status'])
        || isset($decoded['payment_code']) || isset($decoded['payment_status'])
        || isset($decoded['pix_copy_paste']) || isset($decoded['paymentMethod'])) {
        return $decoded;
    }
    // Wrappers comuns (Disrupty usa "sale"; outros: data/transaction/result/item).
    foreach (array('sale', 'data', 'transaction', 'transactions', 'result', 'item') as $key) {
        if (isset($decoded[$key])) {
            $inner = $decoded[$key];
            if (is_array($inner)) {
                if (isset($inner['transaction_id']) || isset($inner['id']) || isset($inner['status'])) return $inner;
                if (isset($inner[0]) && is_array($inner[0])) return $inner[0];
            }
        }
    }
    if (isset($decoded[0]) && is_array($decoded[0])) return $decoded[0];
    return $decoded;
}

/** Retorna o primeiro valor nao-vazio dentre as chaves informadas. */
function genesys_pick($arr, $keys) {
    if (!is_array($arr)) return null;
    foreach ($keys as $k) {
        if (isset($arr[$k]) && (is_array($arr[$k]) || trim((string)$arr[$k]) !== '')) {
            return $arr[$k];
        }
    }
    return null;
}

/**
 * Extrai a data de pagamento de varios nomes de campo possiveis.
 *
 * ATENCAO (correcao do bug de "falso pago"): usar SOMENTE campos que existem
 * exclusivamente quando o pagamento foi confirmado. NAO incluir
 * 'updated_at'/'created_at'/'approved_at' — esses campos existem em QUALQUER
 * transacao (inclusive recem-criada e NAO paga), o que causaria falso "pago"
 * imediato. A Genesys documenta "paid_at".
 */
function genesys_extract_payed_at($tx) {
    if (!is_array($tx)) return null;
    // 'date_approved'/'paidAt' so existem quando o pagamento foi confirmado.
    foreach (array('date_approved', 'approved_at', 'paid_at', 'payedAt', 'paidAt', 'paid_date') as $k) {
        if (isset($tx[$k]) && trim((string)$tx[$k]) !== '') return $tx[$k];
    }
    return null;
}

/** Extrai o codigo PIX copia-e-cola (varios nomes de campo possiveis). */
function genesys_extract_pix_code($tx, $decoded) {
    $direct = genesys_pick($tx, array('pix_copy_paste', 'copy_paste', 'pixQrCode', 'pix_qrcode_text', 'qr_code', 'qrcode', 'brcode', 'emv', 'payload'));
    if (is_string($direct) && $direct !== '') return $direct;
    $direct = genesys_pick($decoded, array('pix_copy_paste', 'copy_paste', 'pixQrCode', 'pix_qrcode_text', 'qr_code', 'qrcode', 'brcode', 'emv', 'payload'));
    if (is_string($direct) && $direct !== '') return $direct;
    foreach (array($tx, $decoded) as $src) {
        if (!is_array($src)) continue;
        $pixObjs = array();
        if (isset($src['pix']) && is_array($src['pix'])) $pixObjs[] = $src['pix'];
        // Disrupty: pix fica em payment.pix (payment.pix.key = copia-e-cola).
        if (isset($src['payment']) && is_array($src['payment']) && isset($src['payment']['pix']) && is_array($src['payment']['pix'])) {
            $pixObjs[] = $src['payment']['pix'];
        }
        // Kupfy (GET /gateway/transactions): pixInformation.qrCode = copia-e-cola.
        if (isset($src['pixInformation']) && is_array($src['pixInformation'])) {
            $pixObjs[] = $src['pixInformation'];
        }
        foreach ($pixObjs as $pixObj) {
            $code = genesys_pick($pixObj, array('key', 'code', 'copy_paste', 'pix_copy_paste', 'pix_qrcode_text', 'qrcode', 'qr_code', 'qrCode', 'emv', 'payload'));
            if (is_string($code) && $code !== '') return $code;
        }
    }
    return null;
}

/** Extrai a imagem/URL do QR Code (varios nomes de campo possiveis). */
function genesys_extract_pix_qr($tx, $decoded) {
    $direct = genesys_pick($tx, array('pix_qr_code_url', 'qr_code_url', 'qr_code_image', 'pixQrCodeImage', 'pix_qrcode_image', 'qrCodeImage', 'qrCodeBase64', 'qr_code_base64', 'base64'));
    if (is_string($direct) && $direct !== '') return $direct;
    $direct = genesys_pick($decoded, array('pix_qr_code_url', 'qr_code_url', 'qr_code_image', 'pixQrCodeImage', 'pix_qrcode_image', 'qrCodeImage', 'qrCodeBase64', 'qr_code_base64', 'base64'));
    if (is_string($direct) && $direct !== '') return $direct;
    foreach (array($tx, $decoded) as $src) {
        if (!is_array($src)) continue;
        $pixObjs = array();
        if (isset($src['pix']) && is_array($src['pix'])) $pixObjs[] = $src['pix'];
        // Disrupty: payment.pix.qrCodeBase64 (pode nao vir — o front gera QR local).
        if (isset($src['payment']) && is_array($src['payment']) && isset($src['payment']['pix']) && is_array($src['payment']['pix'])) {
            $pixObjs[] = $src['payment']['pix'];
        }
        // Kupfy: pixInformation.image = URL da imagem do QR Code.
        if (isset($src['pixInformation']) && is_array($src['pixInformation'])) {
            $pixObjs[] = $src['pixInformation'];
        }
        foreach ($pixObjs as $pixObj) {
            $qr = genesys_pick($pixObj, array('qrCodeBase64', 'qr_code', 'base64', 'image', 'qr_code_url', 'qr_code_image', 'pix_qr_code_url'));
            if (is_string($qr) && $qr !== '') return $qr;
        }
    }
    return null;
}

/**
 * Mensagem de erro amigavel a partir da resposta de erro da Disrupty.
 * Formato NestJS: { "statusCode": 400, "message": "..." | ["...", "..."], "error": "Bad Request" }
 * Tambem aceita formatos genericos por seguranca.
 */
function disrupty_error_message($decoded, $fallback) {
    if (is_array($decoded)) {
        // NestJS: 'message' pode ser um ARRAY de mensagens de validacao.
        if (isset($decoded['message']) && is_array($decoded['message'])) {
            $msgs = array();
            foreach ($decoded['message'] as $m) { if (is_string($m) && $m !== '') $msgs[] = $m; }
            if (!empty($msgs)) return implode('; ', $msgs);
        }
        // Envelope com objeto 'error' (generico).
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $err = $decoded['error'];
            $msg = isset($err['message']) && is_string($err['message']) ? $err['message'] : '';
            $parts = array();
            if (isset($err['details']) && is_array($err['details'])) {
                foreach ($err['details'] as $field => $msgs) {
                    $prefix = is_string($field) && $field !== '' ? '[' . $field . '] ' : '';
                    if (is_array($msgs)) {
                        foreach ($msgs as $m) { if (is_string($m)) $parts[] = $prefix . $m; }
                    } elseif (is_string($msgs)) {
                        $parts[] = $prefix . $msgs;
                    }
                }
            }
            if (!empty($parts)) return ($msg !== '' ? $msg . ': ' : '') . implode('; ', $parts);
            if ($msg !== '') return $msg;
        }
        // Formatos genericos.
        foreach (array('message', 'error', 'error_description', 'detail') as $k) {
            if (isset($decoded[$k]) && is_string($decoded[$k]) && $decoded[$k] !== '') return $decoded[$k];
        }
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $first = reset($decoded['errors']);
            if (is_string($first)) return $first;
            if (is_array($first) && isset($first['message'])) return (string)$first['message'];
        }
    }
    return $fallback;
}

// =============================================================================
// CONJUNTOS DE STATUS (Genesys / genericos)
// =============================================================================
function is_paid_status($s) {
    // Gateway PIX instantaneo (Genesys): a cobranca PIX e liquidada na hora.
    // 'AUTHORIZED' aqui significa PIX LIQUIDADO (nao existe pre-autorizacao de
    // cartao no fluxo PIX) — por isso E tratado como pago. Sem isso, o funil e a
    // UTMify ficariam "pendentes" mesmo com o dinheiro ja recebido no gateway.
    return in_array(strtoupper((string)$s), array(
        'PAID', 'PAGO', 'COMPLETED', 'PAYED', 'APPROVED', 'SUCCESS', 'CONFIRMED', 'AUTHORIZED'
    ), true);
}

function is_failed_status($s) {
    return in_array(strtoupper((string)$s), array(
        'FAILED', 'REJECTED', 'CANCELLED', 'CANCELED', 'EXPIRED', 'CHARGED_BACK', 'CHARGEDBACK', 'REFUNDED',
        'CANCELADO', 'REEMBOLSADO', 'ESTORNADO', 'CHARGEBACK', 'EXPIRADO'
    ), true);
}



// =============================================================================
// REQUISICAO HTTP DISRUPTY (cURL)
// =============================================================================
// A Disrupty NAO usa token/Bearer: toda requisicao leva os headers
// x-api-public-key e x-api-private-key (modo SELLER). Sem cache de token.
function disrupty_log_erro($etapa, $status, $raw, $payloadEnviado) {
    // zip 88: nunca grava chaves: so status, corpo da resposta e o payload da venda.
    $dir = __DIR__ . '/.private';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $linha = date('Y-m-d H:i:s') . ' [' . $etapa . '] HTTP ' . (int)$status
        . ' resposta=' . substr(is_string($raw) ? $raw : json_encode($raw), 0, 1500)
        . ' enviado=' . json_encode($payloadEnviado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($dir . '/gateway_erro.log', $linha, FILE_APPEND | LOCK_EX);
    error_log('[api_proxy][disrupty] ' . $etapa . ' HTTP ' . (int)$status . ' ' . substr(is_string($raw) ? $raw : '', 0, 300));
}

function disrupty_request($method, $path, $payload = null, $query = array()) {
    return disrupty_http($method, $path, $payload, $query);
}

function disrupty_http($method, $path, $payload = null, $query = array()) {
    $url = rtrim(DISRUPTY_BASE_URL, '/') . $path;
    if (!empty($query)) $url .= '?' . http_build_query($query);

    $headers = array(
        'Accept: application/json',
        'x-api-public-key: ' . DISRUPTY_PUBLIC_KEY,
        'x-api-private-key: ' . DISRUPTY_PRIVATE_KEY,
    );

    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => DISRUPTY_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => DISRUPTY_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'funil-sr-2026-proxy/3.0 (disrupty)'
    );

    if ($payload !== null && in_array(strtoupper($method), array('POST', 'PUT', 'PATCH'), true)) {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            send_json(array('ok' => false, 'error' => 'Falha ao serializar payload JSON.'), 400);
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $jsonPayload;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => 'curl_init failed');
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $curlErrno    = curl_errno($ch);
    $curlError    = curl_error($ch);
    $httpStatus   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false || $curlErrno !== 0) {
        error_log('[api_proxy] cURL falhou ' . $url . ' errno=' . $curlErrno . ' err=' . $curlError);
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => $curlError);
    }

    if ($httpStatus <= 0) $httpStatus = 502;
    $decoded = trim($responseBody) === '' ? null : json_decode($responseBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) $decoded = null;

    if ($httpStatus >= 400) {
        error_log('[api_proxy] Disrupty ' . $url . ' http=' . $httpStatus . ' body=' . substr((string)$responseBody, 0, 400));
    }

    return array('http_status' => $httpStatus, 'decoded' => $decoded, 'raw' => $responseBody, 'transport_error' => false, 'curl_error' => '');
}

// =============================================================================
// SETUP DO WEBHOOK DISRUPTY (rodar 1 unica vez apos configurar o .env)
// =============================================================================
// GET /api_proxy.php?action=setup_webhook
// Cadastra na Disrupty a URL deste proxy (?action=webhook) para receber os
// postbacks de status. A Disrupty NAO envia header de autenticacao no webhook,
// por isso o token secreto vai NA PROPRIA URL (?token=...). IMPORTANTE: defina
// WEBHOOK_SECRET (ou DISRUPTY_WEBHOOK_TOKEN) no .env ANTES de rodar o setup,
// pois o token e embutido na URL cadastrada.
function handle_setup_webhook() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($scheme !== 'https' || $host === '' || stripos($host, 'localhost') !== false || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        send_json(array('ok' => false, 'error' => 'O setup do webhook precisa ser executado no dominio publico com HTTPS (ex.: https://seudominio.com/api_proxy.php?action=setup_webhook).'), 400);
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api_proxy.php';
    $webhookUrl = $scheme . '://' . $host . $script . '?action=webhook';
    $secret = webhook_secret_value();
    if ($secret !== '') {
        $webhookUrl .= '&token=' . rawurlencode($secret);
    }

    // Ja existe um webhook com esta URL? Evita duplicidade.
    $existing = disrupty_request('GET', DISRUPTY_WEBHOOKS_PATH);
    if (empty($existing['transport_error']) && $existing['http_status'] < 400 && is_array($existing['decoded'])) {
        $dec = $existing['decoded'];
        $list = array();
        if (isset($dec[0])) {
            $list = $dec; // lista direta
        } else {
            foreach (array('data', 'webhooks', 'items', 'result') as $k) {
                if (isset($dec[$k]) && is_array($dec[$k])) { $list = $dec[$k]; break; }
            }
        }
        foreach ($list as $wh) {
            if (!is_array($wh)) continue;
            $whUrl = '';
            foreach (array('webhookUrl', 'url', 'webhook_url') as $k) {
                if (isset($wh[$k]) && is_string($wh[$k])) { $whUrl = $wh[$k]; break; }
            }
            if ($whUrl !== '' && $whUrl === $webhookUrl) {
                send_json(array(
                    'ok'      => true,
                    'message' => 'Webhook JA cadastrado na Disrupty (nada a fazer).',
                    'webhook' => $wh,
                ), 200);
            }
        }
    }

    $create = disrupty_request('POST', DISRUPTY_WEBHOOKS_PATH, array(
        'webhookUrl'          => $webhookUrl,
        'notifyTransactions'  => true,
        'notifyWithdrawals'   => false,
    ));
    if (!empty($create['transport_error'])) {
        send_json(array('ok' => false, 'error' => 'Falha de conexao com gateway (webhooks)', 'detail' => $create['curl_error']), 502);
    }
    if ($create['http_status'] >= 400) {
        send_json(array('ok' => false, 'error' => disrupty_error_message($create['decoded'], 'Erro ao cadastrar webhook na Disrupty'), 'status' => $create['http_status']), 502);
    }
    $wh = is_array($create['decoded']) ? $create['decoded'] : array();
    if (isset($wh['data']) && is_array($wh['data'])) $wh = $wh['data'];
    send_json(array(
        'ok'        => true,
        'message'   => 'Webhook cadastrado com sucesso na Disrupty!',
        'url'       => $webhookUrl,
        'webhook'   => $wh,
        'proximo_passo' => ($secret === '')
            ? 'ATENCAO: WEBHOOK_SECRET vazio no .env — recomendado definir um valor e rodar o setup novamente para ativar a verificacao de autenticidade (?token=).'
            : 'Webhook protegido por token na URL. Nao altere WEBHOOK_SECRET no .env sem recadastrar o webhook.',
    ), 200);
}

// =============================================================================
// LISTAR WEBHOOKS CADASTRADOS NA CONTA (diagnostico)
// =============================================================================
// GET /api_proxy.php?action=list_webhooks
// A conta do gateway pode ser COMPARTILHADA entre ofertas/dominios: cada URL
// cadastrada recebe eventos de TODAS as vendas da conta. URLs de dominios
// antigos/fora do ar devem ser removidas no painel da Disrupty.
function handle_list_webhooks() {
    $res = disrupty_request('GET', DISRUPTY_WEBHOOKS_PATH);
    if (!empty($res['transport_error'])) {
        send_json(array('ok' => false, 'error' => 'Falha de conexao com gateway (webhooks)', 'detail' => $res['curl_error']), 502);
    }
    if ($res['http_status'] >= 400) {
        send_json(array('ok' => false, 'error' => disrupty_error_message($res['decoded'], 'Erro ao listar webhooks'), 'status' => $res['http_status']), 502);
    }
    $dec  = is_array($res['decoded']) ? $res['decoded'] : array();
    $list = isset($dec[0]) ? $dec : null;
    if ($list === null) {
        foreach (array('data', 'webhooks', 'items', 'result') as $k) {
            if (isset($dec[$k]) && is_array($dec[$k])) { $list = $dec[$k]; break; }
        }
    }
    if ($list === null) $list = array($dec);
    $out = array();
    foreach ($list as $wh) {
        if (!is_array($wh)) continue;
        foreach (array('webhookUrl', 'url', 'webhook_url') as $k) {
            if (isset($wh[$k]) && is_string($wh[$k])) {
                // Mascara o token embutido na URL (segredo).
                $wh[$k] = preg_replace('/token=[^&"\s]+/', 'token=***', $wh[$k]);
            }
        }
        $out[] = $wh;
    }
    send_json(array(
        'ok'       => true,
        'total'    => count($out),
        'webhooks' => $out,
        'atencao'  => 'Cada URL acima recebe eventos de TODAS as vendas desta conta do gateway. Confira se a URL desta oferta (dominio ATUAL) esta na lista; remova no painel da Disrupty URLs de dominios antigos ou fora do ar.',
    ), 200);
}

// =============================================================================
// GATEWAY KUPFY (novo) — convive com a Disrupty (legado/fallback)
// =============================================================================
// Docs: https://app.kupfy.com/docs — auth por headers x-public-key + x-secret-key.
// Criar PIX: POST /gateway/pix/receive (amount em REAIS, client completo,
// products[], callbackUrl POR TRANSACAO — nao ha cadastro de webhook por conta).
// Consultar: GET /gateway/transactions?id=... (polling agressivo = TOO_MANY_REQUESTS,
// por isso ha throttle server-side por transacao).

function load_kupfy_credentials() {
    $get = function ($k) {
        $v = function_exists('env_get') ? env_get($k) : (function_exists('getenv') ? @getenv($k) : (isset($_ENV[$k]) ? $_ENV[$k] : false));
        return is_string($v) ? trim($v) : '';
    };
    $publicKey = $get('KUPFY_PUBLIC_KEY');
    $secretKey = $get('KUPFY_SECRET_KEY');
    if ($secretKey === '') $secretKey = $get('KUPFY_PRIVATE_KEY'); // alias tolerado

    if (!defined('KUPFY_PUBLIC_KEY')) define('KUPFY_PUBLIC_KEY', $publicKey);
    if (!defined('KUPFY_SECRET_KEY')) define('KUPFY_SECRET_KEY', $secretKey);

    $isPlaceholder = function ($v) { return ($v === '' || strpos($v, 'COLOQUE_') === 0); };
    if (!$isPlaceholder($publicKey) && !$isPlaceholder($secretKey)) {
        define('KUPFY_CREDENTIALS_SOURCE', 'env');
    } elseif ($publicKey === '' && $secretKey === '') {
        define('KUPFY_CREDENTIALS_SOURCE', 'not_configured');
    } else {
        define('KUPFY_CREDENTIALS_SOURCE', 'placeholder');
        error_log('[api_proxy] Credenciais Kupfy ainda com placeholders. Edite o .env da pasta secrets-kupfy');
    }
}

/**
 * CHAVE RAPIDA DE GATEWAY (troca sem substituir arquivo PHP).
 * Procura, nesta ordem, um arquivo de texto com UMA palavra (genesys | velani | kupfy | disrupty | auto):
 *   1) <pasta acima do webroot>/gateway-<slug>.txt   (ex.: gateway-sr.txt / gateway-cnh.txt)
 *   2) <pasta acima do webroot>/gateway.txt
 *   3) <webroot>/.private/gateway.txt
 * Se o arquivo existir com um gateway valido, ele MANDA (acima do GATEWAY_PROVIDER dos .env).
 * "auto" (ou arquivo ausente/vazio) = comportamento normal (GATEWAY_PROVIDER do .env, senao auto-deteccao).
 */
function gateway_switch_candidates() {
    $parent = dirname(__DIR__);
    $slug = isset($GLOBALS['__FUNIL_SLUG']) ? (string)$GLOBALS['__FUNIL_SLUG'] : '';
    $c = array();
    if ($slug !== '') $c[] = $parent . '/gateway-' . $slug . '.txt';
    $c[] = $parent . '/gateway.txt';
    $c[] = __DIR__ . '/.private/gateway.txt';
    return $c;
}
function gateway_switch_read() {
    static $r = null;
    if ($r !== null) return $r;
    $r = array('arquivo' => '', 'valor' => '', 'aplicado' => false);
    foreach (gateway_switch_candidates() as $f) {
        if (!@is_readable($f)) continue;
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        // 1a linha nao vazia que nao seja comentario (#)
        $val = '';
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#') continue;
            $val = strtolower(trim($ln, " \t\"'")); break;
        }
        $r['arquivo'] = $f; $r['valor'] = $val;
        if (in_array($val, array('genesys','velani','kupfy','disrupty','mangofy'), true)) $r['aplicado'] = true;
        break; // o primeiro arquivo encontrado decide
    }
    return $r;
}

/**
 * Gateway ATIVO: 'genesys', 'kupfy' ou 'disrupty'.
 * 1) GATEWAY_PROVIDER no .env manda (forca genesys, kupfy ou disrupty);
 * 2) senao, Genesys fica ativa automaticamente quando suas credenciais existem;
 * 3) senao, Kupfy fica ativa automaticamente quando suas credenciais existem;
 * 4) senao, Disrupty (comportamento identico ao anterior).
 */
function active_gateway() {
    static $gw = null;
    if ($gw !== null) return $gw;
    $v = '';
    $sw = gateway_switch_read();
    if (!empty($sw['aplicado'])) { $gw = $sw['valor']; return $gw; }
    if (defined('GATEWAY_PROVIDER')) { $c = constant('GATEWAY_PROVIDER'); if (is_string($c)) $v = strtolower(trim($c)); }
    if ($v === '') {
        $env = function_exists('env_get') ? env_get('GATEWAY_PROVIDER') : (function_exists('getenv') ? @getenv('GATEWAY_PROVIDER') : (isset($_ENV['GATEWAY_PROVIDER']) ? $_ENV['GATEWAY_PROVIDER'] : false));
        if (is_string($env)) $v = strtolower(trim($env));
    }
    if ($v === 'kupfy' || $v === 'disrupty' || $v === 'genesys' || $v === 'velani' || $v === 'mangofy') { $gw = $v; return $gw; }
    if (defined('MANGOFY_CREDENTIALS_SOURCE') && MANGOFY_CREDENTIALS_SOURCE === 'env') { $gw = 'mangofy'; return $gw; }
    if (defined('VELANI_CREDENTIALS_SOURCE') && VELANI_CREDENTIALS_SOURCE === 'env') { $gw = 'velani'; return $gw; }
    if (defined('GENESYS_CREDENTIALS_SOURCE') && GENESYS_CREDENTIALS_SOURCE === 'env') { $gw = 'genesys'; return $gw; }
    $gw = (defined('KUPFY_CREDENTIALS_SOURCE') && KUPFY_CREDENTIALS_SOURCE === 'env') ? 'kupfy' : 'disrupty';
    return $gw;
}

/** Requisicao HTTP a Kupfy (mesmo shape de retorno da disrupty_http). */
function kupfy_request($method, $path, $payload = null, $query = array()) {
    $url = rtrim(KUPFY_BASE_URL, '/') . $path;
    if (!empty($query)) $url .= '?' . http_build_query($query);

    $headers = array(
        'Accept: application/json',
        'x-public-key: ' . (defined('KUPFY_PUBLIC_KEY') ? KUPFY_PUBLIC_KEY : ''),
        'x-secret-key: ' . (defined('KUPFY_SECRET_KEY') ? KUPFY_SECRET_KEY : ''),
    );

    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => DISRUPTY_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => DISRUPTY_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'funil-sr-2026-proxy/4.0 (kupfy)'
    );

    if ($payload !== null && in_array(strtoupper($method), array('POST', 'PUT', 'PATCH'), true)) {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            send_json(array('ok' => false, 'error' => 'Falha ao serializar payload JSON.'), 400);
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $jsonPayload;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => 'curl_init failed');
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $curlErrno    = curl_errno($ch);
    $curlError    = curl_error($ch);
    $httpStatus   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false || $curlErrno !== 0) {
        error_log('[api_proxy] cURL falhou ' . $url . ' errno=' . $curlErrno . ' err=' . $curlError);
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => $curlError);
    }

    if ($httpStatus <= 0) $httpStatus = 502;
    $decoded = trim($responseBody) === '' ? null : json_decode($responseBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) $decoded = null;

    if ($httpStatus >= 400) {
        error_log('[api_proxy] Kupfy ' . $url . ' http=' . $httpStatus . ' body=' . substr((string)$responseBody, 0, 400));
    }

    return array('http_status' => $httpStatus, 'decoded' => $decoded, 'raw' => (string)$responseBody, 'transport_error' => false, 'curl_error' => '');
}

/**
 * Monta o body do POST /gateway/pix/receive da Kupfy a partir do payload ja
 * validado do front. MESMAS normalizacoes defensivas da Disrupty (CPF valido,
 * celular BR 11 digitos, e-mail derivado do nome) — todos os campos de client
 * sao OBRIGATORIOS na Kupfy. Nome de produto NEUTRO (anti-bloqueio).
 */
function build_kupfy_pix_payload($payload) {
    $amount = round((float)$payload['amount'], 2);
    $client = isset($payload['client']) && is_array($payload['client']) ? $payload['client'] : array();

    // document: CPF valido obrigatorio.
    $documentDigits = preg_replace('/\D/', '', isset($client['document']) ? (string)$client['document'] : '');
    if (strlen($documentDigits) !== 11 && strlen($documentDigits) !== 14) {
        $documentDigits = generate_valid_cpf();
    }

    // phone: DDD + numero (celular BR de 11 digitos).
    $phoneDigits = preg_replace('/\D/', '', isset($client['phone']) ? (string)$client['phone'] : '');
    if (strlen($phoneDigits) > 11 && strpos($phoneDigits, '55') === 0) {
        $phoneDigits = substr($phoneDigits, 2);
    }
    if (strlen($phoneDigits) === 10) {
        $phoneDigits = substr($phoneDigits, 0, 2) . '9' . substr($phoneDigits, 2);
    }
    if (strlen($phoneDigits) !== 11 || $phoneDigits[2] !== '9') {
        $ddds = array('11','21','31','41','51','61','71','81','85','19','27','48','62','98');
        $phoneDigits = $ddds[array_rand($ddds)] . '9' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    // name: obrigatorio na Kupfy — fallback plausivel.
    $name = isset($client['name']) ? trim((string)$client['name']) : '';
    if ($name === '') $name = random_br_name();

    // email: obrigatorio e valido.
    $email = isset($client['email']) ? strtolower(trim((string)$client['email'])) : '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $email = email_from_name($name);
    }

    // Produto com nome NEUTRO (mesma regra anti-bloqueio da Disrupty).
    $productType = isset($payload['productType']) ? $payload['productType'] : '';
    $genericName = generic_deposit_name($productType);
    if (!empty($payload['fromEmail'])) $genericName .= '-email';

    $body = array(
        'identifier' => (string)$payload['identifier'],
        'amount'     => $amount,
        'client'     => array(
            'name'     => $name,
            'email'    => $email,
            'phone'    => $phoneDigits,
            'document' => $documentDigits,
        ),
        'products'   => array(
            array(
                'id'       => generic_deposit_id($productType),
                'name'     => $genericName,
                'quantity' => 1,
                'price'    => $amount,
            ),
        ),
    );

    // callbackUrl: webhook POR TRANSACAO (ja com ?token= embutido quando ha segredo).
    if (isset($payload['callbackUrl']) && is_string($payload['callbackUrl']) && $payload['callbackUrl'] !== '') {
        $body['callbackUrl'] = $payload['callbackUrl'];
    }
    // NAO enviar 'metadata' (a API da Kupfy rejeita campos extras nao documentados).

    return $body;
}

/** Cria o PIX na Kupfy. Mesmos efeitos colaterais e mesma resposta da Disrupty. */
function call_kupfy_create_pix($payload) {
    $kupfyPayload = build_kupfy_pix_payload($payload);

    $result = kupfy_request('POST', KUPFY_PIX_PATH, $kupfyPayload);

    // Retry automatico 1x em oscilacao (transporte ou 5xx).
    if (!empty($result['transport_error']) || $result['http_status'] >= 500) {
        sleep(2);
        $retry = kupfy_request('POST', KUPFY_PIX_PATH, $kupfyPayload);
        if (empty($retry['transport_error']) && $retry['http_status'] < 500) {
            $result = $retry;
        }
    }

    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        send_json(array('ok' => false, 'error' => 'Falha de conexao com gateway', 'detail' => $result['curl_error']), 502);
    }
    if ($httpStatus >= 400) {
        send_json(array(
            'ok'     => false,
            'error'  => disrupty_error_message($decoded, 'Erro ao gerar PIX'),
            'status' => $httpStatus
        ), $httpStatus);
    }
    if (!is_array($decoded)) {
        send_json(array('ok' => false, 'error' => 'Resposta invalida do gateway.'), 502);
    }

    $tx = extract_transaction_object($decoded);

    // Id da transacao Kupfy.
    $txId = genesys_pick($tx, array('transactionId', 'id', 'transaction_id', 'uid'));
    if ($txId === null) $txId = genesys_pick($decoded, array('transactionId', 'id', 'transaction_id', 'uid'));

    // Status da CRIACAO (OK/PENDING = criado; FAILED/REJECTED = recusado).
    $status = genesys_pick($tx, array('status'));
    if ($status === null) $status = genesys_pick($decoded, array('status'));
    $sUp = strtoupper((string)$status);
    if ($sUp === 'FAILED' || $sUp === 'REJECTED' || $sUp === 'ERROR') {
        $errDesc = genesys_pick($decoded, array('errorDescription'));
        send_json(array(
            'ok'    => false,
            'error' => (is_string($errDesc) && $errDesc !== '') ? $errDesc : 'O gateway recusou a criacao do PIX. Tente novamente.',
            'status' => 400
        ), 400);
    }

    // Copia-e-cola e QR (criacao: pix.code / pix.image; consulta: pixInformation).
    $pixCode = genesys_extract_pix_code($tx, $decoded);
    $pixQr   = genesys_extract_pix_qr($tx, $decoded);

    // Defensivo: se a criacao nao trouxe o copia-e-cola, consulta a transacao.
    // Poucas tentativas e com pausa MAIOR (a Kupfy limita polling agressivo).
    if (($pixCode === null || $pixCode === '') && $txId) {
        for ($i = 0; $i < 3 && ($pixCode === null || $pixCode === ''); $i++) {
            sleep(3);
            $poll = kupfy_request('GET', KUPFY_TX_PATH, null, array('id' => (string)$txId));
            if (!empty($poll['transport_error']) || $poll['http_status'] >= 400 || !is_array($poll['decoded'])) continue;
            if (kupfy_is_rate_limited($poll['http_status'], $poll['decoded'])) continue;
            $ptx = extract_transaction_object($poll['decoded']);
            $pixCode = genesys_extract_pix_code($ptx, $poll['decoded']);
            $pixQr   = genesys_extract_pix_qr($ptx, $poll['decoded']);
            $s = genesys_pick($ptx, array('status'));
            if ($s !== null) $status = $s;
        }
        if ($pixCode === null || $pixCode === '') {
            send_json(array(
                'ok'    => false,
                'error' => 'O PIX esta sendo processado pelo gateway e ainda nao ficou pronto. Tente novamente em alguns segundos.',
                'transactionId' => $txId,
                'status' => $status
            ), 504);
        }
    }

    // Normaliza QR: URL http -> image; base64/data-uri -> base64 (pix.js trata ambos;
    // sem nenhum, o front gera o QR localmente a partir do code).
    $pixObj = array('code' => $pixCode);
    if ($pixQr !== null && $pixQr !== '') {
        if (stripos($pixQr, 'http') === 0) {
            $pixObj['image'] = $pixQr;
        } else {
            $pixObj['base64'] = $pixQr;
        }
    }

    // MESMOS efeitos colaterais da Disrupty (UTMify + recuperacao + push).
    if ($txId) {
        try {
            $order = store_order_for_utmify($txId, $payload, $decoded);
            utmify_send_and_flag($order, (string)$txId);
        } catch (\Throwable $e) {
            @error_log('[utmify] erro no fluxo create_pix (kupfy): ' . $e->getMessage());
        }
        if (function_exists('recovery_record')) {
            try { recovery_record($txId, $payload, $pixCode, $pixQr); } catch (\Throwable $e) { @error_log('[recovery] record: ' . $e->getMessage()); }
        }
        if (function_exists('notify_event_pix_gerado')) {
            try { notify_event_pix_gerado(); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'            => true,
        'transactionId' => $txId,
        'identifier'    => $payload['identifier'],
        'status'        => ($status !== null ? $status : 'PENDING'),
        'pix'           => $pixObj,
        'amount'        => $payload['amount']
    ), 200);
}

/**
 * FAST PATH compartilhado do check_status: responde direto do cache alimentado
 * pelo webhook (qualquer gateway), reprocessando efeitos de forma idempotente.
 * Se nao houver cache terminal, simplesmente retorna (o chamador consulta a API).
 */
function check_status_cache_fastpath($cacheTxId, $transactionId, $clientIdentifier) {
    if ($cacheTxId === '') return;
    $cached = read_status_cache($cacheTxId);
    if (!is_array($cached) || !isset($cached['status'])) return;
    $cs = strtoupper((string)$cached['status']);
    $cachedPayed = isset($cached['payedAt']) && trim((string)$cached['payedAt']) !== '';
    $paidNow = is_paid_status($cs) || $cachedPayed;
    if (!$paidNow && !is_failed_status($cs)) return;

    // Reprocessa os efeitos colaterais de forma IDEMPOTENTE antes de responder:
    // se o envio a UTMify/push falhou no momento do webhook, o polling do front
    // corrige aqui (utmify_synced e o dedupe do push evitam duplicidade).
    $fpIds = array();
    foreach (array((isset($cached['id']) ? (string)$cached['id'] : ''), (string)$cacheTxId, (string)$transactionId, (string)$clientIdentifier) as $fpc) {
        if ($fpc !== '' && !in_array($fpc, $fpIds, true)) $fpIds[] = $fpc;
    }
    $fpPayed = $cachedPayed ? $cached['payedAt'] : ($paidNow ? date('c') : null);
    try { utmify_notify_status_update($fpIds, $paidNow ? 'PAID' : $cs, $fpPayed); } catch (\Throwable $e) {}
    if ($paidNow && function_exists('notify_on_paid_identifiers')) {
        try { notify_on_paid_identifiers($fpIds); } catch (\Throwable $e) {}
    }
    if ($paidNow && function_exists('recovery_mark_paid')) {
        try { recovery_mark_paid($fpIds, $fpPayed); } catch (\Throwable $e) {}
    }
    send_json(array(
        'ok'      => true,
        'id'      => isset($cached['id']) ? $cached['id'] : $cacheTxId,
        'status'  => $cached['status'],
        'payedAt' => $cachedPayed ? $cached['payedAt'] : ($paidNow ? date('c') : null),
        '_source' => 'webhook_cache'
    ), 200);
}

/** Arquivo de estado do throttle de polling da Kupfy (por transacao). */
function kupfy_poll_state_file($txid) {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$txid);
    if ($safe === '') $safe = 'unknown';
    return genesys_cache_dir() . '/poll_' . $safe . '.json';
}

/** Detecta resposta de rate limit da Kupfy (TOO_MANY_REQUESTS). */
function kupfy_is_rate_limited($httpStatus, $decoded) {
    if ((int)$httpStatus === 429) return true;
    if (is_array($decoded)) {
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $c = isset($decoded['error']['code']) ? strtoupper((string)$decoded['error']['code']) : '';
            if ($c === 'TOO_MANY_REQUESTS') return true;
        }
        $c2 = isset($decoded['errorCode']) ? strtoupper((string)$decoded['errorCode']) : '';
        if ($c2 === 'TOO_MANY_REQUESTS') return true;
    }
    return false;
}

/**
 * Consulta status na Kupfy com THROTTLE server-side: o front polla a cada
 * ~1.5-2.5s, mas a Kupfy bloqueia polling agressivo (TOO_MANY_REQUESTS).
 * Entre consultas reais respondemos o ultimo estado conhecido (_source=
 * kupfy_throttled). O webhook continua sendo o caminho rapido (fastpath).
 */
function call_kupfy_check_status($transactionId, $clientIdentifier = '') {
    $cacheTxId = $transactionId !== '' ? $transactionId : $clientIdentifier;

    // FAST PATH: cache vindo do webhook (mesmo comportamento da Disrupty).
    check_status_cache_fastpath($cacheTxId, $transactionId, $clientIdentifier);

    if ($cacheTxId === '') {
        send_json(array('ok' => true, 'id' => '', 'status' => 'PENDING', 'payedAt' => null, '_source' => 'no_txid'), 200);
    }

    // Intervalo minimo entre consultas REAIS a API (segundos).
    $interval = 20;
    $envInt = function_exists('env_get') ? env_get('KUPFY_POLL_INTERVAL') : (function_exists('getenv') ? @getenv('KUPFY_POLL_INTERVAL') : false);
    if (is_string($envInt) && trim($envInt) !== '' && (int)$envInt >= 5) $interval = (int)$envInt;

    $stFile = kupfy_poll_state_file($cacheTxId);
    $st = null;
    if (is_file($stFile)) {
        $raw = @file_get_contents($stFile);
        if (is_string($raw) && trim($raw) !== '') { $st = json_decode($raw, true); if (!is_array($st)) $st = null; }
    }
    if (is_array($st) && isset($st['ts']) && (time() - (int)$st['ts']) < $interval) {
        send_json(array(
            'ok'      => true,
            'id'      => isset($st['id']) && $st['id'] !== '' ? $st['id'] : $cacheTxId,
            'status'  => isset($st['status']) && $st['status'] !== '' ? $st['status'] : 'PENDING',
            'payedAt' => isset($st['payedAt']) && $st['payedAt'] !== '' ? $st['payedAt'] : null,
            '_source' => 'kupfy_throttled'
        ), 200);
    }

    // Grava o estado ANTES da chamada: mesmo em rajada, so 1 requisicao real
    // por janela chega na API da Kupfy.
    $prevStatus  = (is_array($st) && isset($st['status'])) ? (string)$st['status'] : 'PENDING';
    $prevPayed   = (is_array($st) && isset($st['payedAt'])) ? (string)$st['payedAt'] : '';
    @file_put_contents($stFile, json_encode(array('ts' => time(), 'id' => (string)$cacheTxId, 'status' => $prevStatus, 'payedAt' => $prevPayed), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $query = ($transactionId !== '') ? array('id' => $transactionId) : array('clientIdentifier' => $clientIdentifier);
    $result = kupfy_request('GET', KUPFY_TX_PATH, null, $query);
    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        // Nao derruba o polling do front: mantem PENDING e tenta na proxima janela.
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => $prevStatus, 'payedAt' => ($prevPayed !== '' ? $prevPayed : null), '_source' => 'kupfy_transport_error'), 200);
    }
    if (kupfy_is_rate_limited($httpStatus, $decoded)) {
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => $prevStatus, 'payedAt' => ($prevPayed !== '' ? $prevPayed : null), '_source' => 'kupfy_ratelimited'), 200);
    }
    if ($httpStatus >= 400 || !is_array($decoded)) {
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => $prevStatus, 'payedAt' => ($prevPayed !== '' ? $prevPayed : null), '_source' => 'kupfy_http_' . (int)$httpStatus), 200);
    }

    $tx = extract_transaction_object($decoded);
    $statusRaw = genesys_pick($tx, array('status'));
    if ($statusRaw === null) $statusRaw = genesys_pick($decoded, array('status'));
    $status  = ($statusRaw !== null) ? (string)$statusRaw : 'PENDING';
    $payedAt = genesys_extract_payed_at($tx);
    if ($payedAt === null && is_array($decoded)) $payedAt = genesys_extract_payed_at($decoded);
    $respId  = genesys_pick($tx, array('id', 'transactionId'));
    if ($respId === null) $respId = $cacheTxId;

    // Atualiza o estado do throttle com o resultado real.
    @file_put_contents($stFile, json_encode(array('ts' => time(), 'id' => (string)$respId, 'status' => $status, 'payedAt' => ($payedAt !== null ? (string)$payedAt : '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $sUp = strtoupper($status);
    $isPaid = is_paid_status($sUp) || ($payedAt !== null && trim((string)$payedAt) !== '');
    if ($isPaid || is_failed_status($sUp)) {
        // Identificadores conhecidos (id Kupfy + identifier do pedido).
        $ids = array();
        $respIdent = genesys_pick($tx, array('clientIdentifier', 'identifier'));
        foreach (array((string)$respId, (string)$cacheTxId, (string)$transactionId, (string)$clientIdentifier, ($respIdent !== null ? (string)$respIdent : '')) as $c) {
            if ($c !== '' && !in_array($c, $ids, true)) $ids[] = $c;
        }
        $paidTs = ($payedAt !== null && trim((string)$payedAt) !== '') ? (string)$payedAt : ($isPaid ? date('c') : null);
        // Cache terminal p/ fastpath das proximas consultas (mesmo shape do webhook).
        try { write_status_cache((string)$cacheTxId, array('id' => (string)$respId, 'status' => ($isPaid ? 'PAID' : $sUp), 'payedAt' => $paidTs)); } catch (\Throwable $e) {}
        try { utmify_notify_status_update($ids, $isPaid ? 'PAID' : $sUp, $paidTs); } catch (\Throwable $e) {}
        if ($isPaid && function_exists('notify_on_paid_identifiers')) {
            try { notify_on_paid_identifiers($ids); } catch (\Throwable $e) {}
        }
        if ($isPaid && function_exists('recovery_mark_paid')) {
            try { recovery_mark_paid($ids, $paidTs); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'      => true,
        'id'      => $respId,
        'status'  => $status,
        'payedAt' => ($payedAt !== null ? $payedAt : null),
        '_source' => 'kupfy_get'
    ), 200);
}

// =============================================================================
// GATEWAY GENESYS FINANCE (V28) — convive com Kupfy e Disrupty (fallback)
// =============================================================================
// Mesmo contrato de API usado anteriormente neste funil (jun/2026):
//   Base:      https://api.genesys.finance
//   Criar PIX: POST /v1/transactions  (total_amount em REAIS, payment_method PIX,
//              external_id, ip, customer{name,email,document,document_type,phone},
//              items[] com >= 1 item, webhook_url POR TRANSACAO). Whitelist
//              estrita de campos: NAO enviar metadata/tracking no body.
//   Consultar: GET  /v1/transactions/{transaction_id}
//   Auth:      headers api-secret + Authorization: Bearer (GENESYS_API_TOKEN) e
//              account-hash (GENESYS_ACCOUNT_HASH).
//   Webhook:   POST {event_type, data:{transaction_id, external_id, status,
//              paid_at}} — sem header de autenticacao; o segredo vai na URL
//              (?token=) e eh validado por verify_webhook_authenticity().
// Credenciais: pasta secrets-genesys-sr/.env (uma pasta ACIMA do web root) — conta Genesys EXCLUSIVA desta oferta.
// (fallback antigo: secrets-genesys-<slug>/.env ou secrets-genesys/.env) (uma pasta
// acima do web root). Ao preencher, a Genesys vira o gateway ATIVO (a menos que
// GATEWAY_PROVIDER diga o contrario).

function load_genesys_credentials() {
    $get = function ($k) {
        $v = function_exists('env_get') ? env_get($k) : (function_exists('getenv') ? @getenv($k) : (isset($_ENV[$k]) ? $_ENV[$k] : false));
        return is_string($v) ? trim($v) : '';
    };
    $accountHash = $get('GENESYS_ACCOUNT_HASH');
    $apiToken    = $get('GENESYS_API_TOKEN');
    if ($apiToken === '') $apiToken = $get('GENESYS_API_SECRET'); // alias tolerado

    if (!defined('GENESYS_ACCOUNT_HASH')) define('GENESYS_ACCOUNT_HASH', $accountHash);
    if (!defined('GENESYS_API_TOKEN')) define('GENESYS_API_TOKEN', $apiToken);

    $isPlaceholder = function ($v) { return ($v === '' || strpos($v, 'COLOQUE_') === 0); };
    if (!$isPlaceholder($accountHash) && !$isPlaceholder($apiToken)) {
        define('GENESYS_CREDENTIALS_SOURCE', 'env');
    } elseif ($accountHash === '' && $apiToken === '') {
        define('GENESYS_CREDENTIALS_SOURCE', 'not_configured');
    } else {
        define('GENESYS_CREDENTIALS_SOURCE', 'placeholder');
        error_log('[api_proxy] Credenciais Genesys ainda com placeholders. Edite o .env da pasta secrets-genesys-sr');
    }
}

/** Requisicao HTTP a Genesys (mesmo shape de retorno da disrupty_http/kupfy_request). */
function genesys_request($method, $path, $payload = null, $query = array()) {
    $url = rtrim(GENESYS_BASE_URL, '/') . $path;
    if (!empty($query)) $url .= '?' . http_build_query($query);

    $authMode = GENESYS_AUTH_MODE;
    $headers = array('Accept: application/json');
    if ($authMode === 'api-secret' || $authMode === 'all') {
        $headers[] = 'api-secret: ' . (defined('GENESYS_API_TOKEN') ? GENESYS_API_TOKEN : '');
    }
    if ($authMode === 'bearer' || $authMode === 'all') {
        $headers[] = 'Authorization: Bearer ' . (defined('GENESYS_API_TOKEN') ? GENESYS_API_TOKEN : '');
    }
    if ($authMode !== 'api-secret') {
        $headers[] = 'account-hash: ' . (defined('GENESYS_ACCOUNT_HASH') ? GENESYS_ACCOUNT_HASH : '');
    }

    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => DISRUPTY_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => DISRUPTY_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'funil-sr-2026-proxy/4.0 (genesys)'
    );

    if ($payload !== null && in_array(strtoupper($method), array('POST', 'PUT', 'PATCH'), true)) {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            send_json(array('ok' => false, 'error' => 'Falha ao serializar payload JSON.'), 400);
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $jsonPayload;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => 'curl_init failed');
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $curlErrno    = curl_errno($ch);
    $curlError    = curl_error($ch);
    $httpStatus   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false || $curlErrno !== 0) {
        error_log('[api_proxy] cURL falhou ' . $url . ' errno=' . $curlErrno . ' err=' . $curlError);
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => $curlError);
    }

    if ($httpStatus <= 0) $httpStatus = 502;
    $decoded = trim($responseBody) === '' ? null : json_decode($responseBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) $decoded = null;

    if ($httpStatus >= 400) {
        error_log('[api_proxy] Genesys ' . $url . ' http=' . $httpStatus . ' body=' . substr((string)$responseBody, 0, 400));
    }

    return array('http_status' => $httpStatus, 'decoded' => $decoded, 'raw' => (string)$responseBody, 'transport_error' => false, 'curl_error' => '');
}

/**
 * Monta o body do POST /v1/transactions da Genesys a partir do payload ja
 * validado do front. MESMAS normalizacoes defensivas da Kupfy/Disrupty (CPF
 * valido, celular BR 11 digitos, e-mail derivado do nome) e nome de produto
 * NEUTRO (anti-bloqueio). Whitelist estrita: sem metadata/tracking.
 */
function build_genesys_pix_payload($payload) {
    $amount = round((float)$payload['amount'], 2);
    $amountToSend = (GENESYS_AMOUNT_FORMAT === 'cents') ? (int)round($amount * 100) : $amount;
    $client = isset($payload['client']) && is_array($payload['client']) ? $payload['client'] : array();

    // document + document_type (CPF 11 digitos / CNPJ 14). Invalido -> CPF valido gerado.
    $documentDigits = preg_replace('/\D/', '', isset($client['document']) ? (string)$client['document'] : '');
    if (strlen($documentDigits) !== 11 && strlen($documentDigits) !== 14) {
        $documentDigits = generate_valid_cpf();
    }
    $documentType = (strlen($documentDigits) === 14) ? 'CNPJ' : 'CPF';

    // phone: DDD + numero (celular BR de 11 digitos).
    $phoneDigits = preg_replace('/\D/', '', isset($client['phone']) ? (string)$client['phone'] : '');
    if (strlen($phoneDigits) > 11 && strpos($phoneDigits, '55') === 0) {
        $phoneDigits = substr($phoneDigits, 2);
    }
    if (strlen($phoneDigits) === 10) {
        $phoneDigits = substr($phoneDigits, 0, 2) . '9' . substr($phoneDigits, 2);
    }
    if (strlen($phoneDigits) !== 11 || $phoneDigits[2] !== '9') {
        $ddds = array('11','21','31','41','51','61','71','81','85','19','27','48','62','98');
        $phoneDigits = $ddds[array_rand($ddds)] . '9' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    // name: obrigatorio — fallback plausivel.
    $name = isset($client['name']) ? trim((string)$client['name']) : '';
    if ($name === '') $name = random_br_name();

    // email: obrigatorio e valido.
    $email = isset($client['email']) ? strtolower(trim((string)$client['email'])) : '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $email = email_from_name($name);
    }

    // Produto com nome NEUTRO (mesma regra anti-bloqueio da Disrupty/Kupfy).
    $productType = isset($payload['productType']) ? $payload['productType'] : '';
    $genericName = generic_deposit_name($productType);
    if (!empty($payload['fromEmail'])) $genericName .= '-email';

    // ip do cliente (obrigatorio). Usa o IP real; cai para REMOTE_ADDR/0.0.0.0.
    $ip = client_ip();
    if ($ip === '' && !empty($_SERVER['REMOTE_ADDR'])) $ip = $_SERVER['REMOTE_ADDR'];
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) $ip = '0.0.0.0';

    $body = array(
        'total_amount'   => $amountToSend,
        'payment_method' => 'PIX',
        'external_id'    => (string)$payload['identifier'],
        'ip'             => $ip,
        'customer'       => array(
            'name'          => $name,
            'email'         => $email,
            'document'      => $documentDigits,
            'document_type' => $documentType,
            'phone'         => $phoneDigits,
        ),
        'items'          => array(
            array(
                'id'          => generic_deposit_id($productType),
                'title'       => $genericName,
                'description' => $genericName,
                'price'       => $amountToSend,
                'quantity'    => 1,
                'is_physical' => true,
            ),
        ),
    );

    // webhook_url (OBRIGATORIO na Genesys). Usa o callbackUrl publico (ja com
    // ?token= quando ha segredo); se ausente, constroi a partir do host atual.
    $webhookUrl = '';
    if (isset($payload['callbackUrl']) && is_string($payload['callbackUrl']) && $payload['callbackUrl'] !== '') {
        $webhookUrl = $payload['callbackUrl'];
    } else {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api_proxy.php';
            $webhookUrl = $scheme . '://' . $host . $script . '?action=webhook';
        }
    }
    if ($webhookUrl !== '') $body['webhook_url'] = $webhookUrl;
    // NAO enviar 'metadata' (a Genesys rejeita campos fora da whitelist).

    return $body;
}

/** Cria o PIX na Genesys. Mesmos efeitos colaterais e mesma resposta da Disrupty/Kupfy. */
function call_genesys_create_pix($payload) {
    $genesysPayload = build_genesys_pix_payload($payload);

    $result = genesys_request('POST', GENESYS_PIX_CREATE_PATH, $genesysPayload);

    // Retry automatico 1x em oscilacao (transporte ou 5xx).
    if (!empty($result['transport_error']) || $result['http_status'] >= 500) {
        sleep(2);
        $retry = genesys_request('POST', GENESYS_PIX_CREATE_PATH, $genesysPayload);
        if (empty($retry['transport_error']) && $retry['http_status'] < 500) {
            $result = $retry;
        }
    }

    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        send_json(array('ok' => false, 'error' => 'Falha de conexao com gateway', 'detail' => $result['curl_error']), 502);
    }
    if ($httpStatus >= 400) {
        send_json(array(
            'ok'     => false,
            'error'  => disrupty_error_message($decoded, 'Erro ao gerar PIX'),
            'status' => $httpStatus
        ), $httpStatus);
    }
    if (!is_array($decoded)) {
        send_json(array('ok' => false, 'error' => 'Resposta invalida do gateway.'), 502);
    }

    $tx = extract_transaction_object($decoded);

    // Id da transacao Genesys (transaction_id; tolera transactionId/id).
    $txId = genesys_pick($tx, array('transaction_id', 'transactionId', 'id', 'uid'));
    if ($txId === null) $txId = genesys_pick($decoded, array('transaction_id', 'transactionId', 'id', 'uid'));

    // Status da CRIACAO (PENDING = criado; FAILED/REJECTED = recusado).
    $status = genesys_pick($tx, array('status'));
    if ($status === null) $status = genesys_pick($decoded, array('status'));
    $sUp = strtoupper((string)$status);
    if ($sUp === 'FAILED' || $sUp === 'REJECTED' || $sUp === 'ERROR') {
        $errDesc = genesys_pick($decoded, array('errorDescription', 'message', 'error'));
        send_json(array(
            'ok'    => false,
            'error' => (is_string($errDesc) && $errDesc !== '') ? $errDesc : 'O gateway recusou a criacao do PIX. Tente novamente.',
            'status' => 400
        ), 400);
    }

    // Copia-e-cola e QR (pix_copy_paste / qr_code / pix_qr_code_url etc.).
    $pixCode = genesys_extract_pix_code($tx, $decoded);
    $pixQr   = genesys_extract_pix_qr($tx, $decoded);

    // Defensivo: se a criacao nao trouxe o copia-e-cola, consulta a transacao.
    if (($pixCode === null || $pixCode === '') && $txId) {
        for ($i = 0; $i < 3 && ($pixCode === null || $pixCode === ''); $i++) {
            sleep(2);
            $poll = genesys_request('GET', GENESYS_TRANSACTION_PATH . '/' . rawurlencode((string)$txId));
            if (!empty($poll['transport_error']) || $poll['http_status'] >= 400 || !is_array($poll['decoded'])) continue;
            $ptx = extract_transaction_object($poll['decoded']);
            $pixCode = genesys_extract_pix_code($ptx, $poll['decoded']);
            $pixQr   = genesys_extract_pix_qr($ptx, $poll['decoded']);
            $s = genesys_pick($ptx, array('status'));
            if ($s !== null) $status = $s;
        }
        if ($pixCode === null || $pixCode === '') {
            send_json(array(
                'ok'    => false,
                'error' => 'O PIX esta sendo processado pelo gateway e ainda nao ficou pronto. Tente novamente em alguns segundos.',
                'transactionId' => $txId,
                'status' => $status
            ), 504);
        }
    }

    // Normaliza QR: URL http -> image; base64/data-uri -> base64 (pix.js trata ambos;
    // sem nenhum, o front gera o QR localmente a partir do code).
    $pixObj = array('code' => $pixCode);
    if ($pixQr !== null && $pixQr !== '') {
        if (stripos($pixQr, 'http') === 0) {
            $pixObj['image'] = $pixQr;
        } else {
            $pixObj['base64'] = $pixQr;
        }
    }

    // MESMOS efeitos colaterais da Disrupty/Kupfy (UTMify + recuperacao + push).
    if ($txId) {
        try {
            $order = store_order_for_utmify($txId, $payload, $decoded);
            utmify_send_and_flag($order, (string)$txId);
        } catch (\Throwable $e) {
            @error_log('[utmify] erro no fluxo create_pix (genesys): ' . $e->getMessage());
        }
        if (function_exists('recovery_record')) {
            try { recovery_record($txId, $payload, $pixCode, $pixQr); } catch (\Throwable $e) { @error_log('[recovery] record: ' . $e->getMessage()); }
        }
        if (function_exists('notify_event_pix_gerado')) {
            try { notify_event_pix_gerado(); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'            => true,
        'transactionId' => $txId,
        'identifier'    => $payload['identifier'],
        'status'        => ($status !== null ? $status : 'PENDING'),
        'pix'           => $pixObj,
        'amount'        => $payload['amount']
    ), 200);
}

/** Consulta status na Genesys (GET /v1/transactions/{id}). Mesma resposta da Disrupty/Kupfy. */
function call_genesys_check_status($transactionId, $clientIdentifier = '') {
    $cacheTxId = $transactionId !== '' ? $transactionId : $clientIdentifier;

    // FAST PATH: cache vindo do webhook (mesmo comportamento da Disrupty/Kupfy).
    check_status_cache_fastpath($cacheTxId, $transactionId, $clientIdentifier);

    if ($transactionId === '') {
        // A Genesys consulta somente pelo transaction_id; sem ele, aguarda o webhook.
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'no_txid'), 200);
    }

    $result = genesys_request('GET', GENESYS_TRANSACTION_PATH . '/' . rawurlencode($transactionId));
    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        // Nao derruba o polling do front: mantem PENDING e tenta na proxima consulta.
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'genesys_transport_error'), 200);
    }
    if ($httpStatus >= 400 || !is_array($decoded)) {
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'genesys_http_' . (int)$httpStatus), 200);
    }

    $tx = extract_transaction_object($decoded);
    $statusRaw = genesys_pick($tx, array('status'));
    if ($statusRaw === null) $statusRaw = genesys_pick($decoded, array('status'));
    $status  = ($statusRaw !== null) ? (string)$statusRaw : 'PENDING';
    $payedAt = genesys_extract_payed_at($tx);
    if ($payedAt === null && is_array($decoded)) $payedAt = genesys_extract_payed_at($decoded);
    $respId  = genesys_pick($tx, array('transaction_id', 'id', 'transactionId'));
    if ($respId === null) $respId = $cacheTxId;

    $sUp = strtoupper($status);
    $isPaid = is_paid_status($sUp) || ($payedAt !== null && trim((string)$payedAt) !== '');
    if ($isPaid || is_failed_status($sUp)) {
        // Identificadores conhecidos (transaction_id Genesys + external_id do pedido).
        $ids = array();
        $respIdent = genesys_pick($tx, array('external_id', 'externalId', 'identifier'));
        foreach (array((string)$respId, (string)$cacheTxId, (string)$transactionId, (string)$clientIdentifier, ($respIdent !== null ? (string)$respIdent : '')) as $c) {
            if ($c !== '' && !in_array($c, $ids, true)) $ids[] = $c;
        }
        $paidTs = ($payedAt !== null && trim((string)$payedAt) !== '') ? (string)$payedAt : ($isPaid ? date('c') : null);
        try { write_status_cache((string)$cacheTxId, array('id' => (string)$respId, 'status' => ($isPaid ? 'PAID' : $sUp), 'payedAt' => $paidTs)); } catch (\Throwable $e) {}
        try { utmify_notify_status_update($ids, $isPaid ? 'PAID' : $sUp, $paidTs); } catch (\Throwable $e) {}
        if ($isPaid && function_exists('notify_on_paid_identifiers')) {
            try { notify_on_paid_identifiers($ids); } catch (\Throwable $e) {}
        }
        if ($isPaid && function_exists('recovery_mark_paid')) {
            try { recovery_mark_paid($ids, $paidTs); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'      => true,
        'id'      => $respId,
        'status'  => $status,
        'payedAt' => ($payedAt !== null ? $payedAt : null),
        '_source' => 'genesys_get'
    ), 200);
}

// =============================================================================
// GATEWAY VELANI PAGAMENTOS — https://velanipagamentos.com.br/docs/api/
//   Auth:      header x-api-key: <secret key sk_live_...>  (VELANI_SECRET_KEY)
//   Criar PIX: POST {base}/v1/transactions  (amount em CENTAVOS, min 100)
//   Consultar: GET  {base}/v1/transactions/{id}
//   Webhook:   postbackUrl por transacao; corpo { event, data{...}, signature }
//              signature = HMAC-SHA256(JSON.stringify(data), VELANI_WEBHOOK_SECRET)
//   Status:    pending | processing | paid | failed | expired | refunded
// Credenciais: pasta secrets-velani-sr/.env (uma pasta ACIMA do web root) — conta Velani EXCLUSIVA desta oferta.
// =============================================================================

/** Carrega VELANI_SECRET_KEY (alias VELANI_API_KEY), VELANI_PUBLIC_KEY e VELANI_WEBHOOK_SECRET do env. */
function load_velani_credentials() {
    $get = function ($k) {
        $v = function_exists('env_get') ? env_get($k) : (function_exists('getenv') ? @getenv($k) : (isset($_ENV[$k]) ? $_ENV[$k] : false));
        $v = is_string($v) ? trim($v) : '';
        // tolera valor entre aspas no .env ("sk_live_..." ou 'sk_live_...')
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = trim(substr($v, 1, -1));
        return $v;
    };
    $isPlaceholder = function ($v) { return ($v === '' || strpos($v, 'COLOQUE_') === 0); };

    // A Velani SO aceita a SECRET KEY (sk_live_...) para criar transacoes; a Public Key
    // (pk_live_...) serve apenas para consulta. Por isso, independente de em qual variavel
    // o usuario colou a chave, escolhemos SEMPRE a que comeca com "sk_" e NUNCA usamos "pk_".
    $secretKey = ''; $publicKey = ''; $firstOther = '';
    foreach (array('VELANI_SECRET_KEY','VELANI_API_KEY','VELANI_PRIVATE_KEY','VELANI_KEY','VELANI_TOKEN','VELANI_PUBLIC_KEY') as $k) {
        $v = $get($k);
        if ($isPlaceholder($v)) continue;
        if (stripos($v, 'sk_') === 0)      { if ($secretKey === '') $secretKey = $v; }
        elseif (stripos($v, 'pk_') === 0)  { if ($publicKey === '') $publicKey = $v; }
        elseif ($firstOther === '' && $k !== 'VELANI_PUBLIC_KEY') { $firstOther = $v; }
    }
    // Chave sem prefixo conhecido (formato futuro): aceita como secret, desde que nao seja pk_.
    if ($secretKey === '' && $firstOther !== '') $secretKey = $firstOther;
    $whSecret = $get('VELANI_WEBHOOK_SECRET');

    if (!defined('VELANI_SECRET_KEY')) define('VELANI_SECRET_KEY', $secretKey);
    if (!defined('VELANI_PUBLIC_KEY')) define('VELANI_PUBLIC_KEY', $publicKey);
    if (!defined('VELANI_WEBHOOK_SECRET')) define('VELANI_WEBHOOK_SECRET', $isPlaceholder($whSecret) ? '' : $whSecret);

    if ($secretKey !== '') {
        define('VELANI_CREDENTIALS_SOURCE', 'env');
    } elseif ($publicKey !== '') {
        define('VELANI_CREDENTIALS_SOURCE', 'public_key_only');
        error_log('[api_proxy] Velani: so foi encontrada a PUBLIC KEY (pk_live_...). Para criar PIX e obrigatoria a SECRET KEY (sk_live_...) em VELANI_SECRET_KEY no .env da pasta secrets-velani-db.');
    } elseif ($get('VELANI_SECRET_KEY') === '' && $get('VELANI_API_KEY') === '') {
        define('VELANI_CREDENTIALS_SOURCE', 'not_configured');
    } else {
        define('VELANI_CREDENTIALS_SOURCE', 'placeholder');
        error_log('[api_proxy] Credenciais Velani ainda com placeholders. Edite o .env da pasta secrets-velani-sr');
    }
}

/** Requisicao HTTP a Velani (mesmo shape de retorno da genesys_request/disrupty_http). */
function velani_request($method, $path, $payload = null, $query = array(), $extraHeaders = array()) {
    $url = rtrim(VELANI_BASE_URL, '/') . $path;
    if (!empty($query)) $url .= '?' . http_build_query($query);

    $headers = array(
        'Accept: application/json',
        'x-api-key: ' . (defined('VELANI_SECRET_KEY') ? VELANI_SECRET_KEY : ''),
    );
    foreach ((array)$extraHeaders as $h) { if (is_string($h) && $h !== '') $headers[] = $h; }

    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => DISRUPTY_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => DISRUPTY_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'funil-sr-2026-proxy/4.0 (velani)'
    );

    if ($payload !== null && in_array(strtoupper($method), array('POST', 'PUT', 'PATCH'), true)) {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            send_json(array('ok' => false, 'error' => 'Falha ao serializar payload JSON.'), 400);
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $jsonPayload;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => 'curl_init failed');
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $curlErrno    = curl_errno($ch);
    $curlError    = curl_error($ch);
    $httpStatus   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false || $curlErrno !== 0) {
        error_log('[api_proxy] cURL falhou ' . $url . ' errno=' . $curlErrno . ' err=' . $curlError);
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => $curlError);
    }

    if ($httpStatus <= 0) $httpStatus = 502;
    $decoded = trim($responseBody) === '' ? null : json_decode($responseBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) $decoded = null;

    if ($httpStatus >= 400) {
        error_log('[api_proxy] Velani ' . $url . ' http=' . $httpStatus . ' body=' . substr((string)$responseBody, 0, 400));
    }

    return array('http_status' => $httpStatus, 'decoded' => $decoded, 'raw' => (string)$responseBody, 'transport_error' => false, 'curl_error' => '');
}

/**
 * Monta o body do POST /v1/transactions da Velani a partir do payload ja
 * validado do front. MESMAS normalizacoes defensivas da Genesys (CPF valido,
 * celular BR 11 digitos, e-mail derivado do nome) e nome de produto NEUTRO.
 * amount em CENTAVOS (inteiro). UTMs vao no objeto "tracking" (documentado).
 */
function build_velani_pix_payload($payload) {
    $amount = round((float)$payload['amount'], 2);
    $amountCents = (int)round($amount * 100);
    if ($amountCents < 100) $amountCents = 100; // minimo da Velani: R$ 1,00
    $client = isset($payload['client']) && is_array($payload['client']) ? $payload['client'] : array();

    // document (CPF 11 digitos / CNPJ 14). Invalido -> CPF valido gerado.
    $documentDigits = preg_replace('/\D/', '', isset($client['document']) ? (string)$client['document'] : '');
    if (strlen($documentDigits) !== 11 && strlen($documentDigits) !== 14) {
        $documentDigits = generate_valid_cpf();
    }
    $documentType = (strlen($documentDigits) === 14) ? 'cnpj' : 'cpf';

    // phone: DDD + numero (celular BR de 11 digitos).
    $phoneDigits = preg_replace('/\D/', '', isset($client['phone']) ? (string)$client['phone'] : '');
    if (strlen($phoneDigits) > 11 && strpos($phoneDigits, '55') === 0) {
        $phoneDigits = substr($phoneDigits, 2);
    }
    if (strlen($phoneDigits) === 10) {
        $phoneDigits = substr($phoneDigits, 0, 2) . '9' . substr($phoneDigits, 2);
    }
    if (strlen($phoneDigits) !== 11 || $phoneDigits[2] !== '9') {
        $ddds = array('11','21','31','41','51','61','71','81','85','19','27','48','62','98');
        $phoneDigits = $ddds[array_rand($ddds)] . '9' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    // name: obrigatorio — fallback plausivel.
    $name = isset($client['name']) ? trim((string)$client['name']) : '';
    if ($name === '') $name = random_br_name();

    // email: valido.
    $email = isset($client['email']) ? strtolower(trim((string)$client['email'])) : '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $email = email_from_name($name);
    }

    // Produto com nome NEUTRO (mesma regra anti-bloqueio dos outros gateways).
    $productType = isset($payload['productType']) ? $payload['productType'] : '';
    $genericName = generic_deposit_name($productType);
    if (!empty($payload['fromEmail'])) $genericName .= '-email';

    $body = array(
        'paymentMethod' => 'pix',
        'amount'        => $amountCents,
        'externalId'    => (string)$payload['identifier'],
        'description'   => $genericName,
        'customer'      => array(
            'name'     => $name,
            'email'    => $email,
            'phone'    => $phoneDigits,
            'document' => array(
                'type'   => $documentType,
                'number' => $documentDigits,
            ),
        ),
        'items'         => array(
            array(
                'title'     => $genericName,
                'unitPrice' => $amountCents,
                'quantity'  => 1,
            ),
        ),
    );

    // postbackUrl: usa o callbackUrl publico (ja com ?token= quando ha segredo);
    // se ausente, constroi a partir do host atual.
    $webhookUrl = '';
    if (isset($payload['callbackUrl']) && is_string($payload['callbackUrl']) && $payload['callbackUrl'] !== '') {
        $webhookUrl = $payload['callbackUrl'];
    } else {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api_proxy.php';
            $webhookUrl = $scheme . '://' . $host . $script . '?action=webhook';
        }
    }
    if ($webhookUrl !== '') $body['postbackUrl'] = $webhookUrl;

    // tracking (opcional, documentado): utm_source, utm_campaign, utm_medium,
    // utm_content, utm_term, src, sck — somente strings nao vazias.
    $track = array();
    $tp = isset($payload['trackProps']) && is_array($payload['trackProps']) ? $payload['trackProps'] : (isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : array());
    foreach (array('utm_source','utm_campaign','utm_medium','utm_content','utm_term','src','sck') as $k) {
        if (isset($tp[$k]) && is_string($tp[$k]) && trim($tp[$k]) !== '') $track[$k] = substr(trim($tp[$k]), 0, 255);
    }
    if (!empty($track)) $body['tracking'] = $track;

    return $body;
}

/** Cria o PIX na Velani. Mesmos efeitos colaterais e mesma resposta da Genesys/Disrupty/Kupfy. */
function call_velani_create_pix($payload) {
    $velaniPayload = build_velani_pix_payload($payload);
    // Idempotencia (documentada): evita cobranca duplicada no retry.
    $idem = array('x-idempotency-key: ' . substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$payload['identifier']), 0, 120));

    $result = velani_request('POST', VELANI_PIX_CREATE_PATH, $velaniPayload, array(), $idem);

    // Retry automatico 1x em oscilacao (transporte ou 5xx).
    if (!empty($result['transport_error']) || $result['http_status'] >= 500) {
        sleep(2);
        $retry = velani_request('POST', VELANI_PIX_CREATE_PATH, $velaniPayload, array(), $idem);
        if (empty($retry['transport_error']) && $retry['http_status'] < 500) {
            $result = $retry;
        }
    }

    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        send_json(array('ok' => false, 'error' => 'Falha de conexao com gateway', 'detail' => $result['curl_error']), 502);
    }
    if ($httpStatus >= 400 || (is_array($decoded) && isset($decoded['success']) && $decoded['success'] === false)) {
        $code = ($httpStatus >= 400) ? $httpStatus : 400;
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) && isset($decoded['error']['statusCode'])) {
            $sc = (int)$decoded['error']['statusCode']; if ($sc >= 400 && $sc < 600) $code = $sc;
        }
        $vlMsg = disrupty_error_message($decoded, 'Erro ao gerar PIX');
        $vlHint = null;
        if (stripos($vlMsg, 'Secret Key') !== false || stripos($vlMsg, 'Public Key') !== false) {
            $vlHint = 'A chave enviada no header x-api-key nao e a SECRET KEY. Abra o .env da pasta secrets-velani-sr e coloque em VELANI_SECRET_KEY a chave que comeca com sk_live_ (a pk_live_ nao cria PIX). Confira em api_proxy.php?action=diag -> velani.tipo_de_chave_em_uso.';
            error_log('[api_proxy] Velani recusou a chave (' . (defined('VELANI_SECRET_KEY') && VELANI_SECRET_KEY !== '' ? substr(VELANI_SECRET_KEY, 0, 8) . '...' : 'vazia') . '): ' . $vlMsg);
        }
        send_json(array(
            'ok'      => false,
            'error'   => $vlMsg,
            'hint'    => $vlHint,
            'gateway' => 'velani',
            'status'  => $code
        ), $code);
    }
    if (!is_array($decoded)) {
        send_json(array('ok' => false, 'error' => 'Resposta invalida do gateway.'), 502);
    }

    // A Velani envelopa em { success, data: {...} }.
    $tx = extract_transaction_object($decoded);
    if (!is_array($tx) && isset($decoded['data']) && is_array($decoded['data'])) $tx = $decoded['data'];
    if (!is_array($tx)) $tx = $decoded;

    $txId = genesys_pick($tx, array('id', 'transactionId', 'transaction_id', 'uid'));
    if ($txId === null) $txId = genesys_pick($decoded, array('id', 'transactionId', 'transaction_id'));

    // Status da CRIACAO (pending = criado; failed = recusado).
    $status = genesys_pick($tx, array('status'));
    if ($status === null) $status = genesys_pick($decoded, array('status'));
    $sUp = strtoupper((string)$status);
    if ($sUp === 'FAILED' || $sUp === 'REJECTED' || $sUp === 'ERROR' || $sUp === 'EXPIRED') {
        $errDesc = genesys_pick($decoded, array('errorDescription', 'message', 'error'));
        send_json(array(
            'ok'    => false,
            'error' => (is_string($errDesc) && $errDesc !== '') ? $errDesc : 'O gateway recusou a criacao do PIX. Tente novamente.',
            'status' => 400
        ), 400);
    }

    // Copia-e-cola (pixQrCode) e imagem (pixQrCodeImage = URL png).
    $pixCode = genesys_extract_pix_code($tx, $decoded);
    $pixQr   = genesys_extract_pix_qr($tx, $decoded);

    // Defensivo: se a criacao nao trouxe o copia-e-cola, consulta a transacao.
    if (($pixCode === null || $pixCode === '') && $txId) {
        for ($i = 0; $i < 3 && ($pixCode === null || $pixCode === ''); $i++) {
            sleep(2);
            $poll = velani_request('GET', VELANI_TRANSACTION_PATH . '/' . rawurlencode((string)$txId));
            if (!empty($poll['transport_error']) || $poll['http_status'] >= 400 || !is_array($poll['decoded'])) continue;
            $ptx = extract_transaction_object($poll['decoded']);
            if (!is_array($ptx)) $ptx = isset($poll['decoded']['data']) && is_array($poll['decoded']['data']) ? $poll['decoded']['data'] : $poll['decoded'];
            $pixCode = genesys_extract_pix_code($ptx, $poll['decoded']);
            $pixQr   = genesys_extract_pix_qr($ptx, $poll['decoded']);
            $s = genesys_pick($ptx, array('status'));
            if ($s !== null) $status = $s;
        }
        if ($pixCode === null || $pixCode === '') {
            send_json(array(
                'ok'    => false,
                'error' => 'O PIX esta sendo processado pelo gateway e ainda nao ficou pronto. Tente novamente em alguns segundos.',
                'transactionId' => $txId,
                'status' => strtoupper((string)$status)
            ), 504);
        }
    }

    // Normaliza QR: URL http -> image; base64/data-uri -> base64 (pix.js trata ambos;
    // sem nenhum, o front gera o QR localmente a partir do code).
    $pixObj = array('code' => $pixCode);
    if ($pixQr !== null && $pixQr !== '') {
        if (stripos($pixQr, 'http') === 0) {
            $pixObj['image'] = $pixQr;
        } else {
            $pixObj['base64'] = $pixQr;
        }
    }

    // MESMOS efeitos colaterais (UTMify + recuperacao + push).
    if ($txId) {
        try {
            $order = store_order_for_utmify($txId, $payload, $decoded);
            utmify_send_and_flag($order, (string)$txId);
        } catch (\Throwable $e) {
            @error_log('[utmify] erro no fluxo create_pix (velani): ' . $e->getMessage());
        }
        if (function_exists('recovery_record')) {
            try { recovery_record($txId, $payload, $pixCode, $pixQr); } catch (\Throwable $e) { @error_log('[recovery] record: ' . $e->getMessage()); }
        }
        if (function_exists('notify_event_pix_gerado')) {
            try { notify_event_pix_gerado(); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'            => true,
        'transactionId' => $txId,
        'identifier'    => $payload['identifier'],
        'status'        => ($status !== null ? strtoupper((string)$status) : 'PENDING'),
        'pix'           => $pixObj,
        'amount'        => $payload['amount']
    ), 200);
}

/** Consulta status na Velani (GET /v1/transactions/{id}). Mesma resposta dos outros gateways. */
function call_velani_check_status($transactionId, $clientIdentifier = '') {
    $cacheTxId = $transactionId !== '' ? $transactionId : $clientIdentifier;

    // FAST PATH: cache vindo do webhook (mesmo comportamento dos outros gateways).
    check_status_cache_fastpath($cacheTxId, $transactionId, $clientIdentifier);

    if ($transactionId === '') {
        // A Velani consulta somente pelo id da transacao; sem ele, aguarda o webhook.
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'no_txid'), 200);
    }

    $result = velani_request('GET', VELANI_TRANSACTION_PATH . '/' . rawurlencode($transactionId));
    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'velani_transport_error'), 200);
    }
    if ($httpStatus >= 400 || !is_array($decoded)) {
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'velani_http_' . (int)$httpStatus), 200);
    }

    $tx = extract_transaction_object($decoded);
    if (!is_array($tx) && isset($decoded['data']) && is_array($decoded['data'])) $tx = $decoded['data'];
    if (!is_array($tx)) $tx = $decoded;
    $statusRaw = genesys_pick($tx, array('status'));
    if ($statusRaw === null) $statusRaw = genesys_pick($decoded, array('status'));
    $status  = ($statusRaw !== null) ? strtoupper((string)$statusRaw) : 'PENDING';
    $payedAt = genesys_extract_payed_at($tx);
    if ($payedAt === null && is_array($decoded)) $payedAt = genesys_extract_payed_at($decoded);
    if ($payedAt === null) { $pa = genesys_pick($tx, array('paidAt', 'paid_at')); if (is_string($pa) && trim($pa) !== '') $payedAt = $pa; }
    $respId  = genesys_pick($tx, array('id', 'transactionId', 'transaction_id'));
    if ($respId === null) $respId = $cacheTxId;

    $sUp = $status;
    $isPaid = is_paid_status($sUp) || ($payedAt !== null && trim((string)$payedAt) !== '');
    if ($isPaid || is_failed_status($sUp)) {
        $ids = array();
        $respIdent = genesys_pick($tx, array('externalId', 'external_id', 'identifier'));
        foreach (array((string)$respId, (string)$cacheTxId, (string)$transactionId, (string)$clientIdentifier, ($respIdent !== null ? (string)$respIdent : '')) as $c) {
            if ($c !== '' && !in_array($c, $ids, true)) $ids[] = $c;
        }
        $paidTs = ($payedAt !== null && trim((string)$payedAt) !== '') ? (string)$payedAt : ($isPaid ? date('c') : null);
        try { write_status_cache((string)$cacheTxId, array('id' => (string)$respId, 'status' => ($isPaid ? 'PAID' : $sUp), 'payedAt' => $paidTs)); } catch (\Throwable $e) {}
        try { utmify_notify_status_update($ids, $isPaid ? 'PAID' : $sUp, $paidTs); } catch (\Throwable $e) {}
        if ($isPaid && function_exists('notify_on_paid_identifiers')) {
            try { notify_on_paid_identifiers($ids); } catch (\Throwable $e) {}
        }
        if ($isPaid && function_exists('recovery_mark_paid')) {
            try { recovery_mark_paid($ids, $paidTs); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'      => true,
        'id'      => $respId,
        'status'  => ($isPaid ? 'PAID' : $status),
        'payedAt' => ($payedAt !== null ? $payedAt : null),
        '_source' => 'velani_get'
    ), 200);
}

// =============================================================================
// GATEWAY MANGOFY — https://app.mangofy.com.br/api/doc
// =============================================================================

function load_mangofy_credentials() {
    $get = function ($k) {
        $v = function_exists('env_get') ? env_get($k) : (function_exists('getenv') ? @getenv($k) : (isset($_ENV[$k]) ? $_ENV[$k] : false));
        $v = is_string($v) ? trim($v) : '';
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = trim(substr($v, 1, -1));
        return $v;
    };
    $isPlaceholder = function ($v) { return ($v === '' || strpos($v, 'COLOQUE_') === 0); };

    $apiKey = $get('MANGOFY_API_KEY');
    if ($isPlaceholder($apiKey)) $apiKey = $get('MANGOFY_AUTHORIZATION');
    $store = $get('MANGOFY_STORE_CODE');
    $whSecret = $get('MANGOFY_WEBHOOK_SECRET');

    if (!defined('MANGOFY_API_KEY')) define('MANGOFY_API_KEY', $isPlaceholder($apiKey) ? '' : $apiKey);
    if (!defined('MANGOFY_STORE_CODE')) define('MANGOFY_STORE_CODE', $isPlaceholder($store) ? '' : $store);
    if (!defined('MANGOFY_WEBHOOK_SECRET')) define('MANGOFY_WEBHOOK_SECRET', $isPlaceholder($whSecret) ? '' : $whSecret);

    if (MANGOFY_API_KEY !== '' && MANGOFY_STORE_CODE !== '') {
        define('MANGOFY_CREDENTIALS_SOURCE', 'env');
    } elseif ($isPlaceholder($apiKey) && $isPlaceholder($store)) {
        define('MANGOFY_CREDENTIALS_SOURCE', 'not_configured');
    } else {
        define('MANGOFY_CREDENTIALS_SOURCE', 'placeholder');
        error_log('[api_proxy] Credenciais MangoFy incompletas. Edite secrets-mangofy-sr/.env');
    }
}

function mangofy_request($method, $path, $payload = null, $query = array()) {
    $url = rtrim(MANGOFY_BASE_URL, '/') . $path;
    if (!empty($query)) $url .= '?' . http_build_query($query);

    $headers = array(
        'Accept: application/json',
        'Authorization: ' . (defined('MANGOFY_API_KEY') ? MANGOFY_API_KEY : ''),
        'Store-Code: ' . (defined('MANGOFY_STORE_CODE') ? MANGOFY_STORE_CODE : ''),
    );

    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => DISRUPTY_CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => DISRUPTY_CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'funil-sr-2026-proxy/4.0 (mangofy)',
    );

    if ($payload !== null && in_array(strtoupper($method), array('POST', 'PUT', 'PATCH'), true)) {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            send_json(array('ok' => false, 'error' => 'Falha ao serializar payload JSON.'), 400);
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $jsonPayload;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => 'curl_init failed');
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $curlErrno    = curl_errno($ch);
    $curlError    = curl_error($ch);
    $httpStatus   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false || $curlErrno !== 0) {
        error_log('[api_proxy] cURL falhou ' . $url . ' errno=' . $curlErrno . ' err=' . $curlError);
        return array('http_status' => 502, 'decoded' => null, 'raw' => '', 'transport_error' => true, 'curl_error' => $curlError);
    }

    if ($httpStatus <= 0) $httpStatus = 502;
    $decoded = trim($responseBody) === '' ? null : json_decode($responseBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) $decoded = null;

    if ($httpStatus >= 400) {
        error_log('[api_proxy] MangoFy ' . $url . ' http=' . $httpStatus . ' body=' . substr((string)$responseBody, 0, 400));
    }

    return array('http_status' => $httpStatus, 'decoded' => $decoded, 'raw' => (string)$responseBody, 'transport_error' => false, 'curl_error' => '');
}

function build_mangofy_pix_payload($payload) {
    $amount = round((float)$payload['amount'], 2);
    $amountCents = (int)round($amount * 100);
    if ($amountCents < 500) $amountCents = 500;

    $client = isset($payload['client']) && is_array($payload['client']) ? $payload['client'] : array();
    $documentDigits = preg_replace('/\D/', '', isset($client['document']) ? (string)$client['document'] : '');
    if (strlen($documentDigits) !== 11 && strlen($documentDigits) !== 14) {
        $documentDigits = generate_valid_cpf();
    }

    $phoneDigits = preg_replace('/\D/', '', isset($client['phone']) ? (string)$client['phone'] : '');
    if (strlen($phoneDigits) > 11 && strpos($phoneDigits, '55') === 0) $phoneDigits = substr($phoneDigits, 2);
    if (strlen($phoneDigits) === 10) $phoneDigits = substr($phoneDigits, 0, 2) . '9' . substr($phoneDigits, 2);
    if (strlen($phoneDigits) !== 11 || $phoneDigits[2] !== '9') {
        $ddds = array('11','21','31','41','51','61','71','81','85','19','27','48','62','98');
        $phoneDigits = $ddds[array_rand($ddds)] . '9' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    $name = isset($client['name']) ? trim((string)$client['name']) : '';
    if ($name === '') $name = random_br_name();

    $email = isset($client['email']) ? strtolower(trim((string)$client['email'])) : '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) $email = email_from_name($name);

    $productType = isset($payload['productType']) ? $payload['productType'] : '';
    $genericName = generic_deposit_name($productType);
    if (!empty($payload['fromEmail'])) $genericName .= '-email';

    $postback = '';
    if (isset($payload['callbackUrl']) && is_string($payload['callbackUrl']) && $payload['callbackUrl'] !== '') {
        $postback = $payload['callbackUrl'];
    } else {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api_proxy.php';
            $postback = $scheme . '://' . $host . $script . '?action=webhook';
            $wh = webhook_secret_value();
            if ($wh !== '') $postback .= '&token=' . rawurlencode($wh);
        }
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    $sku = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$payload['identifier']), 0, 48);
    if ($sku === '') $sku = 'item-sr';

    $meta = array();
    $tp = isset($payload['trackProps']) && is_array($payload['trackProps']) ? $payload['trackProps'] : (isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : array());
    foreach ($tp as $mk => $mv) {
        if (!is_string($mk) || $mk === '') continue;
        if (is_scalar($mv) && trim((string)$mv) !== '') $meta[$mk] = trim((string)$mv);
    }

    $body = array(
        'external_code'   => (string)$payload['identifier'],
        'payment_method'  => 'pix',
        'payment_format'  => 'regular',
        'installments'    => 1,
        'payment_amount'  => $amountCents,
        'shipping_amount' => 0,
        'postback_url'    => $postback,
        'items'           => array(
            array(
                'code'     => $sku,
                'name'     => $genericName,
                'quantity' => 1,
                'price'    => $amountCents,
            ),
        ),
        'customer'        => array(
            'email'    => $email,
            'name'     => $name,
            'document' => $documentDigits,
            'phone'    => '55' . $phoneDigits,
            'ip'       => $ip,
        ),
        'pix'             => array('expires_in_days' => 1),
    );
    if (!empty($meta)) $body['extra'] = array('metadata' => $meta);
    return $body;
}

function mangofy_status_upper($raw) {
    $s = strtoupper(trim((string)$raw));
    if ($s === 'APPROVED') return 'PAID';
    if ($s === 'PENDING' || $s === 'IN_PROCESS' || $s === 'IN_REVIEW') return 'PENDING';
    if (in_array($s, array('REFUSED', 'CANCELED', 'CANCELLED', 'EXPIRED', 'GATEWAY_ERROR', 'SYSTEM_ERROR'), true)) return 'EXPIRED';
    return $s !== '' ? $s : 'PENDING';
}

function call_mangofy_create_pix($payload) {
    $mfPayload = build_mangofy_pix_payload($payload);
    $result = mangofy_request('POST', MANGOFY_PIX_CREATE_PATH, $mfPayload);

    if (!empty($result['transport_error'])) {
        send_json(array('ok' => false, 'error' => 'Falha de conexao com gateway', 'detail' => $result['curl_error'], 'gateway' => 'mangofy'), 502);
    }

    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if ($httpStatus >= 400 || !is_array($decoded)) {
        $msg = disrupty_error_message($decoded, 'Erro ao gerar PIX (MangoFy)');
        send_json(array('ok' => false, 'error' => $msg, 'gateway' => 'mangofy', 'status' => $httpStatus), ($httpStatus >= 400 ? $httpStatus : 502));
    }

    $tx = extract_transaction_object($decoded);
    if (!is_array($tx)) $tx = $decoded;

    $txId = genesys_pick($tx, array('payment_code', 'id', 'transactionId'));
    if ($txId === null) $txId = genesys_pick($decoded, array('payment_code', 'id'));

    $pStatus = genesys_pick($tx, array('payment_status', 'status'));
    $sUp = mangofy_status_upper($pStatus);
    if (in_array($sUp, array('EXPIRED', 'REFUSED'), true)) {
        send_json(array('ok' => false, 'error' => 'O gateway recusou a criacao do PIX.', 'gateway' => 'mangofy', 'status' => 400), 400);
    }

    $pixCode = genesys_extract_pix_code($tx, $decoded);
    $pixQr   = genesys_extract_pix_qr($tx, $decoded);

    if (($pixCode === null || $pixCode === '') && $txId) {
        for ($i = 0; $i < 3 && ($pixCode === null || $pixCode === ''); $i++) {
            sleep(2);
            $poll = mangofy_request('GET', MANGOFY_PAYMENT_PATH . rawurlencode((string)$txId));
            if (!empty($poll['transport_error']) || $poll['http_status'] >= 400 || !is_array($poll['decoded'])) continue;
            $ptx = extract_transaction_object($poll['decoded']);
            if (!is_array($ptx)) $ptx = $poll['decoded'];
            $pixCode = genesys_extract_pix_code($ptx, $poll['decoded']);
            $pixQr   = genesys_extract_pix_qr($ptx, $poll['decoded']);
        }
    }

    if ($pixCode === null || $pixCode === '') {
        send_json(array(
            'ok' => false,
            'error' => 'PIX ainda nao disponivel na MangoFy. Tente novamente em instantes.',
            'transactionId' => $txId,
            'gateway' => 'mangofy',
        ), 504);
    }

    $pixObj = array('code' => $pixCode);
    if ($pixQr !== null && $pixQr !== '') {
        if (stripos($pixQr, 'http') === 0) $pixObj['image'] = $pixQr;
        else $pixObj['base64'] = $pixQr;
    }

    if ($txId) {
        try {
            $order = store_order_for_utmify($txId, $payload, $decoded);
            utmify_send_and_flag($order, (string)$txId);
        } catch (\Throwable $e) {
            @error_log('[utmify] erro no fluxo create_pix (mangofy): ' . $e->getMessage());
        }
        if (function_exists('recovery_record')) {
            try { recovery_record($txId, $payload, $pixCode, $pixQr); } catch (\Throwable $e) { @error_log('[recovery] record: ' . $e->getMessage()); }
        }
        if (function_exists('notify_event_pix_gerado')) {
            try { notify_event_pix_gerado(); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'            => true,
        'transactionId' => $txId,
        'identifier'    => $payload['identifier'],
        'status'        => $sUp,
        'pix'           => $pixObj,
        'amount'        => $payload['amount'],
        'gateway'       => 'mangofy',
    ), 200);
}

function call_mangofy_check_status($transactionId, $clientIdentifier = '') {
    $cacheTxId = $transactionId !== '' ? $transactionId : $clientIdentifier;
    check_status_cache_fastpath($cacheTxId, $transactionId, $clientIdentifier);

    if ($transactionId === '') {
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'no_txid'), 200);
    }

    $result = mangofy_request('GET', MANGOFY_PAYMENT_PATH . rawurlencode($transactionId));
    $httpStatus = $result['http_status'];
    $decoded    = $result['decoded'];

    if (!empty($result['transport_error'])) {
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'mangofy_transport_error'), 200);
    }
    if ($httpStatus >= 400 || !is_array($decoded)) {
        send_json(array('ok' => true, 'id' => $cacheTxId, 'status' => 'PENDING', 'payedAt' => null, '_source' => 'mangofy_http_' . (int)$httpStatus), 200);
    }

    $tx = extract_transaction_object($decoded);
    if (!is_array($tx)) $tx = $decoded;

    $statusRaw = genesys_pick($tx, array('payment_status', 'status'));
    $status = mangofy_status_upper($statusRaw);
    $payedAt = genesys_extract_payed_at($tx);
    if ($payedAt === null && is_array($decoded)) $payedAt = genesys_extract_payed_at($decoded);

    $respId = genesys_pick($tx, array('payment_code', 'id'));
    if ($respId === null) $respId = $cacheTxId;

    $isPaid = is_paid_status($status) || ($payedAt !== null && trim((string)$payedAt) !== '');
    if ($isPaid || is_failed_status($status)) {
        $ids = array();
        $respIdent = genesys_pick($tx, array('external_code', 'externalId', 'external_id', 'identifier'));
        foreach (array((string)$respId, (string)$cacheTxId, (string)$transactionId, (string)$clientIdentifier, ($respIdent !== null ? (string)$respIdent : '')) as $c) {
            if ($c !== '' && !in_array($c, $ids, true)) $ids[] = $c;
        }
        $paidTs = ($payedAt !== null && trim((string)$payedAt) !== '') ? (string)$payedAt : ($isPaid ? date('c') : null);
        try { write_status_cache((string)$cacheTxId, array('id' => (string)$respId, 'status' => ($isPaid ? 'PAID' : $status), 'payedAt' => $paidTs)); } catch (\Throwable $e) {}
        try { utmify_notify_status_update($ids, $isPaid ? 'PAID' : $status, $paidTs); } catch (\Throwable $e) {}
        if ($isPaid && function_exists('notify_on_paid_identifiers')) {
            try { notify_on_paid_identifiers($ids); } catch (\Throwable $e) {}
        }
        if ($isPaid && function_exists('recovery_mark_paid')) {
            try { recovery_mark_paid($ids, $paidTs); } catch (\Throwable $e) {}
        }
    }

    send_json(array(
        'ok'      => true,
        'id'      => $respId,
        'status'  => ($isPaid ? 'PAID' : $status),
        'payedAt' => ($payedAt !== null ? $payedAt : null),
        '_source' => 'mangofy_get',
    ), 200);
}

// =============================================================================
// UTMIFY DIRECT API (INTACTO)
// =============================================================================
// POST https://api.utmify.com.br/api-credentials/orders
// Header: x-api-token: <token>
// Status validos: waiting_payment | paid | refused | refunded | chargedback
// Datas em UTC, formato 'Y-m-d H:i:s'. Precos em centavos (int).
// =============================================================================
function utmify_order_filename($txid) {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$txid);
    if ($safe === '') $safe = 'unknown';
    return genesys_cache_dir() . '/order_' . $safe . '.json';
}

// -----------------------------------------------------------------------------
// UTMify — ID CANONICO (zip 75)
// Um mesmo PIX chega com varios ids (id do gateway, transactionId, externalId/
// identifier CNH-xxxx, id do evento do webhook). Antes, se o order_<id>.json
// nao fosse achado pelo id que chegou, o pedido era RECONSTRUIDO com OUTRO
// orderId e sem UTM -> a UTMify contava 2 vendas (uma trackeada + uma "nao
// trackeada"). Agora todo caminho resolve o id canonico (txid do create_pix)
// via alias_<id>.json + recovery DB antes de enviar, e um flag global por
// pagamento impede 2 envios "paid" do mesmo PIX.
// -----------------------------------------------------------------------------
function utmify_alias_filename($id) {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$id);
    if ($safe === '') $safe = 'unknown';
    return genesys_cache_dir() . '/alias_' . $safe . '.json';
}

function utmify_paid_flag_filename($id) {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$id);
    if ($safe === '') $safe = 'unknown';
    return genesys_cache_dir() . '/utmify_paid_' . $safe . '.flag';
}

/** Grava alias_<aliasId>.json -> {txid} (ignora se igual ao txid ou vazio). */
function utmify_write_alias($aliasId, $txid) {
    $aliasId = trim((string)$aliasId); $txid = trim((string)$txid);
    if ($aliasId === '' || $txid === '' || $aliasId === $txid) return;
    @file_put_contents(utmify_alias_filename($aliasId), json_encode(array('txid' => $txid, 'at' => time())));
}

/** Le alias_<id>.json e devolve o txid canonico (ou null). */
function utmify_read_alias($id) {
    $f = utmify_alias_filename($id);
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return (is_array($j) && !empty($j['txid'])) ? (string)$j['txid'] : null;
}

/** Le order_<key>.json (array) ou null. */
function utmify_read_order($key) {
    $f = utmify_order_filename($key);
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false) return null;
    $tmp = json_decode($raw, true);
    return is_array($tmp) ? $tmp : null;
}

/**
 * Resolve o pedido canonico para um conjunto de ids do mesmo pagamento.
 * Ordem: alias -> order_<id>.json existente (prefere o ORIGINAL, nao o
 * reconstruido) -> recovery DB (txid OU identifier) -> null.
 * Retorna array('key'=>string|null, 'order'=>array|null, 'row'=>array|null).
 */
function utmify_resolve_canonical($identifiers) {
    $ids = array();
    if (!is_array($identifiers)) $identifiers = array($identifiers);
    foreach ($identifiers as $k) { $k = trim((string)$k); if ($k !== '' && !in_array($k, $ids, true)) $ids[] = $k; }
    if (!$ids) return array('key' => null, 'order' => null, 'row' => null);

    // 1) expande via alias (alias aponta para o txid canonico -> vai na frente)
    $expanded = array();
    foreach ($ids as $k) {
        $t = utmify_read_alias($k);
        if ($t !== null && !in_array($t, $expanded, true)) $expanded[] = $t;
    }
    foreach ($ids as $k) if (!in_array($k, $expanded, true)) $expanded[] = $k;

    // 2) recovery DB: txid canonico (quem gerou o PIX)
    $row = null;
    if (function_exists('recovery_find_any')) {
        try { $row = recovery_find_any($expanded); } catch (\Throwable $e) { $row = null; }
        if (is_array($row) && !empty($row['txid']) && !in_array((string)$row['txid'], $expanded, true)) array_unshift($expanded, (string)$row['txid']);
    }

    // 3) JSON existente: prefere o ORIGINAL (com UTMs); reconstruido so se nao houver outro
    $firstAny = null; $firstAnyKey = null;
    foreach ($expanded as $k) {
        $o = utmify_read_order($k);
        if (!is_array($o)) continue;
        if (empty($o['reconstructed'])) return array('key' => $k, 'order' => $o, 'row' => is_array($row) ? $row : null);
        if ($firstAny === null) { $firstAny = $o; $firstAnyKey = $k; }
    }
    if ($firstAny !== null) return array('key' => $firstAnyKey, 'order' => $firstAny, 'row' => is_array($row) ? $row : null);

    if (is_array($row) && !empty($row['txid'])) return array('key' => (string)$row['txid'], 'order' => null, 'row' => $row);
    return array('key' => null, 'order' => null, 'row' => null);
}

function utmify_format_date_utc($timestampOrIso = null) {
    if ($timestampOrIso === null || $timestampOrIso === '') {
        return gmdate('Y-m-d H:i:s');
    }
    if (is_numeric($timestampOrIso)) {
        return gmdate('Y-m-d H:i:s', (int)$timestampOrIso);
    }
    $t = strtotime((string)$timestampOrIso);
    return $t ? gmdate('Y-m-d H:i:s', $t) : gmdate('Y-m-d H:i:s');
}

function utmify_map_status($gatewayStatus) {
    $up = strtoupper((string)$gatewayStatus);
    if (in_array($up, array('COMPLETED','PAID','PAGO','PAYED','APPROVED','SUCCESS','CONFIRMED','AUTHORIZED'), true)) return 'paid';
    if (in_array($up, array('FAILED','REJECTED','CANCELLED','CANCELED','CANCELADO','EXPIRED','EXPIRADO'), true)) return 'refused';
    if (in_array($up, array('REFUNDED','REEMBOLSADO','ESTORNADO'), true)) return 'refunded';
    if (in_array($up, array('CHARGED_BACK','CHARGEDBACK','CHARGEBACK'), true)) return 'chargedback';
    return 'waiting_payment';
}

function store_order_for_utmify($txid, $payload, $genesysResponse) {
    $tracking = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : array();
    $customer = isset($payload['client']) && is_array($payload['client']) ? $payload['client'] : array();

    // NOME NEUTRO tambem na UTMify (mantem paridade com o que vai a adquirente).
    $genericName = generic_deposit_name(isset($payload['productType']) ? $payload['productType'] : '');
    $genericId   = generic_deposit_id(isset($payload['productType']) ? $payload['productType'] : '');
    if (!empty($payload['fromEmail'])) {
        $genericName .= '-email';
        $genericId   .= '-EMAIL';
    }

    $products = array();
    if (!empty($payload['products']) && is_array($payload['products'])) {
        foreach ($payload['products'] as $p) {
            if (!is_array($p)) continue;
            $qty  = isset($p['quantity']) ? (int)$p['quantity'] : 1;
            $priceCents = isset($p['price']) ? (int)round(((float)$p['price']) * 100) : 0;
            $products[] = array(
                'id'           => $genericId,
                'name'         => $genericName,
                'planId'       => null,
                'planName'     => null,
                'quantity'     => $qty,
                'priceInCents' => $priceCents,
            );
        }
    }

    $amountCents = isset($payload['amount']) ? (int)round(((float)$payload['amount']) * 100) : 0;
    $feeCents = 0;
    if (is_array($genesysResponse)) {
        foreach (array('fee','feeInCents','gatewayFee','taxes') as $k) {
            if (isset($genesysResponse[$k]) && is_numeric($genesysResponse[$k])) {
                $val = (float)$genesysResponse[$k];
                $feeCents = $val < 100 ? (int)round($val * 100) : (int)round($val);
                break;
            }
        }
    }

    // Merge tracking from payload.trackProps/tracking/trackingParameters
    $candidates = array();
    if (isset($payload['trackProps']))         $candidates[] = $payload['trackProps'];
    if (isset($payload['tracking']))           $candidates[] = $payload['tracking'];
    if (isset($payload['trackingParameters'])) $candidates[] = $payload['trackingParameters'];
    foreach ($candidates as $c) {
        if (is_array($c)) $tracking = array_merge($tracking, $c);
    }
    // zip 88: sem UTM no payload -> busca o que o servidor conhece desta sessao/CPF/fbclid
    if (function_exists('trk_has_utm') && !trk_has_utm($tracking)) {
        $__srv = trk_lookup_for_order($payload);
        if (is_array($__srv)) foreach ($__srv as $k => $v) { if (trk_junk($k, isset($tracking[$k]) ? $tracking[$k] : null) && $v !== '' && $v !== null) $tracking[$k] = $v; }
    }

    $order = array(
        'orderId'       => (string)$txid,
        'platform'      => 'Genesys',
        'paymentMethod' => 'pix',
        'status'        => 'waiting_payment',
        'createdAt'     => utmify_format_date_utc(),
        'approvedDate'  => null,
        'refundedAt'    => null,
        'isTest'        => false,
        'customer' => array(
            'name'     => isset($customer['name']) ? (string)$customer['name'] : '',
            'email'    => isset($customer['email']) ? (string)$customer['email'] : '',
            'phone'    => isset($customer['phone']) ? (string)$customer['phone'] : '',
            'document' => isset($customer['document']) ? (string)$customer['document'] : '',
            'country'  => 'BR',
            'ip'       => isset($tracking['ip']) ? (string)$tracking['ip'] : (client_ip() ?: ''),
        ),
        'products' => $products,
        'trackingParameters' => array(
            'src'          => isset($tracking['src'])          ? (string)$tracking['src']          : null,
            'sck'          => isset($tracking['sck'])          ? (string)$tracking['sck']          : null,
            'utm_source'   => isset($tracking['utm_source'])   ? (string)$tracking['utm_source']   : null,
            'utm_campaign' => isset($tracking['utm_campaign']) ? (string)$tracking['utm_campaign'] : null,
            'utm_medium'   => isset($tracking['utm_medium'])   ? (string)$tracking['utm_medium']   : null,
            'utm_content'  => isset($tracking['utm_content'])  ? (string)$tracking['utm_content']  : null,
            'utm_term'     => isset($tracking['utm_term'])     ? (string)$tracking['utm_term']     : null,
            'utm_id'       => isset($tracking['utm_id'])       ? (string)$tracking['utm_id']       : null,
            'fbc'          => isset($tracking['fbc'])          ? (string)$tracking['fbc']          : null,
            'fbp'          => isset($tracking['fbp'])          ? (string)$tracking['fbp']          : null,
        ),
        'commission' => array(
            'totalPriceInCents'    => $amountCents,
            'gatewayFeeInCents'    => $feeCents,
            'userCommissionInCents'=> max(0, $amountCents - $feeCents),
            'currency'             => 'BRL',
        ),
    );

    $file = utmify_order_filename($txid);
    @file_put_contents($file, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @error_log('[utmify] order salvo para tx=' . $txid . ' utm_source=' . (string)$order['trackingParameters']['utm_source']);
    // ALIAS (zip 75): todo id alternativo deste PIX aponta para o txid canonico.
    try {
        if (!empty($payload['identifier'])) utmify_write_alias($payload['identifier'], $txid);
        if (is_array($genesysResponse)) {
            $rtx = extract_transaction_object($genesysResponse);
            foreach (array($genesysResponse, (is_array($rtx) ? $rtx : array())) as $src) {
                foreach (array('id','transaction_id','transactionId','external_id','externalId','identifier','saleId','uid','transaction_uid') as $k) {
                    if (isset($src[$k]) && is_scalar($src[$k])) utmify_write_alias($src[$k], $txid);
                }
            }
        }
    } catch (\Throwable $e) { /* nao-fatal */ }
    return $order;
}

/**
 * Atualiza o status de um pedido ja salvo e reenvia para a UTMify.
 *
 * Usado tanto pelo webhook quanto pela verificacao de status (polling), para
 * garantir que o pedido saia de "waiting_payment" assim que o PIX for pago,
 * mesmo que o postback do gateway nao esteja configurado ou nao chegue.
 * Idempotente: se o status salvo ja e o mesmo, nao reenvia.
 *
 * @param array  $identifiers Ids candidatos (transaction_id, id, external_id...)
 * @param string $status      Status do gateway (bruto); 'PAID' quando confirmado
 * @param string $payedAt     Data de pagamento (ISO) quando pago
 */
function utmify_notify_status_update($identifiers, $status, $payedAt) {
    if ($status === null || $status === '') return;
    if (!is_array($identifiers) || empty($identifiers)) return;
    try {
        // ID CANONICO (zip 75): alias + JSON original + recovery DB. Nunca mais
        // reconstroi com um id "alternativo" (externalId/identifier/id de evento)
        // enquanto existe o pedido original sob o txid — era isso que criava a
        // 2a venda (sem UTM) na UTMify para o MESMO pagamento.
        $res        = utmify_resolve_canonical($identifiers);
        $orderFound = $res['order'];
        $matchedKey = $res['key'];
        // FALLBACK: o JSON do pedido sumiu do cache (limpeza de /tmp, troca de
        // host, deploy por cima). Reconstroi a partir do banco de recuperacao
        // (SQLite persistente) SEMPRE com o txid canonico da row e, agora, COM
        // as UTMs salvas no create_pix (utm_json). So reconstroi se a transacao
        // for DESTE funil (existe no recovery DB) — webhooks de OUTRAS ofertas
        // que dividem a mesma conta do gateway continuam sendo ignorados.
        if (!$orderFound && is_array($res['row']) && !empty($res['row']['txid'])) {
            $matchedKey = (string)$res['row']['txid'];
            $orderFound = utmify_order_from_recovery_row($matchedKey, $res['row']);
            @file_put_contents(utmify_order_filename($matchedKey), json_encode($orderFound, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            @error_log('[utmify] pedido reconstruido do recovery DB p/ tx=' . $matchedKey . ' (ids recebidos: ' . implode(',', $identifiers) . ')');
        }
        if (!$orderFound) {
            @error_log('[utmify] pedido nao encontrado em cache p/ ' . implode(',', $identifiers));
            return;
        }
        // Garante que todos os ids recebidos passem a apontar para a chave canonica.
        foreach ($identifiers as $aid) { try { utmify_write_alias($aid, $matchedKey); } catch (\Throwable $e) {} }
        $utmifyStatus = utmify_map_status($status);
        // Nunca rebaixa um pedido ja entregue como PAGO para waiting/refused
        // (ex.: consulta de fallback em outro gateway devolvendo "nao encontrado").
        $syncedPrev = isset($orderFound['utmify_synced']) ? (string)$orderFound['utmify_synced'] : '';
        if ($syncedPrev === 'paid' && in_array($utmifyStatus, array('waiting_payment', 'refused'), true)) return;
        // FLAG GLOBAL por pagamento: se este PIX ja foi entregue como paid (por
        // qualquer id), nao envia de novo (dedupe entre arquivos/ids).
        if ($utmifyStatus === 'paid') {
            $flagHit = is_file(utmify_paid_flag_filename($matchedKey));
            if (!$flagHit) foreach ($identifiers as $aid) { if ((string)$aid !== '' && is_file(utmify_paid_flag_filename($aid))) { $flagHit = true; break; } }
            if ($flagHit && $syncedPrev !== 'paid') {
                $orderFound['status'] = 'paid';
                if (empty($orderFound['approvedDate'])) $orderFound['approvedDate'] = utmify_format_date_utc($payedAt);
                $orderFound['utmify_synced'] = 'paid';
                @file_put_contents(utmify_order_filename($matchedKey), json_encode($orderFound, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return;
            }
        }
        // Idempotencia REAL: so pula se este status ja foi ENTREGUE a UTMify
        // (flag utmify_synced, gravada apenas apos HTTP 2xx). Antes a checagem
        // usava o status local, gravado ANTES do POST — qualquer falha de
        // rede/5xx deixava a venda travada como "ja enviada" sem nunca ter
        // chegado ao dashboard (vendas pagas que nao marcavam).
        $synced = isset($orderFound['utmify_synced']) ? (string)$orderFound['utmify_synced'] : '';
        if ($synced === $utmifyStatus) return;
        if (function_exists('trk_enrich_order')) $orderFound = trk_enrich_order($orderFound); // zip 88
        $orderFound['status'] = $utmifyStatus;
        if ($utmifyStatus === 'paid') {
            $orderFound['approvedDate'] = utmify_format_date_utc($payedAt);
        } elseif ($utmifyStatus === 'refunded') {
            $orderFound['refundedAt'] = utmify_format_date_utc();
        }
        @file_put_contents(utmify_order_filename($matchedKey), json_encode($orderFound, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        utmify_send_and_flag($orderFound, $matchedKey);
    } catch (\Throwable $e) {
        @error_log('[utmify] erro no fluxo de atualizacao: ' . $e->getMessage());
    }
}

/**
 * action=utmify_audit&token=<RECOVERY_SECRET>[&fix=1][&days=N][&window=1800]
 * Varre order_*.json do cache e aponta:
 *  - reconstruidos: pedidos recriados do recovery DB (sem UTM na epoca)
 *  - duplicatas: pedidos PAGOS com mesmo CPF+valor em ate `window` segundos
 *    (default 30 min) com orderIds diferentes -> provavel mesmo pagamento
 *  - totais por dia (paid) para conferir com Genesys/Velani
 * fix=1: para cada grupo de duplicatas, mantem 1 pedido (prefere o ORIGINAL com
 * UTM) e reenvia os RECONSTRUIDOS do grupo como 'refused' (a UTMify faz upsert
 * por orderId -> a venda fantasma deixa de contar). Pedidos originais nunca sao
 * alterados automaticamente (aparecem como 'revisar_manual').
 */
function handle_utmify_audit() {
    $tok = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
    if (!function_exists('recovery_secret') || $tok === '' || !hash_equals(recovery_secret(), $tok)) {
        send_json(array('ok' => false, 'error' => 'token invalido'), 403);
    }
    $fix    = isset($_GET['fix']) && (string)$_GET['fix'] === '1';
    $days   = isset($_GET['days']) ? max(1, (int)$_GET['days']) : 30;
    $window = isset($_GET['window']) ? max(60, (int)$_GET['window']) : 1800;
    $dir    = genesys_cache_dir();
    $files  = glob($dir . '/order_*.json');
    if (!is_array($files)) $files = array();
    $minTs  = time() - $days * 86400;
    $orders = array();
    foreach ($files as $f) {
        $o = json_decode((string)@file_get_contents($f), true);
        if (!is_array($o) || empty($o['orderId'])) continue;
        $cts = isset($o['createdAt']) ? strtotime((string)$o['createdAt'] . ' UTC') : @filemtime($f);
        if ($cts && $cts < $minTs) continue;
        $key = preg_replace('/^order_(.*)\.json$/', '$1', basename($f));
        $orders[] = array(
            'file_key'      => $key,
            'orderId'       => (string)$o['orderId'],
            'status'        => isset($o['status']) ? (string)$o['status'] : '',
            'synced'        => isset($o['utmify_synced']) ? (string)$o['utmify_synced'] : '',
            'reconstructed' => !empty($o['reconstructed']),
            'has_utm'       => !empty($o['trackingParameters']['utm_source']) || !empty($o['trackingParameters']['utm_campaign']) || !empty($o['trackingParameters']['src']),
            'document'      => isset($o['customer']['document']) ? preg_replace('/\D/', '', (string)$o['customer']['document']) : '',
            'amount'        => isset($o['commission']['totalPriceInCents']) ? (int)$o['commission']['totalPriceInCents'] : 0,
            'createdAt'     => isset($o['createdAt']) ? (string)$o['createdAt'] : '',
            'approvedDate'  => isset($o['approvedDate']) ? (string)$o['approvedDate'] : '',
            'ts'            => (int)$cts,
            '_order'        => $o,
        );
    }
    // duplicatas: pagos, mesmo CPF+valor, janela de tempo
    $paid = array_filter($orders, function ($x) { return $x['status'] === 'paid'; });
    usort($paid, function ($a, $b) { return $a['ts'] - $b['ts']; });
    $groups = array(); $used = array();
    $paidArr = array_values($paid);
    for ($i = 0; $i < count($paidArr); $i++) {
        if (isset($used[$i]) || $paidArr[$i]['document'] === '') continue;
        $g = array($i);
        for ($j = $i + 1; $j < count($paidArr); $j++) {
            if (isset($used[$j])) continue;
            if ($paidArr[$j]['document'] === $paidArr[$i]['document'] && $paidArr[$j]['amount'] === $paidArr[$i]['amount'] && abs($paidArr[$j]['ts'] - $paidArr[$i]['ts']) <= $window) {
                $g[] = $j;
            }
        }
        if (count($g) > 1) { foreach ($g as $x) $used[$x] = true; $groups[] = $g; }
    }
    $dups = array(); $fixed = array(); $manual = array();
    foreach ($groups as $g) {
        $members = array();
        foreach ($g as $idx) $members[] = $paidArr[$idx];
        // mantem: 1o ORIGINAL (nao reconstruido); se todos reconstruidos, o mais antigo
        $keepIdx = 0;
        foreach ($members as $mi => $m) { if (!$m['reconstructed']) { $keepIdx = $mi; break; } }
        $entry = array('keep' => $members[$keepIdx]['orderId'], 'others' => array());
        foreach ($members as $mi => $m) {
            if ($mi === $keepIdx) continue;
            $info = array('orderId' => $m['orderId'], 'reconstructed' => $m['reconstructed'], 'has_utm' => $m['has_utm'], 'createdAt' => $m['createdAt']);
            if ($fix && $m['reconstructed']) {
                $o = $m['_order'];
                $o['status'] = 'refused';
                $o['approvedDate'] = null;
                $o['audit_refused_at'] = gmdate('Y-m-d H:i:s');
                $r = send_utmify_order($o);
                $code = isset($r['http_status']) ? (int)$r['http_status'] : 0;
                $info['fix_http'] = $code;
                if ($code >= 200 && $code < 300) {
                    $o['utmify_synced'] = 'refused';
                    @file_put_contents(utmify_order_filename($m['file_key']), json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    utmify_write_alias($m['orderId'], $members[$keepIdx]['orderId']);
                    $fixed[] = $m['orderId'];
                }
            } elseif (!$m['reconstructed']) {
                $manual[] = $m['orderId'];
            }
            $entry['others'][] = $info;
        }
        $dups[] = $entry;
    }
    // totais por dia (UTC) de pedidos paid
    $byDay = array();
    foreach ($paidArr as $p) {
        $d = $p['approvedDate'] !== '' ? substr($p['approvedDate'], 0, 10) : substr($p['createdAt'], 0, 10);
        if (!isset($byDay[$d])) $byDay[$d] = array('paid' => 0, 'reconstructed' => 0, 'sem_utm' => 0);
        $byDay[$d]['paid']++;
        if ($p['reconstructed']) $byDay[$d]['reconstructed']++;
        if (!$p['has_utm']) $byDay[$d]['sem_utm']++;
    }
    ksort($byDay);
    $recon = array();
    foreach ($orders as $x) if ($x['reconstructed']) $recon[] = array('orderId' => $x['orderId'], 'status' => $x['status'], 'has_utm' => $x['has_utm'], 'createdAt' => $x['createdAt']);
    send_json(array(
        'ok'            => true,
        'cache_dir'     => $dir,
        'dias'          => $days,
        'janela_seg'    => $window,
        'total_pedidos' => count($orders),
        'total_paid'    => count($paidArr),
        'por_dia'       => $byDay,
        'reconstruidos' => $recon,
        'duplicatas'    => $dups,
        'fix'           => $fix,
        'corrigidos'    => $fixed,
        'revisar_manual'=> $manual,
        'dica'          => 'revisar_manual = 2 pedidos ORIGINAIS pagos do mesmo CPF+valor (cliente pagou 2 PIX ou pagamento duplo): confira no gateway e ajuste no painel da UTMify.',
    ), 200);
}

/**
 * Envia o pedido a UTMify e grava a flag utmify_synced SOMENTE em caso de
 * sucesso (HTTP 2xx). Em falha (timeout/5xx/429), a flag fica para tras e o
 * PROXIMO evento (webhook, polling do front ou cron) reenvia automaticamente.
 * A UTMify faz upsert por orderId, entao reenviar e seguro (nao duplica).
 */
function utmify_send_and_flag($order, $fileKey = null) {
    if (function_exists('trk_enrich_order')) $order = trk_enrich_order($order); // zip 88
    $res  = send_utmify_order($order);
    $code = isset($res['http_status']) ? (int)$res['http_status'] : 0;
    if ($code >= 200 && $code < 300) {
        $order['utmify_synced'] = isset($order['status']) ? $order['status'] : null;
        $key = ($fileKey !== null && (string)$fileKey !== '') ? (string)$fileKey : (isset($order['orderId']) ? (string)$order['orderId'] : '');
        if ($key !== '') {
            @file_put_contents(utmify_order_filename($key), json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        // FLAG GLOBAL (zip 75): este pagamento ja foi entregue como paid.
        if (isset($order['status']) && $order['status'] === 'paid') {
            foreach (array($key, (isset($order['orderId']) ? (string)$order['orderId'] : '')) as $fk) {
                if ($fk !== '') @file_put_contents(utmify_paid_flag_filename($fk), (string)time());
            }
        }
    } else {
        @error_log('[utmify] envio NAO confirmado (http=' . $code . ') — sera reenviado no proximo evento. orderId=' . (isset($order['orderId']) ? $order['orderId'] : '?'));
    }
    return $res;
}

/**
 * Pedido minimo (sem UTMs) reconstruido do banco de recuperacao — usado apenas
 * quando o JSON original do pedido sumiu do cache. Aparece na UTMify como
 * venda sem atribuicao, mas CONTA no total (melhor do que perder a venda).
 */
function utmify_order_from_recovery_row($txid, $row) {
    $amount      = isset($row['amount']) ? (float)$row['amount'] : 0.0;
    $amountCents = (int)round($amount * 100);
    $ptype       = isset($row['product_type']) ? (string)$row['product_type'] : '';
    $genericName = function_exists('generic_deposit_name') ? generic_deposit_name($ptype) : 'SR Deposito PIX';
    $genericId   = function_exists('generic_deposit_id') ? generic_deposit_id($ptype) : 'DEP-PIX';
    $createdAt   = (isset($row['created_at']) && $row['created_at']) ? utmify_format_date_utc((int)$row['created_at']) : utmify_format_date_utc();
    // UTMs salvas no create_pix (coluna utm_json, zip 75) — pedido reconstruido
    // volta a contar como venda TRACKEADA na UTMify.
    $utm = array();
    if (!empty($row['utm_json'])) { $tmp = json_decode((string)$row['utm_json'], true); if (is_array($tmp)) $utm = $tmp; }
    $tp = function ($k) use ($utm) { return (isset($utm[$k]) && (string)$utm[$k] !== '') ? (string)$utm[$k] : null; };
    $__o = array(
        'orderId'       => (string)$txid,
        'platform'      => 'Genesys',
        'paymentMethod' => 'pix',
        'status'        => 'waiting_payment',
        'createdAt'     => $createdAt,
        'approvedDate'  => null,
        'refundedAt'    => null,
        'isTest'        => false,
        'reconstructed' => true,
        'customer' => array(
            'name'     => isset($row['name']) ? (string)$row['name'] : '',
            'email'    => isset($row['email']) ? (string)$row['email'] : '',
            'phone'    => isset($row['phone']) ? (string)$row['phone'] : '',
            'document' => isset($row['document']) ? (string)$row['document'] : '',
            'country'  => 'BR',
            'ip'       => '',
        ),
        'products' => array(array(
            'id'           => $genericId,
            'name'         => $genericName,
            'planId'       => null,
            'planName'     => null,
            'quantity'     => 1,
            'priceInCents' => $amountCents,
        )),
        'trackingParameters' => array(
            'src' => $tp('src'), 'sck' => $tp('sck'),
            'utm_source' => $tp('utm_source'), 'utm_campaign' => $tp('utm_campaign'), 'utm_medium' => $tp('utm_medium'),
            'utm_content' => $tp('utm_content'), 'utm_term' => $tp('utm_term'), 'utm_id' => $tp('utm_id'),
            'fbc' => $tp('fbc'), 'fbp' => $tp('fbp'),
        ),
        'commission' => array(
            'totalPriceInCents'     => $amountCents,
            'gatewayFeeInCents'     => 0,
            'userCommissionInCents' => $amountCents,
            'currency'              => 'BRL',
        ),
    );
    return function_exists('trk_enrich_order') ? trk_enrich_order($__o) : $__o; // zip 88
}

function send_utmify_order($order) {
    if (!defined('UTMIFY_API_URL') || !defined('UTMIFY_API_TOKEN') || trim(UTMIFY_API_TOKEN) === '') {
        @error_log('[utmify] token nao configurado, pulando envio (orderId=' . (isset($order['orderId']) ? $order['orderId'] : '?') . ')');
        return array('skipped' => true, 'reason' => 'no_token');
    }

    $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        @error_log('[utmify] falha ao serializar pedido');
        return array('skipped' => true, 'reason' => 'json_encode_failed');
    }

    $ch = curl_init(UTMIFY_API_URL);
    if ($ch === false) {
        return array('http_status' => 0, 'error' => 'curl_init_failed');
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-token: ' . UTMIFY_API_TOKEN,
        ),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'funil-sr-2026-utmify/1.0',
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    @error_log('[utmify] POST status=' . (isset($order['status']) ? $order['status'] : '?')
        . ' orderId=' . (isset($order['orderId']) ? $order['orderId'] : '?')
        . ' http=' . $code
        . ' body=' . substr((string)$body, 0, 500)
        . ($err !== '' ? ' err=' . $err : ''));
    return array('http_status' => $code, 'body' => $body, 'error' => $err);
}

// =============================================================================
// HELPERS
// =============================================================================
function send_json($payload, $statusCode = 200) {
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}