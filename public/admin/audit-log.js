function auditLogApp() {
    return GFAdmin.page({
        entries: [],
        total: 0,
        loading: false,
        limit: 100,

        async init() {
            if (!this.guard()) return;
            await this.refresh();
        },

        async refresh() {
            this.loading = true;
            try {
                const data = await this.api('/api/admin/audit-log?limit=' + this.limit);
                this.entries = data.entries || [];
                this.total = data.total || 0;
            } catch (err) {
                this.handleError(err);
            } finally {
                this.loading = false;
            }
        },

        formatWhen(iso) {
            if (!iso) return '';
            return new Date(iso).toLocaleString('pt-BR');
        }
    });
}
