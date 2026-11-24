<?php
// =============================================================================
// trk_lib.php (zip 88) — TRACKING NO SERVIDOR (porte do zip 84 da CNH/CS)
// Carregado pelo api_proxy.php e pelos crons. Aditivo: NADA aqui substitui o
// tracking atual (funil_utms / __getTrackProps / trackProps). So PREENCHE o
// que faltou quando o POST create_pix chegou sem UTM (navegador interno do
// Instagram/Facebook no iPhone perde localStorage/cookie JS).
// Chaves de busca, na ordem: sessao (cookie HTTP trk_sid / trk_id) -> fbclid
// -> CPF consultado (trk_cpf) -> IP + aparelho (janela de 6h).
// Arquivos: .private/cache/trk_<sid|fb|cpf|ipua>_<sha1>.json
// =============================================================================
if (function_exists('trk_keys')) return;

function trk_keys() {
    return array('utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id',
                 'fbclid','src','sck','xcod','gclid','ttclid',
                 'cck','cname','domain','placement','adset','adname','site','xgo','pvid',
                 'fbc','fbp');
}

function trk_dir() {
    if (function_exists('genesys_cache_dir')) return genesys_cache_dir();
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function trk_file($kind, $key) {
    $kind = preg_replace('/[^a-z]/', '', strtolower((string)$kind));
    return trk_dir() . '/trk_' . $kind . '_' . sha1((string)$key) . '.json';
}

/** Le trk_<kind>_<hash>.json; $maxAge em segundos (0 = sem limite). */
function trk_read($kind, $key, $maxAge = 0) {
    if ((string)$key === '') return array();
    $f = trk_file($kind, $key);
    if (!is_file($f)) return array();
    if ($maxAge > 0 && (time() - (int)@filemtime($f)) > $maxAge) return array();
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? trk_normalize($d) : array();
}

/** Grava (merge: nunca sobrescreve valor preenchido por vazio). */
function trk_write($kind, $key, $data) {
    if ((string)$key === '') return false;
    $data = trk_normalize($data);
    if (!trk_has($data)) return false;
    $old = trk_read($kind, $key);
    $new = trk_merge($data, $old);
    $new['_ts'] = time();
    return @file_put_contents(trk_file($kind, $key), json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

/** Deriva utm_* dos parametros do cloaker (cck/cname/adset/adname/placement/site). So preenche o que falta. */
function trk_derive($o) {
    $g = function ($k) use ($o) { return isset($o[$k]) ? (string)$o[$k] : ''; };
    if ($g('utm_campaign') === '' && ($g('cname') !== '' || $g('cck') !== '')) $o['utm_campaign'] = ($g('cname') !== '' && $g('cck') !== '') ? $g('cname') . '|' . $g('cck') : ($g('cname') !== '' ? $g('cname') : $g('cck'));
    if ($g('utm_id') === '' && $g('cck') !== '') $o['utm_id'] = $g('cck');
    if ($g('utm_medium') === '' && $g('adset') !== '') $o['utm_medium'] = $g('adset');
    if ($g('utm_content') === '' && $g('adname') !== '') $o['utm_content'] = $g('adname');
    if ($g('utm_term') === '' && $g('placement') !== '') $o['utm_term'] = $g('placement');
    if ($g('utm_source') === '' && ($g('site') !== '' || $g('cck') !== '' || $g('fbclid') !== '')) $o['utm_source'] = $g('site') !== '' ? $g('site') : 'FB';
    if ($g('fbc') === '' && $g('fbclid') !== '') $o['fbc'] = 'fb.1.' . time() . '.' . $g('fbclid');
    return $o;
}

/** Normaliza: aceita chaves "?cck"/"%3Fcck"/"amp;", utmSource/{source,...}; descarta vazios/organic/{{; so chaves conhecidas. */
function trk_normalize($in) {
    if (!is_array($in)) return array();
    $keys = trk_keys();
    $o = array();
    foreach ($in as $k => $v) {
        if (!is_scalar($v)) continue;
        $k = strtolower(trim((string)$k));
        $k = preg_replace('/^(?:%3f|\?|amp;|&)+/i', '', $k);
        if (in_array($k, array('source','medium','campaign','content','term','id'), true)) $k = 'utm_' . $k;
        if (preg_match('/^utm([a-z]+)$/', $k, $mm) && in_array($mm[1], array('source','medium','campaign','content','term','id'), true)) $k = 'utm_' . $mm[1];
        if (!in_array($k, $keys, true)) continue;
        $v = trim((string)$v);
        if ($v === '' || $v === 'null' || $v === 'undefined') continue;
        if (strpos($k, 'utm_') === 0 && (stripos($v, 'organic') === 0 || $v === '::' || strpos($v, '{{') !== false)) continue;
        $v = substr($v, 0, 300);
        if (!isset($o[$k]) || $o[$k] === '') $o[$k] = $v;
    }
    return trk_derive($o);
}

/** Preenche em $base o que estiver vazio com $extra. */
function trk_merge($base, $extra) {
    if (!is_array($base)) $base = array();
    if (!is_array($extra)) return $base;
    foreach ($extra as $k => $v) {
        if ($k === '_ts') continue;
        if ((!isset($base[$k]) || (string)$base[$k] === '') && is_scalar($v) && (string)$v !== '') $base[$k] = (string)$v;
    }
    return trk_derive($base);
}

/** utm_* com "organic..." / "::" / "{{...}}" (UTMify latest.js sem campanha / macro nao resolvida) vale como vazio. */
function trk_junk($k, $v) {
    if ($v === null || !is_scalar($v)) return true;
    $v = trim((string)$v);
    if ($v === '' || $v === 'null' || $v === 'undefined') return true;
    if (strpos((string)$k, 'utm_') !== 0) return false;
    return (stripos($v, 'organic') === 0 || $v === '::' || strpos($v, '{{') !== false);
}
/** true se $o tem utm_source/utm_campaign reais (nao organic). */
function trk_has_utm($o) {
    if (!is_array($o)) return false;
    return !trk_junk('utm_source', isset($o['utm_source']) ? $o['utm_source'] : null) || !trk_junk('utm_campaign', isset($o['utm_campaign']) ? $o['utm_campaign'] : null);
}

/** Tem atribuicao util (campanha/origem/cloaker/fbclid)? */
function trk_has($o) {
    if (!is_array($o)) return false;
    foreach (array('utm_source','utm_campaign','utm_id','cck','fbclid','src','sck') as $k) { if (!empty($o[$k])) return true; }
    return false;
}

/** Extrai os parametros da query de uma URL. */
function trk_from_url($url) {
    $url = (string)$url;
    if ($url === '') return array();
    $q = parse_url($url, PHP_URL_QUERY);
    if (!is_string($q) || $q === '') { $p = strpos($url, '?'); $q = $p === false ? '' : substr($url, $p + 1); }
    if ($q === '') return array();
    $arr = array(); parse_str($q, $arr);
    return trk_normalize($arr);
}

/** Header X-Trk (track-plus.js): base64url(json {sid, p:{...}}) -> array('sid'=>..., 'p'=>array). */
function trk_header() {
    $h = isset($_SERVER['HTTP_X_TRK']) ? trim((string)$_SERVER['HTTP_X_TRK']) : '';
    if ($h === '' || strlen($h) > 8000) return array('sid' => '', 'p' => array());
    $j = json_decode($h, true);
    if (!is_array($j)) { $dec = base64_decode(strtr($h, '-_', '+/'), true); $j = $dec !== false ? json_decode($dec, true) : null; }
    if (!is_array($j)) return array('sid' => '', 'p' => array());
    $sid = isset($j['sid']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string)$j['sid'])) : '';
    $p = isset($j['p']) && is_array($j['p']) ? $j['p'] : $j;
    return array('sid' => $sid, 'p' => trk_normalize($p));
}

function trk_valid_sid($s) {
    $s = preg_replace('/[^a-f0-9]/', '', strtolower((string)$s));
    return (strlen($s) >= 16 && strlen($s) <= 64) ? $s : '';
}

/** Id de sessao: body/query trk_id -> header -> cookie trk_sid. Cria (e grava cookie HTTP) se $create. */
function trk_sid($create = false, $body = null) {
    if (!empty($GLOBALS['__TRK_SID'])) return $GLOBALS['__TRK_SID'];
    $sid = '';
    if (is_array($body) && !empty($body['trk_id'])) $sid = trk_valid_sid($body['trk_id']);
    if ($sid === '' && !empty($_GET['trk_id'])) $sid = trk_valid_sid($_GET['trk_id']);
    if ($sid === '') { $h = trk_header(); $sid = trk_valid_sid($h['sid']); }
    if ($sid === '' && !empty($_COOKIE['trk_sid'])) $sid = trk_valid_sid($_COOKIE['trk_sid']);
    $isNew = false;
    if ($sid === '' && $create) { $sid = bin2hex(random_bytes(16)); $isNew = true; }
    if ($sid !== '' && ($isNew || empty($_COOKIE['trk_sid']) || $_COOKIE['trk_sid'] !== $sid) && !headers_sent()) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        @setcookie('trk_sid', $sid, array('expires' => time() + 7776000, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'));
    }
    if ($sid !== '') $GLOBALS['__TRK_SID'] = $sid;
    return $sid;
}

/** Chave IP + aparelho (grupo entre parenteses do User-Agent: igual no Instagram, Facebook e Safari do mesmo iPhone). */
function trk_ipua_key() {
    $ip = function_exists('client_ip') ? client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $dev = preg_match('/\(([^)]*)\)/', $ua, $m) ? $m[1] : substr($ua, 0, 60);
    if ($ip === '' && $dev === '') return '';
    return $ip . '|' . $dev;
}

/** fbclid vindo do tracking/body/cookie _fbc (fb.1.<ts>.<fbclid>). */
function trk_fbclid_hint($utm, $body) {
    foreach (array($utm, $body) as $src) { if (is_array($src) && !empty($src['fbclid']) && is_scalar($src['fbclid'])) return substr((string)$src['fbclid'], 0, 300); }
    $fbc = !empty($_COOKIE['_fbc']) ? (string)$_COOKIE['_fbc'] : '';
    if ($fbc === '') { foreach (array($utm, $body) as $src) { if (is_array($src) && !empty($src['fbc']) && is_scalar($src['fbc'])) { $fbc = (string)$src['fbc']; break; } } }
    if ($fbc !== '' && preg_match('/^fb\.\d\.\d+\.(.+)$/', $fbc, $m)) return substr($m[1], 0, 300);
    return '';
}

/** Grava os parametros desta sessao em todas as chaves conhecidas. Retorna o sid. */
function trk_save($params, $sid = '', $cpf = '') {
    $params = trk_normalize($params);
    if (!trk_has($params)) return $sid;
    if ($sid === '') $sid = trk_sid(true);
    if ($sid !== '') trk_write('sid', $sid, $params);
    if (!empty($params['fbclid'])) trk_write('fb', $params['fbclid'], $params);
    $ipua = trk_ipua_key();
    if ($ipua !== '') trk_write('ipua', $ipua, $params);
    $cpf = preg_replace('/\D/', '', (string)$cpf);
    if ($cpf !== '') trk_write('cpf', $cpf, $params);
    return $sid;
}

/** Procura por todas as chaves. Retorna array('trk'=>array, 'origem'=>array(...)). */
function trk_lookup($sid = '', $fbclid = '', $cpf = '', $useIpua = true) {
    $trk = array(); $origem = array();
    $try = function ($kind, $key, $maxAge, $label) use (&$trk, &$origem) {
        if ((string)$key === '') return;
        $d = trk_read($kind, $key, $maxAge);
        if (trk_has($d)) { $before = trk_has($trk); $trk = trk_merge($trk, $d); if (!$before) $origem[] = $label; }
    };
    $try('sid', $sid, 0, 'sid');
    $try('fb', $fbclid, 0, 'fbclid');
    $try('cpf', preg_replace('/\D/', '', (string)$cpf), 0, 'cpf');
    if ($useIpua && !trk_has($trk)) $try('ipua', trk_ipua_key(), 6 * 3600, 'ipua');
    return array('trk' => $trk, 'origem' => $origem);
}

/** Reduz ao formato trackProps/trackingParameters (utm_* + src/sck/fbc/fbp). */
function trk_pick_tracking($trk) {
    $out = array();
    foreach (array('utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id','src','sck','fbc','fbp') as $k) {
        if (!empty($trk[$k])) $out[$k] = (string)$trk[$k];
    }
    return $out;
}

/**
 * Usado por store_order_for_utmify quando o payload chegou sem UTM: tenta
 * sessao/fbclid/CPF/IP do request atual. Retorna array utm_x, src, sck, fbc, fbp.
 */
function trk_lookup_for_order($payload) {
    $cpf = (is_array($payload) && isset($payload['client']['document'])) ? (string)$payload['client']['document'] : '';
    $tp  = (is_array($payload) && isset($payload['trackProps']) && is_array($payload['trackProps'])) ? $payload['trackProps'] : array();
    $tr  = (is_array($payload) && isset($payload['tracking']) && is_array($payload['tracking'])) ? $payload['tracking'] : array();
    $fb  = trk_fbclid_hint($tr, $tp);
    $sid = (PHP_SAPI === 'cli') ? '' : trk_sid(false, $payload);
    $r = trk_lookup($sid, $fb, $cpf, PHP_SAPI !== 'cli');
    return trk_has($r['trk']) ? trk_pick_tracking($r['trk']) : array();
}

/** Procura, nos order_*.json das ultimas 72h, um pedido do MESMO CPF que tenha UTM. */
function trk_from_orders_by_document($document, $maxAge = 259200) {
    $document = preg_replace('/\D/', '', (string)$document);
    if ($document === '') return array();
    $dir = trk_dir();
    $files = @glob($dir . '/order_*.json');
    if (!$files) return array();
    $now = time(); $best = array(); $bestTs = 0;
    foreach ($files as $f) {
        $mt = (int)@filemtime($f);
        if ($now - $mt > $maxAge) continue;
        $o = json_decode((string)@file_get_contents($f), true);
        if (!is_array($o) || !isset($o['customer']['document'])) continue;
        if (preg_replace('/\D/', '', (string)$o['customer']['document']) !== $document) continue;
        $tp = isset($o['trackingParameters']) && is_array($o['trackingParameters']) ? $o['trackingParameters'] : array();
        if (!trk_has_utm($tp)) continue;
        if ($mt > $bestTs) { $bestTs = $mt; $best = $tp; }
    }
    return $best ? trk_pick_tracking(trk_normalize($best)) : array();
}

/**
 * Antes de ENVIAR um pedido a UTMify (criacao, webhook, polling, cron): se
 * trackingParameters esta sem utm_source/utm_campaign, completa com o tracking
 * de sessao (trk_cpf_<CPF>) / fbclid ou com outro pedido do mesmo CPF.
 * Nunca sobrescreve o que ja esta preenchido.
 */
function trk_enrich_order($order) {
    if (!is_array($order)) return $order;
    $tp = isset($order['trackingParameters']) && is_array($order['trackingParameters']) ? $order['trackingParameters'] : array();
    if (trk_has_utm($tp)) return $order;
    $doc = isset($order['customer']['document']) ? (string)$order['customer']['document'] : '';
    $src = '';
    $found = array();
    if ($doc !== '') {
        $d = trk_read('cpf', preg_replace('/\D/', '', $doc));
        if (trk_has($d)) { $found = trk_pick_tracking($d); $src = 'trk_cpf'; }
    }
    if (!$found) {
        $fb = '';
        if (!empty($tp['fbc']) && preg_match('/^fb\.\d\.\d+\.(.+)$/', (string)$tp['fbc'], $m)) $fb = $m[1];
        if ($fb !== '') { $d = trk_read('fb', $fb); if (trk_has($d)) { $found = trk_pick_tracking($d); $src = 'trk_fbclid'; } }
    }
    if (!$found && $doc !== '') { $found = trk_from_orders_by_document($doc); if ($found) $src = 'order_mesmo_cpf'; }
    if (!$found) return $order;
    foreach ($found as $k => $v) { if (trk_junk($k, isset($tp[$k]) ? $tp[$k] : null) && $v !== '') $tp[$k] = $v; }
    $order['trackingParameters'] = $tp;
    $order['tracking_enriched'] = $src;
    @error_log('[utmify] tracking completado via ' . $src . ' p/ orderId=' . (isset($order['orderId']) ? $order['orderId'] : '?') . ' utm_campaign=' . (isset($tp['utm_campaign']) ? $tp['utm_campaign'] : ''));
    return $order;
}

/** Corpo JSON sem derrubar a requisicao (vazio/invalido => array()). */
function trk_read_body_soft() {
    $raw = @file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

/**
 * Rota ?action=trk (track-plus.js chama em todo carregamento de pagina): grava os
 * parametros NO SERVIDOR por sessao / fbclid / IP+aparelho e devolve o sid
 * (tambem em cookie HTTP trk_sid) + o que o servidor ja conhece desta sessao.
 */
function trk_route() {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    $b = ($method === 'POST') ? trk_read_body_soft() : array();
    $q = $_GET; unset($q['action'], $q['trk_id'], $q['url']);
    $params = trk_normalize($q);
    if (isset($b['params']) && is_array($b['params']))       $params = trk_merge($params, trk_normalize($b['params']));
    if (isset($b['trackPlus']) && is_array($b['trackPlus'])) $params = trk_merge($params, trk_normalize($b['trackPlus']));
    if (!empty($b['url']))     $params = trk_merge($params, trk_from_url($b['url']));
    if (!empty($_GET['url']))  $params = trk_merge($params, trk_from_url($_GET['url']));
    $hdr = trk_header();
    $params = trk_merge($params, $hdr['p']);
    $sid = trk_sid(true, $b);
    $cpfHint = isset($b['cpf']) ? preg_replace('/\D/', '', (string)$b['cpf']) : '';
    if (trk_has($params)) trk_save($params, $sid, $cpfHint);
    $known = trk_lookup($sid, (isset($params['fbclid']) ? $params['fbclid'] : ''), $cpfHint, true);
    $trk = trk_merge($params, $known['trk']);
    unset($trk['_ts']);
    return array('ok' => true, 'sid' => $sid, 'trk' => $trk, 'origem' => $known['origem']);
}

/** consultar_cpf: amarra o tracking da sessao a este CPF (trk_cpf_<hash>). */
function trk_bind_cpf($cpf) {
    $cpf = preg_replace('/\D/', '', (string)$cpf);
    if ($cpf === '') return;
    try {
        $hdr = trk_header();
        $sid = trk_sid(false);
        $look = trk_lookup($sid, '', '', true);
        $sess = trk_merge($hdr['p'], $look['trk']);
        if (trk_has($sess)) trk_save($sess, $sid, $cpf);
    } catch (\Throwable $e) { @error_log('[trk] consultar_cpf: ' . $e->getMessage()); }
}

/**
 * create_pix: completa $track (saida de build_track_props) com as fontes do
 * servidor. Ordem: body.trackPlus -> body.page_url -> header X-Trk -> sessao
 * (cookie HTTP trk_sid / trk_id) -> fbclid -> CPF -> IP+aparelho. So preenche o
 * que faltou; persiste o resultado final por CPF + sessao (os upsells recuperam).
 * Retorna array('track'=>..., 'origem'=>'body|servidor(...)|nenhum').
 */
function trk_complete_pix_track($track, $rawBody) {
    if (!is_array($track)) $track = array();
    $b = is_array($rawBody) ? $rawBody : array();
    $hasUtm = trk_has_utm($track);
    $origem = $hasUtm ? 'body' : 'nenhum';
    $cpf = isset($b['client']['document']) ? preg_replace('/\D/', '', (string)$b['client']['document']) : '';
    $srvOrigem = array();
    try {
        $srv = array();
        if (isset($b['trackPlus']) && is_array($b['trackPlus'])) { $t = trk_normalize($b['trackPlus']); if (trk_has($t)) { $srv = trk_merge($srv, $t); $srvOrigem[] = 'body.trackPlus'; } }
        if (!empty($b['page_url'])) { $t = trk_from_url($b['page_url']); if (trk_has($t)) { $srv = trk_merge($srv, $t); $srvOrigem[] = 'page_url'; } }
        $hdr = trk_header();
        if (trk_has($hdr['p'])) { $srv = trk_merge($srv, $hdr['p']); $srvOrigem[] = 'header'; }
        $sid = trk_sid(false, $b);
        $tp = isset($b['trackProps']) && is_array($b['trackProps']) ? $b['trackProps'] : array();
        $tr = isset($b['tracking']) && is_array($b['tracking']) ? $b['tracking'] : array();
        $fbHint = trk_fbclid_hint($tr, $tp);
        if ($fbHint === '' && !empty($track['fbc']) && preg_match('/^fb\.\d\.\d+\.(.+)$/', (string)$track['fbc'], $m)) $fbHint = $m[1];
        if ($fbHint === '' && !empty($srv['fbclid'])) $fbHint = $srv['fbclid'];
        $look = trk_lookup($sid, $fbHint, $cpf, true);
        if (trk_has($look['trk'])) { $srv = trk_merge($srv, $look['trk']); foreach ($look['origem'] as $lo) $srvOrigem[] = $lo; }
        if (trk_has($srv)) {
            $filled = false;
            foreach (array('utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id') as $k) {
                if (trk_junk($k, isset($track[$k]) ? $track[$k] : null) && !empty($srv[$k])) { $track[$k] = (string)$srv[$k]; $filled = true; }
            }
            if (empty($track['fbc']) && !empty($srv['fbc'])) $track['fbc'] = (string)$srv['fbc'];
            if (empty($track['fbp']) && !empty($srv['fbp'])) $track['fbp'] = (string)$srv['fbp'];
            if ($filled) $origem = ($origem === 'nenhum' ? '' : $origem . '+') . 'servidor(' . implode(',', array_unique($srvOrigem)) . ')';
        }
        $final = trk_merge(trk_pick_tracking($track), $srv);
        if (trk_has($final)) trk_save($final, $sid, $cpf);
    } catch (\Throwable $e) { @error_log('[trk] pix: ' . $e->getMessage()); }
    @error_log('[trk] pix utm_recebido: ' . $origem . ' utm_source=' . (isset($track['utm_source']) ? $track['utm_source'] : '') . ' utm_campaign=' . (isset($track['utm_campaign']) ? $track['utm_campaign'] : ''));
    return array('track' => $track, 'origem' => $origem);
}
