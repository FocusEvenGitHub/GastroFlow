# Changelog

## v1.8.0 — Reliability & Quality (em andamento, sem tag)

Milestone `v1.8.0` do `ROADMAP.md`. Esta seção é atualizada conforme cada etapa entra em `master`; a tag `v1.8.0` só é criada quando **todos** os itens do milestone estiverem concluídos. Itens ainda abertos: confiabilidade de impressão, realtime/SSE, logging estruturado, histórico de auditoria, health checks, confiabilidade de migração, backup & restore.

### Testes
- **Suíte de integração com MySQL real**: 26 testes cobrindo autenticação, autorização, criação/numeração/preço/conclusão/reabertura de pedido, mutação de cardápio, relatórios e criação de job, mais a jornada ponta a ponta caixa → pedido → cozinha → conclusão → relatório. Exercitada pela camada HTTP, sem automação de navegador (spec 035)
- **Banco de teste dedicado, com o default seguro**: a suíte escreve linhas reais, então só roda com `MYSQL_DATABASE_TEST` apontando para um banco **diferente** do de produção. Sem a variável, os testes se pulam e `phpunit` continua saindo 0; apontada para o mesmo banco, a suíte falha alto em vez de arriscar os dados (spec 035)
- **Concorrência testada de verdade**: quatro processos de SO separados disputando o mesmo job e a mesma numeração de pedido — a lacuna que a spec 033 declarou e adiou, porque `lockForUpdate()` é no-op no SQLite da suíte unitária (spec 035)
- **Novo estágio no CI**: `Integration tests (PHPUnit, MySQL)`, com banco próprio criado num passo dedicado (spec 035)

### Correções
- **Job cujo handler já executou não volta mais para a fila**: sob contenção o MySQL levantava deadlock no `UPDATE` que marca `status = completed` — depois do trabalho já feito — e o `catch` do `processNext()` recolocava o job em `pending`, silenciosamente. O trabalho era refeito: ticket duplicado na impressão, e NFC-e duplicada no job fiscal do `v2.1.0`. Agora a execução do handler e a gravação do resultado são fases separadas, e um resultado que não pode ser gravado estaciona o job em `failed` com um erro explícito em vez de repetir o trabalho (spec 037, defeito encontrado pela spec 035)
- **Retry para erros transitórios de banco** (`40001`/`1213`/`1205`) no claim, na gravação, na falha e na varredura — nunca envolvendo o handler, porque reexecutar a unidade que o contém é exatamente como o trabalho é duplicado (spec 037)
- **Varredura de reservas expiradas fora do caminho quente**: passa a rodar no máximo a cada 10s por processo em vez de em toda chamada. A medição mostrou que era ela — dois `UPDATE` irrestritos sobre os mesmos índices que os claims travam — a causa do deadlock, e não o tipo de lock (spec 037)
- **Cozinha mostrava a data do dia seguinte**: a tela montava a data com `toISOString()`, que converte para UTC, então das 21:00 à meia-noite em UTC−3 ela pedia os pedidos de amanhã e aparecia vazia justamente no horário de maior movimento. Mesmo defeito corrigido no `dateTo` dos relatórios. Trabalho de cliente, fora do milestone (spec 036)

### Correções
- **Fila de jobs — reserva travada**: `JobService::processNext()` gravava `reserved_at` antes de executar o job, e a query de claim só pegava `reserved_at IS NULL` — um worker morto entre a reserva e o fim deixava o job reservado para sempre, sem retry e sem sinal ao operador. O claim agora carimba `reserved_until`, e uma varredura antes de cada claim devolve a reserva vencida para a fila (ou marca `failed`, se não houver tentativa restante), sem devolver a tentativa já consumida (spec 033)
- **Handler não resolvível**: um job cuja classe de handler não existe era apagado como se tivesse dado certo — perda silenciosa. Agora falha e fica registrado (spec 033)
- **Falhas de job invisíveis**: `logJobFailure()` usava `error_log()`, que não chega ao `app.log` — as falhas não apareciam no visualizador de Logs do Admin. Agora vão via Monolog (spec 033)

