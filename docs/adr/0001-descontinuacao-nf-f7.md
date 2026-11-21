# ADR 0001 — Descontinuação do NF-F7 como produto separado: fiscal passa a ser módulo do GastroFlow

- Status: Aceito
- Data: 2026-11-21

## Contexto

O NF-F7 (FocusEven Fiscal) nasceu como produto próprio, com um único cliente: um escritório
contábil, para quem o plano era emitir NFS-e de cobranças de condomínio, com vários emitentes
(CNPJs) numa instalação, importação de planilhas e contrato próprio. Em paralelo, desde
2026-11-16 (spec 084), o mesmo NF-F7 roda **dentro do compose do GastroFlow** para emitir a
NFC-e do restaurante, e o GastroFlow fala com ele por HTTP (`NF_F7_URL`, `NF_F7_API_KEY`,
`Services/Nff7Client`).

O contrato com o escritório contábil foi encerrado.

## Decisão

- O NF-F7 deixa de existir como produto separado, com clientes próprios. Ele continua só como
  o **módulo fiscal do GastroFlow**: o mesmo código e a mesma imagem privada, rodando no compose
  do GastroFlow pelo `compose.override.yml` local, como em `docs/fiscal-nff7.md`.
- O módulo continua **fechado**: privado, fora do GastroFlow Community público. O Community
  segue sem lógica de NFC-e, SEFAZ ou certificado fiscal, e fala com o módulo só por HTTP.
- **Sai de vez** tudo o que existia só para o escritório contábil: NFS-e, cobranças de
  condomínio, importação Condomob, vários emitentes para um contador, emissão em nome de
  terceiros, contrato, medição de uso e relatório mensal por emitente, hospedagem dedicada.

## Impacto

- GastroFlow: nenhuma mudança de código ou de instalação. A integração da spec 084 e o guia de
  instalação valem como estão. A impressão do cupom (não fiscal) não muda.
- NF-F7: o roadmap, as specs e os docs do repositório dele precisam do mesmo corte (o piloto de
  condomínio deixa de ser o objetivo); isso é trabalho do próprio NF-F7, numa ADR dele.
- O que o GastroFlow ainda não faz continua pendente: emitir a NFC-e a partir do pedido,
  forma de pagamento no pedido, status fiscal e DANFE (`docs/ROADMAP.md`, seção pós-v2).

## Fora de escopo

- NFS-e e qualquer documento fiscal além da NFC-e do restaurante.
- Vários emitentes por instalação como requisito (o NF-F7 já suporta; o GastroFlow usa um).
- Licenciamento ou venda do módulo fiscal para terceiros.
