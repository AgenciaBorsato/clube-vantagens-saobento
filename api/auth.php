<?php
// ============================================================
// BipCash SaaS - Autenticacao Multi-Tenant
// Login dual: super_admins + usuarios de farmacia
// ============================================================
require_once __DIR__.'/../includes/db.php';

$input = getInput();
$acao = $input['acao'] ?? '';

if ($acao === 'login') {
    verificarBloqueioLogin();
    $username = trim($input['username'] ?? '');
    $senha = $input['senha'] ?? '';

    if (!$username || !$senha) {
        jsonResponse(['sucesso' => false, 'erro' => 'Preencha usuario e senha'], 400);
    }

    $db = getDB();

    // 1. Verificar super admin
    $stmt = $db->prepare("SELECT * FROM super_admins WHERE username = ? AND ativo = TRUE");
    $stmt->execute([$username]);
    $superAdmin = $stmt->fetch();

    if ($superAdmin && password_verify($senha, $superAdmin['password_hash'])) {
        $_SESSION['logado'] = true;
        $_SESSION['is_super_admin'] = true;
        $_SESSION['super_admin_id'] = $superAdmin['id'];
        $_SESSION['usuario_nome'] = $superAdmin['nome'];
        $_SESSION['farmacia_id'] = null; // Super admin nao pertence a farmacia
        $_SESSION['usuario_id'] = null;
        $_SESSION['usuario_role'] = 'super_admin';
        session_regenerate_id(true);
        limparTentativasLogin();
        $db->prepare("UPDATE super_admins SET ultimo_login = NOW() WHERE id = ?")->execute([$superAdmin['id']]);
        registrarAuditoria('login_super', "Super Admin: {$superAdmin['nome']}");
        jsonResponse([
            'sucesso' => true,
            'csrf_token' => gerarTokenCSRF(),
            'usuario' => ['nome' => $superAdmin['nome'], 'role' => 'super_admin'],
            'tipo' => 'super_admin'
        ]);
    }

    // 2. Verificar usuario de farmacia
    $stmt = $db->prepare("
        SELECT u.*, f.nome as farmacia_nome, f.slug as farmacia_slug,
               f.logo_base64, f.cor_primaria, f.cor_secundaria, f.ativa as farmacia_ativa
        FROM usuarios u
        JOIN farmacias f ON f.id = u.farmacia_id
        WHERE u.username = ? AND u.ativo = TRUE AND f.ativa = TRUE
    ");
    $stmt->execute([$username]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($senha, $usuario['password_hash'])) {
        $_SESSION['logado'] = true;
        $_SESSION['is_super_admin'] = false;
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_role'] = $usuario['role'];
        $_SESSION['farmacia_id'] = $usuario['farmacia_id'];
        session_regenerate_id(true);
        limparTentativasLogin();
        $db->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")->execute([$usuario['id']]);
        registrarAuditoria('login', "Login: {$usuario['nome']} ({$usuario['role']})");
        jsonResponse([
            'sucesso' => true,
            'csrf_token' => gerarTokenCSRF(),
            'usuario' => ['nome' => $usuario['nome'], 'role' => $usuario['role']],
            'farmacia' => [
                'id' => $usuario['farmacia_id'],
                'nome' => $usuario['farmacia_nome'],
                'slug' => $usuario['farmacia_slug'],
                'logo' => $usuario['logo_base64'],
                'cor_primaria' => $usuario['cor_primaria'],
                'cor_secundaria' => $usuario['cor_secundaria']
            ],
            'tipo' => 'farmacia'
        ]);
    }

    registrarTentativaLogin();
    registrarAuditoria('login_falha', "Tentativa com usuario: $username");
    jsonResponse(['sucesso' => false, 'erro' => 'Usuario ou senha incorretos'], 401);
}

if ($acao === 'verificar') {
    $logado = !empty($_SESSION['logado']);
    $resp = ['logado' => $logado];
    if ($logado) {
        $resp['csrf_token'] = gerarTokenCSRF();
        $resp['usuario'] = [
            'nome' => $_SESSION['usuario_nome'] ?? '',
            'role' => $_SESSION['usuario_role'] ?? ''
        ];
        if (!empty($_SESSION['is_super_admin'])) {
            $resp['tipo'] = 'super_admin';
            if (!empty($_SESSION['farmacia_id'])) {
                $db = getDB();
                $stmt = $db->prepare("SELECT id, nome, slug, logo_base64, cor_primaria, cor_secundaria FROM farmacias WHERE id = ?");
                $stmt->execute([$_SESSION['farmacia_id']]);
                $resp['farmacia'] = $stmt->fetch();
                $resp['impersonando'] = !empty($_SESSION['impersonando']);
            }
        } else {
            $resp['tipo'] = 'farmacia';
            $db = getDB();
            $stmt = $db->prepare("SELECT id, nome, slug, logo_base64, cor_primaria, cor_secundaria FROM farmacias WHERE id = ?");
            $stmt->execute([$_SESSION['farmacia_id']]);
            $resp['farmacia'] = $stmt->fetch();
        }
    }
    jsonResponse($resp);
}

if ($acao === 'logout') {
    registrarAuditoria('logout', 'Logout realizado');
    session_destroy();
    jsonResponse(['sucesso' => true]);
}

if ($acao === 'alterar_senha') {
    $senhaAtual = $input['senha_atual'] ?? '';
    $novaSenha = $input['nova_senha'] ?? '';
    if (strlen($novaSenha) < 6) jsonResponse(['sucesso' => false, 'erro' => 'Nova senha deve ter no minimo 6 caracteres'], 400);

    $db = getDB();

    if (!empty($_SESSION['is_super_admin'])) {
        // Super admin changing own password
        $stmt = $db->prepare("SELECT password_hash FROM super_admins WHERE id = ?");
        $stmt->execute([$_SESSION['super_admin_id']]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($senhaAtual, $u['password_hash'])) {
            jsonResponse(['sucesso' => false, 'erro' => 'Senha atual incorreta'], 403);
        }
        $db->prepare("UPDATE super_admins SET password_hash = ? WHERE id = ?")->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $_SESSION['super_admin_id']]);
    } else {
        exigirLogin();
        verificarCSRF();
        $stmt = $db->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
        $stmt->execute([$_SESSION['usuario_id']]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($senhaAtual, $u['password_hash'])) {
            jsonResponse(['sucesso' => false, 'erro' => 'Senha atual incorreta'], 403);
        }
        $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $_SESSION['usuario_id']]);
    }
    registrarAuditoria('alterar_senha', 'Senha alterada');
    jsonResponse(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso']);
}

