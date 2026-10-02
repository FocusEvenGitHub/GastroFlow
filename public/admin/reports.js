function reportsApp() {
    return GFAdmin.page({
        // Filters
        dateFrom: '',
        dateTo: '',
        loading: false,

        // Data
        summary: { orders: 0, revenue: 0, avg_ticket: 0, items_sold: 0 },
        salesData: [],
        topItems: [],
        mainDishes: { total_qty: 0, total_revenue: 0, items: [] }, // spec 032
        diningOptions: [],
        peakHours: [],
        prepTime: { avg_minutes: 0, by_day: [] },
        monthlyComp: { current: {}, previous: {}, change: {} },

        // Chart.js instances
        chartSales: null,
        chartPeakHours: null,
        chartPrepTime: null,

        async init() {
            if (!this.guard()) return;
            this.setCurrentMonth();
            await this.loadData();
        },

        // Não use toISOString(): converte para UTC, e em UTC-3 a partir das 21:00
        // locais o dia já virou — dateTo apontava para amanhã e dateFrom para o
        // último dia do mês anterior (spec 036).
        _localDate(date) {
            const pad = (n) => String(n).padStart(2, '0');
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        },

        setCurrentMonth() {
            const now = new Date();
            const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
            this.dateFrom = this._localDate(firstDay);
            this.dateTo = this._localDate(now);
        },

        destroyCharts() {
            if (this.chartSales) { this.chartSales.destroy(); this.chartSales = null; }
            if (this.chartPeakHours) { this.chartPeakHours.destroy(); this.chartPeakHours = null; }
            if (this.chartPrepTime) { this.chartPrepTime.destroy(); this.chartPrepTime = null; }
        },

        async loadData() {
            this.loading = true;
            this.destroyCharts();
            try {
                const [salesRes, topRes, mainDishesRes, diningRes, peakRes, prepRes, monthRes] = await Promise.all([
                    this.api(`/api/admin/reports/sales?date_from=${this.dateFrom}&date_to=${this.dateTo}`),
                    this.api(`/api/admin/reports/top-items?date_from=${this.dateFrom}&date_to=${this.dateTo}&limit=10`),
                    this.api(`/api/admin/reports/main-dishes?date_from=${this.dateFrom}&date_to=${this.dateTo}`),
                    this.api(`/api/admin/reports/dining-options?date_from=${this.dateFrom}&date_to=${this.dateTo}`),
                    this.api(`/api/admin/reports/peak-hours?date_from=${this.dateFrom}&date_to=${this.dateTo}`),
                    this.api(`/api/admin/reports/prep-time?date_from=${this.dateFrom}&date_to=${this.dateTo}`),
                    this.api(`/api/admin/reports/month-comparison?date_from=${this.dateFrom}&date_to=${this.dateTo}`),
                ]);

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

                const mainDishesData = await mainDishesRes.json();
                if (mainDishesData.success) this.mainDishes = mainDishesData.data || { total_qty: 0, total_revenue: 0, items: [] };

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
                if (err.forbidden || err.unauthorized) this.handleError(err);
                else this.showMessage('Erro ao carregar relatórios: ' + err.message, 'danger');
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
        }
    });
}
