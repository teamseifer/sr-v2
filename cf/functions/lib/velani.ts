import type { Env } from './env';
import { velaniBase, velaniSecret } from './env';
import { digits, str } from './http';
import { genericDepositName } from './genesys';

export interface GwResult {
  httpStatus: number;
  data: unknown;
  transportError: boolean;
  error: string;
}

export async function velaniRequest(
  env: Env,
  method: 'GET' | 'POST',
  path: string,
  payload?: unknown,
  extraHeaders: Record<string, string> = {}
): Promise<GwResult> {
  const headers: Record<string, string> = {
    accept: 'application/json',
    'x-api-key': velaniSecret(env),
    'user-agent': 'funil-sr-v2-proxy/5.0 (velani/pages)',
    ...extraHeaders,
  };
  const init: RequestInit = { method, headers, redirect: 'manual' };
  if (payload !== undefined && method !== 'GET') {
    headers['content-type'] = 'application/json';
    init.body = JSON.stringify(payload);
  }
  try {
    const res = await fetch(velaniBase(env) + path, init);
    const raw = await res.text();
    let data: unknown = null;
    if (raw.trim()) {
      try {
        data = JSON.parse(raw);
      } catch {
        data = null;
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

const FIRST = ['Ana', 'Maria', 'Joao', 'Pedro', 'Lucas', 'Gabriel', 'Camila', 'Beatriz', 'Paulo', 'Carla'];
const LAST = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Lima', 'Pereira', 'Costa', 'Ferreira'];
const DDDS = ['11', '21', '31', '41', '51', '61', '71', '81', '85', '19'];
const pickOne = <T>(arr: T[]): T => arr[Math.floor(Math.random() * arr.length)];

function emailFromName(name: string): string {
  const slug = name
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '')
    .slice(0, 24);
  return `${slug || 'cliente'}${Math.floor(Math.random() * 90 + 10)}@gmail.com`;
}

function phone11(raw: string): string {
  let p = digits(raw);
  if (p.length > 11 && p.startsWith('55')) p = p.slice(2);
  if (p.length === 10) p = p.slice(0, 2) + '9' + p.slice(2);
  if (p.length !== 11 || p[2] !== '9') {
    p = pickOne(DDDS) + '9' + String(Math.floor(Math.random() * 1e8)).padStart(8, '0');
  }
  return p;
}

export function buildVelaniBody(request: Request, env: Env, payload: Record<string, unknown>): Record<string, unknown> {
  const amount = Math.round(Number(payload.amount) * 100) / 100;
  let cents = Math.round(amount * 100);
  if (cents < 100) cents = 100;
  const client = (payload.client && typeof payload.client === 'object' ? payload.client : {}) as Record<string, unknown>;
  let doc = digits(client.document);
  if (doc.length !== 11 && doc.length !== 14) doc = generateCpf();
  const name = str(client.name).trim() || `${pickOne(FIRST)} ${pickOne(LAST)}`;
  let email = str(client.email).trim().toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) email = emailFromName(name);
  let title = genericDepositName(str(payload.productType));
  if (payload.fromEmail) title += '-email';

  const host = new URL(request.url).host;
  const secret = (env.VELANI_WEBHOOK_SECRET || env.WEBHOOK_SECRET || '').trim();
  let postback = `https://${host}/api_proxy.php?action=webhook`;
  if (secret && !secret.startsWith('COLOQUE_')) postback += `&token=${encodeURIComponent(secret)}`;

  const body: Record<string, unknown> = {
    paymentMethod: 'pix',
    amount: cents,
    externalId: str(payload.identifier),
    description: title,
    postbackUrl: postback,
    customer: {
      name,
      email,
      phone: phone11(str(client.phone)),
      document: { type: doc.length === 14 ? 'cnpj' : 'cpf', number: doc },
    },
    items: [{ title, unitPrice: cents, quantity: 1 }],
  };

  const track: Record<string, string> = {};
  for (const src of [payload.tracking, payload.trackProps]) {
    if (!src || typeof src !== 'object') continue;
    const rec = src as Record<string, unknown>;
    for (const k of ['utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term', 'src', 'sck']) {
      if (track[k]) continue;
      const v = rec[k];
      if (typeof v === 'string' && v.trim()) track[k] = v.trim().slice(0, 255);
    }
  }
  if (Object.keys(track).length) body.tracking = track;
  return body;
}

export function velaniError(decoded: unknown, fallback: string): string {
  if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded)) return fallback;
  const d = decoded as Record<string, unknown>;
  if (Array.isArray(d.message)) {
    const msgs = d.message.filter((m): m is string => typeof m === 'string' && m !== '');
    if (msgs.length) return msgs.join('; ');
  }
  if (d.error && typeof d.error === 'object') {
    const err = d.error as Record<string, unknown>;
    if (typeof err.message === 'string' && err.message) return err.message;
  }
  for (const k of ['message', 'error', 'errorDescription']) {
    if (typeof d[k] === 'string' && d[k]) return d[k] as string;
  }
  return fallback;
}

export function velaniRejected(decoded: unknown): boolean {
  if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded)) return false;
  return (decoded as Record<string, unknown>).success === false;
}
