
const MC=['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
let csrfToken = '';
let currentUser = null;

async function api(ep, data=null) {
    const o = data ? {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(Object.assign({}, data, csrfToken ? {csrf_token: csrfToken} : {}))
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
            return {sucesso: true};
        }
        const json = await r.json();
        // Atualizar CSRF token se retornado
        if (json.csrf_token) csrfToken = json.csrf_token;
        return json;
    } catch(e) {
        return {sucesso: false, erro: 'Erro de conexao'};
    }
}

function maskCPF(el){let v=el.value.replace(/\D/g,'').slice(0,11);if(v.length>9)v=v.replace(/(\d{3})(\d{3})(\d{3})(\d+)/,'$1.$2.$3-$4');else if(v.length>6)v=v.replace(/(\d{3})(\d{3})(\d+)/,'$1.$2.$3');else if(v.length>3)v=v.replace(/(\d{3})(\d+)/,'$1.$2');el.value=v;}
function maskPhone(el){let v=el.value.replace(/\D/g,'').slice(0,11);if(v.length>6)v=v.replace(/(\d{2})(\d{5})(\d+)/,'($1) $2-$3');else if(v.length>2)v=v.replace(/(\d{2})(\d+)/,'($1) $2');el.value=v;}
function maskMoney(el){el.value=el.value.replace(/[^\d,]/g,'');}
function parseM(s){return parseFloat((s||'').replace(/\./g,'').replace(',','.'))||0;}
function fM(n){return(n||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});}
function fD(iso){if(!iso)return'\u2014';const d=new Date(iso);return d.toLocaleDateString('pt-BR')+' '+d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});}
function fDS(iso){return iso?new Date(iso).toLocaleDateString('pt-BR'):'\u2014';}
function fC(c){return(c||'').replace(/(\d{3})(\d{3})(\d{3})(\d{2})/,'$1.$2.$3-$4');}
function fP(t){return(t||'').replace(/(\d{2})(\d{5})(\d{4})/,'($1) $2-$3');}
function cl(s){return(s||'').replace(/\D/g,'');}
function toast(m,t='success'){const c=document.getElementById('toastContainer'),e=document.createElement('div');e.className='toast toast-'+t;e.textContent=m;c.appendChild(e);setTimeout(()=>e.remove(),3000);}
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}

async function doLogin(){
    const r=await api('auth.php',{acao:'login',username:document.getElementById('loginUser').value.trim(),senha:document.getElementById('loginPassword').value});
    if(r.sucesso){
        csrfToken = r.csrf_token || '';
        currentUser = r.usuario;
        document.getElementById('loginPage').style.display='none';
        document.getElementById('app').style.display='block';
        sessionStorage.setItem('sb_logged','1');
        setupPermissions();
        updateDashboard();
        updateDate();
    } else {
        const e=document.getElementById('loginError');
        e.textContent=r.erro;
        e.style.display='block';
        setTimeout(()=>e.style.display='none',4000);
    }
}
function setupPermissions(){
    if(!currentUser)return;
    const isGerente = currentUser.role === 'gerente';
    document.getElementById('userGreeting').textContent = currentUser.nome;
    document.querySelectorAll('[data-page="campanhas"],[data-page="usuarios"]').forEach(el => {
        el.style.display = isGerente ? '' : 'none';
    });
    document.querySelectorAll('.gerente-only').forEach(el => {
        el.style.display = isGerente ? '' : 'none';
    });
}
function doLogout(){api('auth.php',{acao:'logout'});csrfToken='';sessionStorage.removeItem('sb_logged');document.getElementById('app').style.display='none';document.getElementById('loginPage').style.display='flex';document.getElementById('loginPassword').value='';}
function showPublicPage(){document.getElementById('loginPage').style.display='none';document.getElementById('app').style.display='none';document.getElementById('publicPage').style.display='block';api('config.php?acao=listar').then(r=>{if(r.cashback_atual)document.getElementById('publicPct').textContent=r.cashback_atual+'%';});}
function showLoginPage(){document.getElementById('publicPage').style.display='none';document.getElementById('app').style.display='none';document.getElementById('loginPage').style.display='flex';}

