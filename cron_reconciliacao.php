<?php
/**
 * =============================================================================
 * CRON DE RECONCILIACAO DE STATUS (Disrupty -> UTMify + banco de recuperacao)
 * =============================================================================
 * PROBLEMA QUE RESOLVE
 *   Quando o cliente fecha a pagina antes de o PIX cair E o webhook do gateway
 *   atrasa (ou nao chega), o pedido fica ETERNAMENTE como "waiting_payment" na
 *   UTMify — mesmo com o dinheiro ja recebido no gateway. Isso gera diferenca
 *   entre as vendas reais e o que aparece no rastreamento (ex.: ~13 vendas
 *   "sumidas").
 *   (Gateway atual: Disrupty.)
 *
 * O QUE FAZ
 *   Varre TODOS os pedidos ainda pendentes (cache da UTMify + banco de
 *   recuperacao) dentro de uma janela de dias, consulta o status REAL de cada
 *   um direto na API da Disrupty e, quando o status e terminal:
 *     - PAGO      -> marca 'paid' na UTMify (reenvia) + marca PAID no banco
 *                    (interrompe e-mails de recuperacao)
 *     - RECUSADO  -> marca 'refused' na UTMify
 *     - ESTORNADO -> marca 'refunded' na UTMify
 *     - CHARGEBACK-> marca 'chargedback' na UTMify
 *   E idempotente: so reenvia quando o status muda, entao pode rodar de hora em
 *   hora (ou a cada 15 min) sem duplicar nada.
 *
 * AGENDAMENTO (aaPanel > Cron > Shell Script) — recomendado a cada 15 min:
 *   php /www/wwwroot/SEU_SITE/cron_reconciliacao.php
 *
 * USO VIA NAVEGADOR (exige o token de recuperacao):
 *   Reconciliar agora:  https://SEU_SITE/cron_reconciliacao.php?token=RECOVERY_SECRET
 *   Simular (nao grava): ...&dry=1
 *   Ver relatorio (sem consultar gateway): ...&report=1
 *
 * PARAMETROS OPCIONAIS (env):
 *   RECON_MAX_AGE_DAYS  janela de dias a reconciliar (padrao 5)
 *   RECON_MAX_PER_RUN   maximo de consultas por execucao (padrao 400)
 * =============================================================================
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
set_time_limit(280);

// ---------------------------------------------------------------------------
// BOOTSTRAP: .env + recovery_lib (mesmos caminhos do api_proxy / cron_recuperacao)
// ---------------------------------------------------------------------------
$__envLoader = __DIR__ . '/.private/env_loader.php';
if (@is_readable($__envLoader)) {
    require_once $__envLoader;
    if (function_exists('load_dotenv')) {
        // Mesmo modelo ESCOPADO do api_proxy.php: cada pasta fornece so o seu grupo
        // de chaves, evitando que o cron de uma oferta leia credenciais de outra.
        $__slug   = 'sr';
        $__parent = dirname(__DIR__);
        $__kGate = array('DISRUPTY_PUBLIC_KEY','DISRUPTY_PRIVATE_KEY','DISRUPTY_BASE_URL','WEBHOOK_SECRET','DISRUPTY_WEBHOOK_TOKEN','GENESYS_WEBHOOK_SECRET','GATEWAY_PROVIDER');
        $__kKupfy = array('KUPFY_PUBLIC_KEY','KUPFY_SECRET_KEY','KUPFY_PRIVATE_KEY','KUPFY_BASE_URL','KUPFY_POLL_INTERVAL','KUPFY_WEBHOOK_TOKEN','GATEWAY_PROVIDER');
        $__kGenesys = array('GENESYS_ACCOUNT_HASH','GENESYS_API_TOKEN','GENESYS_API_SECRET','GENESYS_BASE_URL','GENESYS_WEBHOOK_SECRET','WEBHOOK_SECRET','GATEWAY_PROVIDER');
        $__kVelani = array('VELANI_SECRET_KEY','VELANI_API_KEY','VELANI_PUBLIC_KEY','VELANI_BASE_URL','VELANI_WEBHOOK_SECRET','WEBHOOK_SECRET','GATEWAY_PROVIDER');
        $__kUtm  = array('UTMIFY_API_TOKEN','UTMIFY_API_URL');
        $__kMail = array('SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','SMTP_PASS','SMTP_FROM_EMAIL','SMTP_FROM_NAME','RECOVERY_BASE_URL','RECOVERY_SECRET','RECOVERY_DISCOUNT_PRICE','NOTIFY_WEBHOOK_URL','NOTIFY_PROVIDER','OFFER_NAME','OFFER_PRODUCT_LABEL','DELIVERABLE_URL','DELIVERABLE_BACKFILL_DAYS');
        $__pick = function($cands, $keys) {
            foreach ($cands as $p) { if ($p !== '' && @is_readable($p)) { load_dotenv($p, $keys); return; } }
        };
        $__explicit = (function_exists('getenv') && @getenv('FUNIL_ENV_FILE') !== false) ? @getenv('FUNIL_ENV_FILE') : '';
        if ($__explicit !== '' && @is_readable($__explicit)) { load_dotenv($__explicit); }
        $__pick(array($__parent.'/secrets-disrupty-sr/.env', $__parent.'/secrets-disrupty/.env'), $__kGate); // zip 88: conta por oferta
        $__pick(array($__parent.'/secrets-kupfy-'.$__slug.'/.env', $__parent.'/secrets-kupfy/.env'), $__kKupfy);
        $__pick(array($__parent.'/secrets-genesys-sr/.env', $__parent.'/secrets-genesys-'.$__slug.'/.env', $__parent.'/secrets-genesys/.env'), $__kGenesys);
        $__pick(array($__parent.'/secrets-velani-sr/.env', $__parent.'/secrets-velani-'.$__slug.'/.env', $__parent.'/secrets-velani/.env'), $__kVelani);
        $__pick(array($__parent.'/utmify-'.$__slug.'/.env'), $__kUtm);
        $__pick(array($__parent.'/mail.rec-'.$__slug.'/.env'), $__kMail);
        // Fallback consolidado (preenche apenas o que faltar):
        foreach (array($__parent.'/secrets-funil-sr/.env', $__parent.'/.funil-secrets/.env', __DIR__.'/.private/.env') as $__fb) {
            if ($__fb !== '' && @is_readable($__fb)) { load_dotenv($__fb); }
        }
    }
}
require_once __DIR__ . '/.private/recovery_lib.php';
// zip 88: tracking no servidor (aditivo)
$__trkLib = __DIR__ . '/.private/trk_lib.php';
if (@is_readable($__trkLib)) { try { require_once $__trkLib; } catch (\Throwable $e) { @error_log('[trk] falha ao carregar lib: ' . $e->getMessage()); } }
$__notify_lib = __DIR__ . '/.private/notify_lib.php';
if (@is_readable($__notify_lib)) { require_once $__notify_lib; }

// ---------------------------------------------------------------------------
// SEGURANCA: via web exige token; via CLI roda direto
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    $tok = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if ($tok === '' || !hash_equals(recovery_secret(), $tok)) {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$DRY    = (PHP_SAPI === 'cli')
    ? in_array('--dry', $argv ?: array(), true)
    : (isset($_GET['dry']) && $_GET['dry'] !== '0' && $_GET['dry'] !== '');
$REPORT = (PHP_SAPI === 'cli')
    ? in_array('--report', $argv ?: array(), true)
    : (isset($_GET['report']) && $_GET['report'] !== '0' && $_GET['report'] !== '');

function rlog($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents(__DIR__ . '/.private/reconciliacao.log', $line . "\n", FILE_APPEND);
}

// ---------------------------------------------------------------------------
// CONFIG
// ---------------------------------------------------------------------------
function recon_env($key, $default = '') {
    $v = function_exists('env_get') ? env_get($key) : (function_exists('getenv') ? @getenv($key) : false);
    if ($v === false || $v === null || (is_string($v) && trim($v) === '')) return $default;
    return is_string($v) ? trim($v) : $v;
}

$MAX_AGE_DAYS = (int)recon_env('RECON_MAX_AGE_DAYS', '5');
if ($MAX_AGE_DAYS < 1)  $MAX_AGE_DAYS = 1;
if ($MAX_AGE_DAYS > 30) $MAX_AGE_DAYS = 30;
$MAX_PER_RUN  = (int)recon_env('RECON_MAX_PER_RUN', '400');
if ($MAX_PER_RUN < 1) $MAX_PER_RUN = 400;
// Override pontual do limite: CLI --max=N ou web ?max=N (util para varredura unica do backlog).
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv)) {
    foreach ($argv as $__a) {
        if (strpos($__a, '--max=') === 0) $MAX_PER_RUN = max(1, (int)substr($__a, 6));
    }
} elseif (isset($_GET['max'])) {
    $MAX_PER_RUN = max(1, (int)$_GET['max']);
}
$MAX_AGE = $MAX_AGE_DAYS * 86400;
$now = time();

function cache_dir() {
    $dir = __DIR__ . '/.private/cache';
    if (is_dir($dir) && is_writable($dir)) return $dir;
    // Mesmo fallback do api_proxy: se o cache caiu no tmp do sistema,
    // a reconciliacao precisa enxergar os MESMOS pedidos.
    $tmp = sys_get_temp_dir() . '/funil_sr_cache';
    if (is_dir($tmp)) return $tmp;
    return is_dir($dir) ? $dir : '';
}

// ---------------------------------------------------------------------------
// UTMify (token + envio) — self-contained (nao inclui o api_proxy)
// ---------------------------------------------------------------------------
function recon_utmify_creds() {
    $token = recon_env('UTMIFY_API_TOKEN', '');
    $url   = recon_env('UTMIFY_API_URL', '');
    if ($token === '') {
        $legacy = __DIR__ . '/.private/utmify_credentials.php';
        if (@is_readable($legacy)) {
            require_once $legacy;
            if (defined('UTMIFY_API_TOKEN')) $token = (string)UTMIFY_API_TOKEN;
            if ($url === '' && defined('UTMIFY_API_URL')) $url = (string)UTMIFY_API_URL;
        }
    }
    if ($url === '') $url = 'https://api.utmify.com.br/api-credentials/orders';
    if (strpos($token, 'COLOQUE_') === 0) $token = '';
    return array($token, $url);
}

function recon_utmify_map($gatewayStatus) {
    $up = strtoupper((string)$gatewayStatus);
    if (in_array($up, array('COMPLETED','PAID','PAGO','PAYED','APPROVED','SUCCESS','CONFIRMED','AUTHORIZED'), true)) return 'paid';
    if (in_array($up, array('FAILED','REJECTED','CANCELLED','CANCELED','CANCELADO','EXPIRED','EXPIRADO'), true)) return 'refused';
    if (in_array($up, array('REFUNDED','REEMBOLSADO','ESTORNADO'), true)) return 'refunded';
    if (in_array($up, array('CHARGED_BACK','CHARGEDBACK','CHARGEBACK'), true)) return 'chargedback';
    return 'waiting_payment';
}

function recon_utmify_date($ts = null) {
    if ($ts === null || $ts === '') return gmdate('Y-m-d H:i:s');
    if (is_numeric($ts)) return gmdate('Y-m-d H:i:s', (int)$ts);
    $t = strtotime((string)$ts);
    return $t ? gmdate('Y-m-d H:i:s', $t) : gmdate('Y-m-d H:i:s');
}

function recon_utmify_send($order) {
    list($token, $url) = recon_utmify_creds();
    if ($token === '') return array('skipped' => true, 'reason' => 'no_token');
    $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return array('skipped' => true, 'reason' => 'json_encode_failed');
    $ch = curl_init($url);
    if ($ch === false) return array('http_status' => 0, 'error' => 'curl_init_failed');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-token: ' . $token,
        ),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'funil-sr-2026-reconc/1.0',
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('http_status' => $code, 'body' => $body, 'error' => $err);
}

// Envia a venda PAGA a UTMify a partir de uma linha do banco de recuperacao,
// reaproveitando o JSON do pedido se existir (com as UTMs originais) ou
// reconstruindo um pedido minimo — a venda passa a contar no dashboard.
function recon_utmify_paid_from_row($identifiers, $row, $paidAt) {
    try {
        $dir = cache_dir();
        $order = null; $file = null;
        $identifiers = array_values(array_filter(array_map('strval', (array)$identifiers), function ($x) { return $x !== ''; }));
        // ID CANONICO (zip 75): o txid da row e a chave do pedido na UTMify.
        // Expande por alias_<id>.json (gravado pelo api_proxy) e procura o JSON
        // ORIGINAL por txid/identifier/alias antes de reconstruir.
        $canon = (is_array($row) && !empty($row['txid'])) ? (string)$row['txid'] : (isset($identifiers[0]) ? (string)$identifiers[0] : '');
        $search = array();
        if ($canon !== '') $search[] = $canon;
        foreach ($identifiers as $key) {
            $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
            $af = ($dir !== '') ? $dir . '/alias_' . $safe . '.json' : '';
            if ($af !== '' && is_file($af)) {
                $aj = json_decode((string)@file_get_contents($af), true);
                if (is_array($aj) && !empty($aj['txid']) && !in_array((string)$aj['txid'], $search, true)) $search[] = (string)$aj['txid'];
            }
            if (!in_array($key, $search, true)) $search[] = $key;
        }
        $firstAny = null; $firstAnyFile = null;
        foreach ($search as $key) {
            $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
            $f = ($dir !== '') ? $dir . '/order_' . $safe . '.json' : '';
            if ($f !== '' && is_file($f)) {
                $tmp = json_decode((string)@file_get_contents($f), true);
                if (!is_array($tmp)) continue;
                if (empty($tmp['reconstructed'])) { $order = $tmp; $file = $f; break; } // prefere o ORIGINAL (com UTMs)
                if ($firstAny === null) { $firstAny = $tmp; $firstAnyFile = $f; }
            }
        }
        if (!$order && $firstAny !== null) { $order = $firstAny; $file = $firstAnyFile; }
        // FLAG GLOBAL: este pagamento ja foi entregue como paid por algum caminho.
        foreach ($search as $key) {
            $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
            if ($dir !== '' && is_file($dir . '/utmify_paid_' . $safe . '.flag')) {
                if ($order && $file && (!isset($order['utmify_synced']) || $order['utmify_synced'] !== 'paid')) {
                    $order['status'] = 'paid'; $order['utmify_synced'] = 'paid';
                    @file_put_contents($file, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
                return;
            }
        }
        if (!$order && is_array($row)) {
            $key  = $canon;
            $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
            $file = ($dir !== '') ? $dir . '/order_' . $safe . '.json' : null;
            $amountCents = (int)round(((float)(isset($row['amount']) ? $row['amount'] : 0)) * 100);
            $utm = array();
            if (!empty($row['utm_json'])) { $uj = json_decode((string)$row['utm_json'], true); if (is_array($uj)) $utm = $uj; }
            $tp = function ($k) use ($utm) { return (isset($utm[$k]) && (string)$utm[$k] !== '') ? (string)$utm[$k] : null; };
            $pname = function_exists('generic_deposit_name') ? generic_deposit_name(isset($row['product_type']) ? $row['product_type'] : '') : 'SR Deposito PIX';
            $pid   = function_exists('generic_deposit_id') ? generic_deposit_id(isset($row['product_type']) ? $row['product_type'] : '') : 'DEP-PIX';
            $order = array(
                'orderId' => $key, 'platform' => 'Genesys', 'paymentMethod' => 'pix',
                'status' => 'waiting_payment',
                'createdAt' => gmdate('Y-m-d H:i:s', isset($row['created_at']) && $row['created_at'] ? (int)$row['created_at'] : time()),
                'approvedDate' => null, 'refundedAt' => null, 'isTest' => false, 'reconstructed' => true,
                'customer' => array(
                    'name' => isset($row['name']) ? (string)$row['name'] : '',
                    'email' => isset($row['email']) ? (string)$row['email'] : '',
                    'phone' => isset($row['phone']) ? (string)$row['phone'] : '',
                    'document' => isset($row['document']) ? (string)$row['document'] : '',
                    'country' => 'BR', 'ip' => ''),
                'products' => array(array('id' => $pid, 'name' => $pname, 'planId' => null, 'planName' => null, 'quantity' => 1, 'priceInCents' => $amountCents)),
                'trackingParameters' => array('src' => $tp('src'), 'sck' => $tp('sck'), 'utm_source' => $tp('utm_source'), 'utm_campaign' => $tp('utm_campaign'), 'utm_medium' => $tp('utm_medium'), 'utm_content' => $tp('utm_content'), 'utm_term' => $tp('utm_term'), 'utm_id' => $tp('utm_id'), 'fbc' => $tp('fbc'), 'fbp' => $tp('fbp')),
                'commission' => array('totalPriceInCents' => $amountCents, 'gatewayFeeInCents' => 0, 'userCommissionInCents' => $amountCents, 'currency' => 'BRL'),
            );
        }
        if (!$order) return;
        $synced = isset($order['utmify_synced']) ? (string)$order['utmify_synced'] : '';
        if ($synced === 'paid') return; // ja entregue a UTMify
        if (function_exists('trk_enrich_order')) $order = trk_enrich_order($order); // zip 88
        $order['status'] = 'paid';
        $t = $paidAt ? strtotime((string)$paidAt) : false;
        $order['approvedDate'] = gmdate('Y-m-d H:i:s', $t ?: time());
        $res = recon_utmify_send($order);
        $code = isset($res['http_status']) ? (int)$res['http_status'] : 0;
        if ($code >= 200 && $code < 300) {
            $order['utmify_synced'] = 'paid';
            // FLAG GLOBAL (zip 75) + alias dos ids alternativos -> chave canonica
            if ($dir !== '') {
                $ck = isset($order['orderId']) ? (string)$order['orderId'] : $canon;
                @file_put_contents($dir . '/utmify_paid_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $ck) . '.flag', (string)time());
                foreach ($identifiers as $aid) {
                    if ($aid === $ck) continue;
                    @file_put_contents($dir . '/alias_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $aid) . '.json', json_encode(array('txid' => $ck, 'at' => time())));
                }
            }
        }
        if ($file) @file_put_contents($file, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rlog('[utmify] pago (via banco) sincronizado: ' . substr((string)$identifiers[0], 0, 14) . '... http=' . $code . (($code < 200 || $code >= 300) ? ' (sera retentado)' : ''));
    } catch (\Throwable $e) { rlog('[utmify] erro no sync via banco: ' . $e->getMessage()); }
}

// ---------------------------------------------------------------------------
// DISRUPTY — consulta de status (mesmo padrao do cron_recuperacao)
// ---------------------------------------------------------------------------
function recon_disrupty_base() {
    $b = recon_env('DISRUPTY_BASE_URL', 'https://api-sellers.disruptybr.app');
    return rtrim($b, '/');
}

function recon_disrupty_keys() {
    $pub  = recon_env('DISRUPTY_PUBLIC_KEY', '');
    $priv = recon_env('DISRUPTY_PRIVATE_KEY', '');
    if ($pub === '' || $priv === '' || strpos($pub, 'COLOQUE_') === 0 || strpos($priv, 'COLOQUE_') === 0) return null;
    return array($pub, $priv);
}

function recon_http_json($method, $url, $payload = null, $extraHeaders = array()) {
    $headers = array('Accept: application/json');
    foreach ((array)$extraHeaders as $h) { if (is_string($h) && $h !== '') $headers[] = $h; }
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    );
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return array(0, null);
    return array($code, json_decode($resp, true));
}


function recon_kupfy_base() {
    $b = recon_env('KUPFY_BASE_URL', 'https://app.kupfy.com/api/v1');
    return rtrim($b, '/');
}

function recon_kupfy_keys() {
    $pub = recon_env('KUPFY_PUBLIC_KEY', '');
    $sec = recon_env('KUPFY_SECRET_KEY', '');
    if ($sec === '') $sec = recon_env('KUPFY_PRIVATE_KEY', '');
    if ($pub === '' || $sec === '' || strpos($pub, 'COLOQUE_') === 0 || strpos($sec, 'COLOQUE_') === 0) return null;
    return array($pub, $sec);
}

/** Consulta status na KUPFY; retorna o mesmo shape do recon_check_status ou null. */
function recon_check_status_kupfy($txid) {
    $keys = recon_kupfy_keys();
    if ($keys === null) return null;
    list($pub, $sec) = $keys;
    $url = recon_kupfy_base() . '/gateway/transactions?id=' . rawurlencode($txid);
    list($code, $d) = recon_http_json('GET', $url, null, array(
        'x-public-key: ' . $pub,
        'x-secret-key: ' . $sec,
    ));
    if ($code === 0 || $code >= 400 || !is_array($d)) return null;
    if (isset($d['error'])) return null; // ex.: TOO_MANY_REQUESTS — indeterminado
    $tx = $d;
    if (isset($d['transaction']) && is_array($d['transaction'])) $tx = $d['transaction'];
    elseif (isset($d['data']) && is_array($d['data'])) $tx = $d['data'];
    $statusRaw = isset($tx['status']) ? (string)$tx['status'] : '';
    if ($statusRaw === '') return null;
    $paidAt = null;
    foreach (array('payedAt','paidAt','paid_at','date_approved') as $k) {
        if (!empty($tx[$k])) { $paidAt = (string)$tx[$k]; break; }
    }
    $utm = recon_utmify_map($statusRaw);
    if ($utm === 'waiting_payment' && $paidAt !== null) $utm = 'paid';
    if ($utm === 'paid' && ($paidAt === null || $paidAt === '')) $paidAt = date('c');
    return array('utmify' => $utm, 'paidAt' => $paidAt, 'raw' => $statusRaw);
}

