<?php
// ============================================================
// DROGARIA SAO BENTO - CLUBE DE VANTAGENS v2
// Configuracao e Helpers (PostgreSQL + Railway)
// ============================================================

define('EXPIRACAO_MESES', 3);
define('SENHA_PADRAO', 'saobento2026');
define('MAX_TENTATIVAS_LOGIN', 5);
define('BLOQUEIO_MINUTOS', 15);

session_start();

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $databaseUrl = getenv('DATABASE_URL');
            if ($databaseUrl) {
                // Railway fornece DATABASE_URL automaticamente
                $parsed = parse_url($databaseUrl);
                $host = $parsed['host'];
                $port = $parsed['port'] ?? 5432;
                $dbname = ltrim($parsed['path'], '/');
                $user = $parsed['user'];
                $pass = $parsed['pass'];
                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
            } else {
                // Desenvolvimento local
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

// ===== AUTENTICACAO =====
function exigirLogin() {
    if (empty($_SESSION['logado']) || $_SESSION['logado'] !== true) {
        jsonResponse(['erro' => 'Acesso nao autorizado. Faca login novamente.'], 401);
    }
}

function getClientIP() {
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

// ===== CREDITO =====
function calcularCreditoCliente($clienteId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COALESCE(SUM(cashback_valor),0) as total_cashback, COALESCE(SUM(valor),0) as total_compras, COUNT(*) as num_compras, MAX(data_compra) as ultima_compra FROM compras WHERE cliente_id = ? AND estornada = FALSE");
    $stmt->execute([$clienteId]);
    $compras = $stmt->fetch();

    $stmt = $db->prepare("SELECT COALESCE(SUM(valor),0) as total_resgatado FROM resgates WHERE cliente_id = ? AND estornado = FALSE");
    $stmt->execute([$clienteId]);
    $resgates = $stmt->fetch();

    $creditoTotal = floatval($compras['total_cashback']);
    $totalResgatado = floatval($resgates['total_resgatado']);
    $creditoDisponivel = max(0, $creditoTotal - $totalResgatado);

    $expirado = false;
    if ($compras['ultima_compra']) {
        $limite = date('Y-m-d H:i:s', strtotime('-' . EXPIRACAO_MESES . ' months'));
        if ($compras['ultima_compra'] < $limite) {
            $expirado = true;
            $creditoDisponivel = 0;
        }
    }

    return [
        'total_compras' => floatval($compras['total_compras']),
        'num_compras' => intval($compras['num_compras']),
        'cashback_total' => $creditoTotal,
        'total_resgatado' => $totalResgatado,
        'credito_disponivel' => $creditoDisponivel,
        'ultima_compra' => $compras['ultima_compra'],
        'expirado' => $expirado
    ];
}

// Inicializar banco
function inicializarBanco() {
    $db = getDB();
    // Executar schema se tabelas nao existem
    try {
        $db->query("SELECT 1 FROM configuracoes LIMIT 1");
    } catch (PDOException $e) {
        $sql = file_get_contents(__DIR__ . '/../database.sql');
        $db->exec($sql);
    }
    // Inserir senha padrao se nao existe
    $stmt = $db->query("SELECT COUNT(*) as c FROM configuracoes WHERE chave = 'senha_acesso'");
    if ($stmt->fetch()['c'] == 0) {
        $hash = password_hash(SENHA_PADRAO, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('senha_acesso', ?)")->execute([$hash]);
    }
}
inicializarBanco();
