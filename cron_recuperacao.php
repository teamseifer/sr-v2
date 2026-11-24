<?php
/**
 * =============================================================================
 * CRON DE RECUPERACAO DE PIX — dispara os e-mails da sequencia
 * =============================================================================
 * Agende no aaPanel (Cron) para rodar a cada 10 minutos:
 *   Tipo: Shell Script
 *   Comando: php /www/wwwroot/SEU_SITE/cron_recuperacao.php
 *
 * Tambem pode ser chamado via navegador para TESTE (exige o token):
 *   https://seudominio.com/cron_recuperacao.php?token=SEU_RECOVERY_SECRET
 *
 * PASSADA 1 — FRETE (productType 'acordo'), PIX gerado e nao pago:
 *   E-mail 1: ~20 min apos gerar o PIX  (lembrete + codigo PIX)
 *   E-mail 2: ~2 h                       (urgencia / reserva expirando)
 *   E-mail 3: ~20 h                      (ultima chance + desconto)
 * A sequencia PARA automaticamente quando o PIX e pago.
 *
 * PASSADA 2 — UPSELLS (recuperacao de TODAS as etapas do funil):
 *   Para cada cliente que PAGOU alguma etapa mas nao avancou, envia e-mails
 *   tematicos da PROXIMA etapa pendente (ex.: pagou acordo e parou -> e-mail
 *   do IOF; pagou IOF e parou -> e-mail da Nota Fiscal; e assim por diante).
 *   E-mail 1: ~30 min apos a ultima atividade | E-mail 2: ~6 h depois.
 *   Maximo de 2 e-mails por (e-mail, etapa) — controle na tabela upsell_emails.
 *   O link leva direto a pagina do upsell com ?src=email&t=..&k=.. (a pagina
 *   pre-preenche os dados e o produto ganha o sufixo '-email' no gateway).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
set_time_limit(280);

// ---------------------------------------------------------------------------
// BOOTSTRAP: carrega .env + lib de recuperacao (mesmos caminhos do api_proxy)
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
// Notificacoes push no celular (opcional — nunca quebra o cron se ausente).
$__notifyLib = __DIR__ . '/.private/notify_lib.php';
if (@is_readable($__notifyLib)) {
    try { require_once $__notifyLib; } catch (\Throwable $e) { @error_log('[notify] falha ao carregar lib: ' . $e->getMessage()); }
}

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

function rlog($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents(__DIR__ . '/.private/recovery.log', $line . "\n", FILE_APPEND);
}

// ---------------------------------------------------------------------------
// CONSULTA DE STATUS DIRETO NA DISRUPTY (evita e-mail para PIX ja pago)
// ---------------------------------------------------------------------------
function cron_disrupty_base() {
    $b = function_exists('env_get') ? env_get('DISRUPTY_BASE_URL') : @getenv('DISRUPTY_BASE_URL');
    if (!is_string($b) || trim($b) === '') $b = 'https://api-sellers.disruptybr.app';
    return rtrim(trim($b), '/');
}

function cron_disrupty_keys() {
    $pub  = function_exists('env_get') ? env_get('DISRUPTY_PUBLIC_KEY')  : @getenv('DISRUPTY_PUBLIC_KEY');
    $priv = function_exists('env_get') ? env_get('DISRUPTY_PRIVATE_KEY') : @getenv('DISRUPTY_PRIVATE_KEY');
    $pub  = is_string($pub)  ? trim($pub)  : '';
    $priv = is_string($priv) ? trim($priv) : '';
    if ($pub === '' || $priv === '' || strpos($pub, 'COLOQUE_') === 0 || strpos($priv, 'COLOQUE_') === 0) return null;
    return array($pub, $priv);
}

function cron_http_json($method, $url, $payload = null, $extraHeaders = array()) {
    $headers = array('Accept: application/json');
    foreach ((array)$extraHeaders as $h) { if (is_string($h) && $h !== '') $headers[] = $h; }
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
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

function cron_kupfy_base() {
    $b = function_exists('env_get') ? env_get('KUPFY_BASE_URL') : @getenv('KUPFY_BASE_URL');
    if (!is_string($b) || trim($b) === '') $b = 'https://app.kupfy.com/api/v1';
    return rtrim(trim($b), '/');
}

function cron_kupfy_keys() {
    $pub = function_exists('env_get') ? env_get('KUPFY_PUBLIC_KEY') : @getenv('KUPFY_PUBLIC_KEY');
    $sec = function_exists('env_get') ? env_get('KUPFY_SECRET_KEY') : @getenv('KUPFY_SECRET_KEY');
    if (!is_string($sec) || trim($sec) === '') {
        $sec = function_exists('env_get') ? env_get('KUPFY_PRIVATE_KEY') : @getenv('KUPFY_PRIVATE_KEY');
    }
    $pub = is_string($pub) ? trim($pub) : '';
    $sec = is_string($sec) ? trim($sec) : '';
    if ($pub === '' || $sec === '' || strpos($pub, 'COLOQUE_') === 0 || strpos($sec, 'COLOQUE_') === 0) return null;
    return array($pub, $sec);
}

// Consulta o status de uma transacao na KUPFY (GET /gateway/transactions?id=).
function cron_check_paid_kupfy($txid) {
    $keys = cron_kupfy_keys();
    if ($keys === null) return null;
    list($pub, $sec) = $keys;
    $url = cron_kupfy_base() . '/gateway/transactions?id=' . rawurlencode($txid);
    list($code, $d) = cron_http_json('GET', $url, null, array(
        'x-public-key: ' . $pub,
        'x-secret-key: ' . $sec,
    ));
    if ($code === 0 || $code >= 400 || !is_array($d)) return null;
    if (isset($d['error'])) return null; // ex.: TOO_MANY_REQUESTS — trata como desconhecido
    $tx = $d;
    if (isset($d['transaction']) && is_array($d['transaction'])) $tx = $d['transaction'];
    elseif (isset($d['data']) && is_array($d['data'])) $tx = $d['data'];
    $status = isset($tx['status']) ? strtoupper((string)$tx['status']) : '';
    $paidAt = null;
    foreach (array('payedAt', 'paidAt', 'paid_at', 'date_approved') as $k) {
        if (!empty($tx[$k])) { $paidAt = (string)$tx[$k]; break; }
    }
    $paidSet = array('PAID','PAGO','COMPLETED','PAYED','APPROVED','SUCCESS','CONFIRMED','AUTHORIZED');
    if (in_array($status, $paidSet, true) || $paidAt !== null) return array('paid' => true, 'paidAt' => $paidAt ?: date('c'));
    $failSet = array('FAILED','REJECTED','CANCELLED','CANCELED','EXPIRED','REFUNDED','CHARGED_BACK','CHARGEDBACK',
        'CANCELADO','REEMBOLSADO','ESTORNADO','CHARGEBACK','EXPIRADO');
    if (in_array($status, $failSet, true)) return array('paid' => false, 'failed' => true);
    return array('paid' => false);
}

// ===== GENESYS FINANCE (V28) =====
function cron_genesys_base() {
    $b = function_exists('env_get') ? env_get('GENESYS_BASE_URL') : @getenv('GENESYS_BASE_URL');
    if (!is_string($b) || trim($b) === '') $b = 'https://api.genesys.finance';
    return rtrim(trim($b), '/');
}

function cron_genesys_keys() {
    $hash = function_exists('env_get') ? env_get('GENESYS_ACCOUNT_HASH') : @getenv('GENESYS_ACCOUNT_HASH');
    $tok  = function_exists('env_get') ? env_get('GENESYS_API_TOKEN') : @getenv('GENESYS_API_TOKEN');
    if (!is_string($tok) || trim($tok) === '') {
        $tok = function_exists('env_get') ? env_get('GENESYS_API_SECRET') : @getenv('GENESYS_API_SECRET');
    }
    $hash = is_string($hash) ? trim($hash) : '';
    $tok  = is_string($tok) ? trim($tok) : '';
    if ($hash === '' || $tok === '' || strpos($hash, 'COLOQUE_') === 0 || strpos($tok, 'COLOQUE_') === 0) return null;
    return array($hash, $tok);
}

// Consulta o status de uma transacao na GENESYS (GET /v1/transactions/{id}).
function cron_check_paid_genesys($txid) {
    $keys = cron_genesys_keys();
    if ($keys === null) return null;
    list($hash, $tok) = $keys;
    $url = cron_genesys_base() . '/v1/transactions/' . rawurlencode($txid);
    list($code, $d) = cron_http_json('GET', $url, null, array(
        'api-secret: ' . $tok,
        'Authorization: Bearer ' . $tok,
        'account-hash: ' . $hash,
        'Accept: application/json',
    ));
    if ($code === 0 || $code >= 400 || !is_array($d)) return null;
    $tx = $d;
    if (isset($d['data']) && is_array($d['data'])) $tx = $d['data'];
    elseif (isset($d['transaction']) && is_array($d['transaction'])) $tx = $d['transaction'];
    $status = isset($tx['status']) ? strtoupper((string)$tx['status']) : '';
    $paidAt = null;
    foreach (array('paid_at', 'paidAt', 'payedAt', 'date_approved') as $k) {
        if (!empty($tx[$k])) { $paidAt = (string)$tx[$k]; break; }
    }
    $paidSet = array('PAID','PAGO','COMPLETED','PAYED','APPROVED','SUCCESS','CONFIRMED','AUTHORIZED');
    if (in_array($status, $paidSet, true) || $paidAt !== null) return array('paid' => true, 'paidAt' => $paidAt ?: date('c'));
    $failSet = array('FAILED','REJECTED','CANCELLED','CANCELED','EXPIRED','REFUNDED','CHARGED_BACK','CHARGEDBACK',
        'CANCELADO','REEMBOLSADO','ESTORNADO','CHARGEBACK','EXPIRADO');
    if (in_array($status, $failSet, true)) return array('paid' => false, 'failed' => true);
    return array('paid' => false);
}

// ===== VELANI PAGAMENTOS =====
function cron_velani_base() {
    $b = function_exists('env_get') ? env_get('VELANI_BASE_URL') : @getenv('VELANI_BASE_URL');
    if (!is_string($b) || trim($b) === '') $b = 'https://api.velanipagamentos.com.br/api/v1/api-gateway';
    return rtrim(trim($b), '/');
}

function cron_velani_keys() {
    // Prefere SEMPRE a secret key (sk_live_...); a public key (pk_live_...) so consulta.
    $fallback = null;
    foreach (array('VELANI_SECRET_KEY', 'VELANI_API_KEY', 'VELANI_PUBLIC_KEY') as $name) {
        $k = function_exists('env_get') ? env_get($name) : @getenv($name);
        $k = is_string($k) ? trim($k, " \t\"'") : '';
        if ($k === '' || strpos($k, 'COLOQUE_') === 0) continue;
        if (stripos($k, 'sk_') === 0) return $k;
        if ($fallback === null) $fallback = $k;
    }
    return $fallback;
}

// Consulta o status de uma transacao na VELANI (GET /v1/transactions/{id}, header x-api-key).
function cron_check_paid_velani($txid) {
    $key = cron_velani_keys();
    if ($key === null) return null;
    $url = cron_velani_base() . '/v1/transactions/' . rawurlencode($txid);
    list($code, $d) = cron_http_json('GET', $url, null, array(
        'x-api-key: ' . $key,
        'Accept: application/json',
    ));
    if ($code === 0 || $code >= 400 || !is_array($d)) return null;
    if (isset($d['success']) && $d['success'] === false) return null;
    $tx = $d;
    if (isset($d['data']) && is_array($d['data'])) $tx = $d['data'];
    elseif (isset($d['transaction']) && is_array($d['transaction'])) $tx = $d['transaction'];
    $status = isset($tx['status']) ? strtoupper((string)$tx['status']) : '';
    $paidAt = null;
    foreach (array('paidAt', 'paid_at', 'payedAt', 'date_approved') as $k) {
        if (!empty($tx[$k])) { $paidAt = (string)$tx[$k]; break; }
    }
    $paidSet = array('PAID','PAGO','COMPLETED','PAYED','APPROVED','SUCCESS','CONFIRMED','AUTHORIZED');
    if (in_array($status, $paidSet, true) || $paidAt !== null) return array('paid' => true, 'paidAt' => $paidAt ?: date('c'));
    $failSet = array('FAILED','REJECTED','CANCELLED','CANCELED','EXPIRED','REFUNDED','CHARGED_BACK','CHARGEDBACK',
        'CANCELADO','REEMBOLSADO','ESTORNADO','CHARGEBACK','EXPIRADO');
    if (in_array($status, $failSet, true)) return array('paid' => false, 'failed' => true);
    return array('paid' => false);
}

// Wrapper multi-gateway: tenta VELANI primeiro (se configurada), depois GENESYS, KUPFY
// e cai para a Disrupty — transacoes antigas continuam cobertas na transicao.

/** CHAVE RAPIDA DE GATEWAY: le <pasta acima do webroot>/gateway-<slug>.txt ou gateway.txt (uma palavra:
 *  genesys | velani | kupfy | disrupty | auto). O gateway escolhido e consultado PRIMEIRO; os demais continuam
 *  como fallback (transacoes antigas do gateway anterior seguem cobertas). */