// ===== GENESYS FINANCE (V28) =====
function recon_genesys_base() {
    $b = recon_env('GENESYS_BASE_URL', 'https://api.genesys.finance');
    return rtrim($b, '/');
}

function recon_genesys_keys() {
    $hash = recon_env('GENESYS_ACCOUNT_HASH', '');
    $tok  = recon_env('GENESYS_API_TOKEN', '');
    if ($tok === '') $tok = recon_env('GENESYS_API_SECRET', '');
    if ($hash === '' || $tok === '' || strpos($hash, 'COLOQUE_') === 0 || strpos($tok, 'COLOQUE_') === 0) return null;
    return array($hash, $tok);
}

/** Consulta status na GENESYS (GET /v1/transactions/{id}); mesmo shape do recon_check_status ou null. */
function recon_check_status_genesys($txid) {
    $keys = recon_genesys_keys();
    if ($keys === null) return null;
    list($hash, $tok) = $keys;
    $url = recon_genesys_base() . '/v1/transactions/' . rawurlencode($txid);
    list($code, $d) = recon_http_json('GET', $url, null, array(
        'api-secret: ' . $tok,
        'Authorization: Bearer ' . $tok,
        'account-hash: ' . $hash,
        'Accept: application/json',
    ));
    if ($code === 0 || $code >= 400 || !is_array($d)) return null;
    $tx = $d;
    if (isset($d['data']) && is_array($d['data'])) $tx = $d['data'];
    elseif (isset($d['transaction']) && is_array($d['transaction'])) $tx = $d['transaction'];
    $statusRaw = isset($tx['status']) ? (string)$tx['status'] : '';
    if ($statusRaw === '') return null;
    $paidAt = null;
    foreach (array('paid_at','paidAt','payedAt','date_approved') as $k) {
        if (!empty($tx[$k])) { $paidAt = (string)$tx[$k]; break; }
    }
    $utm = recon_utmify_map($statusRaw);
    if ($utm === 'waiting_payment' && $paidAt !== null) $utm = 'paid';
    if ($utm === 'paid' && ($paidAt === null || $paidAt === '')) $paidAt = date('c');
    return array('utmify' => $utm, 'paidAt' => $paidAt, 'raw' => $statusRaw);
}

