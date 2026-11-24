import type { Env } from './env';
import { mangofyBase, mangofyKey, mangofyStore } from './env';
import { asRecord, clientIp, digits, str } from './http';
import { genericDepositName } from './genesys';

export interface GwResult {
  httpStatus: number;
  data: unknown;
  transportError: boolean;
  error: string;
}

export async function mangofyRequest(
  env: Env,
  method: 'GET' | 'POST',
  path: string,
  payload?: unknown
): Promise<GwResult> {
  const headers: Record<string, string> = {
    accept: 'application/json',
    Authorization: mangofyKey(env),
    'Store-Code': mangofyStore(env),
    'user-agent': 'funil-sr-v2-proxy/5.0 (mangofy)',
  };
  const init: RequestInit = { method, headers, redirect: 'manual' };
  if (payload !== undefined && method !== 'GET') {
    headers['content-type'] = 'application/json';
    init.body = JSON.stringify(payload);
  }
  try {
    const res = await fetch(mangofyBase(env) + path, init);
    const raw = await res.text();
    let data: unknown = null;
    if (raw.trim()) {
      try {
        data = JSON.parse(raw);
      } catch {
        data = { message: raw.slice(0, 300) };
      }
    }
    return { httpStatus: res.status || 502, data, transportError: false, error: '' };
  } catch (e) {
    return {
      httpStatus: 502,
      data: null,
      transportError: true,
      error: e instanceof Error ? e.message : 'falha de rede',
    };
  }
}

const FIRST = ['Ana', 'Maria', 'Joao', 'Pedro', 'Lucas', 'Gabriel', 'Camila', 'Beatriz', 'Paulo', 'Carla'];
const LAST = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Lima', 'Pereira', 'Costa', 'Ferreira'];
const DDDS = ['11', '21', '31', '41', '51', '61', '71', '81', '85', '19'];
const pickOne = <T>(arr: T[]): T => arr[Math.floor(Math.random() * arr.length)];

function generateCpf(): string {
  const n: number[] = [];
  for (let i = 0; i < 9; i++) n.push(Math.floor(Math.random() * 10));
  if (new Set(n).size === 1) n[8] = (n[8] + 1) % 10;
  let d1 = 0;
  for (let i = 0; i < 9; i++) d1 += n[i] * (10 - i);
  d1 = (d1 * 10) % 11;
  if (d1 === 10) d1 = 0;
  n.push(d1);
  let d2 = 0;
  for (let i = 0; i < 10; i++) d2 += n[i] * (11 - i);
  d2 = (d2 * 10) % 11;
  if (d2 === 10) d2 = 0;
  n.push(d2);
  return n.join('');
}

function emailFromName(name: string): string {
  const slug = name
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '')
    .slice(0, 24);
  return `${slug || 'cliente'}${Math.floor(Math.random() * 90 + 10)}@gmail.com`;
}

function phone55(raw: string): string {
  let p = digits(raw);
  if (p.startsWith('55') && p.length > 11) p = p.slice(2);
  if (p.length === 10) p = p.slice(0, 2) + '9' + p.slice(2);
  if (p.length !== 11 || p[2] !== '9') {
    p = pickOne(DDDS) + '9' + String(Math.floor(Math.random() * 1e8)).padStart(8, '0');
  }
  return `55${p}`;
}

