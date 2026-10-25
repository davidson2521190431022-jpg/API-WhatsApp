
<?php

header("Content-Type: application/json; charset=utf-8");

require_once "../config/conexao.php";

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
|--------------------------------------------------------------------------
| RESPOSTAS JSON
|--------------------------------------------------------------------------
*/

function responderJson($dados, $codigo = 200)
{
    http_response_code($codigo);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| ENVIA MENSAGEM PELO WHATSAPP
|--------------------------------------------------------------------------
*/

function enviarMensagemWhatsApp($destinatario, $mensagem)
{
    $token = getenv("WHATSAPP_ACCESS_TOKEN");
    $phoneNumberId = getenv("WHATSAPP_PHONE_NUMBER_ID");
    $versao = getenv("WHATSAPP_GRAPH_VERSION") ?: "v26.0";

    if (!$token || !$phoneNumberId) {
        error_log("WHATSAPP ERRO: Token ou Phone Number ID ausente.");

        return [
            "ok" => false,
            "erro" => "Configuracao ausente"
        ];
    }

    if (!function_exists("curl_init")) {
        error_log("WHATSAPP ERRO: cURL indisponivel.");

        return [
            "ok" => false,
            "erro" => "cURL indisponivel"
        ];
    }

    $destinatario = preg_replace('/\D/', '', $destinatario);

    if ($destinatario === "") {
        return [
            "ok" => false,
            "erro" => "Telefone invalido"
        ];
    }

    $url = "https://graph.facebook.com/"
        . $versao . "/"
        . $phoneNumberId . "/messages";

    $dados = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $destinatario,
        "type" => "text",
        "text" => [
            "preview_url" => false,
            "body" => $mensagem
        ]
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
