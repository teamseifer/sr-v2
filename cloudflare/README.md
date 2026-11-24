# Cloudflare Pages — sr-v2

Stack igual `consultacpf` / OFERTA DSB: pasta `cf/` com `public/` + **Pages Functions** (`/api_proxy.php` → gateway MangoFy).

| Ambiente | Projeto | URL produção |
|----------|---------|----------------|
| Produção | `sr-v2` | https://sr-v2-rah.pages.dev |
| Preview | `sr-v2-preview` | https://sr-v2-preview.pages.dev |
| Staging | `sr-v2-stg` | https://sr-v2-stg.pages.dev |

## Deploy

```bash
cd cf && npm install
npm run deploy:all
```

## Secrets (wrangler — nunca no git)

```bash
cd cf
wrangler pages secret put MANGOFY_API_KEY --project-name sr-v2
wrangler pages secret put MANGOFY_STORE_CODE --project-name sr-v2
wrangler pages secret put WEBHOOK_SECRET --project-name sr-v2
# opcional CPF:
wrangler pages secret put CPF_API_TOKEN --project-name sr-v2
wrangler pages secret put CPF_API2_TOKEN --project-name sr-v2
wrangler pages secret put CPF_API3_TOKEN --project-name sr-v2
```

Variáveis não-secret ficam em `cf/wrangler.toml` `[vars]`.

KV binding `SR_TX` (cache CPF + status) está no `wrangler.toml`.

## Diagnóstico

```bash
curl -s "https://sr-v2-rah.pages.dev/api_proxy.php?action=ping"
```

`mangofy_key_configurada: true` e `kv: true` = API e KV ok.