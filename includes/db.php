<?php
// ============================================================
// DROGARIA SAO BENTO - CLUBE DE VANTAGENS v2
// Configuracao e Helpers (PostgreSQL + Railway)
// ============================================================

define('EXPIRACAO_MESES', 3);
define('SENHA_PADRAO', 'saobento2026');
define('MAX_TENTATIVAS_LOGIN', 5);
define('BLOQUEIO_MINUTOS', 15);

// ===== SESSION SECURITY =====
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// ===== HTTPS REDIRECT (Railway proxy) =====
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'http') {
    $host = $_SERVER['HTTP_HOST'];
    $uri = $_SERVER['REQUEST_URI'];
    header("Location: https://$host$uri", true, 301);
    exit;
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $databaseUrl = getenv('DATABASE_URL');
            if ($databaseUrl) {
                $parsed = parse_url($databaseUrl);
                $host = $parsed['host'];
                $port = $parsed['port'] ?? 5432;
                $dbname = ltrim($parsed['path'], '/');
                $user = $parsed['user'];
                $pass = $parsed['pass'];
                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            } else {
                $host = getenv('DB_HOST') ?: 'localhost';
                $port = getenv('DB_PORT') ?: '5432';
                $dbname = getenv('DB_NAME') ?: 'clube_saobento';
                $user = getenv('DB_USER') ?: 'postgres';
                $pass = getenv('DB_PASS') ?: '';
                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            }
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro de conexao com o banco de dados']);
            exit;
        }
    }
    return $pdo;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    $json = file_get_contents('php://input');
    return json_decode($json, true) ?? [];
}

// ===== CSRF PROTECTION =====
function gerarTokenCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verificarCSRF() {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonResponse(['erro' => 'Token de seguranca invalido. Recarregue a pagina.'], 403);
    }
}

// ===== AUTENTICACAO =====
function exigirLogin() {
    if (empty($_SESSION['logado']) || $_SESSION['logado'] !== true) {
        jsonResponse(['erro' => 'Acesso nao autorizado. Faca login novamente.'], 401);
    }
}

function getClientIP() {
    // Railway e outros proxies enviam o IP real via X-Forwarded-For
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function verificarBloqueioLogin() {
    $db = getDB();
    $ip = getClientIP();
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM login_tentativas WHERE ip = ? AND tentativa_em > NOW() - INTERVAL '" . BLOQUEIO_MINUTOS . " minutes'");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if ($row['c'] >= MAX_TENTATIVAS_LOGIN) {
        jsonResponse(['sucesso' => false, 'erro' => 'Muitas tentativas. Aguarde ' . BLOQUEIO_MINUTOS . ' minutos.'], 429);
    }
}

function registrarTentativaLogin() {
    $db = getDB();
    $db->prepare("INSERT INTO login_tentativas (ip) VALUES (?)")->execute([getClientIP()]);
}

function limparTentativasLogin() {
    $db = getDB();
    $db->prepare("DELETE FROM login_tentativas WHERE ip = ?")->execute([getClientIP()]);
    $db->query("DELETE FROM login_tentativas WHERE tentativa_em < NOW() - INTERVAL '1 hour'");
}

