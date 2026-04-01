<?php
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/whatsapp.php';
exigirLogin();

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();

if ($acao === 'listar') {
    $busca = $input['busca'] ?? $_GET['busca'] ?? '';
    $pagina = max(1, intval($_GET['pagina'] ?? 1));
    $porPagina = 50;
    $offset = ($pagina - 1) * $porPagina;
    $limite = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months'));

    $whereExtra = '';
    $params = [];
    if ($busca) {
        $limpo = preg_replace('/\D/', '', $busca);
        $whereExtra = " AND (cl.nome ILIKE ? OR cl.cpf LIKE ? OR cl.telefone LIKE ?)";
        $params = ["%$busca%", "%$limpo%", "%$limpo%"];
    }

    // Count
    $stmtC = $db->prepare("SELECT COUNT(*) as total FROM clientes cl WHERE cl.ativo = TRUE" . $whereExtra);
    $stmtC->execute($params);
    $total = $stmtC->fetch()['total'];

    // Query otimizada: JOIN com aggregates em vez de N+1
    $sql = "
        SELECT cl.*,
               COALESCE(comp.total_compras, 0) as total_compras,
               COALESCE(comp.cashback_valido, 0) as cashback_valido,
               COALESCE(res.total_resgatado, 0) as total_resgatado,
               GREATEST(0, COALESCE(comp.cashback_valido, 0) - COALESCE(res.total_resgatado, 0)) as credito_disponivel,
               CASE WHEN COALESCE(comp.cashback_valido, 0) <= 0 AND COALESCE(comp.total_cashback, 0) > 0 THEN TRUE ELSE FALSE END as expirado
        FROM clientes cl
        LEFT JOIN (
            SELECT cliente_id,
                   SUM(valor) as total_compras,
                   SUM(CASE WHEN data_compra >= ? THEN cashback_valor ELSE 0 END) as cashback_valido,
                   SUM(cashback_valor) as total_cashback
            FROM compras WHERE estornada = FALSE
            GROUP BY cliente_id
        ) comp ON comp.cliente_id = cl.id
        LEFT JOIN (
            SELECT cliente_id, SUM(valor) as total_resgatado
            FROM resgates WHERE estornado = FALSE
            GROUP BY cliente_id
        ) res ON res.cliente_id = cl.id
        WHERE cl.ativo = TRUE" . $whereExtra . "
        ORDER BY cl.nome LIMIT ? OFFSET ?
    ";
    $queryParams = array_merge([$limite], $params, [$porPagina, $offset]);
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $clientes = $stmt->fetchAll();

    // Converter tipos
    foreach ($clientes as &$cl) {
        $cl['total_compras'] = floatval($cl['total_compras']);
        $cl['credito_disponivel'] = floatval($cl['credito_disponivel']);
        $cl['expirado'] = (bool)$cl['expirado'];
    }

    jsonResponse([
        'clientes' => $clientes,
        'total' => intval($total),
        'pagina' => $pagina,
        'total_paginas' => ceil($total / $porPagina)
    ]);
}

if ($acao === 'cadastrar') {
    verificarCSRF();
    $nome = trim($input['nome'] ?? '');
    $cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
    $telefone = preg_replace('/\D/', '', $input['telefone'] ?? '');
    if (!$nome || !$cpf || !$telefone) jsonResponse(['sucesso' => false, 'erro' => 'Preencha todos os campos'], 400);
    if (!validarCPF($cpf)) jsonResponse(['sucesso' => false, 'erro' => 'CPF invalido. Verifique os digitos.'], 400);
    if (strlen($telefone) < 10) jsonResponse(['sucesso' => false, 'erro' => 'Telefone invalido'], 400);

    $stmt = $db->prepare("SELECT id FROM clientes WHERE (cpf = ? OR telefone = ?) AND ativo = TRUE");
    $stmt->execute([$cpf, $telefone]);
    if ($stmt->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'CPF ou telefone ja cadastrado'], 400);

    $stmt = $db->prepare("INSERT INTO clientes (nome, cpf, telefone) VALUES (?, ?, ?) RETURNING id");
    $stmt->execute([$nome, $cpf, $telefone]);
    $id = $stmt->fetch()['id'];
    registrarAuditoria('cadastro_cliente', "Cliente '$nome' cadastrado (CPF: $cpf)", 'cliente', $id);

    // Boas-vindas via WhatsApp
    notificarBoasVindas($telefone, $nome, getCashbackAtual());

    jsonResponse(['sucesso' => true, 'mensagem' => 'Cliente cadastrado com sucesso', 'id' => $id]);
}

