<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Ingredientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>
        if (localStorage.getItem('gastroflow_darkMode') === 'true') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
</head>
<body>
<div x-data="ingredientsApp()">
    <!-- Navbar -->
    <nav class="gastro-nav">
        <a href="/cashier/" class="gastro-nav-brand">
            <i class="fas fa-utensils"></i>
            <span>GastroFlow</span>
        </a>
        <div class="gastro-nav-links">
            <a href="/cashier/"><i class="fas fa-cash-register"></i>Caixa</a>
            <a href="/kitchen/"><i class="fas fa-fire"></i>Cozinha</a>
            <a href="/admin/" class="active"><i class="fas fa-cog"></i>Admin</a>
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
            <h1 class="h3 mb-0">Gerenciar Ingredientes</h1>
            <div class="d-flex gap-2">
                <a href="/admin/" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Cardápio</a>
                <a href="reports.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-chart-bar"></i> Relatórios</a>
                <a href="logs.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-list"></i> Logs</a>
            </div>
        </div>

    <!-- Formulário de novo ingrediente -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Adicionar Ingrediente</h5>
        </div>
        <div class="card-body">
            <form @submit.prevent="addIngredient">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Nome *</label>
                        <input type="text" x-model="newIngredient.name" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Unidade *</label>
                        <input type="text" x-model="newIngredient.unit" class="form-control" required placeholder="un, g, ml">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Categoria</label>
                        <select x-model="newIngredient.category" class="form-select">
                            <option value="">Selecione...</option>
                            <option value="meat">Carne / Proteína</option>
                            <option value="grain">Grão / Acompanhamento</option>
                            <option value="vegetable">Vegetal</option>
                            <option value="fruit">Fruta</option>
                            <option value="dairy">Laticínio</option>
                            <option value="sauce">Molho</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" :disabled="saving">
                            <span x-show="!saving"><i class="fas fa-save me-1"></i> Salvar</span>
                            <span x-show="saving"><span class="spinner-border spinner-border-sm me-1"></span> Salvando...</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de ingredientes -->
    <h3 class="mb-3"><i class="fas fa-list me-2"></i>Ingredientes Cadastrados</h3>
    <div x-show="loading" class="text-center py-5">
        <div class="spinner-border text-primary"></div>
    </div>
    <div x-show="!loading">
        <table class="table table-striped table-hover">
            <thead>
            <tr>
                <th>Nome</th>
                <th>Unidade</th>
                <th>Categoria</th>
                <th style="width: 150px;">Ações</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="ing in ingredients" :key="ing.id">
                <tr>
                    <td x-text="ing.name"></td>
                    <td x-text="ing.unit"></td>
                    <td x-text="ing.category"></td>
                    <td>
                        <button class="btn btn-sm btn-outline-warning me-1" @click="editIngredient(ing)"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-danger" @click="deleteIngredient(ing.id)"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>
    </div>

    <!-- Modal de edição (simples com campos alteráveis) -->
    <div class="modal fade" id="editModal" tabindex="-1" x-show="editMode" x-transition>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Ingrediente</h5>
                    <button type="button" class="btn-close" @click="editMode = false"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" x-model="editIngredientData.name" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Unidade</label>
                        <input type="text" x-model="editIngredientData.unit" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Categoria</label>
                        <select x-model="editIngredientData.category" class="form-select">
                            <option value="">Selecione...</option>
                            <option value="meat">Carne / Proteína</option>
                            <option value="grain">Grão / Acompanhamento</option>
                            <option value="vegetable">Vegetal</option>
                            <option value="fruit">Fruta</option>
                            <option value="dairy">Laticínio</option>
                            <option value="sauce">Molho</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" @click="editMode = false">Cancelar</button>
                    <button type="button" class="btn btn-primary" @click="updateIngredient">Salvar</button>
                </div>
            </div>
        </div>
    </div> <!-- /container -->
</div> <!-- /x-data -->

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="ingredients.js"></script>
</body>
</html>