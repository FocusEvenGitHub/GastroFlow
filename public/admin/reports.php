<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Relatórios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .stat-card { border-radius: 0.75rem; border-left: 5px solid; transition: all 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
        .stat-card .stat-icon { font-size: 1.8rem; opacity: 0.2; position: absolute; right: 1rem; bottom: 0.5rem; }
        .stat-card .stat-value { font-size: 1.8rem; font-weight: 700; }
        .stat-card .stat-label { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }
        .chart-container { position: relative; height: 300px; width: 100%; }
    </style>
    <script>
        if (localStorage.getItem('gastroflow_darkMode') === 'true') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
</head>
<body>
<div x-data="reportsApp()">
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

        <!-- Reports -->
        <div x-show="loggedIn" x-transition>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0"><i class="fas fa-chart-bar me-2"></i>Relatórios de Vendas</h1>
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

            <!-- Filtro de período -->
            <div class="card shadow-sm mb-4">
                <div class="card-body d-flex align-items-end gap-3 flex-wrap">
                    <div>
                        <label class="form-label small mb-1">Data inicial</label>
                        <input type="date" x-model="dateFrom" class="form-control form-control-sm" style="width:160px">
                    </div>
                    <div>
                        <label class="form-label small mb-1">Data final</label>
                        <input type="date" x-model="dateTo" class="form-control form-control-sm" style="width:160px">
                    </div>
                    <button class="btn btn-primary btn-sm" @click="loadData" :disabled="loading">
                        <i class="fas fa-filter me-1"></i> Filtrar
                    </button>
                    <button class="btn btn-outline-primary btn-sm" @click="setCurrentMonth">
                        <i class="fas fa-calendar-alt me-1"></i> Mês atual
                    </button>
                </div>
            </div>

            <!-- Loading -->
            <div x-show="loading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2 text-muted small">Carregando relatórios...</p>
            </div>

            <div x-show="!loading">
                <!-- Cards de resumo -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card position-relative" style="border-left-color: var(--primary);">
                            <div class="card-body">
                                <div class="stat-value" x-text="summary.orders">0</div>
                                <div class="stat-label">Pedidos</div>
                                <i class="fas fa-shopping-cart stat-icon"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card position-relative" style="border-left-color: var(--success);">
                            <div class="card-body">
                                <div class="stat-value" x-text="'R$ ' + summary.revenue.toFixed(2)">0,00</div>
                                <div class="stat-label">Faturamento</div>
                                <i class="fas fa-dollar-sign stat-icon"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card position-relative" style="border-left-color: var(--info);">
                            <div class="card-body">
                                <div class="stat-value" x-text="'R$ ' + summary.avg_ticket.toFixed(2)">0,00</div>
                                <div class="stat-label">Ticket Médio</div>
                                <i class="fas fa-receipt stat-icon"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card position-relative" style="border-left-color: var(--warning);">
                            <div class="card-body">
                                <div class="stat-value" x-text="summary.items_sold">0</div>
                                <div class="stat-label">Itens Vendidos</div>
                                <i class="fas fa-utensils stat-icon"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráfico Chart.js -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Vendas por Dia</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas x-ref="salesChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Itens mais vendidos -->
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-star me-2"></i>Itens Mais Vendidos</h5>
                            </div>
                            <div class="card-body p-0">
                                <div x-show="topItems.length === 0" class="text-center text-muted py-4">
                                    Nenhum item vendido no período.
                                </div>
                                <table x-show="topItems.length > 0" class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Item</th>
                                            <th class="text-center">Qtd</th>
                                            <th class="text-end">Receita</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(item, idx) in topItems" :key="idx">
                                            <tr>
                                                <td class="text-muted" x-text="idx + 1"></td>
                                                <td x-text="item.name"></td>
                                                <td class="text-center" x-text="item.total_qty"></td>
                                                <td class="text-end" x-text="'R$ ' + item.total_revenue.toFixed(2)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Distribuição por opção de refeição -->
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-box me-2"></i>Opção de Refeição</h5>
                            </div>
                            <div class="card-body p-0">
                                <div x-show="diningOptions.length === 0" class="text-center text-muted py-4">
                                    Nenhum dado no período.
                                </div>
                                <table x-show="diningOptions.length > 0" class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Opção</th>
                                            <th class="text-center">Qtd Itens</th>
                                            <th class="text-end">Custo Embalagem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="opt in diningOptions" :key="opt.dining_option">
                                            <tr>
                                                <td>
                                                    <template x-if="opt.dining_option === 'local'">
                                                        <span><i class="fas fa-store me-1"></i>Local</span>
                                                    </template>
                                                    <template x-if="opt.dining_option === 'viagem_simples'">
                                                        <span><i class="fas fa-shopping-bag me-1"></i>Viagem Simples</span>
                                                    </template>
                                                    <template x-if="opt.dining_option === 'viagem_vip'">
                                                        <span><i class="fas fa-gift me-1"></i>Viagem VIP</span>
                                                    </template>
                                                    <template x-if="!['local','viagem_simples','viagem_vip'].includes(opt.dining_option)">
                                                        <span x-text="opt.dining_option"></span>
                                                    </template>
                                                </td>
                                                <td class="text-center" x-text="opt.total_qty"></td>
                                                <td class="text-end" x-text="'R$ ' + opt.total_packaging.toFixed(2)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> <!-- /loggedIn -->
    </div> <!-- /container -->
</div> <!-- /x-data -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="reports.js"></script>
</body>
</html>
