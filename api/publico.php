<?php
// ============================================================
// BipCash SaaS - API PUBLICA Multi-Tenant
// Sem autenticacao - identificacao por slug da farmacia
// Endpoints para clientes consultarem saldo e se cadastrarem
// ============================================================
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/whatsapp.php';

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();

// Resolver farmacia pelo slug (parametro f=slug)
$farmaciaSlug = $_GET['f'] ?? $input['farmacia'] ?? '';
$farmaciaId = null;
$farmaciaInfo = null;
if ($farmaciaSlug) {
    $stmtF = $db->prepare("SELECT id, nome, slug, logo_base64, cor_primaria, cor_secundaria FROM farmacias WHERE slug = ? AND ativa = TRUE");
    $stmtF->execute([$farmaciaSlug]);
    $farmaciaInfo = $stmtF->fetch();
    if ($farmaciaInfo) $farmaciaId = $farmaciaInfo['id'];
}
if (!$farmaciaId) {
    jsonResponse(['erro' => 'Farmacia nao identificada. Verifique o link de acesso.'], 400);
}

// ===== INFO DA FARMACIA (para personalizar pagina publica) =====
if ($acao === 'info_farmacia') {
    jsonResponse([
        'sucesso' => true,
        'farmacia' => [
            'nome' => $farmaciaInfo['nome'],
            'slug' => $farmaciaInfo['slug'],
            'logo' => $farmaciaInfo['logo_base64'],
            'cor_primaria' => $farmaciaInfo['cor_primaria'],
            'cor_secundaria' => $farmaciaInfo['cor_secundaria']
        ]
    ]);
}

