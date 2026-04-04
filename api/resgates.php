<?php
// ============================================================
// BipCash SaaS - API Resgates Multi-Tenant
// Todas as queries filtradas por farmacia_id
// ============================================================
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/whatsapp.php';
exigirLogin();

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();
$farmaciaId = getFarmaciaId();

if ($acao === 'resgatar') {
    verificarCSRF();
    $clienteId = intval($input['cliente_id'] ?? 0);
    $valor = floatval($input['valor'] ?? 0);
    if (!$clienteId) jsonResponse(['sucesso' => false, 'erro' => 'Cliente invalido'], 400);
    if ($valor <= 0) jsonResponse(['sucesso' => false, 'erro' => 'Valor invalido'], 400);

    // Verificar que o cliente pertence a esta farmacia
    $stmtCl = $db->prepare("SELECT id FROM clientes WHERE id = ? AND farmacia_id = ? AND ativo = TRUE");
    $stmtCl->execute([$clienteId, $farmaciaId]);
    if (!$stmtCl->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'Cliente nao encontrado nesta farmacia'], 404);

    // Transacao com lock para evitar race condition
    $db->beginTransaction();
    try {
        // Lock nas compras do cliente para garantir consistencia
        $limite = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months'));
        $stmt = $db->prepare("SELECT COALESCE(SUM(cashback_valor),0) as cashback_valido FROM compras WHERE cliente_id = ? AND estornada = FALSE AND data_compra >= ? FOR UPDATE");
        $stmt->execute([$clienteId, $limite]);
        $cashbackValido = floatval($stmt->fetch()['cashback_valido']);

        $stmt = $db->prepare("SELECT COALESCE(SUM(valor),0) as total_resgatado FROM resgates WHERE cliente_id = ? AND estornado = FALSE FOR UPDATE");
        $stmt->execute([$clienteId]);
        $totalResgatado = floatval($stmt->fetch()['total_resgatado']);

        $creditoDisponivel = max(0, $cashbackValido - $totalResgatado);

        if ($cashbackValido <= 0) {
            $db->rollBack();
            jsonResponse(['sucesso' => false, 'erro' => 'Creditos expirados'], 400);
        }
        if ($valor > $creditoDisponivel) {
            $db->rollBack();
            jsonResponse(['sucesso' => false, 'erro' => 'Valor maior que o credito disponivel (R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')'], 400);
        }

        $db->prepare("INSERT INTO resgates (farmacia_id, cliente_id, valor) VALUES (?, ?, ?)")->execute([$farmaciaId, $clienteId, $valor]);
        registrarAuditoria('resgate', "Resgate de R$ " . number_format($valor, 2, ',', '.'), 'cliente', $clienteId);
        $db->commit();

        // Notificar cliente via WhatsApp
        $stmtCl = $db->prepare("SELECT nome, telefone FROM clientes WHERE id = ?");
        $stmtCl->execute([$clienteId]);
        $cl = $stmtCl->fetch();
        if ($cl) {
            $infoAtual = calcularCreditoCliente($clienteId);
            notificarResgate($cl['telefone'], $cl['nome'], $valor, $infoAtual['credito_disponivel'], $farmaciaId);
        }

        jsonResponse(['sucesso' => true, 'mensagem' => 'Resgate realizado com sucesso']);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['sucesso' => false, 'erro' => 'Erro ao processar resgate'], 500);
    }
}

if ($acao === 'historico') {
    $clienteId = intval($input['cliente_id'] ?? $_GET['cliente_id'] ?? 0);
    if (!$clienteId) jsonResponse(['sucesso' => false, 'erro' => 'ID do cliente invalido'], 400);

    // Verificar que o cliente pertence a esta farmacia
    $stmtCl = $db->prepare("SELECT id FROM clientes WHERE id = ? AND farmacia_id = ?");
    $stmtCl->execute([$clienteId, $farmaciaId]);
    if (!$stmtCl->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'Cliente nao encontrado'], 404);

    $stmt = $db->prepare("SELECT * FROM resgates WHERE cliente_id = ? AND farmacia_id = ? ORDER BY data_resgate DESC");
    $stmt->execute([$clienteId, $farmaciaId]);
    jsonResponse(['sucesso' => true, 'resgates' => $stmt->fetchAll()]);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
