<?php
/**
 * =============================================================================
 * env_loader.php - Carregador simples de variaveis de ambiente (.env)
 * =============================================================================
 *
 * Le um arquivo .env (formato CHAVE=valor) e injeta as variaveis no ambiente
 * do PHP via putenv()/$_ENV/$_SERVER, SEM sobrescrever variaveis ja definidas
 * pelo servidor (assim, em hospedagens que ja oferecem variaveis de ambiente
 * reais, elas tem prioridade sobre o arquivo .env).
 *
 * Seguranca:
 *  - O arquivo .env fica em .private/ (bloqueado por .htaccess + .gitignore).
 *  - Nenhuma credencial fica hardcoded no codigo versionado.
 *
 * Uso (no topo do api_proxy.php):
 *   require_once __DIR__ . '/.private/env_loader.php';
 *   load_dotenv(__DIR__ . '/.private/.env');
 *   $token = env_get('UTMIFY_API_TOKEN', '');
 * =============================================================================
 */

if (!function_exists('load_dotenv')) {
    /**
     * Carrega um arquivo .env para o ambiente. Idempotente e silencioso.
     *
     * @param string $path Caminho absoluto do arquivo .env.
     * @return bool true se carregou algo, false se o arquivo nao existe/ilegivel.
     */
    function load_dotenv($path, $allowedKeys = null) {
        if (!is_string($path) || $path === '' || !@is_readable($path)) {
            return false;
        }

        // Whitelist opcional de chaves: quando informada, SOMENTE as chaves
        // listadas sao importadas deste arquivo. Isso permite pastas de
        // ambiente ESCOPADAS (ex.: secrets-disrupty so fornece credenciais do
        // gateway; utmify-<oferta> so fornece o token UTMify; mail.rec-<oferta>
        // so fornece SMTP/recuperacao) — mesmo que alguem coloque um .env
        // completo dentro delas, as chaves fora do escopo sao IGNORADAS,
        // eliminando contaminacao entre ofertas no mesmo servidor.
        $filter = null;
        if (is_array($allowedKeys) && count($allowedKeys) > 0) {
            $filter = array();
            foreach ($allowedKeys as $ak) { $filter[strtoupper(trim((string)$ak))] = true; }
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignora linhas vazias e comentarios (# ou ;)
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            // Suporta o prefixo opcional "export "
            if (stripos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }

            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $val = trim(substr($line, $eqPos + 1));

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            // Fora do escopo desta pasta de ambiente? Ignora.
            if ($filter !== null && !isset($filter[strtoupper($key)])) {
                continue;
            }

            // Remove aspas envolventes (simples ou duplas)
            $len = strlen($val);
            if ($len >= 2) {
                $first = $val[0];
                $last  = $val[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, -1);
                    // Em aspas duplas, interpreta \n e \t basicos
                    if ($first === '"') {
                        $val = str_replace(array('\\n', '\\t', '\\r'), array("\n", "\t", "\r"), $val);
                    }
                }
            }

            // NAO sobrescreve variaveis ja existentes no ambiente real do servidor.
            $existing = function_exists('getenv') ? @getenv($key) : false;
            if ($existing === false && isset($_ENV[$key]) && $_ENV[$key] !== '') {
                $existing = $_ENV[$key];
            }
            if ($existing !== false && $existing !== '') {
                continue;
            }

            // putenv() pode estar desabilitado ou removido em servidores com
            // restricoes de seguranca. Se nao existir, usamos apenas $_ENV/$_SERVER.
            if (function_exists('putenv')) {
                @putenv($key . '=' . $val);
            }
            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
        }

        return true;
    }
}

if (!function_exists('env_get')) {
    /**
     * Le uma variavel de ambiente, com valor padrao opcional.
     * Trata strings vazias e os literais "null"/"false"/"true".
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    function env_get($key, $default = null) {
        $val = function_exists('getenv') ? @getenv($key) : false;
        if ($val === false || $val === '') {
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                $val = $_ENV[$key];
            } elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                $val = $_SERVER[$key];
            } else {
                return $default;
            }
        }

        $trimmed = trim($val);
        switch (strtolower($trimmed)) {
            case 'null':  return $default;
            case 'true':  return true;
            case 'false': return false;
        }
        return $val;
    }
}