if ($acao === 'editar') {
    verificarCSRF();
    $id = intval($input['id'] ?? 0);
    $nome = trim($input['nome'] ?? '');
    $cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
    $telefone = preg_replace('/\D/', '', $input['telefone'] ?? '');
    if (!$id) jsonResponse(['sucesso' => false, 'erro' => 'ID invalido'], 400);
    if (!$nome || !$cpf || !$telefone) jsonResponse(['sucesso' => false, 'erro' => 'Preencha todos os campos'], 400);
    if (!validarCPF($cpf)) jsonResponse(['sucesso' => false, 'erro' => 'CPF invalido. Verifique os digitos.'], 400);

    $stmt = $db->prepare("SELECT id FROM clientes WHERE (cpf = ? OR telefone = ?) AND ativo = TRUE AND id != ?");
    $stmt->execute([$cpf, $telefone, $id]);
    if ($stmt->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'CPF ou telefone ja pertence a outro cliente'], 400);

    $db->prepare("UPDATE clientes SET nome = ?, cpf = ?, telefone = ? WHERE id = ? AND ativo = TRUE")->execute([$nome, $cpf, $telefone, $id]);
    registrarAuditoria('editar_cliente', "Cliente #$id atualizado: $nome", 'cliente', $id);
    jsonResponse(['sucesso' => true, 'mensagem' => 'Cliente atualizado com sucesso']);
}

if ($acao === 'buscar') {
    $termo = preg_replace('/\D/', '', $input['termo'] ?? $_GET['termo'] ?? '');
    if (strlen($termo) < 10) jsonResponse(['sucesso' => false, 'erro' => 'Termo de busca invalido'], 400);
    $stmt = $db->prepare("SELECT * FROM clientes WHERE (telefone = ? OR cpf = ?) AND ativo = TRUE");
    $stmt->execute([$termo, $termo]);
    $cliente = $stmt->fetch();
    if (!$cliente) jsonResponse(['sucesso' => false, 'erro' => 'Cliente nao encontrado'], 404);
    $info = calcularCreditoCliente($cliente['id']);
    $cliente = array_merge($cliente, $info);
    jsonResponse(['sucesso' => true, 'cliente' => $cliente]);
}

// Busca rapida por nome parcial, telefone ou CPF (autocomplete)
if ($acao === 'buscar_rapido') {
    $q = trim($input['q'] ?? $_GET['q'] ?? '');
    if (strlen($q) < 2) jsonResponse(['resultados' => []]);
    $limpo = preg_replace('/\D/', '', $q);
    $isNumero = strlen($limpo) >= 2;

    if ($isNumero) {
        $stmt = $db->prepare("SELECT id, nome, telefone, cpf FROM clientes WHERE (telefone LIKE ? OR cpf LIKE ?) AND ativo = TRUE ORDER BY nome LIMIT 8");
        $stmt->execute(["%$limpo%", "%$limpo%"]);
    } else {
        $stmt = $db->prepare("SELECT id, nome, telefone, cpf FROM clientes WHERE nome ILIKE ? AND ativo = TRUE ORDER BY nome LIMIT 8");
        $stmt->execute(["%$q%"]);
    }
    jsonResponse(['resultados' => $stmt->fetchAll()]);
}

if ($acao === 'excluir') {
    verificarCSRF();
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonResponse(['sucesso' => false, 'erro' => 'ID invalido'], 400);
    // Buscar nome antes de excluir para auditoria
    $stmt = $db->prepare("SELECT nome FROM clientes WHERE id = ? AND ativo = TRUE");
    $stmt->execute([$id]);
    $cl = $stmt->fetch();
    if ($cl) {
        $db->prepare("UPDATE clientes SET ativo = FALSE WHERE id = ?")->execute([$id]);
        registrarAuditoria('excluir_cliente', "Cliente #$id '{$cl['nome']}' desativado", 'cliente', $id);
    }
    jsonResponse(['sucesso' => true, 'mensagem' => 'Cliente excluido']);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
