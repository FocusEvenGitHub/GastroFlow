function kitchenApp() {
    return {
        orders: [],
        loading: true,
        message: { text: '', type: 'info' },
        completing: null,  // id do pedido sendo finalizado
        refreshInterval: null,

        async init() {
            await this.fetchOrders();
            // Atualiza automaticamente a cada 5 segundos
            this.refreshInterval = setInterval(() => this.fetchOrders(), 5000);
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
        },

        async completeOrder(orderId) {
            this.completing = orderId;
            try {
                const res = await fetch(`/api/orders/${orderId}/complete`, { method: 'POST' });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao finalizar');
                this.showMessage(`Pedido #${orderId} finalizado!`, 'success');
                // Remove o pedido da lista com animação
                const order = this.orders.find(o => o.id === orderId);
                if (order) order.hidden = true;  // dispara o x-show e transição
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.completing = null;
                // Recarrega lista após um curto delay
                setTimeout(() => this.fetchOrders(), 1000);
            }
        },

        showMessage(text, type = 'info') {
            this.message = { text, type };
            setTimeout(() => { this.message.text = ''; }, 5000);
        },

        // Limpa o intervalo quando a página é fechada (boas práticas)
        destroy() {
            clearInterval(this.refreshInterval);
        }
    };
}