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
