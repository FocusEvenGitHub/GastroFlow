# Changelog

## Não lançado

Trabalho solicitado pelo cliente, **fora dos milestones do `ROADMAP.md`** — sai como patch, como o `v1.8.x`.

> ⚠️ **Rodar `bin/migrate` antes de subir o código novo (spec 058):** a migração `021_order_item_discount.sql` cria as colunas de desconto em `order_items`, que a criação de pedido e todos os relatórios passam a usar. Código novo sem a migração = pedidos e relatórios falhando com erro de SQL. Pedidos antigos ficam com desconto 0,00, então nenhum total histórico muda. *(Correção 2026-10-27, spec 063: "antes" não é possível — os containers rodam o código direto da pasta, então ele entra no ar no `git pull`. Atualize com o restaurante fechado e rode `bin/migrate` logo após o `git pull`/`docker compose up -d --build`, seguindo [`docs/upgrading.md`](docs/upgrading.md).)*

### Segurança
- **PHP atualizado contra o PHP2Shell (CVE-2026-109618 / CVE-2026-109619)**: a imagem passa de `php:8.2-apache` para **`php:8.2.35-apache`**, a versão corrigida. As duas falhas permitem atacar o PHP com uma única requisição sem login, antes de qualquer código do GastroFlow rodar, e Apache com mod_php — exatamente o que a imagem do GastroFlow usa — é o cenário de maior risco. A versão de patch fica fixada no `Dockerfile` para que uma imagem antiga em cache não continue sendo usada. Como defesa extra, `session.upload_progress.enabled=0` (o GastroFlow não usa sessões PHP) (spec 059)
- ⚠️ **Para aplicar, é preciso reconstruir a imagem** — só atualizar o código não basta: `git pull`, depois `docker compose build --pull web print-worker` e `docker compose up -d`. Confira com `docker compose exec web php -v` (tem que mostrar `8.2.35`). Quem roda sem Docker precisa de PHP 8.2.35+, 8.3.36+, 8.4.27+ ou 8.5.12+; a linha 8.1 não recebe mais correção oficial

### Novidades
- **Desconto por item no caixa**: no "Resumo do Pedido", o preço de cada item vira um botão (sublinhado tracejado, ícone de etiqueta). Ao tocar, o modal "Desconto no item" pede **% ou R$**, mostra na hora "De R$ 40,00 por R$ 32,00" e só deixa aplicar um valor válido. O desconto vale para a linha toda (2× R$ 20 − R$ 5 = R$ 35), nunca para a embalagem, e nunca passa do valor do item. A linha mostra o preço antigo riscado e um selo (`−20%` ou `−R$ 5,00`); o resumo e o modal de confirmação mostram "Descontos" e o total já líquido. Se o caixa diminuir a quantidade e um desconto em R$ deixar de caber, o selo fica vermelho ("Revise o desconto") e o envio é bloqueado — o caixa nunca corta o desconto em silêncio. O servidor recalcula e valida tudo de novo (`400 INVALID_DISCOUNT` com a mensagem em português; o pedido inteiro é recusado sem gastar número de senha). Cada pedido com desconto gera uma linha `order.discount_applied` na Auditoria (spec 058)
- **Teto do desconto no Admin**: Configurações › "Desconto no caixa" › "Desconto máximo por item (%)". Padrão **100%**; **0 desativa** o desconto (o preço volta a ser só texto). Vale para o caixa e para a cozinha, e o servidor também o aplica. Lido pelas telas em `GET /api/settings/discount` (spec 058)
- **Desconto no "Editar Pedido" da cozinha**: cada item do modal ganha a linha "Desconto: …" com "Dar desconto"/"Alterar"/"Remover", com as mesmas regras e o mesmo teto do caixa — para corrigir um desconto esquecido ou errado. O quadro de pedidos e o resumo da cozinha continuam sem nenhuma informação de desconto. Ao mudar só a quantidade de um item com desconto, o servidor reajusta sozinho (porcentagem reaplicada; valor em R$ limitado ao novo valor do item) (spec 058)
- **Relatórios líquidos de desconto**: faturamento, ticket médio, vendas por dia, comparativo, itens mais vendidos e pratos principais passam a descontar o que foi dado de desconto. Card novo **"Descontos concedidos"**: total no período, pedidos com desconto, % das vendas de produtos e os itens com mais desconto (`GET /api/admin/reports/discounts`; `summary` ganha `discount_total`) (spec 058)
- **Cupom impresso com desconto**: o item com desconto sai com o valor já líquido e a linha `Desconto 20%: -R$ 8,00` (ou `Desconto: -R$ 5,00`) embaixo; o TOTAL é líquido. Cupom sem desconto sai exatamente como antes (spec 058)

## v1.9.0 — Community Productization (em andamento)

### Segurança
- **MySQL só no próprio servidor**: a porta 3306 passa a ser publicada só em `127.0.0.1` — antes, qualquer aparelho da rede do restaurante alcançava o banco (protegido por senha). O GastroFlow não muda: ele fala com o banco pela rede interna do Docker. Ferramentas no servidor continuam usando `localhost:3306`; um cliente de banco em **outra** máquina deixa de conectar. Vale ao recriar o container (`docker compose up -d`) (spec 062)
- **Modelo de `.env` seguro por padrão**: `.env.example` passa a vir com `APP_ENV=production` e `APP_DEBUG=false` — antes, quem só preenchia o `JWT_SECRET` rodava com o modo debug, que devolve mensagens internas e stack trace nos erros da API. Quem desenvolve define `development`/`true` no próprio `.env`; um `.env` já existente não muda (spec 062)

