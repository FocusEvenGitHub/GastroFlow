<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cozinha - Pedidos Pendentes</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome para ícones -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .order-card {
            transition: all 0.3s ease;
            border-left: 5px solid #ffc107;
        }
        .order-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .order-card.completed {
            border-left-color: #28a745;
            opacity: 0.7;
        }
        .item-list {
            border-top: 1px solid #eee;
            margin-top: 10px;
            padding-top: 10px;
        }
        .item-note {
            font-size: 0.85em;
            color: #6c757d;
            font-style: italic;
        }
        .order-header {
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .status-badge {
            font-size: 0.8em;
            padding: 4px 8px;
        }
        .fade-out {
            opacity: 0;
            transition: opacity 0.5s ease-out;
        }
        .table-badge {
            font-size: 1.1em;
            padding: 5px 15px;
        }
        .no-orders {
            padding: 50px 20px;
            text-align: center;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h1 class="display-5">
                        <i class="fas fa-utensils me-2"></i>Cozinha - Pedidos Pendentes
                    </h1>
                    <div>
                        <button id="refreshBtn" class="btn btn-outline-primary">
                            <i class="fas fa-sync-alt me-2"></i>Atualizar
                        </button>
                        <span class="badge bg-secondary ms-2" id="orderCount">0 pedidos</span>
                    </div>
                </div>
                <p class="lead text-muted">Gerencie e finalize os pedidos dos clientes</p>
                <hr>
            </div>
        </div>
        
        <div class="row">
            <div class="col-12">
                <div id="ordersContainer">
                    <!-- Os pedidos serão carregados aqui via JavaScript -->
                </div>
                
                <div id="noOrdersMessage" class="no-orders" style="display: none;">
                    <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                    <h3 class="text-success">Tudo pronto!</h3>
                    <p class="lead">Não há pedidos pendentes no momento.</p>
                    <p class="text-muted">Os novos pedidos aparecerão automaticamente aqui.</p>
                </div>
                
                <div id="loadingMessage" class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Carregando pedidos...</p>
                </div>
            </div>
        </div>
        
        <!-- Toast para mensagens -->
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
            <div id="liveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    <strong class="me-auto">Sistema Cozinha</strong>
                    <small>Agora</small>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body" id="toastMessage">
                    Pedido finalizado com sucesso!
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    
    <!-- Nosso JavaScript -->
    <script src="app.js" charset="UTF-8"></script>
</body>
</html>