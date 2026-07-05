function adminApp() {
    return {
        // Auth state
        loggedIn: false,
        token: localStorage.getItem('admin_token') || '',
        username: localStorage.getItem('admin_username') || '',
        loginForm: { username: '', password: '' },
        logging: false,
        loginError: '',

        // App state
        menu: [],
        categories: [],
        loading: true,
        newItem: { name: '', price: '', category_name: '', description: '' },
        saving: false,
        message: { text: '', type: 'info' },

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
                if (res.status === 401) {
                    this.logout();
                    return;
                }
                if (!res.ok) throw new Error('Erro ao carregar cardápio');
                this.menu = await res.json();
                this.categories = [...new Set(this.menu.map(c => c.category_name))];
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
                // Atualiza localmente
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