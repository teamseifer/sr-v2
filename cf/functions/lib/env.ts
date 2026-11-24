/**
 * Secrets (wrangler pages secret put) nunca entram no codigo.
 * GENESYS_ACCOUNT_HASH, GENESYS_API_TOKEN, WEBHOOK_SECRET,
 * CPF_API_TOKEN, CPF_API2_TOKEN, META_ACCESS_TOKEN, UTMIFY_API_TOKEN.
 */

export interface Env {
  GENESYS_ACCOUNT_HASH?: string;
  GENESYS_API_TOKEN?: string;
  GENESYS_API_SECRET?: string;
  GENESYS_WEBHOOK_SECRET?: string;
  VELANI_SECRET_KEY?: string;
  VELANI_PUBLIC_KEY?: string;
  VELANI_WEBHOOK_SECRET?: string;
  WEBHOOK_SECRET?: string;
  CPF_API_TOKEN?: string;
  CPF_API_KEY?: string;
  CPF_API2_TOKEN?: string;
  CPF_API2_KEY?: string;
  CPF_API3_TOKEN?: string;
  CPF_API3_KEY?: string;
  META_ACCESS_TOKEN?: string;
  UTMIFY_API_TOKEN?: string;
  SUPABASE_UPLOAD_TOKEN?: string;
  MANGOFY_API_KEY?: string;
  MANGOFY_STORE_CODE?: string;

  GENESYS_BASE_URL?: string;
  MANGOFY_BASE_URL?: string;
  VELANI_BASE_URL?: string;
  UTMIFY_API_URL?: string;
  SUPABASE_URL?: string;
  CPF_API_URL?: string;
  CPF_API_MODE?: string;
  CPF_API2_URL?: string;
  CPF_API2_MODE?: string;
  CPF_API3_URL?: string;
  CPF_API3_MODE?: string;
  CPF_CACHE_TTL?: string;
  META_PIXEL_ID?: string;

  SR_TX: KVNamespace;
}

export const GENESYS_BASE_DEFAULT = 'https://api.genesys.finance';

export function genesysBase(env: Env): string {
  return (env.GENESYS_BASE_URL || GENESYS_BASE_DEFAULT).trim().replace(/\/+$/, '');
}

export function genesysToken(env: Env): string {
  const token = (env.GENESYS_API_TOKEN || env.GENESYS_API_SECRET || '').trim();
  if (token === '' || token.startsWith('COLOQUE_')) return '';
  return token;
}

export function genesysReady(env: Env): boolean {
  return genesysToken(env) !== '';
}

export const VELANI_BASE_DEFAULT = 'https://api.velanipagamentos.com.br/api/v1/api-gateway';

export function velaniBase(env: Env): string {
  return (env.VELANI_BASE_URL || VELANI_BASE_DEFAULT).trim().replace(/\/+$/, '');
}

/** A Velani so cria PIX com a secret key (sk_). A public key (pk_) nao serve aqui. */
export function velaniSecret(env: Env): string {
  const secret = (env.VELANI_SECRET_KEY || '').trim();
  if (secret.toLowerCase().startsWith('sk_')) return secret;
  return '';
}

export function velaniReady(env: Env): boolean {
  return velaniSecret(env) !== '';
}

export const MANGOFY_BASE_DEFAULT = 'https://checkout.mangofy.com.br';

export function mangofyBase(env: Env): string {
  return (env.MANGOFY_BASE_URL || MANGOFY_BASE_DEFAULT).trim().replace(/\/+$/, '');
}

export function mangofyKey(env: Env): string {
  const key = (env.MANGOFY_API_KEY || '').trim();
  if (key === '' || key.startsWith('COLOQUE_')) return '';
  return key;
}

export function mangofyStore(env: Env): string {
  const code = (env.MANGOFY_STORE_CODE || '').trim();
  if (code === '' || code.startsWith('COLOQUE_')) return '';
  return code;
}

export function mangofyReady(env: Env): boolean {
  return mangofyKey(env) !== '' && mangofyStore(env) !== '';
}

export function webhookSecrets(env: Env): string[] {
  const out: string[] = [];
  for (const v of [env.GENESYS_WEBHOOK_SECRET, env.WEBHOOK_SECRET]) {
    const s = (v || '').trim();
    if (s && !s.startsWith('COLOQUE_') && out.indexOf(s) === -1) out.push(s);
  }
  return out;
}
