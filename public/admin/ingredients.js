function ingredientsApp() {
    return {
        ingredients: [],
        newIngredient: { name: '', unit: '', category: '' },
        saving: false,
        loading: true,
        message: { text: '', type: 'info' },
        token: localStorage.getItem('admin_token') || '',
        editMode: false,
        editIngredientData: { id: null, name: '', unit: '', category: '' },

        async init() {
            if (!this.token) {
                window.location.href = '/admin/'; // need login
                return;
            }
            await this.loadIngredients();
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
                    body: JSON.stringify(this.newIngredient)
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                if (!res.ok) throw new Error('Erro ao adicionar ingrediente');
                this.newIngredient = { name: '', unit: '', category: '' };
                await this.loadIngredients();
                this.showMessage('Ingrediente adicionado!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.saving = false;
            }
        },

        editIngredient(ing) {
            this.editIngredientData = { ...ing };
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
                    body: JSON.stringify(this.editIngredientData)
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                if (!res.ok) throw new Error('Erro ao atualizar');
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
                if (!res.ok) throw new Error('Erro ao excluir');
                await this.loadIngredients();
                this.showMessage('Ingrediente excluído!', 'success');
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