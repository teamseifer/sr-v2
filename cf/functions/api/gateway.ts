/**
 * POST/GET /api/gateway
 * O funil continua chamando /api_proxy.php?action=...
 * Um rewrite (_redirects) entrega essa URL aqui.
 */

import { consultarCpf } from '../lib/cpf';
import type { Env } from '../lib/env';
import { mangofyReady } from '../lib/env';
import {
  buildMangofyBody,
  isDuplicateExternal,
  mangofyError,
  mangofyExternal,
  mangofyPaidAt,
  mangofyPaymentCode,
  mangofyPixCode,
  mangofyPixImage,
  mangofyRequest,
  mangofyStatus,
} from '../lib/mangofy';
import { asRecord, clientIp, isPaidStatus, json, preflight, readJson, str, webhookTokenOk } from '../lib/http';
import { readStatus, writeStatus } from '../lib/store';
import { buildUtmifyOrder, sendUtmifyPending, syncUtmifyStatus } from '../lib/utmify';

export const onRequest: PagesFunction<Env> = async (ctx) => {
  const pf = preflight(ctx.request);
  if (pf) return pf;
  const action = new URL(ctx.request.url).searchParams.get('action') || '';
  try {
    switch (action) {
      case 'ping':
        return ping(ctx.env);
      case 'consultar_cpf':
        return consultCpf(ctx);
      case 'create_pix':
        return createPix(ctx);
      case 'check_status':
        return checkStatus(ctx);
      case 'webhook':
        return webhook(ctx);
      case 'track_sale':
        return trackSale(ctx);
      default:
        return json(
          {
            ok: false,
            error:
              'Acao invalida. Use action=create_pix | check_status | consultar_cpf | webhook | ping | track_sale.',
          },
          400
        );
    }
  } catch (e) {
    return json({ ok: false, error: 'Erro interno no proxy' }, 500);
  }
};

const TRACK_KEYS = [
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_content',
  'utm_term',
  'utm_id',
  'src',
  'sck',
  'fbc',
  'fbp',
];

function putTrack(out: Record<string, string>, key: string, value: unknown): void {
  if (TRACK_KEYS.indexOf(key) === -1 || out[key]) return;
  if (typeof value !== 'string') return;
  const clean = value.trim();
  if (clean === '' || clean === 'null' || clean === 'undefined') return;
  out[key] = clean.slice(0, 300);
}

function collectTrack(request: Request, raw: Record<string, unknown>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const src of [raw.tracking, raw.trackProps, raw.trackingParameters]) {
    const rec = asRecord(src);
    if (!rec) continue;
    for (const key of TRACK_KEYS) putTrack(out, key, rec[key]);
    if (!out.fbc && typeof rec.fbclid === 'string' && rec.fbclid.trim()) {
      out.fbc = `fb.1.${Math.floor(Date.now() / 1000)}.${rec.fbclid.trim()}`.slice(0, 300);
    }
  }
  const cookie = request.headers.get('cookie') || '';
  const fbp = cookie.match(/(?:^|;\s*)_fbp=([^;]+)/);
  const fbc = cookie.match(/(?:^|;\s*)_fbc=([^;]+)/);
  if (fbp) putTrack(out, 'fbp', decodeURIComponent(fbp[1]));
  if (fbc) putTrack(out, 'fbc', decodeURIComponent(fbc[1]));
  try {
    const ref = new URL(request.headers.get('referer') || 'http://local/');
    for (const key of TRACK_KEYS) putTrack(out, key, ref.searchParams.get(key) || '');
    const fbclid = (ref.searchParams.get('fbclid') || '').trim();
    if (!out.fbc && fbclid) out.fbc = `fb.1.${Math.floor(Date.now() / 1000)}.${fbclid}`.slice(0, 300);
  } catch {
    /* referer ausente nao impede o pedido */
  }
  return out;
}

function customerEmail(name: string, document: string): string {
  const slug = name
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '')
    .slice(0, 18);
  const tail = document.replace(/\D/g, '').slice(-4) || '00';
  return `${slug || 'cliente'}${tail}@gmail.com`;
}

function ping(env: Env): Response {
  return json({
    ok: true,
    message: 'sr-v2 Pages OK.',
    gateway: 'mangofy',
    mangofy_key_configurada: mangofyReady(env),
    cpf_token: Boolean((env.CPF_API_TOKEN || '').trim()),
    cpf2_token: Boolean((env.CPF_API2_TOKEN || '').trim()),
    meta_token: Boolean((env.META_ACCESS_TOKEN || '').trim()),
    utmify_token: Boolean((env.UTMIFY_API_TOKEN || '').trim()),
    comprovantes_storage_configurada: Boolean((env.SUPABASE_URL || '').trim() && (env.SUPABASE_UPLOAD_TOKEN || '').trim()),
    kv: Boolean(env.SR_TX),
  });
}

