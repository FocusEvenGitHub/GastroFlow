function settingsApp() {
    return {
        loggedIn: false,
        token: localStorage.getItem('admin_token') || '',
        username: localStorage.getItem('admin_username') || '',
        loginForm: { username: '', password: '' },
        logging: false,
        loginError: '',

        form: {
            restaurant_name: '',
            printer_ip: '',
            printer_port: '9100'
        },
        logoUrl: '/assets/img/logo.png?' + Date.now(),
        saving: false,
        testing: false,
        message: { text: '', type: 'info' },

        init() {
            if (this.token) {
                this.loggedIn = true;
                this.loadSettings();
                this.checkLogo();
            }
        },

        // --- Auth ---
        async doLogin() {
            this.logging = true;
            this.loginError = '';
            try {
                const res = await fetch('/api/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.loginForm)
                });
                const data = await res.json();
                if (!res.ok || !data.token) {
                    throw new Error(data.error || 'Falha no login');
                }
                this.token = data.token;
                this.username = data.username || 'Admin';
                localStorage.setItem('admin_token', data.token);
                localStorage.setItem('admin_username', this.username);
                this.loggedIn = true;
                this.loadSettings();
                this.checkLogo();
            } catch (err) {
                this.loginError = err.message;
            } finally {
                this.logging = false;
            }
        },

        logout() {
            this.token = '';
            this.username = '';
            localStorage.removeItem('admin_token');
            localStorage.removeItem('admin_username');
            this.loggedIn = false;
        },

        // --- Settings ---
        getHeaders() {
            return {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + this.token
            };
        },

        async loadSettings() {
            try {
                const res = await fetch('/api/admin/settings', {
                    headers: this.getHeaders()
                });
                const data = await res.json();
                if (data.success && data.settings) {
                    this.form.restaurant_name = data.settings.restaurant_name || 'GastroFlow';
                    this.form.printer_ip = data.settings.printer_ip || '';
                    this.form.printer_port = data.settings.printer_port || '9100';
                }
            } catch (err) {
                console.error('Erro ao carregar configurações:', err);
            }
        },

        async saveSettings() {
            this.saving = true;
            try {
                const res = await fetch('/api/admin/settings', {
                    method: 'PUT',
                    headers: this.getHeaders(),
                    body: JSON.stringify({
                        settings: {
                            restaurant_name: this.form.restaurant_name,
                            printer_ip: this.form.printer_ip,
                            printer_port: this.form.printer_port
                        }
                    })
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Erro ao salvar');
                }
                this.showMessage('Configurações salvas com sucesso!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.saving = false;
            }
        },

        // --- Logo ---
        checkLogo() {
            // Verifica se o logo existe tentando carregá-lo
            const img = new Image();
            img.onload = () => {
                this.logoUrl = '/assets/img/logo.png?' + Date.now();
            };
            img.onerror = () => {
                this.logoUrl = '';
            };
            img.src = '/assets/img/logo.png?' + Date.now();
        },

        async uploadLogo(event) {
            const file = event.target.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('logo', file);

            try {
                const res = await fetch('/api/admin/settings/logo', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + this.token
                    },
                    body: formData
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Erro ao enviar logo');
                }
                this.logoUrl = '/assets/img/logo.png?' + Date.now();
                this.showMessage('Logo atualizada com sucesso!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        // --- Test Print ---
        async testPrint() {
            this.testing = true;
            try {
                // Envia um pedido de teste para a impressora
                const res = await fetch('/api/admin/settings/test-print', {
                    method: 'POST',
                    headers: this.getHeaders()
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Falha no teste');
                }
                this.showMessage('Teste enviado para a impressora!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.testing = false;
            }
        },

        // --- Helpers ---
        showMessage(text, type = 'info') {
            this.message = { text, type };
            setTimeout(() => { this.message.text = ''; }, 5000);
        }
    };
}
