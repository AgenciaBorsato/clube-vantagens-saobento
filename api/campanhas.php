<?php
// ============================================================
// BipCash SaaS - API Campanhas Multi-Tenant
// Todas as queries filtradas por farmacia_id
// ============================================================
require_once __DIR__.'/../includes/db.php';
exigirLogin();

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();
$farmaciaId = getFarmaciaId();

if ($acao === 'listar') {
    $stmt = $db->prepare("SELECT * FROM campanhas WHERE farmacia_id = ? ORDER BY data_inicio DESC");
    $stmt->execute([$farmaciaId]);
    jsonResponse(['campanhas' => $stmt->fetchAll()]);
}

if ($acao === 'ativas') {
    $stmt = $db->prepare("SELECT * FROM campanhas WHERE farmacia_id = ? AND ativa = TRUE AND data_inicio <= CURRENT_DATE AND data_fim >= CURRENT_DATE ORDER BY bonus_percentual DESC");
    $stmt->execute([$farmaciaId]);
    jsonResponse(['campanhas' => $stmt->fetchAll()]);
}

if ($acao === 'criar') {
    exigirGerente();
    verificarCSRF();
    $nome = trim($input['nome'] ?? '');
    $dataInicio = $input['data_inicio'] ?? '';
    $dataFim = $input['data_fim'] ?? '';
    $bonus = floatval($input['bonus_percentual'] ?? 0);
    $descricao = trim($input['descricao'] ?? '');

    if (!$nome) jsonResponse(['sucesso' => false, 'erro' => 'Nome da campanha obrigatorio'], 400);
    if (!$dataInicio || !$dataFim) jsonResponse(['sucesso' => false, 'erro' => 'Datas obrigatorias'], 400);
    if ($dataFim < $dataInicio) jsonResponse(['sucesso' => false, 'erro' => 'Data fim deve ser posterior a data inicio'], 400);
    if ($bonus <= 0 || $bonus > 100) jsonResponse(['sucesso' => false, 'erro' => 'Bonus deve ser entre 0.01 e 100'], 400);

    $stmt = $db->prepare("INSERT INTO campanhas (farmacia_id, nome, data_inicio, data_fim, bonus_percentual, descricao) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$farmaciaId, $nome, $dataInicio, $dataFim, $bonus, $descricao]);
    registrarAuditoria('criar_campanha', "Campanha '$nome' criada: +$bonus%");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Campanha criada com sucesso']);
}

if ($acao === 'editar') {
    exigirGerente();
    verificarCSRF();
    $id = intval($input['id'] ?? 0);
    $nome = trim($input['nome'] ?? '');
    $dataInicio = $input['data_inicio'] ?? '';
    $dataFim = $input['data_fim'] ?? '';
    $bonus = floatval($input['bonus_percentual'] ?? 0);
    $descricao = trim($input['descricao'] ?? '');

    if (!$id || !$nome) jsonResponse(['sucesso' => false, 'erro' => 'Dados invalidos'], 400);

    $db->prepare("UPDATE campanhas SET nome = ?, data_inicio = ?, data_fim = ?, bonus_percentual = ?, descricao = ? WHERE id = ? AND farmacia_id = ?")->execute([$nome, $dataInicio, $dataFim, $bonus, $descricao, $id, $farmaciaId]);
    registrarAuditoria('editar_campanha', "Campanha #$id atualizada");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Campanha atualizada']);
}

if ($acao === 'toggle') {
    exigirGerente();
    verificarCSRF();
    $id = intval($input['id'] ?? 0);
    if (!$id) jsonResponse(['sucesso' => false, 'erro' => 'ID invalido'], 400);
    $db->prepare("UPDATE campanhas SET ativa = NOT ativa WHERE id = ? AND farmacia_id = ?")->execute([$id, $farmaciaId]);
    registrarAuditoria('toggle_campanha', "Campanha #$id ativada/desativada");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Status da campanha alterado']);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