async function consultCpf(ctx: EventContext<Env, string, unknown>): Promise<Response> {
  const url = new URL(ctx.request.url);
  let cpf = url.searchParams.get('cpf') || '';
  if (!cpf && ctx.request.method === 'POST') {
    const body = await readJson(ctx.request);
    cpf = str(body.cpf);
  }
  const result = await consultarCpf(ctx.env, cpf, url.searchParams.get('diag') === '1');
  return json({ ...result }, 200);
}

async function createPix(ctx: EventContext<Env, string, unknown>): Promise<Response> {
  if (ctx.request.method !== 'POST') return json({ ok: false, error: 'Metodo nao permitido' }, 405);
  if (!mangofyReady(ctx.env)) {
    return json(
      {
        ok: false,
        error: 'Credenciais Mangofy nao disponiveis. A API key e o Store Code precisam estar configurados.',
        gateway: 'mangofy',
      },
      503
    );
  }
  const raw = await readJson(ctx.request);
  const client = asRecord(raw.client);
  if (!str(raw.identifier)) return json({ ok: false, error: 'Campo identifier eh obrigatorio.' }, 400);
  if (!(Number(raw.amount) > 0)) return json({ ok: false, error: 'Campo amount eh obrigatorio.' }, 400);
  if (!client || !str(client.phone) || !str(client.document)) {
    return json({ ok: false, error: 'Campo client.phone e client.document sao obrigatorios.' }, 400);
  }

  let result = await mangofyRequest(ctx.env, 'POST', '/api/v1/payment/pix', buildMangofyBody(ctx.request, ctx.env, raw));
  if (result.transportError || result.httpStatus >= 500) {
    await new Promise((r) => setTimeout(r, 1500));
    const retry = await mangofyRequest(ctx.env, 'POST', '/api/v1/payment/pix', buildMangofyBody(ctx.request, ctx.env, raw));
    if (!retry.transportError && retry.httpStatus < 500) result = retry;
  }
  if (isDuplicateExternal(result)) {
    const fresh = `${str(raw.identifier).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 80)}_${Date.now()}`;
    raw.identifier = fresh;
    result = await mangofyRequest(ctx.env, 'POST', '/api/v1/payment/pix', buildMangofyBody(ctx.request, ctx.env, raw));
  }
  if (result.transportError) return json({ ok: false, error: 'Falha de conexao com gateway', gateway: 'mangofy' }, 502);
  if (result.httpStatus >= 400) {
    const code = result.httpStatus >= 400 ? result.httpStatus : 400;
    return json(
      { ok: false, error: mangofyError(result.data, 'Erro ao gerar PIX'), gateway: 'mangofy', status: code },
      code >= 500 ? 502 : 400
    );
  }

  let status = mangofyStatus(result.data);
  let txId = mangofyPaymentCode(result.data);
  let code = mangofyPixCode(result.data);
  let qr = mangofyPixImage(result.data);
  if (status === 'FAILED' || status === 'REFUSED' || status === 'GATEWAY_ERROR' || status === 'SYSTEM_ERROR') {
    return json({ ok: false, error: mangofyError(result.data, 'O gateway recusou a criacao do PIX.'), status: 400 }, 400);
  }
  for (let i = 0; i < 2 && !code && txId; i++) {
    await new Promise((r) => setTimeout(r, 1500));
    const poll = await mangofyRequest(ctx.env, 'GET', `/api/v1/payment/${encodeURIComponent(txId)}`);
    if (poll.transportError || poll.httpStatus >= 400) continue;
    code = mangofyPixCode(poll.data);
    qr = mangofyPixImage(poll.data);
    status = mangofyStatus(poll.data);
    txId = mangofyPaymentCode(poll.data) || txId;
  }
  if (!code) {
    return json(
      {
        ok: false,
        error: 'O PIX esta sendo processado pelo gateway e ainda nao ficou pronto. Tente novamente em alguns segundos.',
        transactionId: txId,
        status,
      },
      504
    );
  }

  const pix: Record<string, string> = { code };
  if (qr) {
    if (qr.toLowerCase().startsWith('http')) pix.image = qr;
    else pix.base64 = qr;
  }
  const identifier = str(raw.identifier);
  if (txId) await writeStatus(ctx.env, [txId, identifier], status || 'PENDING', null);

  const orderId = txId || identifier;
  if (orderId) {
    const createdAt = new Date().toISOString();
    const name = str(client.name).trim();
    const document = str(client.document).replace(/\D/g, '');
    const order = buildUtmifyOrder({
      orderId,
      amount: Math.round(Number(raw.amount) * 100) / 100,
      productType: str(raw.productType),
      name: name || 'Cliente',
      email: str(client.email).trim() || customerEmail(name, document),
      phone: str(client.phone).replace(/\D/g, '') || '11999999999',
      document,
      ip: clientIp(ctx.request),
      track: collectTrack(ctx.request, raw),
      gatewayData: result.data,
      createdAt,
    });
    await sendUtmifyPending(ctx.env, order, [orderId, txId, identifier]);
  }

  return json({
    ok: true,
    transactionId: txId,
    identifier: str(raw.identifier),
    status: status || 'PENDING',
    pix,
    amount: Math.round(Number(raw.amount) * 100) / 100,
  });
}

