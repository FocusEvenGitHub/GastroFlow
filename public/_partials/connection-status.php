<?php
/**
 * Indicador de conexão do Caixa e da Cozinha (spec 057). Incluído na nav, dentro do
 * x-data da página, que usa GF.ui() (public/assets/js/gf.js) — de lá vem `connection`.
 * O estado vai por texto, não só pela cor.
 */
?>
<span class="gf-conn" :class="connection" role="status" aria-live="polite">
    <i class="fas" :class="connection === 'reconnecting' ? 'fa-sync-alt fa-spin' : 'fa-circle'"></i>
    <span x-text="connection === 'connected' ? 'Conectado' : connection === 'reconnecting' ? 'Reconectando…' : 'Conexão perdida'"></span>
</span>
