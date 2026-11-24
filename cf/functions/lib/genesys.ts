import type { Env } from './env';
import { genesysBase, genesysToken } from './env';
import { asRecord, clientIp, digits, pick, str } from './http';

export interface GwResult {
  httpStatus: number;
  data: unknown;
  transportError: boolean;
  error: string;
}

export async function genesysRequest(
  env: Env,
  method: 'GET' | 'POST',
  path: string,
  payload?: unknown
): Promise<GwResult> {
  const token = genesysToken(env);
  const headers: Record<string, string> = {
    accept: 'application/json',
    'api-secret': token,
    authorization: `Bearer ${token}`,
    'user-agent': 'funil-sr-v2-proxy/5.0 (genesys/pages)',
  };
  const account = (env.GENESYS_ACCOUNT_HASH || '').trim();
  if (account !== '' && !account.startsWith('COLOQUE_')) headers['account-hash'] = account;
  const init: RequestInit = { method, headers, redirect: 'manual' };
  if (payload !== undefined && method !== 'GET') {
    headers['content-type'] = 'application/json';
    init.body = JSON.stringify(payload);
  }
  try {
    const res = await fetch(genesysBase(env) + path, init);
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

const DEPOSIT: Record<string, number> = {
  acordo: 0,
  registro: 1,
  adm: 2,
  iof: 3,
  multa_ir: 4,
  issqn: 5,
  verificacao: 6,
  liberacao: 7,
  chave_pix: 8,
  ted: 9,
  certidao: 10,
  cadastro: 11,
  seguro: 12,
  prioridade: 13,
  imposto_ress: 14,
  biometria: 15,
  encerramento: 16,
};

export function genericDepositName(productType: string): string {
  const n = DEPOSIT[productType.toLowerCase().trim()];
  if (n === undefined) return 'DB Deposito PIX';
  return n > 0 ? `DB Deposito PIX ${n}` : 'DB Deposito PIX';
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

function pickOne<T>(arr: T[]): T {
  return arr[Math.floor(Math.random() * arr.length)];
}

function emailFromName(name: string): string {
  const slug = name
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '')
    .slice(0, 24);
  const providers = ['gmail.com', 'hotmail.com', 'outlook.com'];
  return `${slug || 'cliente'}${Math.floor(Math.random() * 90 + 10)}@${pickOne(providers)}`;
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

export function buildGenesysBody(
  request: Request,
  env: Env,
  payload: Record<string, unknown>
): Record<string, unknown> {
  const amount = Math.round(Number(payload.amount) * 100) / 100;
  const client = asRecord(payload.client) || {};
  let doc = digits(client.document);
  if (doc.length !== 11 && doc.length !== 14) doc = generateCpf();
  const name = str(client.name).trim() || `${pickOne(FIRST)} ${pickOne(LAST)}`;
  let email = str(client.email).trim().toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) email = emailFromName(name);
  const productType = str(payload.productType);
  let title = genericDepositName(productType);
  if (payload.fromEmail) title += '-email';

  const host = new URL(request.url).host;
  const secret = (env.WEBHOOK_SECRET || env.GENESYS_WEBHOOK_SECRET || '').trim();
  let webhook = `https://${host}/api_proxy.php?action=webhook`;
  if (secret && !secret.startsWith('COLOQUE_')) webhook += `&token=${encodeURIComponent(secret)}`;

  const customer: Record<string, unknown> = {
    name,
    email,
    document: doc,
    document_type: doc.length === 14 ? 'CNPJ' : 'CPF',
    phone: phone11(str(client.phone)),
  };
  for (const src of [payload.tracking, payload.trackProps]) {
    if (!src || typeof src !== 'object') continue;
    const rec = src as Record<string, unknown>;
    for (const k of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']) {
      if (customer[k]) continue;
      const v = rec[k];
      if (typeof v === 'string' && v.trim()) customer[k] = v.trim().slice(0, 255);
    }
  }

  return {
    total_amount: amount,
    payment_method: 'PIX',
    external_id: str(payload.identifier),
    ip: clientIp(request),
    webhook_url: webhook,
    customer,
    items: [
      {
        id: title.toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-|-$/g, ''),
        title,
        description: title,
        price: amount,
        quantity: 1,
        is_physical: true,
      },
    ],
  };
}

function extractTx(decoded: unknown): Record<string, unknown> {
  const o = asRecord(decoded);
  if (!o) return {};
  for (const k of ['transaction', 'data']) {
    const inner = asRecord(o[k]);
    if (inner && (inner.transaction_id || inner.id || inner.status || inner.pix)) return inner;
  }
  return o;
}

const CODE_KEYS = ['pix_copy_paste', 'copy_paste', 'pixQrCode', 'qr_code', 'qrcode', 'brcode', 'emv', 'payload', 'key', 'code', 'qrCode'];
const QR_KEYS = ['pix_qr_code_url', 'qr_code_url', 'qr_code_image', 'qrCodeBase64', 'qr_code_base64', 'base64', 'image'];

function nestedPix(src: Record<string, unknown> | null): Record<string, unknown>[] {
  if (!src) return [];
  const out: Record<string, unknown>[] = [];
  const pix = asRecord(src.pix);
  if (pix) out.push(pix);
  const payment = asRecord(src.payment);
  const pp = payment ? asRecord(payment.pix) : null;
  if (pp) out.push(pp);
  const info = asRecord(src.pixInformation);
  if (info) out.push(info);
  return out;
}

export function extractPixCode(tx: unknown, decoded: unknown): string {
  const direct = pick(tx, CODE_KEYS) || pick(decoded, CODE_KEYS);
  if (direct) return direct;
  for (const src of [asRecord(tx), asRecord(decoded)]) {
    for (const pix of nestedPix(src)) {
      const code = pick(pix, CODE_KEYS);
      if (code) return code;
    }
  }
  return '';
}

export function extractPixQr(tx: unknown, decoded: unknown): string {
  const direct = pick(tx, QR_KEYS) || pick(decoded, QR_KEYS);
  if (direct) return direct;
  for (const src of [asRecord(tx), asRecord(decoded)]) {
    for (const pix of nestedPix(src)) {
      const qr = pick(pix, QR_KEYS);
      if (qr) return qr;
    }
  }
  return '';
}

export function extractTxId(tx: unknown, decoded: unknown): string {
  return (
    pick(tx, ['transaction_id', 'transactionId', 'id', 'uid']) ||
    pick(decoded, ['transaction_id', 'transactionId', 'id', 'uid']) ||
    ''
  );
}

export function extractStatus(tx: unknown, decoded: unknown): string {
  return pick(tx, ['status']) || pick(decoded, ['status']) || 'PENDING';
}

export function extractPayedAt(tx: unknown, decoded: unknown): string | null {
  const v =
    pick(tx, ['paid_at', 'payedAt', 'paidAt', 'paid_date']) ||
    pick(decoded, ['paid_at', 'payedAt', 'paidAt', 'paid_date']);
  return v || null;
}

export function errorMessage(decoded: unknown, fallback: string): string {
  const msg = pick(decoded, ['errorDescription', 'message', 'error']);
  return msg || fallback;
}

export { extractTx };
