<?php
/**
 * <head> compartilhado das páginas do Admin (spec 051).
 *
 * Variáveis definidas pela própria página (nunca vindas do request):
 * - $pageTitle   string    título da aba ("Admin – $pageTitle")
 * - $pageScripts string[]  scripts da página, carregados com defer antes do Alpine
 * - $extraHead   string    HTML extra opcional (ex.: <style> da página)
 *
 * Todos os scripts usam defer e o Alpine vem por último: ele inicia assim que é
 * executado, então as funções x-data das páginas precisam já estar definidas.
 */
$pageScripts = $pageScripts ?? [];

// Arquivos locais ganham ?v=<mtime>: sem isso um navegador com o app.js antigo
// em cache roda o JS velho contra o HTML novo depois de um deploy.
$asset = static function (string $src): string {
    if (!str_starts_with($src, '/')) {
        return $src;
    }
    $mtime = @filemtime(dirname(__DIR__, 2) . $src);
    return $mtime ? $src . '?v=' . $mtime : $src;
};
?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – <?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($asset('/assets/css/admin.css'), ENT_QUOTES) ?>">
    <?= $extraHead ?? '' ?>
    <script>
        try {
            if (localStorage.getItem('gastroflow_darkMode') === 'true') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (_) {}
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="<?= htmlspecialchars($asset('/admin/auth.js'), ENT_QUOTES) ?>"></script>
<?php foreach ($pageScripts as $src) : ?>
    <script defer src="<?= htmlspecialchars($asset($src), ENT_QUOTES) ?>"></script>
<?php endforeach; ?>
    <script defer src="/assets/js/version-badge.js"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
