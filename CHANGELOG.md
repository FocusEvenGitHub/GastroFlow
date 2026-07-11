# Changelog

## v1.5.5 (2026-07-11)

### Novidades
- **Relatórios**: gráfico de **horário de pico** (pedidos por hora do dia)
- **Relatórios**: **tempo médio de preparo** (média geral + por dia, em minutos)
- **Relatórios**: **comparativo mensal** com o período anterior (cards + tabela de variação percentual)
- **Relatórios**: dark mode compatível com todos os novos gráficos
- **API**: novas rotas `GET /api/admin/reports/peak-hours`, `/prep-time` e `/month-comparison`

## v1.5.4 (2026-07-11)

### Novidades
- **Relatórios de vendas**: nova página `/admin/reports.php` com:
  - Filtro por período (data inicial / final)
  - Cards de resumo: pedidos, faturamento, ticket médio, itens vendidos
  - Gráfico Chart.js (barras + linha) de vendas por dia
  - Tabela de itens mais vendidos
  - Tabela de distribuição por opção de refeição (Local / Viagem Simples / Viagem VIP)
- **API**: novas rotas `GET /api/admin/reports/*` para consulta de relatórios (protegidas por JWT)
- **Price snapshot**: `order_items` agora salva `unit_price` e `packaging_cost` no momento da venda, garantindo precisão histórica
  - Migration `008_order_items_price.sql` com backfill de dados existentes
  - Impressão térmica agora usa o preço salvo no pedido

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
