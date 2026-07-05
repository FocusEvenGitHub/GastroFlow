<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Categorias de Pratos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div x-data="categoriesApp()" class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2"><i class="fas fa-tags me-2"></i>Categorias de Pratos</h1>
        <a href="/admin/" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Voltar</a>
    </div>

    <div x-show="message.text" x-transition>
        <div :class="'alert alert-'+message.type+' alert-dismissible fade show'" role="alert">
            <span x-text="message.text"></span>
            <button type="button" class="btn-close" @click="message.text=''"></button>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Nova Categoria</h5>
        </div>
        <div class="card-body">
            <form @submit.prevent="addCategory">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Nome *</label>
                        <input type="text" x-model="newCategory.name" class="form-control" required>
                    </div>
                    <div class="col-md-4 position-relative">
                        <label class="form-label">Tipo *</label>
                        <input type="text"
                               class="form-control"
                               placeholder="Buscar tipo ou digitar novo..."
                               x-model="typeSearch"
                               @input="filterTypes()"
                               @focus="showTypeDropdown = true"
                               @click.away="showTypeDropdown = false"
                               autocomplete="off" required>
                        <ul class="list-group position-absolute w-100 shadow" style="z-index:1000; max-height:200px; overflow-y:auto;"
                            x-show="showTypeDropdown && (filteredTypes.length > 0 || typeSearch.length > 0)">
                            <template x-for="t in filteredTypes" :key="t">
                                <li class="list-group-item list-group-item-action" @click="selectType(t)" style="cursor:pointer;" x-text="t"></li>
                            </template>
                            <li class="list-group-item list-group-item-action text-primary"
                                x-show="typeSearch.length > 0 && !exactTypeMatch"
                                @click="useNewType()"
                                style="cursor:pointer;">
                                <i class="fas fa-plus-circle me-1"></i> Usar novo tipo: <strong x-text="typeSearch"></strong>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" :disabled="saving">
                            <span x-show="!saving">Salvar</span>
                            <span x-show="saving"><span class="spinner-border spinner-border-sm me-1"></span> Salvando...</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <h3 class="mb-3"><i class="fas fa-list me-2"></i>Categorias Cadastradas</h3>
    <div x-show="loading" class="text-center py-5">
        <div class="spinner-border text-primary"></div>
    </div>
    <div x-show="!loading">
        <table class="table table-striped table-hover">
            <thead>
            <tr><th>Nome</th><th>Tipo</th><th style="width:150px;">Ações</th></tr>
            </thead>
            <tbody>
            <template x-for="cat in categories" :key="cat.id">
                <tr>
                    <td x-text="cat.name"></td>
                    <td x-text="cat.type === 'food' ? 'Comida' : 'Bebida'"></td>
                    <td>
                        <button class="btn btn-sm btn-outline-warning me-1" @click="editCategory(cat)"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-danger" @click="deleteCategory(cat.id)"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>
    </div>

    <!-- Modal de edição -->
    <div x-show="editMode" x-transition style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1050;">
        <div class="modal-dialog" style="margin:1.75rem auto; background:#fff; border-radius:0.5rem; box-shadow:0 0.5rem 1rem rgba(0,0,0,0.15);">
            <div class="modal-content" style="border:none;">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Categoria</h5>
                    <button type="button" class="btn-close" @click="editMode = false"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" x-model="editData.name" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipo</label>
                        <select x-model="editData.type" class="form-select">
                            <option value="food">Comida</option>
                            <option value="drink">Bebida</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" @click="editMode = false">Cancelar</button>
                    <button type="button" class="btn btn-primary" @click="updateCategory">Salvar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="categories.js"></script>
</body>
</html>