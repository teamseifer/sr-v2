#!/usr/bin/env node
/**
 * Servidor local sem PHP: arquivos estáticos + api_proxy.php?action=consultar_cpf
 * Uso: node dev-server.mjs   (porta 8765)
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const PORT = Number(process.env.PORT) || 8765;

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.mp3': 'audio/mpeg',
  '.mp4': 'video/mp4',
  '.ico': 'image/x-icon',
  '.php': 'application/json; charset=utf-8',
};

function loadEnvFile(filePath) {
  try {
    if (!fs.existsSync(filePath)) return;
    const text = fs.readFileSync(filePath, 'utf8');
    for (const line of text.split('\n')) {
      const t = line.trim();
      if (!t || t.startsWith('#')) continue;
      const eq = t.indexOf('=');
      if (eq < 1) continue;
      const k = t.slice(0, eq).trim();
      let v = t.slice(eq + 1).trim();
      if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
      if (!process.env[k]) process.env[k] = v;
    }
  } catch (_) { /* ignore */ }
}

loadEnvFile(path.join(ROOT, '.private', '.env'));
loadEnvFile(path.join(ROOT, 'secrets-cpf', '.env'));

/** Mock so com CPF_DEV_MOCK=1 (testar funil sem chaves). Padrao: APIs reais ou erro claro. */
const CPF_DEV_MOCK = (() => {
  const v = String(process.env.CPF_DEV_MOCK ?? '0').trim();
  return v === '1';
})();

function cpfDevMockResponse(cpf) {
  return {
    ok: true,
    nome: 'NOME MOCK (DEV)',
    cpf,
    cpfformatado: cpfFormat(cpf),
    nascimento: '01/01/1990',
    genero: '',
    _source: 'dev_mock',
    _dev: 'node-dev-server',
    _mock: true,
  };
}

function envGet(key, fallback) {
  const v = String(process.env[key] ?? '').trim();
  return v && !v.startsWith('COLOQUE_') ? v : fallback;
}

function cpfDigits(v) {
  return String(v).replace(/\D/g, '');
}

function cpfIsValid(cpf) {
  cpf = cpfDigits(cpf);
  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
  for (let t = 9; t < 11; t++) {
    let d = 0;
    for (let c = 0; c < t; c++) d += Number(cpf[c]) * (t + 1 - c);
    d = (10 * d) % 11 % 10;
    if (Number(cpf[t]) !== d) return false;
  }
  return true;
}

function cpfFormat(cpf) {
  cpf = cpfDigits(cpf);
  return `${cpf.slice(0, 3)}.${cpf.slice(3, 6)}.${cpf.slice(6, 9)}-${cpf.slice(9, 11)}`;
}

function cpfNormalizeDate(s) {
  s = String(s ?? '').trim();
  if (!s) return '';
  const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (iso) return `${iso[3]}/${iso[2]}/${iso[1]}`;
  const br = s.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
  if (br) return br[0];
  return s;
}

function cpfPick(arr, keys, depth = 0) {
  if (!arr || typeof arr !== 'object' || depth > 4) return '';
  const want = new Set(keys.map((k) => k.toLowerCase()));
  for (const [k, v] of Object.entries(arr)) {
    if (!Array.isArray(v) && typeof v !== 'object' && want.has(String(k).toLowerCase())) {
      const t = String(v).trim();
      if (t) return t;
    }
  }
  for (const v of Object.values(arr)) {
    if (v && typeof v === 'object') {
      const r = cpfPick(v, keys, depth + 1);
      if (r) return r;
    }
  }
  return '';
}

