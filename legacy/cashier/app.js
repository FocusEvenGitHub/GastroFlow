$(document).ready(function() {
    // Elementos DOM
    const $tableNumber = $('#tableNumber');
    const $menuItems = $('#menuItems');
    const $selectedItems = $('#selectedItems');
    const $selectedItemsSection = $('#selectedItemsSection');
    const $orderSummary = $('#orderSummary');
    const $submitOrder = $('#submitOrder');
    const $messageContainer = $('#messageContainer');
    const $categoryTabs = $('#categoryTabs');

    // Variáveis de estado
    let menuData = [];
    let selectedItems = [];
    let categories = [];
    let currentCategory = 'all'; // 'all' ou nome da categoria

    // Inicializar
    loadMenuData();

    // Carregar dados do menu
    function loadMenuData() {
        showMessage('Carregando cardápio...', 'info');

        $.ajax({
            url: '/api/menu',
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                menuData = data;
                prepareCategories();
                displayMenu();
                hideMessage();
            },
            error: function(xhr, status, error) {
                showMessage('Erro ao carregar cardápio. Recarregue a página.', 'error');
                console.error('Erro ao carregar menu:', error);
                loadSampleData();
            }
        });
    }

    // Preparar lista de categorias e criar abas
    function prepareCategories() {
        categories = menuData.map(cat => cat.category_name);
        $categoryTabs.empty();
        // Adiciona aba "Todos"
        $categoryTabs.append(`
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-category="all">Todos</button>
            </li>
        `);
        // Adiciona aba para cada categoria
        categories.forEach(cat => {
            $categoryTabs.append(`
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-category="${cat}">${cat}</button>
                </li>
            `);
        });
        // Evento de clique nas abas
        $categoryTabs.find('.nav-link').on('click', function() {
            const category = $(this).data('category');
            if (category === currentCategory) return;
            currentCategory = category;
            $categoryTabs.find('.nav-link').removeClass('active');
            $(this).addClass('active');
            displayMenu();
        });
    }

    // Carregar dados de exemplo (fallback)
    function loadSampleData() {
        menuData = [
            {
                category_name: 'Pratos Principais',
                type: 'food',
                items: [
                    { id: 1, name: 'Feijoada Completa', description: 'Feijoada com arroz, couve, farofa e laranja', price: 25.90 },
                    { id: 2, name: 'Bife à Parmegiana', description: 'Bife à parmegiana com arroz e batata frita', price: 22.50 },
                    { id: 3, name: 'Frango Grelhado', description: 'Frango grelhado com legumes', price: 18.90 }
                ]
            },
            {
                category_name: 'Bebidas',
                type: 'drink',
                items: [
                    { id: 7, name: 'Coca-Cola', description: 'Lata 350ml', price: 5.00 },
                    { id: 8, name: 'Suco de Laranja', description: 'Copo 300ml', price: 7.00 }
                ]
            }
        ];
        prepareCategories();
        displayMenu();
        hideMessage();
    }

    // Exibir cardápio filtrado por categoria
    function displayMenu() {
        $menuItems.empty();

        if (!menuData || menuData.length === 0) {
            $menuItems.html('<div class="col-12"><div class="alert alert-warning">Nenhum item disponível no cardápio.</div></div>');
            return;
        }

        // Filtrar categorias com base na seleção atual
        let filteredCategories = menuData;
        if (currentCategory !== 'all') {
            filteredCategories = menuData.filter(cat => cat.category_name === currentCategory);
        }

        if (filteredCategories.length === 0) {
            $menuItems.html('<div class="col-12"><div class="alert alert-info">Nenhum item nesta categoria.</div></div>');
            return;
        }

        filteredCategories.forEach(category => {
            const categoryId = category.category_name.toLowerCase().replace(/\s+/g, '-');
            const iconClass = category.type === 'food' ? 'fa-utensils' : 'fa-glass-cheers';

            const categoryHtml = `
                <div class="col-12 mb-4">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="fas ${iconClass} me-2"></i>
                                ${category.category_name}
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                ${category.items.map(item => `
                                    <div class="col-md-4 col-sm-6 mb-3">
                                        <div class="card menu-item-card h-100" data-item-id="${item.id}">
                                            <div class="card-body">
                                                <h6 class="card-title">
                                                    <i class="fas ${iconClass} me-1 text-muted"></i>
                                                    ${item.name}
                                                </h6>
                                                <p class="card-text text-muted small">${item.description || 'Sem descrição'}</p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="h5 text-success mb-0">R$ ${parseFloat(item.price).toFixed(2)}</span>
                                                    <button class="btn btn-sm btn-outline-primary add-item-btn" data-item-id="${item.id}">
                                                        <i class="fas fa-plus"></i> Adicionar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;

            $menuItems.append(categoryHtml);
        });

        // Adicionar eventos aos botões de adicionar
        $('.add-item-btn').on('click', function(e) {
            e.stopPropagation();
            const itemId = $(this).data('item-id');
            addItemToOrder(itemId);
        });

        // Adicionar evento de clique nos cards
        $('.menu-item-card').on('click', function() {
            const itemId = $(this).data('item-id');
            addItemToOrder(itemId);
        });
    }

    // Adicionar item ao pedido com animação
    function addItemToOrder(itemId) {
        // Encontrar o item no menu
        let item = null;
        for (const category of menuData) {
            item = category.items.find(i => i.id == itemId);
            if (item) break;
        }

        if (!item) {
            showMessage('Item não encontrado!', 'error');
            return;
        }

        // Verificar se o item já está selecionado
        const existingItem = selectedItems.find(i => i.id == itemId);

        if (existingItem) {
            existingItem.quantity += 1;
        } else {
            // Determinar o tipo do item para ícone
            let itemType = 'food';
            for (const cat of menuData) {
                if (cat.items.some(i => i.id == itemId)) {
                    itemType = cat.type;
                    break;
                }
            }
            selectedItems.push({
                id: item.id,
                name: item.name,
                price: parseFloat(item.price),
                quantity: 1,
                notes: '',
                type: itemType
            });
        }

        // Atualizar interface
        updateSelectedItemsDisplay();
        updateOrderSummary();

        // Feedback visual
        const $card = $(`.menu-item-card[data-item-id="${itemId}"]`);
        $card.addClass('added');
        setTimeout(() => {
            $card.removeClass('added');
        }, 500);
    }

    // Atualizar exibição dos itens selecionados (compacta com ícones)
    function updateSelectedItemsDisplay() {
        if (selectedItems.length === 0) {
            $selectedItemsSection.hide();
            $selectedItems.html('<p class="text-muted mb-0">Nenhum item selecionado</p>');
            return;
        }

        $selectedItemsSection.show();
        $selectedItems.empty();

        selectedItems.forEach((item, index) => {
            const iconClass = item.type === 'food' ? 'fa-utensils' : 'fa-glass-cheers';
            const itemHtml = `
                <div class="selected-item mb-2 p-2 border rounded" data-index="${index}">
                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                        <div class="d-flex align-items-center flex-grow-1">
                            <i class="fas ${iconClass} me-2 text-secondary"></i>
                            <span class="fw-bold me-2">${item.name}</span>
                            <div class="btn-group btn-group-sm ms-2" role="group">
                                <button type="button" class="btn btn-outline-secondary quantity-minus" data-index="${index}">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <span class="mx-2" style="min-width: 35px; text-align: center;">${item.quantity}</span>
                                <button type="button" class="btn btn-outline-secondary quantity-plus" data-index="${index}">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="d-flex align-items-center mt-1 mt-sm-0">
                            <span class="text-success fw-bold me-3">R$ ${(item.price * item.quantity).toFixed(2)}</span>
                            <i class="fas fa-pencil-alt notes-icon me-2" data-index="${index}" title="Adicionar observação"></i>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-item" data-index="${index}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="notes-container mt-2" style="display: none;">
                        <label class="form-label small mb-1">Observações:</label>
                        <textarea class="form-control form-control-sm notes-input" rows="2" 
                                  placeholder="Ex: sem arroz, bem passado, etc."
                                  data-index="${index}">${item.notes || ''}</textarea>
                    </div>
                </div>
            `;
            $selectedItems.append(itemHtml);
        });

        // Adicionar eventos
        $('.quantity-minus').on('click', function() {
            const index = $(this).data('index');
            updateQuantity(index, selectedItems[index].quantity - 1);
        });

        $('.quantity-plus').on('click', function() {
            const index = $(this).data('index');
            updateQuantity(index, selectedItems[index].quantity + 1);
        });

        $('.notes-icon').on('click', function() {
            const $container = $(this).closest('.selected-item').find('.notes-container');
            $container.slideToggle();
        });

        $('.notes-input').on('change', function() {
            const index = $(this).data('index');
            selectedItems[index].notes = $(this).val();
            updateOrderSummary();
        });

        $('.remove-item').on('click', function() {
            const index = $(this).data('index');
            removeItem(index);
        });
    }

    // Atualizar quantidade de um item
    function updateQuantity(index, newQuantity) {
        if (newQuantity < 1) newQuantity = 1;
        selectedItems[index].quantity = newQuantity;
        updateSelectedItemsDisplay();
        updateOrderSummary();
    }

    // Remover item do pedido
    function removeItem(index) {
        if (confirm(`Remover "${selectedItems[index].name}" do pedido?`)) {
            selectedItems.splice(index, 1);
            updateSelectedItemsDisplay();
            updateOrderSummary();
        }
    }

    // Atualizar resumo do pedido (mais detalhado)
    function updateOrderSummary() {
        if (selectedItems.length === 0) {
            $orderSummary.html('<p class="text-muted">Nenhum item selecionado</p>');
            $submitOrder.prop('disabled', true);
            return;
        }

        let total = 0;
        let totalItems = 0;
        let itemsHtml = '';

        selectedItems.forEach(item => {
            const subtotal = item.price * item.quantity;
            total += subtotal;
            totalItems += item.quantity;

            itemsHtml += `
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                    <div>
                        <span class="badge bg-secondary me-2">${item.quantity}x</span>
                        <span>${item.name}</span>
                        ${item.notes ? `<br><small class="text-muted"><i class="fas fa-comment"></i> ${item.notes}</small>` : ''}
                    </div>
                    <span class="text-end">R$ ${subtotal.toFixed(2)}</span>
                </div>
            `;
        });

        $orderSummary.html(`
            <div class="mb-2">
                <strong>Total de itens:</strong> ${totalItems}
            </div>
            ${itemsHtml}
            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                <strong>TOTAL:</strong>
                <strong class="h5 text-success">R$ ${total.toFixed(2)}</strong>
            </div>
        `);

        // Habilitar botão de envio se tiver mesa e itens
        $submitOrder.prop('disabled', !$tableNumber.val() || selectedItems.length === 0);
    }

    // Enviar pedido (igual ao original, mas com validações)
    $submitOrder.on('click', function() {
        const tableNumber = $tableNumber.val().trim();

        if (!tableNumber) {
            showMessage('Informe o número da mesa!', 'error');
            $tableNumber.focus();
            return;
        }

        if (selectedItems.length === 0) {
            showMessage('Selecione pelo menos um item!', 'error');
            return;
        }

        const orderData = {
            table: tableNumber,
            items: selectedItems.map(item => ({
                id: item.id,
                quantity: item.quantity,
                notes: item.notes
            }))
        };

        $submitOrder.prop('disabled', true).html(`
            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
            Enviando...
        `);

        $.ajax({
            url: '/api/orders',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(orderData),
            success: function(response) {
                if (response.ok || response.success) {
                    const orderId = response.id || 'N/A';
                    showMessage(`✅ Pedido #${orderId} enviado com sucesso para a cozinha!`, 'success');
                    resetForm();
                    setTimeout(() => {
                        loadMenuData();
                    }, 2000);
                } else {
                    showMessage(`❌ Erro: ${response.error || 'Falha ao enviar pedido'}`, 'error');
                    $submitOrder.prop('disabled', false).html('<i class="fas fa-paper-plane me-2"></i>Enviar Pedido');
                }
            },
            error: function(xhr, status, error) {
                showMessage('❌ Erro ao enviar pedido. Tente novamente.', 'error');
                console.error('Erro ao enviar pedido:', error, xhr.responseText);
                $submitOrder.prop('disabled', false).html('<i class="fas fa-paper-plane me-2"></i>Enviar Pedido');
            }
        });
    });

    function resetForm() {
        $tableNumber.val('');
        selectedItems = [];
        updateSelectedItemsDisplay();
        updateOrderSummary();
        $submitOrder.prop('disabled', true).html('<i class="fas fa-paper-plane me-2"></i>Enviar Pedido');
    }

    function showMessage(text, type = 'info') {
        const alertClass = type === 'error' ? 'alert-danger' :
            type === 'success' ? 'alert-success' : 'alert-info';

        $messageContainer.html(`
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${text}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `);
    }

    function hideMessage() {
        $messageContainer.empty();
    }

    $tableNumber.on('input', function() {
        updateOrderSummary();
    });

    $tableNumber.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            if (selectedItems.length > 0) {
                $submitOrder.click();
            }
        }
    });
});