function cashierApp() {
    return {
        tableNumber: '',
        menu: [],               // array vindo da API
        categories: [],         // nomes únicos das categorias
        currentCategory: 'all',
        selectedItems: [],
        message: { text: '', type: 'info' },
        loading: true,
        submitting: false,

        async init() {
            try {
                const [menuRes, nextRes] = await Promise.all([
                    fetch('/api/menu'),
                    fetch('/api/orders/next-number')
                ]);
                if (!menuRes.ok) throw new Error('Erro ao carregar cardápio');
                this.menu = await menuRes.json();
                this.categories = [...new Set(this.menu.map(c => c.category_name))];
                if (nextRes.ok) {
                    const data = await nextRes.json();
                    this.tableNumber = String(data.next);
                }
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        // Menu filtrado pela categoria selecionada
        get filteredMenu() {
            if (this.currentCategory === 'all') return this.menu;
            return this.menu.filter(cat => cat.category_name === this.currentCategory);
        },

        // Adiciona item ao pedido
        addItem(item) {
            const existing = this.selectedItems.find(i => i.id === item.id);
            if (existing) {
                existing.quantity++;
            } else {
                this.selectedItems.push({
                    id: item.id,
                    name: item.name,
                    price: parseFloat(item.price),
                    quantity: 1,
                    notes: '',
                    showNotes: false
                });
            }
        },

        // Altera quantidade (mínimo 1)
        changeQuantity(index, delta) {
            const newQty = this.selectedItems[index].quantity + delta;
            if (newQty < 1) {
                this.selectedItems.splice(index, 1);
            } else {
                this.selectedItems[index].quantity = newQty;
            }
        },

        // Remove item
        removeItem(index) {
            this.selectedItems.splice(index, 1);
        },

        // Total do pedido
        get total() {
            return this.selectedItems.reduce((sum, item) => sum + item.price * item.quantity, 0);
        },

        // Exibe mensagens
        showMessage(text, type = 'info') {
            this.message = { text, type };
            setTimeout(() => { this.message.text = ''; }, 5000);
        },

        // Envia o pedido para a API
        async submitOrder() {
            if (!this.tableNumber) {
                this.showMessage('Informe o número da senha!', 'warning');
                return;
            }
            this.submitting = true;
            try {
                const payload = {
                    table: this.tableNumber,
                    items: this.selectedItems.map(i => ({
                        id: i.id,
                        quantity: i.quantity,
                        notes: i.notes
                    }))
                };
                const res = await fetch('/api/orders', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Erro ao enviar pedido');
                }
                this.showMessage(`Pedido #${data.id} enviado com sucesso!`, 'success');
                this.selectedItems = [];
                // Atualiza para o próximo número
                try {
                    const nextRes = await fetch('/api/orders/next-number');
                    if (nextRes.ok) {
                        const nextData = await nextRes.json();
                        this.tableNumber = String(nextData.next);
                    }
                } catch (_) {}
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.submitting = false;
            }
        }
    };
}