function settingsApp() {
    return GFAdmin.page({
        form: {
            restaurant_name: '',
            printer_ip: '',
            printer_port: '9100'
        },
        logoUrl: '/assets/img/logo.png?' + Date.now(),
        saving: false,
        testing: false,

        init() {
            if (!this.guard()) return;
            this.loadSettings();
            this.checkLogo();
        },

        async loadSettings() {
            try {
                const data = await this.api('/api/admin/settings');
                if (data && data.settings) {
                    this.form.restaurant_name = data.settings.restaurant_name || 'GastroFlow';
                    this.form.printer_ip = data.settings.printer_ip || '';
                    this.form.printer_port = data.settings.printer_port || '9100';
                }
            } catch (err) {
                // 403 (manager) vira o painel "Sem permissão" (spec 051).
                if (err.forbidden || err.unauthorized) { this.handleError(err); return; }
                console.error('Erro ao carregar configurações:', err);
            }
        },

        async saveSettings() {
            this.saving = true;
            try {
                await this.api('/api/admin/settings', {
                    method: 'PUT',
                    json: {
                        settings: {
                            restaurant_name: this.form.restaurant_name,
                            printer_ip: this.form.printer_ip,
                            printer_port: this.form.printer_port
                        }
                    }
                });
                this.showMessage('Configurações salvas com sucesso!', 'success');
            } catch (err) {
                this.handleError(err);
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
                await this.api('/api/admin/settings/logo', { method: 'POST', body: formData });
                this.logoUrl = '/assets/img/logo.png?' + Date.now();
                this.showMessage('Logo atualizada com sucesso!', 'success');
            } catch (err) {
                this.handleError(err);
            }
        },

        // --- Test Print ---
        async testPrint() {
            this.testing = true;
            try {
                // Envia um pedido de teste para a impressora
                await this.api('/api/admin/settings/test-print', { method: 'POST' });
                this.showMessage('Teste enviado para a impressora!', 'success');
            } catch (err) {
                this.handleError(err);
            } finally {
                this.testing = false;
            }
        }
    });
}
