function adminApp() {
    return GFAdmin.page({
        // Login fica só aqui (spec 051); as outras páginas mandam para cá com ?next=.
        loggedIn: !!GFAdmin.token,
        loginForm: { username: '', password: '' },
        logging: false,
        loginError: '',

        menu: [],
        categories: [],
        searchQuery: '',
        categoryFilter: 'all',
        loading: true,
        newItem: { name: '', price: '', category_name: '', description: '' },
        newItemOpen: false,
        saving: false,
        viewMode: GFAdmin.read('adminViewMode') || 'grid',

        editingItem: null,
        editForm: { name: '', price: '', category_name: '', description: '' },
        editComponents: [],
        availableComponents: [],
        tomSelect: null,

        init() {
            this.applyTheme();
            if (this.loggedIn) {
                if (this.goToNext()) return;
                this.loadMenu();
            }
        },

        // Volta para a página que pediu o login, se for uma página do Admin.
        goToNext() {
            const next = GFAdmin.safeNext(new URLSearchParams(location.search).get('next'));
            if (!next) return false;
            location.href = next;
            return true;
        },

        async doLogin() {
            this.logging = true;
            this.loginError = '';
            try {
                await GFAdmin.login(this.loginForm.username, this.loginForm.password);
                this.username = GFAdmin.username;
                this.role = GFAdmin.role;
                this.loginForm = { username: '', password: '' };
                this.loggedIn = true;
                if (this.goToNext()) return;
                history.replaceState(null, '', '/admin/');
                this.loadMenu();
            } catch (err) {
                this.loginError = err.message;
            } finally {
                this.logging = false;
            }
        },

        async loadMenu() {
            this.loading = true;
            try {
                this.menu = await this.api('/api/admin/menu');
                this.categories = this.sortPratoDoDiaFirst([...new Set(this.menu.map(c => c.category_name))]);
                this.menu = this.sortMenuPratoDoDiaFirst(this.menu);
                const adicionais = this.menu.find(c => c.category_name === 'Adicionais');
                this.availableComponents = adicionais ? adicionais.items : [];
                if (this.categoryFilter !== 'all' && !this.categories.includes(this.categoryFilter)) {
                    this.categoryFilter = 'all';
                }
            } catch (err) {
                this.handleError(err);
            } finally {
                this.loading = false;
            }
        },

        get totalItems() {
            return this.menu.reduce((sum, cat) => sum + cat.items.length, 0);
        },

        // Menu filtrado pela categoria escolhida e pela busca por nome
        get filteredMenu() {
            const query = this.searchQuery.trim().toLowerCase();
            return this.menu
                .filter(cat => this.categoryFilter === 'all' || cat.category_name === this.categoryFilter)
                .map(cat => query
                    ? { ...cat, items: cat.items.filter(item => item.name.toLowerCase().includes(query)) }
                    : cat)
                .filter(cat => cat.items.length > 0);
        },

        openNewItem() {
            this.newItem = {
                name: '',
                price: '',
                category_name: this.categoryFilter !== 'all' ? this.categoryFilter : '',
                description: ''
            };
            this.newItemOpen = true;
        },

        async addItem() {
            if (!this.newItem.name || !this.newItem.price || !this.newItem.category_name) {
                this.showMessage('Preencha todos os campos obrigatórios.', 'warning');
                return;
            }
            this.saving = true;
            try {
                await this.api('/api/admin/items', {
                    method: 'POST',
                    json: { ...this.newItem, price: parseFloat(this.newItem.price) }
                });
                this.showMessage('Item adicionado!', 'success');
                this.newItemOpen = false;
                this.loadMenu();
            } catch (err) {
                this.handleError(err);
            } finally {
                this.saving = false;
            }
        },

        startEdit(item) {
            this.editingItem = item;
            this.editForm = {
                name: item.name,
                price: item.price,
                category_name: item.category_name,
                description: item.description || ''
            };
            this.editComponents = (item.components || []).map(c => ({ ...c }));

            this.$nextTick(() => {
                setTimeout(() => this.initTomSelect(), 100);
            });
        },

        initTomSelect() {
            if (this.tomSelect) this.tomSelect.destroy();

            const el = document.getElementById('component-select');
            if (!el) return;

            this.tomSelect = new TomSelect(el, {
                placeholder: 'Buscar adicionais...',
                maxItems: null,
                onChange: (values) => {
                    const selected = (values || []).map(Number);
                    const current = this.editComponents.map(c => c.id);
                    const toRemove = current.filter(id => !selected.includes(id));
                    const toAdd = selected.filter(id => !current.includes(id));

                    toRemove.forEach(id => {
                        const idx = this.editComponents.findIndex(c => c.id === id);
                        if (idx >= 0) this.editComponents.splice(idx, 1);
                    });

                    toAdd.forEach(id => {
                        const comp = this.availableComponents.find(c => c.id === id);
                        if (comp) {
                            this.editComponents.push({ id: comp.id, name: comp.name, quantity: 1 });
                        }
                    });
                }
            });

            const selectedIds = this.editComponents.map(c => String(c.id));
            if (selectedIds.length > 0) {
                this.tomSelect.setValue(selectedIds);
            }
        },

        cancelEdit() {
            if (this.tomSelect) {
                this.tomSelect.destroy();
                this.tomSelect = null;
            }
            this.editingItem = null;
            this.editForm = { name: '', price: '', category_name: '', description: '' };
            this.editComponents = [];
        },

        isComponentSelected(compId) {
            return this.editComponents.some(c => c.id === compId);
        },

        setComponentQty(compId, qty) {
            const c = this.editComponents.find(c => c.id === compId);
            if (c) c.quantity = Math.max(1, parseInt(qty) || 1);
        },

        async updateItem() {
            if (!this.editForm.name || !this.editForm.price || !this.editForm.category_name) {
                this.showMessage('Preencha todos os campos obrigatórios.', 'warning');
                return;
            }
            this.saving = true;
            try {
                await this.api(`/api/admin/items/${this.editingItem.id}`, {
                    method: 'PATCH',
                    json: { ...this.editForm, price: parseFloat(this.editForm.price) }
                });

                await this.saveComponents(this.editingItem.id);

                this.showMessage('Item atualizado!', 'success');
                this.cancelEdit();
                this.loadMenu();
            } catch (err) {
                this.handleError(err);
            } finally {
                this.saving = false;
            }
        },

        async saveComponents(dishId) {
            if (this.editForm.category_name !== 'Pratos Principais') return;
            await this.api(`/api/admin/items/${dishId}/components`, {
                method: 'PUT',
                json: { components: this.editComponents }
            });
        },

        async toggleAvailability(itemId, newAvailable) {
            try {
                await this.api(`/api/admin/items/${itemId}`, {
                    method: 'PATCH',
                    json: { available: newAvailable }
                });
                const cat = this.menu.find(c => c.items.some(i => i.id === itemId));
                if (cat) {
                    const item = cat.items.find(i => i.id === itemId);
                    if (item) item.available = newAvailable;
                }
                this.showMessage(`Item ${newAvailable ? 'ativado' : 'desativado'}!`, 'success');
            } catch (err) {
                this.handleError(err);
            }
        },

        async confirmDelete(item) {
            const ok = await this.askConfirm(`Tem certeza que deseja excluir "${item.name}"? Esta ação não pode ser desfeita.`);
            if (ok) this.deleteItem(item.id);
        },

        async deleteItem(itemId) {
            try {
                await this.api(`/api/admin/items/${itemId}`, { method: 'DELETE' });
                this.showMessage('Item excluído com sucesso!', 'success');
                this.loadMenu();
            } catch (err) {
                this.handleError(err);
            }
        },

        toggleView(mode) {
            this.viewMode = mode;
            localStorage.setItem('adminViewMode', mode);
        },

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
    });
}
