function ingredientsApp() {
    return {
        ingredients: [],
        ingredientCategories: [],
        newIngredient: { name: '', unit: '', category_id: '' },
        saving: false,
        loading: true,
        message: { text: '', type: 'info' },
        token: localStorage.getItem('admin_token') || '',
        editMode: false,
        editIngredientData: { id: null, name: '', unit: '', category_id: '' },
        categorySearch: '',
        showCategoryDropdown: false,
        filteredCategories: [],

        async init() {
            if (!this.token) {
                window.location.href = '/admin/';
                return;
            }
            await Promise.all([this.loadIngredientCategories(), this.loadIngredients()]);
            this.filteredCategories = [...this.ingredientCategories];
        },

        async loadIngredientCategories() {
            try {
                const res = await fetch('/api/admin/ingredient-categories', {
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (!res.ok) throw new Error('Erro ao carregar categorias de ingredientes');
                this.ingredientCategories = await res.json();
                this.filteredCategories = [...this.ingredientCategories];
            } catch (err) {
                console.error(err);
            }
        },

        async loadIngredients() {
            try {
                const res = await fetch('/api/admin/ingredients', {
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                if (!res.ok) throw new Error('Erro ao carregar ingredientes');
                this.ingredients = await res.json();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        async addIngredient() {
            if (!this.newIngredient.name || !this.newIngredient.unit) return;
            this.saving = true;
            try {
                const res = await fetch('/api/admin/ingredients', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify({
                        name: this.newIngredient.name,
                        unit: this.newIngredient.unit,
                        category_id: this.newIngredient.category_id || null
                    })
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.error || 'Erro ao adicionar');
                }
                this.newIngredient = { name: '', unit: '', category_id: '' };
                await this.loadIngredients();
                this.showMessage('Ingrediente adicionado!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.saving = false;
            }
        },

        editIngredient(ing) {
            this.editIngredientData = {
                id: ing.id,
                name: ing.name,
                unit: ing.unit,
                category_id: ing.category_id || ''
            };
            this.editMode = true;
        },

        async updateIngredient() {
            try {
                const res = await fetch(`/api/admin/ingredients/${this.editIngredientData.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify({
                        name: this.editIngredientData.name,
                        unit: this.editIngredientData.unit,
                        category_id: this.editIngredientData.category_id || null
                    })
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.error || 'Erro ao atualizar');
                }
                this.editMode = false;
                await this.loadIngredients();
                this.showMessage('Ingrediente atualizado!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        async deleteIngredient(id) {
            if (!confirm('Excluir este ingrediente? Pode afetar pratos.')) return;
            try {
                const res = await fetch(`/api/admin/ingredients/${id}`, {
                    method: 'DELETE',
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro ao excluir');
                await this.loadIngredients();
                this.showMessage('Ingrediente excluído!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        showMessage(text, type = 'info') {
            this.message = { text, type };
            setTimeout(() => { this.message.text = ''; }, 4000);
        },

        get exactCategoryMatch() {
            return this.ingredientCategories.some(
                cat => cat.name.toLowerCase() === this.categorySearch.trim().toLowerCase()
            );
        },

        filterCategories() {
            const term = this.categorySearch.toLowerCase().trim();
            if (term === '') {
                this.filteredCategories = [...this.ingredientCategories];
            } else {
                this.filteredCategories = this.ingredientCategories.filter(
                    cat => cat.name.toLowerCase().includes(term)
                );
            }
            this.showCategoryDropdown = true;
        },

        selectCategory(cat) {
            this.newIngredient.category_id = cat.id;
            this.categorySearch = cat.name;
            this.showCategoryDropdown = false;
        },

        async createCategoryFromSearch() {
            const name = this.categorySearch.trim();
            if (!name) return;

            try {
                const res = await fetch('/api/admin/ingredient-categories', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify({ name })
                });
                const newCat = await res.json();
                if (!res.ok) throw new Error(newCat.error || 'Erro ao criar categoria');
                // Atualiza lista de categorias e seleciona a nova
                await this.loadIngredientCategories();
                this.newIngredient.category_id = newCat.id;
                this.categorySearch = newCat.name;
                this.showCategoryDropdown = false;
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        }
    };
}