// ===== VELANI PAGAMENTOS =====
function recon_velani_base() {
    $b = recon_env('VELANI_BASE_URL', 'https://api.velanipagamentos.com.br/api/v1/api-gateway');
    return rtrim($b, '/');
}

function recon_velani_keys() {
    // Prefere SEMPRE a secret key (sk_live_...); a public key (pk_live_...) so consulta.
    $cands = array(recon_env('VELANI_SECRET_KEY', ''), recon_env('VELANI_API_KEY', ''), recon_env('VELANI_PUBLIC_KEY', ''));
    $fallback = null;
    foreach ($cands as $k) {
        $k = trim((string)$k, " \t\"'");
        if ($k === '' || strpos($k, 'COLOQUE_') === 0) continue;
        if (stripos($k, 'sk_') === 0) return $k;
        if ($fallback === null) $fallback = $k;
    }
    return $fallback;
}

/** Consulta status na VELANI (GET /v1/transactions/{id}, header x-api-key); mesmo shape do recon_check_status ou null. */
function recon_check_status_velani($txid) {
    $key = recon_velani_keys();
    if ($key === null) return null;
    $url = recon_velani_base() . '/v1/transactions/' . rawurlencode($txid);
    list($code, $d) = recon_http_json('GET', $url, null, array(
        'x-api-key: ' . $key,
        'Accept: application/json',
    ));
    if ($code === 0 || $code >= 400 || !is_array($d)) return null;
    if (isset($d['success']) && $d['success'] === false) return null;
    $tx = $d;
    if (isset($d['data']) && is_array($d['data'])) $tx = $d['data'];
    elseif (isset($d['transaction']) && is_array($d['transaction'])) $tx = $d['transaction'];
    $statusRaw = isset($tx['status']) ? (string)$tx['status'] : '';
    if ($statusRaw === '') return null;
    $paidAt = null;
    foreach (array('paidAt','paid_at','payedAt','date_approved') as $k) {
        if (!empty($tx[$k])) { $paidAt = (string)$tx[$k]; break; }
    }
    $utm = recon_utmify_map(strtoupper($statusRaw));
    if ($utm === 'waiting_payment' && $paidAt !== null) $utm = 'paid';
    if ($utm === 'paid' && ($paidAt === null || $paidAt === '')) $paidAt = date('c');
    return array('utmify' => $utm, 'paidAt' => $paidAt, 'raw' => $statusRaw);
}

