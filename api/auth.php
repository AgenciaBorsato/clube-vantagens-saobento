<?php
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
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE username = ? AND ativo = TRUE");
    $stmt->execute([$username]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($senha, $usuario['password_hash'])) {
        $_SESSION['logado'] = true;
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_role'] = $usuario['role'];
        session_regenerate_id(true);
        limparTentativasLogin();
        $db->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")->execute([$usuario['id']]);
        registrarAuditoria('login', "Login: {$usuario['nome']} ({$usuario['role']})");
        jsonResponse([
            'sucesso' => true,
            'csrf_token' => gerarTokenCSRF(),
            'usuario' => ['nome' => $usuario['nome'], 'role' => $usuario['role']]
        ]);
    } else {
        registrarTentativaLogin();
        registrarAuditoria('login_falha', "Tentativa com usuario: $username");
        jsonResponse(['sucesso' => false, 'erro' => 'Usuario ou senha incorretos'], 401);
    }
}

if ($acao === 'verificar') {
    $logado = !empty($_SESSION['logado']) && !empty($_SESSION['usuario_id']);
    $resp = ['logado' => $logado];
    if ($logado) {
        $resp['csrf_token'] = gerarTokenCSRF();
        $resp['usuario'] = [
            'nome' => $_SESSION['usuario_nome'] ?? '',
            'role' => $_SESSION['usuario_role'] ?? ''
        ];
    }
    jsonResponse($resp);
}

if ($acao === 'logout') {
    registrarAuditoria('logout', 'Logout realizado');
    session_destroy();
    jsonResponse(['sucesso' => true]);
}

if ($acao === 'alterar_senha') {
    exigirLogin();
    verificarCSRF();
    $senhaAtual = $input['senha_atual'] ?? '';
    $novaSenha = $input['nova_senha'] ?? '';

    $db = getDB();
    $stmt = $db->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senhaAtual, $usuario['password_hash'])) {
        jsonResponse(['sucesso' => false, 'erro' => 'Senha atual incorreta'], 403);
    }
    if (strlen($novaSenha) < 6) {
        jsonResponse(['sucesso' => false, 'erro' => 'Nova senha deve ter no minimo 6 caracteres'], 400);
    }
    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
    $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$novoHash, $_SESSION['usuario_id']]);
    registrarAuditoria('alterar_senha', 'Senha alterada');
    jsonResponse(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso']);
}

// ===== GERENCIAMENTO DE USUARIOS (gerente only) =====
if ($acao === 'listar_usuarios') {
    exigirGerente();
    $db = getDB();
    $stmt = $db->query("SELECT id, username, nome, role, ativo, criado_em, ultimo_login FROM usuarios ORDER BY nome");
    jsonResponse(['usuarios' => $stmt->fetchAll()]);
}

if ($acao === 'criar_usuario') {
    exigirGerente();
    verificarCSRF();
    $username = trim($input['username'] ?? '');
    $nome = trim($input['nome'] ?? '');
    $senha = $input['senha'] ?? '';
    $role = $input['role'] ?? 'operador';

    if (!$username || !$nome || !$senha) jsonResponse(['sucesso' => false, 'erro' => 'Preencha todos os campos'], 400);
    if (strlen($senha) < 6) jsonResponse(['sucesso' => false, 'erro' => 'Senha minimo 6 caracteres'], 400);
    if (!in_array($role, ['operador', 'gerente'])) $role = 'operador';

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) jsonResponse(['sucesso' => false, 'erro' => 'Usuario ja existe'], 400);

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO usuarios (username, password_hash, nome, role) VALUES (?, ?, ?, ?)")->execute([$username, $hash, $nome, $role]);
    registrarAuditoria('criar_usuario', "Usuario '$username' criado como $role");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Usuario criado com sucesso']);
}

if ($acao === 'editar_usuario') {
    exigirGerente();
    verificarCSRF();
    $id = intval($input['id'] ?? 0);
    $nome = trim($input['nome'] ?? '');
    $role = $input['role'] ?? 'operador';
    $ativo = isset($input['ativo']) ? (bool)$input['ativo'] : true;

    if (!$id || !$nome) jsonResponse(['sucesso' => false, 'erro' => 'Dados invalidos'], 400);
    if (!in_array($role, ['operador', 'gerente'])) $role = 'operador';

    $db = getDB();
    // Prevenir desativar/rebaixar ultimo gerente ativo
    if ($role !== 'gerente' || !$ativo) {
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM usuarios WHERE role = 'gerente' AND ativo = TRUE AND id != ?");
        $stmt->execute([$id]);
        if ($stmt->fetch()['c'] == 0) {
            jsonResponse(['sucesso' => false, 'erro' => 'Nao e possivel: deve haver pelo menos um gerente ativo'], 400);
        }
    }

    $db->prepare("UPDATE usuarios SET nome = ?, role = ?, ativo = ? WHERE id = ?")->execute([$nome, $role, $ativo, $id]);
    registrarAuditoria('editar_usuario', "Usuario #$id atualizado: $nome ($role)");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Usuario atualizado']);
}

if ($acao === 'resetar_senha_usuario') {
    exigirGerente();
    verificarCSRF();
    $id = intval($input['id'] ?? 0);
    $novaSenha = $input['nova_senha'] ?? '';
    if (!$id || strlen($novaSenha) < 6) jsonResponse(['sucesso' => false, 'erro' => 'Senha minimo 6 caracteres'], 400);
    $db = getDB();
    $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
    $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
    registrarAuditoria('resetar_senha', "Senha do usuario #$id resetada");
    jsonResponse(['sucesso' => true, 'mensagem' => 'Senha resetada com sucesso']);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
