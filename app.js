/* ============================================================
   BipCash SaaS - Complete Frontend Application
   Multi-tenant: Super Admin + Pharmacy Panel + Public Page
   ============================================================ */

const MC = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
let csrfToken = '';
let currentUser = null;
let currentFarmacia = null;
let isSuperAdmin = false;
let isImpersonating = false;

// ===== API HELPER =====
async function api(ep, data = null) {
    const o = data ? {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({}, data, csrfToken ? { csrf_token: csrfToken } : {}))
    } : {};
    try {
        const r = await fetch('api/' + ep, o);
        if (r.headers.get('content-type')?.includes('text/csv')) {
            const b = await r.blob();
            const u = URL.createObjectURL(b);
            const a = document.createElement('a');
            a.href = u;
            a.download = r.headers.get('content-disposition')?.split('filename=')[1] || 'export.csv';
            a.click();
            return { sucesso: true };
        }
        const json = await r.json();
        if (json.csrf_token) csrfToken = json.csrf_token;
        return json;
    } catch (e) {
        return { sucesso: false, erro: 'Erro de conexao' };
    }
}

// ===== MASKS & FORMATTERS =====
function maskCPF(el) { let v = el.value.replace(/\D/g, '').slice(0, 11); if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d+)/, '$1.$2.$3-$4'); else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d+)/, '$1.$2.$3'); else if (v.length > 3) v = v.replace(/(\d{3})(\d+)/, '$1.$2'); el.value = v; }
function maskPhone(el) { let v = el.value.replace(/\D/g, '').slice(0, 11); if (v.length > 6) v = v.replace(/(\d{2})(\d{5})(\d+)/, '($1) $2-$3'); else if (v.length > 2) v = v.replace(/(\d{2})(\d+)/, '($1) $2'); el.value = v; }
function maskMoney(el) { el.value = el.value.replace(/[^\d,]/g, ''); }
function maskPublicBusca(el) { let v = el.value.replace(/\D/g, ''); if (v.length <= 11 && v.length > 2) maskPhone(el); }
function parseM(s) { return parseFloat((s || '').replace(/\./g, '').replace(',', '.')) || 0; }
function fM(n) { return (n || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
function fD(iso) { if (!iso) return '\u2014'; const d = new Date(iso); return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); }
function fDS(iso) { return iso ? new Date(iso).toLocaleDateString('pt-BR') : '\u2014'; }
function fC(c) { return (c || '').replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4'); }
function fP(t) { return (t || '').replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3'); }
function cl(s) { return (s || '').replace(/\D/g, ''); }

// ===== UI HELPERS =====
function toast(m, t = 'success') {
    const c = document.getElementById('toastContainer');
    const e = document.createElement('div');
    e.className = 'toast toast-' + t;
    e.textContent = m;
    c.appendChild(e);
    setTimeout(() => e.remove(), 3000);
}
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
function $(id) { return document.getElementById(id); }

// ===== HIDE LOADING SCREEN =====
function hideLoading() {
    const ls = $('loadingScreen');
    if (ls) { ls.classList.add('hide'); setTimeout(() => ls.style.display = 'none', 500); }
}

// ===== PANEL VISIBILITY =====
function hideAllPanels() {
    $('loginPage').style.display = 'none';
    $('publicPage').style.display = 'none';
    $('superAdminApp').style.display = 'none';
    $('farmaciaApp').style.display = 'none';
    $('impersonateBar').style.display = 'none';
}

function showSuperAdminPanel() {
    hideAllPanels();
    $('superAdminApp').style.display = 'flex';
    $('superUserName').textContent = currentUser?.nome || 'Admin';
    $('superGreeting').textContent = new Date().toLocaleDateString('pt-BR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    renderSuperDashboard();
}

function showFarmaciaPanel() {
    hideAllPanels();
    $('farmaciaApp').style.display = 'flex';
    if (isImpersonating) {
        $('impersonateBar').style.display = 'flex';
        $('impersonateNome').textContent = currentFarmacia?.nome || '';
    }
    setupPermissions();
    applyBranding(currentFarmacia);
    updateDashboard();
    updateDate();
}

function showLoginPage() {
    hideAllPanels();
    $('loginPage').style.display = 'flex';
}

function showPublicPage() {
    hideAllPanels();
    $('publicPage').style.display = 'block';
    // Try to get cashback percentage
    api('config.php?acao=listar').then(r => {
        if (r.cashback_atual) $('publicPct').textContent = r.cashback_atual + '%';
    }).catch(() => {});
}

// ===== LOGIN =====
async function doLogin() {
    const username = $('loginUser').value.trim();
    const senha = $('loginPassword').value;
    if (!username || !senha) { toast('Preencha usuario e senha', 'error'); return; }

    const r = await api('auth.php', { acao: 'login', username, senha });
    if (r.sucesso) {
        csrfToken = r.csrf_token || '';
        currentUser = r.usuario;
        sessionStorage.setItem('sb_logged', '1');

        if (r.tipo === 'super_admin') {
            isSuperAdmin = true;
            isImpersonating = false;
            currentFarmacia = null;
            showSuperAdminPanel();
        } else {
            isSuperAdmin = false;
            isImpersonating = false;
            currentFarmacia = r.farmacia || null;
            showFarmaciaPanel();
        }
    } else {
        const e = $('loginError');
        e.textContent = r.erro;
        e.style.display = 'block';
        setTimeout(() => e.style.display = 'none', 4000);
    }
}

function doLogout() {
    api('auth.php', { acao: 'logout' });
    csrfToken = '';
    currentUser = null;
    currentFarmacia = null;
    isSuperAdmin = false;
    isImpersonating = false;
    sessionStorage.removeItem('sb_logged');
    $('loginPassword').value = '';
    showLoginPage();
}

// ===== PERMISSIONS =====
function setupPermissions() {
    if (!currentUser) return;
    const isGerente = currentUser.role === 'gerente' || isSuperAdmin;
    $('farmaciaUserName').textContent = currentUser.nome;
    $('farmaciaUserRole').textContent = currentUser.role === 'gerente' ? 'Gerente' : (isSuperAdmin ? 'Super Admin' : 'Operador');
    document.querySelectorAll('.gerente-only').forEach(el => {
        if (isGerente) el.classList.remove('hidden-by-role');
        else el.classList.add('hidden-by-role');
    });
}

// ===== BRANDING =====
function applyBranding(farmacia) {
    if (!farmacia) return;
    $('farmaciaNome').textContent = farmacia.nome || 'BipCash';
    const logoEl = $('farmaciaLogo');
    if (farmacia.logo && farmacia.logo.length > 5) {
        logoEl.innerHTML = '<img src="' + farmacia.logo + '" alt="Logo" style="max-width:60px;border-radius:12px;margin:0 auto 8px">';
    } else {
        logoEl.innerHTML = '';
    }
    // Apply custom colors to CSS variables on the pharmacy sidebar
    if (farmacia.cor_primaria) {
        document.documentElement.style.setProperty('--primary', farmacia.cor_primaria);
        // Recalculate shadow
        document.documentElement.style.setProperty('--shadow-color', '0 4px 20px ' + farmacia.cor_primaria + '40');
    }
    if (farmacia.cor_secundaria) {
        // Apply to sidebar gradient
        const sidebar = $('farmaciaSidebar');
        if (sidebar) sidebar.style.background = 'linear-gradient(135deg, ' + farmacia.cor_secundaria + ' 0%, ' + farmacia.cor_primaria + '33 100%)';
    }
}

// ===== NAVIGATION =====
const PT = {
    dashboard: 'Painel', cadastro: 'Cadastrar Cliente', venda: 'Registrar Compra',
    consulta: 'Consultar Cliente', resgate: 'Resgatar Credito', clientes: 'Todos os Clientes',
    relatorios: 'Relatorios', config: 'Configuracoes', aniversariantes: 'Aniversarios do Mes',
    campanhas: 'Campanhas Promocionais', usuarios: 'Gerenciar Usuarios',
    'super-dashboard': 'Dashboard', 'super-farmacias': 'Farmacias', 'super-config': 'Configuracoes'
};

function goTo(p) {
    const isSuper = p.startsWith('super-');
    const container = isSuper ? $('superAdminApp') : $('farmaciaApp');
    if (!container) return;

    // Deactivate all pages within the container
    container.querySelectorAll('.page').forEach(x => x.classList.remove('active'));
    const target = $('page-' + p);
    if (target) target.classList.add('active');

    // Update nav items
    container.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    container.querySelector('[data-page="' + p + '"]')?.classList.add('active');

    // Update title
    const titleEl = isSuper ? $('superPageTitle') : $('pageTitle');
    if (titleEl) titleEl.textContent = PT[p] || '';

    closeSidebar();

    // Load data for pages
    if (p === 'dashboard') updateDashboard();
    if (p === 'clientes') renderClientes();
    if (p === 'config') renderConfig();
    if (p === 'relatorios') renderRelatorios();
    if (p === 'aniversariantes') renderAniversariantes();
    if (p === 'campanhas') renderCampanhas();
    if (p === 'usuarios') renderUsuarios();
    if (p === 'super-dashboard') renderSuperDashboard();
    if (p === 'super-farmacias') renderSuperFarmacias();
}

function toggleSidebar(type) {
    const id = type === 'super' ? 'superSidebar' : 'farmaciaSidebar';
    $(id).classList.toggle('open');
    $('mobileOverlay').classList.toggle('show');
}
function closeSidebar() {
    document.querySelectorAll('.sidebar').forEach(s => s.classList.remove('open'));
    $('mobileOverlay').classList.remove('show');
}
function updateDate() {
    const el = $('todayDate');
    if (el) el.textContent = new Date().toLocaleDateString('pt-BR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

// ============================================================
// SUPER ADMIN FUNCTIONS
// ============================================================

async function renderSuperDashboard() {
    const s = await api('super.php?acao=dashboard');
    if (s.erro) return;
    $('superStatsGrid').innerHTML =
        '<div class="stat-card"><div class="stat-icon stat-icon-primary">🏪</div><div class="stat-value">' + (s.total_farmacias || 0) + '</div><div class="stat-label">Farmacias</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-accent">👥</div><div class="stat-value">' + (s.total_clientes || 0) + '</div><div class="stat-label">Clientes</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-success">🛒</div><div class="stat-value">' + (s.total_compras || 0) + '</div><div class="stat-label">Compras</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-gold">💰</div><div class="stat-value">' + fM(s.total_vendas) + '</div><div class="stat-label">Total Vendas</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-secondary">🔐</div><div class="stat-value">' + (s.total_usuarios || 0) + '</div><div class="stat-label">Usuarios</div></div>';

    // Pharmacy summary table
    const fl = await api('super.php?acao=listar_farmacias');
    $('superFarmaciasResumo').innerHTML = (fl.farmacias || []).map(f =>
        '<tr><td><strong>' + f.nome + '</strong><br><small style="color:var(--text-muted)">' + (f.slug || '') + '</small></td>' +
        '<td>' + (f.total_clientes || 0) + '</td>' +
        '<td>' + fM(f.total_vendas) + '</td>' +
        '<td>' + (f.ativa ? '<span class="badge badge-green">Ativa</span>' : '<span class="badge badge-red">Inativa</span>') + '</td>' +
        '<td><button class="btn btn-primary btn-xs" onclick="impersonarFarmacia(' + f.id + ',\'' + f.nome.replace(/'/g, "\\'") + '\')">Acessar</button></td></tr>'
    ).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:32px">Nenhuma farmacia</td></tr>';
}

async function renderSuperFarmacias() {
    const fl = await api('super.php?acao=listar_farmacias');
    $('superFarmaciasList').innerHTML = (fl.farmacias || []).map(f =>
        '<div class="pharmacy-card">' +
        '<div class="pharmacy-info">' +
        '<h4>' + f.nome + ' ' + (f.ativa ? '<span class="badge badge-green">Ativa</span>' : '<span class="badge badge-red">Inativa</span>') + '</h4>' +
        '<p>Slug: ' + (f.slug || '-') + '</p>' +
        '<div class="pharmacy-stats">' +
        '<span>👥 ' + (f.total_clientes || 0) + ' clientes</span>' +
        '<span>🛒 ' + (f.total_compras || 0) + ' compras</span>' +
        '<span>💰 ' + fM(f.total_vendas) + '</span>' +
        '<span>🔐 ' + (f.total_usuarios || 0) + ' usuarios</span>' +
        '</div></div>' +
        '<div class="pharmacy-actions">' +
        '<button class="btn btn-primary btn-sm" onclick="impersonarFarmacia(' + f.id + ',\'' + f.nome.replace(/'/g, "\\'") + '\')">Acessar</button>' +
        '<button class="btn btn-secondary btn-sm" onclick="toggleFarmaciaStatus(' + f.id + ',' + f.ativa + ',\'' + f.nome.replace(/'/g, "\\'") + '\')">' + (f.ativa ? 'Desativar' : 'Ativar') + '</button>' +
        '</div></div>'
    ).join('') || '<p style="text-align:center;color:var(--text-muted);padding:32px">Nenhuma farmacia cadastrada</p>';
}

async function criarFarmacia() {
    const nome = $('novaFarmNome').value.trim();
    const slug = $('novaFarmSlug').value.trim();
    const cor1 = $('novaFarmCor1').value;
    const cor2 = $('novaFarmCor2').value;
    const admin = $('novaFarmAdmin').value.trim();
    const senha = $('novaFarmSenha').value;

    if (!nome) { toast('Nome obrigatorio', 'error'); return; }

    const r = await api('super.php', {
        acao: 'criar_farmacia', nome, slug,
        cor_primaria: cor1, cor_secundaria: cor2,
        username_admin: admin || 'admin', senha_admin: senha || 'admin123'
    });
    closeModal('modalNovaFarmacia');
    if (r.sucesso) {
        toast(r.mensagem);
        ['novaFarmNome', 'novaFarmSlug', 'novaFarmAdmin', 'novaFarmSenha'].forEach(x => $(x).value = '');
        renderSuperFarmacias();
    } else {
        toast(r.erro, 'error');
    }
}

async function toggleFarmaciaStatus(id, atualAtiva, nome) {
    const novaAtiva = !atualAtiva;
    const r = await api('super.php', { acao: 'editar_farmacia', id, nome, ativa: novaAtiva });
    if (r.sucesso) { toast(r.mensagem); renderSuperFarmacias(); }
    else toast(r.erro, 'error');
}

async function impersonarFarmacia(id, nome) {
    const r = await api('auth.php', { acao: 'impersonar', farmacia_id: id });
    if (r.sucesso) {
        isImpersonating = true;
        currentFarmacia = r.farmacia;
        // Set user role so gerente-only items show for super admin
        currentUser = Object.assign({}, currentUser, { role: 'gerente' });
        showFarmaciaPanel();
        toast('Visualizando: ' + nome);
    } else {
        toast(r.erro, 'error');
    }
}

async function sairImpersonacao() {
    const r = await api('auth.php', { acao: 'sair_impersonacao' });
    if (r.sucesso) {
        isImpersonating = false;
        currentFarmacia = null;
        currentUser = { nome: currentUser?.nome || 'Admin', role: 'super_admin' };
        // Reset branding
        document.documentElement.style.removeProperty('--primary');
        document.documentElement.style.removeProperty('--shadow-color');
        const sidebar = $('farmaciaSidebar');
        if (sidebar) sidebar.style.background = '';
        showSuperAdminPanel();
        toast('Voltou ao Super Admin');
    }
}

async function alterarSenhaSuperAdmin() {
    const a = $('superSenhaAtual').value;
    const s1 = $('superSenha1').value;
    const s2 = $('superSenha2').value;
    if (!a) { toast('Digite a senha atual', 'error'); return; }
    if (!s1 || s1.length < 6) { toast('Minimo 6 caracteres', 'error'); return; }
    if (s1 !== s2) { toast('Senhas nao coincidem', 'error'); return; }
    const r = await api('auth.php', { acao: 'alterar_senha', senha_atual: a, nova_senha: s1 });
    if (r.sucesso) { toast('Senha alterada!'); ['superSenhaAtual', 'superSenha1', 'superSenha2'].forEach(x => $(x).value = ''); }
    else toast(r.erro, 'error');
}

// ============================================================
// PHARMACY PANEL - DASHBOARD
// ============================================================

async function updateDashboard() {
    const s = await api('compras.php?acao=dashboard');
    if (s.erro) return;
    $('statsGrid').innerHTML =
        '<div class="stat-card"><div class="stat-icon stat-icon-primary">👥</div><div class="stat-value">' + s.total_clientes + '</div><div class="stat-label">Clientes</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-accent">🛒</div><div class="stat-value">' + s.total_compras + '</div><div class="stat-label">Compras</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-gold">💰</div><div class="stat-value">' + fM(s.total_vendas) + '</div><div class="stat-label">Total Vendas</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-success">📅</div><div class="stat-value">' + fM(s.vendas_mes) + '</div><div class="stat-label">Vendas Mes</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-secondary">🏷️</div><div class="stat-value">' + s.cashback_atual + '%</div><div class="stat-label">Cashback Mes</div></div>' +
        '<div class="stat-card"><div class="stat-icon stat-icon-danger">🎁</div><div class="stat-value">' + fM(s.total_cashback_gerado) + '</div><div class="stat-label">Cashback Gerado</div></div>';

    // Recent purchases
    const u = await api('compras.php?acao=ultimas&limite=10');
    $('recentPurchases').innerHTML = (u.compras || []).map(c =>
        '<tr><td><strong>' + c.nome + '</strong></td><td>' + fP(c.telefone) + '</td><td><span class="badge badge-blue">' + fM(+c.valor) + '</span></td><td>' + fM(+c.cashback_valor) + ' (' + c.cashback_percentual + '%)</td><td>' + fD(c.data_compra) + '</td></tr>'
    ).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:32px">Nenhuma compra</td></tr>';

    // Campaign banner
    const camp = await api('campanhas.php?acao=ativas');
    const campDiv = $('dashboardCampanha');
    if (campDiv && camp.campanhas && camp.campanhas.length > 0) {
        const c = camp.campanhas[0];
        campDiv.innerHTML = '<h3>📣 ' + c.nome + '</h3><p>Cashback com <strong>+' + c.bonus_percentual + '% bonus</strong> ate ' + fDS(c.data_fim) + '</p>';
        campDiv.style.display = 'block';
    } else if (campDiv) campDiv.style.display = 'none';

    // Expiring credits
    const exp = await api('notificacoes.php?acao=resumo_expiracoes');
    const expDiv = $('dashboardExpirando');
    if (expDiv && exp.total_clientes > 0) {
        expDiv.innerHTML = '<h3>Creditos Expirando</h3><p><strong>' + exp.total_clientes + '</strong> cliente(s) com creditos expirando nos proximos 7 dias (total: ' + fM(+exp.total_valor) + ')</p>';
        expDiv.style.display = 'block';
    } else if (expDiv) expDiv.style.display = 'none';

    // Birthday widget
    const aniv = await api('clientes.php?acao=aniversariantes&mes=' + (new Date().getMonth() + 1));
    const anivDiv = $('dashboardAniv');
    if (anivDiv && aniv.aniversariantes && aniv.aniversariantes.length > 0) {
        anivDiv.innerHTML = '<div class="card-header"><h3 class="card-title">🎂 Aniversariantes do Mes</h3></div><p>' + aniv.aniversariantes.length + ' cliente(s) fazem aniversario este mes</p><button class="btn btn-secondary btn-sm" onclick="goTo(\'aniversariantes\')" style="margin-top:8px">Ver Todos</button>';
        anivDiv.style.display = 'block';
        anivDiv.style.padding = '24px';
    } else if (anivDiv) anivDiv.style.display = 'none';
}

// ============================================================
// CADASTRO
// ============================================================

async function cadastrarCliente() {
    const r = await api('clientes.php', {
        acao: 'cadastrar',
        nome: $('cadNome').value.trim(),
        cpf: $('cadCPF').value,
        telefone: $('cadTel').value,
        data_nascimento: $('cadNascimento').value || null
    });
    if (r.sucesso) {
        toast(r.mensagem);
        ['cadNome', 'cadCPF', 'cadTel', 'cadNascimento'].forEach(x => $(x).value = '');
    } else toast(r.erro, 'error');
}

// ============================================================
// VENDA (REGISTRAR COMPRA)
// ============================================================

let vendaCl = null;

async function buscarVenda() {
    const r = await api('clientes.php', { acao: 'buscar', termo: cl($('vendaTel').value) });
    if (!r.sucesso) { toast(r.erro, 'error'); $('vendaInfo').classList.remove('show'); vendaCl = null; return; }
    vendaCl = r.cliente;
    $('vendaData').innerHTML =
        '<div class="customer-info-item"><label>Nome</label><span>' + r.cliente.nome + '</span></div>' +
        '<div class="customer-info-item"><label>CPF</label><span>' + fC(r.cliente.cpf) + '</span></div>' +
        '<div class="customer-info-item"><label>Telefone</label><span>' + fP(r.cliente.telefone) + '</span></div>' +
        '<div class="customer-info-item"><label>Total</label><span>' + fM(r.cliente.total_compras) + '</span></div>' +
        '<div class="customer-info-item"><label>Credito</label><span style="color:var(--gold)">' + fM(r.cliente.credito_disponivel) + '</span></div>';
    $('vendaInfo').classList.add('show');
    $('vendaValor').value = '';
    $('vendaValor').focus();
}

async function confirmarCompra() {
    if (!vendaCl) return;
    const v = parseM($('vendaValor').value);
    if (v < 0.01) { toast('Valor invalido!', 'error'); return; }
    const p = await api('compras.php', { acao: 'preview', valor: v });
    if (!p.sucesso) { toast(p.erro, 'error'); return; }
    let campanhaHtml = p.campanha ? '<div style="text-align:center;margin-top:8px;font-size:13px;color:var(--gold)">📣 Campanha: <strong>' + p.campanha + '</strong> (+' + p.bonus + '% bonus)</div>' : '';
    $('modalCompraBody').innerHTML =
        '<p>Cliente: <strong>' + vendaCl.nome + '</strong></p>' +
        '<div class="preview-box"><div class="pv-lbl">Valor da Compra</div><div class="pv-val">' + fM(v) + '</div></div>' +
        '<div class="preview-box" style="background:rgba(253,160,133,0.1);border-color:rgba(253,160,133,0.3)"><div class="pv-lbl">Cashback que sera gerado</div><div class="pv-val" style="color:#c27a4a">' + fM(p.cashback_valor) + ' (' + p.cashback_percentual + '%)</div></div>' + campanhaHtml;
    openModal('modalConfirmarCompra');
}

async function executarCompra() {
    closeModal('modalConfirmarCompra');
    const v = parseM($('vendaValor').value);
    const r = await api('compras.php', { acao: 'registrar', telefone: vendaCl.telefone, valor: v });
    if (r.sucesso) {
        toast('Compra de ' + fM(v) + ' registrada! Cashback: ' + fM(r.compra.cashback_valor));
        $('vendaTel').value = '';
        $('vendaValor').value = '';
        $('vendaInfo').classList.remove('show');
        vendaCl = null;
    } else toast(r.erro, 'error');
}

// ============================================================
// CONSULTA
// ============================================================

let consultaCl = null;

async function consultarCliente() {
    const r = await api('clientes.php', { acao: 'buscar', termo: cl($('consultaBusca').value) });
    if (!r.sucesso) { toast(r.erro, 'error'); $('consultaResult').classList.remove('show'); return; }
    consultaCl = r.cliente;
    const c = r.cliente;
    $('consultaData').innerHTML =
        '<div class="customer-info-item"><label>Nome</label><span>' + c.nome + '</span></div>' +
        '<div class="customer-info-item"><label>CPF</label><span>' + fC(c.cpf) + '</span></div>' +
        '<div class="customer-info-item"><label>Telefone</label><span>' + fP(c.telefone) + '</span></div>' +
        '<div class="customer-info-item"><label>Desde</label><span>' + fDS(c.data_cadastro) + '</span></div>';

    const exp = c.expirado ? '<br><span style="color:var(--danger);font-size:11px">CREDITOS EXPIRADOS</span>' : '';
    $('consultaPoints').innerHTML =
        '<div class="points-circle"><span class="pts-value">' + fM(c.credito_disponivel).replace('R$', '').trim() + '</span><span class="pts-label">credito</span></div>' +
        '<div><div style="font-size:14px;color:var(--text-secondary)">Total em Compras</div><div style="font-size:24px;font-weight:700;color:var(--text-primary)">' + fM(c.total_compras) + '</div>' +
        '<div style="font-size:13px;color:var(--text-muted);margin-top:4px">Cashback: ' + fM(c.cashback_total) + ' | Resgatado: ' + fM(c.total_resgatado) + exp + '</div></div>';

    // Purchase history
    const h = await api('compras.php?acao=historico&cliente_id=' + c.id);
    let rows = (h.compras || []).map(x => {
        const est = x.estornada ? 'estornada' : '';
        return '<tr class="' + est + '"><td>' + fD(x.data_compra) + '</td><td>' + fM(x.valor) + '</td><td><span class="badge badge-blue">' + fM(x.cashback_valor) + ' (' + x.cashback_percentual + '%)</span></td><td>' + (x.estornada ? '<span class="badge badge-red">Estornada</span>' : '<span class="badge badge-green">OK</span>') + '</td><td>' + (x.estornada ? '\u2014' : '<button class="btn btn-danger btn-xs" onclick="estornarCompra(' + x.id + ',' + x.valor + ')">Estornar</button>') + '</td></tr>';
    }).join('');
    if (h.compras?.length) rows += '<tr class="total-row"><td><strong>TOTAL</strong></td><td><strong>' + fM(h.total_valor) + '</strong></td><td><strong>' + fM(h.total_cashback) + '</strong></td><td></td><td></td></tr>';
    $('consultaHistorico').innerHTML = rows || '<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">Nenhuma compra</td></tr>';

    // Redemption history
    const rg = await api('resgates.php?acao=historico&cliente_id=' + c.id);
    $('consultaResgateList').innerHTML = (rg.resgates || []).map(x =>
        '<tr><td>' + fD(x.data_resgate) + '</td><td><span class="badge badge-gold">' + fM(+x.valor) + '</span></td></tr>'
    ).join('') || '<tr><td colspan="2" style="text-align:center;color:var(--text-muted)">Nenhum resgate</td></tr>';

    $('consultaResult').classList.add('show');
}

// ============================================================
// ESTORNO
// ============================================================

let estornoId = 0, estornoVal = 0;

function estornarCompra(id, val) {
    estornoId = id;
    estornoVal = val;
    $('modalEstornoText').innerHTML = 'Deseja estornar a compra de <strong>' + fM(val) + '</strong>? O cashback correspondente sera removido.';
    $('estornoMotivo').value = '';
    openModal('modalEstorno');
}

async function confirmarEstorno() {
    const r = await api('compras.php', { acao: 'estornar', compra_id: estornoId, motivo: $('estornoMotivo').value || 'Estorno administrativo' });
    closeModal('modalEstorno');
    if (r.sucesso) { toast('Compra estornada!'); consultarCliente(); }
    else toast(r.erro, 'error');
}

// ============================================================
// RESGATE
// ============================================================

let resgateCl = null, resgateValP = 0;

async function buscarResgate() {
    const r = await api('clientes.php', { acao: 'buscar', termo: cl($('resgateTel').value) });
    if (!r.sucesso) { toast(r.erro, 'error'); $('resgateInfo').classList.remove('show'); return; }
    resgateCl = r.cliente;
    $('resgateData').innerHTML =
        '<div class="customer-info-item"><label>Nome</label><span>' + r.cliente.nome + '</span></div>' +
        '<div class="customer-info-item"><label>CPF</label><span>' + fC(r.cliente.cpf) + '</span></div>' +
        '<div class="customer-info-item"><label>Telefone</label><span>' + fP(r.cliente.telefone) + '</span></div>';
    $('resgatePoints').innerHTML =
        '<div class="points-circle"><span class="pts-value">' + fM(r.cliente.credito_disponivel).replace('R$', '').trim() + '</span><span class="pts-label">disponivel</span></div>' +
        '<div><div style="font-size:14px;color:var(--text-secondary)">Credito Disponivel</div><div style="font-size:24px;font-weight:700;color:var(--gold)">' + fM(r.cliente.credito_disponivel) + '</div></div>';
    $('resgateInfo').classList.add('show');
    $('resgateValor').value = '';
}

function realizarResgate() {
    if (!resgateCl) return;
    resgateValP = parseM($('resgateValor').value);
    if (resgateValP <= 0) { toast('Valor invalido!', 'error'); return; }
    if (resgateValP > resgateCl.credito_disponivel) { toast('Valor maior que o credito!', 'error'); return; }
    $('modalResgateText').innerHTML = 'Confirma resgate de <strong>' + fM(resgateValP) + '</strong> para <strong>' + resgateCl.nome + '</strong>?';
    openModal('modalResgate');
}

async function confirmarResgate() {
    const r = await api('resgates.php', { acao: 'resgatar', cliente_id: resgateCl.id, valor: resgateValP });
    closeModal('modalResgate');
    if (r.sucesso) {
        toast('Resgate de ' + fM(resgateValP) + ' realizado!');
        $('resgateTel').value = '';
        $('resgateValor').value = '';
        $('resgateInfo').classList.remove('show');
        resgateCl = null;
    } else toast(r.erro, 'error');
}

// ============================================================
// CLIENTES
// ============================================================

let filtroTO;

async function renderClientes(b = '') {
    const r = await api('clientes.php?acao=listar&busca=' + encodeURIComponent(b));
    $('totalBadge').textContent = (r.total || 0) + ' clientes';
    $('clientesList').innerHTML = (r.clientes || []).map(c =>
        '<tr><td><strong>' + c.nome + '</strong></td><td>' + fC(c.cpf) + '</td><td>' + fP(c.telefone) + '</td><td>' + fM(c.total_compras) + '</td><td><span class="badge badge-gold">' + fM(c.credito_disponivel) + '</span></td><td>' +
        '<button class="btn btn-secondary btn-xs" onclick="consultarDireto(\'' + c.telefone + '\')" style="margin:2px">Ver</button>' +
        '<button class="btn btn-secondary btn-xs" onclick="editarCliente(' + c.id + ',\'' + c.nome.replace(/'/g, "\\'") + '\',\'' + c.cpf + '\',\'' + c.telefone + '\',\'' + (c.data_nascimento || '') + '\')" style="margin:2px">Editar</button>' +
        '<button class="btn btn-danger btn-xs" onclick="excluirCliente(' + c.id + ',\'' + c.nome.replace(/'/g, "\\'") + '\')" style="margin:2px">Excluir</button></td></tr>'
    ).join('') || '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:32px">Nenhum cliente</td></tr>';
}

function filtrarClientes() {
    clearTimeout(filtroTO);
    filtroTO = setTimeout(() => renderClientes($('clientesSearch').value), 300);
}

function consultarDireto(t) {
    goTo('consulta');
    $('consultaBusca').value = t;
    consultarCliente();
}

function exportarCSV(tipo) { window.open('api/compras.php?acao=exportar_' + tipo, '_blank'); }

// ============================================================
// EDITAR / EXCLUIR CLIENTE
// ============================================================

let editId = 0;

function editarCliente(id, nome, cpf, tel, nasc) {
    editId = id;
    $('editNome').value = nome;
    $('editCPF').value = fC(cpf);
    $('editTel').value = fP(tel);
    $('editNascimento').value = nasc || '';
    openModal('modalEditar');
}

async function salvarEdicao() {
    const r = await api('clientes.php', {
        acao: 'editar', id: editId,
        nome: $('editNome').value.trim(),
        cpf: $('editCPF').value,
        telefone: $('editTel').value,
        data_nascimento: $('editNascimento').value || null
    });
    closeModal('modalEditar');
    if (r.sucesso) { toast(r.mensagem); renderClientes(); }
    else toast(r.erro, 'error');
}

let excluirId = 0;

function excluirCliente(id, nome) {
    excluirId = id;
    $('modalExcluirText').innerHTML = 'Excluir <strong>' + nome + '</strong>?';
    openModal('modalExcluir');
}

async function confirmarExcluir() {
    await api('clientes.php', { acao: 'excluir', id: excluirId });
    closeModal('modalExcluir');
    toast('Excluido.');
    renderClientes();
}

// ============================================================
// AUTOCOMPLETE / BUSCA RAPIDA
// ============================================================

let buscarRapidoTO;

function buscarRapido(q, targetId) {
    clearTimeout(buscarRapidoTO);
    const el = $(targetId);
    if (q.length < 2) { el.classList.remove('show'); el.innerHTML = ''; return; }
    buscarRapidoTO = setTimeout(async () => {
        const r = await api('clientes.php?acao=buscar_rapido&q=' + encodeURIComponent(q));
        if (!r.resultados || r.resultados.length === 0) { el.classList.remove('show'); el.innerHTML = ''; return; }
        el.innerHTML = r.resultados.map(c =>
            '<div class="autocomplete-item" onclick="selecionarSugestao(\'' + targetId + '\',\'' + c.telefone + '\',\'' + c.nome.replace(/'/g, "\\'") + '\')"><div><span class="ac-name">' + c.nome + '</span></div><span class="ac-info">' + fP(c.telefone) + '</span></div>'
        ).join('');
        el.classList.add('show');
    }, 250);
}

function selecionarSugestao(targetId, telefone, nome) {
    $(targetId).classList.remove('show');
    if (targetId === 'vendaSugestoes') { $('vendaTel').value = fP(telefone); buscarVenda(); }
    else if (targetId === 'consultaSugestoes') { $('consultaBusca').value = telefone; consultarCliente(); }
    else if (targetId === 'resgateSugestoes') { $('resgateTel').value = fP(telefone); buscarResgate(); }
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.search-bar')) {
        document.querySelectorAll('.autocomplete-list').forEach(x => { x.classList.remove('show'); x.innerHTML = ''; });
    }
});

// ============================================================
// ANIVERSARIANTES
// ============================================================

let anivMes = new Date().getMonth() + 1;

function mesAniversario(delta) {
    anivMes += delta;
    if (anivMes < 1) anivMes = 12;
    if (anivMes > 12) anivMes = 1;
    renderAniversariantes();
}

async function renderAniversariantes() {
    $('anivMesLabel').textContent = MC[anivMes - 1];
    const r = await api('clientes.php?acao=aniversariantes&mes=' + anivMes);
    $('anivList').innerHTML = (r.aniversariantes || []).map(c =>
        '<tr><td><strong>' + c.dia + '</strong></td><td>' + c.nome + '</td><td>' + fP(c.telefone) + '</td><td>' + fDS(c.data_nascimento) + '</td><td><button class="btn btn-gold btn-xs" onclick="enviarParabens(' + c.id + ')">Parabens</button></td></tr>'
    ).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">Nenhum aniversariante</td></tr>';
}

async function enviarParabens(id) {
    const r = await api('clientes.php', { acao: 'enviar_parabens', cliente_id: id });
    if (r.sucesso) toast(r.mensagem);
    else toast(r.erro, 'error');
}

// ============================================================
// CAMPANHAS
// ============================================================

async function renderCampanhas() {
    const r = await api('campanhas.php', { acao: 'listar' });
    const hoje = new Date().toISOString().slice(0, 10);
    $('campanhasList').innerHTML = (r.campanhas || []).map(c => {
        const ativa = c.ativa && c.data_inicio <= hoje && c.data_fim >= hoje;
        const statusBadge = c.ativa ? (ativa ? '<span class="badge badge-green">Ativa</span>' : '<span class="badge badge-blue">Agendada</span>') : '<span class="badge badge-red">Inativa</span>';
        return '<tr><td><strong>' + c.nome + '</strong>' + (c.descricao ? '<br><small style="color:var(--text-muted)">' + c.descricao + '</small>' : '') + '</td><td>' + fDS(c.data_inicio) + ' - ' + fDS(c.data_fim) + '</td><td><span class="badge badge-gold">+' + c.bonus_percentual + '%</span></td><td>' + statusBadge + '</td><td><button class="btn btn-secondary btn-xs" onclick="toggleCampanha(' + c.id + ')" style="margin:2px">' + (c.ativa ? 'Desativar' : 'Ativar') + '</button></td></tr>';
    }).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">Nenhuma campanha</td></tr>';
}

async function criarCampanha() {
    const r = await api('campanhas.php', {
        acao: 'criar',
        nome: $('campNome').value.trim(),
        data_inicio: $('campInicio').value,
        data_fim: $('campFim').value,
        bonus_percentual: parseFloat($('campBonus').value) || 0,
        descricao: $('campDesc').value.trim()
    });
    closeModal('modalNovaCampanha');
    if (r.sucesso) {
        toast(r.mensagem);
        ['campNome', 'campInicio', 'campFim', 'campBonus', 'campDesc'].forEach(x => $(x).value = '');
        renderCampanhas();
    } else toast(r.erro, 'error');
}

async function toggleCampanha(id) {
    const r = await api('campanhas.php', { acao: 'toggle', id });
    if (r.sucesso) { toast(r.mensagem); renderCampanhas(); }
    else toast(r.erro, 'error');
}

// ============================================================
// RELATORIOS (Chart.js)
// ============================================================

let chartVendasInst, chartCashbackInst, chartClientesInst;

async function renderRelatorios() {
    const ano = new Date().getFullYear();
    $('chartAno').textContent = ano;
    const labels = MC;

    // Sales chart
    const r = await api('compras.php?acao=relatorio_mensal&ano=' + ano);
    const vendas = (r.meses || []).map(m => m.total_vendas);
    if (chartVendasInst) chartVendasInst.destroy();
    chartVendasInst = new Chart($('chartVendas'), {
        type: 'bar', data: { labels, datasets: [{ label: 'Vendas (R$)', data: vendas, backgroundColor: 'rgba(102,126,234,0.7)', borderRadius: 8, borderSkipped: false }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: v => fM(v) } }, x: { grid: { display: false } } } }
    });

    // Cashback vs redemption chart
    const rc = await api('compras.php?acao=relatorio_cashback_resgate&ano=' + ano);
    const cashbackData = (rc.meses || []).map(m => +m.cashback);
    const resgateData = (rc.meses || []).map(m => +m.resgatado);
    if (chartCashbackInst) chartCashbackInst.destroy();
    chartCashbackInst = new Chart($('chartCashback'), {
        type: 'bar', data: { labels, datasets: [{ label: 'Gerado', data: cashbackData, backgroundColor: 'rgba(17,153,142,0.7)', borderRadius: 4, borderSkipped: false }, { label: 'Resgatado', data: resgateData, backgroundColor: 'rgba(253,160,133,0.7)', borderRadius: 4, borderSkipped: false }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { callback: v => fM(v) } }, x: { grid: { display: false } } } }
    });

    // New clients chart
    const nc = await api('compras.php?acao=novos_clientes_mes&ano=' + ano);
    const novosData = (nc.meses || []).map(m => +m.novos);
    if (chartClientesInst) chartClientesInst.destroy();
    chartClientesInst = new Chart($('chartClientes'), {
        type: 'bar', data: { labels, datasets: [{ label: 'Novos Clientes', data: novosData, backgroundColor: 'rgba(79,172,254,0.6)', borderRadius: 4, borderSkipped: false }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { ticks: { stepSize: 1 } }, x: { grid: { display: false } } } }
    });

    // Ranking
    const rk = await api('compras.php?acao=ranking_clientes&limite=20');
    $('rankingList').innerHTML = (rk.ranking || []).map((c, i) =>
        '<tr><td><strong>' + (i + 1) + 'o</strong></td><td>' + c.nome + '</td><td>' + fP(c.telefone) + '</td><td>' + c.num_compras + '</td><td><span class="badge badge-blue">' + fM(+c.total_compras) + '</span></td></tr>'
    ).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">Nenhum dado</td></tr>';
}

// ============================================================
// CONFIGURACOES
// ============================================================

async function renderConfig() {
    const ano = new Date().getFullYear();
    $('configAno').textContent = ano;
    const r = await api('config.php?acao=listar&ano=' + ano);
    const ma = new Date().getMonth();
    $('configGrid').innerHTML = (r.meses || []).map((m, i) =>
        '<div class="config-month' + (i === ma ? ' active' : '') + '"><label>' + MC[i] + '</label><input type="number" id="cb_' + i + '" value="' + m.percentual + '" min="0" max="100" step="0.5"><div class="suffix">%</div></div>'
    ).join('');

    // Load branding fields if farmacia is set
    if (currentFarmacia) {
        $('cfgFarmaciaNome').value = currentFarmacia.nome || '';
        $('cfgCorPrimaria').value = currentFarmacia.cor_primaria || '#667eea';
        $('cfgCorSecundaria').value = currentFarmacia.cor_secundaria || '#0c0c1d';
        $('cfgLogo').value = currentFarmacia.logo || '';
    }
}

async function salvarConfig() {
    const ano = new Date().getFullYear();
    const ms = [];
    for (let i = 0; i < 12; i++) ms.push(parseFloat($('cb_' + i).value) || 0);
    const r = await api('config.php', { acao: 'salvar', ano, meses: ms });
    if (r.sucesso) {
        $('configStatus').textContent = 'Salvo!';
        setTimeout(() => $('configStatus').textContent = '', 3000);
        toast('Salvo!');
    } else toast(r.erro, 'error');
}

async function salvarBranding() {
    if (!currentFarmacia) { toast('Farmacia nao identificada', 'error'); return; }
    const r = await api('super.php', {
        acao: 'editar_farmacia',
        id: currentFarmacia.id,
        nome: $('cfgFarmaciaNome').value.trim(),
        cor_primaria: $('cfgCorPrimaria').value,
        cor_secundaria: $('cfgCorSecundaria').value,
        logo_base64: $('cfgLogo').value.trim(),
        ativa: true
    });
    if (r.sucesso) {
        toast('Branding salvo!');
        currentFarmacia.nome = $('cfgFarmaciaNome').value.trim();
        currentFarmacia.cor_primaria = $('cfgCorPrimaria').value;
        currentFarmacia.cor_secundaria = $('cfgCorSecundaria').value;
        currentFarmacia.logo = $('cfgLogo').value.trim();
        applyBranding(currentFarmacia);
    } else toast(r.erro, 'error');
}

async function salvarWhatsapp() {
    if (!currentFarmacia) { toast('Farmacia nao identificada', 'error'); return; }
    const r = await api('super.php', {
        acao: 'editar_farmacia',
        id: currentFarmacia.id,
        nome: currentFarmacia.nome,
        whatsapp_url: $('cfgWhatsappUrl').value.trim(),
        whatsapp_instance: $('cfgWhatsappInstance').value.trim(),
        whatsapp_token: $('cfgWhatsappToken').value.trim(),
        whatsapp_enabled: $('cfgWhatsappEnabled').checked,
        ativa: true
    });
    if (r.sucesso) toast('WhatsApp salvo!');
    else toast(r.erro, 'error');
}

async function alterarSenha() {
    const a = $('cfgSenhaAtual').value, s1 = $('cfgSenha1').value, s2 = $('cfgSenha2').value;
    if (!a) { toast('Digite a senha atual!', 'error'); return; }
    if (!s1 || s1.length < 6) { toast('Minimo 6 caracteres!', 'error'); return; }
    if (s1 !== s2) { toast('Senhas nao coincidem!', 'error'); return; }
    const r = await api('auth.php', { acao: 'alterar_senha', senha_atual: a, nova_senha: s1 });
    if (r.sucesso) { toast('Senha alterada!'); ['cfgSenhaAtual', 'cfgSenha1', 'cfgSenha2'].forEach(x => $(x).value = ''); }
    else toast(r.erro, 'error');
}

// ============================================================
// USUARIOS
// ============================================================

async function renderUsuarios() {
    const r = await api('auth.php', { acao: 'listar_usuarios' });
    $('usuariosList').innerHTML = (r.usuarios || []).map(u =>
        '<tr><td><strong>' + u.nome + '</strong></td><td>' + u.username + '</td><td><span class="role-badge role-' + u.role + '">' + u.role + '</span></td><td>' + (u.ativo ? '<span class="badge badge-green">Ativo</span>' : '<span class="badge badge-red">Inativo</span>') + '</td><td>' + (u.ultimo_login ? fD(u.ultimo_login) : '\u2014') + '</td><td>' +
        '<button class="btn btn-secondary btn-xs" onclick="editarUsuarioPrompt(' + u.id + ',\'' + u.nome.replace(/'/g, "\\'") + '\',\'' + u.role + '\',' + u.ativo + ')" style="margin:2px">Editar</button>' +
        '<button class="btn btn-danger btn-xs" onclick="resetarSenhaPrompt(' + u.id + ',\'' + u.nome.replace(/'/g, "\\'") + '\')" style="margin:2px">Reset Senha</button></td></tr>'
    ).join('');
}

async function criarUsuario() {
    const r = await api('auth.php', {
        acao: 'criar_usuario',
        nome: $('novoUserNome').value.trim(),
        username: $('novoUserUsername').value.trim(),
        senha: $('novoUserSenha').value,
        role: $('novoUserRole').value
    });
    closeModal('modalNovoUsuario');
    if (r.sucesso) {
        toast(r.mensagem);
        ['novoUserNome', 'novoUserUsername', 'novoUserSenha'].forEach(x => $(x).value = '');
        renderUsuarios();
    } else toast(r.erro, 'error');
}

function editarUsuarioPrompt(id, nome, role, ativo) {
    const novoNome = prompt('Nome:', nome); if (!novoNome) return;
    const novoRole = prompt('Funcao (operador/gerente):', role); if (!novoRole) return;
    const novoAtivo = confirm('Usuario ativo?');
    api('auth.php', { acao: 'editar_usuario', id, nome: novoNome, role: novoRole, ativo: novoAtivo }).then(r => {
        if (r.sucesso) { toast(r.mensagem); renderUsuarios(); } else toast(r.erro, 'error');
    });
}

function resetarSenhaPrompt(id, nome) {
    const senha = prompt('Nova senha para ' + nome + ':'); if (!senha) return;
    api('auth.php', { acao: 'resetar_senha_usuario', id, nova_senha: senha }).then(r => {
        if (r.sucesso) toast(r.mensagem); else toast(r.erro, 'error');
    });
}

// ============================================================
// PUBLIC PAGE
// ============================================================

async function consultarSaldoPublico() {
    const termo = cl($('publicBusca').value);
    if (termo.length < 10) { toast('Informe telefone ou CPF completo', 'error'); return; }
    $('publicSaldoResult').style.display = 'none';
    $('publicSaldoErro').style.display = 'none';

    const r = await fetch('api/publico.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: 'consultar_saldo', termo })
    }).then(x => x.json()).catch(() => ({ sucesso: false, erro: 'Erro de conexao' }));

    if (!r.sucesso) {
        const el = $('publicSaldoErro');
        el.textContent = r.erro;
        el.style.display = 'block';
        return;
    }
    $('publicSaldoNome').textContent = r.cliente.nome;
    $('publicSaldoValor').textContent = fM(r.saldo.credito_disponivel);
    $('publicNumCompras').textContent = r.saldo.num_compras;
    $('publicTotalCompras').textContent = fM(r.saldo.total_compras);
    $('publicResgatado').textContent = fM(r.saldo.total_resgatado);

    // Recent purchases
    let hist = '';
    if (r.ultimas_compras && r.ultimas_compras.length > 0) {
        hist = '<div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin:16px 0 8px">Ultimas compras:</div>';
        hist += r.ultimas_compras.map(c =>
            '<div style="display:flex;justify-content:space-between;padding:8px 12px;background:rgba(102,126,234,0.04);border-radius:8px;margin-bottom:4px;font-size:13px"><span>' + fDS(c.data) + '</span><span>' + fM(c.valor) + '</span><span style="color:var(--gold);font-weight:600">+' + fM(c.cashback) + '</span></div>'
        ).join('');
    }
    $('publicUltimasCompras').innerHTML = hist;

    // Expiration warning
    if (r.proxima_expiracao) {
        const expDiv = $('publicExpiracao');
        if (expDiv) { expDiv.querySelector('#publicExpData').textContent = fDS(r.proxima_expiracao); expDiv.style.display = 'block'; }
    }
    // Campaign banners
    if (r.campanhas_ativas && r.campanhas_ativas.length > 0) {
        const cb = $('publicCampanhasBanner');
        if (cb) {
            cb.innerHTML = r.campanhas_ativas.map(c =>
                '<div class="campaign-banner" style="margin-top:16px;margin-bottom:0"><h3>📣 ' + c.nome + '</h3><p>Cashback com +' + c.bonus_percentual + '% bonus ate ' + fDS(c.data_fim) + '</p></div>'
            ).join('');
            cb.style.display = 'block';
        }
    }
    $('publicSaldoResult').style.display = 'block';
    if (r.cashback_atual) $('publicPct').textContent = r.cashback_atual + '%';
}

async function autoCadastro() {
    const nome = $('pubNome').value.trim();
    const cpf = $('pubCPF').value;
    const telefone = $('pubTel').value;
    const msg = $('pubCadastroMsg');

    const r = await fetch('api/publico.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: 'autocadastro', nome, cpf, telefone, data_nascimento: $('pubNascimento').value || null })
    }).then(x => x.json()).catch(() => ({ sucesso: false, erro: 'Erro de conexao' }));

    msg.style.display = 'block';
    if (r.sucesso) {
        msg.className = 'alert alert-success';
        msg.innerHTML = '<strong>' + r.mensagem + '</strong><br>Cashback atual: ' + r.cashback_atual + '%';
        ['pubNome', 'pubCPF', 'pubTel', 'pubNascimento'].forEach(x => $(x).value = '');
    } else {
        msg.className = 'alert alert-danger';
        msg.textContent = r.erro;
    }
    setTimeout(() => msg.style.display = 'none', 6000);
}

// ============================================================
// SESSION CHECK ON LOAD
// ============================================================

(async function () {
    if (sessionStorage.getItem('sb_logged') === '1') {
        const r = await api('auth.php', { acao: 'verificar' });
        if (r.logado) {
            csrfToken = r.csrf_token || '';
            currentUser = r.usuario;

            if (r.tipo === 'super_admin') {
                isSuperAdmin = true;
                if (r.farmacia && r.impersonando) {
                    // Super admin was impersonating a pharmacy
                    isImpersonating = true;
                    currentFarmacia = r.farmacia;
                    currentUser = Object.assign({}, currentUser, { role: 'gerente' });
                    hideLoading();
                    showFarmaciaPanel();
                } else {
                    // Regular super admin
                    currentFarmacia = null;
                    isImpersonating = false;
                    hideLoading();
                    showSuperAdminPanel();
                }
            } else {
                // Pharmacy user
                isSuperAdmin = false;
                isImpersonating = false;
                currentFarmacia = r.farmacia || null;
                hideLoading();
                showFarmaciaPanel();
            }
        } else {
            sessionStorage.removeItem('sb_logged');
            hideLoading();
            showLoginPage();
        }
    } else {
        hideLoading();
        // Check for public page hash
        if (window.location.hash === '#regras' || window.location.hash.startsWith('#regras')) {
            showPublicPage();
        } else {
            showLoginPage();
        }
    }
})();