### Novidades
- **Caixa, cozinha e Admin funcionam sem internet**: Bootstrap, Font Awesome, Alpine.js, Tom Select, Chart.js e a fonte Inter deixam de vir de CDN e passam a ser servidos pelo próprio GastroFlow, de `public/vendor/<lib>-<versão>/` — arquivos oficiais, byte a byte iguais à origem, com versão, origem, licença e SHA-256 em `public/vendor/README.md`. Com a rede local de pé e a internet caída, as telas carregam inteiras; antes o Alpine nem iniciava. Sem Vite nem build: `public/` continua sem Node. Alpine (`3.x.x`) e Tom Select (`@2`) eram faixas flutuantes no CDN e ficam fixados na versão que já rodava (3.17.4 e 2.6.2). Teste de navegador novo (`offline-assets.spec.ts`) bloqueia toda requisição para fora do servidor e falha se alguma tela voltar a depender de CDN. O Swagger UI de `/api/docs/` continua no CDN, por ser página de desenvolvedor (spec 055)
- **Mensagens de erro claras em todas as telas**: sem conexão com o servidor, caixa, cozinha e Admin mostram "Sem conexão com o servidor." (antes, o texto interno do navegador, `Failed to fetch`); quando um proxy responde com uma página de erro em HTML, aparece "Erro do servidor (HTTP 502)." em vez de `Unexpected token '<'…`. Por trás, um cliente único (`public/assets/js/gf.js`) substitui as chamadas à API escritas à mão em cada tela, e toasts e tema escuro passam a ter uma só implementação e um só HTML (`public/_partials/toasts.php`). No Admin, nada muda para o usuário além disso: 401 continua voltando ao login, 403 continua mostrando "Sem permissão"; os toasts do Admin passam a durar 5 s, como no caixa e na cozinha. Em Relatórios, se uma das consultas falhar, aparece o erro em vez de parte dos gráficos ficar vazia em silêncio (spec 056)
- **Indicador de conexão no caixa e na cozinha**: a barra de navegação mostra "Conectado", "Reconectando…" ou "Conexão perdida", conferido a cada 5 s em `/health/ready` — banco fora do ar também conta como sem conexão. Depois de 15 s sem servidor aparece um aviso vermelho com o horário da queda ("a lista de pedidos pode estar desatualizada" na cozinha; "pedidos não poderão ser enviados" no caixa). A cozinha não mostra "Conectado" enquanto o stream de tempo real estiver caído, e ao reconectar recarrega os pedidos — inclusive os criados durante a queda, que antes só apareciam no próximo pedido ou com um "Atualizar" manual. O caixa aberto sem servidor carrega o cardápio sozinho quando a conexão volta, sem recarregar a página. O stream da cozinha recusado pelo servidor é reaberto a cada 5 s, não mais após 30 s. Nada muda no servidor (spec 057)
- **Operação na rede local sem internet**: `db` e `web` passam a voltar sozinhos depois de um reboot do servidor (`restart: unless-stopped`, como o `print-worker` já fazia). O `README.md` ganha a seção "Operating over the LAN (no internet)": IP fixo e acesso pelos outros aparelhos, regra de firewall só para a porta 8080 na rede privada, impressora pelo IP, o que precisa de internet (instalar, atualizar, `/api/docs/`) e por que não apagar imagens do Docker durante a queda. O teste de navegador sem internet passa a cobrir as 6 páginas do Admin, não só 2. `bin/backup-db`/`bin/restore-db` rodados dentro do container `web` passam a dizer "rode no host" em vez de culpar o `mysqldump` (spec 060)
- **`bin/seed-demo`**: preenche uma instalação nova com pedidos fictícios relativos ao dia em que roda — 60 concluídos nos 45 dias anteriores (horário de almoço, preparo de 8 a 25 min) e 5 pendentes de hoje — para Cozinha e Relatórios terem o que mostrar. Os pedidos passam pelo `OrderRepository` de verdade (preço, senha e nome iguais aos do caixa) e não geram impressão. Recusa rodar se o banco já tiver qualquer pedido, a menos que `APP_ENV=development` — aí as senhas do histórico continuam a partir da maior de cada dia —, então não suja um restaurante em uso (spec 054)
- **Instalação nova sem dados de restaurante**: uma instalação nova deixa de vir com o cardápio do restaurante para o qual o GastroFlow foi feito (pratos, adicionais, bebidas, ingredientes, até "Livro UDF"). Vem só o que o sistema precisa: as categorias Pratos Principais, Adicionais, Bebidas, Sobremesas e Viagem, o "Monte Seu Prato" e as duas embalagens — cada restaurante cadastra o próprio cardápio no Admin. **Instalações existentes não mudam**: a migração `022` só age num banco criado a partir do schema novo. Para demonstração, `bin/seed-demo` cria um cardápio fictício quando não há nenhum, antes dos pedidos. O cardápio do cliente piloto não se perdeu: `bin/seed-client-1` o carrega inteiro (itens, preços, ingredientes e componentes dos pratos), igual ao que uma instalação recebia antes, numa instalação limpa (spec 061)
- **Guia de instalação e referência de comandos**: `docs/installation.md` leva de um clone novo até o GastroFlow funcionando — `.env`, Docker, dependências, tabelas, administrador, impressora com impressão de teste, cardápio e primeiro backup —, com uma tabela de problemas comuns. `docs/commands.md` reúne todos os comandos por tarefa (serviços, saúde, banco, backup, fila de impressão, dados de exemplo, desenvolvimento). O README ganha um passo a passo curto e correto: antes faltavam o `composer install` (sem ele toda página dava erro 500) e o `bin/migrate` (spec 062)
- **Guia de atualização e aviso de migração pendente**: `docs/upgrading.md` descreve a atualização na ordem certa — backup, `git pull` no `master`, `.env`, `docker compose up -d --build`, dependências, `bin/migrate`, validação — e como voltar atrás com o backup. `bin/migrate --status` lista as migrações pendentes sem aplicar nada (código de saída 1 se houver alguma). E enquanto houver migração pendente, `/health/ready` responde 503 `MIGRATIONS_PENDING`: caixa e cozinha mostram "Reconectando…"/"Conexão perdida" em vez de falhar com erro 500 no primeiro pedido, como aconteceu com a migração 021 (spec 063) *(Correção 2026-10-27, spec 064: restaurante não segue o `master` — o guia passou a atualizar de tag em tag; veja a entrada "Instalação e atualização por versão lançada".)*

