$(document).ready(function() {
    // Elementos DOM
    const $ordersContainer = $('#ordersContainer');
    const $noOrdersMessage = $('#noOrdersMessage');
    const $loadingMessage = $('#loadingMessage');
    const $refreshBtn = $('#refreshBtn');
    const $orderCount = $('#orderCount');
    const $liveToast = $('#liveToast');
    const $toastMessage = $('#toastMessage');
    const toast = new bootstrap.Toast($liveToast);
    
    // Configurações
    const REFRESH_INTERVAL = 5000; // 5 segundos
    let refreshInterval;
    
    // Função para mostrar toast
    function showToast(message, type = 'success') {
        const icon = type === 'success' ? 'fas fa-check-circle text-success' : 
                    type === 'error' ? 'fas fa-exclamation-circle text-danger' : 
                    'fas fa-info-circle text-info';
        
        $toastMessage.text(message);
        $liveToast.find('.toast-header i').attr('class', icon + ' me-2');
        toast.show();
    }
    
    // Função para formatar data
    function formatDateTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    // Função para carregar pedidos
    function loadOrders() {
        $loadingMessage.show();
        $ordersContainer.hide();
        $noOrdersMessage.hide();
        
        $.ajax({
            url: '/api/orders?status=pending',
            method: 'GET',
            dataType: 'json',
            success: function(orders) {
                $loadingMessage.hide();
                
                // Atualizar contador
                $orderCount.text(orders.length + ' pedido' + (orders.length !== 1 ? 's' : ''));
                
                if (orders.length === 0) {
                    $noOrdersMessage.show();
                    $ordersContainer.hide();
                    return;
                }
                
                // Limpar container
                $ordersContainer.empty();
                $ordersContainer.show();
                
                // Renderizar cada pedido
                $.each(orders, function(index, order) {
                    const orderTime = formatDateTime(order.created_at);
                    const orderCard = $(`
                        <div class="card order-card mb-4" id="order-${order.id}">
                            <div class="card-header order-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-0">
                                            <i class="fas fa-receipt me-2"></i>
                                            Pedido #${order.id}
                                        </h5>
                                        <small class="text-muted">
                                            <i class="far fa-clock me-1"></i> ${orderTime}
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge table-badge bg-primary">
                                            <i class="fas fa-table me-1"></i> Mesa ${order.table_number}
                                        </span>
                                        <span class="badge status-badge bg-warning ms-2">
                                            Pendente
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2 text-muted">Itens do Pedido:</h6>
                                <div class="item-list" id="items-${order.id}">
                                    <!-- Itens serão adicionados aqui -->
                                </div>
                            </div>
                            <div class="card-footer bg-transparent border-top-0">
                                <button class="btn btn-success btn-lg w-100 complete-order" data-order-id="${order.id}">
                                    <i class="fas fa-check-circle me-2"></i> Dar Baixa (Finalizar Pedido)
                                </button>
                            </div>
                        </div>
                    `);
                    
                    // Adicionar itens ao pedido
                    const $itemsContainer = orderCard.find(`#items-${order.id}`);
                    
                    if (order.items && order.items.length > 0) {
                        $.each(order.items, function(itemIndex, item) {
                            const itemHtml = `
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong>${item.quantity}x ${item.name}</strong>
                                        ${item.description ? `<br><small class="text-muted">${item.description}</small>` : ''}
                                        ${item.notes ? `<div class="item-note"><i class="fas fa-sticky-note me-1"></i> ${item.notes}</div>` : ''}
                                    </div>
                                </div>
                            `;
                            $itemsContainer.append(itemHtml);
                        });
                    } else {
                        $itemsContainer.html('<p class="text-muted">Itens não disponíveis</p>');
                    }
                    
                    $ordersContainer.append(orderCard);
                });
                
                // Adicionar eventos aos botões
                $('.complete-order').off('click').on('click', function() {
                    const orderId = $(this).data('order-id');
                    completeOrder(orderId);
                });
            },
            error: function(xhr, status, error) {
                $loadingMessage.hide();
                $ordersContainer.html(`
                    <div class="alert alert-danger" role="alert">
                        <h4 class="alert-heading">
                            <i class="fas fa-exclamation-triangle me-2"></i>Erro ao carregar pedidos
                        </h4>
                        <p>Não foi possível carregar os pedidos do servidor.</p>
                        <hr>
                        <p class="mb-0">
                            <small>Detalhes: ${error}</small>
                        </p>
                    </div>
                `);
                $ordersContainer.show();
                console.error('Erro ao carregar pedidos:', error);
            }
        });
    }
    
    // Função para finalizar pedido
    function completeOrder(orderId) {
        // Desabilitar botão e mostrar loading
        $(`#order-${orderId} .complete-order`)
            .prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processando...');
        
        $.ajax({
            url: `/api/orders/${orderId}/complete`,
            method: 'POST',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Animar remoção do pedido
                    $(`#order-${orderId}`)
                        .addClass('completed')
                        .fadeOut(500, function() {
                            $(this).remove();
                            showToast(`Pedido #${orderId} finalizado com sucesso!`);
                            loadOrders(); // Recarregar lista
                        });
                } else {
                    alert(response.error || 'Erro ao finalizar pedido');
                    $(`#order-${orderId} .complete-order`)
                        .prop('disabled', false)
                        .html('<i class="fas fa-check-circle me-2"></i> Dar Baixa (Finalizar Pedido)');
                }
            },
            error: function(xhr, status, error) {
                alert('Erro ao finalizar pedido. Tente novamente.');
                $(`#order-${orderId} .complete-order`)
                    .prop('disabled', false)
                    .html('<i class="fas fa-check-circle me-2"></i> Dar Baixa (Finalizar Pedido)');
                console.error('Erro ao finalizar pedido:', error);
            }
        });
    }
    
    // Event Listeners
    $refreshBtn.on('click', function() {
        $(this).find('i').addClass('fa-spin');
        loadOrders();
        setTimeout(() => {
            $(this).find('i').removeClass('fa-spin');
        }, 1000);
    });
    
    // Iniciar
    loadOrders();
    
    // Configurar atualização automática
    refreshInterval = setInterval(loadOrders, REFRESH_INTERVAL);
    
    // Parar atualização automática quando a página não estiver visível
    $(window).on('blur', function() {
        clearInterval(refreshInterval);
    });
    
    $(window).on('focus', function() {
        clearInterval(refreshInterval);
        refreshInterval = setInterval(loadOrders, REFRESH_INTERVAL);
        loadOrders(); // Recarregar ao retornar
    });
});