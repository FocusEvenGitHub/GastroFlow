function categoriesApp() {
    return {
        categories: [],
        newCategory: { name: '', type: '' },
        saving: false,
        loading: true,
        message: { text: '', type: 'info' },
        token: localStorage.getItem('admin_token') || '',
        editMode: false,
        editData: { id: null, name: '', type: '' },

        init() {
            if (!this.token) {
                window.location.href = '/admin/';
                return;
            }
            this.loadCategories();
        },

        async loadCategories() {
            try {
                const res = await fetch('/api/admin/categories', {
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                if (!res.ok) throw new Error('Erro ao carregar categorias');
                this.categories = await res.json();
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        async addCategory() {
            if (!this.newCategory.name || !this.newCategory.type) return;
            this.saving = true;
            try {
                const res = await fetch('/api/admin/categories', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify(this.newCategory)
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro ao adicionar');
                this.newCategory = { name: '', type: '' };
                await this.loadCategories();
                this.showMessage('Categoria adicionada!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.saving = false;
            }
        },

        editCategory(cat) {
            this.editData = { id: cat.id, name: cat.name, type: cat.type };
            this.editMode = true;
        },

        async updateCategory() {
            try {
                const res = await fetch(`/api/admin/categories/${this.editData.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${this.token}`
                    },
                    body: JSON.stringify(this.editData)
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro ao atualizar');
                this.editMode = false;
                await this.loadCategories();
                this.showMessage('Categoria atualizada!', 'success');
            } catch (err) {
                this.showMessage(err.message, 'danger');
            }
        },

        async deleteCategory(id) {
            if (!confirm('Excluir esta categoria? Pode afetar pratos vinculados.')) return;
            try {
                const res = await fetch(`/api/admin/categories/${id}`, {
                    method: 'DELETE',
                    headers: { 'Authorization': `Bearer ${this.token}` }
                });
                if (res.status === 401) { window.location.href = '/admin/'; return; }
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erro ao excluir');
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