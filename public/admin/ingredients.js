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
                const res = await this.api('/api/admin/ingredients');
                if (!res.ok) throw new Error('Erro ao carregar ingredientes');
                this.ingredients = await res.json();
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
                const res = await this.api('/api/admin/ingredients', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.newIngredient)
                });
                if (!res.ok) throw new Error('Erro ao adicionar ingrediente');
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
                const res = await this.api(`/api/admin/ingredients/${this.editIngredientData.id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.editIngredientData)
                });
                if (!res.ok) throw new Error('Erro ao atualizar');
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
                const res = await this.api(`/api/admin/ingredients/${ing.id}`, { method: 'DELETE' });
                if (!res.ok) throw new Error('Erro ao excluir');
                await this.loadIngredients();
                this.showMessage('Ingrediente excluído!', 'success');
            } catch (err) {
                this.handleError(err);
            }
        }
    });
}
