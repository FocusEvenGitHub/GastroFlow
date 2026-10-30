function settingsApp() {
    return GFAdmin.page({
        form: {
            restaurant_name: '',
            printer_ip: '',
            printer_port: '9100',
            // Desconto máximo por item, em % (spec 058). 0 desativa.
            discount_max_percent: '100'
        },
        // Vem do servidor (spec 066): a logo enviada fica em /uploads, a padrão em /assets/img.
        logoUrl: '',
        // Motivo de a logo não sair no cupom (spec 065); vazio quando ela imprime.
        logoProblem: '',
        saving: false,
        testing: false,

        init() {
            if (!this.guard()) return;
            this.loadSettings();
        },

        async loadSettings() {
            try {
                const data = await this.api('/api/admin/settings');
                if (data && data.settings) {
                    this.form.restaurant_name = data.settings.restaurant_name || 'GastroFlow';
                    this.form.printer_ip = data.settings.printer_ip || '';
                    this.form.printer_port = data.settings.printer_port || '9100';
                    this.form.discount_max_percent = data.settings.discount_max_percent ?? '100';
                }
                this.logoProblem = (data && data.logo_problem) || '';
                // Date.now() fura o cache do navegador depois de um upload.
                this.logoUrl = (data && data.logo_url) ? data.logo_url + '?' + Date.now() : '';
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
                            printer_port: this.form.printer_port,
                            // Aceita vírgula ("12,5"); o servidor só aceita ponto.
                            discount_max_percent: String(this.form.discount_max_percent ?? '').trim().replace(',', '.')
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
        async uploadLogo(event) {
            const file = event.target.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('logo', file);

            try {
                await this.api('/api/admin/settings/logo', { method: 'POST', body: formData });
                this.showMessage('Logo atualizada com sucesso!', 'success');
                // Recarrega logo_url (agora /uploads) e o aviso de impressão.
                this.loadSettings();
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