/** Wrapper multi-gateway: VELANI primeiro (se configurada), depois GENESYS, KUPFY, e cai para a Disrupty. */

/** CHAVE RAPIDA DE GATEWAY: le <pasta acima do webroot>/gateway-<slug>.txt ou gateway.txt (uma palavra:
 *  genesys | velani | kupfy | disrupty | auto). O gateway escolhido e consultado PRIMEIRO; os demais continuam
 *  como fallback (transacoes antigas do gateway anterior seguem cobertas). */
function recon_gateway_switch() {
    static $r = null;
    if ($r !== null) return $r;
    $r = '';
    $p = dirname(__DIR__);
    foreach (array($p.'/gateway-sr.txt' , $p.'/gateway-serasa.txt', $p.'/gateway.txt', __DIR__.'/.private/gateway.txt') as $f) {
        if (!@is_readable($f)) continue;
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#') continue;
            $v = strtolower(trim($ln, " \t\"'"));
            if (in_array($v, array('genesys','velani','kupfy','disrupty'), true)) $r = $v;
            break;
        }
        break;
    }
    return $r;
}

function recon_check_status($txid) {
    if (!function_exists('curl_init')) return null;
    $sw = recon_gateway_switch();
    if ($sw !== '' && $sw !== 'disrupty') {
        $kf = 'recon_%s_keys'; $cf = 'recon_check_status_' . $sw;
        if (function_exists(sprintf($kf, $sw)) && call_user_func(sprintf($kf, $sw)) !== null && function_exists($cf)) {
            $rs = call_user_func($cf, $txid);
            if (is_array($rs)) return $rs;
        }
    } elseif ($sw === 'disrupty') {
        $rd = recon_check_status_disrupty($txid);
        if (is_array($rd)) return $rd;
    }
    if (recon_velani_keys() !== null) {
        $rv = recon_check_status_velani($txid);
        if (is_array($rv)) return $rv;
    }
    if (recon_genesys_keys() !== null) {
        $rg = recon_check_status_genesys($txid);
        if (is_array($rg)) return $rg;
    }
    if (recon_kupfy_keys() !== null) {
        $rk = recon_check_status_kupfy($txid);
        if (is_array($rk)) return $rk;
    }
    return recon_check_status_disrupty($txid);
}

