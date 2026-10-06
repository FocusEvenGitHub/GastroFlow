function logsApp() {
    return GFAdmin.page({
        lines: [],
        filteredLines: [],
        totalLines: 0,
        loading: false,
        filterLevel: '',
        lineCount: 200,

        async init() {
            if (!this.guard()) return;
            await this.refresh();
        },

        async refresh() {
            this.loading = true;
            try {
                const data = await this.api('/api/admin/logs?lines=' + this.lineCount);
                this.lines = data.lines || [];
                this.totalLines = data.total || 0;
                this.applyFilter();
            } catch (err) {
                this.handleError(err);
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
        }
    });
}
