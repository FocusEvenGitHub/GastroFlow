function kitchenApp() {
    return {
        orders: [],
        ingredientSummary: [],
        loading: true,
        message: { text: '', type: 'info' },
        completing: null,
        refreshInterval: null,

        async init() {
            await this.fetchAll();
            this.refreshInterval = setInterval(() => this.fetchAll(), 5000);
        },

        async fetchAll() {
            try {
                const [ordersRes, ingredientsRes] = await Promise.all([
                    fetch('/api/orders?status=pending'),
                    fetch('/api/kitchen/ingredients-summary')
                ]);
                if (!ordersRes.ok) throw new Error('Erro ao buscar pedidos');
                if (!ingredientsRes.ok) throw new Error('Erro ao buscar ingredientes');

                this.orders = await ordersRes.json();
                this.ingredientSummary = await ingredientsRes.json().then(d => d.ingredients);
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        async refresh() {
            this.loading = true;
            await this.fetchAll();
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
                setTimeout(() => this.fetchAll(), 1500);
            }
        },

        // Group ingredients by their category
        get groupedIngredients() {
            if (!this.ingredientSummary.length) return {};
            const categoryOrder = ['meat', 'protein', 'grain', 'vegetable', 'fruit', 'dairy', 'sauce'];
            const groups = this.ingredientSummary.reduce((acc, ing) => {
                const cat = ing.category || 'outros';
                if (!acc[cat]) acc[cat] = [];
                acc[cat].push(ing);
                return acc;
            }, {});
            // Reorder keys according to categoryOrder, then append anything else alphabetically
            const ordered = {};
            categoryOrder.forEach(cat => {
                if (groups[cat]) ordered[cat] = groups[cat];
            });
            Object.keys(groups).sort().forEach(cat => {
                if (!ordered[cat]) ordered[cat] = groups[cat];
            });
            return ordered;
        },

        // Optional: translate category names for display
        translateCategory(category) {
            const map = {
                meat: 'Carnes / Proteínas',
                grain: 'Acompanhamentos / Grãos',
                vegetable: 'Vegetais',
                fruit: 'Frutas',
                dairy: 'Laticínios',
                sauce: 'Molhos',
                protein: 'Proteínas',
                outros: 'Outros'
            };
            return map[category] || category;
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