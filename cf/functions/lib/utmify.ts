/**
 * Pedidos na UTMify — mesmo contrato do api_proxy.php (send_utmify_order).
 * Pendente sai na criacao do PIX. Aprovado sai quando a Mangofy confirma.
 * A UTMify faz upsert por orderId, entao reenviar o mesmo pedido e seguro.
 */

import type { Env } from './env';
import { genericDepositName } from './genesys';

const TTL = 60 * 60 * 24 * 30;
const DEFAULT_URL = 'https://api.utmify.com.br/api-credentials/orders';

export interface UtmifyOrder {
  orderId: string;
  platform: 'Genesys';
  paymentMethod: 'pix';
  status: 'waiting_payment' | 'paid' | 'refused' | 'refunded' | 'chargedback';
  createdAt: string;
  approvedDate: string | null;
  refundedAt: string | null;
  isTest: false;
  customer: {
    name: string;
    email: string;
    phone: string;
    document: string;
    country: 'BR';
    ip: string;
  };
  products: Array<{
    id: string;
    name: string;
    planId: null;
    planName: null;
    quantity: number;
    priceInCents: number;
  }>;
  trackingParameters: {
    src: string | null;
    sck: string | null;
    utm_source: string | null;
    utm_campaign: string | null;
    utm_medium: string | null;
    utm_content: string | null;
    utm_term: string | null;
    utm_id: string | null;
    fbc: string | null;
    fbp: string | null;
  };
  commission: {
    totalPriceInCents: number;
    gatewayFeeInCents: number;
    userCommissionInCents: number;
    currency: 'BRL';
  };
}

interface StoredOrder {
  order: UtmifyOrder;
  synced: string;
}

function utcStamp(input?: string | null): string {
  const raw = (input || '').trim();
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)) return raw;
  const d = raw ? new Date(raw) : new Date();
  const when = Number.isNaN(d.getTime()) ? new Date() : d;
  return when.toISOString().slice(0, 19).replace('T', ' ');
}

function tp(track: Record<string, string>, key: string): string | null {
  const v = (track[key] || '').trim();
  return v === '' ? null : v.slice(0, 300);
}