async function cpfFetchFromApi(url, token, mode, cpf) {
  url = String(url).replace(/\/$/, '');
  mode = String(mode || 'path').toLowerCase();
  const headers = { Accept: 'application/json', 'User-Agent': 'funil-sr-dev-cpf/1.0' };
  let full = url;

  if (mode === 'query') {
    full = `${url}${url.includes('?') ? '&' : '?'}token=${encodeURIComponent(token)}&cpf=${cpf}`;
  } else if (mode === 'xapikey') {
    full = `${url}${url.includes('?') ? '&' : '?'}cpf=${cpf}`;
    headers['X-API-KEY'] = token;
  } else if (mode === 'xapikey_path') {
    full = `${url}/${cpf}`;
    headers['X-API-KEY'] = token;
  } else {
    full = `${url}/${encodeURIComponent(token)}/${cpf}`;
  }

  try {
    const res = await fetch(full, { headers, signal: AbortSignal.timeout(20_000) });
    const http = res.status;
    let json = null;
    try {
      json = await res.json();
    } catch {
      json = null;
    }
    const out = { http, errc: '', json, nome: '', nasc: '', sexo: '' };
    if (json) {
      out.nome = cpfPick(json, ['nome', 'name', 'nome_completo', 'full_name']);
      out.nasc = cpfPick(json, ['nasc', 'nascimento', 'data_nascimento', 'birthdate', 'birth_date', 'dt_nascimento']);
      out.sexo = cpfPick(json, ['sexo', 'genero', 'gender']);
    }
    cpfEnrichFetchFields(out);
    return out;
  } catch (e) {
    return { http: 0, errc: e?.message || 'fetch failed', json: null, nome: '', nasc: '', sexo: '' };
  }
}

function cpfEnrichFetchFields(r) {
  if (!r.json || typeof r.json !== 'object') return;
  const blocks = [];
  if (r.json.data && typeof r.json.data === 'object') blocks.push(r.json.data);
  if (r.json.dados && typeof r.json.dados === 'object') blocks.push(r.json.dados);
  for (const b of blocks) {
    if (!r.nome) r.nome = cpfPick(b, ['nome', 'name', 'NOME', 'nameUpper', 'nome_completo']);
    if (!r.nasc) r.nasc = cpfPick(b, ['nasc', 'nascimento', 'birthDate', 'data_nascimento', 'dt_nascimento']);
    if (!r.sexo) r.sexo = cpfPick(b, ['sexo', 'genero', 'gender']);
  }
}

function cpfApiErrorMessage(r) {
  if (!r) return '';
  if (r.errc) return r.errc;
  if (!r.json) return '';
  const j = r.json;
  if (j.message && String(j.message).trim()) return String(j.message).trim();
  if (j.erro && String(j.erro).trim()) return String(j.erro).trim();
  if (j.error) {
    if (typeof j.error === 'string' && j.error.trim()) return j.error.trim();
    if (j.error.message) return String(j.error.message).trim();
  }
  return '';
}

function cpfResponseUsable(r) {
  return r && r.errc === '' && r.json && r.http !== 404 && r.nome !== '';
}

