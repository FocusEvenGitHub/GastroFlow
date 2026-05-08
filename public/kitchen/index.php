<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cozinha – Pedidos e Ingredientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .order-card { border-left: 5px solid #ffc107; transition: all 0.3s; }
        .order-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .order-card.completed { border-left-color: #28a745; opacity: 0.7; }
        .item-note { font-size: 0.85em; color: #6c757d; font-style: italic; }
        .ingredient-category-title { border-bottom: 2px solid #0d6efd; padding-bottom: 0.3rem; margin-bottom: 0.8rem; }
        .ingredient-row.meat { background-color: #fff5f5; }
        .ingredient-row.grain { background-color: #fffff0; }
        .ingredient-row.vegetable { background-color: #f0fff0; }
    </style>
</head>
<body>
<div x-data="kitchenApp()" class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2"><i class="fas fa-utensils me-2"></i>Cozinha – Pedidos Pendentes</h1>
        <div>
            <button @click="refresh" class="btn btn-outline-primary me-2">
                <i class="fas fa-sync-alt me-1"></i> Atualizar
            </button>
            <span class="badge bg-secondary fs-6" x-text="orders.length + ' pedido(s)'"></span>
        </div>
    </div>

    <!-- Mensagens -->
    <div x-show="message.text" x-transition class="mb-3">
        <div :class="'alert alert-'+message.type+' alert-dismissible fade show'" role="alert">
            <span x-text="message.text"></span>
            <button type="button" class="btn-close" @click="message.text=''"></button>
        </div>
    </div>

    <div class="row">
        <!-- Coluna da esquerda: pedidos -->
        <div class="col-md-8">
            <h4 class="mb-3"><i class="fas fa-clipboard-list me-2"></i>Pedidos</h4>

            <div x-show="loading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2">Carregando pedidos...</p>
            </div>

            <div x-show="!loading">
                <div x-show="orders.length === 0" class="text-center py-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h3>Tudo pronto!</h3>
                    <p class="text-muted">Nenhum pedido pendente no momento.</p>
                </div>

                <template x-for="order in orders" :key="order.id">
                    <div class="card order-card mb-4" :class="{ 'completed': completing === order.id }" x-show="!order.hidden">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Pedido #<span x-text="order.id"></span></h5>
                                <small class="text-muted" x-text="order.created_at"></small>
                            </div>
                            <div>
                                <span class="badge bg-primary"><i class="fas fa-table me-1"></i> Mesa <span x-text="order.table_number"></span></span>
                                <span class="badge bg-warning ms-2">Pendente</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <h6>Itens:</h6>
                            <template x-for="item in order.items" :key="item.name">
                                <div class="d-flex justify-content-between mb-2">
                                    <div>
                                        <strong x-text="item.quantity + 'x ' + item.name"></strong>
                                        <div x-show="item.notes" class="item-note"><i class="fas fa-sticky-note me-1"></i><span x-text="item.notes"></span></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="card-footer bg-transparent border-top-0">
                            <button class="btn btn-success btn-lg w-100" @click="completeOrder(order.id)"
                                    :disabled="completing === order.id">
                                <span x-show="completing !== order.id"><i class="fas fa-check-circle me-2"></i> Dar Baixa (Finalizar)</span>
                                <span x-show="completing === order.id"><span class="spinner-border spinner-border-sm me-2"></span> Finalizando...</span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Coluna da direita: resumo de ingredientes -->
        <div class="col-md-4">
            <div class="card shadow-sm sticky-top" style="top: 1rem;">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-carrot me-2"></i>Ingredientes necessários</h5>
                </div>
                <div class="card-body" x-show="ingredientSummary.length === 0">
                    <p class="text-muted">Nenhum pedido pendente.</p>
                </div>
                <div class="card-body" x-show="ingredientSummary.length > 0" style="max-height: 70vh; overflow-y: auto;">
                    <template x-for="(items, category) in groupedIngredients" :key="category">
                        <div class="mb-3">
                            <h6 class="ingredient-category-title text-capitalize" x-text="translateCategory(category)"></h6>
                            <template x-for="ing in items" :key="ing.id">
                                <div class="ingredient-row d-flex justify-content-between align-items-center p-2 rounded mb-1" :class="category">
                                    <div>
                                        <span x-text="ing.name"></span>
                                        <small class="text-muted ms-1" x-text="'(' + ing.unit + ')'"></small>
                                    </div>
                                    <strong class="text-primary" x-text="ing.total_quantity"></strong>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="app.js"></script>
</body>
</html>