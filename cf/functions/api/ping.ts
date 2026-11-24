import type { Env } from '../lib/env';
import { velaniReady } from '../lib/env';
import { json, preflight } from '../lib/http';

export const onRequestGet: PagesFunction<Env> = async (ctx) => {
  const pf = preflight(ctx.request);
  if (pf) return pf;
  return json({
    ok: true,
    message: 'sr-v2 Pages OK.',
    gateway: 'velani',
    velani_key_configurada: velaniReady(ctx.env),
    kv: Boolean(ctx.env.SR_TX),
  });
};
