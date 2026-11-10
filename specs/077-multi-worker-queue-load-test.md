# Spec 077 — Teste de carga da fila com múltiplos workers

## Metadata

- Status: Implemented
- Created: 2026-11-09
- Updated: 2026-11-09
- Owner: Henry
- Related issue: Trello #64 "Teste de carga com múltiplos workers" (https://trello.com/c/DXw5a9CH)
- Related branch: `test/077-multi-worker-queue-load` (proposta)

## Context

`CHANGELOG.md` › v1.8.0 › "Lacunas conhecidas nesta fase" registra duas lacunas que este trabalho fecha ou mede:

- "Operação com **múltiplos workers em paralelo** não foi testada sob carga; o Community roda um `print-worker`".
- "O deadlock **não reproduz mais**, então o caminho de retry da spec 037 é exercitado por simulação, não pelo MySQL. O código de erro `1205` (lock wait timeout) está na lista de retry mas nunca foi observado".

`docs/architecture.md` › Known architectural limitations acrescenta que o `ConcurrencyTest` "não é determinístico — a green run proves it did not happen that time, not that it cannot".

O milestone v2.1 (fiscal) vai pôr na mesma fila um job de emissão em que trabalho duplicado tem custo real (nota emitida duas vezes). Antes disso, a fila precisa ter sido medida sob carga com vários workers, não só com 1 job e 4 processos.

## Problem

O único teste de concorrência da fila (`tests/Integration/ConcurrencyTest.php::testTwoWorkersCannotClaimTheSameJob`) cria **um** job e dispara 4 processos que chamam `processNext()` **uma vez** cada. Ele não cobre:

- muitos jobs drenados por vários workers ao mesmo tempo (o regime de produção se houver mais de um worker, ou de um pico de pedidos);
- o laço `processAll()`/`processNext()` repetido, com a varredura `reclaimStaleReservations()` rodando em cada processo (a origem do deadlock da spec 037);
- se algum handler roda **mais de uma vez** — o teste atual mede `attempts`, mas um handler que rodasse duas vezes em reservas diferentes não seria visto;
- quantos retries transitórios (`40001`/`1213`/`1205`) acontecem de fato — hoje ninguém sabe se é zero ou centenas.

## Goals

- Um teste de integração, contra MySQL real e em processos separados, que drene N jobs com M workers paralelos e prove: cada handler rodou exatamente uma vez, todo job terminou `completed`, nenhum ficou `pending`/`reserved`/`failed`.
- Contar e reportar os retries transitórios observados por código (`1213`/`40001`/`1205`) e o tempo total, para registrar a medição.
- Atualizar `CHANGELOG.md` e `docs/architecture.md` com o resultado medido, em vez da lacuna aberta.

## Non-goals

- Rodar mais de um `print-worker` em produção ou mudar `docker-compose.yml` — o Community continua com um worker.
- Mudar a semântica de lock de `JobService` (ex.: `SKIP LOCKED`). Se o teste achar defeito, ele é registrado e vira spec própria, como a 037 nasceu da 035.
- Provocar `1205` artificialmente (segurar lock por 50 s com `innodb_lock_wait_timeout` baixo). O handler roda fora da transação de claim, então `1205` só apareceria com o banco travado por outro motivo; a medição registra se apareceu ou não.
- Benchmark de desempenho com meta de throughput. O tempo é registrado como informação, não como critério.
- Ferramenta de carga nova (k6, JMeter, etc.) — nada de dependência nova.

## Current behavior

Confirmado lendo o código:

