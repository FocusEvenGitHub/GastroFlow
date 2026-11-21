# ADR 0001 — NF-F7 deixa de ser produto separado: fiscal passa a ser módulo do GastroFlow

- Status: Aceito
- Data: 2026-11-21

## Contexto

Desde 2026-11-16 (spec 084), o NF-F7 roda dentro do compose do GastroFlow para a área fiscal,
e o GastroFlow fala com ele por HTTP (`NF_F7_URL`, `NF_F7_API_KEY`, `Services/Nff7Client`). Até
aqui o NF-F7 era tratado como um produto separado, com uso e planos próprios fora do GastroFlow.

## Decisão

- O NF-F7 deixa de ser um produto separado. Ele continua só como o **módulo fiscal do
  GastroFlow**: o mesmo código e a mesma imagem privada, rodando no compose do GastroFlow pelo
  `compose.override.yml` local, como em `docs/fiscal-nff7.md`.
- O módulo continua **fechado**: privado, fora do GastroFlow Community público. O Community
  segue sem lógica de NFC-e, SEFAZ ou certificado fiscal, e fala com o módulo só por HTTP.
- O escopo do módulo é a **NFC-e do restaurante**. Qualquer outro uso planejado para o NF-F7
  fora do GastroFlow sai de escopo.

## Impacto

- GastroFlow: nenhuma mudança de código ou de instalação. A integração da spec 084 e o guia de
  instalação valem como estão. A impressão do cupom (não fiscal) não muda.
- O que o GastroFlow ainda não faz continua pendente: emitir a NFC-e a partir do pedido, forma
  de pagamento no pedido, status fiscal e DANFE (`docs/ROADMAP.md`, seção pós-v2).

## Fora de escopo

- Qualquer documento fiscal além da NFC-e do restaurante.
- Uso do módulo fiscal fora do GastroFlow.
