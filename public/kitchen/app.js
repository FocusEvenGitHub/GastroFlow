function kitchenApp() {
    return {
        orders: [],
        completedOrders: [],
        loading: true,
        toasts: [],
        completing: null,
        uncompleting: null,
        showDone: true,
        viewMode: localStorage.getItem('kitchenViewMode') || 'list',
        darkMode: localStorage.getItem('gastroflow_darkMode') === 'true',
        eventSource: null,
        foodSummary: [],
        selectedDate: new Date().toISOString().split('T')[0],

        _today() {
            return new Date().toISOString().split('T')[0];
        },

        async init() {
            this.applyTheme();
            await this.fetchAll();
            this.connectSSE();
        },

        onDateChange() {
            this.fetchAll();
        },

        // Conecta ao stream SSE para atualizações em tempo real
        connectSSE() {
            if (this.eventSource) {
                this.eventSource.close();
            }

            this.eventSource = new EventSource('/api/events/stream.php');

            this.eventSource.addEventListener('connected', () => {
                console.log('SSE conectado');
            });

            this.eventSource.addEventListener('order.created', () => {
                if (this.selectedDate === this._today()) this.fetchAll();
            });

            this.eventSource.addEventListener('order.completed', () => {
                if (this.selectedDate === this._today()) this.fetchAll();
            });

            this.eventSource.addEventListener('order.uncompleted', () => {
                if (this.selectedDate === this._today()) this.fetchAll();
            });

            this.eventSource.onerror = () => {
                // EventSource reconecta automaticamente, mas se ficar muito tempo
                // sem conexão, recarregue manualmente após 30s
                setTimeout(() => {
                    if (this.eventSource && this.eventSource.readyState === EventSource.CLOSED) {
                        console.log('SSE: tentando reconectar…');
                        this.connectSSE();
                    }
                }, 30000);
            };
        },

        async fetchAll() {
            await Promise.all([
                this.fetchOrders(),
                this.fetchCompletedOrders(),
                this.loadFoodSummary(),
            ]);
            this.loading = false;
        },

        async fetchOrders() {
            try {
                const res = await fetch('/api/orders?status=pending&date=' + this.selectedDate);
                if (!res.ok) throw new Error('Erro ao buscar pedidos');
                this.orders = await res.json();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        async fetchCompletedOrders() {
            try {
                const res = await fetch('/api/orders?status=done&date=' + this.selectedDate);
                if (!res.ok) throw new Error('Erro ao buscar finalizados');
                this.completedOrders = await res.json();
            } catch (err) {
                console.error('Erro ao buscar finalizados:', err);
            }
        },

        async refresh() {
            this.loading = true;
            await this.fetchAll();
        },

        async loadFoodSummary() {
            try {
                const res = await fetch('/api/kitchen/food-summary?date=' + this.selectedDate);
                if (!res.ok) throw new Error('Erro ao buscar resumo');
                const data = await res.json();
                this.foodSummary = data.items || [];
            } catch (err) {
                console.error('Erro food-summary:', err);
            }
        },

        groupedSummary() {
            const groups = {};
            for (const item of this.foodSummary) {
                const cat = item.food_category || 'other';
                if (!groups[cat]) groups[cat] = [];
                groups[cat].push(item);
            }

            for (const cat of Object.keys(groups)) {
                groups[cat].sort((a, b) => a.name.localeCompare(b.name));
            }

            const order = ['protein', 'grain', 'vegetable', 'sauce', 'side', 'other'];
            const sorted = {};
            for (const key of order) {
                if (groups[key]) sorted[key] = groups[key];
            }
            for (const key of Object.keys(groups).sort()) {
                if (!sorted[key]) sorted[key] = groups[key];
            }
            return sorted;
        },

        async completeOrder(orderId) {
            this.completing = orderId;
            try {
                const res = await fetch(`/api/orders/${orderId}/complete`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao finalizar');
                this.showMessage(`Pedido #${orderId} finalizado!`, 'success');
                // Remove from pending, move to completed
                this.orders = this.orders.filter(o => o.id !== orderId);
                await this.fetchCompletedOrders();
                await this.loadFoodSummary();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.completing = null;
            }
        },

        async uncompleteOrder(orderId) {
            this.uncompleting = orderId;
            try {
                const res = await fetch(`/api/orders/${orderId}/uncomplete`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao estornar');
                this.showMessage(`Pedido #${orderId} reaberto!`, 'success');
                // Remove from completed, add to pending
                this.completedOrders = this.completedOrders.filter(o => o.id !== orderId);
                await this.fetchOrders();
                await this.loadFoodSummary();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.uncompleting = null;
            }
        },

        // Retorna o nome do cliente (se houver) ou "Pedido #N" como fallback
        displayName(order) {
            if (order.customer_name && typeof order.customer_name === 'string' && order.customer_name.trim() !== '') {
                return order.customer_name.trim();
            }
            return 'Pedido #' + order.id;
        },

        timeAgo(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr.replace(' ', 'T') + 'Z');
            const now = new Date();
            const diffMs = now - date;
            const diffMin = Math.floor(diffMs / 60000);
            if (diffMin < 1) return 'agora';
            if (diffMin < 60) return diffMin + 'min';
            const diffH = Math.floor(diffMin / 60);
            const remM = diffMin % 60;
            return diffH + 'h' + (remM > 0 ? remM + 'm' : '');
        },

        showMessage(text, type = 'info') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, text, type });
            setTimeout(() => {
                this.toasts = this.toasts.filter(t => t.id !== id);
            }, 5000);
        },

        toggleView(mode) {
            this.viewMode = mode;
            localStorage.setItem('kitchenViewMode', mode);
        },

        applyTheme() {
            document.documentElement.setAttribute('data-theme', this.darkMode ? 'dark' : '');
        },

        toggleDarkMode() {
            this.darkMode = !this.darkMode;
            localStorage.setItem('gastroflow_darkMode', this.darkMode);
            this.applyTheme();
        },

        destroy() {
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }
        }
    };
}
