// admin.js - com categorias vindas do menu

// Função para exibir mensagens (Bootstrap alert)
function showMessage(message, type = 'danger') {
    const container = document.getElementById('alertContainer');
    if (!container) return;
    container.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : (type === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-circle')} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    setTimeout(() => {
        const alert = container.querySelector('.alert');
        if (alert) alert.classList.add('fade');
    }, 5000);
}

// Carregar cardápio e extrair categorias para o select
async function loadData() {
    try {
        // Carregar cardápio atual
        const menuResponse = await fetch('/api/menu');
        if (!menuResponse.ok) throw new Error(`HTTP ${menuResponse.status}`);
        const menu = await menuResponse.json();

        // Extrair categorias únicas para o select
        const categories = menu.map(cat => ({
            name: cat.category_name,
            type: cat.type
        }));

        const categorySelect = document.getElementById('categorySelect');
        if (categorySelect) {
            categorySelect.innerHTML = categories.map(cat =>
                `<option value="${escapeHtml(cat.name)}">${escapeHtml(cat.name)}</option>`
            ).join('');
        }

        // Exibir cardápio
        displayMenu(menu);

    } catch (error) {
        console.error('Erro ao carregar dados:', error);
        showMessage(`Erro ao carregar cardápio: ${error.message}`, 'danger');
        document.getElementById('currentMenu').innerHTML = `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Não foi possível carregar o cardápio.
            </div>
        `;
    }
}

// Exibir cardápio com Bootstrap cards
function displayMenu(menu) {
    const container = document.getElementById('currentMenu');
    if (!container) return;

    if (!menu || menu.length === 0) {
        container.innerHTML = '<div class="alert alert-info">Nenhum item cadastrado.</div>';
        return;
    }

    container.innerHTML = menu.map(category => `
        <div class="card mb-4 shadow-sm">
            <div class="card-header category-header">
                <h4 class="mb-0">
                    <i class="fas ${category.type === 'food' ? 'fa-utensils' : 'fa-glass-cheers'} me-2"></i>
                    ${escapeHtml(category.category_name)}
                </h4>
            </div>
            <div class="card-body">
                <div class="row">
                    ${category.items.map(item => `
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="card h-100 card-item">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <h5 class="card-title mb-1">${escapeHtml(item.name)}</h5>
                                        <span class="price">R$ ${parseFloat(item.price).toFixed(2)}</span>
                                    </div>
                                    ${item.description ? `<p class="card-text text-muted small">${escapeHtml(item.description)}</p>` : ''}
                                    <div class="mt-2 d-flex justify-content-end">
                                        <button 
                                            class="btn btn-sm ${item.available !== false ? 'btn-outline-danger' : 'btn-outline-success'}"
                                            onclick="toggleAvailability(${item.id}, ${!item.available})">
                                            <i class="fas ${item.available !== false ? 'fa-ban' : 'fa-check'} me-1"></i>
                                            ${item.available !== false ? 'Desativar' : 'Ativar'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>
    `).join('');
}

// Adicionar novo item (usa a API /api/items)
document.getElementById('addItemForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const name = document.getElementById('itemName').value.trim();
    const description = document.getElementById('itemDescription').value.trim();
    const price = parseFloat(document.getElementById('itemPrice').value);
    const categoryName = document.getElementById('categorySelect').value;

    if (!name || isNaN(price) || !categoryName) {
        showMessage('Preencha todos os campos obrigatórios.', 'warning');
        return;
    }

    const itemData = { name, description, price, category_name: categoryName };

    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Enviando...';

    try {
        const response = await fetch('/api/items', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(itemData)
        });

        const result = await response.json();

        if (!response.ok || result.error) {
            throw new Error(result.error || 'Erro ao adicionar item');
        }

        showMessage('Item adicionado com sucesso!', 'success');
        document.getElementById('addItemForm').reset();
        loadData();
    } catch (error) {
        console.error('Erro ao adicionar item:', error);
        showMessage(`Erro: ${error.message}`, 'danger');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
});

// Alternar disponibilidade do item (usa a API /api/items/{id})
window.toggleAvailability = async function(itemId, newAvailability) {
    const action = newAvailability ? 'ativar' : 'desativar';
    if (!confirm(`Tem certeza que deseja ${action} este item?`)) return;

    try {
        const response = await fetch(`/api/items/${itemId}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ available: newAvailability })
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.error || `Erro ao ${action} item`);
        }

        showMessage(`Item ${action}do com sucesso!`, 'success');
        loadData(); // recarrega a lista

    } catch (error) {
        console.error(`Erro ao ${action} item:`, error);
        showMessage(`Erro: ${error.message}`, 'danger');
    }
};

// Função auxiliar para escapar HTML (segurança)
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}


window.toggleAvailability = async function(itemId, newAvailability) {
    const action = newAvailability ? 'ativar' : 'desativar';
    if (!confirm(`Tem certeza que deseja ${action} este item?`)) return;

    try {
        const response = await fetch(`/api/items/${itemId}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ available: newAvailability })
        });

        const result = await response.json();

        // Verifica se houve erro no servidor (status 4xx/5xx ou campo error)
        if (!response.ok || result.error) {
            throw new Error(result.error || `Erro ao ${action} item`);
        }

        showMessage(`Item ${action}do com sucesso!`, 'success');
        loadData(); // recarrega a lista
    } catch (error) {
        console.error(`Erro ao ${action} item:`, error);
        showMessage(`Erro: ${error.message}`, 'danger');
    }
};

// Inicializar
loadData();