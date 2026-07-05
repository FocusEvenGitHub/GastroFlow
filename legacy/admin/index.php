<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin - Gerenciar Cardápio</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card-item {
            transition: transform 0.2s;
        }
        .card-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }
        .price {
            font-size: 1.25rem;
            font-weight: bold;
            color: #198754;
        }
        .category-header {
            background-color: #f8f9fa;
            border-left: 5px solid #0d6efd;
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">
            <i class="fas fa-utensils me-2 text-primary"></i>
            Gerenciar Cardápio
        </h1>
        <a href="../cashier/index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Voltar para o Caixa
        </a>
    </div>

    <div id="alertContainer"></div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Adicionar Novo Item</h5>
        </div>
        <div class="card-body">
            <form id="addItemForm">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="itemName" class="form-label">Nome *</label>
                        <input type="text" class="form-control" id="itemName" name="name" required>
                    </div>
                    <div class="col-md-3">
                        <label for="itemPrice" class="form-label">Preço (R$) *</label>
                        <input type="number" step="0.01" class="form-control" id="itemPrice" name="price" required>
                    </div>
                    <div class="col-md-5">
                        <label for="categorySelect" class="form-label">Categoria *</label>
                        <select class="form-select" id="categorySelect" name="category_id" required>
                            <!-- carregado via JS -->
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="itemDescription" class="form-label">Descrição</label>
                        <textarea class="form-control" id="itemDescription" name="description" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Salvar Item
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <h3 class="mb-3"><i class="fas fa-list me-2"></i>Cardápio Atual</h3>
    <div id="currentMenu">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Carregando...</span>
            </div>
            <p class="mt-2">Carregando cardápio...</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script src="app.js" charset="UTF-8"></script>
</body>
</html>