### Novidades
- **Observabilidade da fila**: colunas `status` (`pending`/`reserved`/`completed`/`failed`), `last_error`, `failed_at` e `completed_at` na tabela `jobs` (migração `016`); jobs concluídos deixam de ser apagados e passam a ser podados por retenção (`QUEUE_RETENTION_DAYS`, padrão 7) (spec 033)
- **`bin/jobs-status`**: lista falhas permanentes e reservas expiradas. Na primeira execução revelou 47 jobs de impressão acumulados que estavam invisíveis (spec 033)
- **`bin/jobs-prune`**: remove jobs concluídos além da retenção; jobs com falha nunca são removidos automaticamente (spec 033)

### Infraestrutura / Qualidade
- **Análise estática**: PHPStan no nível 5 (`phpstan.neon`), escolhido por medição — 107 achados no nível 5 contra 215 no 6, onde o salto é type hint faltando nos models do Eloquent. Dois `ignoreErrors` cobrem `__callStatic`/`__get` do Eloquent, e os 16 achados restantes ficam congelados em `phpstan-baseline.neon` (spec 034)
- **Estilo de código**: PHP-CS-Fixer com PSR-12 + `declare_strict_types` (`.php-cs-fixer.dist.php`), em modo somente-leitura no CI; reformatação única aplicada a 29 arquivos, sem mudança de lógica (spec 034)
- **Pipeline de CI**: `composer validate --strict` → `composer audit` → PHPStan → PHP-CS-Fixer → PHPUnit, em estágios nomeados, antes dos passos que dependem do MySQL (spec 034)
- **`.gitattributes`**: fixa `eol=lf`. Sem isso o check de estilo acusava 69 arquivos no Windows (CRLF) e 0 no CI, contradizendo-se (spec 034)

### Lacunas conhecidas nesta fase
- ~~Concorrência entre workers sem teste~~ — **fechada** pela spec 035, que a testou e no caminho descobriu o defeito corrigido pela spec 037
- O deadlock **não reproduz mais**, então o caminho de retry da spec 037 é exercitado por simulação, não pelo MySQL. O código de erro `1205` (lock wait timeout) está na lista de retry mas nunca foi observado
- Operação com **múltiplos workers em paralelo** não foi testada sob carga; o Community roda um `print-worker`
- `OrderService::$printService` é injetado e nunca lido — achado real do PHPStan, deixado na baseline porque removê-lo muda assinatura de construtor, fora do escopo da spec 034

## v1.7.1 (2026-09-19) — Monte Seu Prato, cozinha e relatórios

Trabalho solicitado pelo cliente, **fora dos milestones do `ROADMAP.md`**: montagem de prato no Caixa, ajustes na tela da Cozinha e um novo recorte de relatório. Pela tabela SemVer do `docs/COMMIT_CONVENTION.md`, commits `feat` pediriam um bump MINOR (`v1.8.0`), mas `v1.8.0` está reservado para o milestone `v1.8.0 — Reliability & Quality` — por isso esta release sai como `v1.7.1`. Desvio consciente, registrado aqui em vez de silencioso.

### Novidades
- **Monte Seu Prato**: novo item montável no Caixa a partir dos Adicionais (modal com quantidades e preço ao vivo). O preço é autoritativo no servidor (`PricingService::composedUnitPrice()` — base + Σ adicional × quantidade), cada adicional é gravado em `order_item_components` no momento da venda, aparece sob o prato no cupom impresso (`+ 2x Filé de Frango`) e é contado no resumo de ingredientes da Cozinha. Item montável não pode ser adicionado pela tela da Cozinha (spec 030)
- **Resumo de pratos na Cozinha**: o painel lateral agora alterna entre *Ingredientes* e *Pratos* (preferência salva no navegador) — total de pratos pendentes e contagem por prato, com quebra por combinação de adicionais nos pratos montados (spec 030)
- **Relatório de pratos principais**: nova seção *Pratos Principais Vendidos* na página de Relatórios, servida por `GET /api/admin/reports/main-dishes` (JWT + `admin`/`manager`) — total do período e quantidade, receita e participação por prato, sem o limite de 10 itens de *Itens Mais Vendidos* (spec 032)

