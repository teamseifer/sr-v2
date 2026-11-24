import { cpSync, mkdirSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const root = join(import.meta.dirname, '..');
const dest = join(import.meta.dirname, 'public');

const skip = new Set([
  '.git',
  '.github',
  '.private',
  '.wrangler',
  'cf',
  'cloudflare',
  'node_modules',
  '.DS_Store',
  'secrets-cpf',
  'secrets-mangofy-sr',
  'secrets-mangofy',
]);

rmSync(dest, { recursive: true, force: true });
mkdirSync(dest, { recursive: true });

for (const name of readdirSync(root)) {
  if (skip.has(name)) continue;
  if (name.endsWith('.php')) continue;
  if (name.endsWith('.sqlite') || name.includes('.sqlite-')) continue;
  if (name === '.env' || name.startsWith('.env.')) continue;
  const src = join(root, name);
  const target = join(dest, name);
  const isDir = statSync(src).isDirectory();
  cpSync(src, target, {
    recursive: isDir,
    filter(path) {
      const base = path.split('/').pop() || '';
      if (base.endsWith('.php')) return false;
      if (base === '.DS_Store') return false;
      if (base === '.env' || base.startsWith('.env.')) return false;
      if (path.includes('/secrets-')) return false;
      return true;
    },
  });
}

writeFileSync(join(dest, '_redirects'), '/api_proxy.php /api/gateway 200\n');
console.log('public sincronizado');