<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cozinha – Pedidos</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link href="/vendor/bootstrap-5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/vendor/fontawesome-free-6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: var(--bg); font-size: 1.2rem; }
        /* ── Cards de pedido ── */
        .compact-card { border-left: 6px solid #ffc107; transition: all 0.15s; border-radius: 0.75rem; }
        .compact-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
        .compact-card.completing { border-left-color: var(--success); opacity: 0.7; }
        .compact-card.done { border-left-color: var(--success); opacity: 0.8; }
        .compact-card .card-header {
            padding: 1rem 1.5rem;
            background: transparent;
            border-bottom: 1px solid rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .compact-card .card-body { padding: 0.75rem 1.5rem 1rem; }
        .item-row { font-size: 1.6rem; line-height: 1.4; }
        /* Observação do item: vermelho chamativo; --danger já muda no tema escuro (spec 031) */
        .item-note {
            display: inline-block; margin: 0.15rem 0 0.35rem 2.4rem; padding: 0.2rem 0.7rem;
            font-size: 1.15rem; font-weight: 700; color: var(--danger);
            background: rgba(214, 48, 49, 0.05)!important; border-left: 4px solid var(--danger); border-radius: 0.35rem;
        }
        [data-theme="dark"] .item-note { background: rgba(248, 81, 73, 0.16); }
        .btn-sm-icon { padding: 0.5rem 1rem; font-size: 1.4rem; border-radius: 0.5rem; }
        /* ── Resumo de ingredientes ── */
        .summary-card .cat-protein { border-left: 6px solid #dc3545; }
        .summary-card .cat-grain { border-left: 6px solid #ffc107; }
        .summary-card .cat-vegetable { border-left: 6px solid #28a745; }
        .summary-card .cat-sauce { border-left: 6px solid #17a2b8; }
        .summary-card .cat-side { border-left: 6px solid #6f42c1; }
        .badge-table { font-size: 1.1rem; padding: 0.3rem 0.6rem; }
        .section-header { font-size: 1.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text); }
        .done-toggle { cursor: pointer; user-select: none; }
        .done-toggle:hover { color: var(--text); }
        /* ── Alternância grade / lista ── */
        .view-toggle .btn { border-radius: 4px; padding: 0.4rem 0.7rem; font-size: 1rem; }
        .view-list .col { width: 100%; flex: 0 0 100%; max-width: 100%; }
        .view-list .compact-card .card-body { padding: 0.5rem 1.5rem 0.75rem; }
        .view-list .item-row { font-size: 1.5rem; }
        /* ── Monte Seu Prato: adicionais escolhidos (spec 030) ── */
        .build-components { display: flex; flex-wrap: wrap; gap: 0.35rem; margin: 0.2rem 0 0.4rem 2.4rem; }
        .build-chip { font-size: 1.05rem; line-height: 1.3; background: var(--primary-light); border: 1px solid var(--primary); border-radius: 999px; padding: 0.15rem 0.7rem; color: var(--text); }
        [data-theme="dark"] .build-chip { background: rgba(225,112,85,0.18); }
        /* ── Resumo de pratos (spec 030) ── */
        .summary-toggle .btn { font-size: 0.85rem; padding: 0.25rem 0.6rem; }
        .dish-total { background: var(--primary); color: #fff; }
        .dish-total-count { font-size: 2.2rem; font-weight: 800; line-height: 1; }
        .dish-variant { font-size: 0.95rem; border-left: 3px solid var(--primary); }
        /* ── Modal "Editar Pedido" (redesign) ── */
        .edit-order-fields { background: var(--bg); border: 1px solid var(--border); border-radius: 0.6rem; padding: 0.9rem 1rem 0.6rem; }
        .edit-items-heading { font-size: 0.95rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin: 1.15rem 0 0.6rem; display: flex; justify-content: space-between; align-items: center; }
        .edit-item-card { border: 1px solid var(--border); border-radius: 0.6rem; padding: 0.85rem 1rem; margin-bottom: 0.75rem; }
        .edit-item-title { font-size: 1.05rem; }
        .edit-item-remove { color: var(--text-muted); }
        .edit-item-remove:hover { color: var(--danger); }
        .dining-toggle .btn { font-size: 0.8rem; padding: 0.35rem 0.7rem; font-weight: 600; }
        .qty-stepper .btn { font-size: 1.1rem; font-weight: 700; padding: 0.35rem 0.8rem; }
        .qty-stepper .qty-value { font-weight: 700; min-width: 1.6rem; text-align: center; }
        .add-item-card { border: 1px dashed var(--border); border-radius: 0.6rem; padding: 0.85rem 1rem; }
        [data-theme="dark"] .edit-item-card,
        [data-theme="dark"] .edit-order-fields { background: rgba(255,255,255,0.03); }
    </style>
    <script>
        if (localStorage.getItem('gastroflow_darkMode') === 'true') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
</head>
<body>
<div x-data="kitchenApp()">
    <!-- Navbar -->
    <nav class="gastro-nav">
        <a href="/cashier/" class="gastro-nav-brand">
            <i class="fas fa-utensils"></i>
            <span>GastroFlow</span>
        </a>
        <div class="gastro-nav-links">
            <a href="/cashier/"><i class="fas fa-cash-register"></i>Caixa</a>
            <a href="/kitchen/" class="active"><i class="fas fa-fire"></i>Cozinha</a>
            <a href="/admin/"><i class="fas fa-cog"></i>Admin</a>
        </div>
        <button class="dark-toggle" @click="toggleDarkMode()" title="Alternar tema">
            <i class="fas" :class="darkMode ? 'fa-sun' : 'fa-moon'"></i>
        </button>
    </nav>

    <!-- Impressão bloqueada após falhas consecutivas (spec 039).
         x-if, e não x-show: as utilitárias do Bootstrap 5 são !important, então
         .d-flex vence o display:none inline que o x-show aplica e o aviso nunca sumiria
         (pego pela verificação no navegador). x-if remove do DOM. -->
    <template x-if="printerBlocked">
    <div class="alert alert-danger d-flex align-items-center justify-content-between m-3">
        <span>
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Impressão bloqueada.</strong>
            As últimas <span x-text="printerFailures"></span> impressões falharam.
            Verifique a impressora e reative.
        </span>
        <button class="btn btn-sm btn-danger" @click="reactivatePrinting()" :disabled="reactivatingPrinter">
            <span x-show="!reactivatingPrinter">Reativar impressão</span>
            <span x-show="reactivatingPrinter"><span class="spinner-border spinner-border-sm"></span></span>
        </button>
    </div>
    </template>

    <!-- Toast container -->
    <?php include dirname(__DIR__) . '/_partials/toasts.php'; ?>

    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Cozinha</h1>
            <div class="d-flex align-items-center gap-2">
                <label class="d-flex align-items-center gap-1 mb-0" style="cursor:pointer"
                       @click="$refs.dateInput.showPicker()">
                    <span class="form-label mb-0 small text-nowrap">Data:</span>
                    <input type="date" x-ref="dateInput"
                           class="form-control form-control-sm"
                           style="width:140px"
                           x-model="selectedDate"
                           @change="onDateChange()">
                </label>
                <div class="view-toggle btn-group btn-group-sm me-2">
                    <button class="btn btn-outline-secondary" :class="{ 'btn-primary active': viewMode === 'grid' }"
                            @click="toggleView('grid')" title="Visualização em grade">
                        <i class="fas fa-th-large"></i>
                    </button>
                    <button class="btn btn-outline-secondary" :class="{ 'btn-primary active': viewMode === 'list' }"
                            @click="toggleView('list')" title="Visualização em lista">
                        <i class="fas fa-list"></i>
                    </button>
                </div>
                <button @click="refresh" class="btn btn-outline-primary btn-sm" title="Atualizar">
                    <i class="fas fa-sync-alt"></i>
                </button>
                <span class="badge bg-secondary align-middle" x-text="orders.length + ' pendente(s)'"></span>
                <span class="badge bg-success align-middle ms-1" x-text="completedOrders.length + ' finalizado(s)'"></span>
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
                    <div class="row row-cols-1 row-cols-xl-2 g-3" :class="viewMode === 'list' ? 'view-list' : ''">
                        <template x-for="order in orders" :key="order.id">
                            <div class="col">
                                <div class="card compact-card mb-0 h-100" :class="{ 'completing': completing === order.id }">
                                    <div class="card-header">
                                        <div class="d-flex align-items-center gap-2">
                                            <strong class="small" x-text="displayName(order)"></strong>
                                            <span class="badge bg-primary badge-table"><i class="fas fa-hashtag me-1"></i><span x-text="order.order_number"></span></span>
                                            <small class="text-muted" x-text="timeAgo(order)"></small>
                                        </div>
                                        <div class="d-flex align-items-center gap-1">
                                            <button class="btn btn-outline-secondary btn-sm-icon" @click="reprintOrder(order.id)" :disabled="reprinting === order.id || printerBlocked" :title="printerBlocked ? 'Impressão bloqueada após falhas consecutivas' : 'Reimprimir nota'">
                                                <span x-show="reprinting !== order.id"><i class="fas fa-print"></i></span>
                                                <span x-show="reprinting === order.id"><span class="spinner-border spinner-border-sm"></span></span>
                                            </button>
                                            <button class="btn btn-outline-primary btn-sm-icon" @click="openEditModal(order)" title="Editar pedido">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <button class="btn btn-success btn-sm-icon" @click="completeOrder(order.id)" :disabled="completing === order.id" title="Dar Baixa">
                                                <span x-show="completing !== order.id"><i class="fas fa-check"></i></span>
                                                <span x-show="completing === order.id"><span class="spinner-border spinner-border-sm"></span></span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <template x-for="item in kitchenItems(order)" :key="item.item_id">
                                            <div class="mb-1">
                                                <div class="item-row d-flex justify-content-between">
                                                    <span>
                                                        <strong x-text="item.quantity + 'x'"></strong>
                                                        <template x-if="item.dining_option === 'viagem_simples'">
                                                            <span class="badge bg-warning text-dark me-1">Simples</span>
                                                        </template>
                                                        <template x-if="item.dining_option === 'viagem_vip'">
                                                            <span class="badge bg-danger me-1">VIP</span>
                                                        </template>
                                                        <i class="fas fa-magic text-primary me-1" x-show="item.components && item.components.length" title="Prato montado"></i>
                                                        <span x-text="item.name"></span>
                                                    </span>
                                                </div>
                                                <template x-if="item.components && item.components.length">
                                                    <div class="build-components">
                                                        <template x-for="c in item.components" :key="c.menu_item_id">
                                                            <span class="build-chip"><strong x-text="c.quantity + 'x'"></strong> <span x-text="c.name"></span></span>
                                                        </template>
                                                    </div>
                                                </template>
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
                        <div class="row row-cols-1 row-cols-xl-2 g-3" :class="viewMode === 'list' ? 'view-list' : ''">
                            <template x-for="order in completedOrders" :key="order.id">
                                <div class="col">
                                    <div class="card compact-card done mb-0 h-100">
                                        <div class="card-header">
                                            <div class="d-flex align-items-center gap-2">
                                                <strong class="small" x-text="displayName(order)"></strong>
                                                <span class="badge bg-secondary badge-table"><i class="fas fa-hashtag me-1"></i><span x-text="order.order_number"></span></span>
                                                <small class="text-muted" x-text="timeAgo(order)"></small>
                                            </div>
                                            <div class="d-flex align-items-center gap-1">
                                                <button class="btn btn-outline-secondary btn-sm-icon" @click="reprintOrder(order.id)" :disabled="reprinting === order.id || printerBlocked" :title="printerBlocked ? 'Impressão bloqueada após falhas consecutivas' : 'Reimprimir nota'">
                                                    <span x-show="reprinting !== order.id"><i class="fas fa-print"></i></span>
                                                    <span x-show="reprinting === order.id"><span class="spinner-border spinner-border-sm"></span></span>
                                                </button>
                                                <button class="btn btn-outline-primary btn-sm-icon" @click="openEditModal(order)" title="Editar pedido">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="btn btn-outline-secondary btn-sm-icon" @click="uncompleteOrder(order.id)" :disabled="uncompleting === order.id" title="Estornar Baixa">
                                                    <span x-show="uncompleting !== order.id"><i class="fas fa-undo"></i></span>
                                                    <span x-show="uncompleting === order.id"><span class="spinner-border spinner-border-sm"></span></span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <template x-for="item in kitchenItems(order)" :key="item.item_id">
                                                <div class="mb-1">
                                                    <div class="item-row d-flex justify-content-between">
                                                        <span>
                                                        <strong x-text="item.quantity + 'x'"></strong>
                                                        <template x-if="item.dining_option === 'viagem_simples'">
                                                            <span class="badge bg-warning text-dark me-1">Simples</span>
                                                        </template>
                                                        <template x-if="item.dining_option === 'viagem_vip'">
                                                            <span class="badge bg-danger me-1">VIP</span>
                                                        </template>
                                                        <i class="fas fa-magic text-primary me-1" x-show="item.components && item.components.length" title="Prato montado"></i>
                                                        <span x-text="item.name"></span>
                                                    </span>
                                                    </div>
                                                    <template x-if="item.components && item.components.length">
                                                        <div class="build-components">
                                                            <template x-for="c in item.components" :key="c.menu_item_id">
                                                                <span class="build-chip"><strong x-text="c.quantity + 'x'"></strong> <span x-text="c.name"></span></span>
                                                            </template>
                                                        </div>
                                                    </template>
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
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <h6 class="mb-0">
                        <i class="fas me-1 text-primary" :class="summaryMode === 'dishes' ? 'fa-utensils' : 'fa-chart-bar'"></i>
                        <span x-text="summaryMode === 'dishes' ? 'Resumo de Pratos' : 'Resumo de Ingredientes'"></span>
                    </h6>
                    <div class="btn-group btn-group-sm summary-toggle" role="group" aria-label="Tipo de resumo">
                        <button class="btn" :class="summaryMode === 'ingredients' ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="setSummaryMode('ingredients')">Ingredientes</button>
                        <button class="btn" :class="summaryMode === 'dishes' ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="setSummaryMode('dishes')">Pratos</button>
                    </div>
                </div>
                <!-- Resumo de pratos (spec 030): pratos principais pendentes, por prato -->
                <div class="card-body py-3 px-4" x-show="summaryMode === 'dishes'">
                    <div x-show="dishSummary().total === 0" class="text-center text-muted py-3">
                        Nenhum prato pendente no momento.
                    </div>
                    <template x-if="dishSummary().total > 0">
                        <div>
                            <div class="dish-total d-flex justify-content-between align-items-center px-3 py-3 mb-3 rounded">
                                <span class="fw-bold text-uppercase small">Total de pratos</span>
                                <span class="dish-total-count" x-text="dishSummary().total"></span>
                            </div>
                            <div class="row row-cols-1 g-2">
                                <template x-for="dish in dishSummary().dishes" :key="dish.name">
                                    <div class="col">
                                        <div class="px-3 py-2 rounded" style="background:#f8f9fa;">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span x-text="dish.name" style="max-width:70%"></span>
                                                <span class="badge bg-primary badge-table" x-text="dish.quantity"></span>
                                            </div>
                                            <template x-for="variant in dish.variants" :key="variant.label">
                                                <div class="dish-variant d-flex justify-content-between gap-2 text-muted mt-1 ps-2">
                                                    <span x-text="variant.label"></span>
                                                    <strong class="text-nowrap" x-text="variant.quantity + 'x'"></strong>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="card-body py-3 px-4" x-show="summaryMode === 'ingredients'">
                    <div x-show="Object.keys(groupedSummary()).length === 0" class="text-center text-muted py-3">
                        Nenhum ingrediente necessário no momento.
                    </div>

                    <template x-for="(items, category) in groupedSummary()" :key="category">
                        <div class="mb-2">
                            <div class="fw-bold text-muted d-block border-bottom pb-2 mb-2"
                                   x-text="category === 'protein' ? 'Proteínas' :
                                          category === 'grain' ? 'Grãos' :
                                          category === 'vegetable' ? 'Vegetais' :
                                          category === 'sauce' ? 'Molhos' :
                                          category === 'side' ? 'Acompanhamentos' :
                                          'Outros'">
                            </div>
                            <div class="row row-cols-1 g-2">
                                <template x-for="item in items" :key="item.id">
                                    <div class="col">
                                        <div class="d-flex justify-content-between align-items-center px-3 py-2 rounded"
                                             :class="'cat-' + item.food_category"
                                             style="background:#f8f9fa;">
                                            <span x-text="item.name" style="max-width:70%"></span>
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

<!-- Modal de editar/remover pedido -->
<div class="modal fade" id="editOrderModal" tabindex="-1" data-bs-backdrop="static"
     x-effect="(() => { const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editOrderModal')); editingOrder ? modal.show() : modal.hide(); })()">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="font-size: 1rem;">
            <template x-if="editingOrder">
                <div class="d-flex flex-column" style="max-height: 85vh;">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-pencil-alt text-primary me-2"></i>Editar Pedido <span x-text="'#' + editingOrder.id"></span></h5>
                        <button type="button" class="btn-close" @click="closeEditModal()"></button>
                    </div>
                    <div class="modal-body" style="overflow-y: auto;">
                        <div class="edit-order-fields row g-3">
                            <div class="col-sm-5">
                                <label class="form-label small fw-bold mb-1">Senha</label>
                                <input type="text" class="form-control" x-model="editingOrder.order_number">
                            </div>
                            <div class="col-sm-7">
                                <label class="form-label small fw-bold mb-1">Cliente</label>
                                <input type="text" class="form-control" placeholder="Nome do cliente (opcional)" x-model="editingOrder.customer_name">
                            </div>
                        </div>

                        <div class="edit-items-heading">
                            <span>Itens do pedido</span>
                            <span class="badge bg-secondary" x-text="editingOrder.items.length"></span>
                        </div>

                        <template x-for="item in editingOrder.items" :key="item.item_id">
                            <div class="edit-item-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="edit-item-title fw-bold" x-text="item.name"></span>
                                        <span class="badge bg-secondary ms-1" x-text="item.category_name || 'Sem categoria'"></span>
                                    </div>
                                    <button class="btn btn-sm btn-link edit-item-remove p-0" @click="removeItemFromModal(item.item_id)" :disabled="removingItemId === item.item_id" title="Remover item">
                                        <span x-show="removingItemId !== item.item_id"><i class="fas fa-trash"></i></span>
                                        <span x-show="removingItemId === item.item_id"><span class="spinner-border spinner-border-sm"></span></span>
                                    </button>
                                </div>
                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Observação (opcional)"
                                       x-model="item.notes">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <!-- Seletor de onde comer (spec 046) -->
                                    <div class="btn-group dining-toggle" role="group">
                                        <button type="button" class="btn"
                                                :class="item.dining_option === 'local' ? 'btn-success' : 'btn-outline-success'"
                                                @click="item.dining_option = 'local'" title="Consumo no local">
                                            Local
                                        </button>
                                        <button type="button" class="btn"
                                                :class="item.dining_option === 'viagem_simples' ? 'btn-warning' : 'btn-outline-warning'"
                                                @click="item.dining_option = 'viagem_simples'" :title="packagingTitle('Viagem Simples', 'viagem_simples')">
                                            Simples
                                        </button>
                                        <button type="button" class="btn"
                                                :class="item.dining_option === 'viagem_vip' ? 'btn-danger' : 'btn-outline-danger'"
                                                @click="item.dining_option = 'viagem_vip'" :title="packagingTitle('Viagem VIP', 'viagem_vip')">
                                            VIP
                                        </button>
                                    </div>
                                    <div class="btn-group qty-stepper" role="group">
                                        <button class="btn btn-outline-secondary" @click="item.quantity = Math.max(1, item.quantity - 1)">−</button>
                                        <span class="qty-value d-flex align-items-center px-2" x-text="item.quantity"></span>
                                        <button class="btn btn-outline-secondary" @click="item.quantity++">+</button>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div class="add-item-card d-flex flex-wrap gap-2 align-items-end">
                            <div class="flex-grow-1">
                                <label class="form-label small mb-1">Adicionar item</label>
                                <select class="form-select form-select-sm" x-model.number="newItemId">
                                    <option value="">Selecione um item...</option>
                                    <template x-for="item in allMenuItems()" :key="item.id">
                                        <option :value="item.id" x-text="item.category_name + ' — ' + item.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div style="width:80px">
                                <label class="form-label small mb-1">Qtd</label>
                                <input type="number" min="1" class="form-control form-control-sm" x-model.number="newItemQty">
                            </div>
                            <button class="btn btn-sm btn-primary" @click="addItemToModal()" :disabled="!newItemId || addingItem" title="Adicionar item ao pedido">
                                <span x-show="!addingItem"><i class="fas fa-plus"></i></span>
                                <span x-show="addingItem"><span class="spinner-border spinner-border-sm"></span></span>
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button class="btn btn-outline-danger" @click="cancelOrder()" :disabled="savingOrder">
                            <i class="fas fa-ban me-1"></i>Cancelar Pedido
                        </button>
                        <div>
                            <button class="btn btn-outline-secondary me-2" @click="closeEditModal()">Fechar</button>
                            <button class="btn btn-primary" @click="saveOrderChanges()" :disabled="savingOrder">
                                <span x-show="!savingOrder"><i class="fas fa-save me-1"></i>Salvar</span>
                                <span x-show="savingOrder"><span class="spinner-border spinner-border-sm"></span></span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script defer src="/vendor/alpinejs-3.17.4/cdn.min.js"></script>
<script src="/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/gf.js?v=<?= filemtime(dirname(__DIR__) . '/assets/js/gf.js') ?>"></script>
<script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
<script src="/assets/js/version-badge.js"></script>
</body>
</html>