### Correções
- **Idade do pedido na Cozinha**: `created_at` passou a expor o offset de fuso horário (`created_at_iso`) — os cards apareciam 3 h mais velhos porque o horário local do servidor era interpretado como UTC no navegador (spec 031)
- **Observações do item**: agora destacadas em vermelho nos temas claro e escuro (spec 031)

### Infraestrutura
- **Migração `015_build_your_own_dish.sql`**: adiciona `menu_items.is_customizable`, insere o item "Monte Seu Prato" uma única vez e cria a tabela `order_item_components`. Instalações existentes precisam rodar `bin/migrate` ao atualizar; reexecutar a migração é no-op

## v1.7.0 (2026-09-07) — Domain & Architecture

Fecha o milestone `v1.7.0 — Domain & Architecture` do `ROADMAP.md`: torna explícitas, previsíveis e testáveis as regras de negócio críticas de pedidos, preços e permissões, e resolve as duas últimas lacunas arquiteturais nomeadas no roadmap (responsabilidade de controllers, limites de persistência).

### Novidades
- **Numeração de pedidos**: `order_number` agora é gerado de forma segura sob concorrência, por dia de operação (`business_date`), com unicidade garantida no banco (spec 019)
- **Ciclo de vida do pedido**: máquina de estados explícita e minimalista (`pending ⇄ done`, `pending → cancelled`, `done → cancelled`, `cancelled` terminal) — os valores `preparing`/`ready` do enum, nunca usados por nenhum fluxo real, foram removidos em vez de mantidos por completude teórica; cancelamento suave (soft cancellation, `POST /api/orders/{id}/cancel`) substitui o antigo `DELETE` físico, preservando o registro para histórico/relatórios; transições inválidas falham de forma previsível com `409` (spec 020)
- **Validação de pedidos**: pedidos sem itens, com item de cardápio inexistente/indisponível, quantidade inválida ou opção de refeição inválida são rejeitados antes da persistência (spec 022)
- **Padronização de erros da API**: todo controller agora retorna o mesmo formato `{"success": false, "error": ..., "code": ...}` (spec 024)
- **Domínio de precificação**: `PricingService` centraliza o cálculo de subtotal, embalagem e total do pedido, antes espalhado entre `OrderRepository` e `PrintService` (spec 026)
- **Validação de entrada**: novos validadores para itens de cardápio, ingredientes, configurações e autenticação; rotas `/api/admin/ingredients*` (antes inexistentes) agora estão registradas e funcionais (spec 027)
- **Favicon**: novo favicon e ícones do GastroFlow (`favicon.ico`, PNGs, apple-touch-icon, android-chrome, `site.webmanifest`) aplicados a todas as páginas (admin, caixa, cozinha, docs da API)

### Correções
- **Dinheiro exato**: cálculos financeiros agora usam `App\Money` (aritmética em centavos), eliminando erros de ponto flutuante (spec 021)
- **Histórico de pedidos**: nome do item, preço unitário e custo de embalagem são gravados no momento da venda — pedidos antigos não mudam de valor quando o cardápio muda (spec 023)
- **Desempenho de consultas**: filtros de data em pedidos agora usam a coluna sargable `business_date`, evitando full scans (spec 025)

### Infraestrutura / Arquitetura
- **Responsabilidade de controllers**: `AdminController` dividido em `SettingsController`, `PrinterController` e `LogController`, cada um com apenas as dependências que usa (spec 028)
- **Limites de persistência**: `IngredientController` agora passa por `IngredientService`/`IngredientRepository` em vez de chamar o Eloquent diretamente, fechando a última lacuna de camadas do roadmap `v1.7.0` (spec 029)
- **Documentação**: `docs/architecture.md` sincronizado com o estado real das camadas (`Controllers/`, `Services/`, `Repositories/`, `Validators/`) após as mudanças acima

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
