<?php
/**
 * Indicador de conexão do Caixa e da Cozinha (spec 057). Incluído na nav, dentro do
 * x-data da página, que usa GF.ui() (public/assets/js/gf.js) — de lá vem `connection`.
 * O estado vai por texto, não só pela cor. 'outdated' (spec 069): servidor no ar, atualização
 * incompleta — texto não técnico, para quem está no caixa e na cozinha.
 */
?>
<span class="gf-conn" :class="connection" role="status" aria-live="polite">
    <i class="fas" :class="connection === 'reconnecting' ? 'fa-sync-alt fa-spin' : connection === 'outdated' ? 'fa-exclamation-triangle' : 'fa-circle'"></i>
    <span x-text="connection === 'connected' ? 'Conectado' : connection === 'reconnecting' ? 'Reconectando…' : connection === 'outdated' ? 'Atualização incompleta' : 'Conexão perdida'"></span>
</span>
