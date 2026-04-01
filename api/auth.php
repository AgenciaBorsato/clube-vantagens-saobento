<?php
require_once __DIR__.'/../includes/db.php';

$input = getInput();
$acao = $input['acao'] ?? '';

if ($acao === 'login') {
    verificarBloqueioLogin();
    $senha = $input['senha'] ?? '';
    $hash = getSenha();
    if (password_verify($senha, $hash)) {
        $_SESSION['logado'] = true;
        // Regenerar session ID apos login para prevenir session fixation
        session_regenerate_id(true);
        limparTentativasLogin();
        registrarAuditoria('login', 'Login bem-sucedido');
        jsonResponse(['sucesso' => true, 'csrf_token' => gerarTokenCSRF()]);
    } else {
        registrarTentativaLogin();
        registrarAuditoria('login_falha', 'Tentativa de login com senha incorreta');
        jsonResponse(['sucesso' => false, 'erro' => 'Senha incorreta'], 401);
    }
}

if ($acao === 'verificar') {
    $logado = !empty($_SESSION['logado']);
    $resp = ['logado' => $logado];
    if ($logado) $resp['csrf_token'] = gerarTokenCSRF();
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
    $hash = getSenha();
    if (!password_verify($senhaAtual, $hash)) {
        jsonResponse(['sucesso' => false, 'erro' => 'Senha atual incorreta'], 403);
    }
    if (strlen($novaSenha) < 6) {
        jsonResponse(['sucesso' => false, 'erro' => 'Nova senha deve ter no minimo 6 caracteres'], 400);
    }
    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
    $db = getDB();
    $db->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'senha_acesso'")->execute([$novoHash]);
    registrarAuditoria('alterar_senha', 'Senha de acesso alterada');
    jsonResponse(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso']);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
