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
    <style>
        .menu-item-card { cursor: pointer; transition: all 0.2s; }
        .menu-item-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .selected-item { background: #fff; transition: background 0.2s; }
        .selected-item:hover { background: #f8f9fa; }
        .quantity-btn { min-width: 2rem; }
        .category-tab.active { font-weight: bold; border-bottom: 2px solid #0d6efd; }
    </style>
</head>
<body>
<div x-data="cashierApp()" class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h2"><i class="fas fa-cash-register me-2"></i>Novo Pedido</h1>
            <hr>
        </div>
    </div>

    <!-- Mensagens -->
    <div x-show="message.text" x-transition>
        <div :class="'alert alert-'+message.type+' alert-dismissible fade show'" role="alert">
            <span x-text="message.text"></span>
            <button type="button" class="btn-close" @click="message.text=''"></button>
        </div>
    </div>

    <div class="row">
        <!-- Lado esquerdo: mesa + cardápio -->
        <div class="col-md-8">
            <!-- Mesa -->
            <div class="mb-4">
                <label for="tableNumber" class="form-label fw-bold">Número da Mesa *</label>
                <input type="number" id="tableNumber" x-model="tableNumber" min="1" max="50"
                       class="form-control form-control-lg" placeholder="Ex: 5">
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
            <div x-show="!loading && filteredMenu.length === 0" class="text-muted">Nenhum item disponível.</div>
            <div class="row" x-show="!loading">
                <template x-for="category in filteredMenu" :key="category.category_name">
                    <div class="col-12 mb-4">
                        <h5 class="text-primary"><i class="fas" :class="category.type === 'food' ? 'fa-utensils' : 'fa-glass-cheers'"></i> <span x-text="category.category_name"></span></h5>
                        <div class="row">
                            <template x-for="item in category.items" :key="item.id">
                                <div class="col-md-4 col-sm-6 mb-3">
                                    <div class="card menu-item-card h-100" @click="addItem(item)">
                                        <div class="card-body">
                                            <h6 class="card-title" x-text="item.name"></h6>
                                            <p class="card-text text-muted small" x-text="item.description || 'Sem descrição'"></p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="h5 text-success mb-0">R$ <span x-text="item.price.toFixed(2)"></span></span>
                                                <button class="btn btn-sm btn-outline-primary" @click.stop="addItem(item)">
                                                    <i class="fas fa-plus"></i> Adicionar
                                                </button>
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
                                    <strong x-text="item.name"></strong><br>
                                    <div class="btn-group btn-group-sm mt-1">
                                        <button class="btn btn-outline-secondary quantity-btn" @click="changeQuantity(index, -1)">−</button>
                                        <span class="mx-2" x-text="item.quantity"></span>
                                        <button class="btn btn-outline-secondary quantity-btn" @click="changeQuantity(index, 1)">+</button>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="text-success fw-bold">R$ <span x-text="(item.price * item.quantity).toFixed(2)"></span></span>
                                    <button class="btn btn-sm btn-outline-danger ms-2" @click="removeItem(index)"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                            <div x-show="item.notes" class="mt-1"><small class="text-muted"><i class="fas fa-sticky-note"></i> <span x-text="item.notes"></span></small></div>
                            <div class="mt-2" x-show="item.showNotes !== undefined">
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
                                :disabled="!tableNumber || selectedItems.length === 0 || submitting">
                            <span x-show="!submitting"><i class="fas fa-paper-plane me-2"></i>Enviar Pedido</span>
                            <span x-show="submitting"><span class="spinner-border spinner-border-sm me-2"></span>Enviando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alpine.js -->
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Nosso componente Alpine -->
<script src="app.js"></script>
</body>
</html>