- **Produção separada do desenvolvimento**: o `docker-compose.yml` passa a ser o de produção — o código, as dependências PHP e as telas ficam **dentro da imagem**, e de fora só vêm o `.env` (somente leitura), o banco, a logo enviada e o log. Um `git checkout` na pasta não muda mais nada do que está no ar; só `docker compose up -d --build` troca a versão. E as migrações rodam sozinhas, num serviço `migrate` que roda antes do sistema subir: o código novo nunca atende antes do banco estar pronto para ele, e se uma migração falhar o sistema novo não sobe (`docker compose logs migrate` mostra o motivo). Instalar e atualizar ficam mais curtos: não há mais `composer install` nem `bin/migrate` para digitar. `/version` mostra a versão de que a imagem foi construída (`GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build`). Quem desenvolve usa `docker-compose.dev.yml`, o compose antigo, pondo `COMPOSE_FILE=docker-compose.dev.yml` no `.env` (spec 066)
- ⚠️ **Primeira atualização para a v1.9 (vindo da v1.8.x ou de um `master` anterior)**: siga [`docs/upgrading.md`](docs/upgrading.md) › "First upgrade to v1.9". O banco é o mesmo volume e não precisa de nada. Se o restaurante enviou a própria logo, ela estava gravada por cima da logo do GastroFlow na pasta do código: guarde uma cópia antes de atualizar e envie de novo no Admin depois (spec 066)

### Correções
- **Logo enviada sobrescrevia um arquivo do código**: o upload gravava em `public/assets/img/logo.png`, a logo do GastroFlow versionada no git — toda instalação com logo própria ficava com uma alteração local, que podia travar o `git checkout` de uma atualização. Agora a logo enviada vai para `public/uploads/` (um volume em produção, que sobrevive às atualizações); a do GastroFlow fica como padrão enquanto nenhuma é enviada (spec 066)
- **Log da aplicação se perdia a cada recriação do container**: `logs/app.log` não era montado em lugar nenhum e sumia a cada `docker compose up` que recriasse o `web`. Em produção ele agora fica num volume (spec 066)
- **Imagem Docker não construía num clone novo**: o `Dockerfile` copiava a pasta `legacy/`, removida do repositório há muito tempo — ela só existia na máquina de quem já tinha o projeto, recriada vazia pelos volumes. Quem baixava o GastroFlow pela primeira vez parava no `docker compose up --build`. Referências removidas do `Dockerfile` e do `docker-compose` (spec 062)
- **Instalação com outro nome de banco**: com `MYSQL_DATABASE` diferente de `restaurant`, as tabelas iam parar num banco `restaurant` criado à parte e o `bin/migrate` falhava — o `001_schema.sql` tinha o nome fixo. Agora usa o banco configurado no `.env` (spec 062)
- **Logo no cupom impresso**: a logo nunca saía no cupom, em nenhuma instalação — a imagem Docker não tinha a extensão GD, que a biblioteca de impressão precisa para ler a imagem, e o erro ficava só como aviso no log. Agora a imagem inclui GD e o GastroFlow lê a logo pelo conteúdo do arquivo e a reduz para a largura do papel de 58 mm (384 pontos). O upload passa a gravar sempre um PNG de verdade (antes um JPG ou WebP era salvo com o nome `logo.png` e não imprimiria) e recusa um arquivo que não seja imagem, mantendo a logo anterior. Se a logo não puder ser impressa, Admin › Configurações mostra um aviso amarelo com o motivo. ⚠️ Requer reconstruir a imagem: `docker compose up -d --build` (spec 065)
- **Badge de versão na instância de teste**: a instância do `docker-compose.e2e.yml` (porta 8081) mostrava "dev" no canto inferior direito porque não montava o `.git`; agora monta, somente leitura, como o `web`, e mostra a versão real (`git describe`) (spec 054)

