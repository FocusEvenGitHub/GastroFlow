// Carregar categorias e cardápio
async function loadData() {
    try {
        // Carregar categorias para o select
        const categoriesResponse = await fetch('/api/categories');
        const categories = await categoriesResponse.json();
        
        const categorySelect = document.getElementById('categorySelect');
        categorySelect.innerHTML = categories.map(cat => 
            `<option value="${cat.id}">${cat.name}</option>`
        ).join('');
        
        // Carregar cardápio atual
        const menuResponse = await fetch('/api/menu');
        const menu = await menuResponse.json();
        displayMenu(menu);
        
    } catch (error) {
        console.error('Erro ao carregar dados:', error);
    }
}

// Exibir cardápio atual
function displayMenu(menu) {
    const container = document.getElementById('currentMenu');
    
    container.innerHTML = menu.map(category => `
        <div class="category">
            <h3>${category.category_name}</h3>
            ${category.items.map(item => `
                <div class="item">
                    <strong>${item.name}</strong> - R$ ${item.price}
                    <p>${item.description || ''}</p>
                    <button onclick="toggleAvailability(${item.id}, ${!item.available})">
                        ${item.available ? 'Desativar' : 'Ativar'}
                    </button>
                </div>
            `).join('')}
        </div>
    `).join('');
}

// Adicionar novo item
document.getElementById('addItemForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const itemData = {
        name: formData.get('name'),
        description: formData.get('description'),
        price: parseFloat(formData.get('price')),
        category_id: parseInt(formData.get('category_id'))
    };
    
    // Aqui você precisaria criar uma API para adicionar itens
    // Por enquanto, vamos apenas recarregar
    alert('Funcionalidade de adicionar item será implementada com a API completa');
    e.target.reset();
});

// Alternar disponibilidade do item
async function toggleAvailability(itemId, available) {
    // Implementar API para atualizar disponibilidade
    alert(`Item ${itemId} - ${available ? 'Ativado' : 'Desativado'}`);
    loadData(); // Recarregar dados
}

// Carregar dados ao iniciar
loadData();