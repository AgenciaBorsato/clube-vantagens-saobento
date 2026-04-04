<?php
// ============================================================
// BipCash SaaS - API Config Cashback Multi-Tenant
// Percentuais por farmacia_id
// ============================================================
require_once __DIR__.'/../includes/db.php';

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();

if ($acao === 'listar') {
    exigirLogin();
    $farmaciaId = getFarmaciaId();
    $ano = intval($input['ano'] ?? $_GET['ano'] ?? date('Y'));
    $stmt = $db->prepare("SELECT mes, percentual FROM cashback_mensal WHERE farmacia_id = ? AND ano = ? ORDER BY mes");
    $stmt->execute([$farmaciaId, $ano]);
    $rows = $stmt->fetchAll();
    $existentes = [];
    foreach ($rows as $r) $existentes[$r['mes']] = floatval($r['percentual']);
    $meses = [];
    for ($m = 1; $m <= 12; $m++) $meses[] = ['mes' => $m, 'percentual' => $existentes[$m] ?? 5.00];
    jsonResponse(['ano' => $ano, 'meses' => $meses, 'cashback_atual' => getCashbackAtual($farmaciaId)]);
}

if ($acao === 'salvar') {
    exigirGerente();
    verificarCSRF();
    $farmaciaId = getFarmaciaId();
    $ano = intval($input['ano'] ?? date('Y'));
    $meses = $input['meses'] ?? [];
    if (!is_array($meses) || count($meses) !== 12) jsonResponse(['sucesso' => false, 'erro' => 'Dados invalidos'], 400);
    $stmt = $db->prepare("INSERT INTO cashback_mensal (farmacia_id, ano, mes, percentual) VALUES (?, ?, ?, ?) ON CONFLICT (farmacia_id, ano, mes) DO UPDATE SET percentual = EXCLUDED.percentual");
    foreach ($meses as $i => $pct) $stmt->execute([$farmaciaId, $ano, $i + 1, max(0, min(100, floatval($pct)))]);
    registrarAuditoria('config_cashback', "Percentuais de cashback alterados para $ano");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Configuracoes salvas com sucesso']);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