function getSenha() {
    $db = getDB();
    $stmt = $db->query("SELECT valor FROM configuracoes WHERE chave = 'senha_acesso'");
    $row = $stmt->fetch();
    if ($row) return $row['valor'];
    $hash = password_hash(SENHA_PADRAO, PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('senha_acesso', ?)")->execute([$hash]);
    return $hash;
}

// ===== VALIDACAO CPF =====
function validarCPF($cpf) {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    // Rejeitar sequencias repetidas (111.111.111-11, etc)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    // Calcular primeiro digito verificador
    $soma = 0;
    for ($i = 0; $i < 9; $i++) $soma += intval($cpf[$i]) * (10 - $i);
    $resto = $soma % 11;
    $d1 = ($resto < 2) ? 0 : 11 - $resto;
    if (intval($cpf[9]) !== $d1) return false;
    // Calcular segundo digito verificador
    $soma = 0;
    for ($i = 0; $i < 10; $i++) $soma += intval($cpf[$i]) * (11 - $i);
    $resto = $soma % 11;
    $d2 = ($resto < 2) ? 0 : 11 - $resto;
    if (intval($cpf[10]) !== $d2) return false;
    return true;
}

// ===== CASHBACK =====
function getCashbackPercentual($ano, $mes) {
    $db = getDB();
    $stmt = $db->prepare("SELECT percentual FROM cashback_mensal WHERE ano = ? AND mes = ?");
    $stmt->execute([$ano, $mes]);
    $row = $stmt->fetch();
    return $row ? floatval($row['percentual']) : 5.00;
}

function getCashbackAtual() {
    return getCashbackPercentual(date('Y'), date('n'));
}

// ===== CREDITO (com expiracao por compra individual) =====
function calcularCreditoCliente($clienteId) {
    $db = getDB();
    $limite = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months'));

    // Cashback de compras NAO expiradas (individual por compra)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(cashback_valor),0) as cashback_valido
        FROM compras
        WHERE cliente_id = ? AND estornada = FALSE AND data_compra >= ?
    ");
    $stmt->execute([$clienteId, $limite]);
    $cashbackValido = floatval($stmt->fetch()['cashback_valido']);

    // Cashback de compras expiradas
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(cashback_valor),0) as cashback_expirado
        FROM compras
        WHERE cliente_id = ? AND estornada = FALSE AND data_compra < ?
    ");
    $stmt->execute([$clienteId, $limite]);
    $cashbackExpirado = floatval($stmt->fetch()['cashback_expirado']);

    // Totais gerais
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(valor),0) as total_compras,
               COALESCE(SUM(cashback_valor),0) as total_cashback,
               COUNT(*) as num_compras,
               MAX(data_compra) as ultima_compra
        FROM compras WHERE cliente_id = ? AND estornada = FALSE
    ");
    $stmt->execute([$clienteId]);
    $compras = $stmt->fetch();

    $stmt = $db->prepare("SELECT COALESCE(SUM(valor),0) as total_resgatado FROM resgates WHERE cliente_id = ? AND estornado = FALSE");
    $stmt->execute([$clienteId]);
    $totalResgatado = floatval($stmt->fetch()['total_resgatado']);

    // Credito disponivel = cashback valido - total resgatado (nunca negativo)
    $creditoDisponivel = max(0, $cashbackValido - $totalResgatado);

    return [
        'total_compras' => floatval($compras['total_compras']),
        'num_compras' => intval($compras['num_compras']),
        'cashback_total' => floatval($compras['total_cashback']),
        'cashback_valido' => $cashbackValido,
        'cashback_expirado' => $cashbackExpirado,
        'total_resgatado' => $totalResgatado,
        'credito_disponivel' => $creditoDisponivel,
        'ultima_compra' => $compras['ultima_compra'],
        'expirado' => ($cashbackValido <= 0 && floatval($compras['total_cashback']) > 0)
    ];
}

// ===== AUDIT TRAIL =====
function registrarAuditoria($acao, $detalhes = '', $entidadeTipo = null, $entidadeId = null) {
    $db = getDB();
    $ip = getClientIP();
    $stmt = $db->prepare("INSERT INTO auditoria (acao, detalhes, entidade_tipo, entidade_id, ip) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$acao, $detalhes, $entidadeTipo, $entidadeId, $ip]);
}

// ===== INICIALIZACAO =====
function inicializarBanco() {
    static $inicializado = false;
    if ($inicializado) return;
    $inicializado = true;

    $db = getDB();
    try {
        $db->query("SELECT 1 FROM configuracoes LIMIT 1");
    } catch (PDOException $e) {
        $sql = file_get_contents(__DIR__ . '/../database.sql');
        $db->exec($sql);
    }
    $stmt = $db->query("SELECT COUNT(*) as c FROM configuracoes WHERE chave = 'senha_acesso'");
    if ($stmt->fetch()['c'] == 0) {
        $hash = password_hash(SENHA_PADRAO, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('senha_acesso', ?)")->execute([$hash]);
    }
}
inicializarBanco();
