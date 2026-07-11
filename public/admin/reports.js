function reportsApp() {
    return {
        // Auth
        loggedIn: false,
        token: localStorage.getItem('admin_token') || '',
        loginForm: { username: '', password: '' },
        loginError: '',
        logging: false,
        username: '',

        // Filters
        dateFrom: '',
        dateTo: '',
        loading: false,
        toasts: [],

        // Data
        summary: { orders: 0, revenue: 0, avg_ticket: 0, items_sold: 0 },
        salesData: [],
        topItems: [],
        diningOptions: [],

        // Chart.js instance
        chartInstance: null,

        // Dark mode
        darkMode: localStorage.getItem('gastroflow_darkMode') === 'true',

        async init() {
            this.applyTheme();
            this.setCurrentMonth();

            if (this.token) {
                this.loggedIn = true;
                this.username = localStorage.getItem('admin_username') || 'Admin';
                await this.loadData();
            }
        },

        setCurrentMonth() {
            const now = new Date();
            const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
            this.dateFrom = firstDay.toISOString().split('T')[0];
            this.dateTo = now.toISOString().split('T')[0];
        },

        async doLogin() {
            this.logging = true;
            this.loginError = '';
            try {
                const res = await fetch('/api/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.loginForm),
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Login inválido');
                this.token = data.token;
                this.username = data.user?.username || this.loginForm.username;
                localStorage.setItem('admin_token', this.token);
                localStorage.setItem('admin_username', this.username);
                this.loggedIn = true;
                await this.loadData();
            } catch (err) {
                this.loginError = err.message;
            } finally {
                this.logging = false;
            }
        },

        logout() {
            localStorage.removeItem('admin_token');
            localStorage.removeItem('admin_username');
            this.loggedIn = false;
            this.token = '';
            this.username = '';
        },

        async loadData() {
            if (!this.token) return;
            this.loading = true;
            try {
                const headers = { 'Authorization': 'Bearer ' + this.token };

                const [salesRes, topRes, diningRes] = await Promise.all([
                    fetch(`/api/admin/reports/sales?date_from=${this.dateFrom}&date_to=${this.dateTo}`, { headers }),
                    fetch(`/api/admin/reports/top-items?date_from=${this.dateFrom}&date_to=${this.dateTo}&limit=10`, { headers }),
                    fetch(`/api/admin/reports/dining-options?date_from=${this.dateFrom}&date_to=${this.dateTo}`, { headers }),
                ]);

                if (salesRes.status === 401) {
                    this.logout();
                    return;
                }

                const salesData = await salesRes.json();
                if (salesData.success) {
                    this.salesData = salesData.data || [];
                    // Compute summary from sales data (aggregate over the period)
                    const totalOrders = this.salesData.reduce((acc, d) => acc + d.orders, 0);
                    const totalRevenue = this.salesData.reduce((acc, d) => acc + d.revenue, 0);
                    const totalItems = this.salesData.reduce((acc, d) => acc + (d.items_sold || 0), 0);
                    this.summary = {
                        orders: totalOrders,
                        revenue: totalRevenue,
                        avg_ticket: totalOrders > 0 ? totalRevenue / totalOrders : 0,
                        items_sold: totalItems,
                    };
                    this.$nextTick(() => this.renderChart());
                }

                const topData = await topRes.json();
                if (topData.success) {
                    this.topItems = topData.data || [];
                }

                const diningData = await diningRes.json();
                if (diningData.success) {
                    this.diningOptions = diningData.data || [];
                }
            } catch (err) {
                this.showMessage('Erro ao carregar relatórios: ' + err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        renderChart() {
            if (!this.$refs.salesChart) return;

            // Destroy previous chart
            if (this.chartInstance) {
                this.chartInstance.destroy();
                this.chartInstance = null;
            }

            const labels = this.salesData.map(d => {
                const parts = d.date.split('-');
                return parts[2] + '/' + parts[1];
            });
            const revenues = this.salesData.map(d => d.revenue);
            const orders = this.salesData.map(d => d.orders);

            const isDark = this.darkMode;
            const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.06)';
            const textColor = isDark ? '#e0e0e0' : '#666';

            this.chartInstance = new Chart(this.$refs.salesChart, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Faturamento (R$)',
                            data: revenues,
                            backgroundColor: 'rgba(13, 110, 253, 0.5)',
                            borderColor: 'rgba(13, 110, 253, 1)',
                            borderWidth: 1,
                            yAxisID: 'y',
                            order: 2,
                        },
                        {
                            label: 'Pedidos',
                            data: orders,
                            type: 'line',
                            backgroundColor: 'rgba(25, 135, 84, 0.8)',
                            borderColor: 'rgba(25, 135, 84, 1)',
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBackgroundColor: 'rgba(25, 135, 84, 1)',
                            yAxisID: 'y1',
                            order: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index',
                    },
                    scales: {
                        x: {
                            grid: { color: gridColor },
                            ticks: { color: textColor },
                        },
                        y: {
                            beginAtZero: true,
                            position: 'left',
                            grid: { color: gridColor },
                            ticks: {
                                color: textColor,
                                callback: (v) => 'R$ ' + v.toFixed(0),
                            },
                            title: {
                                display: true,
                                text: 'Faturamento (R$)',
                                color: textColor,
                            },
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: { display: false },
                            ticks: {
                                color: textColor,
                                stepSize: 1,
                            },
                            title: {
                                display: true,
                                text: 'Pedidos',
                                color: textColor,
                            },
                        },
                    },
                    plugins: {
                        legend: {
                            labels: { color: textColor },
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    if (ctx.dataset.label.includes('Faturamento')) {
                                        return ctx.dataset.label + ': R$ ' + ctx.raw.toFixed(2);
                                    }
                                    return ctx.dataset.label + ': ' + ctx.raw;
                                },
                            },
                        },
                    },
                },
            });
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
            // Re-render chart with new theme colors
            if (this.salesData.length > 0) {
                this.$nextTick(() => this.renderChart());
            }
        },
    };
}
