<?php
/**
 * =============================================================================
 * NOTIFY LIB — Push em tempo real (Pushcut ou Webhooky)
 * =============================================================================
 * Envia notificacoes push para o celular do dono da operacao nos eventos:
 *   - Deposito confirmado (PIX pago)            -> push imediato
 *   - Deposito recuperado por e-mail            -> push imediato
 *   - Estorno / chargeback                      -> push imediato
 *   - ALERTA inteligente: N PIX seguidos sem conversao -> push imediato
 *   - Resumo do dia (gerados/pagos/expirados/e-mails)  -> a cada ciclo do cron,
 *     apenas quando os numeros mudaram desde o ultimo resumo
 *
 * Privacidade (a pedido do dono): as notificacoes NAO contem e-mail do cliente
 * e NAO contem o nome do produto — todo pagamento aparece como "Deposito".
 *
 * CONFIG (.env):
 *   NOTIFY_WEBHOOK_URL   URL do seu endpoint de push (obrigatoria). A URL e o
 *                        proprio segredo — nao compartilhe. Aceita:
 *                          Pushcut : https://api.pushcut.io/SEGREDO/notifications/NOME
 *                          Webhooky: https://api.webhooky.app/SEU_ID
 *   NOTIFY_PROVIDER      (opcional) 'pushcut' ou 'webhooky'. Se vazio, e
 *                        detectado automaticamente pela URL acima.
 *   NOTIFY_ALERT_STREAK  (opcional) qtos PIX seguidos sem pagamento disparam o
 *                        alerta (padrao 10)
 *
 * TOLERANCIA A FALHAS: tudo aqui e "fire and forget". Qualquer erro (rede,
 * URL errada, app fora do ar) e silencioso e NUNCA afeta pagamento/e-mails.
 * =============================================================================
 */

if (!function_exists('notify_env')) {

function notify_env($key, $default = '') {
    $v = function_exists('env_get') ? env_get($key) : (function_exists('getenv') ? @getenv($key) : false);
    if ($v === false || $v === null || (is_string($v) && trim($v) === '')) return $default;
    return is_string($v) ? trim($v) : $v;
}

function notify_enabled() {
    if (!function_exists('curl_init')) return false;
    $u = notify_env('NOTIFY_WEBHOOK_URL', '');
    return ($u !== '' && stripos($u, 'http') === 0 && strpos($u, 'COLOQUE_') === false);
}

/** Provider de push em uso: 'pushcut' ou 'webhooky' (auto-detectado pela URL). */
function notify_provider() {
    $p = strtolower(notify_env('NOTIFY_PROVIDER', ''));
    if ($p === 'pushcut' || $p === 'webhooky') return $p;
    $u = notify_env('NOTIFY_WEBHOOK_URL', '');
    if (stripos($u, 'pushcut.io') !== false) return 'pushcut';
    return 'webhooky';
}

/** Traduz o id de som interno ('cash_register'/'error_1') para o nome aceito
 *  pelo provider. No Pushcut os sons tem nomes proprios; no Webhooky use direto. */
function notify_map_sound($sound, $provider) {
    if (!is_string($sound) || $sound === '') return '';
    if ($provider === 'pushcut') {
        $map = array(
            'cash_register' => 'jobDone',   // venda paga -> som de conclusao
            'error_1'       => 'problem',   // alerta -> som de problema
            'default'       => 'system',
        );
        return isset($map[$sound]) ? $map[$sound] : $sound;
    }
    return $sound; // Webhooky usa os nomes originais
}

/** Envia um push (Pushcut ou Webhooky). Silencioso: retorna true/false, nunca lanca excecao.
 *  $link e mantido na assinatura por compatibilidade (nenhum dos dois providers usa; ignorado).
 *  $sound (opcional): id interno de som, ex. 'cash_register', 'error_1'. */
function notify_brand() {
    // Nome da oferta exibido no push. Vem do .env ESCOPADO (OFFER_NAME) da pasta
    // de e-mail/notificacoes desta oferta; assim cada funil identifica a origem.
    return notify_env('OFFER_NAME', 'SERASA LIMPA NOME');
}

function notify_push($title, $body, $link = null, $sound = null) {
    try {
        if (!notify_enabled()) return false;
        $url = notify_env('NOTIFY_WEBHOOK_URL', '');
        // Prefixa o titulo com a oferta (ex.: "[DESENROLA] ...") para nao confundir
        // notificacoes de ofertas diferentes no mesmo celular.
        $__brand = notify_brand();
        if ($__brand !== '' && stripos((string)$title, '[' . $__brand . ']') === false) {
            $title = '[' . $__brand . '] ' . $title;
        }
        $provider = notify_provider();
        $snd = notify_map_sound($sound, $provider);
        if ($provider === 'pushcut') {
            // Pushcut: o corpo da notificacao vai no campo 'text'.
            $payload = array('title' => (string)$title, 'text' => (string)$body);
            if ($snd !== '') $payload['sound'] = $snd;
        } else {
            // Webhooky: o corpo vai no campo 'message'.
            $payload = array('title' => (string)$title, 'message' => (string)$body, 'vibrate' => true);
            if ($snd !== '') $payload['sound'] = $snd;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $ch = curl_init($url);
        if ($ch === false) return false;
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
            ),
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 400 || $resp === false) {
            @error_log('[notify] push falhou http=' . $code);
            return false;
        }
        return true;
    } catch (\Throwable $e) {
        @error_log('[notify] erro: ' . $e->getMessage());
        return false;
    }
}

