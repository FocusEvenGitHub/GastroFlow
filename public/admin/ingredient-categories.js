function ingCatApp() {
    return {
        categories: [],
        newCategory: { name: '' },
        saving: false,
        loading: true,
        message: { text: '', type: 'info' },
        token: localStorage.getItem('admin_token') || '',
        editMode: false,
        editData: { id: null, name: '' },

        init() {
            if (!this.token) {
                window.location.href = '/admin/';
                return;
            }
            this.loadCategories();
        },

        async loadCategories() {
            try {
                const res = await fetch('/api/admin/ingredient-categories', {
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                if (!res.ok) throw new Error('Erro ao carregar');
                this.categories = await res.json();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        async addCategory() {
            if (!this.newCategory.name) return;
            this.saving = true;
            try {
                const res = await fetch('/api/admin/ingredient-categories', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify(this.newCategory)
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro');
                this.newCategory = { name: '' };
                await this.loadCategories();
                this.showMessage('Categoria adicionada!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.saving = false;
            }
        },

        editCategory(cat) {
            this.editData = { id: cat.id, name: cat.name };
            this.editMode = true;
        },

        async updateCategory() {
            try {
                const res = await fetch(`/api/admin/ingredient-categories/${this.editData.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify(this.editData)
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro');
                this.editMode = false;
                await this.loadCategories();
                this.showMessage('Categoria atualizada!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        async deleteCategory(id) {
            if (!confirm('Excluir esta categoria? Pode afetar ingredientes.')) return;
            try {
                const res = await fetch(`/api/admin/ingredient-categories/${id}`, {
                    method: 'DELETE',
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro');
                await this.loadCategories();
                this.showMessage('Categoria excluída!', 'success');
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