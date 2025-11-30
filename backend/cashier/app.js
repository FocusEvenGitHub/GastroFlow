let menuData = [];
let selectedItems = [];

// Carregar cardápio
async function loadMenu() {
    try {
        const response = await fetch('/api/menu');
        menuData = await response.json();
        displayMenu();
    } catch (error) {
        console.error('Erro ao carregar cardápio:', error);
    }
}

// Exibir cardápio
function displayMenu() {
    const menuContainer = document.getElementById('menu');
    menuContainer.innerHTML = '';

    menuData.forEach(category => {
        const categoryDiv = document.createElement('div');
        categoryDiv.className = 'category';
        
        const categoryTitle = document.createElement('div');
        categoryTitle.className = 'category-title';
        categoryTitle.textContent = `${category.category_name} (${category.type === 'food' ? 'Prato' : 'Bebida'})`;
        categoryDiv.appendChild(categoryTitle);

        category.items.forEach(item => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'menu-item';
            itemDiv.innerHTML = `
                <div>
                    <span class="item-name">${item.name}</span>
                    <span class="item-price">R$ ${item.price}</span>
                    <button type="button" onclick="addItem(${item.id}, '${item.name.replace(/'/g, "\\'")}')">Adicionar</button>
                </div>
                <div class="item-description">${item.description || ''}</div>
            `;
            categoryDiv.appendChild(itemDiv);
        });

        menuContainer.appendChild(categoryDiv);
    });
}

// Adicionar item ao pedido
function addItem(itemId, itemName) {
    const existingItem = selectedItems.find(item => item.id === itemId);
    
    if (existingItem) {
        existingItem.quantity++;
    } else {
        selectedItems.push({
            id: itemId,
            name: itemName,
            quantity: 1,
            notes: ''
        });
    }
    
    updateSelectedItemsDisplay();
}

// Remover item do pedido
function removeItem(itemId) {
    selectedItems = selectedItems.filter(item => item.id !== itemId);
    updateSelectedItemsDisplay();
}

// Atualizar quantidade
function updateQuantity(itemId, quantity) {
    const item = selectedItems.find(item => item.id === itemId);
    if (item) {
        item.quantity = parseInt(quantity) || 1;
        updateSelectedItemsDisplay();
    }
}

// Atualizar observações
function updateNotes(itemId, notes) {
    const item = selectedItems.find(item => item.id === itemId);
    if (item) {
        item.notes = notes;
    }
}

// Atualizar exibição dos itens selecionados
function updateSelectedItemsDisplay() {
    const container = document.getElementById('selectedItems');
    
    if (selectedItems.length === 0) {
        container.innerHTML = 'Nenhum item selecionado';
        return;
    }
    
    container.innerHTML = selectedItems.map(item => `
        <div class="selected-item">
            ${item.name} 
            <input type="number" class="quantity" value="${item.quantity}" min="1" 
                   onchange="updateQuantity(${item.id}, this.value)">
            <input type="text" class="notes" placeholder="Observações (ex: sem arroz)" 
                   onchange="updateNotes(${item.id}, this.value)" value="${item.notes}">
            <span class="remove-item" onclick="removeItem(${item.id})">❌</span>
        </div>
    `).join('');
}

// Enviar pedido
document.getElementById('orderForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    if (selectedItems.length === 0) {
        alert('Selecione pelo menos um item');
        return;
    }

    const formData = new FormData(e.target);
    const orderData = {
        table: formData.get('table'),
        items: selectedItems
    };

    try {
        const response = await fetch('/api/orders', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(orderData)
        });

        const result = await response.json();

        if (result.ok) {
            document.getElementById('message').textContent = `Pedido #${result.id} criado com sucesso!`;
            // Limpar formulário
            e.target.reset();
            selectedItems = [];
            updateSelectedItemsDisplay();
        } else {
            document.getElementById('message').textContent = `Erro: ${result.error}`;
        }
    } catch (error) {
        console.error('Erro ao enviar pedido:', error);
        document.getElementById('message').textContent = 'Erro ao enviar pedido';
    }
});

// Carregar cardápio ao iniciar
loadMenu();