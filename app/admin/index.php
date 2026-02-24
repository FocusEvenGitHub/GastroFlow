<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Admin - Gerenciar Cardápio</title>
    <style>
        .category { margin: 20px 0; padding: 15px; border: 1px solid #ccc; }
        .item { margin: 10px 0; padding: 10px; background: #f9f9f9; }
        .form-group { margin: 10px 0; }
        label { display: inline-block; width: 100px; }
        input, textarea, select { margin: 5px 0; padding: 5px; }
    </style>
</head>
<body>
    <h1>Gerenciar Cardápio</h1>
    
    <div id="menuManager">
        <h2>Adicionar Novo Item</h2>
        <form id="addItemForm">
            <div class="form-group">
                <label>Nome:</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>Descrição:</label>
                <textarea name="description"></textarea>
            </div>
            <div class="form-group">
                <label>Preço:</label>
                <input type="number" step="0.01" name="price" required>
            </div>
            <div class="form-group">
                <label>Categoria:</label>
                <select name="category_id" id="categorySelect" required></select>
            </div>
            <button type="submit">Adicionar Item</button>
        </form>
        
        <h2>Cardápio Atual</h2>
        <div id="currentMenu"></div>
    </div>

    <script src="app.js"></script>
</body>
</html>