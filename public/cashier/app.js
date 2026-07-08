function cashierApp() {
    return {
        tableNumber: '',
        customerName: '',
        menu: [],               // array vindo da API
        categories: [],         // nomes únicos das categorias
        currentCategory: 'all',
        selectedItems: [],
        message: { text: '', type: 'info' },
        loading: true,
        submitting: false,
        printTicket: true,

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

        // Adiciona item ao pedido (padrão: Local)
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
                    showNotes: false,
                    category_name: item.category_name || '',
                    diningOption: 'local'
                });
            }
        },

        // Altera a opção de onde comer (local / viagem_simples / viagem_vip)
        setDiningOption(index, option) {
            if (this.selectedItems[index]) {
                this.selectedItems[index].diningOption = option;
            }
        },

        // Custo da embalagem para um item
        packagingCost(item) {
            if (item.diningOption === 'viagem_simples') return 1.0;
            if (item.diningOption === 'viagem_vip') return 2.0;
            return 0;
        },

        // Total do item incluindo embalagem
        itemTotal(index) {
            const item = this.selectedItems[index];
            if (!item) return 0;
            const base = item.price * item.quantity;
            const packing = this.packagingCost(item) * item.quantity;
            return base + packing;
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

        // Total do pedido (inclui custo de embalagem)
        get total() {
            return this.selectedItems.reduce((sum, item) => {
                let itemTotal = item.price * item.quantity;
                if (item.diningOption === 'viagem_simples') {
                    itemTotal += 1.0 * item.quantity;
                } else if (item.diningOption === 'viagem_vip') {
                    itemTotal += 2.0 * item.quantity;
                }
                return sum + itemTotal;
            }, 0);
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
                    customer_name: this.customerName || undefined,
                    print_ticket: this.printTicket,
                    items: this.selectedItems.map(i => ({
                        id: i.id,
                        quantity: i.quantity,
                        notes: i.notes,
                        dining_option: i.diningOption
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
                this.customerName = '';
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