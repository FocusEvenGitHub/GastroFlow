<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Gestão</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .menu-item-card { transition: all 0.2s; }
        .menu-item-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .price { font-size: 1.25rem; color: #198754; }
    </style>
</head>
<body>
<div x-data="adminApp()" class="container py-4">
    <!-- Se não logado → formulário de login -->
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

    <!-- Logado → conteúdo do admin (cards antigos) -->
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
                    <!-- Ingredientes (opcional na criação, obrigatório ao menos um) -->
                    <div class="mt-3">
                        <label class="form-label">Ingredientes <small class="text-muted">(pelo menos 1)</small></label>
                        <div id="ingredient-rows">
                            <template x-for="(ing, index) in newItemIngredients" :key="index">
                                <div class="input-group mb-2">
                                    <select class="form-select" x-model.number="ing.id" required>
                                        <option value="">Selecione ingrediente...</option>
                                        <template x-for="avail in allIngredients" :key="avail.id">
                                            <option :value="avail.id" x-text="avail.name + ' (' + avail.unit + ')'"></option>
                                        </template>
                                    </select>
                                    <input type="number" class="form-control" x-model.number="ing.quantity" placeholder="Qtd" step="0.01" required style="max-width: 100px;">
                                    <button type="button" class="btn btn-outline-danger" @click="removeNewIngredient(index)"><i class="fas fa-times"></i></button>
                                </div>
                            </template>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="addNewIngredient"><i class="fas fa-plus"></i> Adicionar ingrediente</button>
                    </div>
                </form>
            </div>
        </div>

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
                                            <p class="card-text text-muted small" x-text="item.description || ''"></p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="price">R$ <span x-text="parseFloat(item.price).toFixed(2)"></span></span>
                                                <button class="btn btn-sm mt-2"
                                                        :class="item.available ? 'btn-outline-danger' : 'btn-outline-success'"
                                                        @click="toggleAvailability(item.id, !item.available)">
                                                    <i class="fas" :class="item.available ? 'fa-ban' : 'fa-check'"></i>
                                                    <span x-text="item.available ? 'Desativar' : 'Ativar'"></span>
                                                </button>
                                                <button class="btn btn-sm btn-outline-info mt-2" @click="openRecipeModal(item)">
                                                    <i class="fas fa-edit me-1"></i> Editar
                                                </button>
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

    <!-- Modal para editar receita -->
    <div x-show="showRecipeModal" x-transition x-cloak style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1050; overflow-y: auto;">
        <div class="modal-dialog modal-lg border rounded shadow" style="margin: 1.75rem auto; background: #fff; padding: 1.5rem;">
            <div class="modal-content" style="border: none;">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Receita: <span x-text="selectedDish?.name"></span></h5>
                    <button type="button" class="btn-close" @click="showRecipeModal = false"></button>
                </div>
                <div class="modal-body">
                    <div x-show="recipeLoading" class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                    </div>
                    <div x-show="!recipeLoading">
                        <div class="mb-3">
                            <label class="form-label">Ingredientes</label>
                            <template x-for="(ing, index) in recipeIngredients" :key="index">
                                <div class="input-group mb-2">
                                    <select class="form-select" x-model="ing.ingredient_id">
                                        <option :value="ing.ingredient_id" x-text="ing.name + ' (' + ing.unit + ')'"></option>
                                        <template x-for="avail in allIngredients" :key="avail.id">
                                            <option :value="avail.id" x-text="avail.name + ' (' + avail.unit + ')'"></option>
                                        </template>
                                    </select>
                                    <input type="number" class="form-control" x-model="ing.quantity" placeholder="Qtd" step="0.01" style="max-width: 100px;">
                                    <button class="btn btn-outline-danger" @click="removeRecipeRow(index)"><i class="fas fa-times"></i></button>
                                </div>
                            </template>
                            <button class="btn btn-sm btn-outline-secondary" @click="addRecipeRow"><i class="fas fa-plus"></i> Adicionar ingrediente</button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" @click="showRecipeModal = false">Cancelar</button>
                    <button type="button" class="btn btn-primary" @click="saveRecipe">Salvar Receita</button>
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