<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Caixa - Novo Pedido</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .menu-item-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .menu-item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .menu-item-card.selected {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .selected-items-list {
            max-height: 300px;
            overflow-y: auto;
        }
        .quantity-input {
            width: 70px;
        }

        /* Animação ao adicionar item */
        @keyframes pulse {
            0% { transform: scale(1); background-color: rgba(13,110,253,0); }
            50% { transform: scale(1.02); background-color: rgba(13,110,253,0.1); }
            100% { transform: scale(1); background-color: rgba(13,110,253,0); }
        }
        .menu-item-card.added {
            animation: pulse 0.5s ease;
        }

        /* Melhorias nos itens selecionados */
        .selected-item {
            transition: all 0.2s;
            background-color: #fff;
        }
        .selected-item:hover {
            background-color: #f8f9fa;
        }
        .notes-icon {
            cursor: pointer;
            color: #6c757d;
        }
        .notes-icon:hover {
            color: #0d6efd;
        }
        .notes-textarea {
            transition: all 0.3s;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row mb-4">
            <div class="col-12">
                <h1 class="display-5">
                    <i class="fas fa-cash-register me-2"></i>Caixa - Novo Pedido
                </h1>
                <hr>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Selecione os Itens:</h5>
                    </div>
                    <div class="card-body">
                        <!-- Nova barra de categorias -->
                        <ul class="nav nav-tabs mb-3" id="categoryTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-category="all">Todos</button>
                            </li>
                        </ul>

                        <div id="menuItems" class="row">
                            <!-- Itens do menu serão carregados aqui -->
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Resumo do Pedido</h5>
                    </div>
                    <div class="card-body">
                        <div id="orderSummary">
                            <p class="text-muted">Nenhum item selecionado</p>
                        </div>
                        
                        <div class="mt-3">
                            <button id="submitOrder" class="btn btn-success btn-lg w-100" disabled>
                                <i class="fas fa-paper-plane me-2"></i>Enviar Pedido
                            </button>
                        </div>
                        
                        <div class="mt-3" id="messageContainer">
                            <!-- Mensagens de sucesso/erro aparecerão aqui -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    
    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/your-font-awesome-kit.js" crossorigin="anonymous"></script>
    
    <!-- Nosso JavaScript -->
    <script src="app.js" charset="UTF-8">></script>
</body>
</html>