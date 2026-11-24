import type { Env } from './env';

export interface StatusCache {
  status: string;
  payedAt: string | null;
  id: string;
}

export async function readStatus(env: Env, id: string): Promise<StatusCache | null> {
  if (!id || !env.SR_TX) return null;
  try {
    const raw = await env.SR_TX.get(`st:${id}`, 'json');
    if (!raw || typeof raw !== 'object') return null;
    return raw as StatusCache;
  } catch {
    return null;
  }
}

export async function writeStatus(env: Env, ids: string[], status: string, payedAt: string | null): Promise<void> {
  if (!env.SR_TX) return;
  const seen = new Set<string>();
  for (const id of ids) {
    const k = (id || '').trim();
    if (!k || seen.has(k)) continue;
    seen.add(k);
    try {
      await env.SR_TX.put(
        `st:${k}`,
        JSON.stringify({ status, payedAt, id: k }),
        { expirationTtl: 60 * 60 * 24 * 7 }
      );
    } catch {
      /* KV indisponivel nao derruba o PIX */
    }
  }
}