- `src/Services/JobService.php::processNext()` chama `sweepIfDue()` (no máximo a cada 10 s por processo; a primeira chamada sempre varre), depois reserva o próximo job em `DB::transaction` com `lockForUpdate()` (FOR UPDATE simples, sem `SKIP LOCKED`), incrementa `attempts`, executa o handler **fora** da transação e grava `completed` via `recordCompletion()`. Erros `40001`/`1213`/`1205` são repetidos até 3 vezes em `retryOnTransientDbError()`, que loga `warning` "Erro transitório de banco; tentando novamente" com `operation` e `attempt`.
- `processAll($queue, $max)` repete `processNext()` até não haver job ou atingir `$max`; `bin/worker` usa `processAll($queue, 10)` em laço.
- `tests/Integration/ConcurrencyTest.php` já tem `runConcurrently()` (via `proc_open`, processos iniciados antes de serem lidos) e `failOnUnexpectedError()`.
- `tests/Integration/bin/claim-one-job.php` é o modelo de script de processo filho: carrega `.env`, faz a ponte `getenv()` → `$_ENV`, força `MYSQL_DATABASE` ao banco de teste recebido e usa `JobService(new NullLogger())`.
- `tests/Integration/NoopJob.php` é o handler sem efeito; ele não registra execução, então não serve para detectar execução dupla.
- `IntegrationTestCase` limpa `jobs` entre testes e pula tudo sem `MYSQL_DATABASE_TEST`.
- CI (`.github/workflows/ci.yml`, passo "Integration tests (PHPUnit, MySQL)") roda `vendor/bin/phpunit --testsuite Integration` contra MySQL 8.0.
- `monolog/monolog` já traz `Monolog\Handler\TestHandler`, que guarda os registros em memória — dá para contar os warnings de retry sem dependência nova.

Nota: o template de spec diz que o projeto não tem testes automatizados; isso está desatualizado desde as specs 004/035 — este trabalho usa a suíte de integração existente.

## Proposed behavior

Novo teste em `tests/Integration/ConcurrencyTest.php` (reaproveitando `runConcurrently()`):

1. Cria `N = 200` jobs pendentes na fila `spec077`, com um handler de teste novo (`Tests\Integration\RecordingJob`) cujo `handle()` acrescenta o `job_id` a um arquivo de registro (`fopen` modo `a` + `flock(LOCK_EX)`) e dorme um tempo curto (`usleep` de 1–5 ms) para simular o trabalho de impressão e alongar a sobreposição.
2. Dispara `M = 4` processos de `tests/Integration/bin/drain-queue.php <fila> <banco>` (o caminho do arquivo de registro vai no payload de cada job, em `data.log`), que chamam `processNext()` até ele devolver `false` e imprimem uma linha JSON: `{"processed": n, "retries": {"1213": a, "40001": b, "1205": c, "other": d}, "operations": {"claim": x, …}, "seconds": t}`, contando os warnings de retry por um `TestHandler` do Monolog. *(Corrigido 2026-11-09, `/spec-review`: o rascunho previa um terceiro argumento `<arquivo-de-registro>` e não tinha `operations` — ver Implementation log.)*
3. Verifica no banco e no arquivo:
   - todos os N jobs `completed`, `attempts = 1`;
   - zero jobs `pending`, `reserved` ou `failed`;
   - o arquivo de registro tem exatamente N linhas, N ids distintos, iguais aos ids criados;
   - a soma de `processed` dos M processos é N;
   - nenhum processo terminou com erro (`failOnUnexpectedError`).
4. Escreve a medição (N, M, segundos, retries por código, jobs por worker) com `fwrite(STDERR, …)` ao final, para aparecer no log do CI e ser copiada para a spec.

## Functional requirements

1. FR1 — `tests/Integration/bin/drain-queue.php` processa a fila recebida até `processNext()` devolver `false`, contra o banco recebido por argumento (mesma proteção do `claim-one-job.php`), e imprime uma única linha JSON com `processed`, `retries` (por código), `operations` (retries por operação do `JobService`) e `seconds`.
2. FR2 — `Tests\Integration\RecordingJob::handle()` registra cada execução com o id do job num arquivo informado em `$data['log']`, de forma que escritas de processos concorrentes não se misturem.
3. FR3 — o novo teste falha se qualquer job rodar o handler mais de uma vez, se qualquer job não rodar, ou se qualquer job terminar em estado diferente de `completed` com `attempts = 1`.
4. FR4 — o novo teste falha se um processo filho sair com erro que não seja retry esgotado de deadlock (mesma regra de `failOnUnexpectedError`); retries transitórios bem-sucedidos não falham o teste — são contados e reportados.
5. FR5 — o teste roda dentro da suíte `Integration` existente (mesmo comando, mesmo CI), sem alterar `phpunit.xml` além do necessário.

