<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Caixa – Novo Pedido</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .menu-item-card { cursor: pointer; transition: all 0.2s; }
        .menu-item-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .reorder-card { cursor: move; border: 1px dashed #ffc107; -webkit-user-select: none; user-select: none; }
        .selected-item { background: #fff; transition: background 0.2s; }
        .selected-item:hover { background: #f8f9fa; }
        .quantity-btn { min-width: 2rem; }
        .category-tab.active { font-weight: bold; border-bottom: 2px solid #0d6efd; }
        .print-switch { border-radius: 6px; transition: background 0.15s; }
        .print-switch:hover { background: #f8f9fa; }
        /* Alternância grade / lista */
        .view-toggle .btn { border-radius: 4px; padding: 0.25rem 0.5rem; font-size: 0.85rem; }
        .view-list .menu-item-col { width: 100%; flex: 0 0 100%; max-width: 100%; }
        .view-list .menu-item-card { flex-direction: row; align-items: center; }
        .view-list .menu-item-card .card-body { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 1rem; }
        .view-list .menu-item-card .card-body .item-desc { display: none; }
        .view-list .menu-item-card .card-body .item-footer { margin-left: auto; }
    </style>
    <script>
        if (localStorage.getItem('gastroflow_darkMode') === 'true') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
</head>
<body>
<div x-data="cashierApp()">
    <!-- Navbar -->
    <nav class="gastro-nav">
        <a href="/cashier/" class="gastro-nav-brand">
            <i class="fas fa-utensils"></i>
            <span>GastroFlow</span>
        </a>
        <div class="gastro-nav-links">
            <a href="/cashier/" class="active"><i class="fas fa-cash-register"></i>Caixa</a>
            <a href="/kitchen/"><i class="fas fa-fire"></i>Cozinha</a>
            <a href="/admin/"><i class="fas fa-cog"></i>Admin</a>
        </div>
        <button class="dark-toggle" @click="toggleDarkMode()" title="Alternar tema">
            <i class="fas" :class="darkMode ? 'fa-sun' : 'fa-moon'"></i>
        </button>
    </nav>

    <!-- Toast container -->
    <div class="toast-container" x-show="toasts.length">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="gastro-toast" :class="toast.type">
                <i class="fas gastro-toast-icon"
                   :class="toast.type === 'success' ? 'fa-check-circle' : toast.type === 'danger' ? 'fa-exclamation-circle' : toast.type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'"></i>
                <span class="gastro-toast-text" x-text="toast.text"></span>
                <button class="gastro-toast-close" @click="toasts = toasts.filter(t => t.id !== toast.id)">&times;</button>
            </div>
        </template>
    </div>

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">Novo Pedido</h1>
            <div class="d-flex align-items-center gap-2">
                <div class="view-toggle btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary" :class="{ 'btn-primary active': viewMode === 'grid' }"
                            @click="toggleView('grid')" title="Visualização em grade">
                        <i class="fas fa-th-large"></i>
                    </button>
                    <button class="btn btn-outline-secondary" :class="{ 'btn-primary active': viewMode === 'list' }"
                            @click="toggleView('list')" title="Visualização em lista">
                        <i class="fas fa-list"></i>
                    </button>
                </div>
                <button class="btn btn-sm" :class="reorderMode ? 'btn-warning' : 'btn-outline-secondary'"
                        @click="toggleReorderMode()" title="Reorganizar itens do cardápio">
                    <span x-show="!reordering"><i class="fas fa-arrows-alt me-1"></i><span x-text="reorderMode ? 'Concluir' : 'Reorganizar'"></span></span>
                    <span x-show="reordering"><span class="spinner-border spinner-border-sm me-1"></span>Salvando ordem...</span>
                </button>
                <div class="form-check form-switch print-switch mb-0" title="Quando ligado, o pedido é enviado para a impressora térmica">
                    <input class="form-check-input" type="checkbox" id="printToggle" x-model="printTicket" checked>
                    <label class="form-check-label small" for="printToggle">
                        <i class="fas fa-print text-secondary me-1"></i>Imprimir
                        <i class="fas fa-info-circle text-muted ms-1" title="Desligue para não enviar o pedido à impressora"></i>
                    </label>
                </div>
            </div>
        </div>

    <div class="row">
        <!-- Lado esquerdo: senha + cardápio -->
        <div class="col-md-8">
            <!-- Senha + Cliente -->
            <div class="row mb-4 g-3">
                <div class="col-md-6">
                    <label for="orderNumber" class="form-label fw-bold">Número da Senha *</label>
                    <input type="text" id="orderNumber" x-model="orderNumber" @input="orderNumberAuto = false"
                           class="form-control form-control-lg" placeholder="Ex: 42">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Nome do Cliente</label>
                    <input type="text" x-model="customerName"
                           class="form-control form-control-lg" placeholder="Ex: João Silva (opcional)">
                </div>
            </div>

            <!-- Busca -->
            <div class="mb-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" x-model="searchQuery" class="form-control"
                           placeholder="Buscar item pelo nome...">
                    <button class="btn btn-outline-secondary" type="button" x-show="searchQuery"
                            @click="searchQuery = ''" title="Limpar busca">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Categorias -->
            <ul class="nav nav-tabs mb-3" x-show="categories.length > 0">
                <li class="nav-item" @click="currentCategory='all'">
                    <span class="nav-link category-tab" :class="{ active: currentCategory === 'all' }" role="button">Todos</span>
                </li>
                <template x-for="cat in categories" :key="cat">
                    <li class="nav-item" @click="currentCategory = cat">
                        <span class="nav-link category-tab" :class="{ active: currentCategory === cat }" role="button" x-text="cat"></span>
                    </li>
                </template>
            </ul>

            <!-- Itens do cardápio -->
            <div x-show="loading">Carregando cardápio...</div>
            <div x-show="!loading && filteredMenu.length === 0 && searchQuery" class="text-muted">
                Nenhum item encontrado para "<span x-text="searchQuery"></span>".
            </div>
            <div x-show="!loading && filteredMenu.length === 0 && !searchQuery" class="text-muted">Nenhum item disponível.</div>
            <div class="row" x-show="!loading">
                <template x-for="category in filteredMenu" :key="category.category_name">
                    <div class="col-12 mb-4">
                        <h5 class="text-primary"><i class="fas" :class="category.type === 'food' ? 'fa-utensils' : 'fa-glass-cheers'"></i> <span x-text="category.category_name"></span></h5>
                        <div class="row" :class="viewMode === 'list' ? 'view-list' : ''">
                            <template x-for="(item, index) in category.items" :key="item.id">
                                <div class="menu-item-col col-md-4 col-sm-6 mb-3"
                                     :draggable="reorderMode && !reordering"
                                     @dragstart="dragStart(category, index)"
                                     @dragover.prevent
                                     @drop.prevent="dragDrop(category, index)">
                                    <div class="card menu-item-card h-100" :class="{ 'reorder-card': reorderMode, 'opacity-50': item.available === false }" @click="!reorderMode && item.available !== false && addItem(item)">
                                        <div class="card-body">
                                            <h6 class="card-title" x-text="item.name"></h6>
                                            <span class="badge bg-secondary" x-show="item.available === false">Indisponível</span>
                                            <template x-if="category.category_name !== 'Pratos Principais'">
                                                <p class="card-text text-muted small item-desc" x-text="item.description || 'Sem descrição'"></p>
                                            </template>
                                            <template x-if="category.category_name === 'Pratos Principais'">
                                                <p class="card-text text-muted small item-desc">
                                                    <i class="fas fa-layer-group me-1 text-primary"></i>
                                                    <span x-text="(item.components || []).map(c => c.name + ' x' + c.quantity).join(', ')"></span>
                                                </p>
                                            </template>
                                            <div class="d-flex justify-content-between align-items-center item-footer">
                                                <span class="h5 text-success mb-0">R$ <span x-text="item.price.toFixed(2)"></span></span>
                                                <button class="btn btn-sm btn-outline-primary" @click.stop="addItem(item)" x-show="!reorderMode" :disabled="item.available === false">
                                                    <i class="fas fa-plus"></i> Adicionar
                                                </button>
                                                <span class="text-muted" x-show="reorderMode" title="Arraste para reorganizar"><i class="fas fa-grip-lines"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Lado direito: resumo do pedido -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Resumo do Pedido</h5>
                </div>
                <div class="card-body">
                    <div x-show="selectedItems.length === 0" class="text-muted">Nenhum item selecionado</div>

                    <template x-for="(item, index) in selectedItems" :key="item.id">
                        <div class="selected-item mb-2 p-2 border rounded">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong x-text="item.name"></strong>
                                    <!-- Badge da opção de consumo -->
                                    <template x-if="item.diningOption === 'viagem_simples'">
                                        <span class="badge bg-warning text-dark ms-1">Simples</span>
                                    </template>
                                    <template x-if="item.diningOption === 'viagem_vip'">
                                        <span class="badge bg-danger ms-1">VIP</span>
                                    </template>
                                    <br>
                                    <div class="btn-group btn-group-sm mt-1">
                                        <button class="btn btn-outline-secondary quantity-btn" @click="changeQuantity(index, -1)">−</button>
                                        <span class="mx-2" x-text="item.quantity"></span>
                                        <button class="btn btn-outline-secondary quantity-btn" @click="changeQuantity(index, 1)">+</button>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="text-success fw-bold">R$ <span x-text="itemTotal(index).toFixed(2)"></span></span>
                                    <button class="btn btn-sm btn-outline-danger ms-2" @click="removeItem(index)"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                            <!-- Seletor de onde comer (só para Pratos Principais e Adicionais) -->
                            <div x-show="item.category_name === 'Pratos Principais' || item.category_name === 'Adicionais'"
                                 class="btn-group btn-group-sm mt-1">
                                <button class="btn btn-sm" style="font-size:0.7rem; padding:0.1rem 0.4rem;"
                                        :class="item.diningOption === 'local' ? 'btn-success' : 'btn-outline-success'"
                                        @click="setDiningOption(index, 'local')" title="Consumo no local">
                                    Local
                                </button>
                                <button class="btn btn-sm" style="font-size:0.7rem; padding:0.1rem 0.4rem;"
                                        :class="item.diningOption === 'viagem_simples' ? 'btn-warning' : 'btn-outline-warning'"
                                        @click="setDiningOption(index, 'viagem_simples')" title="Viagem Simples (+R$ 1,00)">
                                    Simples
                                </button>
                                <button class="btn btn-sm" style="font-size:0.7rem; padding:0.1rem 0.4rem;"
                                        :class="item.diningOption === 'viagem_vip' ? 'btn-danger' : 'btn-outline-danger'"
                                        @click="setDiningOption(index, 'viagem_vip')" title="Viagem VIP (+R$ 2,00)">
                                    VIP
                                </button>
                            </div>
                            <!-- Preço base vs total com embalagem -->
                            <template x-if="item.diningOption !== 'local'">
                                <div class="mt-1">
                                    <small class="text-muted">
                                        R$ <span x-text="(item.price * item.quantity).toFixed(2)"></span> item
                                        + R$ <span x-text="(packagingCost(item) * item.quantity).toFixed(2)"></span> embalagem
                                    </small>
                                </div>
                            </template>
                            <div x-show="item.notes" class="mt-1"><small class="text-muted"><i class="fas fa-sticky-note"></i> <span x-text="item.notes"></span></small></div>
                            <div class="mt-2" x-show="item.showNotes">
                                <textarea class="form-control form-control-sm" placeholder="Observação (ex: sem cebola)" x-model="item.notes" @keyup.enter="item.showNotes = false"></textarea>
                            </div>
                            <button class="btn btn-link btn-sm p-0 mt-1" @click="item.showNotes = !item.showNotes">
                                <i class="fas fa-pencil-alt"></i> <span x-text="item.showNotes ? 'Fechar' : 'Adicionar obs.'"></span>
                            </button>
                        </div>
                    </template>

                    <div x-show="selectedItems.length > 0" class="mt-3 pt-2 border-top">
                        <div class="d-flex justify-content-between">
                            <strong>Total:</strong>
                            <strong class="h5 text-success">R$ <span x-text="total.toFixed(2)"></span></strong>
                        </div>
                        <button class="btn btn-success btn-lg w-100 mt-2" @click="submitOrder"
                                :disabled="!orderNumber || selectedItems.length === 0 || submitting">
                            <span x-show="!submitting"><i class="fas fa-paper-plane me-2"></i>Enviar Pedido</span>
                            <span x-show="submitting"><span class="spinner-border spinner-border-sm me-2"></span>Enviando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- /container -->
</div> <!-- /x-data -->

<!-- Alpine.js -->
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Nosso componente Alpine -->
<script src="app.js"></script>
</body>
</html>