function cron_gateway_switch() {
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

function cron_check_paid($txid) {
    if (!function_exists('curl_init')) return null; // desconhecido
    $sw = cron_gateway_switch();
    if ($sw !== '' && $sw !== 'disrupty') {
        $kf = 'cron_%s_keys'; $cf = 'cron_check_paid_' . $sw;
        if (function_exists(sprintf($kf, $sw)) && call_user_func(sprintf($kf, $sw)) !== null && function_exists($cf)) {
            $rs = call_user_func($cf, $txid);
            if (is_array($rs)) return $rs;
        }
    } elseif ($sw === 'disrupty') {
        $rd = cron_check_paid_disrupty($txid);
        if (is_array($rd)) return $rd;
    }
    if (cron_velani_keys() !== null) {
        $rv = cron_check_paid_velani($txid);
        if (is_array($rv)) return $rv;
    }
    if (cron_genesys_keys() !== null) {
        $rg = cron_check_paid_genesys($txid);
        if (is_array($rg)) return $rg;
    }
    if (cron_kupfy_keys() !== null) {
        $rk = cron_check_paid_kupfy($txid);
        if (is_array($rk)) return $rk;
    }
    return cron_check_paid_disrupty($txid);
}

function cron_check_paid_disrupty($txid) {
    $keys = cron_disrupty_keys();
    if ($keys === null) return null;
    list($pub, $priv) = $keys;
    $url = cron_disrupty_base() . '/api/sales/' . rawurlencode($txid);
    list($code, $d) = cron_http_json('GET', $url, null, array(
        'x-api-public-key: ' . $pub,
        'x-api-private-key: ' . $priv,
    ));
    if ($code === 0 || $code >= 400 || !is_array($d)) return null;
    // Disrupty embrulha a venda em 'sale'; aceita tambem 'data' ou objeto plano.
    $tx = $d;
    if (isset($d['sale']) && is_array($d['sale'])) $tx = $d['sale'];
    elseif (isset($d['data']) && is_array($d['data'])) $tx = $d['data'];
    $status = isset($tx['status']) ? strtoupper((string)$tx['status']) : '';
    $paidAt = null;
    foreach (array('date_approved', 'paid_at', 'payedAt', 'paidAt', 'paid_date') as $k) {
        if (!empty($tx[$k])) { $paidAt = (string)$tx[$k]; break; }
    }
    $paidSet = array('PAID','PAGO','COMPLETED','PAYED','APPROVED','SUCCESS','CONFIRMED','AUTHORIZED');
    if (in_array($status, $paidSet, true) || $paidAt !== null) return array('paid' => true, 'paidAt' => $paidAt ?: date('c'));
    $failSet = array('FAILED','REJECTED','CANCELLED','CANCELED','EXPIRED','REFUNDED','CHARGED_BACK','CHARGEDBACK',
        'CANCELADO','REEMBOLSADO','ESTORNADO','CHARGEBACK','EXPIRADO');
    if (in_array($status, $failSet, true)) return array('paid' => false, 'failed' => true);
    return array('paid' => false);
}

// ---------------------------------------------------------------------------
// EXECUCAO
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// UTMIFY — sincroniza a venda PAGA tambem no rastreamento (idempotente).
// Antes, vendas detectadas como pagas pelo CRON ganhavam push e paravam os
// e-mails, mas NUNCA eram enviadas a UTMify — o dashboard ficava para tras
// (relatorio de push maior que o de vendas na UTMify).
// ---------------------------------------------------------------------------
function cron_utmify_cache_dir() {
    $dir = __DIR__ . '/.private/cache';
    if (is_dir($dir) && is_writable($dir)) return $dir;
    $tmp = sys_get_temp_dir() . '/funil_sr_cache';
    return is_dir($tmp) ? $tmp : $dir;
}
function cron_utmify_env($k, $d = '') {
    $v = getenv($k);
    if (($v === false || $v === '') && isset($_ENV[$k])) $v = $_ENV[$k];
    $v = is_string($v) ? trim($v) : '';
    return $v !== '' ? $v : $d;
}
function cron_utmify_mark_paid($identifiers, $row, $paidAt) {
    try {
        $token = cron_utmify_env('UTMIFY_API_TOKEN');
        if ($token === '') { rlog('[utmify] token ausente — venda paga NAO sincronizada no rastreamento.'); return; }
        $url = cron_utmify_env('UTMIFY_API_URL', 'https://api.utmify.com.br/api-credentials/orders');
        $dir = cron_utmify_cache_dir();
        $order = null; $file = null;
        foreach ((array)$identifiers as $key) {
            $key = (string)$key; if ($key === '') continue;
            $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
            $f = $dir . '/order_' . $safe . '.json';
            if (is_file($f)) {
                $tmp = json_decode((string)@file_get_contents($f), true);
                if (is_array($tmp)) { $order = $tmp; $file = $f; break; }
            }
        }
        if (!$order && is_array($row)) {
            // Reconstroi pedido minimo (sem UTMs) — a venda conta no total.
            $key  = (string)$identifiers[0];
            $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
            $file = $dir . '/order_' . $safe . '.json';
            $amountCents = (int)round(((float)(isset($row['amount']) ? $row['amount'] : 0)) * 100);
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
                'products' => array(array('id' => 'DEP-PIX', 'name' => 'SR Deposito PIX', 'planId' => null, 'planName' => null, 'quantity' => 1, 'priceInCents' => $amountCents)),
                'trackingParameters' => array('src' => null, 'sck' => null, 'utm_source' => null, 'utm_campaign' => null, 'utm_medium' => null, 'utm_content' => null, 'utm_term' => null, 'utm_id' => null, 'fbc' => null, 'fbp' => null),
                'commission' => array('totalPriceInCents' => $amountCents, 'gatewayFeeInCents' => 0, 'userCommissionInCents' => $amountCents, 'currency' => 'BRL'),
            );
        }
        if (!$order) return;
        $synced = isset($order['utmify_synced']) ? (string)$order['utmify_synced'] : '';
        if ($synced === 'paid') return; // ja entregue a UTMify
        $order['status'] = 'paid';
        $t = $paidAt ? strtotime((string)$paidAt) : false;
        $order['approvedDate'] = gmdate('Y-m-d H:i:s', $t ?: time());
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json', 'x-api-token: ' . $token),
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ));
        @curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) { $order['utmify_synced'] = 'paid'; }
        if ($file) @file_put_contents($file, json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rlog('[utmify] venda paga sincronizada via cron: ' . substr((string)$identifiers[0], 0, 14) . '... http=' . $code . ($code >= 300 || $code < 200 ? ' (sera retentado)' : ''));
    } catch (\Throwable $e) { rlog('[utmify] erro no sync do cron: ' . $e->getMessage()); }
}