## Non-functional requirements

- Duração do novo teste no CI abaixo de 30 s (estimativa: 200 jobs × ~5 ms / 4 workers ≈ 0,3 s de trabalho + boot dos processos).
- Nenhuma dependência nova; nada em `src/` muda.
- O arquivo de registro fica em `sys_get_temp_dir()` e é apagado no `tearDown`/fim do teste.

## User flows

Not applicable — sem tela nem papel de usuário; é teste de infraestrutura.

## API changes

Not applicable — nenhum endpoint muda.

## Data model and migrations

Not applicable — usa a tabela `jobs` existente, só no banco de teste.

## Architecture and affected components

- `tests/Integration/ConcurrencyTest.php` — novo método de teste.
- `tests/Integration/RecordingJob.php` — novo handler de teste (arquivo PSR-4 próprio, pelo mesmo motivo documentado em `NoopJob.php`).
- `tests/Integration/bin/drain-queue.php` — novo script de processo filho, copiado do padrão de `claim-one-job.php`.
- `CHANGELOG.md`, `docs/architecture.md` — registro da medição (docs pass).
- `src/` — **sem mudança**, a menos que o teste ache defeito (ver Open questions 1).

## Security considerations

Not applicable para a aplicação: só código de teste. O script filho mantém a proteção de só rodar contra o banco recebido, e a suíte continua pulando sem `MYSQL_DATABASE_TEST` e falhando se ele for igual a `MYSQL_DATABASE`.

## Backward compatibility

Nenhum impacto: não altera código de produção, schema nem testes existentes.

## Acceptance criteria

- AC1 — Com `MYSQL_DATABASE_TEST=restaurant_test`, `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --filter testManyWorkersDrainTheQueueRunningEachHandlerOnce` passa: 200 jobs, 4 workers, 200 `completed` com `attempts = 1`, 0 em outro estado, 200 execuções registradas com 200 ids distintos.
- AC2 — O mesmo teste roda verde **5 vezes seguidas** localmente (registrado na Validation evidence, com a medição de cada run).
- AC3 — Prova de que o teste detecta o defeito: com uma alteração temporária (não commitada) que faça o handler rodar duas vezes para algum job — p.ex. o script filho chamando `handle()` de novo após um `processNext()` bem-sucedido —, o teste falha apontando ids duplicados. Saída registrada na Validation evidence; alteração revertida.
- AC4 — Sem `MYSQL_DATABASE_TEST`, o teste é pulado (skipped), como o restante da suíte.
- AC5 — A suíte `Integration` inteira passa no CI do PR (passo "Integration tests (PHPUnit, MySQL)"), e PHPStan e PHP-CS-Fixer passam com os arquivos novos.
- AC6 — `CHANGELOG.md` (entrada em "Não lançado") e `docs/architecture.md` › Known architectural limitations registram a medição real (N, M, retries por código, tempo) e substituem a frase "não foi testada sob carga" por esse resultado, com nota datada.

## Implementation plan

1. Criar `tests/Integration/RecordingJob.php` (append + `flock` + `usleep`).
2. Criar `tests/Integration/bin/drain-queue.php` a partir de `claim-one-job.php`, com `JobService` recebendo um `Logger` com `TestHandler`, laço até `processNext()` devolver `false`, contagem dos warnings de retry por código e saída JSON.
3. Adicionar `testManyWorkersDrainTheQueueRunningEachHandlerOnce()` a `ConcurrencyTest.php`, reaproveitando `runConcurrently()`/`failOnUnexpectedError()`.
4. Rodar o teste 5 vezes, registrar medições (AC1, AC2); rodar a prova negativa (AC3); rodar sem a variável (AC4); PHPStan e php-cs-fixer.
5. Docs pass: `CHANGELOG.md`, `docs/architecture.md` (e conferir `specs/000-project-baseline.md`/`README.md`).

