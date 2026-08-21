# Changelog

## v1.5.6 (2026-08-20)

### Segurança
- **Autenticação**: `JWT_SECRET` agora é obrigatório — a aplicação lança exceção na inicialização se a variável de ambiente não estiver definida, em vez de usar um segredo hardcoded conhecido publicamente
- **CORS**: origem permitida agora é configurável via `CORS_ALLOWED_ORIGIN` (aplicada em toda resposta, incluindo preflight OPTIONS); mantém `*` como padrão quando não definida, preservando o comportamento anterior

### Correções
- **Migrations**: `006_settings.sql` agora verifica se a coluna `orders.customer_name` já existe antes de tentar criá-la, evitando falha ("Duplicate column name") em instalações novas — a coluna já é declarada em `001_schema.sql`
- **`bin/migrate`**: agora propaga variáveis de ambiente do processo (`getenv()`) para `$_ENV`, corrigindo falha de conexão ao banco (`DB_HOST` não reconhecido) em ambientes que definem variáveis de ambiente reais em vez de um arquivo `.env`, como o GitHub Actions

### Infraestrutura (`ROADMAP.md` v2.0/v2.1)
- **Testes**: adicionado PHPUnit `^11` com teste de smoke (`GET /api/menu`) e testes unitários para `OrderService` e `OrderValidator`
- **CI**: novo workflow do GitHub Actions que roda `composer install`, aplica o schema + migrations em MySQL 8.0 e executa a suíte PHPUnit em todo push/PR para `master`; badge do README atualizado para refletir o status real do workflow
- **Config**: caminhos de sistema de arquivos centralizados em `Settings` via injeção de dependência, substituindo caminhos `__DIR__`-relativos espalhados em `App`, `AdminController`, `PrintService` e `PrintOrderJob`
- **Code style**: `declare(strict_types=1)` adicionado a todos os arquivos PHP em `src/`

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
