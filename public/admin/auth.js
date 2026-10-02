// Sessão e helpers compartilhados por todas as páginas do Admin (spec 051).
// Substitui os três logins duplicados (app.js, settings.js, reports.js) e o
// tratamento de 401 copiado em cada fetch. A autorização de verdade continua no
// servidor (JwtMiddleware + RoleMiddleware) — esconder itens do menu por papel é
// só cosmético.
const GFAdmin = {
    KEY_TOKEN: 'admin_token',
    KEY_USER: 'admin_username',
    KEY_ROLE: 'admin_role',

    read(key) {
        try { return localStorage.getItem(key) || ''; } catch (_) { return ''; }
    },

    get token() { return this.read(this.KEY_TOKEN); },
    get username() { return this.read(this.KEY_USER); },
    get role() { return this.read(this.KEY_ROLE); },

    saveSession(data) {
        localStorage.setItem(this.KEY_TOKEN, data.token);
        localStorage.setItem(this.KEY_USER, data.user?.username || '');
        localStorage.setItem(this.KEY_ROLE, data.user?.role || '');
    },

    clearSession() {
        [this.KEY_TOKEN, this.KEY_USER, this.KEY_ROLE].forEach(k => localStorage.removeItem(k));
    },

    // Só aceita caminhos dentro do Admin — evita open redirect via ?next=.
    safeNext(raw) {
        if (typeof raw !== 'string' || !raw.startsWith('/admin/')) return null;
        if (raw.includes('//') || raw.includes('\\')) return null;
        if (raw === '/admin/' || raw === '/admin/index.php') return null;
        return raw;
    },

    loginUrl() {
        return '/admin/?next=' + encodeURIComponent(location.pathname);
    },

    requireLogin() {
        if (this.token) return true;
        location.href = this.loginUrl();
        return false;
    },

    async login(username, password) {
        const res = await fetch('/api/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });
        const data = await res.json();
        if (!res.ok || !data.token) throw new Error(data.error || 'Falha na autenticação');
        this.saveSession(data);
        return data;
    },

    logout() {
        this.clearSession();
        location.href = '/admin/';
    },

    // fetch com Bearer. 401 → encerra a sessão e volta ao login (com retorno);
    // 403 → lança um erro marcado `forbidden`, que a página mostra como painel.
    async authFetch(url, options = {}) {
        const headers = { ...(options.headers || {}), 'Authorization': 'Bearer ' + this.token };
        const res = await fetch(url, { ...options, headers });
        if (res.status === 401) {
            this.clearSession();
            location.href = this.loginUrl();
            throw Object.assign(new Error('Sessão expirada'), { unauthorized: true });
        }
        if (res.status === 403) {
            throw Object.assign(new Error('Sem permissão para esta área.'), { forbidden: true });
        }
        return res;
    },

    // Estado e métodos comuns a toda página do Admin (shell, toasts, tema,
    // confirmação). Usa descritores para não avaliar os getters da página.
    page(extra) {
        const base = {
            toasts: [],
            darkMode: GFAdmin.read('gastroflow_darkMode') === 'true',
            username: GFAdmin.username,
            role: GFAdmin.role,
            forbidden: false,
            confirmDialog: { open: false, title: '', message: '', okLabel: 'Excluir', resolve: null },

            // Sem papel salvo (sessões anteriores à spec 051) mostra tudo; o servidor barra.
            get canSeeAdminOnly() {
                return !this.role || this.role === 'admin';
            },

            guard() {
                this.applyTheme();
                return GFAdmin.requireLogin();
            },

            logout() {
                GFAdmin.logout();
            },

            api(url, options) {
                return GFAdmin.authFetch(url, options);
            },

            // Erro de request: 403 vira o painel "Sem permissão"; 401 já redirecionou.
            handleError(err) {
                if (err.forbidden) { this.forbidden = true; return; }
                if (err.unauthorized) return;
                this.showMessage(err.message, 'danger');
            },

            showMessage(text, type = 'info') {
                const id = Date.now() + Math.random();
                this.toasts.push({ id, text, type });
                setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 4500);
            },

            askConfirm(message, { title = 'Confirmar exclusão', okLabel = 'Excluir' } = {}) {
                return new Promise(resolve => {
                    this.confirmDialog = { open: true, title, message, okLabel, resolve };
                });
            },

            closeConfirm(answer) {
                const resolve = this.confirmDialog.resolve;
                this.confirmDialog = { ...this.confirmDialog, open: false, resolve: null };
                if (resolve) resolve(answer);
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
        return Object.defineProperties(base, Object.getOwnPropertyDescriptors(extra));
    }
};
