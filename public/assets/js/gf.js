// Infraestrutura compartilhada pelo Caixa, Cozinha e Admin (spec 056): cliente da
// API, toasts e tema. Script simples, sem build — define o global GF. O que é só
// do Admin (sessão, Bearer, 401/403, confirmação) fica em public/admin/auth.js.
const GF = {
    // fetch + JSON. Devolve o corpo já lido (null se vazio) ou lança um Error com
    // mensagem pronta para o operador, mais `status`, `code` (da API) e `network`
    // (true só quando o servidor nem respondeu). `options.json` vira o body JSON.
    async api(url, options = {}) {
        const { json, ...init } = options;
        if (json !== undefined) {
            init.headers = { ...(init.headers || {}), 'Content-Type': 'application/json' };
            init.body = JSON.stringify(json);
        }

        let res;
        try {
            res = await fetch(url, init);
        } catch (_) {
            throw Object.assign(new Error('Sem conexão com o servidor.'), { network: true });
        }

        const text = await res.text();
        let data = null;
        try {
            data = text ? JSON.parse(text) : null;
        } catch (_) {
            // HTML de proxy/Apache (502, 504...) ou corpo truncado.
            throw Object.assign(new Error(`Erro do servidor (HTTP ${res.status}).`), { status: res.status });
        }

        if (!res.ok || (data && data.error)) {
            const message = data && typeof data.error === 'string' ? data.error : `Erro do servidor (HTTP ${res.status}).`;
            throw Object.assign(new Error(message), { status: res.status, code: data && data.code });
        }
        return data;
    },

    readSetting(key) {
        try { return localStorage.getItem(key); } catch (_) { return null; }
    },

    // Estado e métodos comuns a toda tela, para espalhar no x-data: { ...GF.ui(), ... }.
    // O tema é aplicado antes da pintura por um <script> inline em cada página; aqui
    // só fica o que muda depois.
    ui() {
        return {
            toasts: [],
            darkMode: GF.readSetting('gastroflow_darkMode') === 'true',

            showMessage(text, type = 'info') {
                const id = Date.now() + Math.random();
                this.toasts.push({ id, text, type });
                setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 5000);
            },

            applyTheme() {
                document.documentElement.setAttribute('data-theme', this.darkMode ? 'dark' : '');
            },

            toggleDarkMode() {
                this.darkMode = !this.darkMode;
                try { localStorage.setItem('gastroflow_darkMode', this.darkMode); } catch (_) {}
                this.applyTheme();
            }
        };
    }
};
