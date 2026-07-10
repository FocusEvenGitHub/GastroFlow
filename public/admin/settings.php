<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Configurações</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .logo-preview { max-width: 150px; max-height: 150px; border: 2px dashed #dee2e6; border-radius: 8px; padding: 4px; }
        .logo-preview img { width: 100%; height: auto; border-radius: 4px; }
    </style>
    <script>
        if (localStorage.getItem('gastroflow_darkMode') === 'true') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
</head>
<body>
<div x-data="settingsApp()">
    <!-- Navbar -->
    <nav class="gastro-nav">
        <a href="/cashier/" class="gastro-nav-brand">
            <i class="fas fa-utensils"></i>
            <span>GastroFlow</span>
        </a>
        <div class="gastro-nav-links">
            <a href="/cashier/"><i class="fas fa-cash-register"></i>Caixa</a>
            <a href="/kitchen/"><i class="fas fa-fire"></i>Cozinha</a>
            <a href="/admin/" class="active"><i class="fas fa-cog"></i>Admin</a>
        </div>
        <button class="dark-toggle" @click="toggleDarkMode()" title="Alternar tema">
            <i class="fas" :class="darkMode ? 'fa-sun' : 'fa-moon'"></i>
        </button>
    </nav>

    <!-- Toast container -->
    <div class="toast-container" x-show="toasts.length">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="gastro-toast" :class="toast.type">
                <i class="fas gastro-toast-icon"
                   :class="toast.type === 'success' ? 'fa-check-circle' : toast.type === 'danger' ? 'fa-exclamation-circle' : toast.type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'"></i>
                <span class="gastro-toast-text" x-text="toast.text"></span>
                <button class="gastro-toast-close" @click="toasts = toasts.filter(t => t.id !== toast.id)">&times;</button>
            </div>
        </template>
    </div>

    <div class="container py-4">
        <!-- Login -->
        <div x-show="!loggedIn" class="row justify-content-center pt-4">
            <div class="col-md-5">
                <div class="card shadow">
                    <div class="card-body">
                        <h3 class="card-title mb-4">Login Administrativo</h3>
                        <div x-show="loginError" class="alert alert-danger" x-text="loginError"></div>
                        <form @submit.prevent="doLogin">
                            <div class="mb-3">
                                <label class="form-label">Usuário</label>
                                <input type="text" x-model="loginForm.username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Senha</label>
                                <input type="password" x-model="loginForm.password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100" :disabled="logging">
                                <span x-show="!logging">Entrar</span>
                                <span x-show="logging"><span class="spinner-border spinner-border-sm me-1"></span> Entrando...</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Painel de Configurações -->
        <div x-show="loggedIn" x-transition>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">Configurações</h1>
                <div class="d-flex align-items-center gap-2">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Cardápio
                    </a>
                    <a href="logs.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-list"></i> Logs
                    </a>
                    <span>Olá, <strong x-text="username"></strong></span>
                    <button @click="logout" class="btn btn-outline-secondary btn-sm">Sair</button>
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
    </div> <!-- /loggedIn -->
    </div> <!-- /container -->
</div> <!-- /x-data -->

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="settings.js"></script>
</body>
</html>
