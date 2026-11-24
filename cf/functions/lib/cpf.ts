import type { Env } from './env';

const NAME_KEYS = ['nome', 'name', 'nome_completo', 'full_name', 'nomecompleto', 'nome_pessoa'];
const BIRTH_KEYS = ['nasc', 'nascimento', 'data_nascimento', 'datanascimento', 'birthdate', 'birth_date'];

export interface CpfResult {
  ok: boolean;
  nome?: string;
  cpf?: string;
  nascimento?: string;
  genero?: string;
  error?: string;
  code?: number;
}

function pickDeep(node: unknown, keys: string[], depth = 0): string {
  if (!node || typeof node !== 'object' || depth > 4) return '';
  const record = node as Record<string, unknown>;
  for (const [k, v] of Object.entries(record)) {
    if (v !== null && typeof v !== 'object' && keys.indexOf(k.toLowerCase()) !== -1) {
      const s = String(v).trim();
      if (s) return s;
    }
  }
  for (const v of Object.values(record)) {
    if (v && typeof v === 'object') {
      const found = pickDeep(v, keys, depth + 1);
      if (found) return found;
    }
  }
  return '';
}

function cpfValid(cpf: string): boolean {
  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
  for (let t = 9; t < 11; t++) {
    let d = 0;
    for (let c = 0; c < t; c++) d += Number(cpf[c]) * (t + 1 - c);
    d = ((10 * d) % 11) % 10;
    if (Number(cpf[t]) !== d) return false;
  }
  return true;
}

function normalizeDate(s: string): string {
  const iso = /^(\d{4})-(\d{2})-(\d{2})/.exec(s.trim());
  if (iso) return `${iso[3]}/${iso[2]}/${iso[1]}`;
  return s.trim();
}

async function sha256(text: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function token(env: Env, primary: 'CPF_API' | 'CPF_API2' | 'CPF_API3'): string {
  if (primary === 'CPF_API') {
    return (env.CPF_API_TOKEN || env.CPF_API_KEY || '').trim();
  }
  if (primary === 'CPF_API2') {
    return (env.CPF_API2_TOKEN || env.CPF_API2_KEY || '').trim();
  }
  return (env.CPF_API3_TOKEN || env.CPF_API3_KEY || '').trim();
}

async function fetchApi(url: string, tok: string, mode: string, cpf: string) {
  const base = url.replace(/\/+$/, '');
  const m = mode.toLowerCase();
  const headers: Record<string, string> = {
    accept: 'application/json',
    'user-agent':
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  };
  let full = base;
  if (m === 'query') {
    const join = base.includes('?') ? '&' : '?';
    full = `${base}${join}token=${encodeURIComponent(tok)}&cpf=${cpf}`;
  } else if (m === 'bearer') {
    full = `${base}/${cpf}`;
    headers.authorization = `Bearer ${tok}`;
  } else if (m === 'xapikey') {
    const join = base.includes('?') ? '&' : '?';
    full = `${base}${join}cpf=${cpf}`;
    headers['x-api-key'] = tok;
  } else {
    full = `${base}/${encodeURIComponent(tok)}/${cpf}`;
  }
  try {
    const res = await fetch(full, { method: 'GET', headers, signal: AbortSignal.timeout(20000) });
    const body = await res.text();
    let parsed: unknown = null;
    try {
      parsed = body ? JSON.parse(body) : null;
    } catch {
      parsed = null;
    }
    return {
      http: res.status,
      nome: pickDeep(parsed, NAME_KEYS),
      nasc: pickDeep(parsed, BIRTH_KEYS),
      json: parsed,
      note: noteOf(parsed, body),
    };
  } catch {
    return { http: 0, nome: '', nasc: '', json: null, note: 'sem conexao' };
  }
}

function noteOf(parsed: unknown, body: string): string {
  if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
    const rec = parsed as Record<string, unknown>;
    const msg = rec.message || rec.error || rec.msg || rec.erro;
    if (typeof msg === 'string' && msg.trim()) return msg.trim().slice(0, 140);
    return Object.keys(rec).slice(0, 8).join(',');
  }
  return body.replace(/\s+/g, ' ').trim().slice(0, 80);
}

function failed(hit: { http: number; nome: string; json: unknown }): boolean {
  return !hit.json || typeof hit.json !== 'object' || hit.http === 404 || hit.nome === '';
}

export async function consultarCpf(env: Env, cpfIn: string, diag = false): Promise<CpfResult> {
  const cpf = cpfIn.replace(/\D/g, '');
  if (!cpfValid(cpf)) return { ok: false, code: 400, error: 'CPF invalido' };

  const providers = [
    {
      url: (env.CPF_API_URL || 'https://api.apicpf.dev/consulta').trim(),
      tok: token(env, 'CPF_API'),
      mode: (env.CPF_API_MODE || 'xapikey').trim(),
    },
    {
      url: (env.CPF_API2_URL || 'https://api.apicpf.dev/consulta').trim(),
      tok: token(env, 'CPF_API2'),
      mode: (env.CPF_API2_MODE || 'xapikey').trim(),
    },
    {
      url: (env.CPF_API3_URL || 'https://api.zapgroup.shop/consultar-filtrada/cpf').trim(),
      tok: token(env, 'CPF_API3'),
      mode: (env.CPF_API3_MODE || 'query').trim(),
    },
  ].filter((p) => p.url && p.tok);

  if (!providers.length) {
    return { ok: false, code: 503, error: 'API de CPF nao configurada (defina CPF_API_TOKEN / CPF_API2_TOKEN / CPF_API3_TOKEN no Cloudflare)' };
  }

  const ttl = Math.max(60, Number(env.CPF_CACHE_TTL || '86400') || 86400);
  const cacheKey = `cpf:${await sha256(cpf)}`;
  try {
    const cached = await env.SR_TX.get(cacheKey, 'json');
    if (cached && typeof cached === 'object' && (cached as CpfResult).ok && (cached as CpfResult).nome) {
      return cached as CpfResult;
    }
  } catch {
    /* segue */
  }

  const notes: string[] = [];
  let hit = { http: 0, nome: '', nasc: '', json: null as unknown, note: '' };
  for (const p of providers) {
    if (hit.nome) break;
    const tryHit = await fetchApi(p.url, p.tok, p.mode, cpf);
    if (!failed(tryHit)) {
      hit = tryHit;
      break;
    }
    notes.push(`${tryHit.http} ${tryHit.note}`);
    hit = tryHit;
  }

  if (!hit.nome) {
    const out: CpfResult = { ok: false, code: 404, error: 'CPF nao encontrado' };
    if (diag && notes.length) out.error = notes.join(' | ');
    return out;
  }

  const out: CpfResult = {
    ok: true,
    nome: hit.nome,
    cpf,
    nascimento: normalizeDate(hit.nasc),
    genero: '',
  };
  try {
    await env.SR_TX.put(cacheKey, JSON.stringify(out), { expirationTtl: ttl });
  } catch {
    /* segue */
  }
  return out;
}