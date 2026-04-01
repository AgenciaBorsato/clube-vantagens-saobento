<?php
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/whatsapp.php';
exigirLogin();

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();

if ($acao === 'registrar') {
    verificarCSRF();
    $telefone = preg_replace('/\D/', '', $input['telefone'] ?? '');
    $valor = floatval($input['valor'] ?? 0);
    if (strlen($telefone) < 10) jsonResponse(['sucesso' => false, 'erro' => 'Telefone invalido'], 400);
    if ($valor < 0.01) jsonResponse(['sucesso' => false, 'erro' => 'Valor invalido'], 400);

    $stmt = $db->prepare("SELECT id, nome FROM clientes WHERE telefone = ? AND ativo = TRUE");
    $stmt->execute([$telefone]);
    $cliente = $stmt->fetch();
    if (!$cliente) jsonResponse(['sucesso' => false, 'erro' => 'Cliente nao encontrado'], 404);

    $pct = getCashbackAtual();
    $cashbackValor = round($valor * ($pct / 100), 2);
    $stmt = $db->prepare("INSERT INTO compras (cliente_id, valor, cashback_percentual, cashback_valor) VALUES (?, ?, ?, ?)");
    $stmt->execute([$cliente['id'], $valor, $pct, $cashbackValor]);
    registrarAuditoria('compra', "Compra R$ " . number_format($valor, 2, ',', '.') . " - Cashback R$ " . number_format($cashbackValor, 2, ',', '.'), 'cliente', $cliente['id']);

    // Notificar cliente via WhatsApp
    $info = calcularCreditoCliente($cliente['id']);
    $whatsappResult = notificarCashbackCompra($telefone, $cliente['nome'], $valor, $cashbackValor, $pct, $info['credito_disponivel']);

    jsonResponse([
        'sucesso' => true,
        'mensagem' => 'Compra registrada com sucesso',
        'compra' => ['valor' => $valor, 'cashback_percentual' => $pct, 'cashback_valor' => $cashbackValor, 'cliente_nome' => $cliente['nome']],
        'whatsapp_enviado' => $whatsappResult['sucesso'] ?? false,
    ]);
}

if ($acao === 'preview') {
    $valor = floatval($input['valor'] ?? 0);
    if ($valor < 0.01) jsonResponse(['sucesso' => false, 'erro' => 'Valor invalido'], 400);
    $pct = getCashbackAtual();
    $cashbackValor = round($valor * ($pct / 100), 2);
    jsonResponse(['sucesso' => true, 'valor' => $valor, 'cashback_percentual' => $pct, 'cashback_valor' => $cashbackValor]);
}

if ($acao === 'estornar') {
    verificarCSRF();
    $compraId = intval($input['compra_id'] ?? 0);
    $motivo = trim($input['motivo'] ?? 'Estorno administrativo');
    if (!$compraId) jsonResponse(['sucesso' => false, 'erro' => 'ID da compra invalido'], 400);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM compras WHERE id = ? AND estornada = FALSE FOR UPDATE");
        $stmt->execute([$compraId]);
        $compra = $stmt->fetch();
        if (!$compra) {
            $db->rollBack();
            jsonResponse(['sucesso' => false, 'erro' => 'Compra nao encontrada ou ja estornada'], 404);
        }

        // Verificar se o cashback desta compra ja foi resgatado
        $info = calcularCreditoCliente($compra['cliente_id']);
        $cashbackCompra = floatval($compra['cashback_valor']);

        // Se estornar esta compra reduziria o credito abaixo do ja resgatado, bloquear
        if ($info['credito_disponivel'] < $cashbackCompra && $info['total_resgatado'] > 0) {
            $db->rollBack();
            jsonResponse([
                'sucesso' => false,
                'erro' => 'Nao e possivel estornar: o cashback desta compra (R$ ' . number_format($cashbackCompra, 2, ',', '.') . ') ja foi parcialmente resgatado. Credito disponivel atual: R$ ' . number_format($info['credito_disponivel'], 2, ',', '.')
            ], 400);
        }

        $db->prepare("UPDATE compras SET estornada = TRUE, data_estorno = NOW(), motivo_estorno = ? WHERE id = ?")->execute([$motivo, $compraId]);
        registrarAuditoria('estorno', "Compra #$compraId estornada. Motivo: $motivo. Valor: R$ " . number_format(floatval($compra['valor']), 2, ',', '.'), 'cliente', $compra['cliente_id']);
        $db->commit();
        jsonResponse(['sucesso' => true, 'mensagem' => 'Compra estornada com sucesso']);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['sucesso' => false, 'erro' => 'Erro ao processar estorno'], 500);
    }
}

if ($acao === 'historico') {
    $clienteId = intval($input['cliente_id'] ?? $_GET['cliente_id'] ?? 0);
    if (!$clienteId) jsonResponse(['sucesso' => false, 'erro' => 'ID do cliente invalido'], 400);

    $stmt = $db->prepare("SELECT * FROM compras WHERE cliente_id = ? ORDER BY data_compra ASC");
    $stmt->execute([$clienteId]);
    $compras = $stmt->fetchAll();
    $totalValor = 0;
    $totalCashback = 0;
    foreach ($compras as &$c) {
        $c['valor'] = floatval($c['valor']);
        $c['cashback_percentual'] = floatval($c['cashback_percentual']);
        $c['cashback_valor'] = floatval($c['cashback_valor']);
        $c['estornada'] = (bool)$c['estornada'];
        if (!$c['estornada']) {
            $totalValor += $c['valor'];
            $totalCashback += $c['cashback_valor'];
        }
    }
    jsonResponse(['sucesso' => true, 'compras' => $compras, 'total_valor' => $totalValor, 'total_cashback' => $totalCashback]);
}