// ===== IMPERSONAR FARMACIA (super admin only) =====
if ($acao === 'impersonar') {
    exigirSuperAdmin();
    $farmaciaId = intval($input['farmacia_id'] ?? 0);
    if (!$farmaciaId) jsonResponse(['sucesso' => false, 'erro' => 'Farmacia invalida'], 400);
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM farmacias WHERE id = ? AND ativa = TRUE");
    $stmt->execute([$farmaciaId]);
    $farmacia = $stmt->fetch();
    if (!$farmacia) jsonResponse(['sucesso' => false, 'erro' => 'Farmacia nao encontrada'], 404);

    $_SESSION['farmacia_id'] = $farmaciaId;
    $_SESSION['impersonando'] = true;
    registrarAuditoria('impersonar', "Impersonando farmacia: {$farmacia['nome']}");
    jsonResponse([
        'sucesso' => true,
        'farmacia' => [
            'id' => $farmacia['id'],
            'nome' => $farmacia['nome'],
            'slug' => $farmacia['slug'],
            'logo' => $farmacia['logo_base64'],
            'cor_primaria' => $farmacia['cor_primaria'],
            'cor_secundaria' => $farmacia['cor_secundaria']
        ]
    ]);
}

if ($acao === 'sair_impersonacao') {
    exigirSuperAdmin();
    $_SESSION['farmacia_id'] = null;
    $_SESSION['impersonando'] = false;
    jsonResponse(['sucesso' => true]);
}