// -----------------------------------------------------------------------------
// PLACAR DO DIA — contadores em JSON com trava de arquivo (multi-processo safe)
// -----------------------------------------------------------------------------
function notify_tz_now($fmt) {
    try {
        $d = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        return $d->format($fmt);
    } catch (\Throwable $e) { return date($fmt); }
}

function notify_stats_file() {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); @chmod($dir, 0777); }
    return $dir . '/notify_stats_' . notify_tz_now('Y-m-d') . '.json';
}

function notify_stats_default() {
    return array(
        'gerados' => 0,
        'pagos' => 0, 'pagos_valor' => 0.0,
        'recuperados' => 0, 'recuperados_valor' => 0.0,
        'emails' => 0,
        'expirados' => 0,
        'estornos' => 0,
        'streak_sem_pagto' => 0,
        'alerta_enviado_em' => 0,
        'ultimo_resumo' => '',
        'notificados' => array(),      // txid => 1 (dedupe de pago/estorno)
        'expirados_vistos' => array(), // txid => 1 (dedupe de expirado)
    );
}

/**
 * Le-modifica-grava o placar do dia sob flock. $fn recebe o array de stats por
 * referencia e pode retornar um valor, que e repassado ao chamador.
 */
function notify_stats_update($fn) {
    $file = notify_stats_file();
    // O site (www-data) e os crons (usuario da conta) escrevem no MESMO arquivo.
    // Se quem criou o arquivo nao abriu as permissoes, o outro processo falhava
    // em silencio e o placar/push se perdia. Por isso: umask 0 + chmod 0666.
    $old = @umask(0);
    $fh = @fopen($file, 'c+');
    @umask($old);
    if ($fh === false) {
        @error_log('[notify] stats: sem permissao de escrita em ' . $file
            . ' (dono do arquivo difere do processo atual). Rode: chmod 666 no arquivo e 777 na pasta cache/.');
        return 'NOSTATS';
    }
    $out = null;
    try {
        if (@flock($fh, LOCK_EX)) {
            $raw = stream_get_contents($fh);
            $stats = json_decode((string)$raw, true);
            if (!is_array($stats)) $stats = notify_stats_default();
            $stats = array_merge(notify_stats_default(), $stats);
            $out = $fn($stats);
            @ftruncate($fh, 0);
            @rewind($fh);
            @fwrite($fh, json_encode($stats, JSON_UNESCAPED_UNICODE));
            @fflush($fh);
            @flock($fh, LOCK_UN);
        }
    } catch (\Throwable $e) { @error_log('[notify] stats: ' . $e->getMessage()); }
    @fclose($fh);
    @chmod($file, 0666);
    return $out;
}

