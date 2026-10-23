// Infraestrutura compartilhada pelo Caixa, Cozinha e Admin (spec 056): cliente da
// API, toasts e tema. Script simples, sem build — define o global GF. O que é só
// do Admin (sessão, Bearer, 401/403, confirmação) fica em public/admin/auth.js.
const GF = {
    // Indicador de conexão do Caixa e da Cozinha (spec 057).
    HEARTBEAT_MS: 5000,
    // Maior que o intervalo de propósito: servidor lento não é servidor fora do ar. No
    // Docker Desktop/Windows medimos /health/ready levando 2,5–6 s com o container ocioso.
    HEARTBEAT_TIMEOUT_MS: 10000,
    LOST_AFTER_MS: 15000,

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
            // Antecipa o próximo heartbeat do indicador de conexão (spec 057).
            window.dispatchEvent(new Event('gf:network-error'));
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

    // Desconto por item (spec 058), compartilhado pelo Caixa e pelo "Editar Pedido" da
    // Cozinha para as duas telas não divergirem. Espelha o PricingService: tudo em
    // inteiros (centavos; porcentagem em centésimos, 2000 = 20%), arredondamento
    // half-up. O servidor valida de novo ao gravar — isto é só para a tela.
    discount: {
        // "12,5" | "12.5" | "5" → centésimos (1250, 500); null se inválido.
        parse(text) {
            const t = String(text ?? '').trim();
            if (!/^\d{1,8}([.,]\d{1,2})?$/.test(t)) return null;
            const [int, dec = ''] = t.replace(',', '.').split('.');
            return parseInt(int, 10) * 100 + parseInt((dec + '00').slice(0, 2), 10);
        },

        percentOf(cents, hundredths) {
            return Math.floor((cents * hundredths + 5000) / 10000);
        },

        money(cents) {
            return (cents / 100).toFixed(2).replace('.', ',');
        },

        // 3000 → "30", 1250 → "12,5"
        rate(hundredths) {
            return (hundredths / 100).toFixed(2).replace(/\.?0+$/, '').replace('.', ',');
        },

        // Valor digitado → { amount (centavos), error (texto ou null) }, com as mesmas
        // regras e mensagens do servidor.
        evaluate(type, valueText, lineCents, maxHundredths) {
            if (maxHundredths <= 0) return { amount: 0, error: 'O desconto está desativado.' };
            if (lineCents <= 0) return { amount: 0, error: 'Este item não tem valor para dar desconto.' };
            const value = this.parse(valueText);
            if (value === null || value === 0) return { amount: 0, error: 'Informe um valor maior que zero.' };
            if (type === 'percent' && value > 10000) {
                return { amount: 0, error: `O desconto não pode ser maior que o valor do item (R$ ${this.money(lineCents)}).` };
            }
            const amount = type === 'percent' ? this.percentOf(lineCents, value) : value;
            if (amount === 0) return { amount, error: 'Informe um valor maior que zero.' };
            if (amount > lineCents) {
                return { amount, error: `O desconto não pode ser maior que o valor do item (R$ ${this.money(lineCents)}).` };
            }
            const cap = this.percentOf(lineCents, maxHundredths);
            const overCap = type === 'percent' ? value > maxHundredths : amount > cap;
            if (overCap) {
                return { amount, error: `O desconto máximo permitido é de ${this.rate(maxHundredths)}% (R$ ${this.money(cap)} neste item).` };
            }
            return { amount, error: null };
        },

        // Selo curto: "−20%" | "−R$ 5,00"
        badge(type, valueText) {
            const value = this.parse(valueText);
            if (value === null) return '';
            return type === 'percent' ? `−${this.rate(value)}%` : `−R$ ${this.money(value)}`;
        },

        // Para a API: { type, value } com ponto decimal.
        payload(type, valueText) {
            const value = this.parse(valueText);
            return value === null ? null : { type, value: (value / 100).toFixed(2) };
        },

        // Teto vindo de /api/settings/discount (número em %) → centésimos.
        async loadMax() {
            const data = await GF.api('/api/settings/discount');
            return Math.round(Number(data.discount_max_percent) * 100);
        }
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
            },

            // Conexão (spec 057): 'connected' | 'reconnecting' | 'lost'. Saudável =
            // último heartbeat em /health/ready ok E stream da tela (se houver) aberto.
            // /health/ready, e não /live: banco fora do ar também deixa a tela inútil.
            connection: 'connected',
            connectionDownSince: null,
            beatOk: true,
            streamUp: true,
            beating: false,
            onConnectionRestore: null,

            startConnectionWatch(onRestore = null) {
                this.onConnectionRestore = onRestore;
                const beat = () => this.heartbeat();
                setInterval(beat, GF.HEARTBEAT_MS);
                ['online', 'offline', 'gf:network-error'].forEach(e => window.addEventListener(e, beat));
                beat();
            },

            async heartbeat() {
                // Um por vez: com o timeout maior que o intervalo, uma resposta antiga
                // chegando depois de uma nova inverteria o estado.
                if (this.beating) return;
                this.beating = true;
                let ok = false;
                try {
                    const res = await fetch('/health/ready', {
                        cache: 'no-store',
                        signal: AbortSignal.timeout(GF.HEARTBEAT_TIMEOUT_MS)
                    });
                    ok = res.ok;
                } catch (_) {
                } finally {
                    this.beating = false;
                }
                this.beatOk = ok;
                this.updateConnection();
            },

            setStreamUp(up) {
                this.streamUp = up;
                this.updateConnection();
            },

            updateConnection() {
                if (this.beatOk && this.streamUp) {
                    if (this.connection !== 'connected') {
                        this.connection = 'connected';
                        this.connectionDownSince = null;
                        if (this.onConnectionRestore) this.onConnectionRestore();
                    }
                    return;
                }
                this.connectionDownSince ??= Date.now();
                this.connection = Date.now() - this.connectionDownSince >= GF.LOST_AFTER_MS ? 'lost' : 'reconnecting';
            },

            connectionDownTime() {
                return this.connectionDownSince
                    ? new Date(this.connectionDownSince).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
                    : '';
            }
        };
    }
};
