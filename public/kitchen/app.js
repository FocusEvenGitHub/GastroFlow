/**
 * Data de hoje no fuso do navegador, como YYYY-MM-DD.
 *
 * Não use toISOString(): ela converte para UTC, e em UTC-3 qualquer horário a partir
 * das 21:00 locais já caiu no dia seguinte — a cozinha passava a pedir os pedidos de
 * amanhã e mostrava a tela vazia (spec 036).
 */
function localDateString(date = new Date()) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function kitchenApp() {
    return {
        ...GF.ui(), // toasts, showMessage, tema (spec 056)
        orders: [],
        completedOrders: [],
        loading: true,
        completing: null,
        uncompleting: null,
        showDone: true,
        viewMode: localStorage.getItem('kitchenViewMode') || 'list',
        eventSource: null,
        foodSummary: [],
        selectedDate: localDateString(),
        // Estado da impressora (spec 039)
        printerBlocked: false,
        printerFailures: 0,
        lastPrintFailureSeen: null,
        reactivatingPrinter: false,
        editingOrder: null,
        savingOrder: false,
        reprinting: null,
        menu: [],
        newItemId: '',
        newItemQty: 1,
        addingItem: false,
        removingItemId: null,
        // Desconto por item (spec 058): só no modal "Editar Pedido" — o quadro e o
        // resumo da cozinha nunca mostram desconto. Teto do Admin em centésimos de %.
        maxDiscount: 10000,
        KITCHEN_CATEGORIES: ['Pratos Principais', 'Adicionais'],
        // 'ingredients' | 'dishes' — qual resumo mostrar no painel lateral (spec 030)
        summaryMode: localStorage.getItem('kitchenSummaryMode') === 'dishes' ? 'dishes' : 'ingredients',

        _today() {
            return localDateString();
        },

        async init() {
            this.applyTheme();
            // Ao voltar a conexão, recarrega tudo: um EventSource novo (connectSSE) não
            // reenvia Last-Event-ID, então eventos da queda só chegam pelo fetch (spec 057).
            this.startConnectionWatch(() => {
                this.fetchAll();
                if (!this.menu.length) this.loadMenu();
            });
            await Promise.all([this.fetchAll(), this.loadMenu()]);
            this.connectSSE();
            this.startPrinterWatch();
        },

        // Polling, e não SSE: o worker de impressão roda em outro container, e o SSE atual
        // lê um arquivo em sys_get_temp_dir() — o evento escrito pelo worker seria invisível
        // para o container que serve o stream. A tabela jobs é o único estado que os dois
        // enxergam, e é o que este endpoint consulta (spec 039).
        startPrinterWatch() {
            this.refreshPrinterStatus();
            setInterval(() => this.refreshPrinterStatus(), 15000);
        },

        async refreshPrinterStatus() {
            try {
                const status = await GF.api('/api/printer/status');

                // Avisa a cada falha nova, identificando o pedido afetado.
                if (status.last_failed_order_id && status.last_failed_order_id !== this.lastPrintFailureSeen) {
                    this.lastPrintFailureSeen = status.last_failed_order_id;
                    this.showMessage(`Erro ao imprimir o pedido #${status.last_failed_order_id}`, 'danger');
                }
                if (!status.last_failed_order_id) {
                    this.lastPrintFailureSeen = null;
                }

                this.printerBlocked = status.blocked;
                this.printerFailures = status.consecutive_failures;
            } catch (err) {
                // Rede instável não deve poluir a tela da cozinha com toasts.
                console.error('Erro ao consultar status da impressora:', err);
            }
        },

        async reactivatePrinting() {
            this.reactivatingPrinter = true;
            try {
                await GF.api('/api/printer/reset', { method: 'POST' });
                await this.refreshPrinterStatus();
                this.showMessage('Impressão reativada', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.reactivatingPrinter = false;
            }
        },

        async loadMenu() {
            try {
                this.menu = await GF.api('/api/menu');
            } catch (err) {
                console.error('Erro ao carregar cardápio:', err);
            }
        },

        // Título do botão de opção de viagem com o preço da embalagem vinculada no
        // Admin (spec 050); mesmo fallback do PricingService.
        packagingTitle(label, option) {
            const linked = this.menu.flatMap(cat => cat.items || []).find(i => i.packaging_option === option);
            const fee = linked ? parseFloat(linked.price) : { viagem_simples: 1.0, viagem_vip: 2.0 }[option];
            return `${label} (+R$ ${fee.toFixed(2).replace('.', ',')})`;
        },

        // Cardápio achatado (sem agrupar por categoria) para o seletor "Adicionar item".
        // Pratos montáveis ficam de fora: só o Caixa escolhe os adicionais (spec 030).
        allMenuItems() {
            return this.menu.flatMap(cat => (cat.items || []).filter(i => !i.is_customizable).map(i => ({
                id: i.id,
                name: i.name,
                category_name: cat.category_name
            })));
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
            this.eventSource.onopen = () => this.setStreamUp(true);

            this.eventSource.addEventListener('order.created', () => {
                if (this.selectedDate === this._today()) this.fetchAll();
            });

            this.eventSource.addEventListener('order.completed', () => {
                if (this.selectedDate === this._today()) this.fetchAll();
            });

            this.eventSource.addEventListener('order.uncompleted', () => {
                if (this.selectedDate === this._today()) this.fetchAll();
            });

            this.eventSource.addEventListener('order.updated', () => {
                if (this.selectedDate === this._today()) this.fetchAll();
            });

            // order.cancelled replaced order.deleted when hard-delete was
            // removed in favor of soft-cancel (spec 020) — this listener was
            // never updated then (code review fix).
            this.eventSource.addEventListener('order.cancelled', () => {
                if (this.selectedDate === this._today()) this.fetchAll();
            });

            this.eventSource.onerror = () => {
                // Stream caído = cozinha nunca aparece "Conectado" (spec 057).
                this.setStreamUp(false);
                // Erro de rede: o EventSource reconecta sozinho. CLOSED (resposta HTTP de
                // erro) ele abandona — reabrimos no ritmo do heartbeat, não após 30 s, para
                // o indicador voltar a "Conectado" junto com o servidor (spec 057).
                setTimeout(() => {
                    if (this.eventSource && this.eventSource.readyState === EventSource.CLOSED) {
                        console.log('SSE: tentando reconectar…');
                        this.connectSSE();
                    }
                }, GF.HEARTBEAT_MS);
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
                this.orders = await GF.api('/api/orders?status=pending&date=' + this.selectedDate);
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        async fetchCompletedOrders() {
            try {
                this.completedOrders = await GF.api('/api/orders?status=done&date=' + this.selectedDate);
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
                const data = await GF.api('/api/kitchen/food-summary?date=' + this.selectedDate);
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

        setSummaryMode(mode) {
            this.summaryMode = mode;
            localStorage.setItem('kitchenSummaryMode', mode);
        },

        // Resumo de pratos (spec 030): pratos principais dos pedidos pendentes da
        // data selecionada, somados por nome. Pratos montados são detalhados por
        // combinação de adicionais.
        dishSummary() {
            const byName = {};
            let total = 0;
            for (const order of this.orders) {
                for (const item of (order.items || [])) {
                    if (item.category_name !== 'Pratos Principais') continue;
                    const qty = Number(item.quantity) || 0;
                    total += qty;
                    if (!byName[item.name]) byName[item.name] = { name: item.name, quantity: 0, variants: {} };
                    const dish = byName[item.name];
                    dish.quantity += qty;
                    if (item.components && item.components.length) {
                        const label = [...item.components]
                            .sort((a, b) => a.name.localeCompare(b.name))
                            .map(c => c.quantity + 'x ' + c.name)
                            .join(', ');
                        dish.variants[label] = (dish.variants[label] || 0) + qty;
                    }
                }
            }
            const dishes = Object.values(byName)
                .map(d => ({
                    name: d.name,
                    quantity: d.quantity,
                    variants: Object.entries(d.variants)
                        .map(([label, quantity]) => ({ label, quantity }))
                        .sort((a, b) => b.quantity - a.quantity)
                }))
                .sort((a, b) => b.quantity - a.quantity || a.name.localeCompare(b.name));
            return { total, dishes };
        },

        async completeOrder(orderId) {
            this.completing = orderId;
            try {
                await GF.api(`/api/orders/${orderId}/complete`, { method: 'POST' });
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
                await GF.api(`/api/orders/${orderId}/uncomplete`, { method: 'POST' });
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

        // Itens que a cozinha precisa preparar (prato principal + adicionais)
        kitchenItems(order) {
            return (order.items || []).filter(i => this.KITCHEN_CATEGORIES.includes(i.category_name));
        },

        openEditModal(order) {
            // Cópia profunda: edições só afetam o servidor ao salvar/remover explicitamente.
            const copy = JSON.parse(JSON.stringify(order));
            copy.items.forEach(item => this.prepareDiscount(item));
            this.editingOrder = copy;
            this.newItemId = '';
            this.newItemQty = 1;
            // Preços atuais do Admin (títulos Simples/VIP, seletor de itens) — spec 052.
            this.loadMenu();
            GF.discount.loadMax().then(max => { this.maxDiscount = max; }).catch(() => {});
        },

        // ── Desconto por item no "Editar Pedido" (spec 058) ──
        // item.discountDraft = { type, valueText } | null é o desconto como ficará ao salvar;
        // discountDirty diz se a cozinha mexeu nele (só então vai no PATCH, e só então é
        // auditado); discountEdit é o painel aberto dentro do card do item.

        prepareDiscount(item) {
            const d = item.discount;
            item.discountDraft = d ? { type: d.type, valueText: GF.discount.rate(Math.round(Number(d.value) * 100)) } : null;
            item.discountDirty = false;
            item.discountEdit = { open: false, type: 'percent', valueText: '' };
        },

        kLineCents(item) {
            return Math.round(Number(item.unit_price || 0) * 100) * item.quantity;
        },

        // { amount, error } do desconto como ficará ao salvar. Sem a cozinha mexer, o
        // servidor só reajusta (% reaplicada, R$ limitado ao novo valor) — sem erro.
        kDiscountState(item) {
            const d = item.discountDraft;
            if (!d) return null;
            const line = this.kLineCents(item);
            if (!item.discountDirty) {
                const value = GF.discount.parse(d.valueText) || 0;
                const raw = d.type === 'percent' ? GF.discount.percentOf(line, value) : value;
                return { amount: Math.min(raw, line), error: null };
            }
            return GF.discount.evaluate(d.type, d.valueText, line, this.maxDiscount);
        },

        kDiscountSummary(item) {
            const state = this.kDiscountState(item);
            if (!state) return 'Sem desconto';
            if (state.error) return 'Revise o desconto';
            return `${GF.discount.badge(item.discountDraft.type, item.discountDraft.valueText)} (−R$ ${GF.discount.money(state.amount)})`;
        },

        kDiscountInvalid(item) {
            const state = this.kDiscountState(item);
            return !!state && !!state.error;
        },

        kDiscountPreview(item) {
            const line = this.kLineCents(item);
            const edit = item.discountEdit;
            const typed = edit.valueText.trim() !== '';
            const result = GF.discount.evaluate(edit.type, edit.valueText, line, this.maxDiscount);
            return { line, amount: result.error ? 0 : result.amount, error: result.error, typed };
        },

        kMoney(cents) {
            return GF.discount.money(cents);
        },

        openKitchenDiscount(item) {
            const d = item.discountDraft;
            item.discountEdit = { open: true, type: d ? d.type : 'percent', valueText: d ? d.valueText : '' };
        },

        closeKitchenDiscount(item) {
            item.discountEdit = { open: false, type: 'percent', valueText: '' };
        },

        setKitchenDiscountType(item, type) {
            if (item.discountEdit.type === type) return;
            item.discountEdit.type = type;
            item.discountEdit.valueText = '';
        },

        applyKitchenDiscount(item) {
            if (this.kDiscountPreview(item).error) return;
            item.discountDraft = { type: item.discountEdit.type, valueText: item.discountEdit.valueText.trim() };
            item.discountDirty = true;
            this.closeKitchenDiscount(item);
        },

        removeKitchenDiscount(item) {
            item.discountDraft = null;
            item.discountDirty = true;
            this.closeKitchenDiscount(item);
        },

        closeEditModal() {
            this.editingOrder = null;
        },

        async addItemToModal() {
            if (!this.editingOrder || !this.newItemId || this.addingItem) return;
            this.addingItem = true;
            try {
                const data = await GF.api(`/api/orders/${this.editingOrder.id}/items`, {
                    method: 'POST',
                    json: { menu_item_id: this.newItemId, quantity: this.newItemQty || 1 }
                });
                this.prepareDiscount(data.item);
                this.editingOrder.items.push(data.item);
                this.newItemId = '';
                this.newItemQty = 1;
                this.showMessage('Item adicionado!', 'success');
                await this.fetchAll();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.addingItem = false;
            }
        },

        async removeItemFromModal(itemId) {
            if (!this.editingOrder || this.removingItemId !== null) return;
            if (this.editingOrder.items.length <= 1) {
                this.showMessage('Não é possível remover o último item. Exclua o pedido inteiro.', 'warning');
                return;
            }
            this.removingItemId = itemId;
            try {
                await GF.api(`/api/orders/${this.editingOrder.id}/items/${itemId}`, { method: 'DELETE' });
                this.editingOrder.items = this.editingOrder.items.filter(i => i.item_id !== itemId);
                this.showMessage('Item removido!', 'success');
                await this.fetchAll();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.removingItemId = null;
            }
        },

        async saveOrderChanges() {
            if (!this.editingOrder) return;
            if (this.editingOrder.items.some(item => this.kDiscountInvalid(item))) {
                this.showMessage('Revise o desconto marcado em vermelho antes de salvar.', 'warning');
                return;
            }
            this.savingOrder = true;
            try {
                await GF.api(`/api/orders/${this.editingOrder.id}`, {
                    method: 'PATCH',
                    json: {
                        order_number: this.editingOrder.order_number,
                        customer_name: this.editingOrder.customer_name
                    }
                });

                for (const item of this.editingOrder.items) {
                    const json = { quantity: item.quantity, notes: item.notes, dining_option: item.dining_option };
                    // Só manda o desconto quando a cozinha mexeu nele (spec 058): sem o campo,
                    // o servidor mantém e reajusta o desconto existente.
                    if (item.discountDirty) {
                        json.discount = item.discountDraft
                            ? GF.discount.payload(item.discountDraft.type, item.discountDraft.valueText)
                            : null;
                    }
                    await GF.api(`/api/orders/${this.editingOrder.id}/items/${item.item_id}`, { method: 'PATCH', json });
                }

                this.showMessage('Pedido atualizado!', 'success');
                this.editingOrder = null;
                await this.fetchAll();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.savingOrder = false;
            }
        },

        async cancelOrder() {
            if (!this.editingOrder) return;
            if (!confirm(`Cancelar o pedido #${this.editingOrder.id}? Ele deixará de aparecer na cozinha, mas o registro é mantido.`)) return;
            this.savingOrder = true;
            try {
                await GF.api(`/api/orders/${this.editingOrder.id}/cancel`, { method: 'POST' });
                this.showMessage('Pedido cancelado!', 'success');
                this.editingOrder = null;
                await this.fetchAll();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.savingOrder = false;
            }
        },

        async reprintOrder(orderId) {
            if (this.printerBlocked) return;

            this.reprinting = orderId;
            try {
                await GF.api(`/api/orders/${orderId}/print`, { method: 'POST' });
                // O endpoint só enfileira — ele retorna antes de qualquer byte chegar na
                // impressora. Dizer "impresso" aqui era afirmar o que não se sabe: com a
                // impressora fora do ar o operador via sucesso e nada nunca o corrigia.
                // Falhas aparecem no visualizador de Logs do Admin e em bin/jobs-status (spec 038).
                this.showMessage(`Pedido #${orderId} na fila de impressão`, 'info');
            } catch (err) {
                // O servidor também bloqueia (spec 039): se o estado local estiver
                // defasado, a resposta corrige a tela.
                if (err.code === 'PRINTING_BLOCKED') this.printerBlocked = true;
                this.showMessage(err.message, 'danger');
            } finally {
                this.reprinting = null;
            }
        },

        // Retorna o nome do cliente (se houver) ou "Pedido #N" como fallback
        displayName(order) {
            if (order.customer_name && typeof order.customer_name === 'string' && order.customer_name.trim() !== '') {
                return order.customer_name.trim();
            }
            return 'Pedido #' + order.id;
        },

        // Recebe o pedido inteiro: usa created_at_iso (com offset do servidor). O antigo
        // created_at + 'Z' tratava horário local (UTC-3) como UTC e somava 3h (spec 031).
        timeAgo(order) {
            const iso = order && order.created_at_iso;
            if (!iso) return '';
            const date = new Date(iso);
            const now = new Date();
            const diffMs = now - date;
            const diffMin = Math.floor(diffMs / 60000);
            if (diffMin < 1) return 'agora';
            if (diffMin < 60) return diffMin + 'min';
            const diffH = Math.floor(diffMin / 60);
            const remM = diffMin % 60;
            return diffH + 'h' + (remM > 0 ? remM + 'm' : '');
        },

        toggleView(mode) {
            this.viewMode = mode;
            localStorage.setItem('kitchenViewMode', mode);
        },

        destroy() {
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }
        }
    };
}
