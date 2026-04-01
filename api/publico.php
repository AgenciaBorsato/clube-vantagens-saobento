<?php
// ============================================================
// API PUBLICA - Sem autenticação
// Endpoints para clientes consultarem saldo e se cadastrarem
// ============================================================
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/whatsapp.php';

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();

// ===== CONSULTA PUBLICA DE SALDO =====
if ($acao === 'consultar_saldo') {
    $termo = preg_replace('/\D/', '', $input['termo'] ?? $_GET['termo'] ?? '');
    if (strlen($termo) < 10) {
        jsonResponse(['sucesso' => false, 'erro' => 'Informe seu telefone ou CPF completo'], 400);
    }

    $stmt = $db->prepare("SELECT id, nome, telefone, data_cadastro FROM clientes WHERE (telefone = ? OR cpf = ?) AND ativo = TRUE");
    $stmt->execute([$termo, $termo]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        jsonResponse(['sucesso' => false, 'erro' => 'Cadastro nao encontrado. Procure o balcao da farmacia para se cadastrar!'], 404);
    }

    $info = calcularCreditoCliente($cliente['id']);

    // Ultimas 5 compras (sem dados sensiveis)
    $stmt = $db->prepare("
        SELECT data_compra, valor, cashback_valor, cashback_percentual
        FROM compras
        WHERE cliente_id = ? AND estornada = FALSE
        ORDER BY data_compra DESC LIMIT 5
    ");
    $stmt->execute([$cliente['id']]);
    $ultimasCompras = $stmt->fetchAll();

    // Mascarar nome: mostrar primeiro nome + inicial do sobrenome
    $partes = explode(' ', $cliente['nome']);
    $nomePublico = $partes[0];
    if (count($partes) > 1) {
        $nomePublico .= ' ' . substr(end($partes), 0, 1) . '.';
    }

    jsonResponse([
        'sucesso' => true,
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
        'cashback_atual' => getCashbackAtual(),
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

    // Verificar duplicidade
    $stmt = $db->prepare("SELECT id FROM clientes WHERE (cpf = ? OR telefone = ?) AND ativo = TRUE");
    $stmt->execute([$cpf, $telefone]);
    if ($stmt->fetch()) {
        jsonResponse(['sucesso' => false, 'erro' => 'CPF ou telefone ja cadastrado! Voce ja faz parte do clube. Consulte seu saldo acima.'], 400);
    }

    $stmt = $db->prepare("INSERT INTO clientes (nome, cpf, telefone) VALUES (?, ?, ?) RETURNING id");
    $stmt->execute([mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8'), $cpf, $telefone]);
    $id = $stmt->fetch()['id'];

    registrarAuditoria('autocadastro', "Auto-cadastro: $nome (CPF: $cpf)", 'cliente', $id);

    // Enviar boas-vindas via WhatsApp (async - nao bloqueia resposta)
    $cashbackAtual = getCashbackAtual();
    notificarBoasVindas($telefone, $nome, $cashbackAtual);

    jsonResponse([
        'sucesso' => true,
        'mensagem' => 'Cadastro realizado com sucesso! Bem-vindo(a) ao Clube de Vantagens!',
        'cashback_atual' => $cashbackAtual,
    ]);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