/** Retorna array('utmify'=><status utmify>, 'paidAt'=>..., 'raw'=>...) ou null se indeterminado. */
function recon_check_status_disrupty($txid) {
    $keys = recon_disrupty_keys();
    if ($keys === null) return null;
    list($pub, $priv) = $keys;
    $url = recon_disrupty_base() . '/api/sales/' . rawurlencode($txid);
    list($code, $d) = recon_http_json('GET', $url, null, array(
        'x-api-public-key: ' . $pub,
        'x-api-private-key: ' . $priv,
    ));
    if ($code === 0 || $code >= 400 || !is_array($d)) return null;
    // Disrupty embrulha a venda em 'sale'; aceita tambem 'data' ou objeto plano.
    $tx = $d;
    if (isset($d['sale']) && is_array($d['sale'])) $tx = $d['sale'];
    elseif (isset($d['data']) && is_array($d['data'])) $tx = $d['data'];
    $statusRaw = isset($tx['status']) ? (string)$tx['status'] : '';
    $paidAt = null;
    foreach (array('date_approved','paid_at','payedAt','paidAt','paid_date') as $k) {
        if (!empty($tx[$k])) { $paidAt = (string)$tx[$k]; break; }
    }
    $utm = recon_utmify_map($statusRaw);
    if ($utm === 'waiting_payment' && $paidAt !== null) $utm = 'paid';
    if ($utm === 'paid' && ($paidAt === null || $paidAt === '')) $paidAt = date('c');
    return array('utmify' => $utm, 'paidAt' => $paidAt, 'raw' => $statusRaw);
}

