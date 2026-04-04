<?php
// ============================================================
// BipCash SaaS - API Notificacoes Multi-Tenant
// Todas as queries filtradas por farmacia_id
// ============================================================
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/whatsapp.php';
exigirLogin();

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();
$farmaciaId = getFarmaciaId();

if ($acao === 'creditos_expirando') {
    $limiteExpiracao = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months'));
    $limiteAlerta = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months + 7 days'));

    $stmt = $db->prepare("
        SELECT c.id, c.nome, c.telefone,
               SUM(comp.cashback_valor) as cashback_expirando,
               MIN(comp.data_compra) as compra_mais_antiga
        FROM clientes c
        INNER JOIN compras comp ON comp.cliente_id = c.id
        WHERE c.farmacia_id = ? AND c.ativo = TRUE
          AND comp.estornada = FALSE
          AND comp.data_compra >= ?
          AND comp.data_compra < ?
        GROUP BY c.id, c.nome, c.telefone
        HAVING SUM(comp.cashback_valor) > 0
        ORDER BY compra_mais_antiga ASC
    ");
    $stmt->execute([$farmaciaId, $limiteExpiracao, $limiteAlerta]);
    $clientes = $stmt->fetchAll();

    // Para cada cliente, calcular credito real disponivel
    foreach ($clientes as &$cl) {
        $info = calcularCreditoCliente($cl['id']);
        $cl['credito_disponivel'] = $info['credito_disponivel'];
        $cl['data_expiracao'] = date('Y-m-d', strtotime($cl['compra_mais_antiga'] . ' + ' . EXPIRACAO_MESES . ' months'));
    }
    // Filtrar apenas quem tem credito disponivel
    $clientes = array_values(array_filter($clientes, fn($c) => $c['credito_disponivel'] > 0));

    jsonResponse(['clientes' => $clientes, 'total' => count($clientes)]);
}

if ($acao === 'resumo_expiracoes') {
    $limiteExpiracao = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months'));
    $limiteAlerta = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months + 7 days'));

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT comp.cliente_id) as total_clientes,
               COALESCE(SUM(comp.cashback_valor), 0) as total_valor
        FROM compras comp
        INNER JOIN clientes c ON c.id = comp.cliente_id AND c.farmacia_id = ? AND c.ativo = TRUE
        WHERE comp.estornada = FALSE
          AND comp.data_compra >= ?
          AND comp.data_compra < ?
    ");
    $stmt->execute([$farmaciaId, $limiteExpiracao, $limiteAlerta]);
    jsonResponse($stmt->fetch());
}

if ($acao === 'enviar_alerta') {
    verificarCSRF();
    $clienteId = intval($input['cliente_id'] ?? 0);

    if ($clienteId) {
        // Enviar para um cliente especifico (verificar que pertence a farmacia)
        $stmt = $db->prepare("SELECT nome, telefone FROM clientes WHERE id = ? AND farmacia_id = ? AND ativo = TRUE");
        $stmt->execute([$clienteId, $farmaciaId]);
        $cl = $stmt->fetch();
        if (!$cl) jsonResponse(['sucesso' => false, 'erro' => 'Cliente nao encontrado'], 404);
        $info = calcularCreditoCliente($clienteId);
        $dataExp = date('d/m/Y', strtotime('-' . EXPIRACAO_MESES . ' months + ' . EXPIRACAO_MESES . ' months'));
        $resultado = notificarCreditoExpirando($cl['telefone'], $cl['nome'], $info['credito_disponivel'], $dataExp, $farmaciaId);
        registrarAuditoria('alerta_expiracao', "Alerta enviado para {$cl['nome']}", 'cliente', $clienteId);
        jsonResponse(['sucesso' => true, 'mensagem' => 'Alerta enviado!', 'whatsapp' => $resultado]);
    }

    jsonResponse(['sucesso' => false, 'erro' => 'Cliente invalido'], 400);
}

if ($acao === 'enviar_alerta_todos') {
    verificarCSRF();
    // Reutilizar logica de creditos_expirando
    $limiteExpiracao = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months'));
    $limiteAlerta = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months + 7 days'));

    $stmt = $db->prepare("
        SELECT c.id, c.nome, c.telefone, MIN(comp.data_compra) as compra_mais_antiga
        FROM clientes c
        INNER JOIN compras comp ON comp.cliente_id = c.id
        WHERE c.farmacia_id = ? AND c.ativo = TRUE AND comp.estornada = FALSE
          AND comp.data_compra >= ? AND comp.data_compra < ?
        GROUP BY c.id, c.nome, c.telefone
        HAVING SUM(comp.cashback_valor) > 0
    ");
    $stmt->execute([$farmaciaId, $limiteExpiracao, $limiteAlerta]);
    $clientes = $stmt->fetchAll();

    $enviados = 0;
    foreach ($clientes as $cl) {
        $info = calcularCreditoCliente($cl['id']);
        if ($info['credito_disponivel'] > 0) {
            $dataExp = date('d/m/Y', strtotime($cl['compra_mais_antiga'] . ' + ' . EXPIRACAO_MESES . ' months'));
            notificarCreditoExpirando($cl['telefone'], $cl['nome'], $info['credito_disponivel'], $dataExp, $farmaciaId);
            $enviados++;
        }
    }
    registrarAuditoria('alerta_expiracao_massa', "Alertas enviados para $enviados clientes");
    jsonResponse(['sucesso' => true, 'mensagem' => "Alertas enviados para $enviados clientes", 'enviados' => $enviados]);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