const PT={dashboard:'Painel',cadastro:'Cadastrar Cliente',venda:'Registrar Compra',consulta:'Consultar Cliente',resgate:'Resgatar Cr\u00e9dito',clientes:'Todos os Clientes',relatorios:'Relat\u00f3rios',config:'Configura\u00e7\u00f5es',aniversariantes:'Anivers\u00e1rios do M\u00eas',campanhas:'Campanhas Promocionais',usuarios:'Gerenciar Usu\u00e1rios'};
function goTo(p){document.querySelectorAll('.page').forEach(x=>x.classList.remove('active'));document.getElementById('page-'+p).classList.add('active');document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));document.querySelector(`[data-page="${p}"]`)?.classList.add('active');document.getElementById('pageTitle').textContent=PT[p]||'';closeSidebar();if(p==='dashboard')updateDashboard();if(p==='clientes')renderClientes();if(p==='config')renderConfig();if(p==='relatorios')renderRelatorios();if(p==='aniversariantes')renderAniversariantes();if(p==='campanhas')renderCampanhas();if(p==='usuarios')renderUsuarios();}
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('mobileOverlay').classList.toggle('show');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('mobileOverlay').classList.remove('show');}
function updateDate(){document.getElementById('todayDate').textContent=new Date().toLocaleDateString('pt-BR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});}

async function updateDashboard(){
  const s=await api('compras.php?acao=dashboard');if(s.erro)return;
  document.getElementById('statsGrid').innerHTML=`
    <div class="stat-card"><div class="stat-icon" style="background:var(--blue-100);color:var(--blue-600)">\ud83d\udc65</div><div class="stat-value">${s.total_clientes}</div><div class="stat-label">Clientes</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:var(--blue-100);color:var(--blue-600)">\ud83d\uded2</div><div class="stat-value">${s.total_compras}</div><div class="stat-label">Compras</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:var(--gold-200);color:var(--gold-500)">\ud83d\udcb0</div><div class="stat-value">${fM(s.total_vendas)}</div><div class="stat-label">Total Vendas</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:var(--blue-100);color:var(--blue-600)">\ud83d\udcc5</div><div class="stat-value">${fM(s.vendas_mes)}</div><div class="stat-label">Vendas M\u00eas</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:var(--green-100);color:var(--green-500)">\ud83c\udff7\ufe0f</div><div class="stat-value">${s.cashback_atual}%</div><div class="stat-label">Cashback M\u00eas</div></div>
    <div class="stat-card"><div class="stat-icon" style="background:var(--gold-200);color:var(--gold-500)">\ud83c\udf81</div><div class="stat-value">${fM(s.total_cashback_gerado)}</div><div class="stat-label">Cashback Gerado</div></div>`;
  const u=await api('compras.php?acao=ultimas&limite=10');
  document.getElementById('recentPurchases').innerHTML=(u.compras||[]).map(c=>
    `<tr><td><strong>${c.nome}</strong></td><td>${fP(c.telefone)}</td><td><span class="badge badge-blue">${fM(+c.valor)}</span></td><td>${fM(+c.cashback_valor)} (${c.cashback_percentual}%)</td><td>${fD(c.data_compra)}</td></tr>`
  ).join('')||'<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:32px">Nenhuma compra</td></tr>';

  // Campaign banner
  const camp=await api('campanhas.php?acao=ativas');
  const campDiv=document.getElementById('dashboardCampanha');
  if(campDiv&&camp.campanhas&&camp.campanhas.length>0){
    const c=camp.campanhas[0];
    campDiv.innerHTML=`<h3>📣 ${c.nome}</h3><p>Cashback com <strong>+${c.bonus_percentual}% bonus</strong> ate ${fDS(c.data_fim)}</p>`;
    campDiv.style.display='block';
  } else if(campDiv) campDiv.style.display='none';

  // Expiring credits
  const exp=await api('notificacoes.php?acao=resumo_expiracoes');
  const expDiv=document.getElementById('dashboardExpirando');
  if(expDiv&&exp.total_clientes>0){
    expDiv.innerHTML=`<h3>⚠️ Creditos Expirando</h3><p><strong>${exp.total_clientes}</strong> cliente(s) com creditos expirando nos proximos 7 dias (total: ${fM(+exp.total_valor)})</p><button class="btn btn-gold btn-sm" onclick="goTo('expirando')" style="margin-top:8px">Ver Detalhes</button>`;
    expDiv.style.display='block';
  } else if(expDiv) expDiv.style.display='none';

  // Birthday widget
  const aniv=await api('clientes.php?acao=aniversariantes&mes='+(new Date().getMonth()+1));
  const anivDiv=document.getElementById('dashboardAniv');
  if(anivDiv&&aniv.aniversariantes&&aniv.aniversariantes.length>0){
    anivDiv.innerHTML=`<h3>🎂 Aniversariantes do Mes</h3><p><strong>${aniv.aniversariantes.length}</strong> cliente(s) fazem aniversario este mes</p><button class="btn btn-secondary btn-sm" onclick="goTo('aniversariantes')" style="margin-top:8px">Ver Todos</button>`;
    anivDiv.style.display='block';
  } else if(anivDiv) anivDiv.style.display='none';
}

async function cadastrarCliente(){const r=await api('clientes.php',{acao:'cadastrar',nome:document.getElementById('cadNome').value.trim(),cpf:document.getElementById('cadCPF').value,telefone:document.getElementById('cadTel').value,data_nascimento:document.getElementById('cadNascimento').value||null});if(r.sucesso){toast(r.mensagem);['cadNome','cadCPF','cadTel','cadNascimento'].forEach(x=>document.getElementById(x).value='');}else toast(r.erro,'error');}

let vendaCl=null;
async function buscarVenda(){const r=await api('clientes.php',{acao:'buscar',termo:cl(document.getElementById('vendaTel').value)});if(!r.sucesso){toast(r.erro,'error');document.getElementById('vendaInfo').classList.remove('show');vendaCl=null;return;}vendaCl=r.cliente;document.getElementById('vendaData').innerHTML=`<div class="customer-info-item"><label>Nome</label><span>${r.cliente.nome}</span></div><div class="customer-info-item"><label>CPF</label><span>${fC(r.cliente.cpf)}</span></div><div class="customer-info-item"><label>Telefone</label><span>${fP(r.cliente.telefone)}</span></div><div class="customer-info-item"><label>Total</label><span>${fM(r.cliente.total_compras)}</span></div><div class="customer-info-item"><label>Cr\u00e9dito</label><span style="color:var(--gold-500)">${fM(r.cliente.credito_disponivel)}</span></div>`;document.getElementById('vendaInfo').classList.add('show');document.getElementById('vendaValor').value='';document.getElementById('vendaValor').focus();}

async function confirmarCompra(){if(!vendaCl)return;const v=parseM(document.getElementById('vendaValor').value);if(v<0.01){toast('Valor inv\u00e1lido!','error');return;}const p=await api('compras.php',{acao:'preview',valor:v});if(!p.sucesso){toast(p.erro,'error');return;}let campanhaHtml=p.campanha?`<div style="text-align:center;margin-top:8px;font-size:13px;color:var(--gold-500)">📣 Campanha: <strong>${p.campanha}</strong> (+${p.bonus}% bonus)</div>`:'';document.getElementById('modalCompraBody').innerHTML=`<p>Cliente: <strong>${vendaCl.nome}</strong></p><div class="preview-box"><div class="pv-lbl">Valor da Compra</div><div class="pv-val">${fM(v)}</div></div><div class="preview-box" style="background:var(--gold-200);border-color:var(--gold-500)"><div class="pv-lbl">Cashback que ser\u00e1 gerado</div><div class="pv-val" style="color:var(--gold-500)">${fM(p.cashback_valor)} (${p.cashback_percentual}%)</div></div>${campanhaHtml}`;openModal('modalConfirmarCompra');}

async function executarCompra(){closeModal('modalConfirmarCompra');const v=parseM(document.getElementById('vendaValor').value);const r=await api('compras.php',{acao:'registrar',telefone:vendaCl.telefone,valor:v});if(r.sucesso){toast(`Compra de ${fM(v)} registrada! Cashback: ${fM(r.compra.cashback_valor)}`);document.getElementById('vendaTel').value='';document.getElementById('vendaValor').value='';document.getElementById('vendaInfo').classList.remove('show');vendaCl=null;}else toast(r.erro,'error');}

let consultaCl=null;
async function consultarCliente(){const r=await api('clientes.php',{acao:'buscar',termo:cl(document.getElementById('consultaBusca').value)});if(!r.sucesso){toast(r.erro,'error');document.getElementById('consultaResult').classList.remove('show');return;}consultaCl=r.cliente;const c=r.cliente;document.getElementById('consultaData').innerHTML=`<div class="customer-info-item"><label>Nome</label><span>${c.nome}</span></div><div class="customer-info-item"><label>CPF</label><span>${fC(c.cpf)}</span></div><div class="customer-info-item"><label>Telefone</label><span>${fP(c.telefone)}</span></div><div class="customer-info-item"><label>Desde</label><span>${fDS(c.data_cadastro)}</span></div>`;const exp=c.expirado?'<br><span style="color:var(--red-500);font-size:11px">CR\u00c9DITOS EXPIRADOS</span>':'';document.getElementById('consultaPoints').innerHTML=`<div class="points-circle"><span class="pts-value">${fM(c.credito_disponivel).replace('R$','').trim()}</span><span class="pts-label">cr\u00e9dito</span></div><div><div style="font-size:14px;color:var(--text-secondary)">Total em Compras</div><div style="font-size:24px;font-weight:700;color:var(--blue-900)">${fM(c.total_compras)}</div><div style="font-size:13px;color:var(--text-muted);margin-top:4px">Cashback: ${fM(c.cashback_total)} | Resgatado: ${fM(c.total_resgatado)}${exp}</div></div>`;
  const h=await api('compras.php?acao=historico&cliente_id='+c.id);let rows=(h.compras||[]).map(x=>{const est=x.estornada?'estornada':'';return`<tr class="${est}"><td>${fD(x.data_compra)}</td><td>${fM(x.valor)}</td><td><span class="badge badge-blue">${fM(x.cashback_valor)} (${x.cashback_percentual}%)</span></td><td>${x.estornada?'<span class="badge badge-red">Estornada</span>':'<span class="badge badge-green">OK</span>'}</td><td>${x.estornada?'\u2014':`<button class="btn btn-danger btn-xs" onclick="estornarCompra(${x.id},${x.valor})">Estornar</button>`}</td></tr>`;}).join('');if(h.compras?.length)rows+=`<tr class="total-row"><td><strong>TOTAL</strong></td><td><strong>${fM(h.total_valor)}</strong></td><td><strong>${fM(h.total_cashback)}</strong></td><td></td><td></td></tr>`;document.getElementById('consultaHistorico').innerHTML=rows||'<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">Nenhuma compra</td></tr>';
  const rg=await api('resgates.php?acao=historico&cliente_id='+c.id);document.getElementById('consultaResgateList').innerHTML=(rg.resgates||[]).map(x=>`<tr><td>${fD(x.data_resgate)}</td><td><span class="badge badge-gold">${fM(+x.valor)}</span></td></tr>`).join('')||'<tr><td colspan="2" style="text-align:center;color:var(--text-muted)">Nenhum resgate</td></tr>';document.getElementById('consultaResult').classList.add('show');}

let estornoId=0,estornoVal=0;
function estornarCompra(id,val){estornoId=id;estornoVal=val;document.getElementById('modalEstornoText').innerHTML=`Deseja estornar a compra de <strong>${fM(val)}</strong>? O cashback correspondente ser\u00e1 removido.`;document.getElementById('estornoMotivo').value='';openModal('modalEstorno');}
async function confirmarEstorno(){const r=await api('compras.php',{acao:'estornar',compra_id:estornoId,motivo:document.getElementById('estornoMotivo').value||'Estorno administrativo'});closeModal('modalEstorno');if(r.sucesso){toast('Compra estornada!');consultarCliente();}else toast(r.erro,'error');}

let resgateCl=null,resgateValP=0;
async function buscarResgate(){const r=await api('clientes.php',{acao:'buscar',termo:cl(document.getElementById('resgateTel').value)});if(!r.sucesso){toast(r.erro,'error');document.getElementById('resgateInfo').classList.remove('show');return;}resgateCl=r.cliente;document.getElementById('resgateData').innerHTML=`<div class="customer-info-item"><label>Nome</label><span>${r.cliente.nome}</span></div><div class="customer-info-item"><label>CPF</label><span>${fC(r.cliente.cpf)}</span></div><div class="customer-info-item"><label>Telefone</label><span>${fP(r.cliente.telefone)}</span></div>`;document.getElementById('resgatePoints').innerHTML=`<div class="points-circle"><span class="pts-value">${fM(r.cliente.credito_disponivel).replace('R$','').trim()}</span><span class="pts-label">dispon\u00edvel</span></div><div><div style="font-size:14px;color:var(--text-secondary)">Cr\u00e9dito Dispon\u00edvel</div><div style="font-size:24px;font-weight:700;color:var(--gold-500)">${fM(r.cliente.credito_disponivel)}</div></div>`;document.getElementById('resgateInfo').classList.add('show');document.getElementById('resgateValor').value='';}
function realizarResgate(){if(!resgateCl)return;resgateValP=parseM(document.getElementById('resgateValor').value);if(resgateValP<=0){toast('Valor inv\u00e1lido!','error');return;}if(resgateValP>resgateCl.credito_disponivel){toast('Valor maior que o cr\u00e9dito!','error');return;}document.getElementById('modalResgateText').innerHTML=`Confirma resgate de <strong>${fM(resgateValP)}</strong> para <strong>${resgateCl.nome}</strong>?`;openModal('modalResgate');}
async function confirmarResgate(){const r=await api('resgates.php',{acao:'resgatar',cliente_id:resgateCl.id,valor:resgateValP});closeModal('modalResgate');if(r.sucesso){toast(`Resgate de ${fM(resgateValP)} realizado!`);document.getElementById('resgateTel').value='';document.getElementById('resgateValor').value='';document.getElementById('resgateInfo').classList.remove('show');resgateCl=null;}else toast(r.erro,'error');}

let filtroTO;
async function renderClientes(b=''){const r=await api('clientes.php?acao=listar&busca='+encodeURIComponent(b));document.getElementById('totalBadge').textContent=(r.total||0)+' clientes';document.getElementById('clientesList').innerHTML=(r.clientes||[]).map(c=>`<tr><td><strong>${c.nome}</strong></td><td>${fC(c.cpf)}</td><td>${fP(c.telefone)}</td><td>${fM(c.total_compras)}</td><td><span class="badge badge-gold">${fM(c.credito_disponivel)}</span></td><td><button class="btn btn-secondary btn-xs" onclick="consultarDireto('${c.telefone}')" style="margin:2px">Ver</button><button class="btn btn-secondary btn-xs" onclick="editarCliente(${c.id},'${c.nome}','${c.cpf}','${c.telefone}','${c.data_nascimento||''}')" style="margin:2px">Editar</button><button class="btn btn-danger btn-xs" onclick="excluirCliente(${c.id},'${c.nome}')" style="margin:2px">Excluir</button></td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:32px">Nenhum cliente</td></tr>';}
function filtrarClientes(){clearTimeout(filtroTO);filtroTO=setTimeout(()=>renderClientes(document.getElementById('clientesSearch').value),300);}
function consultarDireto(t){goTo('consulta');document.getElementById('consultaBusca').value=t;consultarCliente();}
function exportarCSV(tipo){window.open('api/compras.php?acao=exportar_'+tipo,'_blank');}

let editId=0;
function editarCliente(id,nome,cpf,tel,nasc){editId=id;document.getElementById('editNome').value=nome;document.getElementById('editCPF').value=fC(cpf);document.getElementById('editTel').value=fP(tel);document.getElementById('editNascimento').value=nasc||'';openModal('modalEditar');}
async function salvarEdicao(){const r=await api('clientes.php',{acao:'editar',id:editId,nome:document.getElementById('editNome').value.trim(),cpf:document.getElementById('editCPF').value,telefone:document.getElementById('editTel').value,data_nascimento:document.getElementById('editNascimento').value||null});closeModal('modalEditar');if(r.sucesso){toast(r.mensagem);renderClientes();}else toast(r.erro,'error');}

let excluirId=0;
function excluirCliente(id,nome){excluirId=id;document.getElementById('modalExcluirText').innerHTML=`Excluir <strong>${nome}</strong>?`;openModal('modalExcluir');}
async function confirmarExcluir(){await api('clientes.php',{acao:'excluir',id:excluirId});closeModal('modalExcluir');toast('Exclu\u00eddo.');renderClientes();}

let chartVendasInst,chartCashbackInst,chartClientesInst;
async function renderRelatorios(){
  const ano=new Date().getFullYear();document.getElementById('chartAno').textContent=ano;
  const r=await api('compras.php?acao=relatorio_mensal&ano='+ano);
  const labels=MC;
  const vendas=(r.meses||[]).map(m=>m.total_vendas);

  if(chartVendasInst)chartVendasInst.destroy();
  chartVendasInst=new Chart(document.getElementById('chartVendas'),{type:'bar',data:{labels,datasets:[{label:'Vendas (R$)',data:vendas,backgroundColor:'rgba(33,150,243,0.7)',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>fM(v)}}}}});

  const rc=await api('compras.php?acao=relatorio_cashback_resgate&ano='+ano);
  const cashbackData=(rc.meses||[]).map(m=>+m.cashback);
  const resgateData=(rc.meses||[]).map(m=>+m.resgatado);
  if(chartCashbackInst)chartCashbackInst.destroy();
  chartCashbackInst=new Chart(document.getElementById('chartCashback'),{type:'bar',data:{labels,datasets:[{label:'Gerado',data:cashbackData,backgroundColor:'rgba(39,184,128,0.7)',borderRadius:4},{label:'Resgatado',data:resgateData,backgroundColor:'rgba(212,168,83,0.7)',borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{ticks:{callback:v=>fM(v)}}}}});

  const nc=await api('compras.php?acao=novos_clientes_mes&ano='+ano);
  const novosData=(nc.meses||[]).map(m=>+m.novos);
  if(chartClientesInst)chartClientesInst.destroy();
  chartClientesInst=new Chart(document.getElementById('chartClientes'),{type:'bar',data:{labels,datasets:[{label:'Novos Clientes',data:novosData,backgroundColor:'rgba(33,150,243,0.5)',borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{stepSize:1}}}}});

  const rk=await api('compras.php?acao=ranking_clientes&limite=20');
  document.getElementById('rankingList').innerHTML=(rk.ranking||[]).map((c,i)=>`<tr><td><strong>${i+1}\u00ba</strong></td><td>${c.nome}</td><td>${fP(c.telefone)}</td><td>${c.num_compras}</td><td><span class="badge badge-blue">${fM(+c.total_compras)}</span></td></tr>`).join('')||'<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">Nenhum dado</td></tr>';
}

async function renderConfig(){const ano=new Date().getFullYear();document.getElementById('configAno').textContent=ano;const r=await api('config.php?acao=listar&ano='+ano);const ma=new Date().getMonth();document.getElementById('configGrid').innerHTML=(r.meses||[]).map((m,i)=>`<div class="config-month${i===ma?' active':''}"><label>${MC[i]}</label><input type="number" id="cb_${i}" value="${m.percentual}" min="0" max="100" step="0.5"><div class="suffix">%</div></div>`).join('');}
async function salvarConfig(){const ano=new Date().getFullYear();const ms=[];for(let i=0;i<12;i++)ms.push(parseFloat(document.getElementById('cb_'+i).value)||0);const r=await api('config.php',{acao:'salvar',ano,meses:ms});if(r.sucesso){document.getElementById('configStatus').textContent='\u2713 Salvo!';setTimeout(()=>document.getElementById('configStatus').textContent='',3000);toast('Salvo!');}else toast(r.erro,'error');}
async function alterarSenha(){const a=document.getElementById('cfgSenhaAtual').value,s1=document.getElementById('cfgSenha1').value,s2=document.getElementById('cfgSenha2').value;if(!a){toast('Digite a senha atual!','error');return;}if(!s1||s1.length<6){toast('M\u00ednimo 6 caracteres!','error');return;}if(s1!==s2){toast('Senhas n\u00e3o coincidem!','error');return;}const r=await api('auth.php',{acao:'alterar_senha',senha_atual:a,nova_senha:s1});if(r.sucesso){toast('Senha alterada!');['cfgSenhaAtual','cfgSenha1','cfgSenha2'].forEach(x=>document.getElementById(x).value='');}else toast(r.erro,'error');}

// ===== PAGINA PUBLICA: Consulta de Saldo =====
function maskPublicBusca(el){
    let v = el.value.replace(/\D/g,'');
    if(v.length <= 11 && v.length > 2) maskPhone(el);
}

async function consultarSaldoPublico(){
    const termo = cl(document.getElementById('publicBusca').value);
    if(termo.length < 10){toast('Informe telefone ou CPF completo','error');return;}
    document.getElementById('publicSaldoResult').style.display='none';
    document.getElementById('publicSaldoErro').style.display='none';

    const r = await fetch('api/publico.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({acao:'consultar_saldo',termo})}).then(x=>x.json()).catch(()=>({sucesso:false,erro:'Erro de conexao'}));

    if(!r.sucesso){
        const el=document.getElementById('publicSaldoErro');
        el.textContent=r.erro;
        el.style.display='block';
        return;
    }
    document.getElementById('publicSaldoNome').textContent=r.cliente.nome;
    document.getElementById('publicSaldoValor').textContent=fM(r.saldo.credito_disponivel);
    document.getElementById('publicNumCompras').textContent=r.saldo.num_compras;
    document.getElementById('publicTotalCompras').textContent=fM(r.saldo.total_compras);
    document.getElementById('publicResgatado').textContent=fM(r.saldo.total_resgatado);

    let hist='';
    if(r.ultimas_compras && r.ultimas_compras.length>0){
        hist='<div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:8px">Ultimas compras:</div>';
        hist+=r.ultimas_compras.map(c=>`<div style="display:flex;justify-content:space-between;padding:8px 12px;background:var(--blue-50);border-radius:6px;margin-bottom:4px;font-size:13px"><span>${fDS(c.data)}</span><span>${fM(c.valor)}</span><span style="color:var(--gold-500);font-weight:600">+${fM(c.cashback)}</span></div>`).join('');
    }
    document.getElementById('publicUltimasCompras').innerHTML=hist;
    // Expiration warning
    if(r.proxima_expiracao){
        const expDiv=document.getElementById('publicExpiracao');
        if(expDiv){expDiv.querySelector('#publicExpData').textContent=fDS(r.proxima_expiracao);expDiv.style.display='block';}
    }
    // Campaign banners
    if(r.campanhas_ativas&&r.campanhas_ativas.length>0){
        const cb=document.getElementById('publicCampanhasBanner');
        if(cb){cb.innerHTML=r.campanhas_ativas.map(c=>`<div style="margin-bottom:8px"><strong>📣 ${c.nome}</strong> — Cashback com +${c.bonus_percentual}% bonus ate ${fDS(c.data_fim)}</div>`).join('');cb.style.display='block';}
    }
    document.getElementById('publicSaldoResult').style.display='block';
    if(r.cashback_atual) document.getElementById('publicPct').textContent=r.cashback_atual+'%';
}

// ===== PAGINA PUBLICA: Auto-Cadastro =====
async function autoCadastro(){
    const nome=document.getElementById('pubNome').value.trim();
    const cpf=document.getElementById('pubCPF').value;
    const telefone=document.getElementById('pubTel').value;
    const msg=document.getElementById('pubCadastroMsg');

    const r = await fetch('api/publico.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({acao:'autocadastro',nome,cpf,telefone,data_nascimento:document.getElementById('pubNascimento').value||null})}).then(x=>x.json()).catch(()=>({sucesso:false,erro:'Erro de conexao'}));

    msg.style.display='block';
    if(r.sucesso){
        msg.style.background='var(--green-100)';
        msg.style.color='var(--green-500)';
        msg.innerHTML='<strong>\u2713 '+r.mensagem+'</strong><br>Cashback atual: '+r.cashback_atual+'%';
        ['pubNome','pubCPF','pubTel','pubNascimento'].forEach(x=>document.getElementById(x).value='');
    } else {
        msg.style.background='var(--red-100)';
        msg.style.color='var(--red-500)';
        msg.textContent=r.erro;
    }
    setTimeout(()=>msg.style.display='none',6000);
}

// ===== BUSCA RAPIDA / AUTOCOMPLETE =====
let buscarRapidoTO;
function buscarRapido(q, targetId){
    clearTimeout(buscarRapidoTO);
    const el=document.getElementById(targetId);
    if(q.length < 2){el.classList.remove('show');el.innerHTML='';return;}
    buscarRapidoTO=setTimeout(async()=>{
        const r=await api('clientes.php?acao=buscar_rapido&q='+encodeURIComponent(q));
        if(!r.resultados||r.resultados.length===0){el.classList.remove('show');el.innerHTML='';return;}
        el.innerHTML=r.resultados.map(c=>`<div class="autocomplete-item" onclick="selecionarSugestao('${targetId}','${c.telefone}','${c.nome}')"><div><span class="ac-name">${c.nome}</span></div><span class="ac-info">${fP(c.telefone)}</span></div>`).join('');
        el.classList.add('show');
    },250);
}

function selecionarSugestao(targetId, telefone, nome){
    document.getElementById(targetId).classList.remove('show');
    // Determinar qual campo preencher baseado no target
    if(targetId==='vendaSugestoes'){
        document.getElementById('vendaTel').value=fP(telefone);
        buscarVenda();
    } else if(targetId==='consultaSugestoes'){
        document.getElementById('consultaBusca').value=telefone;
        consultarCliente();
    } else if(targetId==='resgateSugestoes'){
        document.getElementById('resgateTel').value=fP(telefone);
        buscarResgate();
    }
}

// Fechar autocomplete ao clicar fora
document.addEventListener('click',function(e){
    if(!e.target.closest('.search-bar')){
        document.querySelectorAll('.autocomplete-list').forEach(x=>{x.classList.remove('show');x.innerHTML='';});
    }
});

// ===== ANIVERSARIANTES =====
let anivMes=new Date().getMonth()+1;
function mesAniversario(delta){anivMes+=delta;if(anivMes<1)anivMes=12;if(anivMes>12)anivMes=1;renderAniversariantes();}
async function renderAniversariantes(){
  document.getElementById('anivMesLabel').textContent=MC[anivMes-1];
  const r=await api('clientes.php?acao=aniversariantes&mes='+anivMes);
  document.getElementById('anivList').innerHTML=(r.aniversariantes||[]).map(c=>`<tr><td><strong>${c.dia}</strong></td><td>${c.nome}</td><td>${fP(c.telefone)}</td><td>${fDS(c.data_nascimento)}</td><td><button class="btn btn-gold btn-xs" onclick="enviarParabens(${c.id})">🎂 Parabens</button></td></tr>`).join('')||'<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">Nenhum aniversariante</td></tr>';
}
async function enviarParabens(id){const r=await api('clientes.php',{acao:'enviar_parabens',cliente_id:id});if(r.sucesso)toast(r.mensagem);else toast(r.erro,'error');}

// ===== CAMPANHAS =====
async function renderCampanhas(){
  const r=await api('campanhas.php',{acao:'listar'});
  document.getElementById('campanhasList').innerHTML=(r.campanhas||[]).map(c=>{
    const ativa=c.ativa&&c.data_inicio<=new Date().toISOString().slice(0,10)&&c.data_fim>=new Date().toISOString().slice(0,10);
    const statusBadge=c.ativa?(ativa?'<span class="badge badge-green">Ativa</span>':'<span class="badge badge-blue">Agendada</span>'):'<span class="badge badge-red">Inativa</span>';
    return`<tr><td><strong>${c.nome}</strong>${c.descricao?'<br><small style="color:var(--text-muted)">'+c.descricao+'</small>':''}</td><td>${fDS(c.data_inicio)} - ${fDS(c.data_fim)}</td><td><span class="badge badge-gold">+${c.bonus_percentual}%</span></td><td>${statusBadge}</td><td><button class="btn btn-secondary btn-xs" onclick="toggleCampanha(${c.id})" style="margin:2px">${c.ativa?'Desativar':'Ativar'}</button></td></tr>`;
  }).join('')||'<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">Nenhuma campanha</td></tr>';
}
async function criarCampanha(){
  const r=await api('campanhas.php',{acao:'criar',nome:document.getElementById('campNome').value.trim(),data_inicio:document.getElementById('campInicio').value,data_fim:document.getElementById('campFim').value,bonus_percentual:parseFloat(document.getElementById('campBonus').value)||0,descricao:document.getElementById('campDesc').value.trim()});
  closeModal('modalNovaCampanha');
  if(r.sucesso){toast(r.mensagem);renderCampanhas();['campNome','campInicio','campFim','campBonus','campDesc'].forEach(x=>document.getElementById(x).value='');}else toast(r.erro,'error');
}
async function toggleCampanha(id){const r=await api('campanhas.php',{acao:'toggle',id});if(r.sucesso){toast(r.mensagem);renderCampanhas();}else toast(r.erro,'error');}

// ===== USUARIOS =====
async function renderUsuarios(){
  const r=await api('auth.php',{acao:'listar_usuarios'});
  document.getElementById('usuariosList').innerHTML=(r.usuarios||[]).map(u=>`<tr><td><strong>${u.nome}</strong></td><td>${u.username}</td><td><span class="role-badge role-${u.role}">${u.role}</span></td><td>${u.ativo?'<span class="badge badge-green">Ativo</span>':'<span class="badge badge-red">Inativo</span>'}</td><td>${u.ultimo_login?fD(u.ultimo_login):'\u2014'}</td><td><button class="btn btn-secondary btn-xs" onclick="editarUsuarioPrompt(${u.id},'${u.nome}','${u.role}',${u.ativo})" style="margin:2px">Editar</button><button class="btn btn-danger btn-xs" onclick="resetarSenhaPrompt(${u.id},'${u.nome}')" style="margin:2px">Reset Senha</button></td></tr>`).join('');
}
async function criarUsuario(){
  const r=await api('auth.php',{acao:'criar_usuario',nome:document.getElementById('novoUserNome').value.trim(),username:document.getElementById('novoUserUsername').value.trim(),senha:document.getElementById('novoUserSenha').value,role:document.getElementById('novoUserRole').value});
  closeModal('modalNovoUsuario');
  if(r.sucesso){toast(r.mensagem);renderUsuarios();['novoUserNome','novoUserUsername','novoUserSenha'].forEach(x=>document.getElementById(x).value='');}else toast(r.erro,'error');
}
function editarUsuarioPrompt(id,nome,role,ativo){
  const novoNome=prompt('Nome:',nome);if(!novoNome)return;
  const novoRole=prompt('Funcao (operador/gerente):',role);if(!novoRole)return;
  const novoAtivo=confirm('Usuario ativo?');
  api('auth.php',{acao:'editar_usuario',id,nome:novoNome,role:novoRole,ativo:novoAtivo}).then(r=>{if(r.sucesso){toast(r.mensagem);renderUsuarios();}else toast(r.erro,'error');});
}
function resetarSenhaPrompt(id,nome){
  const senha=prompt('Nova senha para '+nome+':');if(!senha)return;
  api('auth.php',{acao:'resetar_senha_usuario',id,nova_senha:senha}).then(r=>{if(r.sucesso)toast(r.mensagem);else toast(r.erro,'error');});
}

(async function(){
    if(sessionStorage.getItem('sb_logged')==='1'){
        const r = await api('auth.php',{acao:'verificar'});
        if(r.logado){
            csrfToken = r.csrf_token || '';
            currentUser = r.usuario;
            document.getElementById('loginPage').style.display='none';
            document.getElementById('app').style.display='block';
            setupPermissions();
            updateDashboard();
            updateDate();
        } else {
            sessionStorage.removeItem('sb_logged');
        }
    }
    if(window.location.hash==='#regras')showPublicPage();
})();
