# Changelog

## v1.5.3 (2026-07-11)

### Novidades
- **Cozinha**: seletor de data para visualizar pedidos de qualquer dia, com o dia atual pré-selecionado
  - Input `date` no cabeçalho da página
  - APIs `GET /api/orders` e `GET /api/kitchen/food-summary` agora aceitam parâmetro `?date=YYYY-MM-DD`
- **Cozinha**: atualizações em tempo real (SSE) agora só ocorrem quando a data selecionada é o dia de hoje

## v1.5.2 (2026-07-10)

### Correções
- **Dark mode**: seta do `<select>` (`.form-select`) não repetia mais horizontalmente nem ficava oversized — adicionados `background-repeat`, `background-position` e `background-size` explícitos

### Novidades
- **Log viewer**: nova página `/admin/logs.php` para consultar o arquivo `logs/app.log` diretamente do painel admin
  - Filtro por nível (ERROR / WARNING / INFO / DEBUG)
  - Seletor de quantidade de linhas (100–1000)
  - Exibição monocromática estilizada com cores por severidade
  - Compatível com tema escuro
- **Error handler**: agora registra automaticamente no `logs/app.log` toda exceção não capturada, incluindo método HTTP, URL e stack trace
- **API**: nova rota `GET /api/admin/logs` (protegida por JWT) para leitura do arquivo de logs
- **Admin**: links para "Logs" adicionados nas páginas de Cardápio, Ingredientes e Configurações
