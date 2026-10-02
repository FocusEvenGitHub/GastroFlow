<?php
$pageTitle = 'Ingredientes';
$activePage = 'ingredients.php';
$pageScripts = ['/admin/ingredients.js'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<?php include __DIR__ . '/_partials/head.php'; ?>
</head>
<body class="gf-admin">
<div x-data="ingredientsApp()">
<?php include __DIR__ . '/_partials/shell-start.php'; ?>

    <div class="gf-page-header">
        <div>
            <h1>Ingredientes</h1>
            <p class="gf-subtitle">Cadastro de ingredientes usados nos pratos.</p>
        </div>
    </div>

    <!-- Formulário de novo ingrediente -->
    <div class="card shadow-sm mb-4">
        <div class="card-header">
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
    <h2 class="h5 mb-3"><i class="fas fa-list me-2"></i>Ingredientes Cadastrados</h2>
    <div x-show="loading" class="text-center py-5">
        <div class="spinner-border text-primary"></div>
    </div>
    <div x-show="!loading" class="card"><div class="table-responsive">
        <table class="table table-striped table-hover mb-0">
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
                        <button class="btn btn-sm btn-outline-danger" @click="deleteIngredient(ing)"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>
    </div></div>

    <!-- Modal de edição (simples com campos alteráveis) -->
    <!-- spec 051: x-show numa .modal do Bootstrap nunca a exibia (o CSS mantém display:none);
         mesmo padrão x-effect do modal de edição do Cardápio. -->
    <div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="editIngredientTitle"
         x-effect="const m = bootstrap.Modal.getOrCreateInstance($el); editMode ? m.show() : m.hide()">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editIngredientTitle">Editar Ingrediente</h5>
                    <button type="button" class="btn-close" @click="editMode = false" aria-label="Fechar"></button>
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

<?php include __DIR__ . '/_partials/shell-end.php'; ?>
</div>
</body>
</html>