function depositId(productType: string): string {
  return genericDepositName(productType)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

export function mapUtmifyStatus(gatewayStatus: string): UtmifyOrder['status'] {
  const up = gatewayStatus.toUpperCase();
  if (['COMPLETED', 'PAID', 'PAGO', 'PAYED', 'APPROVED', 'SUCCESS', 'CONFIRMED', 'AUTHORIZED'].includes(up)) {
    return 'paid';
  }
  if (['FAILED', 'REJECTED', 'CANCELLED', 'CANCELED', 'CANCELADO', 'EXPIRED', 'EXPIRADO'].includes(up)) {
    return 'refused';
  }
  if (['REFUNDED', 'REEMBOLSADO', 'ESTORNADO'].includes(up)) return 'refunded';
  if (['CHARGED_BACK', 'CHARGEDBACK', 'CHARGEBACK'].includes(up)) return 'chargedback';
  return 'waiting_payment';
}

function feeCents(data: unknown): number {
  const sources: Record<string, unknown>[] = [];
  if (data && typeof data === 'object') {
    sources.push(data as Record<string, unknown>);
    const inner = (data as Record<string, unknown>).data;
    if (inner && typeof inner === 'object') sources.push(inner as Record<string, unknown>);
  }
  for (const src of sources) {
    for (const k of ['fee', 'feeInCents', 'gatewayFee', 'taxes']) {
      const v = src[k];
      if (typeof v === 'number' && Number.isFinite(v)) {
        return v < 100 ? Math.round(v * 100) : Math.round(v);
      }
    }
  }
  return 0;
}

export function buildUtmifyOrder(input: {
  orderId: string;
  amount: number;
  productType: string;
  name: string;
  email: string;
  phone: string;
  document: string;
  ip: string;
  track: Record<string, string>;
  gatewayData?: unknown;
  createdAt?: string;
}): UtmifyOrder {
  const amountCents = Math.round(input.amount * 100);
  const fee = feeCents(input.gatewayData);
  const name = genericDepositName(input.productType);
  return {
    orderId: input.orderId,
    platform: 'Genesys',
    paymentMethod: 'pix',
    status: 'waiting_payment',
    createdAt: utcStamp(input.createdAt),
    approvedDate: null,
    refundedAt: null,
    isTest: false,
    customer: {
      name: input.name,
      email: input.email,
      phone: input.phone,
      document: input.document,
      country: 'BR',
      ip: input.ip,
    },
    products: [
      {
        id: depositId(input.productType),
        name,
        planId: null,
        planName: null,
        quantity: 1,
        priceInCents: amountCents,
      },
    ],
    trackingParameters: {
      src: tp(input.track, 'src'),
      sck: tp(input.track, 'sck'),
      utm_source: tp(input.track, 'utm_source'),
      utm_campaign: tp(input.track, 'utm_campaign'),
      utm_medium: tp(input.track, 'utm_medium'),
      utm_content: tp(input.track, 'utm_content'),
      utm_term: tp(input.track, 'utm_term'),
      utm_id: tp(input.track, 'utm_id'),
      fbc: tp(input.track, 'fbc'),
      fbp: tp(input.track, 'fbp'),
    },
    commission: {
      totalPriceInCents: amountCents,
      gatewayFeeInCents: fee,
      userCommissionInCents: Math.max(0, amountCents - fee),
      currency: 'BRL',
    },
  };
}

async function putOrder(env: Env, stored: StoredOrder, aliases: string[]): Promise<void> {
  const id = stored.order.orderId;
  if (!id || !env.SR_TX) return;
  await env.SR_TX.put(`utmify:${id}`, JSON.stringify(stored), { expirationTtl: TTL });
  for (const alias of aliases) {
    const a = alias.trim();
    if (a === '' || a === id) continue;
    await env.SR_TX.put(`utmify-alias:${a}`, id, { expirationTtl: TTL });
  }
}

async function loadOrder(env: Env, ids: string[]): Promise<StoredOrder | null> {
  if (!env.SR_TX) return null;
  const seen = new Set<string>();
  for (const raw of ids) {
    const id = (raw || '').trim();
    if (id === '' || seen.has(id)) continue;
    seen.add(id);
    const direct = await env.SR_TX.get(`utmify:${id}`);
    if (direct) {
      try {
        return JSON.parse(direct) as StoredOrder;
      } catch {
        return null;
      }
    }
    const alias = await env.SR_TX.get(`utmify-alias:${id}`);
    if (!alias) continue;
    const via = await env.SR_TX.get(`utmify:${alias}`);
    if (!via) continue;
    try {
      return JSON.parse(via) as StoredOrder;
    } catch {
      return null;
    }
  }
  return null;
}

async function postOrder(env: Env, order: UtmifyOrder): Promise<boolean> {
  const token = (env.UTMIFY_API_TOKEN || '').trim();
  const url = (env.UTMIFY_API_URL || '').trim() || DEFAULT_URL;
  if (token === '') {
    console.log(`[utmify] token ausente, pedido nao enviado orderId=${order.orderId}`);
    return false;
  }
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json',
        'x-api-token': token,
      },
      body: JSON.stringify(order),
      signal: AbortSignal.timeout(8000),
    });
    const body = await res.text();
    console.log(
      `[utmify] POST status=${order.status} orderId=${order.orderId} http=${res.status} body=${body.slice(0, 300)}`
    );
    return res.ok;
  } catch (e) {
    console.log(`[utmify] falha de rede orderId=${order.orderId} ${e instanceof Error ? e.message : String(e)}`);
    return false;
  }
}

/** Grava e envia a venda pendente assim que o PIX e criado. */
export async function sendUtmifyPending(env: Env, order: UtmifyOrder, aliases: string[]): Promise<void> {
  try {
    const stored: StoredOrder = { order, synced: '' };
    const ok = await postOrder(env, order);
    if (ok) stored.synced = order.status;
    await putOrder(env, stored, aliases);
  } catch (e) {
    console.log(`[utmify] pendente falhou ${e instanceof Error ? e.message : String(e)}`);
  }
}

/**
 * Atualiza o mesmo orderId para paid/refused/refunded.
 * Nao rebaixa um pedido que ja foi entregue como pago.
 */
export async function syncUtmifyStatus(
  env: Env,
  ids: Array<string | undefined | null>,
  gatewayStatus: string,
  payedAt: string | null
): Promise<void> {
  try {
    const clean = ids.map((id) => (id || '').trim()).filter((id) => id !== '');
    const stored = await loadOrder(env, clean);
    if (!stored) {
      console.log(`[utmify] pedido nao encontrado ids=${clean.slice(0, 4).join(',')}`);
      return;
    }
    const next = mapUtmifyStatus(gatewayStatus);
    if (stored.synced === 'paid' && (next === 'waiting_payment' || next === 'refused')) return;
    if (stored.synced === next) return;
    stored.order.status = next;
    if (next === 'paid') stored.order.approvedDate = utcStamp(payedAt);
    if (next === 'refunded') stored.order.refundedAt = utcStamp(null);
    const ok = await postOrder(env, stored.order);
    if (ok) stored.synced = next;
    await putOrder(env, stored, clean);
  } catch (e) {
    console.log(`[utmify] status falhou ${e instanceof Error ? e.message : String(e)}`);
  }
}