// ===== CONSULTA PUBLICA DE SALDO =====
if ($acao === 'consultar_saldo') {
    $termo = preg_replace('/\D/', '', $input['termo'] ?? $_GET['termo'] ?? '');
    if (strlen($termo) < 10) {
        jsonResponse(['sucesso' => false, 'erro' => 'Informe seu telefone ou CPF completo'], 400);
    }

    $stmt = $db->prepare("SELECT id, nome, telefone, data_cadastro FROM clientes WHERE (telefone = ? OR cpf = ?) AND farmacia_id = ? AND ativo = TRUE");
    $stmt->execute([$termo, $termo, $farmaciaId]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        jsonResponse(['sucesso' => false, 'erro' => 'Cadastro nao encontrado. Procure o balcao da farmacia para se cadastrar!'], 404);
    }

    $info = calcularCreditoCliente($cliente['id']);

    // Ultimas 5 compras (sem dados sensiveis)
    $stmt = $db->prepare("
        SELECT data_compra, valor, cashback_valor, cashback_percentual
        FROM compras
        WHERE cliente_id = ? AND farmacia_id = ? AND estornada = FALSE
        ORDER BY data_compra DESC LIMIT 5
    ");
    $stmt->execute([$cliente['id'], $farmaciaId]);
    $ultimasCompras = $stmt->fetchAll();

    // Mascarar nome: mostrar primeiro nome + inicial do sobrenome
    $partes = explode(' ', $cliente['nome']);
    $nomePublico = $partes[0];
    if (count($partes) > 1) {
        $nomePublico .= ' ' . substr(end($partes), 0, 1) . '.';
    }

    // Info de expiracao
    $limite = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months'));
    $stmtExp = $db->prepare("SELECT MIN(data_compra) as mais_antiga FROM compras WHERE cliente_id = ? AND farmacia_id = ? AND estornada = FALSE AND data_compra >= ?");
    $stmtExp->execute([$cliente['id'], $farmaciaId, $limite]);
    $maisAntiga = $stmtExp->fetch()['mais_antiga'];
    $proximaExpiracao = $maisAntiga ? date('Y-m-d', strtotime($maisAntiga . ' + ' . EXPIRACAO_MESES . ' months')) : null;

    // Campanhas ativas da farmacia
    $stmtCamp = $db->prepare("SELECT nome, bonus_percentual, data_fim FROM campanhas WHERE farmacia_id = ? AND ativa = TRUE AND data_inicio <= CURRENT_DATE AND data_fim >= CURRENT_DATE ORDER BY bonus_percentual DESC");
    $stmtCamp->execute([$farmaciaId]);
    $campanhasAtivas = $stmtCamp->fetchAll();

    jsonResponse([
        'sucesso' => true,
        'farmacia' => [
            'nome' => $farmaciaInfo['nome'],
            'cor_primaria' => $farmaciaInfo['cor_primaria'],
            'cor_secundaria' => $farmaciaInfo['cor_secundaria']
        ],
        'cliente' => [
            'nome' => $nomePublico,
            'membro_desde' => $cliente['data_cadastro'],
        ],
        'saldo' => [
            'credito_disponivel' => $info['credito_disponivel'],
            'total_compras' => $info['total_compras'],
            'num_compras' => $info['num_compras'],
            'cashback_total' => $info['cashback_total'],
            'total_resgatado' => $info['total_resgatado'],
            'expirado' => $info['expirado'],
        ],
        'ultimas_compras' => array_map(function($c) {
            return [
                'data' => $c['data_compra'],
                'valor' => floatval($c['valor']),
                'cashback' => floatval($c['cashback_valor']),
            ];
        }, $ultimasCompras),
        'cashback_atual' => getCashbackAtual($farmaciaId),
        'proxima_expiracao' => $proximaExpiracao,
        'campanhas_ativas' => $campanhasAtivas,
    ]);
}

// ===== AUTO-CADASTRO PUBLICO =====
if ($acao === 'autocadastro') {
    $nome = trim($input['nome'] ?? '');
    $cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
    $telefone = preg_replace('/\D/', '', $input['telefone'] ?? '');

    if (!$nome) jsonResponse(['sucesso' => false, 'erro' => 'Informe seu nome completo'], 400);
    if (strlen($nome) < 5) jsonResponse(['sucesso' => false, 'erro' => 'Informe nome e sobrenome'], 400);
    if (!validarCPF($cpf)) jsonResponse(['sucesso' => false, 'erro' => 'CPF invalido. Verifique os digitos.'], 400);
    if (strlen($telefone) < 10 || strlen($telefone) > 11) jsonResponse(['sucesso' => false, 'erro' => 'Telefone invalido. Use DDD + numero.'], 400);

    $dataNasc = $input['data_nascimento'] ?? null;
    if ($dataNasc && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataNasc)) $dataNasc = null;

    // Verificar duplicidade na farmacia
    $stmt = $db->prepare("SELECT id FROM clientes WHERE (cpf = ? OR telefone = ?) AND farmacia_id = ? AND ativo = TRUE");
    $stmt->execute([$cpf, $telefone, $farmaciaId]);
    if ($stmt->fetch()) {
        jsonResponse(['sucesso' => false, 'erro' => 'CPF ou telefone ja cadastrado! Voce ja faz parte do clube. Consulte seu saldo acima.'], 400);
    }

    $stmt = $db->prepare("INSERT INTO clientes (farmacia_id, nome, cpf, telefone, data_nascimento) VALUES (?, ?, ?, ?, ?) RETURNING id");
    $stmt->execute([$farmaciaId, mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8'), $cpf, $telefone, $dataNasc]);
    $id = $stmt->fetch()['id'];

    // Usar farmacia_id null na sessao para auditoria (endpoint publico)
    $_SESSION['farmacia_id'] = $farmaciaId;
    registrarAuditoria('autocadastro', "Auto-cadastro: $nome (CPF: $cpf)", 'cliente', $id);

    // Enviar boas-vindas via WhatsApp
    $cashbackAtual = getCashbackAtual($farmaciaId);
    notificarBoasVindas($telefone, $nome, $cashbackAtual, $farmaciaId);

    jsonResponse([
        'sucesso' => true,
        'mensagem' => 'Cadastro realizado com sucesso! Bem-vindo(a) ao Clube de Vantagens!',
        'cashback_atual' => $cashbackAtual,
    ]);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
