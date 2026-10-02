<?php
$pageTitle = 'Cardápio';
$activePage = 'index.php';
$gate = true;
$pageScripts = ['https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js', '/admin/app.js'];
$extraHead = '<link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<?php include __DIR__ . '/_partials/head.php'; ?>
</head>
<body class="gf-admin">
<div x-data="adminApp()">
    <!-- Login (único ponto de login do Admin — spec 051) -->
    <div x-show="!loggedIn" x-cloak class="gf-login">
        <div class="card shadow gf-login-card">
            <div class="card-body p-4">
                <div class="gf-login-brand"><i class="fas fa-utensils"></i> GastroFlow</div>
                <p class="text-muted mb-4">Entre para gerenciar o restaurante.</p>
                <div x-show="loginError" class="alert alert-danger" x-text="loginError"></div>
                <form @submit.prevent="doLogin">
                    <div class="mb-3">
                        <label class="form-label" for="loginUser">Usuário</label>
                        <input type="text" id="loginUser" x-model="loginForm.username" class="form-control" autocomplete="username" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="loginPass">Senha</label>
                        <input type="password" id="loginPass" x-model="loginForm.password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" :disabled="logging">
                        <span x-show="!logging">Entrar</span>
                        <span x-show="logging"><span class="spinner-border spinner-border-sm me-1"></span> Entrando...</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/_partials/shell-start.php'; ?>

    <div class="gf-page-header">
        <div>
            <h1>Cardápio</h1>
            <p class="gf-subtitle" x-text="totalItems + ' itens em ' + menu.length + ' categorias'"></p>
        </div>
        <div class="gf-page-actions">
            <button class="btn btn-primary" @click="openNewItem()">
                <i class="fas fa-plus me-1"></i> Novo item
            </button>
        </div>
    </div>

    <!-- Toolbar: busca + grade/lista + categorias -->
    <div class="gf-toolbar">
        <div class="input-group input-group-sm gf-search">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="search" x-model="searchQuery" class="form-control" placeholder="Buscar item pelo nome..." aria-label="Buscar item pelo nome">
            <button class="btn btn-outline-secondary" type="button" x-show="searchQuery" @click="searchQuery = ''" title="Limpar busca">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Visualização">
            <button class="btn btn-outline-secondary" :class="{ 'active': viewMode === 'grid' }" :aria-pressed="viewMode === 'grid'"
                    @click="toggleView('grid')" title="Visualização em grade">
                <i class="fas fa-th-large"></i>
            </button>
            <button class="btn btn-outline-secondary" :class="{ 'active': viewMode === 'list' }" :aria-pressed="viewMode === 'list'"
                    @click="toggleView('list')" title="Visualização em lista">
                <i class="fas fa-list"></i>
            </button>
        </div>
        <div class="gf-pills" role="group" aria-label="Filtrar por categoria">
            <button class="gf-pill" :class="{ active: categoryFilter === 'all' }" :aria-pressed="categoryFilter === 'all'"
                    @click="categoryFilter = 'all'">Todos</button>
            <template x-for="cat in menu" :key="cat.category_name">
                <button class="gf-pill" :class="{ active: categoryFilter === cat.category_name }"
                        :aria-pressed="categoryFilter === cat.category_name"
                        @click="categoryFilter = cat.category_name">
                    <span x-text="cat.category_name"></span><span class="gf-pill-count" x-text="cat.items.length"></span>
                </button>
            </template>
        </div>
    </div>

    <div x-show="loading" class="text-center py-5">
        <div class="spinner-border text-primary"></div>
    </div>
    <div x-show="!loading && filteredMenu.length === 0" class="text-muted text-center py-5">
        <i class="fas fa-search fa-2x mb-2 d-block opacity-50"></i>
        Nenhum item encontrado<span x-show="searchQuery"> para "<span x-text="searchQuery"></span>"</span>.
    </div>

    <div x-show="!loading">
        <template x-for="category in filteredMenu" :key="category.category_name">
            <section class="gf-category">
                <h2 class="gf-category-title">
                    <i class="fas" :class="category.type === 'food' ? 'fa-utensils' : 'fa-glass-cheers'"></i>
                    <span x-text="category.category_name"></span>
                    <span class="badge" x-text="category.items.length"></span>
                </h2>
                <div class="row" :class="viewMode === 'list' ? 'view-list' : ''">
                    <template x-for="item in category.items" :key="item.id">
                        <div class="menu-item-col col-xl-3 col-lg-4 col-sm-6 mb-3">
                            <div class="card gf-item-card" :class="{ unavailable: !item.available }">
                                <div class="card-body">
                                    <h3 class="card-title" x-text="item.name"></h3>
                                    <div class="d-flex flex-wrap gap-1">
                                        <!-- Preço deste item = taxa da opção de viagem (spec 050) -->
                                        <template x-if="item.packaging_option">
                                            <span class="badge"
                                                  :class="item.packaging_option === 'viagem_vip' ? 'bg-danger' : 'bg-warning text-dark'"
                                                  :title="'Preço usado como taxa de embalagem da opção ' + (item.packaging_option === 'viagem_vip' ? 'VIP' : 'Simples')"
                                                  x-text="item.packaging_option === 'viagem_vip' ? 'Embalagem VIP' : 'Embalagem Simples'"></span>
                                        </template>
                                        <span class="badge bg-secondary" x-show="!item.available">Indisponível</span>
                                    </div>
                                    <template x-if="category.category_name !== 'Pratos Principais'">
                                        <p class="item-desc" x-text="item.description || ''"></p>
                                    </template>
                                    <template x-if="category.category_name === 'Pratos Principais'">
                                        <p class="item-desc">
                                            <i class="fas fa-layer-group me-1 text-primary"></i>
                                            <span x-text="(item.components || []).map(c => c.name + ' x' + c.quantity).join(', ')"></span>
                                        </p>
                                    </template>
                                    <div class="gf-item-footer">
                                        <span class="gf-price">R$ <span x-text="parseFloat(item.price).toFixed(2)"></span></span>
                                        <div class="btn-group gf-item-actions">
                                            <button class="btn btn-sm btn-outline-primary" @click="startEdit(item)" title="Editar" :aria-label="'Editar ' + item.name">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm"
                                                    :class="item.available ? 'btn-outline-secondary' : 'btn-outline-success'"
                                                    :title="item.available ? 'Desativar' : 'Ativar'"
                                                    :aria-label="(item.available ? 'Desativar ' : 'Ativar ') + item.name"
                                                    @click="toggleAvailability(item.id, !item.available)">
                                                <i class="fas" :class="item.available ? 'fa-ban' : 'fa-check'"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" @click="confirmDelete(item)" title="Excluir" :aria-label="'Excluir ' + item.name">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </template>
    </div>

    <!-- Modal Novo Item -->
    <div class="modal fade" id="newItemModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="newItemTitle"
         x-effect="const m = bootstrap.Modal.getOrCreateInstance($el); newItemOpen ? m.show() : m.hide()">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form @submit.prevent="addItem">
                    <div class="modal-header">
                        <h5 class="modal-title" id="newItemTitle"><i class="fas fa-plus-circle me-2"></i>Novo item</h5>
                        <button type="button" class="btn-close" @click="newItemOpen = false" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="newName">Nome *</label>
                            <input type="text" id="newName" x-model="newItem.name" class="form-control" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-5">
                                <label class="form-label" for="newPrice">Preço (R$) *</label>
                                <input type="number" id="newPrice" step="0.01" min="0" x-model="newItem.price" class="form-control" required>
                            </div>
                            <div class="col-sm-7">
                                <label class="form-label" for="newCategory">Categoria *</label>
                                <select id="newCategory" x-model="newItem.category_name" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <template x-for="cat in categories" :key="cat">
                                        <option :value="cat" x-text="cat"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="newDescription">Descrição</label>
                            <textarea id="newDescription" x-model="newItem.description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" @click="newItemOpen = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="saving">
                            <span x-show="!saving"><i class="fas fa-save me-1"></i> Salvar</span>
                            <span x-show="saving"><span class="spinner-border spinner-border-sm me-1"></span> Salvando...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Editar Item -->
    <div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="editItemTitle"
         x-effect="const m = bootstrap.Modal.getOrCreateInstance($el); editingItem ? m.show() : m.hide()">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editItemTitle"><i class="fas fa-edit me-2"></i>Editar item</h5>
                    <button type="button" class="btn-close" @click="cancelEdit" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="updateItem">
                        <div class="mb-3">
                            <label class="form-label" for="editName">Nome *</label>
                            <input type="text" id="editName" x-model="editForm.name" class="form-control" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-5">
                                <label class="form-label" for="editPrice">Preço (R$) *</label>
                                <input type="number" id="editPrice" step="0.01" x-model="editForm.price" class="form-control" required>
                            </div>
                            <div class="col-sm-7">
                                <label class="form-label" for="editCategory">Categoria *</label>
                                <select id="editCategory" x-model="editForm.category_name" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <template x-for="cat in categories" :key="cat">
                                        <option :value="cat" x-text="cat"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <!-- Descrição (esconder para Pratos Principais) -->
                        <div class="mb-3" x-show="editForm.category_name !== 'Pratos Principais'">
                            <label class="form-label" for="editDescription">Descrição</label>
                            <textarea id="editDescription" x-model="editForm.description" class="form-control" rows="2"></textarea>
                        </div>

                        <!-- Componentes (substitui descrição para Pratos Principais) -->
                        <div x-show="editForm.category_name === 'Pratos Principais'">
                            <hr>
                            <h6><i class="fas fa-layer-group me-1"></i> Componentes do Prato</h6>
                            <p class="text-muted small">Selecione os adicionais que compõem este prato.</p>
                            <select multiple id="component-select" class="tom-select" x-ref="componentSelect" style="width:100%">
                                <template x-for="comp in availableComponents" :key="comp.id">
                                    <option :value="comp.id" :selected="isComponentSelected(comp.id)" x-text="comp.name"></option>
                                </template>
                            </select>

                            <template x-if="editComponents.length > 0">
                                <div class="mt-3">
                                    <label class="form-label small fw-bold">Quantidades</label>
                                    <template x-for="(comp, idx) in editComponents" :key="comp.id">
                                        <div class="row align-items-center mb-1">
                                            <div class="col-6">
                                                <span class="small" x-text="comp.name"></span>
                                            </div>
                                            <div class="col-3">
                                                <input type="number" class="form-control form-control-sm"
                                                       min="1" :value="comp.quantity"
                                                       @input="setComponentQty(comp.id, $event.target.value)">
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="availableComponents.length === 0">
                                <p class="text-muted small mt-2">Nenhum adicional disponível. Adicione itens na categoria "Adicionais" primeiro.</p>
                            </template>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" @click="cancelEdit">Cancelar</button>
                    <button type="button" class="btn btn-primary" @click="updateItem" :disabled="saving">
                        <span x-show="!saving"><i class="fas fa-save me-1"></i> Salvar</span>
                        <span x-show="saving"><span class="spinner-border spinner-border-sm me-1"></span> Salvando...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/_partials/shell-end.php'; ?>
</div>
</body>
</html>
