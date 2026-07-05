function adminApp() {
    return {
        loggedIn: false,
        token: localStorage.getItem('admin_token') || '',
        username: localStorage.getItem('admin_username') || '',
        loginForm: { username: '', password: '' },
        logging: false,
        loginError: '',

        menu: [],
        categories: [],
        loading: true,
        newItem: { name: '', price: '', category_name: '', description: '' },
        saving: false,
        message: { text: '', type: 'info' },

        editingItem: null,
        editForm: { name: '', price: '', category_name: '', description: '' },
        editComponents: [],
        availableComponents: [],
        tomSelect: null,

        init() {
            if (this.token) {
                this.loggedIn = true;
                this.loadMenu();
            }
        },

        async doLogin() {
            this.logging = true;
            this.loginError = '';
            try {
                const res = await fetch('/api/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.loginForm)
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Falha na autenticação');
                this.token = data.token;
                this.username = data.user.username;
                localStorage.setItem('admin_token', this.token);
                localStorage.setItem('admin_username', this.username);
                this.loggedIn = true;
                this.loadMenu();
            } catch (err) {
                this.loginError = err.message;
            } finally {
                this.logging = false;
            }
        },

        logout() {
            this.token = '';
            this.username = '';
            localStorage.removeItem('admin_token');
            localStorage.removeItem('admin_username');
            this.loggedIn = false;
            this.menu = [];
            this.categories = [];
        },

        async loadMenu() {
            this.loading = true;
            try {
                const res = await fetch('/api/admin/menu', {
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (res.status === 401) { this.logout(); return; }
                if (!res.ok) throw new Error('Erro ao carregar cardápio');
                this.menu = await res.json();
                this.categories = [...new Set(this.menu.map(c => c.category_name))];
                const adicionais = this.menu.find(c => c.category_name === 'Adicionais');
                this.availableComponents = adicionais ? adicionais.items : [];
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        async addItem() {
            if (!this.newItem.name || !this.newItem.price || !this.newItem.category_name) {
                this.showMessage('Preencha todos os campos obrigatórios.', 'warning');
                return;
            }
            this.saving = true;
            try {
                const res = await fetch('/api/admin/items', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify({...this.newItem, price: parseFloat(this.newItem.price)})
                });
                const data = await res.json();
                if (res.status === 401) { this.logout(); return; }
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao adicionar');
                this.showMessage('Item adicionado!', 'success');
                this.newItem = { name: '', price: '', category_name: '', description: '' };
                this.loadMenu();
            } catch (err) {
                this.showMessage(err.message, 'danger');
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
                const res = await fetch(`/api/admin/items/${this.editingItem.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify({
                        ...this.editForm,
                        price: parseFloat(this.editForm.price)
                    })
                });
                if (res.status === 401) { this.logout(); return; }
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao atualizar');

                await this.saveComponents(this.editingItem.id);

                this.showMessage('Item atualizado!', 'success');
                this.cancelEdit();
                this.loadMenu();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.saving = false;
            }
        },

        async saveComponents(dishId) {
            if (this.editForm.category_name !== 'Pratos Principais') return;
            const res = await fetch(`/api/admin/items/${dishId}/components`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.token}`
                },
                body: JSON.stringify({ components: this.editComponents })
            });
            if (res.status === 401) { this.logout(); return; }
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || 'Erro ao salvar componentes');
        },

        async toggleAvailability(itemId, newAvailable) {
            try {
                const res = await fetch(`/api/admin/items/${itemId}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify({ available: newAvailable })
                });
                if (res.status === 401) { this.logout(); return; }
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Erro');
                const cat = this.menu.find(c => c.items.some(i => i.id === itemId));
                if (cat) {
                    const item = cat.items.find(i => i.id === itemId);
                    if (item) item.available = newAvailable;
                }
                this.showMessage(`Item ${newAvailable ? 'ativado' : 'desativado'}!`, 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        showMessage(text, type = 'info') {
            this.message = { text, type };
            setTimeout(() => { this.message.text = ''; }, 4000);
        }
    };
}