// ---------------------------------------------------------------------------
// RELATORIO (nao consulta o gateway — visao rapida de pendencias)
// ---------------------------------------------------------------------------
if ($REPORT) {
    $dir = cache_dir();
    $pend = 0; $paid = 0; $other = 0; $recent = 0;
    if ($dir !== '') {
        foreach (glob($dir . '/order_*.json') as $f) {
            $o = json_decode(@file_get_contents($f), true);
            if (!is_array($o)) continue;
            $st = isset($o['status']) ? $o['status'] : '';
            $age = $now - (int)@filemtime($f);
            if ($age <= $MAX_AGE) $recent++;
            if ($st === 'waiting_payment') $pend++;
            elseif ($st === 'paid') $paid++;
            else $other++;
        }
    }
    $dbPend = 0; $dbPaid = 0;
    $db = recovery_db();
    if ($db) {
        $dbPend = (int)$db->query('SELECT COUNT(*) FROM transactions WHERE status="PENDING" AND created_at > ' . ($now - $MAX_AGE))->fetchColumn();
        $dbPaid = (int)$db->query('SELECT COUNT(*) FROM transactions WHERE status="PAID"  AND created_at > ' . ($now - $MAX_AGE))->fetchColumn();
    }
    rlog('RELATORIO (janela ' . $MAX_AGE_DAYS . 'd) — UTMify cache: pendentes=' . $pend . ' pagos=' . $paid . ' outros=' . $other . ' | banco: PENDING=' . $dbPend . ' PAID=' . $dbPaid);
    exit;
}

