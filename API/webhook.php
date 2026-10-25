
<?php

header("Content-Type: application/json; charset=utf-8");

require_once "../config/conexao.php";

date_default_timezone_set("America/Sao_Paulo");

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
        return false;
    }

    if (!function_exists("curl_init")) {
        error_log("WHATSAPP ERRO: cURL indisponivel.");
        return false;
    }

    $destinatario = preg_replace('/\D/', '', $destinatario);

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
        !empty($messageId)
    );

    error_log(
        "WHATSAPP ENVIO HTTP: " . $codigoHttp
        . " | sucesso=" . ($sucesso ? "sim" : "nao")
    );

    if (!$sucesso) {
        error_log(
            "WHATSAPP ENVIO ERRO: "
            . json_encode(
                $respostaJson ?? ["curl" => $erroCurl],
                JSON_UNESCAPED_UNICODE
            )
        );
    }

    return $sucesso;
}

/*
|--------------------------------------------------------------------------
| VERIFICACAO DO WEBHOOK
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "GET") {

    $modo = $_GET["hub_mode"]
        ?? $_GET["hub.mode"]
        ?? "";

    $tokenRecebido = $_GET["hub_verify_token"]
        ?? $_GET["hub.verify_token"]
        ?? "";

    $desafio = $_GET["hub_challenge"]
        ?? $_GET["hub.challenge"]
        ?? "";

    $tokenCorreto = getenv("WHATSAPP_VERIFY_TOKEN") ?: "";

    if (
        $modo === "subscribe" &&
        $tokenCorreto !== "" &&
        hash_equals($tokenCorreto, $tokenRecebido)
    ) {
        header("Content-Type: text/plain; charset=utf-8");
        echo $desafio;
        exit;
    }

    responderJson([
        "erro" => "Token de verificacao invalido"
    ], 403);
}

/*
|--------------------------------------------------------------------------
| RECEBE POST
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responderJson(["erro" => "Metodo nao permitido"], 405);
}

$conteudo = file_get_contents("php://input");
$dados = json_decode($conteudo, true);

if (!is_array($dados)) {
    responderJson(["erro" => "JSON invalido"], 400);
}

/*
|--------------------------------------------------------------------------
| EVENTOS DO WHATSAPP
|--------------------------------------------------------------------------
*/

