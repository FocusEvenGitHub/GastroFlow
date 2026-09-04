# Changelog

## v1.6.0 (2026-09-03) — Baseline & Security

Fecha o milestone `v1.6.0 — Baseline & Security` do `ROADMAP.md`: remove os últimos defaults inseguros conhecidos (senha de admin, credenciais de banco), adiciona RBAC aos endpoints administrativos e sincroniza a documentação com o comportamento real da aplicação.

### Segurança
- **Bootstrap do administrador**: removida a linha semeada `admin`/`admin123` de `001_schema.sql` — uma instalação nova não tem mais nenhum usuário até que um seja criado explicitamente; novo `bin/create-admin` cria o primeiro administrador (senha mínima de 8 caracteres, confirmada, hash bcrypt). Bancos já inicializados (incluindo o de desenvolvimento) não são afetados retroativamente (spec 015)
- **Autorização (RBAC)**: rotas `/api/admin/*` agora exigem papel, além de autenticação — `admin` para configurações/logs/impressora; `admin` ou `manager` para cardápio e relatórios; troca da própria senha aberta a qualquer papel autenticado. `users.role` passa a suportar `admin`/`manager`/`cashier`/`kitchen`, mas `cashier`/`kitchen` ainda não bloqueiam nada — `/api/orders*` e `/api/kitchen/*` continuam públicas de propósito (endpoints de rede confiável, sem tela de login) (spec 018)
- **Respostas de erro em produção**: com `APP_ENV=production`, respostas da API não expõem mais stack trace, caminho de arquivo ou detalhes de SQL — passam a retornar `{"success": false, "error": "Internal server error", "code": "INTERNAL_ERROR"}`; a exceção completa continua disponível em `logs/app.log` (spec 012)
- **Docker**: `.env` não é mais copiado para dentro da imagem construída (spec 013)
- **Banco de dados**: credenciais de banco hardcoded removidas de `common/sql/001_schema.sql`/bootstrap — usuário e senha do banco agora vêm exclusivamente de configuração de ambiente/deploy (spec 014)

### Novidades
- **Autenticação**: novo endpoint `PATCH /api/admin/account/password` para troca de senha; estratégia de autenticação (expiração/invalidação de token, hashing, ausência deliberada de logout — JWT stateless) documentada em `docs/architecture.md` (spec 016)
- **Configuração**: variáveis `APP_ENV`, `APP_DEBUG` e `APP_TIMEZONE` introduzidas — timezone da aplicação agora é configurável sem editar Docker/OS (spec 011)
- **Cozinha**: pedidos agora podem ser editados, excluídos e reimpressos diretamente da tela da cozinha
- **Cardápio**: barra de busca adicionada ao Caixa e ao Admin, limpa automaticamente após adicionar um item (spec 009)

### Correções
- **Impressão**: falhas de impressão agora se propagam corretamente, permitindo que jobs sejam reenfileirados e tentem novamente em vez de serem descartados silenciosamente
- **Dados**: cardápio padrão de Pratos Principais re-semeado com receitas corrigidas

### Alterações que quebram compatibilidade
- **`POST /api/orders`**: campo do corpo da requisição renomeado de `table` para `table_number`, unificando com o nome já usado por `PUT` (o valor sempre foi um número de senha de retirada, não uma mesa física — rótulo "Número da Senha" no Caixa, "Senha" na Cozinha). Clientes de API que ainda enviam `table` precisam ser atualizados (spec 010)

### Infraestrutura
- **Dependências**: `composer.lock` agora é versionado e faz parte do repositório; `composer validate --strict` passa (spec 017)
- **Docker**: timezone do container fixado em `America/Sao_Paulo` (-3)
- **Documentação**: `README.md`, `CLAUDE.md`, `docs/architecture.md` e as specs sincronizados com o comportamento real da aplicação (baseline v1.6.0)

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