function notify_fmt_brl($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

/** "maria clara souza" -> "Maria S." (nome discreto na tela de bloqueio). */
function notify_short_name($name) {
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '') return '';
    $parts = explode(' ', $name);
    $first = ucfirst(mb_strtolower($parts[0], 'UTF-8'));
    if (count($parts) > 1) {
        $lastInitial = mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8'), 'UTF-8');
        return $first . ' ' . $lastInitial . '.';
    }
    return $first;
}

// -----------------------------------------------------------------------------
// EVENTOS
// -----------------------------------------------------------------------------

/**
 * PIX gerado (checkout ou upsell). Sem push individual — so alimenta o placar
 * e o ALERTA INTELIGENTE: N PIX seguidos sem nenhum pagamento -> aviso.
 */
function notify_event_pix_gerado() {
    if (!notify_enabled()) return;
    $limiar = (int)notify_env('NOTIFY_ALERT_STREAK', '10');
    if ($limiar < 3) $limiar = 3;
    $disparar = notify_stats_update(function (&$s) use ($limiar) {
        $s['gerados']++;
        $s['streak_sem_pagto']++;
        // dispara 1x ao atingir o limiar; re-arma apos novo ciclo completo e
        // no maximo 1 alerta a cada 2 horas (anti-spam)
        if ($s['streak_sem_pagto'] >= $limiar
            && ($s['streak_sem_pagto'] % $limiar) === 0
            && (time() - (int)$s['alerta_enviado_em']) > 7200) {
            $s['alerta_enviado_em'] = time();
            return $s['streak_sem_pagto'];
        }
        return 0;
    });
    if (is_int($disparar) && $disparar > 0) {
        notify_push(
            '⚠️ ALERTA · ' . $disparar . ' PIX sem conversao',
            'Foram gerados ' . $disparar . ' PIX seguidos sem nenhum pagamento confirmado hoje.'
            . "\n" . 'Vale conferir se o checkout e o gateway estao OK (api_proxy.php?action=diag).',
            null, 'error_1'
        );
    }
}

/**
 * Pagamento confirmado (webhook, polling ou reconciliacao — todos os caminhos).
 * Dedupe por txid: cada deposito notifica UMA unica vez, mesmo que o evento
 * chegue por mais de um caminho.
 * $row: linha do banco de recuperacao (se conhecida) — usa amount/name/emails_sent.
 */
/**
 * Rotulo do pagamento por etapa do funil: front = "PIX", upsell N = "PIX N".
 * (Nunca cita o nome real do produto na notificacao.)
 */
function notify_stage_label($productType) {
    $map = array(
        'acordo' => 0,
        'registro' => 1, 'adm' => 2, 'iof' => 3, 'multa_ir' => 4,
        'issqn' => 5, 'verificacao' => 6, 'liberacao' => 7,
        'chave_pix' => 8, 'ted' => 9, 'certidao' => 10,
        'cadastro' => 11, 'seguro' => 12, 'prioridade' => 13,
        'imposto_ress' => 14, 'biometria' => 15, 'encerramento' => 16,
    );
    $k = strtolower(trim((string)$productType));
    if ($k !== '' && isset($map[$k]) && $map[$k] > 0) return 'PIX ' . $map[$k];
    return 'PIX';
}

