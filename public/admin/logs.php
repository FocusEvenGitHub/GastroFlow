<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
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
    <script>
        if (localStorage.getItem('gastroflow_darkMode') === 'true') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
</head>
<body>
<div x-data="logsApp()">
    <!-- Navbar -->
    <nav class="gastro-nav">
        <a href="/cashier/" class="gastro-nav-brand">
            <i class="fas fa-utensils"></i>
            <span>GastroFlow</span>
        </a>
        <div class="gastro-nav-links">
            <a href="/cashier/"><i class="fas fa-cash-register"></i>Caixa</a>
            <a href="/kitchen/"><i class="fas fa-fire"></i>Cozinha</a>
            <a href="/admin/" class="active"><i class="fas fa-cog"></i>Admin</a>
        </div>
        <button class="dark-toggle" @click="toggleDarkMode()" title="Alternar tema">
            <i class="fas" :class="darkMode ? 'fa-sun' : 'fa-moon'"></i>
        </button>
    </nav>

    <!-- Toast container -->
    <div class="toast-container" x-show="toasts.length">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="gastro-toast" :class="toast.type">
                <i class="fas gastro-toast-icon"
                   :class="toast.type === 'success' ? 'fa-check-circle' : toast.type === 'danger' ? 'fa-exclamation-circle' : toast.type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'"></i>
                <span class="gastro-toast-text" x-text="toast.text"></span>
                <button class="gastro-toast-close" @click="toasts = toasts.filter(t => t.id !== toast.id)">&times;</button>
            </div>
        </template>
    </div>

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0"><i class="fas fa-list me-2"></i>Logs do Sistema</h1>
            <div class="d-flex align-items-center gap-2">
                <a href="/admin/" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Cardápio
                </a>
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
    </div>
</div>

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function logsApp() {
    return {
        lines: [],
        filteredLines: [],
        totalLines: 0,
        loading: false,
        toasts: [],
        darkMode: localStorage.getItem('gastroflow_darkMode') === 'true',
        token: localStorage.getItem('admin_token') || '',
        filterLevel: '',
        lineCount: 200,

        async init() {
            this.applyTheme();
            if (!this.token) {
                window.location.href = '/admin/';
                return;
            }
            await this.refresh();
        },

        async refresh() {
            this.loading = true;
            try {
                const res = await fetch('/api/admin/logs?lines=' + this.lineCount, {
                    headers: { 'Authorization': 'Bearer ' + this.token }
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao carregar logs');
                this.lines = data.lines || [];
                this.totalLines = data.total || 0;
                this.applyFilter();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        applyFilter() {
            if (!this.filterLevel) {
                this.filteredLines = this.lines;
                return;
            }
            this.filteredLines = this.lines.filter(l => l.includes('.' + this.filterLevel + ':') || l.includes('.' + this.filterLevel + ' '));
        },

        extractTime(line) {
            const m = line.match(/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/);
            if (m) return m[1].replace('T', ' ');
            const m2 = line.match(/^\[(\d{2}\/\w+\/\d{4}\s+\d{2}:\d{2}:\d{2})/);
            if (m2) return m2[1];
            return '';
        },

        extractLevel(line) {
            const m = line.match(/\.(DEBUG|INFO|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)\b/);
            if (m) {
                const lvl = m[1];
                if (lvl === 'CRITICAL' || lvl === 'ALERT' || lvl === 'EMERGENCY') return 'ERROR';
                if (lvl === 'WARNING') return 'WARNING';
                if (lvl === 'INFO') return 'INFO';
                if (lvl === 'DEBUG') return 'DEBUG';
            }
            return 'INFO';
        },

        extractMessage(line) {
            // Remove timestamp and level prefix, keep the message
            const idx = line.indexOf(']: ');
            if (idx > 0) return line.substring(idx + 3);
            return line;
        },

        showMessage(text, type = 'info') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, text, type });
            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 4000);
        },

        applyTheme() {
            document.documentElement.setAttribute('data-theme', this.darkMode ? 'dark' : '');
        },

        toggleDarkMode() {
            this.darkMode = !this.darkMode;
            localStorage.setItem('gastroflow_darkMode', this.darkMode);
            this.applyTheme();
        }
    };
}
</script>
</body>
</html>
