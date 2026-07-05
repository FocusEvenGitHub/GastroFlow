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

            // Mapeamento: nome da categoria → prioridade (menor = primeiro)
            const priority = {
                'Carnes': 1,
                'Proteínas': 2,
                'Frituras': 3,
                'Grãos / Acompanhamentos': 4,
                'Vegetais': 5,
                'Frutas': 6,
                'Laticínios': 7,
                'Molhos': 8,
            };

            const groups = this.ingredientSummary.reduce((acc, ing) => {
                const cat = ing.category && ing.category.trim() !== '' ? ing.category : 'Outros';
                if (!acc[cat]) acc[cat] = [];
                acc[cat].push(ing);
                return acc;
            }, {});

            // Ordenar chaves pela prioridade definida, depois alfabeticamente
            const sortedKeys = Object.keys(groups).sort((a, b) => {
                const pA = priority[a] ?? 99;
                const pB = priority[b] ?? 99;
                return pA - pB || a.localeCompare(b);
            });

            const ordered = {};
            sortedKeys.forEach(k => { ordered[k] = groups[k]; });
            return ordered;
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