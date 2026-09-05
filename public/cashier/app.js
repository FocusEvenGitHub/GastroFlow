function cashierApp() {
    return {
        orderNumber: '',
        orderNumberAuto: true, // false once the cashier edits the suggested number by hand
        customerName: '',
        menu: [],               // array vindo da API
        categories: [],         // nomes únicos das categorias
        currentCategory: 'all',
        searchQuery: '',
        selectedItems: [],
        toasts: [],
        loading: true,
        submitting: false,
        printTicket: true,
        viewMode: localStorage.getItem('cashierViewMode') || 'grid',
        darkMode: localStorage.getItem('gastroflow_darkMode') === 'true',
        reorderMode: false,
        dragSource: null, // { categoryName, index }
        reordering: false,

        async init() {
            this.applyTheme();
            try {
                const [menuRes, nextRes] = await Promise.all([
                    fetch('/api/menu'),
                    fetch('/api/orders/next-number')
                ]);
                if (!menuRes.ok) throw new Error('Erro ao carregar cardápio');
                this.menu = await menuRes.json();
                this.categories = this.sortPratoDoDiaFirst([...new Set(this.menu.map(c => c.category_name))]);
                this.menu = this.sortMenuPratoDoDiaFirst(this.menu);
                if (nextRes.ok) {
                    const data = await nextRes.json();
                    this.orderNumber = String(data.next);
                    this.orderNumberAuto = true;
                }
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        // Menu filtrado pela categoria selecionada e pela busca por nome
        get filteredMenu() {
            let menu = this.currentCategory === 'all'
                ? this.menu
                : this.menu.filter(cat => cat.category_name === this.currentCategory);

            const query = this.searchQuery.trim().toLowerCase();
            if (!query) return menu;

            return menu
                .map(cat => ({ ...cat, items: cat.items.filter(item => item.name.toLowerCase().includes(query)) }))
                .filter(cat => cat.items.length > 0);
        },

        // Adiciona item ao pedido (padrão: Local)
        addItem(item) {
            if (item.available === false) return;
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
            // Limpa a busca para facilitar a próxima seleção
            this.searchQuery = '';
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

        // Exibe mensagens (toast)
        showMessage(text, type = 'info') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, text, type });
            setTimeout(() => {
                this.toasts = this.toasts.filter(t => t.id !== id);
            }, 5000);
        },

        // Envia o pedido para a API
        async submitOrder() {
            if (!this.orderNumber) {
                this.showMessage('Informe o número da senha!', 'warning');
                return;
            }
            this.submitting = true;
            try {
                const payload = {
                    // Omit order_number when the cashier hasn't edited the suggestion,
                    // so the server auto-assigns it atomically (concurrency-safe) instead
                    // of trusting a value that may have gone stale since the page loaded.
                    order_number: this.orderNumberAuto ? undefined : this.orderNumber,
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
                        this.orderNumber = String(nextData.next);
                        this.orderNumberAuto = true;
                    }
                } catch (_) {}
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.submitting = false;
            }
        },

        toggleView(mode) {
            this.viewMode = mode;
            localStorage.setItem('cashierViewMode', mode);
        },

        toggleReorderMode() {
            this.reorderMode = !this.reorderMode;
        },

        dragStart(category, index) {
            this.dragSource = { categoryName: category.category_name, index };
        },

        async dragDrop(category, targetIndex) {
            if (!this.dragSource || this.dragSource.categoryName !== category.category_name) {
                this.dragSource = null;
                return;
            }
            const sourceIndex = this.dragSource.index;
            this.dragSource = null;
            if (sourceIndex === targetIndex) return;

            const items = category.items;
            const [moved] = items.splice(sourceIndex, 1);
            items.splice(targetIndex, 0, moved);

            this.reordering = true;
            try {
                const res = await fetch('/api/menu/reorder', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        category_name: category.category_name,
                        item_ids: items.map(i => i.id)
                    })
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao reorganizar');
                this.showMessage('Ordem do cardápio atualizada!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.reordering = false;
            }
        },

        applyTheme() {
            document.documentElement.setAttribute('data-theme', this.darkMode ? 'dark' : '');
        },

        toggleDarkMode() {
            this.darkMode = !this.darkMode;
            localStorage.setItem('gastroflow_darkMode', this.darkMode);
            this.applyTheme();
        },

        // Move "Prato do Dia" para o início do array
        sortPratoDoDiaFirst(arr) {
            const idx = arr.indexOf('Prato do Dia');
            if (idx > 0) { const item = arr.splice(idx, 1)[0]; arr.unshift(item); }
            return arr;
        },

        sortMenuPratoDoDiaFirst(menu) {
            const prato = menu.find(c => c.category_name === 'Prato do Dia');
            const others = menu.filter(c => c.category_name !== 'Prato do Dia');
            return prato ? [prato, ...others] : menu;
        }
    };
}