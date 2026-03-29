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
        limparTentativasLogin();
        jsonResponse(['sucesso' => true]);
    } else {
        registrarTentativaLogin();
        jsonResponse(['sucesso' => false, 'erro' => 'Senha incorreta'], 401);
    }
}

if ($acao === 'verificar') {
    jsonResponse(['logado' => !empty($_SESSION['logado'])]);
}

if ($acao === 'logout') {
    session_destroy();
    jsonResponse(['sucesso' => true]);
}

if ($acao === 'alterar_senha') {
    exigirLogin();
    $senhaAtual = $input['senha_atual'] ?? '';
    $novaSenha = $input['nova_senha'] ?? '';
    $hash = getSenha();
    if (!password_verify($senhaAtual, $hash)) {
        jsonResponse(['sucesso' => false, 'erro' => 'Senha atual incorreta'], 403);
    }
    if (strlen($novaSenha) < 4) {
        jsonResponse(['sucesso' => false, 'erro' => 'Nova senha deve ter no minimo 4 caracteres'], 400);
    }
    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
    $db = getDB();
    $db->prepare("UPDATE configuracoes SET valor = ? WHERE chave = 'senha_acesso'")->execute([$novoHash]);
    jsonResponse(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso']);
}

jsonResponse(['erro' => 'Acao invalida'], 400);
