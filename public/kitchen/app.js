function kitchenApp() {
    return {
        orders: [],
        loading: true,
        message: { text: '', type: 'info' },
        completing: null,
        refreshInterval: null,
        foodSummary: [],

        async init() {
            await this.fetchOrders();
            await this.loadFoodSummary();
            this.refreshInterval = setInterval(() => {
                this.fetchOrders();
                this.loadFoodSummary();
            }, 5000);
        },

        async fetchOrders() {
            try {
                const res = await fetch('/api/orders?status=pending');
                if (!res.ok) throw new Error('Erro ao buscar pedidos');
                this.orders = await res.json();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        async refresh() {
            this.loading = true;
            await this.fetchOrders();
            await this.loadFoodSummary();
        },

        async loadFoodSummary() {
            try {
                const res = await fetch('/api/kitchen/food-summary');
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

            // Ordenar itens por nome dentro de cada grupo
            for (const cat of Object.keys(groups)) {
                groups[cat].sort((a, b) => a.name.localeCompare(b.name));
            }

            // Ordem fixa: protein sempre no topo
            const order = ['protein', 'grain', 'vegetable', 'sauce', 'side', 'other'];
            const sorted = {};
            for (const key of order) {
                if (groups[key]) sorted[key] = groups[key];
            }
            // Demais categorias inesperadas no final
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
                const order = this.orders.find(o => o.id === orderId);
                if (order) order.hidden = true;
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.completing = null;
                setTimeout(() => this.fetchOrders(), 1000);
                setTimeout(() => this.loadFoodSummary(), 1000);
            }
        },

        showMessage(text, type = 'info') {
            this.message = { text, type };
            setTimeout(() => { this.message.text = ''; }, 5000);
        },

        destroy() {
            clearInterval(this.refreshInterval);
        }
    };
}
