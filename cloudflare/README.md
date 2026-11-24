# Cloudflare Pages (sr-v2)

Projetos criados:

| Ambiente | Projeto | URL `*.pages.dev` |
|----------|---------|-------------------|
| Produção | `sr-v2` | https://sr-v2-rah.pages.dev |
| Preview | `sr-v2-preview` | https://sr-v2-preview.pages.dev |
| Staging | `sr-v2-stg` | https://sr-v2-stg.pages.dev |

## Secrets (Pages)

Em cada projeto (Production + Preview):

- `GATEWAY_PROVIDER=mangofy`
- `MANGOFY_API_KEY` (secret)
- `MANGOFY_STORE_CODE` (secret)
- `MANGOFY_BASE_URL=https://checkout.mangofy.com.br`

> O funil usa **PHP** (`api_proxy.php`). No Pages entram sobretudo HTML/CSS/JS.
> Para PIX/MangoFy em produção, rode `api_proxy.php` em hospedagem PHP (aaPanel etc.)
> com `secrets-mangofy-sr/.env` ou as mesmas variáveis no servidor.

## Deploy estático (opcional)

```bash
npx wrangler pages deploy . --project-name sr-v2 --commit-dirty=true
```

Conecte o GitHub `teamseifer/sr-v2` no dashboard do Cloudflare Pages para deploy automático.