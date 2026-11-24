/**
 * POST /api/comprovantes-upload
 * Grava o arquivo no bucket privado do Supabase e uma linha na tabela comprovantes.
 */

import type { Env } from '../lib/env';
import { digits, json, preflight, readJson, str } from '../lib/http';

const MAX_BYTES = 8 * 1024 * 1024;
const EXT: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'application/pdf': 'pdf',
};

function decodeBase64(b64: string): Uint8Array {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function fail(error: string, status: number): Response {
  return json({ success: false, error }, status);
}

export const onRequestPost: PagesFunction<Env> = async (ctx) => {
  const pf = preflight(ctx.request);
  if (pf) return pf;

  const env = ctx.env;
  const base = (env.SUPABASE_URL || '').trim().replace(/\/+$/, '');
  const token = (env.SUPABASE_UPLOAD_TOKEN || '').trim();
  if (base === '' || token === '') return fail('Armazenamento de comprovantes nao configurado', 503);

  const b = await readJson(ctx.request);
  const data = str(b.arquivo);
  const match = data.match(/^data:([a-z0-9.+\/-]+);base64,(.+)$/is);
  if (!match) return fail('Arquivo invalido', 400);

  const mime = match[1].toLowerCase();
  const ext = EXT[mime];
  if (!ext) return fail('Envie uma imagem (jpg, png, webp) ou PDF', 400);

  let bytes: Uint8Array;
  try {
    bytes = decodeBase64(match[2]);
  } catch {
    return fail('Arquivo invalido', 400);
  }
  if (bytes.byteLength === 0 || bytes.byteLength > MAX_BYTES) return fail('Arquivo maior que 8MB', 400);

  const tx = str(b.transaction_id).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 80) || 'semtx';
  const now = new Date();
  const y = now.getUTCFullYear();
  const mo = String(now.getUTCMonth() + 1).padStart(2, '0');
  const da = String(now.getUTCDate()).padStart(2, '0');
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mi = String(now.getUTCMinutes()).padStart(2, '0');
  const ss = String(now.getUTCSeconds()).padStart(2, '0');
  const rand = crypto.randomUUID().slice(0, 8);
  const path = `${y}/${mo}/${da}/${tx}/${hh}${mi}${ss}-${rand}.${ext}`;

  const meta = {
    path,
    content_type: mime,
    transaction_id: tx,
    filename: str(b.filename).slice(0, 180),
    customer_name: str(b.customer_name).slice(0, 180),
    customer_cpf: digits(b.customer_cpf).slice(0, 14),
    customer_email: str(b.customer_email).slice(0, 180),
    size_bytes: bytes.byteLength,
  };

  const call = (action: string) =>
    fetch(`${base}/functions/v1/comprovantes-upload`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-upload-token': token },
      body: JSON.stringify({ action, ...meta }),
    });

  const prepared = await call('prepare');
  const prepBody = (await prepared.json()) as { signedUrl?: string; error?: string };
  if (!prepared.ok || !prepBody.signedUrl) {
    return fail(prepBody.error || 'Falha ao preparar o armazenamento', 502);
  }

  const uploaded = await fetch(prepBody.signedUrl, {
    method: 'PUT',
    headers: { 'content-type': mime, 'x-upsert': 'false' },
    body: bytes,
  });
  if (!uploaded.ok) return fail('Falha ao gravar o comprovante', 502);

  const committed = await call('commit');
  const commitBody = (await committed.json()) as { ok?: boolean; error?: string };
  if (!committed.ok || !commitBody.ok) {
    return fail(commitBody.error || 'Arquivo gravado, mas o registro falhou', 502);
  }

  return json({ success: true }, 200);
};

export const onRequestGet: PagesFunction<Env> = async (ctx) => {
  const pf = preflight(ctx.request);
  if (pf) return pf;
  return fail('Metodo nao permitido', 405);
};