// ---------------------------------------------------------------------------
// EXECUCAO — reconciliacao
// ---------------------------------------------------------------------------
list($utToken, $utUrl) = recon_utmify_creds();
if (recon_disrupty_keys() === null && recon_kupfy_keys() === null && recon_genesys_keys() === null && recon_velani_keys() === null) { rlog('ERRO: nenhum gateway configurado (Disrupty/Kupfy/Genesys/Velani) — nada a fazer.'); exit; }
if ($utToken === '') rlog('AVISO: token UTMify ausente — vou marcar no banco, mas NAO reenviar para a UTMify.');
if ($DRY) rlog('MODO SIMULACAO (dry-run): nenhuma alteracao sera gravada.');

$dir = cache_dir();

// Lock: impede execucoes sobrepostas (cron muito frequente = varias instancias ao mesmo tempo).
$__lockPath = ($dir !== '' ? $dir : sys_get_temp_dir()) . '/recon.lock';
$__lock = @fopen($__lockPath, 'c');
if ($__lock && !@flock($__lock, LOCK_EX | LOCK_NB)) {
    rlog('Outra reconciliacao ja esta em execucao — abortando esta instancia.');
    exit;
}
$checked = 0; $paidF = 0; $refusedF = 0; $refundF = 0; $chargeF = 0; $skipped = 0; $errors = 0; $backfilled = 0;

// ---- PASSADA 1: cache de pedidos da UTMify (fonte que alimenta o rastreamento)
$files = ($dir !== '') ? glob($dir . '/order_*.json') : array();
// Rotacao: consulta primeiro quem nunca foi verificado (ou foi verificado ha mais tempo),
// para que o limite por execucao cubra TODO o backlog em poucas rodadas em vez de
// reverificar sempre os mesmos primeiros arquivos do glob.
$__lastCheck = array();
foreach ($files as $__f) {
    $__j = json_decode(@file_get_contents($__f), true);
    $__lastCheck[$__f] = (is_array($__j) && isset($__j['recon_last_check'])) ? (int)$__j['recon_last_check'] : 0;
}
usort($files, function ($a, $b) use ($__lastCheck) {
    return $__lastCheck[$a] <=> $__lastCheck[$b];
});
rlog('Passada 1: ' . count($files) . ' pedidos no cache UTMify (janela ' . $MAX_AGE_DAYS . 'd).');

