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
| ENVIA MENSAGEM PELA WHATSAPP CLOUD API
|--------------------------------------------------------------------------
*/

function enviarMensagemWhatsApp($destinatario, $mensagem)
{
    $token = getenv("WHATSAPP_ACCESS_TOKEN");
    $phoneNumberId = getenv("WHATSAPP_PHONE_NUMBER_ID");
    $versao = getenv("WHATSAPP_GRAPH_VERSION") ?: "v26.0";

    if (!$token || !$phoneNumberId) {
        error_log(
            "WHATSAPP ERRO: token ou Phone Number ID nao configurado."
        );

        return [
            "ok" => false,
            "erro" => "Configuracao da Cloud API ausente"
        ];
    }

    if (!function_exists("curl_init")) {
        error_log("WHATSAPP ERRO: extensao cURL indisponivel.");

        return [
            "ok" => false,
            "erro" => "Extensao cURL indisponivel"
        ];
    }

    $destinatario = preg_replace('/\D/', '', $destinatario);

    if ($destinatario === "") {
        return [
            "ok" => false,
            "erro" => "Telefone do destinatario invalido"
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

    $jsonDados = json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($jsonDados === false) {
        return [
            "ok" => false,
            "erro" => "Nao foi possivel gerar o JSON"
        ];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => $jsonDados,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
    ]);

    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $respostaJson = is_string($resposta)
        ? json_decode($resposta, true)
        : null;

    $messageId = $respostaJson["messages"][0]["id"] ?? null;

    $sucesso = (
        $erroCurl === "" &&
        $codigoHttp >= 200 &&
        $codigoHttp < 300 &&
        is_string($messageId) &&
        $messageId !== ""
    );

    error_log(
        "WHATSAPP ENVIO: HTTP " . $codigoHttp
        . " | sucesso=" . ($sucesso ? "sim" : "nao")
        . " | message_id=" . ($messageId ?: "ausente")
        . " | curl=" . ($erroCurl ?: "nenhum")
    );

    if (!$sucesso) {
        error_log(
            "WHATSAPP ERRO RESPOSTA: "
            . json_encode(
                $respostaJson ?? ["resposta" => $resposta],
                JSON_UNESCAPED_UNICODE
            )
        );
    }

    return [
        "ok" => $sucesso,
        "http" => $codigoHttp,
        "message_id" => $messageId,
        "erro" => $erroCurl ?: null
    ];
}

/*
|--------------------------------------------------------------------------
| VERIFICACAO DO WEBHOOK DA META
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $modo = $_GET["hub_mode"] ?? "";
    $tokenRecebido = $_GET["hub_verify_token"] ?? "";
    $desafio = $_GET["hub_challenge"] ?? "";

    $tokenCorreto = getenv("WHATSAPP_VERIFY_TOKEN") ?: "";

    if (
        $modo === "subscribe" &&
        $tokenCorreto !== "" &&
        hash_equals($tokenCorreto, $tokenRecebido)
    ) {
        http_response_code(200);
        header("Content-Type: text/plain; charset=utf-8");
        echo $desafio;
        exit;
    }

    http_response_code(403);
    echo "Token de verificacao invalido";
    exit;
}

/*
|--------------------------------------------------------------------------
| ACEITA SOMENTE POST
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderJson([
        "erro" => "Metodo nao permitido"
    ], 405);
}

/*
|--------------------------------------------------------------------------
| LEITURA E VALIDACAO DO JSON
|--------------------------------------------------------------------------
*/

$conteudo = file_get_contents("php://input");
$dados = json_decode($conteudo, true);

if (!is_array($dados) || json_last_error() !== JSON_ERROR_NONE) {
    error_log("WEBHOOK ERRO: JSON invalido.");

    responderJson([
        "erro" => "JSON invalido"
    ], 400);
}

/*
|--------------------------------------------------------------------------
| IDENTIFICA EVENTO DA META
|--------------------------------------------------------------------------
*/

$ehWhatsApp = (
    ($dados["object"] ?? "") === "whatsapp_business_account"
);

error_log(
    "WEBHOOK: "
    . ($ehWhatsApp ? "Evento WhatsApp" : "Requisicao normal")
);

/*
|--------------------------------------------------------------------------
| PROCESSA EVENTOS DO WHATSAPP
|--------------------------------------------------------------------------
*/

if ($ehWhatsApp) {
    $mensagensRecebidas = [];
    $houveEvento = false;

    foreach (($dados["entry"] ?? []) as $entrada) {
        foreach (($entrada["changes"] ?? []) as $alteracao) {
            if (($alteracao["field"] ?? "") !== "messages") {
                continue;
            }

            $houveEvento = true;
            $value = $alteracao["value"] ?? [];

            $phoneIdEvento =
                $value["metadata"]["phone_number_id"] ?? "";

            $phoneIdConfigurado =
                getenv("WHATSAPP_PHONE_NUMBER_ID") ?: "";

            if (
                $phoneIdEvento !== "" &&
                $phoneIdConfigurado !== "" &&
                $phoneIdEvento !== $phoneIdConfigurado
            ) {
                error_log(
                    "WEBHOOK: Evento ignorado; Phone Number ID diferente."
                );
                continue;
            }

            foreach (($value["statuses"] ?? []) as $status) {
                error_log(
                    "WHATSAPP STATUS: "
                    . ($status["status"] ?? "desconhecido")
                    . " | ID: "
                    . ($status["id"] ?? "ausente")
                );

                if (($status["status"] ?? "") === "failed") {
                    error_log(
                        "WHATSAPP FALHA: "
                        . json_encode(
                            $status["errors"] ?? [],
                            JSON_UNESCAPED_UNICODE
                        )
                    );
                }
            }

            $nomesContatos = [];

            foreach (($value["contacts"] ?? []) as $contato) {
                $waId = $contato["wa_id"] ?? "";
                $nomeContato =
                    $contato["profile"]["name"] ?? "Cliente";

                if ($waId !== "") {
                    $nomesContatos[$waId] = $nomeContato;
                }
            }

            foreach (($value["messages"] ?? []) as $mensagem) {
                $mensagem["_nome_contato"] =
                    $nomesContatos[$mensagem["from"] ?? ""]
                    ?? "Cliente";

                $mensagensRecebidas[] = $mensagem;
            }
        }
    }

    if (count($mensagensRecebidas) === 0) {
        responderJson([
            "status" => $houveEvento
                ? "Evento recebido"
                : "Evento ignorado"
        ], 200);
    }

    $resultados = [];

    try {
        foreach ($mensagensRecebidas as $mensagem) {
            $messageId = $mensagem["id"] ?? "";
            $telefone = $mensagem["from"] ?? "";

            if ($messageId === "" || $telefone === "") {
                error_log(
                    "WHATSAPP ERRO: Mensagem sem ID ou telefone."
                );
                continue;
            }

            $nome = trim(
                (string) ($mensagem["_nome_contato"] ?? "Cliente")
            );

            if ($nome === "") {
                $nome = "Cliente";
            }

            $tipo = $mensagem["type"] ?? "";

            if ($tipo === "text") {
                $descricao = $mensagem["text"]["body"] ?? "";
            } else {
                $descricao =
                    "Mensagem recebida pelo WhatsApp (" . $tipo . ")";
            }

            if (trim($descricao) === "") {
                error_log(
                    "WHATSAPP: Mensagem sem conteudo; ID " . $messageId
                );
                continue;
            }

            $cpf = "Nao informado";
            $matricula = "";
            $setor = "WhatsApp";
            $categoria = "Atendimento WhatsApp";
            $prioridade = "Media";

            $sql = "INSERT INTO chamados
                (
                    nome,
                    cpf,
                    matricula,
                    setor,
                    categoria,
                    descricao,
                    prioridade,
                    status,
                    whatsapp_message_id
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Aberto', ?)";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $nome,
                    $cpf,
                    $matricula,
                    $setor,
                    $categoria,
                    $descricao,
                    $prioridade,
                    $messageId
                ]);
            } catch (PDOException $e) {
                $codigoMySQL = $e->errorInfo[1] ?? null;

                if ((int) $codigoMySQL === 1062) {
                    error_log(
                        "WEBHOOK DUPLICADO: mensagem ja processada. ID "
                        . $messageId
                    );

                    $resultados[] = [
                        "message_id" => $messageId,
                        "status" => "duplicado_ignorado"
                    ];
                    continue;
                }

                throw $e;
            }

            $idChamado = $pdo->lastInsertId();
            $protocolo = date("YmdHis");

            error_log(
                "BANCO: Chamado criado. ID "
                . $idChamado
                . " | mensagem "
                . $messageId
            );

            $textoResposta =
                "Olá, " . $nome . "! 👋\n\n"
                . "Seu chamado foi registrado com sucesso.\n\n"
                . "📋 Protocolo: " . $protocolo . "\n"
                . "📌 Status: Aberto\n"
                . "🏢 Setor: " . $setor . "\n\n"
                . "Em breve seu atendimento será analisado.";

            $envio = enviarMensagemWhatsApp(
                $telefone,
                $textoResposta
            );

            $resultados[] = [
                "message_id" => $messageId,
                "chamado_id" => $idChamado,
                "status" => "chamado_criado",
                "envio_whatsapp" => $envio["ok"]
                    ? "aceito_pela_api"
                    : "falhou"
            ];
        }
    } catch (PDOException $e) {
        error_log("BANCO ERRO: " . $e->getMessage());

        responderJson([
            "erro" => "Erro ao processar mensagem"
        ], 500);
    }

    responderJson([
        "status" => "Webhook processado",
        "resultados" => $resultados
    ], 200);
}