async function checkStatus(ctx: EventContext<Env, string, unknown>): Promise<Response> {
  if (ctx.request.method !== 'GET') return json({ ok: false, error: 'Metodo nao permitido' }, 405);
  const url = new URL(ctx.request.url);
  const transactionId = (url.searchParams.get('id') || '').trim();
  const clientIdentifier = (url.searchParams.get('clientIdentifier') || '').trim();
  const cacheId = transactionId || clientIdentifier;
  if (!cacheId) return json({ ok: false, error: 'Parametro id ou clientIdentifier eh obrigatorio.' }, 400);

  const cached = await readStatus(ctx.env, cacheId);
  if (cached && isPaidStatus(cached.status)) {
    await syncUtmifyStatus(
      ctx.env,
      [cached.id, cacheId, transactionId, clientIdentifier],
      cached.status,
      cached.payedAt
    );
    return json({ ok: true, id: cached.id || cacheId, status: cached.status, payedAt: cached.payedAt, _source: 'kv' });
  }
  if (!transactionId || !mangofyReady(ctx.env)) {
    return json({ ok: true, id: cacheId, status: 'PENDING', payedAt: null, _source: 'no_txid' });
  }

  const result = await mangofyRequest(ctx.env, 'GET', `/api/v1/payment/${encodeURIComponent(transactionId)}`);
  if (result.transportError || result.httpStatus >= 400 || !result.data) {
    return json({ ok: true, id: cacheId, status: 'PENDING', payedAt: null, _source: 'mangofy_wait' });
  }
  const status = mangofyStatus(result.data);
  const payedAt = mangofyPaidAt(result.data);
  const respId = mangofyPaymentCode(result.data) || cacheId;
  if (isPaidStatus(status) || payedAt) {
    const paidTs = payedAt || new Date().toISOString();
    const ids = [respId, cacheId, transactionId, clientIdentifier];
    await writeStatus(ctx.env, ids, 'PAID', paidTs);
    await syncUtmifyStatus(ctx.env, ids, 'PAID', paidTs);
    return json({ ok: true, id: respId, status: 'PAID', payedAt: paidTs, _source: 'mangofy_get' });
  }
  return json({ ok: true, id: respId, status, payedAt: null, _source: 'mangofy_get' });
}

async function webhook(ctx: EventContext<Env, string, unknown>): Promise<Response> {
  if (!webhookTokenOk(ctx.request, ctx.env)) return json({ ok: false, error: 'Webhook nao autorizado.' }, 401);
  const body = await readJson(ctx.request);
  const status = mangofyStatus(body);
  const txId = mangofyPaymentCode(body);
  const external = mangofyExternal(body);
  const payedAt = mangofyPaidAt(body);
  if (txId || external) {
    const paid = isPaidStatus(status) || Boolean(payedAt);
    const paidTs = paid ? payedAt || new Date().toISOString() : null;
    const ids = [txId, external];
    const finalStatus = paid ? 'PAID' : status || 'PENDING';
    await writeStatus(ctx.env, ids, finalStatus, paidTs);
    await syncUtmifyStatus(ctx.env, ids, finalStatus, paidTs);
  }
  return json({ ok: true, received: true });
}

async function trackSale(ctx: EventContext<Env, string, unknown>): Promise<Response> {
  if (ctx.request.method !== 'POST') return json({ ok: false, error: 'Metodo nao permitido' }, 405);
  const pixel = (ctx.env.META_PIXEL_ID || '').trim();
  const token = (ctx.env.META_ACCESS_TOKEN || '').trim();
  if (!pixel || !token) return json({ ok: false, error: 'META_PIXEL_ID / META_ACCESS_TOKEN nao configurados' }, 500);
  const body = await readJson(ctx.request);
  const eventId = str(body.event_id).trim();
  const eventName = str(body.event_name).trim() || 'Purchase';
  const value = Number(body.value) || 0;
  const currency = str(body.currency).trim() || 'BRL';
  const payload = {
    data: [
      {
        event_name: eventName,
        event_time: Math.floor(Date.now() / 1000),
        event_id: eventId || undefined,
        action_source: 'website',
        user_data: {
          fbp: str(body.fbp) || undefined,
          fbc: str(body.fbc) || undefined,
          client_ip_address: ctx.request.headers.get('cf-connecting-ip') || undefined,
          client_user_agent: ctx.request.headers.get('user-agent') || undefined,
        },
        custom_data: { value, currency },
      },
    ],
  };
  try {
    const res = await fetch(`https://graph.facebook.com/v21.0/${encodeURIComponent(pixel)}/events?access_token=${encodeURIComponent(token)}`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(payload),
    });
    if (!res.ok) return json({ ok: false, error: 'Meta recusou o evento' }, 502);
    return json({ ok: true });
  } catch {
    return json({ ok: false, error: 'Falha ao enviar evento' }, 502);
  }
}