## Testing and validation strategy

O projeto tem suíte de integração contra MySQL (spec 035), e este trabalho é ele próprio um teste nela.

- AC1/AC2: `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration --filter testManyWorkersDrainTheQueueRunningEachHandlerOnce`, 5 vezes, copiando a medição do STDERR.
- AC3: alteração temporária no script filho, rodar, ver a falha, `git checkout` no arquivo.
- AC4: `docker compose exec web vendor/bin/phpunit --filter testManyWorkersDrainTheQueueRunningEachHandlerOnce` sem a variável → `S`.
- AC5: CI do PR + `vendor/bin/phpstan analyse` e `vendor/bin/php-cs-fixer fix --dry-run --diff` locais.
- AC6: revisão do diff de docs.

## Rollout and rollback

Só testes e docs. Rollback: reverter o commit. Nada a implantar.

## Open questions

*(Respostas registradas em 2026-11-09, durante a implementação.)*

1. (não bloqueante) Se o teste revelar execução dupla, job preso ou erro não-transitório, o defeito **não** é corrigido nesta spec: ela registra a evidência, o teste fica marcado como falha conhecida? Proposta: registrar e abrir spec própria antes de mergear, como 035 → 037; decidir com o resultado em mãos. **Resolvida: não se aplicou** — nenhuma execução dupla, job preso ou erro não-transitório em 7 runs; só deadlocks `1213`, todos recuperados.
2. (não bloqueante) N = 200 e M = 4 são suficientes? Proposta: sim para o CI; se a medição local mostrar zero contenção, rodar uma vez à parte com N = 2000 e M = 8 (só registrado na evidência, sem ir para o CI). **Resolvida:** 200/4 já produz contenção real (1–3 deadlocks por run), então a rodada maior não foi necessária.
3. (não bloqueante) O teste deve ficar em `ConcurrencyTest.php` ou num arquivo próprio? Proposta: no `ConcurrencyTest`, que já tem o utilitário `runConcurrently()`. **Resolvida:** ficou no `ConcurrencyTest`.

## Task checklist

- [x] `RecordingJob` criado
- [x] `drain-queue.php` criado
- [x] Teste novo em `ConcurrencyTest`
- [x] AC1/AC2 — 5 runs verdes com medição
- [x] AC3 — prova negativa
- [x] AC4 — skip sem variável
- [ ] AC5 — PHPStan e php-cs-fixer locais feitos; **falta o CI do PR**
- [x] AC6 — CHANGELOG e architecture.md

## Implementation log

- 2026-11-09 — `/spec-implement` rodado com a spec em Draft e sem pergunta bloqueante: tratado como aprovação (Draft → Approved → In Progress).
- 2026-11-09 — Os jobs são criados com um único `INSERT` em lote (`Db::table('jobs')->insert`), não 200 `Job::create()`. A primeira tentativa passou `created_at`/`updated_at` e falhou: a tabela `jobs` não tem `updated_at` (`common/migrations/007_jobs.sql`), e `created_at` tem default. Os dois saíram.
- 2026-11-09 — Desvio pequeno do FR1: além de `retries` por código, o `drain-queue.php` também devolve `operations` (retries por operação do `JobService`: `claim`, `complete`, …). A primeira run mostrou deadlocks reais, e saber **onde** eles acontecem é o que torna a medição útil. O formato definido no FR1 continua lá; o campo é a mais.
- 2026-11-09 — Achado: o deadlock `1213` **reproduz** com 200 jobs e 4 workers — 1 a 3 por run, no `claim` e no `complete` —, ao contrário da nota do v1.8.0 de que ele "não reproduz mais". Todos foram recuperados pelo retry da spec 037 e nenhum handler rodou duas vezes, então não há defeito a corrigir (Open question 1 não se aplicou). O `1205` não apareceu em nenhuma run.
- 2026-11-09 — A duração variou entre ~1 s e ~4,4 s por run, sem relação com o número de retries. Registrado como informação; não é critério (Non-goals).
- 2026-11-09 — `/spec-review`: a seção Proposed behavior e o FR1 foram alinhados ao que foi implementado (sem o argumento `<arquivo-de-registro>`, com `operations`); comentário de uso de `drain-queue.php` reordenado.
- 2026-11-09 — PHPStan não analisa `tests/` (`phpstan.neon` › `paths`: `src`, `bin`), então o "PHPStan passa" do AC5 só confirma que nada em `src/`/`bin/` mudou. O php-cs-fixer cobre `tests/`.