export function buildMangofyBody(
  request: Request,
  env: Env,
  payload: Record<string, unknown>
): Record<string, unknown> {
  const amount = Math.round(Number(payload.amount) * 100) / 100;
  let cents = Math.round(amount * 100);
  if (cents < 500) cents = 500;
  const client = asRecord(payload.client) || {};
  let doc = digits(client.document);
  if (doc.length !== 11 && doc.length !== 14) doc = generateCpf();
  const name = str(client.name).trim() || `${pickOne(FIRST)} ${pickOne(LAST)}`;
  let email = str(client.email).trim().toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) email = emailFromName(name);
  let title = genericDepositName(str(payload.productType));
  if (payload.fromEmail) title += '-email';
  const itemId = title
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  const host = new URL(request.url).host;
  const secret = (env.WEBHOOK_SECRET || '').trim();
  let postback = `https://${host}/api_proxy.php?action=webhook`;
  if (secret && !secret.startsWith('COLOQUE_')) postback += `&token=${encodeURIComponent(secret)}`;

  const metadata: Record<string, string> = {};
  for (const src of [payload.tracking, payload.trackProps]) {
    if (!src || typeof src !== 'object') continue;
    const rec = src as Record<string, unknown>;
    for (const k of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'src', 'sck']) {
      if (metadata[k]) continue;
      const v = rec[k];
      if (typeof v === 'string' && v.trim()) metadata[k] = v.trim().slice(0, 255);
    }
  }

  const body: Record<string, unknown> = {
    external_code: str(payload.identifier).slice(0, 100),
    payment_method: 'pix',
    payment_format: 'regular',
    installments: 1,
    payment_amount: cents,
    shipping_amount: 0,
    postback_url: postback.slice(0, 250),
    items: [
      {
        code: itemId || 'DB-DEPOSITO-PIX',
        name: title,
        description: title,
        quantity: 1,
        price: cents,
        digital_flag: true,
      },
    ],
    customer: {
      email,
      name: name.slice(0, 254),
      document: doc.slice(0, 14),
      phone: phone55(str(client.phone)),
      ip: clientIp(request).slice(0, 100),
    },
    pix: { expires_in_days: 1 },
  };
  if (Object.keys(metadata).length) body.extra = { metadata };
  return body;
}

export function paymentRecord(decoded: unknown): Record<string, unknown> {
  const root = asRecord(decoded);
  if (!root) return {};
  const inner = asRecord(root.data);
  if (inner && (inner.payment_code || inner.payment_status || inner.pix)) return inner;
  return root;
}

export function mangofyError(decoded: unknown, fallback: string): string {
  if (typeof decoded === 'string' && decoded.trim()) return decoded.trim().slice(0, 300);
  const rec = asRecord(decoded);
  if (!rec) return fallback;
  if (typeof rec.message === 'string' && rec.message.trim()) return rec.message.trim();
  if (Array.isArray(rec.message)) {
    const msgs = rec.message.filter((m): m is string => typeof m === 'string' && m !== '');
    if (msgs.length) return msgs.join('; ');
  }
  const errors = rec.errors;
  if (errors && typeof errors === 'object') {
    const parts: string[] = [];
    for (const [k, v] of Object.entries(errors as Record<string, unknown>)) {
      if (Array.isArray(v) && v.length) parts.push(`${k}: ${v.join(', ')}`);
      else if (typeof v === 'string' && v) parts.push(`${k}: ${v}`);
    }
    if (parts.length) return parts.join('; ').slice(0, 300);
  }
  return fallback;
}

export function isDuplicateExternal(result: GwResult): boolean {
  if (result.httpStatus === 409) return true;
  const msg = mangofyError(result.data, '').toLowerCase();
  return msg.includes('external') && (msg.includes('already') || msg.includes('existe') || msg.includes('unique') || msg.includes('duplic'));
}

const PAID = new Set(['approved']);
const PENDING = new Set(['pending', 'in_process', 'in_review', 'challenge_active']);

export function mangofyStatus(decoded: unknown): string {
  const rec = paymentRecord(decoded);
  const raw = str(rec.payment_status || rec.status).toLowerCase();
  if (PAID.has(raw) || rec.approved_at) return 'PAID';
  if (PENDING.has(raw) || raw === '') return 'PENDING';
  return raw.toUpperCase();
}

export function mangofyPaidAt(decoded: unknown): string | null {
  const rec = paymentRecord(decoded);
  const v = rec.approved_at;
  return typeof v === 'string' && v.trim() ? v.trim() : null;
}

export function mangofyPaymentCode(decoded: unknown): string {
  const rec = paymentRecord(decoded);
  return str(rec.payment_code).trim();
}

export function mangofyExternal(decoded: unknown): string {
  const rec = paymentRecord(decoded);
  return str(rec.external_code).trim();
}

export function mangofyPixCode(decoded: unknown): string {
  const pix = asRecord(paymentRecord(decoded).pix);
  return pix ? str(pix.pix_qrcode_text).trim() : '';
}

export function mangofyPixImage(decoded: unknown): string {
  const pix = asRecord(paymentRecord(decoded).pix);
  return pix ? str(pix.pix_qrcode_image).trim() : '';
}