/*
|--------------------------------------------------------------------------
| REQUISICAO NORMAL DA API
|--------------------------------------------------------------------------
*/

$nome = trim((string) ($dados["nome"] ?? ""));
$cpf = trim((string) ($dados["cpf"] ?? ""));
$matricula = trim((string) ($dados["matricula"] ?? ""));
$setor = trim((string) ($dados["setor"] ?? ""));
$categoria = trim((string) ($dados["categoria"] ?? ""));
$descricao = trim((string) ($dados["descricao"] ?? ""));
$prioridade = trim((string) ($dados["prioridade"] ?? "Media"));

if (
    $nome === "" ||
    $cpf === "" ||
    $setor === "" ||
    $categoria === "" ||
    $descricao === ""
) {
    responderJson([
        "erro" => "Dados obrigatorios ausentes"
    ], 400);
}

try {
    $sql = "INSERT INTO chamados
        (
            nome,
            cpf,
            matricula,
            setor,
            categoria,
            descricao,
            prioridade,
            status,
            whatsapp_message_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Aberto', NULL)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $nome,
        $cpf,
        $matricula,
        $setor,
        $categoria,
        $descricao,
        $prioridade
    ]);

    $idChamado = $pdo->lastInsertId();
    $protocolo = date("YmdHis");

    error_log("API: Chamado criado. ID " . $idChamado);

    responderJson([
        "status" => "Chamado criado com sucesso!",
        "id" => $idChamado,
        "protocolo" => $protocolo,
        "nome" => $nome,
        "cpf" => $cpf,
        "matricula" => $matricula,
        "setor" => $setor,
        "categoria" => $categoria,
        "descricao" => $descricao,
        "prioridade" => $prioridade,
        "status_chamado" => "Aberto"
    ], 200);

} catch (PDOException $e) {
    error_log("BANCO ERRO API: " . $e->getMessage());

    responderJson([
        "erro" => "Erro ao criar chamado"
    ], 500);
}
