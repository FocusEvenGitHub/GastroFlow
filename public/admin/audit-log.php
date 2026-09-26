<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Histórico de Auditoria</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .audit-row td { font-size: 0.85rem; vertical-align: top; }
        .audit-action { font-family: 'Cascadia Code', 'Fira Code', 'JetBrains Mono', monospace; font-size: 0.8rem; }
        .audit-details { font-family: 'Cascadia Code', 'Fira Code', 'JetBrains Mono', monospace; font-size: 0.75rem; white-space: pre-wrap; word-break: break-all; color: var(--text-muted); }
        #audit-container { max-height: 70vh; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); }
    </style>
    <script>
        if (localStorage.getItem('gastroflow_darkMode') === 'true') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
</head>
<body>
<div x-data="auditLogApp()">
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
            <h1 class="h3 mb-0"><i class="fas fa-shield-alt me-2"></i>Histórico de Auditoria</h1>
            <div class="d-flex align-items-center gap-2">
                <a href="/admin/" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Cardápio
                </a>
                <a href="logs.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list"></i> Logs Técnicos
                </a>
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
    </div>
</div>

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function auditLogApp() {
    return {
        entries: [],
        total: 0,
        loading: false,
        toasts: [],
        darkMode: localStorage.getItem('gastroflow_darkMode') === 'true',
        token: localStorage.getItem('admin_token') || '',
        limit: 100,

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
                const res = await fetch('/api/admin/audit-log?limit=' + this.limit, {
                    headers: { 'Authorization': 'Bearer ' + this.token }
                });
                if (res.status === 401 || res.status === 403) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Erro ao carregar auditoria');
                this.entries = data.entries || [];
                this.total = data.total || 0;
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        formatWhen(iso) {
            if (!iso) return '';
            return new Date(iso).toLocaleString('pt-BR');
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
