<?php
// ============================================================
// BipCash SaaS - API Super Admin
// Gerenciamento de farmacias e dashboard global
// ============================================================
require_once __DIR__.'/../includes/db.php';
exigirSuperAdmin();

$input = getInput();
$acao = $input['acao'] ?? $_GET['acao'] ?? '';
$db = getDB();

if ($acao === 'dashboard') {
    $stmt = $db->query("
        SELECT
            (SELECT COUNT(*) FROM farmacias WHERE ativa = TRUE) as total_farmacias,
            (SELECT COUNT(*) FROM clientes WHERE ativo = TRUE) as total_clientes,
            (SELECT COUNT(*) FROM compras WHERE estornada = FALSE) as total_compras,
            (SELECT COALESCE(SUM(valor),0) FROM compras WHERE estornada = FALSE) as total_vendas,
            (SELECT COUNT(*) FROM usuarios WHERE ativo = TRUE) as total_usuarios
    ");
    jsonResponse($stmt->fetch());
}

if ($acao === 'listar_farmacias') {
    $stmt = $db->query("
        SELECT f.*,
               (SELECT COUNT(*) FROM clientes c WHERE c.farmacia_id = f.id AND c.ativo = TRUE) as total_clientes,
               (SELECT COUNT(*) FROM compras co WHERE co.farmacia_id = f.id AND co.estornada = FALSE) as total_compras,
               (SELECT COALESCE(SUM(co.valor),0) FROM compras co WHERE co.farmacia_id = f.id AND co.estornada = FALSE) as total_vendas,
               (SELECT COUNT(*) FROM usuarios u WHERE u.farmacia_id = f.id AND u.ativo = TRUE) as total_usuarios
        FROM farmacias f
        ORDER BY f.nome
    ");
    $farmacias = $stmt->fetchAll();
    // Remove logo_base64 from listing (too heavy)
    foreach ($farmacias as &$f) { unset($f['logo_base64']); }
    jsonResponse(['farmacias' => $farmacias]);
}

if ($acao === 'criar_farmacia') {
    verificarCSRF();
    $nome = trim($input['nome'] ?? '');
    $slug = trim($input['slug'] ?? '');
    $corPrimaria = $input['cor_primaria'] ?? '#2196f3';
    $corSecundaria = $input['cor_secundaria'] ?? '#0a2540';
    $senhaAdmin = $input['senha_admin'] ?? 'admin123';
    $usernameAdmin = trim($input['username_admin'] ?? 'admin');

    if (!$nome) jsonResponse(['sucesso' => false, 'erro' => 'Nome obrigatorio'], 400);
    if (!$slug) $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $nome)));

    // Verificar slug unico
    $stmt = $db->prepare("SELECT id FROM farmacias WHERE slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'Slug ja existe'], 400);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO farmacias (nome, slug, cor_primaria, cor_secundaria) VALUES (?, ?, ?, ?) RETURNING id");
        $stmt->execute([$nome, $slug, $corPrimaria, $corSecundaria]);
        $farmaciaId = $stmt->fetch()['id'];

        // Criar admin da farmacia
        $hash = password_hash($senhaAdmin, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO usuarios (farmacia_id, username, password_hash, nome, role) VALUES (?, ?, ?, ?, 'gerente')")->execute([$farmaciaId, $usernameAdmin, $hash, "Gerente $nome"]);

        $db->commit();
        registrarAuditoria('criar_farmacia', "Farmacia '$nome' criada (slug: $slug)");
        jsonResponse(['sucesso' => true, 'mensagem' => 'Farmacia criada com sucesso', 'farmacia_id' => $farmaciaId]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['sucesso' => false, 'erro' => 'Erro ao criar farmacia'], 500);
    }
}

if ($acao === 'editar_farmacia') {
    verificarCSRF();
    $id = intval($input['id'] ?? 0);
    $nome = trim($input['nome'] ?? '');
    $corPrimaria = $input['cor_primaria'] ?? null;
    $corSecundaria = $input['cor_secundaria'] ?? null;
    $ativa = isset($input['ativa']) ? (bool)$input['ativa'] : true;
    $logo = $input['logo_base64'] ?? null;
    $whatsappUrl = $input['whatsapp_url'] ?? null;
    $whatsappInstance = $input['whatsapp_instance'] ?? null;
    $whatsappToken = $input['whatsapp_token'] ?? null;
    $whatsappEnabled = isset($input['whatsapp_enabled']) ? (bool)$input['whatsapp_enabled'] : null;

    if (!$id || !$nome) jsonResponse(['sucesso' => false, 'erro' => 'Dados invalidos'], 400);

    $sql = "UPDATE farmacias SET nome = ?, ativa = ?";
    $params = [$nome, $ativa];
    if ($corPrimaria) { $sql .= ", cor_primaria = ?"; $params[] = $corPrimaria; }
    if ($corSecundaria) { $sql .= ", cor_secundaria = ?"; $params[] = $corSecundaria; }
    if ($logo !== null) { $sql .= ", logo_base64 = ?"; $params[] = $logo; }
    if ($whatsappUrl !== null) { $sql .= ", whatsapp_url = ?"; $params[] = $whatsappUrl; }
    if ($whatsappInstance !== null) { $sql .= ", whatsapp_instance = ?"; $params[] = $whatsappInstance; }
    if ($whatsappToken !== null) { $sql .= ", whatsapp_token = ?"; $params[] = $whatsappToken; }
    if ($whatsappEnabled !== null) { $sql .= ", whatsapp_enabled = ?"; $params[] = $whatsappEnabled; }
    $sql .= " WHERE id = ?";
    $params[] = $id;

    $db->prepare($sql)->execute($params);
    registrarAuditoria('editar_farmacia', "Farmacia #$id atualizada");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Farmacia atualizada']);
}

if ($acao === 'obter_farmacia') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['sucesso' => false, 'erro' => 'ID invalido'], 400);
    $stmt = $db->prepare("SELECT * FROM farmacias WHERE id = ?");
    $stmt->execute([$id]);
    $farmacia = $stmt->fetch();
    if (!$farmacia) jsonResponse(['sucesso' => false, 'erro' => 'Farmacia nao encontrada'], 404);
    jsonResponse(['farmacia' => $farmacia]);
}

if ($acao === 'stats_farmacia') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['sucesso' => false, 'erro' => 'ID invalido'], 400);
    $mesAtual = intval(date('n'));
    $anoAtual = intval(date('Y'));

    $stmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM clientes WHERE farmacia_id = ? AND ativo = TRUE) as total_clientes,
            (SELECT COUNT(*) FROM compras WHERE farmacia_id = ? AND estornada = FALSE) as total_compras,
            (SELECT COALESCE(SUM(valor),0) FROM compras WHERE farmacia_id = ? AND estornada = FALSE) as total_vendas,
            (SELECT COALESCE(SUM(valor),0) FROM compras WHERE farmacia_id = ? AND estornada = FALSE AND EXTRACT(MONTH FROM data_compra) = ? AND EXTRACT(YEAR FROM data_compra) = ?) as vendas_mes,
            (SELECT COALESCE(SUM(cashback_valor),0) FROM compras WHERE farmacia_id = ? AND estornada = FALSE) as total_cashback,
            (SELECT COUNT(*) FROM usuarios WHERE farmacia_id = ? AND ativo = TRUE) as total_usuarios
    ");
    $stmt->execute([$id, $id, $id, $id, $mesAtual, $anoAtual, $id, $id]);
    jsonResponse($stmt->fetch());
}

jsonResponse(['erro' => 'Acao invalida'], 400);