function notify_event_pago($txid, $row = null) {
    if (!notify_enabled()) return;
    $txid = (string)$txid;
    if ($txid === '') return;
    $amount = is_array($row) && isset($row['amount']) ? (float)$row['amount'] : 0.0;
    $nome   = is_array($row) && isset($row['name']) ? notify_short_name($row['name']) : '';
    $recuperado = is_array($row) && isset($row['emails_sent']) && (int)$row['emails_sent'] > 0;
    $etapa  = $recuperado ? (int)$row['emails_sent'] : 0;

    $res = notify_stats_update(function (&$s) use ($txid, $amount, $recuperado) {
        if (isset($s['notificados'][$txid])) return null; // ja notificado
        $s['notificados'][$txid] = 1;
        $s['pagos']++;
        $s['pagos_valor'] += $amount;
        $s['streak_sem_pagto'] = 0; // conversao aconteceu -> re-arma o alerta
        if ($recuperado) {
            $s['recuperados']++;
            $s['recuperados_valor'] += $amount;
        }
        return array($s['pagos'], $s['pagos_valor'], $s['recuperados'], $s['recuperados_valor']);
    });
    if ($res === null) return; // txid ja notificado hoje (dedupe) -> nada a fazer

    $semPlacar = !is_array($res); // 'NOSTATS' = placar indisponivel, MAS o push sai mesmo assim
    $pagosHoje = 0; $valorHoje = 0.0; $recHoje = 0; $recValor = 0.0;
    if (!$semPlacar) list($pagosHoje, $valorHoje, $recHoje, $recValor) = $res;

    $valorTxt = $amount > 0 ? ' · ' . notify_fmt_brl($amount) : '';
    $quando = notify_tz_now('d/m') . ' às ' . notify_tz_now('H:i');
    $etiqueta = notify_stage_label(is_array($row) && isset($row['product_type']) ? $row['product_type'] : '');
    $linha2 = ($nome !== '' ? $nome . ' ' : '') . $quando
        . ($semPlacar ? '' : ' · Total do dia: ' . notify_fmt_brl($valorHoje) . ' (' . $pagosHoje . ' vendas)');

    if ($recuperado) {
        $body = 'Pagamento · ' . $etiqueta . ' · recuperado por e-mail (aviso ' . $etapa . ')'
            . "\n" . $linha2
            . "\n" . 'Recuperados hoje: ' . $recHoje . ' · ' . notify_fmt_brl($recValor);
        notify_push('✅ PEDIDO RECUPERADO' . $valorTxt, $body, null, 'cash_register');
    } else {
        $body = 'Pagamento · ' . $etiqueta
            . "\n" . $linha2;
        notify_push('💰 PEDIDO PAGO' . $valorTxt, $body, null, 'cash_register');
    }
}

/**
 * Busca a linha no banco de recuperacao por qualquer identificador e notifica
 * o pagamento. Chamar ANTES de recovery_mark_paid (para ainda ver emails_sent
 * e o status anterior). Se a linha ja esta PAID, nao notifica (idempotente).
 */
function notify_on_paid_identifiers($identifiers) {
    try {
        if (!notify_enabled()) return;
        if (!is_array($identifiers)) $identifiers = array($identifiers);
        $row = null; $txKey = '';
        if (function_exists('recovery_db')) {
            $db = recovery_db();
            if ($db) {
                foreach ($identifiers as $id) {
                    $id = (string)$id;
                    if ($id === '') continue;
                    try {
                        $st = $db->prepare('SELECT txid, amount, name, emails_sent, status, product_type FROM transactions WHERE txid=? OR identifier=? LIMIT 1');
                        $st->execute(array($id, $id));
                    } catch (\Throwable $eCol) {
                        // banco criado por versao antiga sem a coluna product_type
                        $st = $db->prepare('SELECT txid, amount, name, emails_sent, status FROM transactions WHERE txid=? OR identifier=? LIMIT 1');
                        $st->execute(array($id, $id));
                    }
                    $r = $st->fetch(PDO::FETCH_ASSOC);
                    if ($r) { $row = $r; $txKey = (string)$r['txid']; break; }
                }
            }
        }
        // IMPORTANTE: NAO abortar so porque o banco ja esta marcado como PAID.
        // Em varios caminhos (ex.: webhook/polling) o recovery_mark_paid roda
        // ANTES deste push; se retornassemos aqui, a venda NUNCA notificaria.
        // A deduplicacao real (1 push por venda/dia) e feita em notify_event_pago
        // atraves do placar 'notificados'[txid] — nao aqui.
        if ($txKey === '') {
            foreach ($identifiers as $id) { if ((string)$id !== '') { $txKey = (string)$id; break; } }
        }
        if ($txKey === '') return;
        notify_event_pago($txKey, $row);
    } catch (\Throwable $e) { @error_log('[notify] on_paid: ' . $e->getMessage()); }
}