if (($dados["object"] ?? "") === "whatsapp_business_account") {

    foreach (($dados["entry"] ?? []) as $entrada) {

        foreach (($entrada["changes"] ?? []) as $alteracao) {

            if (($alteracao["field"] ?? "") !== "messages") {
                continue;
            }

            $value = $alteracao["value"] ?? [];

            $phoneIdEvento =
                (string) ($value["metadata"]["phone_number_id"] ?? "");

            $phoneIdConfigurado =
                (string) (getenv("WHATSAPP_PHONE_NUMBER_ID") ?: "");

            if (
                $phoneIdEvento !== "" &&
                $phoneIdConfigurado !== "" &&
                $phoneIdEvento !== $phoneIdConfigurado
            ) {
                error_log("WHATSAPP: Phone Number ID diferente.");
                continue;
            }

            /*
            |--------------------------------------------------------------
            | STATUS DAS MENSAGENS
            |--------------------------------------------------------------
            */

            foreach (($value["statuses"] ?? []) as $status) {

                error_log(
                    "WHATSAPP STATUS: "
                    . ($status["status"] ?? "desconhecido")
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

            /*
            |--------------------------------------------------------------
            | IDENTIFICA NOME DO WHATSAPP
            |--------------------------------------------------------------
            */

            $nomes = [];

            foreach (($value["contacts"] ?? []) as $contato) {

                $numero = $contato["wa_id"] ?? "";

                if ($numero !== "") {
                    $nomes[$numero] =
                        $contato["profile"]["name"] ?? "Cliente";
                }
            }

            /*
            |--------------------------------------------------------------
            | PROCESSA MENSAGENS
            |--------------------------------------------------------------
            */

            foreach (($value["messages"] ?? []) as $mensagem) {

                $messageId = $mensagem["id"] ?? "";
                $telefone = $mensagem["from"] ?? "";
                $tipo = $mensagem["type"] ?? "";

                if ($messageId === "" || $telefone === "") {
                    continue;
                }

                if ($tipo !== "text") {
                    error_log("WHATSAPP: Mensagem nao textual ignorada.");
                    continue;
                }

                $texto = trim(
                    (string) ($mensagem["text"]["body"] ?? "")
                );

                if ($texto === "") {
                    continue;
                }

                $nome = trim(
                    (string) ($nomes[$telefone] ?? "Cliente")
                );

                if ($nome === "") {
                    $nome = "Cliente";
                }

                try {

                    $pdo->beginTransaction();

                    /*
                    |------------------------------------------------------
                    | EVITA MENSAGENS DUPLICADAS
                    |------------------------------------------------------
                    */

                    $stmt = $pdo->prepare(
                        "INSERT INTO whatsapp_mensagens_processadas
                         (message_id, telefone)
                         VALUES (?, ?)"
                    );

                    try {
                        $stmt->execute([$messageId, $telefone]);

                    } catch (PDOException $e) {

                        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                            $pdo->rollBack();
                            error_log("WHATSAPP: Mensagem duplicada.");
                            continue;
                        }

                        throw $e;
                    }

                    /*
                    |------------------------------------------------------
                    | CRIA ATENDIMENTO SE NAO EXISTIR
                    |------------------------------------------------------
                    */

                    $stmt = $pdo->prepare(
                        "INSERT INTO atendimento_whatsapp
                         (telefone, nome, setor, etapa)
                         VALUES (?, ?, NULL, 'aguardando_setor')
                         ON DUPLICATE KEY UPDATE
                         telefone = VALUES(telefone)"
                    );

                    $stmt->execute([$telefone, $nome]);

                    $stmt = $pdo->prepare(
                        "SELECT *
                         FROM atendimento_whatsapp
                         WHERE telefone = ?
                         FOR UPDATE"
                    );

                    $stmt->execute([$telefone]);

                    $atendimento = $stmt->fetch(PDO::FETCH_ASSOC);

                    $etapa = $atendimento["etapa"];

                    /*
                    |------------------------------------------------------
                    | ETAPA 1 - PERGUNTA O SETOR
                    |------------------------------------------------------
                    */

                    if ($etapa === "aguardando_setor") {

                        $stmt = $pdo->prepare(
                            "UPDATE atendimento_whatsapp
                             SET nome = ?,
                                 setor = NULL,
                                 etapa = 'recebendo_setor'
                             WHERE telefone = ?"
                        );

                        $stmt->execute([$nome, $telefone]);

                        $pdo->commit();

                        enviarMensagemWhatsApp(
                            $telefone,
                            "Olá, " . $nome . "! 👋\n\n"
                            . "Bem-vindo ao Sistema de Chamados Técnico (SCT).\n\n"
                            . "🏢 Qual é o setor onde você trabalha?"
                        );

                        continue;
                    }

                    /*
                    |------------------------------------------------------
                    | ETAPA 2 - RECEBE O SETOR
                    |------------------------------------------------------
                    */

                    if ($etapa === "recebendo_setor") {

                        // Limita o setor sem exigir mbstring.
                        $setor = substr($texto, 0, 255);

                        $stmt = $pdo->prepare(
                            "UPDATE atendimento_whatsapp
                             SET nome = ?,
                                 setor = ?,
                                 etapa = 'aguardando_descricao'
                             WHERE telefone = ?"
                        );

                        $stmt->execute([
                            $nome,
                            $setor,
                            $telefone
                        ]);

                        $pdo->commit();

                        enviarMensagemWhatsApp(
                            $telefone,
                            "✅ Setor registrado: " . $setor . "\n\n"
                            . "📝 Agora descreva o problema que você está enfrentando."
                        );

                        continue;
                    }

                    /*
                    |------------------------------------------------------
                    | ETAPA 3 - RECEBE A DESCRICAO
                    |------------------------------------------------------
                    */

                    if ($etapa === "aguardando_descricao") {

                        $setor = trim(
                            (string) ($atendimento["setor"] ?? "")
                        );

                        $descricao = $texto;

                        $cpf = "Nao informado";
                        $matricula = "";
                        $categoria = "Atendimento WhatsApp";
                        $prioridade = "Media";

                        /*
                        |--------------------------------------------------
                        | CADASTRA O CHAMADO
                        |--------------------------------------------------
                        */

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

                        $idChamado = $pdo->lastInsertId();

                        $protocolo = date("YmdHis") . "-" . $idChamado;

                        /*
                        |--------------------------------------------------
                        | REINICIA PARA UM NOVO CHAMADO
                        |--------------------------------------------------
                        */

                        $stmt = $pdo->prepare(
                            "UPDATE atendimento_whatsapp
                             SET nome = ?,
                                 setor = NULL,
                                 etapa = 'aguardando_setor'
                             WHERE telefone = ?"
                        );

                        $stmt->execute([$nome, $telefone]);

                        $pdo->commit();

                        error_log(
                            "BANCO: Chamado criado com sucesso. ID "
                            . $idChamado
                        );

                        /*
                        |--------------------------------------------------
                        | CONFIRMACAO PELO WHATSAPP
                        |--------------------------------------------------
                        */

                        $resposta =
                            "✅ *Chamado aberto com sucesso!*\n\n"
                            . "👤 Nome: " . $nome . "\n"
                            . "🏢 Setor: " . $setor . "\n"
                            . "📝 Problema: " . $descricao . "\n\n"
                            . "🔢 Protocolo: " . $protocolo . "\n"
                            . "📌 Status: Aberto\n\n"
                            . "Em breve seu chamado será analisado pela equipe técnica.";

                        enviarMensagemWhatsApp(
                            $telefone,
                            $resposta
                        );

                        continue;
                    }

                    /*
                    |------------------------------------------------------
                    | RECUPERACAO DE ETAPA INVALIDA
                    |------------------------------------------------------
                    */

                    $stmt = $pdo->prepare(
                        "UPDATE atendimento_whatsapp
                         SET nome = ?,
                             setor = NULL,
                             etapa = 'recebendo_setor'
                         WHERE telefone = ?"
                    );

                    $stmt->execute([$nome, $telefone]);

                    $pdo->commit();

                    enviarMensagemWhatsApp(
                        $telefone,
                        "Olá, " . $nome . "! 👋\n\n"
                        . "🏢 Qual é o setor onde você trabalha?"
                    );

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        "WHATSAPP PROCESSAMENTO ERRO: "
                        . $e->getMessage()
                    );
                }
            }
        }
    }

    responderJson([
        "status" => "Webhook processado"
    ], 200);
}

/*
|--------------------------------------------------------------------------
| CADASTRO NORMAL PELA API
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
    $protocolo = date("YmdHis") . "-" . $idChamado;

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