### Documentação
- **Configuração, segurança, backup e solução de problemas**: quatro guias novos em `docs/`. `configuration.md` explica cada variável do `.env` e cada campo de Admin › Configurações (padrão, efeito, quando vale). `security.md` descreve o modelo de rede local confiável, o que pede login e o que não pede (caixa e cozinha são abertos de propósito), os perfis `admin`/`manager`, sessões de 8 h e como encerrar todas trocando o `JWT_SECRET`, e as limitações conhecidas (sem HTTPS, CORS `*` por padrão). `backup-and-restore.md` mostra o backup completo — o `bin/backup-db` salva **só o banco**: a logo enviada e o `.env` precisam ser copiados à parte —, cópia fora do servidor, agendamento e restauração no mesmo servidor ou num servidor novo. `troubleshooting.md` começa pelas verificações básicas e aponta para a tabela de cada guia (spec 067)
- **Guia de impressão**: `docs/printing.md` — que impressoras funcionam (ESC/POS pela rede, porta 9100; não USB), papel de 58 mm, IP fixo e como achar o IP da impressora, configuração e impressão de teste no Admin, o que acontece quando a impressora falha (3 tentativas, bloqueio, "Reativar impressão", reimpressão pela cozinha), tabela de problemas comuns e comandos. O IP `192.168.0.100` que vem numa instalação nova é documentado como exemplo a trocar (spec 065)
- **Instalação e atualização por versão lançada**: os guias de instalação e de atualização deixam de tratar o `master` como versão estável — ele é a próxima versão em desenvolvimento. Restaurante roda uma **tag** (GitHub Release): instala com `git checkout vX.Y.Z` e atualiza com `git fetch --tags` + `git checkout` da tag nova. `/version` mostra a tag exata num release e `vX.Y.Z-N-g…` em código não lançado. Até sair o `v1.9.0` — o primeiro release coberto pelos guias —, o `v1.8.3` não instala do zero (`COPY legacy/`), então quem instala agora fica no `master` sabendo que é pré-lançamento (spec 064)
- **README enxuto e prints novos**: o `README.md` cai de 409 para menos de 250 linhas. Sai o que já tem dono em outro documento — o histórico de releases por spec (está no `CHANGELOG.md`), o processo de engenharia e de IA em detalhe (`CLAUDE.md`, `specs/README.md`), a convenção de commits (`docs/COMMIT_CONVENTION.md`) e o sumário manual (o GitHub já mostra um) — com links na tabela "Documentation". Corrige o que estava desatualizado: o SSE da cozinha descrito como arquivo de sinal (é a tabela `events` desde a spec 041), o CI descrito como só PHPUnit e os releases descritos como SemVer. Os quatro prints foram refeitos com a interface atual, numa instalação limpa com pedidos fictícios, e passam de `tela_*.png` para `tela_*.jpg` (69–90 KB cada; a lista de exceções do `.gitignore` acompanha) (spec 054)

## v1.8.3 (2026-10-03) — Limite de tentativas de login

Item do Backlog sem milestone (o "Authentication hardening" de origem fazia parte do `v1.6.0`, já fechado) — sai como patch, como o trabalho de cliente do `v1.8.x`.

> ⚠️ **Rodar `bin/migrate` antes de subir o código novo (spec 053):** a migração `020_login_attempts.sql` cria a tabela que o login passa a consultar em toda tentativa. Código novo sem a migração = todo login falhando com erro de SQL. *(Correção 2026-10-27, spec 063: "antes" não é possível — os containers rodam o código direto da pasta, então ele entra no ar no `git pull`. Atualize com o restaurante fechado e rode `bin/migrate` logo após o `git pull`/`docker compose up -d --build`, seguindo [`docs/upgrading.md`](docs/upgrading.md).)*

### Segurança
- **Limite de tentativas de login**: `POST /api/login` passa a bloquear por 15 minutos depois de **10 falhas em 15 minutos** para o mesmo IP + usuário, ou **30 falhas do mesmo IP** somando usuários. Responde `429 TOO_MANY_LOGIN_ATTEMPTS` com `Retry-After` e a mensagem "Muitas tentativas de login. Tente novamente em N minutos." (a tela de login do Admin já mostra). Enquanto bloqueado, nem a senha certa entra e as tentativas não contam, então o bloqueio não se estende; login certo zera a contagem; erro de validação (400) não conta. Chave IP + usuário de propósito: um atacante só consegue travar o admin no próprio IP. Tentativas ficam na tabela `login_attempts` (IP, usuário normalizado e horário — nunca senha), apagadas sozinhas depois de 15 minutos; cada bloqueio gera uma linha `auth.login_throttled` no `app.log`. Fecha o último item do "Authentication hardening" que a spec 016 tinha deixado para depois (spec 053)
- ⚠️ **Docker Desktop (Windows/Mac)**: o app vê todo acesso vindo do host com o IP do gateway do Docker (medido: `172.18.0.1`), então o limite por IP vira global e o "IP + usuário" vira "por usuário" nesse ambiente. Em Docker no Linux o IP real do cliente é preservado. Cabeçalhos de proxy (`X-Forwarded-For`) continuam ignorados de propósito

## v1.8.2 (2026-10-02) — "Editar Pedido" com preços atuais

Trabalho solicitado pelo cliente, **fora dos milestones do `ROADMAP.md`**, que entrou em `master` depois de `v1.8.1`. Mesma regra do `v1.7.1`/`v1.8.1`: patch, para não ocupar o `v1.9.0`.

