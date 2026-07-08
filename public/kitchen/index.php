<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cozinha – Pedidos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f6fa; }
        .compact-card { border-left: 4px solid #ffc107; transition: all 0.15s; }
        .compact-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .compact-card.completing { border-left-color: #28a745; opacity: 0.6; }
        .compact-card.done { border-left-color: #28a745; opacity: 0.75; }
        .compact-card .card-header {
            padding: 0.4rem 0.75rem;
            background: transparent;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .compact-card .card-body { padding: 0.4rem 0.75rem; }
        .item-row { font-size: 0.85rem; line-height: 1.3; }
        .item-note { font-size: 0.75em; color: #6c757d; font-style: italic; }
        .btn-sm-icon { padding: 0.15rem 0.4rem; font-size: 0.75rem; }
        .summary-card .cat-protein { border-left: 4px solid #dc3545; }
        .summary-card .cat-grain { border-left: 4px solid #ffc107; }
        .summary-card .cat-vegetable { border-left: 4px solid #28a745; }
        .summary-card .cat-sauce { border-left: 4px solid #17a2b8; }
        .badge-table { font-size: 0.7rem; }
        .section-header { font-size: 0.9rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; }
        .done-toggle { cursor: pointer; user-select: none; }
        .done-toggle:hover { color: #212529; }
    </style>
</head>
<body>
<div x-data="kitchenApp()" class="container-fluid py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h1 class="h5 mb-0"><i class="fas fa-utensils me-2 text-primary"></i>Cozinha</h1>
        <div>
            <button @click="refresh" class="btn btn-outline-primary btn-sm me-2" title="Atualizar">
                <i class="fas fa-sync-alt"></i>
            </button>
            <span class="badge bg-secondary align-middle" x-text="orders.length + ' pendente(s)'"></span>
            <span class="badge bg-success align-middle ms-1" x-text="completedOrders.length + ' finalizado(s)'"></span>
        </div>
    </div>

    <!-- Alert -->
    <div x-show="message.text" x-transition class="mb-2">
        <div :class="'alert alert-'+message.type+' alert-dismissible fade show py-2 mb-0 small'" role="alert">
            <span x-text="message.text"></span>
            <button type="button" class="btn-close py-2" @click="message.text=''"></button>
        </div>
    </div>

    <!-- Loading -->
    <div x-show="loading" class="text-center py-4">
        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
        <span class="ms-2 small text-muted">Carregando pedidos...</span>
    </div>

    <div x-show="!loading" class="row">
        <!-- Left: Orders -->
        <div class="col-lg-8">

            <template x-if="orders.length === 0 && completedOrders.length === 0">
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <h6>Tudo pronto!</h6>
                    <p class="small text-muted mb-0">Nenhum pedido no momento.</p>
                </div>
            </template>

            <template x-if="orders.length > 0">
                <div class="mb-3">
                    <div class="section-header mb-2">
                        <i class="fas fa-clock me-1 text-warning"></i> Pendentes
                        <span class="badge bg-warning text-dark badge-table ms-1" x-text="orders.length"></span>
                    </div>
                    <div class="row row-cols-1 row-cols-xl-2 g-2">
                        <template x-for="order in orders" :key="order.id">
                            <div class="col">
                                <div class="card compact-card mb-0 h-100" :class="{ 'completing': completing === order.id }">
                                    <div class="card-header">
                                        <div class="d-flex align-items-center gap-2">
                                            <strong class="small" x-text="displayName(order)"></strong>
                                            <span class="badge bg-primary badge-table"><i class="fas fa-hashtag me-1"></i><span x-text="order.table_number"></span></span>
                                            <small class="text-muted" x-text="timeAgo(order.created_at)"></small>
                                        </div>
                                        <button class="btn btn-success btn-sm-icon" @click="completeOrder(order.id)" :disabled="completing === order.id" title="Dar Baixa">
                                            <span x-show="completing !== order.id"><i class="fas fa-check"></i></span>
                                            <span x-show="completing === order.id"><span class="spinner-border spinner-border-sm"></span></span>
                                        </button>
                                    </div>
                                    <div class="card-body">
                                        <template x-for="item in order.items" :key="item.name">
                                            <div class="mb-1">
                                                <div class="item-row d-flex justify-content-between">
                                                    <span>
                                                        <strong x-text="item.quantity + 'x'"></strong>
                                                        <template x-if="item.dining_option === 'viagem_simples'">
                                                            <span class="badge bg-warning text-dark me-1" style="font-size:0.6rem;">Simples</span>
                                                        </template>
                                                        <template x-if="item.dining_option === 'viagem_vip'">
                                                            <span class="badge bg-danger me-1" style="font-size:0.6rem;">VIP</span>
                                                        </template>
                                                        <span x-text="item.name"></span>
                                                    </span>
                                                </div>
                                                <div x-show="item.notes" class="item-note"><i class="fas fa-sticky-note me-1"></i><span x-text="item.notes"></span></div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="completedOrders.length > 0">
                <div>
                    <div class="section-header mb-2 done-toggle" @click="showDone = !showDone">
                        <i class="fas" :class="showDone ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                        Finalizados
                        <span class="badge bg-success badge-table ms-1" x-text="completedOrders.length"></span>
                    </div>
                    <div x-show="showDone">
                        <div class="row row-cols-1 row-cols-xl-2 g-2">
                            <template x-for="order in completedOrders" :key="order.id">
                                <div class="col">
                                    <div class="card compact-card done mb-0 h-100">
                                        <div class="card-header">
                                            <div class="d-flex align-items-center gap-2">
                                                <strong class="small" x-text="displayName(order)"></strong>
                                                <span class="badge bg-secondary badge-table"><i class="fas fa-hashtag me-1"></i><span x-text="order.table_number"></span></span>
                                                <small class="text-muted" x-text="timeAgo(order.created_at)"></small>
                                            </div>
                                            <button class="btn btn-outline-secondary btn-sm-icon" @click="uncompleteOrder(order.id)" :disabled="uncompleting === order.id" title="Estornar Baixa">
                                                <span x-show="uncompleting !== order.id"><i class="fas fa-undo"></i></span>
                                                <span x-show="uncompleting === order.id"><span class="spinner-border spinner-border-sm"></span></span>
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <template x-for="item in order.items" :key="item.name">
                                                <div class="mb-1">
                                                    <div class="item-row d-flex justify-content-between">
                                                        <span>
                                                        <strong x-text="item.quantity + 'x'"></strong>
                                                        <template x-if="item.dining_option === 'viagem_simples'">
                                                            <span class="badge bg-warning text-dark me-1" style="font-size:0.6rem;">Simples</span>
                                                        </template>
                                                        <template x-if="item.dining_option === 'viagem_vip'">
                                                            <span class="badge bg-danger me-1" style="font-size:0.6rem;">VIP</span>
                                                        </template>
                                                        <span x-text="item.name"></span>
                                                    </span>
                                                    </div>
                                                    <div x-show="item.notes" class="item-note"><i class="fas fa-sticky-note me-1"></i><span x-text="item.notes"></span></div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Right: Food Summary -->
        <div class="col-lg-4">
            <div class="card shadow-sm summary-card sticky-lg-top" style="top:1rem;">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 small"><i class="fas fa-chart-bar me-1 text-primary"></i>Resumo de Ingredientes</h6>
                </div>
                <div class="card-body py-2 px-3">
                    <div x-show="Object.keys(groupedSummary()).length === 0" class="text-center text-muted py-2">
                        <small>Nenhum ingrediente necessário no momento.</small>
                    </div>

                    <template x-for="(items, category) in groupedSummary()" :key="category">
                        <div class="mb-1">
                            <small class="fw-bold text-muted d-block border-bottom pb-1 mb-1"
                                   x-text="category === 'protein' ? 'Proteínas' :
                                          category === 'grain' ? 'Grãos' :
                                          category === 'vegetable' ? 'Vegetais' :
                                          category === 'sauce' ? 'Molhos' :
                                          category === 'side' ? 'Acompanhamentos' :
                                          'Outros'">
                            </small>
                            <div class="row row-cols-1 g-1">
                                <template x-for="item in items" :key="item.id">
                                    <div class="col">
                                        <div class="d-flex justify-content-between align-items-center px-2 py-1 rounded"
                                             :class="'cat-' + item.food_category"
                                             style="background:#f8f9fa;">
                                            <small class="text-truncate" x-text="item.name" style="max-width:70%"></small>
                                            <span class="badge badge-table" :class="item.food_category === 'protein' ? 'bg-danger' : 'bg-secondary'"
                                                  x-text="item.total_quantity"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
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
