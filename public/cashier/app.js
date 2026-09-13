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
        nextUid: 1,             // chave única por linha do pedido (x-for)
        // Monte Seu Prato (spec 030): dish = item do cardápio, quantities = { addonId: qtd },
        // editIndex = índice em selectedItems quando editando uma montagem existente
        builder: { open: false, dish: null, quantities: {}, editIndex: null },
        MAX_ADDON_QTY: 10,
        ADDON_CATEGORY: 'Adicionais',
        FOOD_CATEGORY_LABELS: {
            protein: 'Proteínas', grain: 'Grãos', vegetable: 'Vegetais',
            sauce: 'Molhos', side: 'Acompanhamentos', other: 'Outros'
        },

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
            if (item.is_customizable) {
                this.openBuilder(item);
                return;
            }
            const existing = this.selectedItems.find(i => i.id === item.id && !i.components);
            if (existing) {
                existing.quantity++;
            } else {
                this.selectedItems.push({
                    uid: this.nextUid++,
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

        // ── Monte Seu Prato (spec 030) ──

        // Adicionais disponíveis, agrupados por food_category
        get addonGroups() {
            const category = this.menu.find(c => c.category_name === this.ADDON_CATEGORY);
            const groups = {};
            for (const addon of (category ? category.items : [])) {
                if (addon.available === false || addon.is_customizable) continue;
                const key = addon.food_category || 'other';
                if (!groups[key]) groups[key] = [];
                groups[key].push(addon);
            }
            const order = Object.keys(this.FOOD_CATEGORY_LABELS);
            return Object.keys(groups)
                .sort((a, b) => (order.indexOf(a) + 1 || 99) - (order.indexOf(b) + 1 || 99))
                .map(key => ({ key, label: this.FOOD_CATEGORY_LABELS[key] || 'Outros', items: groups[key] }));
        },

        // Adicionais escolhidos na montagem atual, na ordem do cardápio
        get builderLines() {
            return this.addonGroups
                .flatMap(g => g.items)
                .filter(a => this.builderQty(a.id) > 0)
                .map(a => ({ id: a.id, name: a.name, price: parseFloat(a.price), quantity: this.builderQty(a.id) }));
        },

        get builderCount() {
            return this.builderLines.reduce((sum, l) => sum + l.quantity, 0);
        },

        // Preço de um prato montado: base + Σ(preço do adicional × qtd), somado em centavos
        get builderUnitPrice() {
            if (!this.builder.dish) return 0;
            const cents = this.builderLines.reduce(
                (sum, l) => sum + Math.round(l.price * 100) * l.quantity,
                Math.round(parseFloat(this.builder.dish.price) * 100)
            );
            return cents / 100;
        },

        builderQty(addonId) {
            return this.builder.quantities[addonId] || 0;
        },

        stepAddon(addonId, delta) {
            const qty = Math.min(this.MAX_ADDON_QTY, Math.max(0, this.builderQty(addonId) + delta));
            this.builder.quantities = { ...this.builder.quantities, [addonId]: qty };
        },

        openBuilder(dish, editIndex = null) {
            const quantities = {};
            if (editIndex !== null) {
                for (const c of this.selectedItems[editIndex].components) quantities[c.id] = c.quantity;
            }
            this.builder = {
                open: true,
                dish: { id: dish.id, name: dish.name, price: parseFloat(dish.price), category_name: dish.category_name },
                quantities,
                editIndex
            };
        },

        editBuiltItem(index) {
            const item = this.selectedItems[index];
            this.openBuilder({ id: item.id, name: item.name, price: item.basePrice, category_name: item.category_name }, index);
        },

        closeBuilder() {
            this.builder = { open: false, dish: null, quantities: {}, editIndex: null };
            this.searchQuery = '';
        },

        confirmBuilder() {
            if (!this.builder.dish || this.builderCount === 0) return;
            const components = this.builderLines;
            const price = this.builderUnitPrice;
            if (this.builder.editIndex !== null) {
                const item = this.selectedItems[this.builder.editIndex];
                item.components = components;
                item.price = price;
            } else {
                this.selectedItems.push({
                    uid: this.nextUid++,
                    id: this.builder.dish.id,
                    name: this.builder.dish.name,
                    basePrice: this.builder.dish.price,
                    price,
                    quantity: 1,
                    notes: '',
                    showNotes: false,
                    category_name: this.builder.dish.category_name || '',
                    diningOption: 'local',
                    components
                });
            }
            this.closeBuilder();
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
                        dining_option: i.diningOption,
                        components: i.components
                            ? i.components.map(c => ({ id: c.id, quantity: c.quantity }))
                            : undefined
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