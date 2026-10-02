<?php
/**
 * Fecha o layout aberto por shell-start.php e adiciona os elementos comuns:
 * toasts e o modal de confirmação usado por askConfirm() (spec 051 — substitui
 * as caixas de confirmação nativas do navegador). Incluído DENTRO do elemento x-data da página.
 */
$gate = $gate ?? false;
?>
        </div><!-- /!forbidden -->
    </main>
</div><!-- /.gf-layout -->
<?php if ($gate) : ?>
</div><!-- /loggedIn -->
<?php endif; ?>

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

<div class="modal fade" id="gfConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"
     aria-labelledby="gfConfirmTitle"
     x-effect="const m = bootstrap.Modal.getOrCreateInstance($el); confirmDialog.open ? m.show() : m.hide()">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="gfConfirmTitle" x-text="confirmDialog.title"></h5>
                <button type="button" class="btn-close" @click="closeConfirm(false)" aria-label="Cancelar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" x-text="confirmDialog.message"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" @click="closeConfirm(false)">Cancelar</button>
                <button type="button" class="btn btn-danger" @click="closeConfirm(true)">
                    <i class="fas fa-trash me-1"></i><span x-text="confirmDialog.okLabel"></span>
                </button>
            </div>
        </div>
    </div>
</div>
