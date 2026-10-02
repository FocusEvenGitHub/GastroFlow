<?php
/**
 * Abre o layout do Admin (spec 051): barra superior + menu lateral + <main>.
 * Incluído DENTRO do elemento x-data da página, que precisa ser montado com
 * GFAdmin.page({...}) (public/admin/auth.js) — o shell usa username, darkMode,
 * canSeeAdminOnly, forbidden, logout() e toggleDarkMode() dele.
 *
 * Variáveis definidas pela própria página:
 * - $activePage string  arquivo da página atual (ex.: 'reports.php')
 * - $gate       bool    true só no index: o shell só aparece depois do login
 */
$adminPages = [
    ['file' => 'index.php',       'label' => 'Cardápio',      'icon' => 'fa-utensils',    'adminOnly' => false],
    ['file' => 'reports.php',     'label' => 'Relatórios',    'icon' => 'fa-chart-bar',   'adminOnly' => false],
    ['file' => 'ingredients.php', 'label' => 'Ingredientes',  'icon' => 'fa-carrot',      'adminOnly' => false],
    ['file' => 'settings.php',    'label' => 'Configurações', 'icon' => 'fa-sliders-h',   'adminOnly' => true],
    ['file' => 'logs.php',        'label' => 'Logs',          'icon' => 'fa-terminal',    'adminOnly' => true],
    ['file' => 'audit-log.php',   'label' => 'Auditoria',     'icon' => 'fa-shield-alt',  'adminOnly' => true],
];
$gate = $gate ?? false;

$renderLink = static function (array $page) use ($activePage): string {
    $isActive = $page['file'] === $activePage;
    $href = $page['file'] === 'index.php' ? '/admin/' : '/admin/' . $page['file'];
    return sprintf(
        '<a href="%s" class="gf-side-link%s"%s><i class="fas %s fa-fw"></i><span>%s</span></a>',
        htmlspecialchars($href, ENT_QUOTES),
        $isActive ? ' active' : '',
        $isActive ? ' aria-current="page"' : '',
        htmlspecialchars($page['icon'], ENT_QUOTES),
        htmlspecialchars($page['label'], ENT_QUOTES)
    );
};
?>
<?php if ($gate) : ?>
<div x-show="loggedIn" x-cloak>
<?php endif; ?>
<nav class="gastro-nav gf-topbar">
    <button class="gf-menu-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#gfSidebar"
            aria-controls="gfSidebar" aria-label="Abrir menu do Admin">
        <i class="fas fa-bars"></i>
    </button>
    <a href="/cashier/" class="gastro-nav-brand">
        <i class="fas fa-utensils"></i>
        <span>GastroFlow</span>
    </a>
    <div class="gastro-nav-links d-none d-md-flex">
        <a href="/cashier/"><i class="fas fa-cash-register"></i>Caixa</a>
        <a href="/kitchen/"><i class="fas fa-fire"></i>Cozinha</a>
        <a href="/admin/" class="active"><i class="fas fa-cog"></i>Admin</a>
    </div>
    <div class="gf-topbar-user">
        <span class="gf-user d-none d-sm-inline" x-show="username"><i class="fas fa-user-circle"></i> <span x-text="username"></span></span>
        <button class="dark-toggle" @click="toggleDarkMode()" title="Alternar tema" aria-label="Alternar tema">
            <i class="fas" :class="darkMode ? 'fa-sun' : 'fa-moon'"></i>
        </button>
        <button class="gf-logout" @click="logout()" title="Sair">
            <i class="fas fa-sign-out-alt"></i><span class="d-none d-sm-inline"> Sair</span>
        </button>
    </div>
</nav>

<div class="gf-layout">
    <aside class="gf-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="gfSidebar" aria-labelledby="gfSidebarTitle">
        <div class="offcanvas-header d-lg-none">
            <h2 class="offcanvas-title h6 mb-0" id="gfSidebarTitle">Admin</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#gfSidebar" aria-label="Fechar"></button>
        </div>
        <nav class="gf-side-nav" aria-label="Admin">
            <div class="gf-side-section">Gestão</div>
<?php foreach ($adminPages as $page) : ?>
<?php if (!$page['adminOnly']) : ?>
            <?= $renderLink($page) ?>

<?php endif; ?>
<?php endforeach; ?>
            <template x-if="canSeeAdminOnly">
                <div>
                    <div class="gf-side-section">Sistema</div>
<?php foreach ($adminPages as $page) : ?>
<?php if ($page['adminOnly']) : ?>
                    <?= $renderLink($page) ?>

<?php endif; ?>
<?php endforeach; ?>
                </div>
            </template>
            <div class="gf-side-section d-md-none">Telas</div>
            <a href="/cashier/" class="gf-side-link d-md-none"><i class="fas fa-cash-register fa-fw"></i><span>Caixa</span></a>
            <a href="/kitchen/" class="gf-side-link d-md-none"><i class="fas fa-fire fa-fw"></i><span>Cozinha</span></a>
        </nav>
    </aside>

    <main class="gf-main">
        <div x-show="forbidden" x-cloak class="gf-forbidden">
            <i class="fas fa-lock"></i>
            <h1 class="h4">Sem permissão para esta área.</h1>
            <p class="text-muted mb-3">Seu usuário não tem acesso a esta página. Fale com um administrador.</p>
            <a href="/admin/" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left me-1"></i> Voltar ao Cardápio</a>
        </div>
        <div x-show="!forbidden">
