<?php
$pageTitle = 'Logs';
$activePage = 'logs.php';
$pageScripts = ['/admin/logs.js'];
$extraHead = <<<'HTML'
    <style>
        .log-line { font-family: 'Cascadia Code', 'Fira Code', 'JetBrains Mono', monospace; font-size: 0.75rem; line-height: 1.5; white-space: pre-wrap; word-break: break-all; padding: 0.1rem 0.5rem; border-bottom: 1px solid var(--border); }
        .log-line:hover { background: rgba(255,255,255,0.03); }
        .log-line .log-level { display: inline-block; width: 5.5rem; font-weight: 700; text-transform: uppercase; font-size: 0.65rem; text-align: center; border-radius: 3px; padding: 0 0.3rem; }
        .log-level.ERROR { color: #fff; background: var(--danger); }
        .log-level.WARNING { color: #2d3436; background: var(--warning); }
        .log-level.INFO { color: #fff; background: var(--info); }
        .log-level.DEBUG { color: var(--text-muted); background: var(--border); }
        .log-time { color: var(--text-muted); margin-right: 0.75rem; }
        #log-container { max-height: 70vh; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); }
    </style>
HTML;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<?php include __DIR__ . '/_partials/head.php'; ?>
</head>
<body class="gf-admin">
<div x-data="logsApp()">
<?php include __DIR__ . '/_partials/shell-start.php'; ?>

    <div class="gf-page-header">
        <div>
            <h1>Logs</h1>
            <p class="gf-subtitle">Log técnico da aplicação (app.log).</p>
        </div>
        <div class="gf-page-actions">
            <button class="btn btn-outline-primary btn-sm" @click="refresh" :disabled="loading">
                <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i> Atualizar
            </button>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-auto">
            <label class="form-label small mb-0">Filtrar:</label>
            <select x-model="filterLevel" class="form-select form-select-sm" @change="refresh">
                <option value="">Todos os níveis</option>
                <option value="ERROR">ERROR</option>
                <option value="WARNING">WARNING</option>
                <option value="INFO">INFO</option>
                <option value="DEBUG">DEBUG</option>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-0">Linhas:</label>
            <select x-model="lineCount" class="form-select form-select-sm" @change="refresh">
                <option value="100">100</option>
                <option value="200" selected>200</option>
                <option value="500">500</option>
                <option value="1000">1000</option>
            </select>
        </div>
        <div class="col-auto ms-auto d-flex align-items-end">
            <small class="text-muted" x-text="totalLines ? totalLines + ' linhas no arquivo' : ''"></small>
        </div>
    </div>

    <div x-show="loading" class="text-center py-5">
        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
        <span class="ms-2 text-muted small">Carregando logs...</span>
    </div>

    <div x-show="!loading && filteredLines.length === 0" class="text-center text-muted py-5">
        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
        <p>Nenhuma linha de log encontrada.</p>
    </div>

    <div id="log-container" x-show="!loading && filteredLines.length > 0">
        <template x-for="(line, idx) in filteredLines" :key="idx">
            <div class="log-line d-flex align-items-start">
                <span class="log-time flex-shrink-0" x-text="extractTime(line)"></span>
                <span class="log-level flex-shrink-0 me-2" :class="extractLevel(line)" x-text="extractLevel(line)"></span>
                <span class="flex-grow-1" x-text="extractMessage(line)"></span>
            </div>
        </template>
    </div>

<?php include __DIR__ . '/_partials/shell-end.php'; ?>
</div>
</body>
</html>
