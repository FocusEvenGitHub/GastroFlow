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

        // Recipe modal state
        selectedDish: null,
        showRecipeModal: false,
        allIngredients: [],
        recipeIngredients: [],
        recipeLoading: false,
        newItemIngredients: [],

        init() {
            if (this.token) {
                this.loggedIn = true;
                this.loadMenu();
                this.loadAllIngredients();
            }
        },

        loadAllIngredients() {
            fetch('/api/admin/ingredients', {
                headers: { 'Authorization': `Bearer ${this.token}` }
            })
                .then(res => res.json())
                .then(data => { this.allIngredients = data; })
                .catch(() => {});
        },

        // métodos de manipulação de ingredientes no formulário:
        addNewIngredient() {
            this.newItemIngredients.push({ id: null, quantity: 1, searchText: '', showDropdown: false });
        },
        removeNewIngredient(index) {
            this.newItemIngredients.splice(index, 1);
        },

        // modificar addItem() para enviar ingredients
        async addItem() {
            if (!this.newItem.name || !this.newItem.price || !this.newItem.category_name) {
                this.showMessage('Preencha todos os campos obrigatórios.', 'warning');
                return;
            }
            if (this.newItemIngredients.length === 0) {
                this.showMessage('Adicione pelo menos um ingrediente.', 'warning');
                return;
            }
            this.saving = true;
            try {
                const payload = {
                    name: this.newItem.name,
                    price: parseFloat(this.newItem.price),
                    category_name: this.newItem.category_name,
                    description: this.newItem.description,
                    ingredients: this.newItemIngredients.filter(i => i.id).map(i => ({
                        id: parseInt(i.id),
                        quantity: parseFloat(i.quantity)
                    }))
                };
                const res = await fetch('/api/admin/items', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.status === 401) { this.logout(); return; }
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao adicionar');
                this.showMessage('Item adicionado!', 'success');
                this.newItem = { name: '', price: '', category_name: '', description: '' };
                this.newItemIngredients = [];
                await this.loadMenu();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.saving = false;
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

        // Recipe modal methods
        async openRecipeModal(dish) {
            this.selectedDish = dish;
            this.showRecipeModal = true;
            this.recipeLoading = true;
            try {
                const res = await fetch(`/api/admin/dishes/${dish.id}`, {
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (!res.ok) throw new Error('Falha ao carregar receita');
                const data = await res.json();
                this.recipeIngredients = (data.ingredients || []).map(ing => ({
                    ingredient_id: Number(ing.ingredient_id),
                    quantity: Number(ing.quantity),
                    name: String(ing.name),
                    unit: String(ing.unit),
                }));
            } catch (err) {
                this.recipeIngredients = [];
            }
            // Load all ingredients for the dropdown
            try {
                const res = await fetch('/api/admin/ingredients', {
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                this.allIngredients = await res.json();
            } catch (err) {
                this.allIngredients = [];
            }
            this.recipeLoading = false;
        },

        addRecipeRow() {
            this.recipeIngredients.push({ ingredient_id: '', quantity: 0 });
        },

        removeRecipeRow(index) {
            this.recipeIngredients.splice(index, 1);
        },

        async saveRecipe() {
            const ingredients = this.recipeIngredients
                .filter(i => i.ingredient_id && i.quantity > 0)
                .map(i => ({ id: parseInt(i.ingredient_id), quantity: parseFloat(i.quantity) }));

            try {
                const res = await fetch(`/api/admin/dishes/${this.selectedDish.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify({ ingredients })
                });
                if (!res.ok) throw new Error('Erro ao salvar receita');
                this.showRecipeModal = false;
                this.showMessage('Receita atualizada!', 'success');
                this.loadMenu();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        // Computed para verificar correspondência exata global (não por linha)
        exactDishIngredientMatch(searchText) {
            if (!searchText) return false;
            const term = searchText.toLowerCase().trim();
            return this.allIngredients.some(i => i.name.toLowerCase() === term);
        },

// Filtro aplicado sobre allIngredients (reutilizado por todas as linhas)
        filteredDishIngredients: [],   // será atualizado por filterDishIngredients

        filterDishIngredients(index) {
            const search = this.newItemIngredients[index].searchText || '';
            const term = search.toLowerCase().trim();
            if (term === '') {
                this.filteredDishIngredients = [...this.allIngredients];
            } else {
                this.filteredDishIngredients = this.allIngredients.filter(i =>
                    i.name.toLowerCase().includes(term)
                );
            }
            // Mostra o dropdown para essa linha
            this.newItemIngredients[index].showDropdown = true;
        },

        // Seleciona um ingrediente da lista
        selectDishIngredient(index, ingredient) {
            const row = this.newItemIngredients[index];
            row.id = ingredient.id;
            row.searchText = ingredient.name;
            row.showDropdown = false;
            this.filteredDishIngredients = [...this.allIngredients];  // reset
        },

        // Cria um novo ingrediente via API e seleciona
        async createIngredientOnFly(index) {
            const row = this.newItemIngredients[index];
            const name = row.searchText.trim();
            if (!name) return;

            try {
                const res = await fetch('/api/admin/ingredients', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify({ name: name, unit: 'un' })   // unidade padrão
                });
                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.error || 'Erro ao criar ingrediente');
                }
                const newIng = await res.json();
                // Atualiza a lista global de ingredientes e seleciona na linha
                this.allIngredients.push(newIng);
                row.id = newIng.id;
                row.searchText = newIng.name;
                row.showDropdown = false;
                this.filteredDishIngredients = [...this.allIngredients];
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