# Roadmap — GastroFlow v2.0

> Plano de melhorias arquiteturais, qualidade e infraestrutura.
> Organizado em Milestones (GitHub Issues) com ordem de execução definida.

---

## Sumário

- [Milestones](#milestones)
- [Labels](#labels)
- [Issues por Milestone](#issues-por-milestone)
  - [v2.0 — Fundação](#v20--fundação)
  - [v2.1 — Testes & Qualidade](#v21--testes--qualidade)
  - [v2.2 — Arquitetura](#v22--arquitetura)
  - [v2.3 — Frontend & Infra](#v23--frontend--infra)
- [Ordem de execução recomendada](#ordem-de-execução-recomendada)
- [Branch naming convention](#branch-naming-convention)
- [Template de Pull Request](#template-de-pull-request)

---

## Milestones

| Milestone | Previsão | Foco |
|---|---|---|
| **v2.0 — Fundação** | 1-2 semanas | Changes pequenas e mecânicas que preparam o terreno |
| **v2.1 — Testes & Qualidade** | 2-3 semanas | PHPUnit + CI/CD (fazer antes de refatores grandes) |
| **v2.2 — Arquitetura** | 3-4 semanas | Refatores de controllers, validadores, paginação |
| **v2.3 — Frontend & Infra** | 2 semanas | Modularização JS, build tool, SSE robusto |

---

## Labels

| Label | Cor | Significado |
|---|---|---|
| `tipo: refactor` | `#1d76db` | Mudança de código sem alterar comportamento |
| `tipo: segurança` | `#b60205` | CORS, JWT, validação |
| `tipo: teste` | `#0e8a16` | PHPUnit |
| `tipo: infra` | `#0052cc` | CI/CD, build tool |
| `tipo: frontend` | `#fbca04` | Alpine.js, CSS, JS |
| `tamanho: S` | `#c2e0c6` | ≤ 1 hora |
| `tamanho: M` | `#fef2c0` | 2-4 horas |
| `tamanho: L` | `#f9d0c4` | 4-8 horas |
| `tamanho: XL` | `#e99695` | > 8 horas |
| `prioridade: alta` | `#e11d21` | Fazer agora |
| `prioridade: media` | `#eb6420` | Próximo sprint |
| `prioridade: baixa` | `#cccccc` | Quando der tempo |

---

## Issues por Milestone

### v2.0 — Fundação

#### #12 — Adicionar `declare(strict_types=1)` em todos os PHP

**Labels:** `tipo: refactor` `tamanho: S` `prioridade: alta`

**Descrição:**  
Nem todos os arquivos PHP declaram `strict_types=1`. Para evitar bugs de coerção, adicionar em todos os arquivos `src/` e `public/` (exceto páginas que misturam HTML/PHP).

**Arquivos envolvidos:** Todos os `.php` em `src/` e `public/`.

**Acceptance Criteria:**
- [ ] Todo arquivo PHP em `src/` tem `declare(strict_types=1)` após `<?php`
- [ ] Todo arquivo PHP em `public/` (não-HTML) tem a declaração

---

#### #8 — Tornar CORS configurável por variável de ambiente

**Labels:** `tipo: segurança` `tamanho: M` `prioridade: alta`

**Descrição:**  
Hoje o CORS permite qualquer origem (`*`). Criar env `CORS_ALLOWED_ORIGIN` para configurar a origem permitida. Fallback para `*` se não setada.

**Arquivos envolvidos:**
- `src/Middleware/CorsMiddleware.php`
- `.env.example`

**Acceptance Criteria:**
- [ ] `CORS_ALLOWED_ORIGIN` definida no `.env.example`
- [ ] `CorsMiddleware` lê a variável e seta o header dinamicamente
- [ ] OPTIONS preflight respeita a mesma origem
- [ ] Fallback `*` mantém backward compatibility

---

#### #9 — JWT sem fallback hardcoded

**Labels:** `tipo: segurança` `tamanho: S` `prioridade: alta`

**Descrição:**  
`Routes.php` tem `$_ENV['JWT_SECRET'] ?? 'your-secret-key-change-me'`. Esse fallback público é risco de segurança. Deve lançar exceção se a env não estiver definida.

**Arquivos envolvidos:**
- `src/Routes.php`

**Acceptance Criteria:**
- [ ] Fallback removido
- [ ] `RuntimeException` lançada se `JWT_SECRET` não estiver setada

---

#### #10 — Caminhos absolutos via Settings (DI)

**Labels:** `tipo: refactor` `tamanho: M` `prioridade: alta`

**Descrição:**  
Diversos arquivos usam `__DIR__ . '/../../'` hardcoded. Definir caminhos no objeto `Settings` e injetar via DI.

**Arquivos envolvidos:**
- `src/Settings.php` (adicionar métodos)
- `src/App.php`
- `src/Controllers/AdminController.php`

**Acceptance Criteria:**
- [ ] `Settings` tem `getLogDir(): string`, `getPublicDir(): string`, etc.
- [ ] `AdminController` recebe `Settings` via construtor e usa os métodos
- [ ] Toda ocorrência de `__DIR__ . '/../..'` no código é substituída

---

### v2.1 — Testes & Qualidade

#### #1 — Implementar PHPUnit com testes de smoke

**Labels:** `tipo: teste` `tamanho: L` `prioridade: alta`

**Descrição:**  
Zero testes no projeto. Implementar PHPUnit com:
1. Smoke tests nos endpoints públicos
2. Teste unitário da `OrderService`
3. Teste unitário da `OrderValidator`

**Arquivos envolvidos:**
- `composer.json` (adicionar `phpunit/phpunit`)
- `phpunit.xml` (criar)
- `tests/` (criar diretório)

**Acceptance Criteria:**
- [ ] `composer require --dev phpunit/phpunit ^11`
- [ ] `phpunit.xml` configurado
- [ ] `tests/Smoke/ApiTest.php` → GET `/api/menu` retorna 200
- [ ] `tests/Unit/OrderServiceTest.php` → `createOrder` válido
- [ ] `tests/Unit/OrderValidatorTest.php` → validação rejeita inválidos
- [ ] `vendor/bin/phpunit` passa verde

---

#### #15 — GitHub Actions CI/CD

**Labels:** `tipo: infra` `tamanho: M` `prioridade: alta`

**Descrição:**  
Pipeline mínima: `composer install` → `phpunit`.

**Arquivos envolvidos:**
- `.github/workflows/ci.yml` (criar)

**Dependências:** [#1](#1--implementar-phpunit-com-testes-de-smoke)

**Acceptance Criteria:**
- [ ] Workflow roda em `push` (main) e `pull_request`
- [ ] Setup PHP 8.2
- [ ] `vendor/bin/phpunit` passa no CI
- [ ] Badge de status no `README.md`

---

### v2.2 — Arquitetura

#### #2 — Unificar DishController e IngredientController

**Labels:** `tipo: refactor` `tamanho: L` `prioridade: media`

**Descrição:**  
`DishController` e `IngredientController` usam Eloquent direto. Migrar para Service/Repository.

**Arquivos envolvidos:**
- `src/Controllers/DishController.php`
- `src/Controllers/IngredientController.php`
- `src/Services/MenuService.php`
- `src/Services/IngredientService.php` (criar)
- `src/Repositories/IngredientRepository.php` (criar)

**Acceptance Criteria:**
- [ ] Nenhum Controller chama Model diretamente
- [ ] Lógica movida para `IngredientService` + `IngredientRepository`
- [ ] Testes continuam passando

---

#### #3 — Extrair AdminController em controllers menores

**Labels:** `tipo: refactor` `tamanho: L` `prioridade: media`

**Descrição:**  
`AdminController` mistura settings, logs, logo, test-print. Extrair para:
- `SettingsController`
- `LogController`
- `LogoController`

**Arquivos envolvidos:**
- `src/Controllers/` (criar novos, refatorar existente)
- `src/Routes.php`

**Acceptance Criteria:**
- [ ] Cada controller tem 1 responsabilidade
- [ ] Rotas atualizadas
- [ ] Frontend admin continua funcionando

---

#### #6 — Validators para endpoints admin

**Labels:** `tipo: refactor` `tipo: segurança` `tamanho: L` `prioridade: media`

**Descrição:**  
Criar validators: `MenuItemValidator`, `SettingValidator`, `IngredientValidator`.

**Arquivos envolvidos:**
- `src/Validators/MenuItemValidator.php` (criar)
- `src/Validators/SettingValidator.php` (criar)
- `src/Validators/IngredientValidator.php` (criar)

**Acceptance Criteria:**
- [ ] `POST /api/admin/items` valida campos obrigatórios
- [ ] `PUT /api/admin/settings` valida tipos
- [ ] Erros no formato padronizado

---

#### #7 — Error handling padronizado

**Labels:** `tipo: refactor` `tamanho: M` `prioridade: media`

**Descrição:**  
Criar helper `ApiResponse` para padronizar respostas de sucesso e erro.

**Formato de erro:**
```json
{
  "success": false,
  "error": "Mensagem amigável",
  "code": "VALIDATION_ERROR"
}
```

**Arquivos envolvidos:**
- `src/Http/ApiResponse.php` (criar)
- Todos os controllers (refatorar `try/catch`)

**Acceptance Criteria:**
- [ ] Erros sempre têm `success: false`
- [ ] Sucessos sempre têm `success: true`
- [ ] Códigos padronizados

---

#### #11 — Paginação no GET /api/orders

**Labels:** `tipo: refactor` `tamanho: M` `prioridade: media`

**Descrição:**  
Adicionar `?page=1&per_page=50` ao listing de pedidos.

**Arquivos envolvidos:**
- `src/Controllers/OrderController.php`
- `src/Repositories/OrderRepository.php`

**Acceptance Criteria:**
- [ ] `GET /api/orders?page=2&per_page=25` retorna 25 registros
- [ ] Retorno inclui `meta` com `page`, `per_page`, `total`, `last_page`
- [ ] Sem parâmetros funciona como hoje

---

### v2.3 — Frontend & Infra

#### #4 — Criar módulo compartilhado common.js

**Labels:** `tipo: frontend` `tamanho: L` `prioridade: media`

**Descrição:**  
Extrair código duplicado (toasts, tema, fetch) para `public/assets/js/common.js`.

**Funções a extrair:**
- `showMessage(text, type)` → `Alpine.store('toasts')`
- `applyTheme()` / `toggleDarkMode()` → `Alpine.store('theme')`
- `apiFetch(url, options)` — wrapper com token + 401
- `sortPratoDoDiaFirst()` / `sortMenuPratoDoDiaFirst()`

**Arquivos envolvidos:**
- `public/assets/js/common.js` (criar)
- `public/cashier/app.js`
- `public/kitchen/app.js`
- `public/admin/app.js`, `reports.js`, `settings.js`, `logs.js`
- Todos os `*.php` que carregam scripts

**Acceptance Criteria:**
- [ ] `common.js` carregado antes dos apps em todas as páginas
- [ ] Toast system centralizado via `Alpine.store`
- [ ] Dark mode gerenciado centralizadamente
- [ ] Fetch wrapper lida com token e 401 automático

---

#### #17 — Setup NPM + Vite para frontend

**Labels:** `tipo: infra` `tipo: frontend` `tamanho: XL` `prioridade: baixa`

**Descrição:**  
Migrar de CDN para dependências locais com Vite.

**Arquivos envolvidos:**
- `package.json` (criar)
- `vite.config.js` (criar)
- `Dockerfile` / `docker-compose.yml` (ajustar)

**Acceptance Criteria:**
- [ ] `npm install` baixa todas as dependências
- [ ] `npm run build` gera bundle em `public/assets/dist/`
- [ ] Pages PHP referenciam bundle local
- [ ] Tamanho final menor que CDN individual

---

#### #5 — Substituir SSE file-based

**Labels:** `tipo: refactor` `tamanho: XL` `prioridade: baixa`

**Descrição:**  
O sistema de eventos SSE usa arquivo JSON em `sys_get_temp_dir()` — sem lock, sem fila. Substituir por Redis pub/sub ou tabela `events` no MySQL.

**Arquivos envolvidos:**
- `src/Services/OrderService.php`
- `public/api/events/stream.php`
- `docker-compose.yml` (se Redis)
- Migration `009_events.sql` (se MySQL)

**Acceptance Criteria:**
- [ ] Eventos não são perdidos em concorrência
- [ ] Cozinha recebe eventos em ≤ 2s
- [ ] Backward compatible com frontend existente

---

## Ordem de execução recomendada

| Ordem | Issue | Branch | Motivo |
|---|---|---|---|
| 1 | #12 strict_types | `chore/strict-types` | Mecânico, evita conflitos futuros |
| 2 | #8 CORS | `feat/cors-env` | Pequeno, independente |
| 3 | #9 JWT | `fix/jwt-no-fallback` | Pequeno, independente |
| 4 | #10 caminhos | `refactor/paths-settings` | Toca vários arquivos, melhor fazer cedo |
| 5 | #1 PHPUnit | `feat/phpunit` | Testes protegem os refatores seguintes |
| 6 | #15 CI/CD | `feat/github-actions` | Depende de #1 |
| 7 | #2 Dish+Ingredient | `refactor/dish-ingredient` | Refactor médio, com testes |
| 8 | #3 AdminController | `refactor/admin-controller` | Refactor médio |
| 9 | #7 error handling | `feat/error-handler` | Toca controllers, melhor após splits |
| 10 | #6 validators | `feat/admin-validators` | Complementa #7 |
| 11 | #11 paginação | `feat/orders-pagination` | Independente |
| 12 | #4 common.js | `refactor/common-js` | Frontend, independente |
| 13 | #17 NPM+Vite | `feat/vite-setup` | Infra frontend |
| 14 | #5 SSE | `refactor/sse-events` | Mais complexo, último |

---

## Branch naming convention

```
tipo/descricao-curta
```

Exemplos:
- `chore/strict-types`
- `feat/cors-env`
- `fix/jwt-no-fallback`
- `refactor/paths-settings`
- `feat/phpunit`
- `feat/github-actions`
- `refactor/dish-ingredient`
- `refactor/admin-controller`
- `feat/error-handler`
- `feat/admin-validators`
- `feat/orders-pagination`
- `refactor/common-js`
- `feat/vite-setup`
- `refactor/sse-events`

---

## Template de Pull Request

```
## Resumo
<!-- O que esta PR faz em 1-2 linhas -->

## Issues relacionadas
Closes #N

## Tipo de mudança
- [ ] Refactor (sem mudar comportamento)
- [ ] Feature (nova funcionalidade)
- [ ] Fix (correcao de bug)
- [ ] Chore (config, build, dependencias)

## Checklist
- [ ] Codigo segue `declare(strict_types=1)`
- [ ] Testes passam (`vendor/bin/phpunit`)
- [ ] Verificado manualmente no navegador
- [ ] CHANGELOG.md atualizado (se aplicavel)

## Screenshots (se frontend)
```
