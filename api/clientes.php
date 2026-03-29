<?php
require_once __DIR__.'/../includes/db.php';
exigirLogin();

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();

if ($acao === 'listar') {
    $busca = $input['busca'] ?? $_GET['busca'] ?? '';
    $pagina = max(1, intval($_GET['pagina'] ?? 1));
    $porPagina = 50;
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT * FROM clientes WHERE ativo = TRUE";
    $sqlCount = "SELECT COUNT(*) as total FROM clientes WHERE ativo = TRUE";
    $params = [];
    if ($busca) {
        $limpo = preg_replace('/\D/', '', $busca);
        $where = " AND (nome ILIKE ? OR cpf LIKE ? OR telefone LIKE ?)";
        $sql .= $where;
        $sqlCount .= $where;
        $params = ["%$busca%", "%$limpo%", "%$limpo%"];
    }

    $stmtC = $db->prepare($sqlCount);
    $stmtC->execute($params);
    $total = $stmtC->fetch()['total'];

    $sql .= " ORDER BY nome LIMIT ? OFFSET ?";
    $params[] = $porPagina;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $clientes = $stmt->fetchAll();

    foreach ($clientes as &$cl) {
        $info = calcularCreditoCliente($cl['id']);
        $cl['total_compras'] = $info['total_compras'];
        $cl['credito_disponivel'] = $info['credito_disponivel'];
        $cl['expirado'] = $info['expirado'];
    }

    jsonResponse([
        'clientes' => $clientes,
        'total' => intval($total),
        'pagina' => $pagina,
        'total_paginas' => ceil($total / $porPagina)
    ]);
}

if ($acao === 'cadastrar') {
    $nome = trim($input['nome'] ?? '');
    $cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
    $telefone = preg_replace('/\D/', '', $input['telefone'] ?? '');
    if (!$nome || !$cpf || !$telefone) jsonResponse(['sucesso' => false, 'erro' => 'Preencha todos os campos'], 400);
    if (strlen($cpf) !== 11) jsonResponse(['sucesso' => false, 'erro' => 'CPF invalido'], 400);
    if (strlen($telefone) < 10) jsonResponse(['sucesso' => false, 'erro' => 'Telefone invalido'], 400);

    $stmt = $db->prepare("SELECT id FROM clientes WHERE (cpf = ? OR telefone = ?) AND ativo = TRUE");
    $stmt->execute([$cpf, $telefone]);
    if ($stmt->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'CPF ou telefone ja cadastrado'], 400);

    $stmt = $db->prepare("INSERT INTO clientes (nome, cpf, telefone) VALUES (?, ?, ?) RETURNING id");
    $stmt->execute([$nome, $cpf, $telefone]);
    $id = $stmt->fetch()['id'];
    jsonResponse(['sucesso' => true, 'mensagem' => 'Cliente cadastrado com sucesso', 'id' => $id]);
}

if ($acao === 'editar') {
    $id = intval($input['id'] ?? 0);
    $nome = trim($input['nome'] ?? '');
    $cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
    $telefone = preg_replace('/\D/', '', $input['telefone'] ?? '');
    if (!$id) jsonResponse(['sucesso' => false, 'erro' => 'ID invalido'], 400);
    if (!$nome || !$cpf || !$telefone) jsonResponse(['sucesso' => false, 'erro' => 'Preencha todos os campos'], 400);

    $stmt = $db->prepare("SELECT id FROM clientes WHERE (cpf = ? OR telefone = ?) AND ativo = TRUE AND id != ?");
    $stmt->execute([$cpf, $telefone, $id]);
    if ($stmt->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'CPF ou telefone ja pertence a outro cliente'], 400);

    $db->prepare("UPDATE clientes SET nome = ?, cpf = ?, telefone = ? WHERE id = ? AND ativo = TRUE")->execute([$nome, $cpf, $telefone, $id]);
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

if ($acao === 'excluir') {
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonResponse(['sucesso' => false, 'erro' => 'ID invalido'], 400);
    $db->prepare("UPDATE clientes SET ativo = FALSE WHERE id = ?")->execute([$id]);
    jsonResponse(['sucesso' => true, 'mensagem' => 'Cliente excluido']);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
