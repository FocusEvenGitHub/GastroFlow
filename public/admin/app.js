function adminApp() {
    return {
        menu: [],
        categories: [],
        loading: true,
        newItem: { name: '', price: '', category_name: '', description: '' },
        saving: false,
        message: { text: '', type: 'info' },

        async init() {
            await this.loadMenu();
        },

        async loadMenu() {
            try {
                const res = await fetch('/api/menu');
                if (!res.ok) throw new Error('Erro ao carregar cardápio');
                this.menu = await res.json();
                // Extrai categorias para o select
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
                const res = await fetch('/api/items', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: this.newItem.name,
                        price: parseFloat(this.newItem.price),
                        category_name: this.newItem.category_name,
                        description: this.newItem.description
                    })
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao adicionar item');
                this.showMessage('Item adicionado com sucesso!', 'success');
                this.newItem = { name: '', price: '', category_name: '', description: '' };
                await this.loadMenu(); // Recarrega a lista
            } catch (err) {
                this.showMessage(err.message, 'danger');
            } finally {
                this.saving = false;
            }
        },

        async toggleAvailability(itemId, newAvailable) {
            try {
                const res = await fetch(`/api/items/${itemId}`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ available: newAvailable })
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Erro ao alterar disponibilidade');
                // Atualiza o item localmente sem recarregar toda a lista
                const category = this.menu.find(c => c.items.some(i => i.id === itemId));
                if (category) {
                    const item = category.items.find(i => i.id === itemId);
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