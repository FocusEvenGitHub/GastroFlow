<?php
$pageTitle = 'Configurações';
$activePage = 'settings.php';
$pageScripts = ['/admin/settings.js'];
$extraHead = '<style>
        .logo-preview { max-width: 150px; max-height: 150px; border: 2px dashed #dee2e6; border-radius: 8px; padding: 4px; }
        .logo-preview img { width: 100%; height: auto; border-radius: 4px; }
    </style>';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<?php include __DIR__ . '/_partials/head.php'; ?>
</head>
<body class="gf-admin">
<div x-data="settingsApp()">
<?php include __DIR__ . '/_partials/shell-start.php'; ?>

    <div class="gf-page-header">
        <div>
            <h1>Configurações</h1>
            <p class="gf-subtitle">Dados do restaurante e impressora térmica.</p>
        </div>
    </div>

    <div class="row">
        <!-- Configurações Gerais -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-store me-2"></i>Restaurante</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Nome do Restaurante</label>
                        <input type="text" x-model="form.restaurant_name" class="form-control"
                               placeholder="Ex: GastroFlow">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo (PNG/JPG quadrado, 1:1)</label>
                        <div class="logo-preview mb-2">
                            <template x-if="logoUrl">
                                <img :src="logoUrl" alt="Logo">
                            </template>
                            <template x-if="!logoUrl">
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-image fa-2x"></i>
                                    <p class="small mt-1">Nenhuma logo</p>
                                </div>
                            </template>
                        </div>
                        <input type="file" accept="image/png,image/jpeg,image/webp"
                               class="form-control" @change="uploadLogo($event)">
                    </div>
                </div>
            </div>
        </div>

        <!-- Configurações de Impressão -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-print me-2"></i>Impressão Térmica</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">IP da Impressora</label>
                        <input type="text" x-model="form.printer_ip" class="form-control"
                               placeholder="Ex: 192.168.0.100">
                        <small class="text-muted">Endereço IP da Epson TM-T20 na rede</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Porta</label>
                        <input type="number" x-model="form.printer_port" class="form-control"
                               placeholder="9100">
                        <small class="text-muted">Porta padrão ESC/POS: 9100</small>
                    </div>
                    <div class="mt-3 p-3 bg-light rounded">
                        <h6><i class="fas fa-info-circle me-1"></i>Testar Impressão</h6>
                        <p class="small text-muted">Após configurar o IP, clique abaixo para imprimir um teste.</p>
                        <button class="btn btn-outline-primary btn-sm" @click="testPrint" :disabled="testing">
                            <span x-show="!testing"><i class="fas fa-print"></i> Imprimir Teste</span>
                            <span x-show="testing"><span class="spinner-border spinner-border-sm"></span> Imprimindo...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Botão Salvar -->
    <div class="row">
        <div class="col-12">
            <button class="btn btn-success btn-lg w-100" @click="saveSettings" :disabled="saving">
                <span x-show="!saving"><i class="fas fa-save me-2"></i>Salvar Configurações</span>
                <span x-show="saving"><span class="spinner-border spinner-border-sm me-2"></span> Salvando...</span>
            </button>
        </div>
    </div>

<?php include __DIR__ . '/_partials/shell-end.php'; ?>
</div>
</body>
</html>
