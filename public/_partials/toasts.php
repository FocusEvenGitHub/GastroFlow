<?php
/**
 * Toasts das três telas (spec 056). Incluído dentro do x-data da página, que usa
 * GF.ui() (public/assets/js/gf.js) — de lá vêm `toasts` e showMessage().
 * x-text, nunca x-html: a mensagem pode vir do servidor.
 */
?>
<div class="toast-container" x-show="toasts.length" aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div class="gastro-toast" :class="toast.type">
            <i class="fas gastro-toast-icon"
               :class="toast.type === 'success' ? 'fa-check-circle' : toast.type === 'danger' ? 'fa-exclamation-circle' : toast.type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'"></i>
            <span class="gastro-toast-text" x-text="toast.text"></span>
            <button class="gastro-toast-close" @click="toasts = toasts.filter(t => t.id !== toast.id)" aria-label="Fechar">&times;</button>
        </div>
    </template>
</div>
