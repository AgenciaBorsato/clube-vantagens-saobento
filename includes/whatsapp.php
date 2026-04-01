<?php
// ============================================================
// Integração com Evolution API v2 - WhatsApp
// Docs: https://doc.evolution-api.com/v2/api-reference/message-controller/send-text
// ============================================================

function getEvolutionConfig() {
    return [
        'url' => rtrim(getenv('EVOLUTION_API_URL') ?: '', '/'),
        'instance' => getenv('EVOLUTION_INSTANCE') ?: '',
        'token' => getenv('EVOLUTION_API_TOKEN') ?: '',
        'enabled' => (bool)(getenv('EVOLUTION_ENABLED') ?: false),
    ];
}

function enviarWhatsApp($telefone, $mensagem) {
    $config = getEvolutionConfig();
    if (!$config['enabled'] || !$config['url'] || !$config['instance'] || !$config['token']) {
        return ['sucesso' => false, 'erro' => 'WhatsApp nao configurado'];
    }

    // Formatar numero: 55 + DDD + numero (sem formatacao)
    $numero = preg_replace('/\D/', '', $telefone);
    if (strlen($numero) === 11) {
        $numero = '55' . $numero; // Adicionar codigo do Brasil
    } elseif (strlen($numero) === 13 && substr($numero, 0, 2) === '55') {
        // Ja tem o 55
    } else {
        return ['sucesso' => false, 'erro' => 'Numero invalido'];
    }

    $endpoint = $config['url'] . '/message/sendText/' . $config['instance'];

    // Payload Evolution API v2
    $data = [
        'number' => $numero,
        'text' => $mensagem,
        'delay' => 1200, // Simular digitacao (ms)
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'apikey: ' . $config['token'],
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['sucesso' => false, 'erro' => 'Erro de conexao: ' . $error];
    }

    if ($httpCode === 200 || $httpCode === 201) {
        return ['sucesso' => true, 'resposta' => json_decode($response, true)];
    }

    return ['sucesso' => false, 'erro' => 'HTTP ' . $httpCode, 'resposta' => json_decode($response, true)];
}

// Mensagens padrao
function notificarCashbackCompra($telefone, $nomeCliente, $valorCompra, $cashbackValor, $cashbackPercentual, $creditoDisponivel) {
    $nome = explode(' ', trim($nomeCliente))[0]; // Primeiro nome
    $valorFmt = number_format($valorCompra, 2, ',', '.');
    $cashbackFmt = number_format($cashbackValor, 2, ',', '.');
    $creditoFmt = number_format($creditoDisponivel, 2, ',', '.');

    $mensagem = "Ola, {$nome}! 🏥\n\n"
        . "Sua compra na *Drogaria Sao Bento* foi registrada no Clube de Vantagens!\n\n"
        . "🛒 Compra: R\$ {$valorFmt}\n"
        . "🎁 Cashback ({$cashbackPercentual}%): *R\$ {$cashbackFmt}*\n"
        . "💰 Seu credito disponivel: *R\$ {$creditoFmt}*\n\n"
        . "Use seu credito na proxima compra! 😊\n"
        . "_Drogaria Sao Bento - Clube de Vantagens_";

    return enviarWhatsApp($telefone, $mensagem);
}

function notificarResgate($telefone, $nomeCliente, $valorResgate, $creditoRestante) {
    $nome = explode(' ', trim($nomeCliente))[0];
    $resgateFmt = number_format($valorResgate, 2, ',', '.');
    $restanteFmt = number_format($creditoRestante, 2, ',', '.');

    $mensagem = "Ola, {$nome}! 🏥\n\n"
        . "Resgate realizado no *Clube de Vantagens*!\n\n"
        . "✅ Valor resgatado: *R\$ {$resgateFmt}*\n"
        . "💰 Credito restante: *R\$ {$restanteFmt}*\n\n"
        . "Continue comprando e acumulando cashback! 🎁\n"
        . "_Drogaria Sao Bento - Clube de Vantagens_";

    return enviarWhatsApp($telefone, $mensagem);
}

function notificarBoasVindas($telefone, $nomeCliente, $cashbackAtual) {
    $nome = explode(' ', trim($nomeCliente))[0];

    $mensagem = "Bem-vindo(a) ao *Clube de Vantagens*, {$nome}! 🎉\n\n"
        . "Voce agora faz parte do programa de fidelidade da *Drogaria Sao Bento*!\n\n"
        . "🎁 Cashback atual: *{$cashbackAtual}%* sobre suas compras\n"
        . "💰 Acumule creditos e use como desconto\n"
        . "📱 Consulte seu saldo a qualquer momento\n\n"
        . "Obrigado por escolher a Drogaria Sao Bento! 😊";

    return enviarWhatsApp($telefone, $mensagem);
}
