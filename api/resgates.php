<?php
require_once __DIR__.'/../includes/db.php';
exigirLogin();

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();

if ($acao === 'resgatar') {
    $clienteId = intval($input['cliente_id'] ?? 0);
    $valor = floatval($input['valor'] ?? 0);
    if (!$clienteId) jsonResponse(['sucesso' => false, 'erro' => 'Cliente invalido'], 400);
    if ($valor <= 0) jsonResponse(['sucesso' => false, 'erro' => 'Valor invalido'], 400);
    $info = calcularCreditoCliente($clienteId);
    if ($info['expirado']) jsonResponse(['sucesso' => false, 'erro' => 'Creditos expirados'], 400);
    if ($valor > $info['credito_disponivel']) jsonResponse(['sucesso' => false, 'erro' => 'Valor maior que o credito disponivel'], 400);
    $db->prepare("INSERT INTO resgates (cliente_id, valor) VALUES (?, ?)")->execute([$clienteId, $valor]);
    jsonResponse(['sucesso' => true, 'mensagem' => 'Resgate realizado com sucesso']);
}

if ($acao === 'historico') {
    $clienteId = intval($input['cliente_id'] ?? $_GET['cliente_id'] ?? 0);
    if (!$clienteId) jsonResponse(['sucesso' => false, 'erro' => 'ID do cliente invalido'], 400);
    $stmt = $db->prepare("SELECT * FROM resgates WHERE cliente_id = ? ORDER BY data_resgate DESC");
    $stmt->execute([$clienteId]);
    jsonResponse(['sucesso' => true, 'resgates' => $stmt->fetchAll()]);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
