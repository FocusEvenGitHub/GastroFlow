<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Caixa - Novo Pedido</title>
</head>
<body>
<h1>Novo Pedido</h1>

<form id="orderForm">
    Mesa: <input type="text" name="table" required><br><br>
    Itens: <textarea name="items" required></textarea><br><br>
    <button type="submit">Enviar</button>
</form>

<div id="msg"></div>

<script src="app.js"></script>
</body>
</html>