> ⚠️ **Mudança de comportamento visível (spec 052):** salvar o "Editar Pedido" na cozinha agora atualiza o pedido com os preços atuais do Admin — um pedido antigo editado passa a ter outro total na reimpressão e nos relatórios. Pedidos que ninguém edita continuam com os valores da venda.

### Novidades
- **"Editar Pedido" atualiza os preços pelo cardápio atual**: ao salvar, cada item do pedido (a cozinha envia todos) é recalculado com o cardápio do Admin — preço unitário, preço dos adicionais de pratos "Monte Seu Prato" e a soma, e a taxa de embalagem, que agora é recalculada em todo salvar (antes, só quando quantidade ou opção mudavam). Os nomes continuam os da venda. Vale para pedidos pendentes e concluídos; cancelado segue bloqueado. Prato ou adicional que saiu do cardápio mantém o preço gravado, então a edição nunca falha por preço. Qualquer `PATCH /api/orders/{id}/items/{itemId}` bem-sucedido re-precifica, mesmo com corpo vazio. A cozinha recarrega o cardápio ao abrir o modal (spec 052, PR #34)

### Infraestrutura / Qualidade
- **No máximo um PR por spec**: o PR da spec passa a levar o código, a spec, a entrada do `CHANGELOG.md` e a passada de docs — sem PR de changelog/docs separado depois do merge; o corte de release vai junto do último PR de spec da release. Regra registrada em `CLAUDE.md` › "Release workflow". A entrada da spec 052 acima é a última que precisou de PR próprio: o PR #34 foi mergeado antes da regra

## v1.8.1 (2026-10-02) — Taxa de embalagem do Admin e Admin redesenhado

Trabalho solicitado pelo cliente, **fora dos milestones do `ROADMAP.md`**, que entrou em `master` depois de `v1.8.0`. Pela regra já usada no `v1.7.1`, sai como patch (`v1.8.1`) para não ocupar o `v1.9.0`, reservado ao milestone `Community Productization` — embora os commits `feat`/`ui` pedissem um bump MINOR pela tabela SemVer. Desvio consciente, registrado aqui.

> ⚠️ **Rodar `bin/migrate` antes de subir o código novo (spec 050):** a migração `019_menu_item_packaging_option.sql` cria a coluna `menu_items.packaging_option`, que o código novo consulta em todo pedido de viagem. Código novo sem a migração = pedido de viagem e edição de item na cozinha falhando com erro de SQL. *(Correção 2026-10-27, spec 063: "antes" não é possível — os containers rodam o código direto da pasta, então ele entra no ar no `git pull`. Atualize com o restaurante fechado e rode `bin/migrate` logo após o `git pull`/`docker compose up -d --build`, seguindo [`docs/upgrading.md`](docs/upgrading.md).)*

### Novidades
- **Taxa de embalagem Simples/VIP vinda do cardápio do Admin**: as opções `viagem_simples` e `viagem_vip` passam a cobrar o preço do item do cardápio vinculado a cada uma ("Embalagem Simples" e "Embalagem Especial", vinculados pela migração 019), em vez de R$ 1,00 / R$ 2,00 fixos no `PricingService` e no caixa. Mudar o preço no Admin muda a taxa no caixa, na cozinha e no pedido gravado; o Admin mostra um badge nos dois itens. Sem item vinculado, vale o valor antigo — o pedido nunca falha. Pedidos já gravados mantêm o `packaging_cost` da venda, **exceto** quando a cozinha salva o "Editar Pedido": aí a embalagem dos itens enviados é recalculada pelo preço atual (decisão do cliente). `/api/menu` e `/api/admin/menu` ganham o campo `packaging_option` (mudança aditiva). O caixa só mostra o preço novo depois de recarregar a página (spec 050, PR #29)
- **Admin redesenhado, com layout e login únicos**: as 6 páginas do Admin (Cardápio, Relatórios, Ingredientes, Configurações, Logs, Auditoria) passam a compartilhar uma barra superior e um menu lateral — antes cada página linkava um conjunto diferente, Ingredientes não era alcançável e Auditoria só pelos Logs. No celular o menu vira gaveta. Um único login em `/admin/` substitui os três que existiam (Cardápio, Configurações, Relatórios) e devolve o usuário à página de onde veio; sessão expirada volta ao login e acesso sem permissão mostra "Sem permissão para esta área." em vez de um erro genérico; o gerente não vê no menu as páginas só de admin (o servidor continua barrando). No Cardápio: busca, filtro por categoria e grade/lista numa barra só, "Novo item" em modal e exclusão com modal de confirmação (também em Ingredientes), no lugar da caixa de confirmação do navegador. Mesmos endpoints, sem dependência nova, caixa e cozinha intocados (spec 051, PR #31)

### Correções
- **Editar ingrediente voltou a abrir o modal**: o modal de edição de Ingredientes usava `x-show` num modal do Bootstrap, cujo CSS o mantinha escondido; corrigido junto com o redesign (spec 051)

## v1.8.0 (2026-09-27) — Reliability & Quality

Milestone `v1.8.0` do `ROADMAP.md`, concluído. Todos os itens têm uma seção "Status" registrada em `docs/ROADMAP.md`, e o exit gate do milestone foi auditado e considerado cumprido em 2026-09-27 (ver `docs/ROADMAP.md` › "v1.8 Exit Gate", incluindo os dois ressalvos registrados junto).

> ⚠️ **Mudança de comportamento visível ao atualizar (spec 049):** se o seu working tree no Windows já foi afetado por fim de linha CRLF antes desta spec (`vendor/bin/php-cs-fixer` aponta um arquivo inteiro como diff, mas `git status`/`git diff` não mostram nada), veja `README.md` › "Line endings (Windows)" para o comando de recheckout limpo. Não é automático — precisa ser rodado manualmente.

> ⚠️ **Mudança de comportamento visível ao atualizar (spec 048):** `bin/migrate` passa a recusar rodar se o conteúdo de uma migração já aplicada não bater mais com o hash registrado — inclusive em instalações já existentes, porque a normalização de fim de linha da spec 034 (CRLF→LF) já muda esse hash sozinha para 10 dos 14 arquivos de migração anteriores à `v1.7.1`. É o alarme correto funcionando pela primeira vez, não uma regressão: rode `bin/migrate --trust-current-hashes` uma vez (reconcilia o hash registrado com o conteúdo atual, sem aplicar nada) e depois `bin/migrate` normalmente.

> ⚠️ **Mudança de comportamento visível ao atualizar (spec 038):** instalações com a impressora não configurada vão começar a acumular jobs de impressão com status `failed`, onde antes tudo parecia bem. É o alarme correto — antes esses pedidos constavam impressos sem nada ter saído. Configure `printer_ip` ou crie os pedidos com `print_ticket=false`.

> ⚠️ **Mudança de dados ao rodar `bin/migrate`:** as migrações `009_dishes_default_menu.sql` e `010_empanado_protein.sql` reescrevem o cardápio de "Pratos Principais" (13 pratos com nome, descrição e preço canônicos; "Barça" vira três pratos; "Picadinho" vira "Picadinho da Alegria") e os componentes empanados. São dados específicos do restaurante atual, não estrutura — exatamente o tipo de conteúdo que o item "Clean initial database" do `v1.9.0` pretende separar do schema.

### Trabalho de cliente, fora do milestone
- **Corrigir a opção de consumo de um item já enviado**: o modal "Editar Pedido" da cozinha passa a permitir trocar `local`/`viagem_simples`/`viagem_vip` por item, com o custo de embalagem, a reimpressão e os relatórios refletindo a mudança. No caixa, um modal de confirmação obrigatório entre "Enviar Pedido" e o envio real lista cada item com sua opção de consumo, para pegar o erro antes de sair (spec 046)
- **Modal "Editar Pedido" da cozinha redesenhado**: cada item em seu próprio card, botões de opção de consumo e contador de quantidade com área de toque confortável, cabeçalho e rodapé fixos com corpo rolável para pedidos grandes. Sem mudança de comportamento — mesmos bindings Alpine, mesmas chamadas de API (spec 047)
- **Cardápio padrão de pratos principais e proteínas empanadas**: as duas migrações descritas no aviso acima. Entraram em `master` num commit fora da convenção (`130450e`), junto com dois arquivos de spec cuja numeração colide com specs já existentes (`specs/006-dishes-default-menu.md`, `specs/007-empanado-protein.md`) — registrado aqui para o histórico ficar completo; a colisão de numeração segue como pendência separada

### Infraestrutura / Qualidade
- **Auditoria do exit gate do milestone**: as 9 seções do roadmap que ainda não tinham uma linha "Status" registrada (análise estática, estilo de código, pipeline de CI, testes de integração, testes E2E, confiabilidade de impressão, confiabilidade de realtime, logging estruturado, histórico de auditoria) foram conferidas contra o `CHANGELOG.md`/specs reais e agora têm uma. O exit gate do `v1.8.0` foi então avaliado e considerado cumprido — ver `docs/ROADMAP.md` › "v1.8 Exit Gate" para os dois ressalvos honestos registrados junto
- **Política de fim de linha (LF/CRLF), fechando o último item do milestone**: `.gitattributes` passa a ser a única fonte da verdade, cobrindo todo caminho de texto rastreado que uma auditoria completa (`git ls-files --eol`, 255 arquivos) achou sem regra `eol` explícita — scripts `bin/*` sem extensão, `.dockerignore`, `Dockerfile`, `.gitignore`, `composer.lock`, todo `.js`/`.css`/`.html`/`.yaml`/`.ini`/`.webmanifest`/`.sh`. Um commit dedicado, só de fim de linha (conferido com `git diff --ignore-space-at-eol` antes de commitar), corrigiu os 9 arquivos que já estavam commitados com CRLF ou (`.gitignore`) misto desde antes da spec 034 existir. Novo passo de CI, sem dependência nova, recusa a build se um blob rastreado tiver CRLF/misto de novo — testado forçando um blob assim diretamente no índice, do jeito que os 9 arquivos originais ficaram CRLF. `README.md` documenta `core.autocrlf false` para Windows e o comando de recheckout limpo para quem já foi afetado — exercitado de verdade nesta mesma sessão (o hook de segurança de IA do repositório recusou rodar `git reset --hard` para o assistente mesmo com confirmação prévia; o usuário rodou manualmente, e o resultado foi confirmado depois: 75 → 0 arquivos afetados) (spec 049)
- **`bin/migrate` detecta migração já aplicada com conteúdo divergente**: o hash de cada migração era gravado mas nunca comparado de novo — um arquivo editado depois de aplicado passava despercebido. A comparação normaliza fim de linha antes do hash, então uma normalização como a da spec 034 nunca é confundida com adulteração; uma mudança real de conteúdo interrompe `bin/migrate` **antes** de aplicar qualquer migração pendente na mesma execução. `bin/migrate --trust-current-hashes` reconcilia o hash registrado com o conteúdo atual — explícito, nunca automático — para o caso legítimo (linha de fim normalizada). Confirmado necessário de verdade: tanto o banco `restaurant_test` compartilhado quanto o banco de desenvolvimento deste projeto precisaram da reconciliação na prática (spec 048)
- **Teste de fresh install, nomeado como tal**: os passos que o CI já rodava (carregar `001_schema.sql` + `bin/migrate` contra um banco vazio) agora se identificam explicitamente como o teste de instalação nova pedido pelo roadmap, em vez de ser um efeito colateral da ordem dos passos (spec 048)
- **Teste real de upgrade `v1.7.1 → HEAD`**: novo teste de integração reconstrói o schema exatamente como estava na tag `v1.7.1` (lendo os arquivos históricos via `git show`), insere um pedido histórico, roda o `MigrationRunner` atual, e confirma que o pedido sobrevive e que o banco chega ao `HEAD` de verdade (checagem de uma tabela pós-`v1.7.1`). Banco de teste próprio (`MYSQL_DATABASE_TEST_UPGRADE`), nunca o `restaurant_test` compartilhado. O checkout do CI passa a buscar histórico completo (`fetch-depth: 0`), necessário para ler a tag (spec 048)

### Novidades
- **Indicador de versão no rodapé do caixa/cozinha/admin**: novo `GET /version` (público) roda `git describe --tags --always` contra o `.git` do próprio repositório (montado somente leitura no container `web`); as três telas mostram o resultado discretamente no canto inferior direito. Cai para `"dev"` sem quebrar a página quando o mount ou o binário `git` não estão disponíveis. Fora do escopo do milestone, pedido direto pelo usuário
- **Backup e restauração do banco de dados**: `bin/backup-db` e `bin/restore-db`, script rodando no host (não no container `web`, que não tem cliente MySQL — só `pdo_mysql`), chamando o `mysqldump`/`mysql`/`gzip` que já vêm na imagem oficial `mysql:8.0` do serviço `db`. Nenhuma credencial é lida do `.env` pelo script — usa as variáveis que o próprio `docker-compose.yml` já injeta no container `db`. `bin/restore-db` usa o backup mais recente em `backups/` por padrão (ou um arquivo informado), e exige digitar `RESTAURAR` (ou `--yes`) antes de rodar, porque um dump do `mysqldump` derruba e recria cada tabela por padrão. Restauração testada de verdade, contra um banco descartável criado só para isso — nunca o banco de desenvolvimento (spec 045)
- **Health checks**: dois endpoints públicos novos, `GET /health/live` e `GET /health/ready`, para infraestrutura verificar o processo e a disponibilidade do banco sem precisar de JWT. `/health/live` nunca falha, só confirma que o Slim está roteando; `/health/ready` faz uma consulta real pela conexão Eloquent já existente e responde `503 DB_UNAVAILABLE` (mensagem fixa, nunca a exceção original, para não vazar host/banco) quando o banco está fora do ar (spec 044)
- **Histórico de auditoria para operações administrativas sensíveis**: mudança de item de cardápio (`PATCH /api/admin/items/{id}`), mudança de configurações incluindo as chaves de impressora (`PUT /api/admin/settings`, que já é o mesmo endpoint para as duas coisas — não existiam endpoints separados), reabertura de pedido (`POST /api/orders/{id}/uncomplete`) e criação de usuário via `bin/create-admin` (não existe endpoint HTTP para isso; coberto mesmo assim, com ator nulo — não existe usuário autenticado num processo de CLI). Tabela `audit_log` própria, nunca podada (ao contrário de `jobs`/`events`), conceitualmente separada do `app.log` técnico por pedido explícito do roadmap. Nova tela `GET /api/admin/audit-log` + `public/admin/audit-log.php`, no mesmo padrão do visualizador de logs técnicos (spec 043)

### Observabilidade
- **Correlation ID por requisição**: todo request HTTP recebe um `request_id` (propagado de `X-Request-Id` quando o cliente já manda um, gerado como 32 caracteres hex quando não) — presente no header da resposta e no contexto de **todo** log emitido durante aquele request, inclusive o do handler de erro global. Autenticado, o log também carrega `user_id` (o `sub` do JWT); anônimo, a chave nem aparece (spec 042)
- **A cadeia HTTP → Pedido → Job → Impressão agora é rastreável em um único `app.log`**: o `request_id` de quem criou o pedido viaja dentro do `payload` do job (sem migração — é só mais uma chave no JSON que já existia) e chega ao log da tentativa de impressão, feita minutos depois por um processo totalmente separado (`bin/worker`). `PrintService` e as falhas de fila do `JobService` passam a logar contexto estruturado (`order_id`, `job_id`, `event`, `request_id`) em vez de mensagem concatenada em string (spec 042)

### Correções
- **Eventos em tempo real deixam de se perder entre containers**: a cozinha descobria pedidos novos via SSE lendo um arquivo em `sys_get_temp_dir()`, que é por container — um evento publicado pelo worker de impressão nunca chegava à cozinha, servida pelo container `web`. Confirmado quebrado três vezes nas specs 038-040 sem ser o assunto delas, até a 040 ter que desabilitar o SSE inteiro no servidor de teste. `OrderService` agora depende só da interface `EventPublisher`, nunca do transporte, implementada por uma tabela `events` no MySQL — o mesmo banco que todo container já compartilha (spec 041)
- **Reconexão da cozinha deixa de perder eventos**: o stream SSE passa a emitir uma linha `id:` por evento, habilitando a reconexão nativa do `EventSource` (`Last-Event-ID`) — uma tela que cai por um minuto recupera o que perdeu em vez de ficar desatualizada até o próximo carregamento completo. Retenção de 2 dias via `bin/events-prune`, reaproveitando o mesmo gancho horário que já poda jobs concluídos (spec 041)

### Testes
- **Suíte de testes de navegador** (`tests/e2e/`, Playwright): cinco testes cobrindo **só o que quebra exclusivamente no navegador** — a interação Alpine/Bootstrap que produziu o defeito invisível da spec 039, estados habilitado/desabilitado por binding, e elementos que aparecem por condição. Roda contra uma **segunda instância do app na porta 8081** apontada para o banco de teste, então nunca escreve no banco de desenvolvimento. O item de E2E do roadmap foi emendado junto, distinguindo "não automatizar o frontend inteiro" (que continua valendo) de "cobrir o que só quebra no navegador" (spec 040)
- **Semente do banco de teste deixa de crescer a cada execução**: o `001_schema.sql` tem `INSERT`s sem guarda de idempotência e o bootstrap da suíte de integração os reexecutava a cada processo — o banco de teste chegou a 510 categorias e 5368 itens, com `/api/menu` devolvendo 1 MB. O seed passa a rodar uma vez por banco; as migrações continuam rodando sempre (spec 040)
- **Suíte de integração com MySQL real**: 26 testes cobrindo autenticação, autorização, criação/numeração/preço/conclusão/reabertura de pedido, mutação de cardápio, relatórios e criação de job, mais a jornada ponta a ponta caixa → pedido → cozinha → conclusão → relatório. Exercitada pela camada HTTP, sem automação de navegador (spec 035)
- **Banco de teste dedicado, com o default seguro**: a suíte escreve linhas reais, então só roda com `MYSQL_DATABASE_TEST` apontando para um banco **diferente** do de produção. Sem a variável, os testes se pulam e `phpunit` continua saindo 0; apontada para o mesmo banco, a suíte falha alto em vez de arriscar os dados (spec 035)
- **Concorrência testada de verdade**: quatro processos de SO separados disputando o mesmo job e a mesma numeração de pedido — a lacuna que a spec 033 declarou e adiou, porque `lockForUpdate()` é no-op no SQLite da suíte unitária (spec 035)
- **Novo estágio no CI**: `Integration tests (PHPUnit, MySQL)`, com banco próprio criado num passo dedicado (spec 035)

### Novidades
- **Bloqueio de impressão após 3 falhas consecutivas**: três jobs de impressão falhando em sequência bloqueiam novas impressões na cozinha e no caixa, com um botão explícito de reativar. A contagem é de **jobs que falharam de vez**, não de tentativas — a spec 008 dá 3 tentativas por job, então contar tentativas bloquearia a impressora por causa de um único pedido. **O pedido continua sendo criado normalmente**: a regra dura do roadmap diz que falha de impressora nunca invalida um pedido (spec 039)
- **Aviso de falha de impressão nas telas**: cozinha e caixa consultam `GET /api/printer/status` a cada 15s e mostram um aviso identificando o pedido afetado a cada nova falha. Dois endpoints públicos novos, `GET /api/printer/status` e `POST /api/printer/reset`, pelo mesmo motivo que `/api/orders*` já é público: essas telas não têm login (spec 039)

### Correções
- **Impressora não configurada deixa de fazer o job "dar certo"**: `printOrder()` logava um aviso e retornava normal com `printer_ip` vazio, então o job virava `completed` — o pedido constava impresso sem nada ter saído, e `bin/jobs-status` não mostrava problema. `printTestPage()` já lançava exceção para a mesma condição; as duas rotas agora concordam, com a mensagem vinda de uma constante compartilhada (spec 038)
- **Teste de impressão passa a dizer o que está errado**: o endpoint não tinha `try/catch`, então qualquer falha virava 500 e era sanitizada para "Erro interno do servidor" — a única tela feita para diagnosticar a impressora não conseguia diagnosticá-la. Agora responde `503 PRINTER_UNAVAILABLE` com mensagem acionável (IP não configurado, ou o endereço que foi tentado), construída de fatos conhecidos em vez de ecoar a exceção (spec 038)
- **Cozinha para de afirmar que imprimiu**: o toast dizia "enviado para impressão!" numa resposta que só significa "enfileirado"; passa a "na fila de impressão" (spec 038)
- **Aviso de bloqueio nunca sumia da tela**: o `x-show` do Alpine perde para o `!important` das utilitárias do Bootstrap 5, então o aviso continuava visível mesmo depois de reativar — a API já respondia desbloqueado e o botão voltava a funcionar, só o texto mentia. Encontrado por verificação no navegador, que nenhum teste de backend pegaria (spec 039)
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