/** E-mail de recuperacao enviado (sem push individual — conta no resumo). */
function notify_event_email_enviado() {
    if (!notify_enabled()) return;
    notify_stats_update(function (&$s) { $s['emails']++; return null; });
}

/** PIX expirado/recusado detectado (sem push individual — conta no resumo). */
function notify_event_expirado($txid) {
    if (!notify_enabled()) return;
    $txid = (string)$txid;
    if ($txid === '') return;
    notify_stats_update(function (&$s) use ($txid) {
        if (isset($s['expirados_vistos'][$txid])) return null;
        $s['expirados_vistos'][$txid] = 1;
        $s['expirados']++;
        return null;
    });
}

/** Estorno ou chargeback — push imediato (dinheiro saindo do saldo). */
function notify_event_estorno($txid, $amount, $tipo) {
    if (!notify_enabled()) return;
    $txid = (string)$txid;
    $novo = notify_stats_update(function (&$s) use ($txid) {
        $k = 'estorno_' . $txid;
        if (isset($s['notificados'][$k])) return false;
        $s['notificados'][$k] = 1;
        $s['estornos']++;
        return true;
    });
    if ($novo !== true) return;
    $rotulo = (strtoupper((string)$tipo) === 'CHARGEDBACK' || strtoupper((string)$tipo) === 'CHARGEBACK')
        ? 'CHARGEBACK' : 'ESTORNO';
    $valorTxt = ((float)$amount > 0) ? ' · ' . notify_fmt_brl($amount) : '';
    notify_push(
        '🔄 ' . $rotulo . $valorTxt,
        'Um deposito foi devolvido (status: ' . strtoupper((string)$tipo) . ').'
        . "\n" . 'Atencao: o valor sai do seu saldo.'
    );
}

/**
 * Resumo do dia — chamado ao fim de cada ciclo do cron. So envia se os numeros
 * mudaram desde o ultimo resumo (nada de push repetido de madrugada).
 */
function notify_daily_summary() {
    if (!notify_enabled()) return;
    $dados = notify_stats_update(function (&$s) {
        $chave = implode('|', array($s['gerados'], $s['pagos'], round($s['pagos_valor'], 2),
            $s['expirados'], $s['emails'], $s['recuperados'], $s['estornos']));
        if ($chave === $s['ultimo_resumo']) return null;          // nada mudou
        if ($s['gerados'] === 0 && $s['pagos'] === 0 && $s['emails'] === 0) return null; // dia zerado
        $s['ultimo_resumo'] = $chave;
        return $s;
    });
    if (!is_array($dados)) return;
    $conv = $dados['gerados'] > 0 ? number_format($dados['pagos'] * 100 / $dados['gerados'], 1, ',', '.') : '0,0';
    $pend = max(0, $dados['gerados'] - $dados['pagos'] - $dados['expirados']);
    $body = 'Gerados: ' . $dados['gerados'] . ' · Pagos: ' . $dados['pagos'] . ' (' . $conv . '%)'
        . "\n" . 'Expirados/recusados: ' . $dados['expirados'] . ' · Pendentes: ' . $pend
        . "\n" . 'E-mails: ' . $dados['emails'] . ' enviados · ' . $dados['recuperados'] . ' recuperados';
    if ($dados['estornos'] > 0) $body .= "\n" . 'Estornos: ' . $dados['estornos'];
    notify_push('📊 Hoje: ' . $dados['pagos'] . ' pagos · ' . notify_fmt_brl($dados['pagos_valor']), $body);
}

} // if (!function_exists('notify_env'))
