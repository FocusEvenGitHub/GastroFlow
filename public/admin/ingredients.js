function ingredientsApp() {
    return GFAdmin.page({
        ingredients: [],
        newIngredient: { name: '', unit: '', category: '' },
        saving: false,
        loading: true,
        editMode: false,
        editIngredientData: { id: null, name: '', unit: '', category: '' },

        async init() {
            if (!this.guard()) return;
            await this.loadIngredients();
        },

        async loadIngredients() {
            try {
                this.ingredients = await this.api('/api/admin/ingredients');
            } catch (err) {
                this.handleError(err);
            } finally {
                this.loading = false;
            }
        },

        async addIngredient() {
            if (!this.newIngredient.name || !this.newIngredient.unit) return;
            this.saving = true;
            try {
                await this.api('/api/admin/ingredients', { method: 'POST', json: this.newIngredient });
                this.newIngredient = { name: '', unit: '', category: '' };
                await this.loadIngredients();
                this.showMessage('Ingrediente adicionado!', 'success');
            } catch (err) {
                this.handleError(err);
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
                await this.api(`/api/admin/ingredients/${this.editIngredientData.id}`, {
                    method: 'PUT',
                    json: this.editIngredientData
                });
                this.editMode = false;
                await this.loadIngredients();
                this.showMessage('Ingrediente atualizado!', 'success');
            } catch (err) {
                this.handleError(err);
            }
        },

        async deleteIngredient(ing) {
            const ok = await this.askConfirm(`Excluir o ingrediente "${ing.name}"? Pode afetar pratos.`);
            if (!ok) return;
            try {
                await this.api(`/api/admin/ingredients/${ing.id}`, { method: 'DELETE' });
                await this.loadIngredients();
                this.showMessage('Ingrediente excluído!', 'success');
            } catch (err) {
                this.handleError(err);
            }
        }
    });
}
