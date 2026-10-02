<?php
$pageTitle = 'Auditoria';
$activePage = 'audit-log.php';
$pageScripts = ['/admin/audit-log.js'];
$extraHead = <<<'HTML'
    <style>
        .audit-row td { font-size: 0.85rem; vertical-align: top; }
        .audit-action { font-family: 'Cascadia Code', 'Fira Code', 'JetBrains Mono', monospace; font-size: 0.8rem; }
        .audit-details { font-family: 'Cascadia Code', 'Fira Code', 'JetBrains Mono', monospace; font-size: 0.75rem; white-space: pre-wrap; word-break: break-all; color: var(--text-muted); }
        #audit-container { max-height: 70vh; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); }
    </style>
HTML;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<?php include __DIR__ . '/_partials/head.php'; ?>
</head>
<body class="gf-admin">
<div x-data="auditLogApp()">
<?php include __DIR__ . '/_partials/shell-start.php'; ?>

    <div class="gf-page-header">
        <div>
            <h1>Auditoria</h1>
            <p class="gf-subtitle">Histórico permanente de operações administrativas sensíveis.</p>
        </div>
        <div class="gf-page-actions">
            <button class="btn btn-outline-primary btn-sm" @click="refresh" :disabled="loading">
                <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i> Atualizar
            </button>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-auto">
            <label class="form-label small mb-0">Linhas:</label>
            <select x-model="limit" class="form-select form-select-sm" @change="refresh">
                <option value="50">50</option>
                <option value="100" selected>100</option>
                <option value="500">500</option>
            </select>
        </div>
        <div class="col-auto ms-auto d-flex align-items-end">
            <small class="text-muted" x-text="total ? total + ' entradas no total' : ''"></small>
        </div>
    </div>

    <div x-show="loading" class="text-center py-5">
        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
        <span class="ms-2 text-muted small">Carregando...</span>
    </div>

    <div x-show="!loading && entries.length === 0" class="text-center text-muted py-5">
        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
        <p>Nenhuma entrada de auditoria encontrada.</p>
    </div>

    <div id="audit-container" x-show="!loading && entries.length > 0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Quando</th>
                    <th>Quem</th>
                    <th>Ação</th>
                    <th>Item</th>
                    <th>Detalhes</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="entry in entries" :key="entry.id">
                    <tr class="audit-row">
                        <td x-text="formatWhen(entry.created_at)"></td>
                        <td x-text="entry.username || '(sistema)'"></td>
                        <td><span class="audit-action" x-text="entry.action"></span></td>
                        <td x-text="entry.entity_type ? (entry.entity_type + ' #' + entry.entity_id) : '—'"></td>
                        <td><span class="audit-details" x-text="JSON.stringify(entry.details)"></span></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

<?php include __DIR__ . '/_partials/shell-end.php'; ?>
</div>
</body>
</html>
