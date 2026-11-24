import "jsr:@supabase/functions-js/edge-runtime.d.ts";

const BUCKET = "comprovantes";

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

async function sha256Hex(text: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

function same(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let out = 0;
  for (let i = 0; i < a.length; i++) out |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return out === 0;
}

function serviceHeaders(key: string): HeadersInit {
  return {
    apikey: key,
    authorization: `Bearer ${key}`,
    "content-type": "application/json",
  };
}

Deno.serve(async (req) => {
  if (req.method !== "POST") return json({ error: "Metodo nao permitido" }, 405);

  const supabaseUrl = (Deno.env.get("SUPABASE_URL") || "").replace(/\/+$/, "");
  const serviceKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") || "";
  if (supabaseUrl === "" || serviceKey === "") return json({ error: "Funcao sem credencial" }, 500);

  const token = req.headers.get("x-upload-token") || "";
  const hash = await sha256Hex(token);
  const cfgRes = await fetch(
    `${supabaseUrl}/rest/v1/internal_config?key=eq.upload_token_sha256&select=value`,
    { headers: serviceHeaders(serviceKey) }
  );
  const cfg = await cfgRes.json();
  const expected = Array.isArray(cfg) && cfg[0] && typeof cfg[0].value === "string" ? cfg[0].value : "";
  if (expected === "" || !same(hash, expected)) return json({ error: "Nao autorizado" }, 401);

  const body = (await req.json()) as Record<string, unknown>;
  const action = String(body.action || "");
  const path = String(body.path || "").replace(/^\/+/, "");

  if (action === "prepare") {
    if (path === "" || path.includes("..")) return json({ error: "Caminho invalido" }, 400);
    const sign = await fetch(`${supabaseUrl}/storage/v1/object/upload/sign/${BUCKET}/${path}`, {
      method: "POST",
      headers: serviceHeaders(serviceKey),
      body: JSON.stringify({ upsert: false }),
    });
    const signed = (await sign.json()) as { url?: string; token?: string; message?: string };
    if (!sign.ok || !signed.url) {
      return json({ error: signed.message || "Falha ao preparar o upload" }, 502);
    }
    const signedUrl = signed.url.startsWith("http") ? signed.url : `${supabaseUrl}/storage/v1${signed.url}`;
    return json({ path, signedUrl });
  }

  if (action === "commit") {
    if (path === "" || path.includes("..")) return json({ error: "Caminho invalido" }, 400);
    const row = {
      transaction_id: String(body.transaction_id || "").slice(0, 120),
      storage_path: path,
      content_type: String(body.content_type || "").slice(0, 80),
      filename: String(body.filename || "").slice(0, 180),
      customer_name: String(body.customer_name || "").slice(0, 180),
      customer_cpf: String(body.customer_cpf || "").replace(/\D/g, "").slice(0, 14),
      customer_email: String(body.customer_email || "").slice(0, 180),
      size_bytes: Number(body.size_bytes) || 0,
    };
    const ins = await fetch(`${supabaseUrl}/rest/v1/comprovantes`, {
      method: "POST",
      headers: { ...serviceHeaders(serviceKey), prefer: "return=representation" },
      body: JSON.stringify(row),
    });
    const created = await ins.json();
    if (!ins.ok) {
      const message = created && typeof created === "object" && "message" in created ? String(created.message) : "Falha ao registrar";
      return json({ error: message }, 502);
    }
    const id = Array.isArray(created) && created[0] ? created[0].id : null;
    return json({ ok: true, id });
  }

  return json({ error: "Acao invalida" }, 400);
});
