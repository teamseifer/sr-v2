import type { Env } from './env';

export function json(body: Record<string, unknown>, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'content-type': 'application/json; charset=utf-8',
      'cache-control': 'no-store',
      'access-control-allow-methods': 'GET, POST, OPTIONS',
      'access-control-allow-headers': 'content-type',
    },
  });
}

export function preflight(request: Request): Response | null {
  if (request.method.toUpperCase() === 'OPTIONS') return json({ ok: true });
  return null;
}

export async function readJson(request: Request): Promise<Record<string, unknown>> {
  try {
    const raw = await request.text();
    if (!raw.trim()) return {};
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
      ? (parsed as Record<string, unknown>)
      : {};
  } catch {
    return {};
  }
}

export function str(v: unknown): string {
  return typeof v === 'string' ? v : v === undefined || v === null ? '' : String(v);
}

export function digits(v: unknown): string {
  return str(v).replace(/\D/g, '');
}

export function pick(obj: unknown, keys: string[]): string | null {
  if (!obj || typeof obj !== 'object') return null;
  const o = obj as Record<string, unknown>;
  for (const k of keys) {
    const v = o[k];
    if (typeof v === 'string' && v.trim() !== '') return v.trim();
    if (typeof v === 'number' && Number.isFinite(v)) return String(v);
  }
  return null;
}

export function asRecord(v: unknown): Record<string, unknown> | null {
  if (v && typeof v === 'object' && !Array.isArray(v)) return v as Record<string, unknown>;
  return null;
}

const PAID = ['PAID', 'PAGO', 'COMPLETED', 'PAYED', 'APPROVED', 'SUCCESS', 'CONFIRMED', 'AUTHORIZED'];

export function isPaidStatus(s: unknown): boolean {
  return PAID.indexOf(str(s).toUpperCase()) !== -1;
}

export function clientIp(request: Request): string {
  const ip = request.headers.get('cf-connecting-ip') || '';
  return ip || '0.0.0.0';
}

export function webhookTokenOk(request: Request, env: Env): boolean {
  const secrets = [env.GENESYS_WEBHOOK_SECRET, env.WEBHOOK_SECRET]
    .map((v) => (v || '').trim())
    .filter((v) => v !== '' && !v.startsWith('COLOQUE_'));
  if (secrets.length === 0) return true;
  const token = new URL(request.url).searchParams.get('token') || '';
  return secrets.indexOf(token) !== -1;
}
