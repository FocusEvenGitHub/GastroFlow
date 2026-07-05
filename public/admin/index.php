<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Gestão</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <style>
        .menu-item-card { transition: all 0.2s; }
        .menu-item-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .price { font-size: 1.25rem; color: #198754; }
    </style>
</head>
<body>
<div x-data="adminApp()" class="container py-4">
    <!-- Login -->
    <div x-show="!loggedIn" class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-body">
                    <h3 class="card-title mb-4">Login Administrativo</h3>
                    <div x-show="loginError" class="alert alert-danger" x-text="loginError"></div>
                    <form @submit.prevent="doLogin">
                        <div class="mb-3">
                            <label class="form-label">Usuário</label>
                            <input type="text" x-model="loginForm.username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Senha</label>
                            <input type="password" x-model="loginForm.password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" :disabled="logging">
                            <span x-show="!logging">Entrar</span>
                            <span x-show="logging"><span class="spinner-border spinner-border-sm me-1"></span> Entrando...</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin -->
    <div x-show="loggedIn" x-transition>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2"><i class="fas fa-utensils me-2 text-primary"></i>Gerenciar Cardápio</h1>
            <div>
                <span class="me-3">Olá, <strong x-text="username"></strong></span>
                <button @click="logout" class="btn btn-outline-secondary btn-sm">Sair</button>
            </div>
        </div>

        <div x-show="message.text" x-transition>
            <div :class="'alert alert-'+message.type+' alert-dismissible fade show'" role="alert">
                <span x-text="message.text"></span>
                <button type="button" class="btn-close" @click="message.text=''"></button>
            </div>
        </div>

        <!-- Adicionar Item -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Adicionar Novo Item</h5>
            </div>
            <div class="card-body">
                <form @submit.prevent="addItem">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Nome *</label>
                            <input type="text" x-model="newItem.name" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Preço (R$) *</label>
                            <input type="number" step="0.01" x-model="newItem.price" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Categoria *</label>
                            <select x-model="newItem.category_name" class="form-select" required>
                                <option value="">Selecione...</option>
                                <template x-for="cat in categories" :key="cat">
                                    <option :value="cat" x-text="cat"></option>
                                </template>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100" :disabled="saving">
                                <span x-show="!saving"><i class="fas fa-save me-1"></i> Salvar</span>
                                <span x-show="saving"><span class="spinner-border spinner-border-sm me-1"></span> Salvando...</span>
                            </button>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea x-model="newItem.description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Cardápio -->
        <h3><i class="fas fa-list me-2"></i>Cardápio Atual</h3>
        <div x-show="loading" class="text-center py-5">
            <div class="spinner-border text-primary"></div>
        </div>
        <div x-show="!loading">
            <template x-for="category in menu" :key="category.category_name">
                <div class="card mb-4 shadow-sm">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="fas" :class="category.type === 'food' ? 'fa-utensils' : 'fa-glass-cheers'"></i> <span x-text="category.category_name"></span></h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <template x-for="item in category.items" :key="item.id">
                                <div class="col-md-4 col-sm-6 mb-3">
                                    <div class="card h-100 menu-item-card">
                                        <div class="card-body">
                                            <h5 class="card-title mb-1" x-text="item.name"></h5>
                                            <template x-if="category.category_name !== 'Pratos Principais'">
                                                <p class="card-text text-muted small" x-text="item.description || ''"></p>
                                            </template>
                                            <template x-if="category.category_name === 'Pratos Principais'">
                                                <p class="card-text text-muted small">
                                                    <i class="fas fa-layer-group me-1 text-primary"></i>
                                                    <span x-text="(item.components || []).map(c => c.name + ' x' + c.quantity).join(', ')"></span>
                                                </p>
                                            </template>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="price">R$ <span x-text="parseFloat(item.price).toFixed(2)"></span></span>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-primary" @click="startEdit(item)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm"
                                                            :class="item.available ? 'btn-outline-danger' : 'btn-outline-success'"
                                                            @click="toggleAvailability(item.id, !item.available)">
                                                        <i class="fas" :class="item.available ? 'fa-ban' : 'fa-check'"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" @click="confirmDelete(item)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Modal Editar Item -->
    <div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static"
         x-data x-effect="() => { const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal'));
         editingItem ? modal.show() : modal.hide(); }">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar Item</h5>
                    <button type="button" class="btn-close" @click="cancelEdit"></button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="updateItem">
                        <div class="mb-3">
                            <label class="form-label">Nome *</label>
                            <input type="text" x-model="editForm.name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Preço (R$) *</label>
                            <input type="number" step="0.01" x-model="editForm.price" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Categoria *</label>
                            <select x-model="editForm.category_name" class="form-select" required>
                                <option value="">Selecione...</option>
                                <template x-for="cat in categories" :key="cat">
                                    <option :value="cat" x-text="cat"></option>
                                </template>
                            </select>
                        </div>
                        <!-- Descrição (esconder para Pratos Principais) -->
                        <div class="mb-3" x-show="editForm.category_name !== 'Pratos Principais'">
                            <label class="form-label">Descrição</label>
                            <textarea x-model="editForm.description" class="form-control" rows="2"></textarea>
                        </div>

                        <!-- Componentes (substitui descrição para Pratos Principais) -->
                        <div x-show="editForm.category_name === 'Pratos Principais'">
                            <hr>
                            <h6><i class="fas fa-layer-group me-1"></i> Componentes do Prato</h6>
                            <p class="text-muted small">Selecione os adicionais que compõem este prato.</p>
                            <select multiple id="component-select" class="tom-select"
                                    x-ref="componentSelect"
                                    style="width:100%">
                                <template x-for="comp in availableComponents" :key="comp.id">
                                    <option :value="comp.id"
                                            :selected="isComponentSelected(comp.id)"
                                            x-text="comp.name"></option>
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
                    <button type="button" class="btn btn-secondary" @click="cancelEdit">Cancelar</button>
                    <button type="button" class="btn btn-primary" @click="updateItem" :disabled="saving">
                        <span x-show="!saving"><i class="fas fa-save me-1"></i> Salvar</span>
                        <span x-show="saving"><span class="spinner-border spinner-border-sm me-1"></span> Salvando...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="app.js"></script>
</body>
</html>