async function consultarCpfExterno(cpfIn) {
  const cpf = cpfDigits(cpfIn);
  if (!cpfIsValid(cpf)) {
    return { ok: false, code: 400, error: 'CPF invalido' };
  }

  const providers = [
    {
      url: envGet('CPF_API_URL', 'https://api.apicpf.dev/consulta'),
      token: envGet('CPF_API_TOKEN', envGet('CPF_API_KEY', '')),
      mode: envGet('CPF_API_MODE', 'xapikey'),
      src: 'apicpf_primary',
      err: 'cpf_api',
    },
    {
      url: envGet('CPF_API2_URL', 'https://api.apicpf.dev/consulta'),
      token: envGet('CPF_API2_TOKEN', envGet('CPF_API2_KEY', '')),
      mode: envGet('CPF_API2_MODE', 'xapikey'),
      src: 'apicpf_fallback',
      err: 'cpf_api2',
    },
    {
      url: envGet('CPF_API3_URL', 'https://api.zapgroup.shop/consultar-filtrada/cpf'),
      token: envGet('CPF_API3_TOKEN', envGet('CPF_API3_KEY', '')),
      mode: envGet('CPF_API3_MODE', 'query'),
      src: 'zapgroup',
      err: 'cpf_api3',
    },
  ];

  const anyConfigured = providers.some((p) => p.url && p.token);
  if (!anyConfigured) {
    if (CPF_DEV_MOCK) return cpfDevMockResponse(cpf);
    return {
      ok: false,
      code: 503,
      error: 'API de CPF nao configurada. Crie secrets-cpf/.env (veja secrets-cpf/.env.example) com CPF_API_TOKEN e/ou CPF_API2_TOKEN e/ou CPF_API3_TOKEN.',
    };
  }

  let r = { http: 0, errc: '', json: null, nome: '', nasc: '', sexo: '' };
  let src = '';
  const providerErrors = [];

  for (const p of providers) {
    if (!p.url || !p.token) continue;
    const tryR = await cpfFetchFromApi(p.url, p.token, p.mode, cpf);
    if (cpfResponseUsable(tryR)) {
      r = tryR;
      src = p.src;
      break;
    }
    const e = cpfApiErrorMessage(tryR);
    if (e) providerErrors.push(`${p.err}: ${e}`);
    r = tryR;
  }

  if (!cpfResponseUsable(r) && CPF_DEV_MOCK) {
    return cpfDevMockResponse(cpf);
  }

  if (r.errc && !r.json) {
    return { ok: false, code: 502, error: 'Falha ao consultar a API de CPF.', detail: r.errc, providers: providerErrors };
  }
  if (!r.json) {
    return { ok: false, code: 502, error: 'Resposta invalida da API de CPF.', http: r.http, providers: providerErrors };
  }
  if (r.http === 404 || !r.nome) {
    let err = 'CPF nao encontrado';
    if (providerErrors.length) {
      err = `Nao foi possivel consultar o CPF. ${[...new Set(providerErrors)].join(' · ')}`;
    }
    return { ok: false, code: 404, error: err, http: r.http, providers: providerErrors };
  }

  return {
    ok: true,
    nome: r.nome,
    cpf,
    cpfformatado: cpfFormat(cpf),
    nascimento: cpfNormalizeDate(r.nasc),
    genero: r.sexo,
    _source: src || 'desconhecida',
    _dev: 'node-dev-server',
  };
}

function sendJson(res, status, body) {
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
  });
  res.end(JSON.stringify(body));
}

function serveStatic(req, res, urlPath) {
  let rel = decodeURIComponent(urlPath.split('?')[0]);
  if (rel === '/' || rel === '') rel = '/index.html';
  const filePath = path.normalize(path.join(ROOT, rel.replace(/^\//, '')));
  if (!filePath.startsWith(ROOT)) {
    res.writeHead(403).end('Forbidden');
    return;
  }
  if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
    const tryIndex = path.join(filePath, 'index.html');
    if (fs.existsSync(tryIndex)) return serveFile(res, tryIndex);
    res.writeHead(404).end('Not found');
    return;
  }
  serveFile(res, filePath);
}

function serveFile(res, filePath) {
  const ext = path.extname(filePath).toLowerCase();
  const type = MIME[ext] || 'application/octet-stream';
  res.writeHead(200, { 'Content-Type': type });
  fs.createReadStream(filePath).pipe(res);
}

const server = http.createServer(async (req, res) => {
  const u = new URL(req.url || '/', `http://127.0.0.1:${PORT}`);
  if (u.pathname === '/api_proxy.php' && u.searchParams.get('action') === 'consultar_cpf') {
    const cpf = u.searchParams.get('cpf') || '';
    const out = await consultarCpfExterno(cpf);
    sendJson(res, 200, out);
    return;
  }
  if (u.pathname === '/api_proxy.php') {
    sendJson(res, 501, {
      ok: false,
      error: 'acao nao suportada no dev-server. Use PHP em producao ou action=consultar_cpf.',
      action: u.searchParams.get('action'),
    });
    return;
  }
  serveStatic(req, res, u.pathname);
});

server.listen(PORT, () => {
  console.log(`SER dev: http://127.0.0.1:${PORT}/  (CPF via api_proxy.php?action=consultar_cpf)`);
  if (CPF_DEV_MOCK) console.log('CPF_DEV_MOCK=1: se as APIs falharem, retorna nome mock para testar o funil.');
  else console.log('CPF_DEV_MOCK=0: apenas APIs reais (configure secrets-cpf/.env).');
});