## Validation evidence

Todos os comandos rodados em 2026-11-09 contra o stack de desenvolvimento (`restaurant_web`), banco de teste `restaurant_test`.

**AC1/AC2** — `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration --filter testManyWorkersDrainTheQueueRunningEachHandlerOnce`, 5 vezes seguidas, todas `OK (1 test, 14 assertions)`:

| Run | Jobs por worker | Segundos (por worker) | Retries | Operações |
|---|---|---|---|---|
| 1 | 51/49/50/50 | ~4,1 | `1213`: 1 | claim: 1 |
| 2 | 51/51/49/49 | ~0,93 | `1213`: 2 | claim: 2 |
| 3 | 52/48/50/50 | ~1,22 | `1213`: 2 | claim: 1, complete: 1 |
| 4 | 49/50/52/49 | ~0,99 | `1213`: 1 | claim: 1 |
| 5 | 52/49/49/50 | ~4,36 | `1213`: 2 | claim: 2 |

Em todas: `40001`: 0, `1205`: 0, `other`: 0. Uma run anterior a estas (antes do campo `operations`) também passou, com `1213`: 3, e a run da suíte completa (AC5) passou com `1213`: 1 → 7 runs verdes no total.

**AC3** — alteração temporária em `tests/Integration/RecordingJob.php` (gravar o id duas vezes quando `id % 50 === 0`), arquivo salvo antes e restaurado depois (`grep -c "AC3 TEMP"` → `0`). Resultado:

```
1) Tests\Integration\ConcurrencyTest::testManyWorkersDrainTheQueueRunningEachHandlerOnce
Handlers ran more than once for these job ids
+    0 => 1500,
+    1 => 1550,
+    2 => 1600,
+    3 => 1650,
FAILURES!
Tests: 1, Assertions: 12, Failures: 1.
```

**AC4** — `docker compose exec web vendor/bin/phpunit --filter testManyWorkersDrainTheQueueRunningEachHandlerOnce` (sem `MYSQL_DATABASE_TEST`) → `S`, `Tests: 1, Assertions: 0, Skipped: 1.`

**AC5** — parcial:
- `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration` → `OK, but some tests were skipped! Tests: 78, Assertions: 348, Skipped: 2.` (os 2 pulados não são deste teste).
- `docker compose exec web vendor/bin/phpstan analyse --no-progress` → `[OK] No errors` (não cobre `tests/`, ver Implementation log).
- `docker compose exec web vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 119 files that can be fixed`.
- **CI do PR: ainda não rodou** (sem PR aberto). Por isso o status é `Implemented`, não `Verified`.

**AC6** — `CHANGELOG.md`: entrada em "Não lançado › Qualidade" e correção datada nas duas linhas de lacuna do v1.8.0; `docs/architecture.md` › Known architectural limitations: nota datada com a medição. Conferidos sem mudança necessária: `README.md`, `docs/technical-decisions.md`, `specs/000-project-baseline.md` (nenhum afirmava algo sobre carga com vários workers).
