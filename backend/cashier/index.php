<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Caixa - Novo Pedido</title>
    <style>
        .category { margin: 20px 0; padding: 10px; border: 1px solid #ddd; }
        .category-title { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
        .menu-item { margin: 10px 0; padding: 10px; border: 1px solid #eee; }
        .item-name { font-weight: bold; }
        .item-price { color: #28a745; }
        .item-description { color: #666; font-size: 0.9em; }
        .quantity { width: 60px; margin: 0 10px; }
        .notes { width: 200px; margin-left: 10px; padding: 5px; }
        .selected-items { margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #ddd; }
        .selected-item { margin: 5px 0; }
        .remove-item { color: red; cursor: pointer; margin-left: 10px; }
    </style>
</head>
<body>
    <h1>Novo Pedido</h1>

    <form id="orderForm">
        <div>
            <label>Mesa: <input type="text" name="table" required></label>
        </div>

        <h3>Cardápio</h3>
        <div id="menu"></div>

        <h3>Itens Selecionados</h3>
        <div id="selectedItems" class="selected-items">
            Nenhum item selecionado
        </div>

        <button type="submit">Enviar Pedido</button>
    </form>

    <div id="message"></div>

    <script src="app.js"></script>
</body>
</html>