// ===== GERENCIAMENTO DE USUARIOS (gerente only) =====
if ($acao === 'listar_usuarios') {
    exigirGerente();
    $farmaciaId = getFarmaciaId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, nome, role, ativo, criado_em, ultimo_login FROM usuarios WHERE farmacia_id = ? ORDER BY nome");
    $stmt->execute([$farmaciaId]);
    jsonResponse(['usuarios' => $stmt->fetchAll()]);
}

if ($acao === 'criar_usuario') {
    exigirGerente();
    verificarCSRF();
    $farmaciaId = getFarmaciaId();
    $username = trim($input['username'] ?? '');
    $nome = trim($input['nome'] ?? '');
    $senha = $input['senha'] ?? '';
    $role = $input['role'] ?? 'operador';

    if (!$username || !$nome || !$senha) jsonResponse(['sucesso' => false, 'erro' => 'Preencha todos os campos'], 400);
    if (strlen($senha) < 6) jsonResponse(['sucesso' => false, 'erro' => 'Senha minimo 6 caracteres'], 400);
    if (!in_array($role, ['operador', 'gerente'])) $role = 'operador';

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE farmacia_id = ? AND username = ?");
    $stmt->execute([$farmaciaId, $username]);
    if ($stmt->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'Usuario ja existe nesta farmacia'], 400);

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO usuarios (farmacia_id, username, password_hash, nome, role) VALUES (?, ?, ?, ?, ?)")->execute([$farmaciaId, $username, $hash, $nome, $role]);
    registrarAuditoria('criar_usuario', "Usuario '$username' criado como $role");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Usuario criado com sucesso']);
}

if ($acao === 'editar_usuario') {
    exigirGerente();
    verificarCSRF();
    $farmaciaId = getFarmaciaId();
    $id = intval($input['id'] ?? 0);
    $nome = trim($input['nome'] ?? '');
    $role = $input['role'] ?? 'operador';
    $ativo = isset($input['ativo']) ? (bool)$input['ativo'] : true;

    if (!$id || !$nome) jsonResponse(['sucesso' => false, 'erro' => 'Dados invalidos'], 400);
    if (!in_array($role, ['operador', 'gerente'])) $role = 'operador';

    $db = getDB();
    // Prevenir desativar/rebaixar ultimo gerente ativo
    if ($role !== 'gerente' || !$ativo) {
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM usuarios WHERE farmacia_id = ? AND role = 'gerente' AND ativo = TRUE AND id != ?");
        $stmt->execute([$farmaciaId, $id]);
        if ($stmt->fetch()['c'] == 0) {
            jsonResponse(['sucesso' => false, 'erro' => 'Deve haver pelo menos um gerente ativo'], 400);
        }
    }

    $db->prepare("UPDATE usuarios SET nome = ?, role = ?, ativo = ? WHERE id = ? AND farmacia_id = ?")->execute([$nome, $role, $ativo, $id, $farmaciaId]);
    registrarAuditoria('editar_usuario', "Usuario #$id atualizado");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Usuario atualizado']);
}

if ($acao === 'resetar_senha_usuario') {
    exigirGerente();
    verificarCSRF();
    $farmaciaId = getFarmaciaId();
    $id = intval($input['id'] ?? 0);
    $novaSenha = $input['nova_senha'] ?? '';
    if (!$id || strlen($novaSenha) < 6) jsonResponse(['sucesso' => false, 'erro' => 'Senha minimo 6 caracteres'], 400);
    $db = getDB();
    $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
    $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ? AND farmacia_id = ?")->execute([$hash, $id, $farmaciaId]);
    registrarAuditoria('resetar_senha', "Senha do usuario #$id resetada");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Senha resetada com sucesso']);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