$db = recovery_db();
if (!$db) { rlog('ERRO: banco indisponivel (pdo_sqlite ausente?)'); exit; }

// Janelas da sequencia (segundos)
$STAGES = array(
    1 => 20 * 60,        // 20 min
    2 => 2 * 3600,       // 2 h
    3 => 20 * 3600,      // 20 h
);
$MAX_EMAILS_PER_RUN = 40;   // protecao contra throttling do SMTP
$MAX_AGE = 3 * 86400;       // ignora transacoes com mais de 3 dias

$now = time();
$sent = 0; $checked = 0; $paidFound = 0;

// Somente PIX do FRETE, pendentes, com e-mail real, dentro da janela de 3 dias
$rows = $db->query('SELECT * FROM transactions
    WHERE status = "PENDING" AND product_type = "acordo"
      AND email <> "" AND emails_sent < 3
      AND created_at > ' . ($now - $MAX_AGE) . '
    ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

rlog('Iniciando varredura: ' . count($rows) . ' PIX de acordo pendentes.');

// Dedupe por e-mail: processa apenas a transacao MAIS RECENTE de cada e-mail
// e ignora e-mails que ja possuem um acordo PAGO (lead ja convertido).
$latestByEmail = array();
foreach ($rows as $r) {
    $em = strtolower(trim($r['email']));
    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
    if (!isset($latestByEmail[$em])) $latestByEmail[$em] = $r; // rows ja ordenadas DESC
}

$stPaidCheck = $db->prepare('SELECT COUNT(*) FROM transactions WHERE email = ? AND product_type = "acordo" AND status = "PAID"');

foreach ($latestByEmail as $em => $row) {
    if ($sent >= $MAX_EMAILS_PER_RUN) { rlog('Limite de envios por execucao atingido.'); break; }

    // Lead ja pagou algum acordo? Pula.
    $stPaidCheck->execute(array($em));
    if ((int)$stPaidCheck->fetchColumn() > 0) continue;

    $age = $now - (int)$row['created_at'];
    $next = (int)$row['emails_sent'] + 1;
    if (!isset($STAGES[$next]) || $age < $STAGES[$next]) continue; // ainda nao chegou a hora

    // Confere na Disrupty se ja foi pago (nunca enviar cobranca de PIX pago).
    $checked++;
    $chk = cron_check_paid($row['txid']);
    if (is_array($chk) && !empty($chk['paid'])) {
        if (function_exists('notify_on_paid_identifiers')) {
            try { notify_on_paid_identifiers(array($row['txid'], $row['identifier'])); } catch (\Throwable $e) {}
        }
        recovery_mark_paid(array($row['txid'], $row['identifier']), $chk['paidAt']);
        cron_utmify_mark_paid(array($row['txid'], $row['identifier']), $row, $chk['paidAt']);
        $paidFound++;
        continue;
    }

    $mail = recovery_build_email($next, $row);
    $err = null;
    $ok = recovery_send_mail($row['email'], $row['name'], $mail['subject'], $mail['html'], $err);
    if ($ok) {
        $up = $db->prepare('UPDATE transactions SET emails_sent = ?, last_email_at = ? WHERE txid = ?');
        $up->execute(array($next, $now, $row['txid']));
        $sent++;
        if (function_exists('notify_event_email_enviado')) { try { notify_event_email_enviado(); } catch (\Throwable $e) {} }
        rlog('E-mail ' . $next . ' enviado para ' . $em . ' (tx ' . substr($row['txid'], 0, 12) . '…, ' . number_format($row['amount'], 2, ',', '.') . ')');
    } else {
        rlog('FALHA ao enviar para ' . $em . ': ' . $err);
        // Falha de SMTP geralmente afeta todos — interrompe a execucao.
        if ($err && (stripos($err, 'SMTP nao configurado') !== false || stripos($err, 'conexao') !== false || stripos($err, 'AUTH') !== false)) break;
    }
}

rlog('Passada 1 (acordo): ' . $sent . ' e-mails enviados, ' . $paidFound . ' pagamentos detectados, ' . $checked . ' status verificados.');

// ---------------------------------------------------------------------------
// PASSADA 2 — RECUPERACAO DE UPSELLS (todas as etapas do funil)
// ---------------------------------------------------------------------------
$UP_DELAY_1 = 30 * 60;    // e-mail 1: 30 min apos a ultima atividade
$UP_DELAY_2 = 6 * 3600;   // e-mail 2: 6 h apos o e-mail 1
$UP_MAX_AGE = 7 * 86400;  // considera atividade dos ultimos 7 dias
$upSent = 0; $upChecked = 0; $upPaidFound = 0;

$chain = recovery_stage_chain();
$chainIdx = array_flip($chain); // slug => posicao

// Todas as transacoes recentes com e-mail real, agrupadas por e-mail.
$allRows = $db->query('SELECT txid, identifier, product_type, status, name, email, created_at
    FROM transactions
    WHERE email <> "" AND created_at > ' . ($now - $UP_MAX_AGE) . '
    ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$byEmail = array();
foreach ($allRows as $r) {
    $em = strtolower(trim($r['email']));
    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
    $byEmail[$em][] = $r;
}

rlog('Passada 2 (upsells): ' . count($byEmail) . ' clientes com atividade recente.');

$base = recovery_base_url();
$stUp = $db->prepare('SELECT emails_sent, last_email_at FROM upsell_emails WHERE email = ? AND stage = ?');
$stUpIns = $db->prepare('INSERT OR IGNORE INTO upsell_emails (email, stage, emails_sent, last_email_at, created_at) VALUES (?,?,0,NULL,?)');
$stUpUpd = $db->prepare('UPDATE upsell_emails SET emails_sent = ?, last_email_at = ? WHERE email = ? AND stage = ?');

foreach ($byEmail as $em => $txs) {
    if ($sent + $upSent >= $MAX_EMAILS_PER_RUN) { rlog('Limite de envios por execucao atingido (upsells).'); break; }

    // Maior etapa PAGA na cadeia + transacao paga correspondente.
    $maxPaidIdx = -1; $paidRow = null; $lastActivity = 0;
    $paidStages = array(); $pendingByStage = array();
    foreach ($txs as $r) {
        $pt = strtolower(trim((string)$r['product_type']));
        if ((int)$r['created_at'] > $lastActivity) $lastActivity = (int)$r['created_at'];
        if (!isset($chainIdx[$pt])) continue;
        if ($r['status'] === 'PAID') {
            $paidStages[$pt] = true;
            if ($chainIdx[$pt] > $maxPaidIdx) { $maxPaidIdx = $chainIdx[$pt]; $paidRow = $r; }
        } elseif ($r['status'] === 'PENDING' && !isset($pendingByStage[$pt])) {
            $pendingByStage[$pt] = $r; // mais recente (rows em ordem DESC)
        }
    }

    // Sem nenhuma etapa paga -> a Passada 1 (acordo) cuida deste lead.
    if ($maxPaidIdx < 0 || $paidRow === null) continue;
    // Ja concluiu a ultima etapa -> funil completo, nada a recuperar.
    if ($maxPaidIdx >= count($chain) - 1) continue;

    $target = $chain[$maxPaidIdx + 1];
    if (isset($paidStages[$target])) continue; // etapa-alvo ja paga

    // Controle de envios por (e-mail, etapa)
    $stUp->execute(array($em, $target));
    $ctl = $stUp->fetch(PDO::FETCH_ASSOC);
    $sentCount = $ctl ? (int)$ctl['emails_sent'] : 0;
    $lastAt = $ctl && $ctl['last_email_at'] !== null ? (int)$ctl['last_email_at'] : 0;
    if ($sentCount >= 2) continue; // maximo 2 e-mails por etapa

    if ($sentCount === 0) {
        if (($now - $lastActivity) < $UP_DELAY_1) continue; // ainda cedo
    } else {
        if ($lastAt === 0 || ($now - $lastAt) < $UP_DELAY_2) continue;
    }

    // Se existe PIX PENDENTE da etapa-alvo, confere na Disrupty antes de cobrar.
    if (isset($pendingByStage[$target])) {
        $upChecked++;
        $chk = cron_check_paid($pendingByStage[$target]['txid']);
        if (is_array($chk) && !empty($chk['paid'])) {
            if (function_exists('notify_on_paid_identifiers')) {
                try { notify_on_paid_identifiers(array($pendingByStage[$target]['txid'], $pendingByStage[$target]['identifier'])); } catch (\Throwable $e) {}
            }
            recovery_mark_paid(array($pendingByStage[$target]['txid'], $pendingByStage[$target]['identifier']), $chk['paidAt']);
            cron_utmify_mark_paid(array($pendingByStage[$target]['txid'], $pendingByStage[$target]['identifier']), $pendingByStage[$target], $chk['paidAt']);
            $upPaidFound++;
            continue;
        }
    }

    // Link para a pagina do upsell, com token da transacao PAGA anterior
    // (a pagina usa recover_info para pre-preencher os dados do cliente).
    $tok = recovery_token($paidRow['txid']);
    $link = $base . '/upsell/' . rawurlencode($target) . '/?src=email&t=' . rawurlencode($paidRow['txid']) . '&k=' . $tok;

    $n = $sentCount + 1;
    $mail = recovery_build_upsell_email($n, $target, $paidRow, $link);
    $err = null;
    $ok = recovery_send_mail($em, $paidRow['name'], $mail['subject'], $mail['html'], $err);
    if ($ok) {
        $stUpIns->execute(array($em, $target, $now));
        $stUpUpd->execute(array($n, $now, $em, $target));
        $upSent++;
        if (function_exists('notify_event_email_enviado')) { try { notify_event_email_enviado(); } catch (\Throwable $e) {} }
        rlog('Upsell "' . $target . '" e-mail ' . $n . ' enviado para ' . $em . ' (base tx ' . substr($paidRow['txid'], 0, 12) . '…)');
    } else {
        rlog('FALHA upsell para ' . $em . ': ' . $err);
        if ($err && (stripos($err, 'SMTP nao configurado') !== false || stripos($err, 'conexao') !== false || stripos($err, 'AUTH') !== false)) break;
    }
}

rlog('Fim: acordo=' . $sent . ' | upsells=' . $upSent . ' enviados, ' . ($paidFound + $upPaidFound) . ' pagamentos detectados, ' . ($checked + $upChecked) . ' status verificados.');

// ---------------------------------------------------------------------------
// PASSADA 3 — BACKSTOP DO ENTREGAVEL (confirmacao do acordo)
// Acordos PAGOS que ainda nao receberam o e-mail com o link do entregavel
// (ex.: pagamento detectado antes do deploy desta versao, ou falha de SMTP
// no momento do mark_paid). Envia e marca deliverable_sent=1.
// ---------------------------------------------------------------------------
$delivSent = 0;
if (function_exists('recovery_send_deliverable_row')) {
    $DELIV_MAX_PER_RUN = 30; // protecao contra throttling do SMTP
    // Janela: por padrao so pagamentos dos ultimos 3 dias (evita disparo em massa
    // para todo o historico de acordos pagos antes desta versao). Para liberar o
    // envio retroativo, defina DELIVERABLE_BACKFILL_DAYS maior no .env do mail.
    $DELIV_DAYS = (int)(getenv('DELIVERABLE_BACKFILL_DAYS') ?: 3);
    if ($DELIV_DAYS < 1) $DELIV_DAYS = 1;
    try {
        $delivCut = $now - $DELIV_DAYS * 86400;
        // Busca TODOS os candidatos (PAID + freight + nao enviado + com e-mail) e
        // aplica a janela em PHP considerando created_at OU paid_at (FIX: antes a
        // janela olhava so created_at — PIX criado ha dias e pago hoje, via e-mail
        // de recuperacao, ficava fora e o entregavel NUNCA saia).
        $dAll = $db->query('SELECT * FROM transactions
            WHERE status = "PAID" AND product_type = "acordo"
              AND COALESCE(deliverable_sent,0) = 0 AND email <> ""
            ORDER BY created_at DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        $dRows = array(); $dOutOfWindow = 0;
        foreach ($dAll as $cand) {
            $inWin = ((int)$cand['created_at'] > $delivCut);
            if (!$inWin && !empty($cand['paid_at'])) {
                $pTs = @strtotime((string)$cand['paid_at']);
                if ($pTs && $pTs > $delivCut) $inWin = true;
            }
            if ($inWin) { if (count($dRows) < $DELIV_MAX_PER_RUN) $dRows[] = $cand; }
            else $dOutOfWindow++;
        }
        // Diagnostico: acordos pagos SEM e-mail nunca receberao o entregavel
        $dNoEmail = (int)$db->query('SELECT COUNT(*) FROM transactions
            WHERE status = "PAID" AND product_type = "acordo"
              AND COALESCE(deliverable_sent,0) = 0 AND email = ""')->fetchColumn();
        rlog('[entregavel] candidatos=' . count($dRows) . ' | fora da janela de ' . $DELIV_DAYS . 'd=' . $dOutOfWindow . ' (aumente DELIVERABLE_BACKFILL_DAYS p/ liberar) | pagos SEM e-mail=' . $dNoEmail);
        foreach ($dRows as $dRow) {
            if (!filter_var(strtolower(trim($dRow['email'])), FILTER_VALIDATE_EMAIL)) {
                // e-mail invalido: marca para nao reprocessar eternamente
                $db->prepare('UPDATE transactions SET deliverable_sent=1 WHERE txid=?')->execute(array($dRow['txid']));
                continue;
            }
            if (recovery_send_deliverable_row($dRow)) {
                $delivSent++;
                rlog('[entregavel] link enviado para ' . $dRow['email'] . ' (tx ' . substr($dRow['txid'], 0, 12) . '...)');
            } else {
                rlog('[entregavel] FALHA ao enviar para ' . $dRow['email'] . ' (tx ' . substr($dRow['txid'], 0, 12) . '...)');
                break; // falha de SMTP costuma afetar todos — tenta na proxima execucao
            }
        }
    } catch (\Throwable $e) { rlog('[entregavel] erro na passada: ' . $e->getMessage()); }
    if ($delivSent > 0) rlog('Passada 3 (entregavel): ' . $delivSent . ' e-mails enviados.');
}

// Mantem o log enxuto (ultimas ~500 linhas)
$logFile = __DIR__ . '/.private/recovery.log';
if (@filesize($logFile) > 200 * 1024) {
    $lines = @file($logFile);
    if (is_array($lines)) @file_put_contents($logFile, implode('', array_slice($lines, -500)));
}