if ($acao === 'ultimas') {
    $limite = min(50, max(1, intval($_GET['limite'] ?? 10)));
    $stmt = $db->prepare("SELECT c.*, cl.nome, cl.telefone FROM compras c JOIN clientes cl ON cl.id = c.cliente_id WHERE c.estornada = FALSE ORDER BY c.data_compra DESC LIMIT ?");
    $stmt->execute([$limite]);
    jsonResponse(['compras' => $stmt->fetchAll()]);
}

if ($acao === 'dashboard') {
    // Dashboard consolidado em uma unica query com CTEs
    $mesAtual = intval(date('n'));
    $anoAtual = intval(date('Y'));

    $stmt = $db->prepare("
        WITH stats_clientes AS (
            SELECT COUNT(*) as total_clientes FROM clientes WHERE ativo = TRUE
        ),
        stats_compras AS (
            SELECT COUNT(*) as total_compras,
                   COALESCE(SUM(valor),0) as total_vendas,
                   COALESCE(SUM(cashback_valor),0) as total_cashback_gerado
            FROM compras WHERE estornada = FALSE
        ),
        stats_mes AS (
            SELECT COALESCE(SUM(valor),0) as vendas_mes
            FROM compras
            WHERE estornada = FALSE
              AND EXTRACT(MONTH FROM data_compra) = ?
              AND EXTRACT(YEAR FROM data_compra) = ?
        ),
        stats_resgates AS (
            SELECT COALESCE(SUM(valor),0) as total_resgatado
            FROM resgates WHERE estornado = FALSE
        )
        SELECT c.total_clientes, p.total_compras, p.total_vendas,
               m.vendas_mes, p.total_cashback_gerado, r.total_resgatado
        FROM stats_clientes c, stats_compras p, stats_mes m, stats_resgates r
    ");
    $stmt->execute([$mesAtual, $anoAtual]);
    $row = $stmt->fetch();

    jsonResponse([
        'total_clientes' => intval($row['total_clientes']),
        'total_compras' => intval($row['total_compras']),
        'total_vendas' => floatval($row['total_vendas']),
        'vendas_mes' => floatval($row['vendas_mes']),
        'cashback_atual' => getCashbackAtual(),
        'total_cashback_gerado' => floatval($row['total_cashback_gerado']),
        'total_resgatado' => floatval($row['total_resgatado'])
    ]);
}

if ($acao === 'relatorio_mensal') {
    $ano = intval($_GET['ano'] ?? date('Y'));
    $stmt = $db->prepare("
        SELECT EXTRACT(MONTH FROM data_compra)::int as mes,
               COUNT(*) as num_compras,
               SUM(valor) as total_vendas,
               SUM(cashback_valor) as total_cashback
        FROM compras
        WHERE EXTRACT(YEAR FROM data_compra) = ? AND estornada = FALSE
        GROUP BY EXTRACT(MONTH FROM data_compra)
        ORDER BY mes
    ");
    $stmt->execute([$ano]);
    $dados = $stmt->fetchAll();
    $meses = [];
    $existentes = [];
    foreach ($dados as $d) $existentes[$d['mes']] = $d;
    for ($m = 1; $m <= 12; $m++) {
        $meses[] = [
            'mes' => $m,
            'num_compras' => intval($existentes[$m]['num_compras'] ?? 0),
            'total_vendas' => floatval($existentes[$m]['total_vendas'] ?? 0),
            'total_cashback' => floatval($existentes[$m]['total_cashback'] ?? 0)
        ];
    }
    jsonResponse(['ano' => $ano, 'meses' => $meses]);
}

if ($acao === 'ranking_clientes') {
    $limite = min(50, max(5, intval($_GET['limite'] ?? 20)));
    $stmt = $db->prepare("
        SELECT cl.id, cl.nome, cl.telefone,
               COUNT(c.id) as num_compras,
               COALESCE(SUM(c.valor),0) as total_compras
        FROM clientes cl
        LEFT JOIN compras c ON c.cliente_id = cl.id AND c.estornada = FALSE
        WHERE cl.ativo = TRUE
        GROUP BY cl.id, cl.nome, cl.telefone
        ORDER BY total_compras DESC
        LIMIT ?
    ");
    $stmt->execute([$limite]);
    jsonResponse(['ranking' => $stmt->fetchAll()]);
}

if ($acao === 'exportar_clientes') {
    $clientes = $db->query("SELECT c.nome, c.cpf, c.telefone, c.data_cadastro FROM clientes c WHERE c.ativo = TRUE ORDER BY c.nome")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=clientes_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Nome', 'CPF', 'Telefone', 'Data Cadastro'], ';');
    foreach ($clientes as $cl) {
        $cl['cpf'] = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cl['cpf']);
        $cl['telefone'] = preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $cl['telefone']);
        fputcsv($out, $cl, ';');
    }
    fclose($out);
    exit;
}

if ($acao === 'exportar_compras') {
    $stmt = $db->query("
        SELECT cl.nome, cl.telefone, c.valor, c.cashback_percentual, c.cashback_valor, c.data_compra,
               CASE WHEN c.estornada = TRUE THEN 'ESTORNADA' ELSE 'OK' END as status
        FROM compras c JOIN clientes cl ON cl.id = c.cliente_id
        ORDER BY c.data_compra DESC
    ");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=compras_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Cliente', 'Telefone', 'Valor', 'Cashback %', 'Cashback R$', 'Data', 'Status'], ';');
    while ($row = $stmt->fetch()) {
        $row['telefone'] = preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $row['telefone']);
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

jsonResponse(['erro' => 'Acao invalida'], 400);
