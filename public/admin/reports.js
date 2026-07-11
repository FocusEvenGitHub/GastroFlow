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
        peakHours: [],
        prepTime: { avg_minutes: 0, by_day: [] },
        monthlyComp: { current: {}, previous: {}, change: {} },

        // Chart.js instances
        chartSales: null,
        chartPeakHours: null,
        chartPrepTime: null,

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

        destroyCharts() {
            if (this.chartSales) { this.chartSales.destroy(); this.chartSales = null; }
            if (this.chartPeakHours) { this.chartPeakHours.destroy(); this.chartPeakHours = null; }
            if (this.chartPrepTime) { this.chartPrepTime.destroy(); this.chartPrepTime = null; }
        },

        async loadData() {
            if (!this.token) return;
            this.loading = true;
            this.destroyCharts();
            try {
                const headers = { 'Authorization': 'Bearer ' + this.token };

                const [salesRes, topRes, diningRes, peakRes, prepRes, monthRes] = await Promise.all([
                    fetch(`/api/admin/reports/sales?date_from=${this.dateFrom}&date_to=${this.dateTo}`, { headers }),
                    fetch(`/api/admin/reports/top-items?date_from=${this.dateFrom}&date_to=${this.dateTo}&limit=10`, { headers }),
                    fetch(`/api/admin/reports/dining-options?date_from=${this.dateFrom}&date_to=${this.dateTo}`, { headers }),
                    fetch(`/api/admin/reports/peak-hours?date_from=${this.dateFrom}&date_to=${this.dateTo}`, { headers }),
                    fetch(`/api/admin/reports/prep-time?date_from=${this.dateFrom}&date_to=${this.dateTo}`, { headers }),
                    fetch(`/api/admin/reports/month-comparison?date_from=${this.dateFrom}&date_to=${this.dateTo}`, { headers }),
                ]);

                if (salesRes.status === 401) {
                    this.logout();
                    return;
                }

                const salesData = await salesRes.json();
                if (salesData.success) {
                    this.salesData = salesData.data || [];
                    const totalOrders = this.salesData.reduce((acc, d) => acc + d.orders, 0);
                    const totalRevenue = this.salesData.reduce((acc, d) => acc + d.revenue, 0);
                    const totalItems = this.salesData.reduce((acc, d) => acc + (d.items_sold || 0), 0);
                    this.summary = {
                        orders: totalOrders,
                        revenue: totalRevenue,
                        avg_ticket: totalOrders > 0 ? totalRevenue / totalOrders : 0,
                        items_sold: totalItems,
                    };
                }

                const topData = await topRes.json();
                if (topData.success) this.topItems = topData.data || [];

                const diningData = await diningRes.json();
                if (diningData.success) this.diningOptions = diningData.data || [];

                const peakData = await peakRes.json();
                if (peakData.success) this.peakHours = peakData.data || [];

                const prepData = await prepRes.json();
                if (prepData.success) this.prepTime = prepData.data || { avg_minutes: 0, by_day: [] };

                const monthData = await monthRes.json();
                if (monthData.success) this.monthlyComp = monthData.data || { current: {}, previous: {}, change: {} };

                this.$nextTick(() => {
                    this.renderSalesChart();
                    this.renderPeakHoursChart();
                    this.renderPrepTimeChart();
                });
            } catch (err) {
                this.showMessage('Erro ao carregar relatórios: ' + err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        // ─── Chart helpers ───
        chartColors() {
            const isDark = this.darkMode;
            return {
                gridColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.06)',
                textColor: isDark ? '#e0e0e0' : '#666',
                blue:    'rgba(13, 110, 253, 0.5)',
                blueBorder: 'rgba(13, 110, 253, 1)',
                green:   'rgba(25, 135, 84, 0.7)',
                greenBorder: 'rgba(25, 135, 84, 1)',
                orange:  'rgba(225, 112, 85, 0.6)',
                orangeBorder: 'rgba(225, 112, 85, 1)',
                purple:  'rgba(111, 66, 193, 0.5)',
                purpleBorder: 'rgba(111, 66, 193, 1)',
                red:     'rgba(214, 48, 49, 0.5)',
                redBorder: 'rgba(214, 48, 49, 1)',
            };
        },

        baseChartOptions() {
            const { gridColor, textColor } = this.chartColors();
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: textColor } },
                },
                scales: {
                    x: { grid: { color: gridColor }, ticks: { color: textColor } },
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor } },
                },
            };
        },

        // ─── Sales by day chart ───
        renderSalesChart() {
            if (!this.$refs.salesChart || this.salesData.length === 0) return;
            const { textColor, blue, blueBorder, green, greenBorder } = this.chartColors();

            const labels = this.salesData.map(d => {
                const parts = d.date.split('-');
                return parts[2] + '/' + parts[1];
            });

            this.chartSales = new Chart(this.$refs.salesChart, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Faturamento (R$)',
                            data: this.salesData.map(d => d.revenue),
                            backgroundColor: blue,
                            borderColor: blueBorder,
                            borderWidth: 1,
                            yAxisID: 'y',
                            order: 2,
                        },
                        {
                            label: 'Pedidos',
                            data: this.salesData.map(d => d.orders),
                            type: 'line',
                            backgroundColor: green,
                            borderColor: greenBorder,
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBackgroundColor: greenBorder,
                            yAxisID: 'y1',
                            order: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    scales: {
                        x: {
                            grid: { color: this.chartColors().gridColor },
                            ticks: { color: textColor },
                        },
                        y: {
                            beginAtZero: true,
                            position: 'left',
                            grid: { color: this.chartColors().gridColor },
                            ticks: { color: textColor, callback: (v) => 'R$ ' + v.toFixed(0) },
                            title: { display: true, text: 'Faturamento (R$)', color: textColor },
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: { display: false },
                            ticks: { color: textColor, stepSize: 1 },
                            title: { display: true, text: 'Pedidos', color: textColor },
                        },
                    },
                    plugins: {
                        legend: { labels: { color: textColor } },
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

        // ─── Peak hours chart ───
        renderPeakHoursChart() {
            if (!this.$refs.peakHoursChart || this.peakHours.length === 0) return;
            const { orange, orangeBorder } = this.chartColors();

            const labels = this.peakHours.map(d => String(d.hour).padStart(2, '0') + 'h');

            this.chartPeakHours = new Chart(this.$refs.peakHoursChart, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Pedidos',
                        data: this.peakHours.map(d => d.orders),
                        backgroundColor: orange,
                        borderColor: orangeBorder,
                        borderWidth: 1,
                        borderRadius: 4,
                    }],
                },
                options: {
                    ...this.baseChartOptions(),
                    scales: {
                        x: {
                            grid: { color: this.chartColors().gridColor },
                            ticks: { color: this.chartColors().textColor, maxRotation: 0 },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: this.chartColors().gridColor },
                            ticks: { color: this.chartColors().textColor, stepSize: 1 },
                            title: { display: true, text: 'Pedidos', color: this.chartColors().textColor },
                        },
                    },
                },
            });
        },

        // ─── Prep time chart ───
        renderPrepTimeChart() {
            if (!this.$refs.prepTimeChart || this.prepTime.by_day.length === 0) return;
            const { purple, purpleBorder } = this.chartColors();

            const labels = this.prepTime.by_day.map(d => {
                const parts = d.date.split('-');
                return parts[2] + '/' + parts[1];
            });

            this.chartPrepTime = new Chart(this.$refs.prepTimeChart, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Tempo médio (min)',
                        data: this.prepTime.by_day.map(d => d.avg_minutes),
                        backgroundColor: purple,
                        borderColor: purpleBorder,
                        borderWidth: 1,
                        borderRadius: 4,
                    }],
                },
                options: {
                    ...this.baseChartOptions(),
                    scales: {
                        x: {
                            grid: { color: this.chartColors().gridColor },
                            ticks: { color: this.chartColors().textColor },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: this.chartColors().gridColor },
                            ticks: { color: this.chartColors().textColor },
                            title: { display: true, text: 'Minutos', color: this.chartColors().textColor },
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
            this.$nextTick(() => {
                this.destroyCharts();
                this.renderSalesChart();
                this.renderPeakHoursChart();
                this.renderPrepTimeChart();
            });
        },
    };
}