foreach ($files as $f) {
    if ($checked >= $MAX_PER_RUN) { rlog('Limite de consultas por execucao atingido (' . $MAX_PER_RUN . ').'); break; }
    $age = $now - (int)@filemtime($f);
    if ($age > $MAX_AGE) continue;
    $order = json_decode(@file_get_contents($f), true);
    if (!is_array($order)) continue;
    $curStatus = isset($order['status']) ? (string)$order['status'] : '';
    $orderId = isset($order['orderId']) ? (string)$order['orderId'] : '';
    if ($orderId === '') continue;

    // BACKFILL: pedido ja em status terminal cujo envio a UTMify falhou na hora
    // (sem a flag utmify_synced). Reenvia direto — a UTMify faz upsert por
    // orderId, entao reenviar e seguro e nao duplica.
    $terminais = array('paid', 'refused', 'refunded', 'chargedback');
    if (in_array($curStatus, $terminais, true)) {
        $synced = isset($order['utmify_synced']) ? (string)$order['utmify_synced'] : '';
        if ($synced === $curStatus) continue; // ja entregue a UTMify
        if ($DRY) { rlog('[dry] backfill ' . substr($orderId, 0, 12) . '... -> ' . $curStatus); $backfilled++; continue; }
        $res = recon_utmify_send($order);
        $bc = isset($res['http_status']) ? (int)$res['http_status'] : 0;
        if ($bc >= 200 && $bc < 300) {
            $order['utmify_synced'] = $curStatus;
            @file_put_contents($f, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $backfilled++;
            rlog('BACKFILL UTMify: ' . substr($orderId, 0, 14) . '... (' . $curStatus . ') reenviado.');
        } else {
            $errors++;
            rlog('BACKFILL UTMify falhou: ' . substr($orderId, 0, 14) . '... http=' . $bc . ' (retenta no proximo ciclo).');
        }
        continue;
    }
    if ($curStatus !== 'waiting_payment') continue;

    $checked++;
    // Marca de rotacao: registra quando este pedido foi consultado, preservando o
    // mtime do arquivo (o mtime define a janela de idade em dias — nao pode rejuvenescer).
    $order['recon_last_check'] = $now;
    if (!$DRY) {
        $__mt = (int)@filemtime($f);
        @file_put_contents($f, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($__mt) @touch($f, $__mt);
    }
    $st = recon_check_status($orderId);
    usleep(120000); // ~0.12s entre chamadas — nao martelar o gateway
    if ($st === null) { $skipped++; continue; }
    $utm = $st['utmify'];
    if ($utm === 'waiting_payment') { continue; } // ainda pendente de verdade

    // Status terminal detectado — atualiza JSON + reenvia UTMify (idempotente).
    if ($DRY) {
        rlog('[dry] ' . substr($orderId, 0, 12) . '... -> ' . $utm . ' (raw=' . $st['raw'] . ')');
    } else {
        $order['status'] = $utm;
        if ($utm === 'paid')       $order['approvedDate'] = recon_utmify_date($st['paidAt']);
        elseif ($utm === 'refunded') $order['refundedAt'] = recon_utmify_date();
        $res = recon_utmify_send($order);
        $sc = isset($res['http_status']) ? (int)$res['http_status'] : 0;
        if ($sc >= 200 && $sc < 300) {
            $order['utmify_synced'] = $utm; // entregue a UTMify — nao reenviar
        } else {
            $errors++; // sem flag: o backfill do proximo ciclo reenvia sozinho
        }
        @file_put_contents($f, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        // Notificacoes push (antes de marcar como pago, para detectar recuperacao).
        if (function_exists('notify_on_paid_identifiers')) {
            try {
                if ($utm === 'paid') {
                    notify_on_paid_identifiers(array($orderId));
                } elseif ($utm === 'refused') {
                    notify_event_expirado($orderId);
                } elseif ($utm === 'refunded' || $utm === 'chargedback') {
                    $amt = isset($order['commission']['totalPriceInCents']) ? ((int)$order['commission']['totalPriceInCents']) / 100 : 0;
                    notify_event_estorno($orderId, $amt, $st['raw']);
                }
            } catch (\Throwable $e) {}
        }
        // Banco de recuperacao: para os e-mails quando pago.
        if ($utm === 'paid' && function_exists('recovery_mark_paid')) {
            try { recovery_mark_paid(array($orderId, isset($order['orderId']) ? $order['orderId'] : ''), $st['paidAt']); } catch (\Throwable $e) {}
        }
        rlog(strtoupper($utm) . ' reconciliado: ' . substr($orderId, 0, 14) . '... (raw=' . $st['raw'] . ($utm === 'paid' ? ', pagoEm=' . $order['approvedDate'] : '') . ')');
    }
    if ($utm === 'paid') $paidF++;
    elseif ($utm === 'refused') $refusedF++;
    elseif ($utm === 'refunded') $refundF++;
    elseif ($utm === 'chargedback') $chargeF++;
}

rlog('Passada 1 concluida: verificados=' . $checked . ' | PAGOS=' . $paidF . ' recusados=' . $refusedF . ' estornados=' . $refundF . ' chargeback=' . $chargeF . ' indeterminados=' . $skipped . ' backfill_utmify=' . $backfilled . ' erros_utmify=' . $errors);

// ---- PASSADA 2: banco de recuperacao (PENDING sem pedido no cache UTMify)
// Garante que vendas pagas saiam de PENDING no banco (parando e-mails) mesmo que
// nao exista JSON de pedido correspondente.
$db = recovery_db();
if ($db) {
    $known = array();
    foreach ($files as $f) {
        $o = json_decode(@file_get_contents($f), true);
        if (is_array($o) && isset($o['orderId'])) $known[(string)$o['orderId']] = true;
    }
    // zip 75: ids alternativos que ja apontam para um pedido conhecido tambem
    // contam como "tratados" (evita 2o pedido na UTMify para o mesmo PIX).
    $aliasFiles = ($dir !== '') ? glob($dir . '/alias_*.json') : array();
    if (is_array($aliasFiles)) foreach ($aliasFiles as $af) {
        $aj = json_decode((string)@file_get_contents($af), true);
        if (is_array($aj) && !empty($aj['txid']) && isset($known[(string)$aj['txid']])) {
            $known[preg_replace('/^alias_(.*)\.json$/', '$1', basename($af))] = true;
        }
    }
    $rows = $db->query('SELECT * FROM transactions
        WHERE status = "PENDING" AND created_at > ' . ($now - $MAX_AGE) . '
        ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $p2checked = 0; $p2paid = 0;
    $p2budget = 60; // orcamento proprio da Passada 2 — nao compete com o limite da Passada 1
    foreach ($rows as $r) {
        if ($p2checked >= $p2budget) break;
        $txid = (string)$r['txid'];
        if ($txid === '' || isset($known[$txid])) continue; // ja tratado na passada 1
        $checked++; $p2checked++;
        $st = recon_check_status($txid);
        usleep(120000);
        if ($st === null) continue;
        if ($st['utmify'] === 'paid') {
            if (!$DRY) {
                if (function_exists('notify_on_paid_identifiers')) {
                    try { notify_on_paid_identifiers(array($txid, (string)$r['identifier'])); } catch (\Throwable $e) {}
                }
                try { recovery_mark_paid(array($txid, (string)$r['identifier']), $st['paidAt']); } catch (\Throwable $e) {}
                // Sincroniza a venda paga tambem na UTMify (antes so o push saia,
                // e o dashboard ficava para tras do relatorio de notificacoes).
                recon_utmify_paid_from_row(array($txid, (string)$r['identifier']), $r, $st['paidAt']);
            }
            $p2paid++;
            rlog(($DRY ? '[dry] ' : '') . 'PAGO (somente banco) reconciliado: ' . substr($txid, 0, 14) . '...');
        }
    }
    rlog('Passada 2 concluida: verificados=' . $p2checked . ' | PAGOS marcados no banco=' . $p2paid);
}

// Resumo diario por push (so envia se os numeros mudaram desde o ultimo ciclo).
if (!$DRY && function_exists('notify_daily_summary')) {
    try { notify_daily_summary(); } catch (\Throwable $e) {}
}

rlog('Reconciliacao finalizada.');
