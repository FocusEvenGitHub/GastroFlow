<?php

declare(strict_types=1);

/**
 * Roteador para o servidor embutido do PHP (`php -S`), usado só pelos testes de navegador
 * no CI (spec 040).
 *
 * Por que existe: o `php -S` NÃO lê o `public/.htaccess`, e é o .htaccess que manda os
 * caminhos inexistentes para o front controller. Sem este roteador, `/api/menu` devolve 404
 * porque não existe arquivo com esse nome — foi exatamente assim que a primeira execução do
 * job de navegador falhou.
 *
 * Reproduz a regra do .htaccess: se o arquivo existe, sirva-o; senão, front controller.
 */

$root = dirname(__DIR__, 2) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = $root . $path;

/**
 * O stream SSE é recusado de propósito neste servidor de teste.
 *
 * Cada carga da tela da cozinha abre uma conexão de longa duração em
 * /api/events/stream.php, e cada uma ocupa um worker do `php -S` até o cliente sumir. Com
 * cinco testes abrindo a tela, os workers acabam e TODAS as requisições seguintes passam a
 * dar timeout — foi exatamente o que aconteceu no CI (15s em apiRequestContext.get).
 *
 * Os testes de printer-block.spec.ts (spec 040) não exercitam SSE: o estado da impressora
 * chega por polling. Os de realtime-events.spec.ts (spec 041) exercitam SSE de propósito, mas
 * se pulam sozinhos quando E2E_PHP_DIRECT=1 é o sinal de que é este servidor — exatamente por
 * causa desta limitação. Devolver 204 mantém qualquer EventSource remanescente inofensivo (ele
 * apenas tenta reconectar) sem prender worker. O Apache de produção não tem essa limitação,
 * então nada disso vale fora daqui.
 */
if (str_starts_with($path, '/api/events/')) {
    http_response_code(204);
    return true;
}

// Arquivo real (css, js, imagem, .php de tela): o servidor embutido serve sozinho.
if ($path !== '/' && is_file($file)) {
    return false;
}

// Diretório com index.php próprio — é o caso de /cashier/, /kitchen/ e /admin/.
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    require rtrim($file, '/') . '/index.php';
    return true;
}

require $root . '/index.php';
return true;
