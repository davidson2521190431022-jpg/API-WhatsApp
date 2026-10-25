
<?php

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../config/conexao.php";

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
    $phoneId = getenv("WHATSAPP_PHONE_NUMBER_ID");
    $versao = getenv("WHATSAPP_GRAPH_VERSION") ?: "v26.0";

    if (!$token || !$phoneId || !function_exists("curl_init")) {
        error_log("WHATSAPP: Token, ID ou cURL ausente.");
        return false;
    }

    $url = "https://graph.facebook.com/"
        . $versao . "/"
        . $phoneId . "/messages";

    $dados = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => preg_replace("/\D/", "", $destinatario),
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
    $erro = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $json = is_string($resposta)
        ? json_decode($resposta, true)
        : null;

    $sucesso = (
        $erro === "" &&
        $http >= 200 &&
        $http < 300 &&
        !empty($json["messages"][0]["id"])
    );

    error_log(
        "WHATSAPP ENVIO HTTP: " . $http
        . " | sucesso=" . ($sucesso ? "sim" : "nao")
    );

    if (!$sucesso) {
        error_log(
            "WHATSAPP ENVIO ERRO: "
            . json_encode(
                $json ?? ["curl" => $erro],
                JSON_UNESCAPED_UNICODE
            )
        );
    }

    return $sucesso;
}

/*
|--------------------------------------------------------------------------
| MENU DO SCT
|--------------------------------------------------------------------------
*/

function menuSct($nome)
{
    return "Olá, {$nome}! 👋\n\n"
        . "*Sistema de Chamados Técnico (SCT)*\n\n"
        . "*1* - Abrir novo chamado\n"
        . "*2* - Consultar meus chamados\n"
        . "*3* - Consultar por código\n\n"
        . "Digite 1, 2 ou 3.";
}

/*
|--------------------------------------------------------------------------
| ATUALIZA ETAPA DO ATENDIMENTO
|--------------------------------------------------------------------------
*/

function atualizarEtapa($pdo, $telefone, $nome, $etapa, $setor = null)
{
    $stmt = $pdo->prepare(
        "UPDATE atendimento_whatsapp
         SET nome = ?, etapa = ?, setor = ?
         WHERE telefone = ?"
    );

    $stmt->execute([
        $nome,
        $etapa,
        $setor,
        $telefone
    ]);
}

/*
|--------------------------------------------------------------------------
| LIMITA TAMANHO DO TEXTO
|--------------------------------------------------------------------------
*/

function textoCurto($texto, $limite = 110)
{
    $texto = preg_replace("/\s+/u", " ", $texto) ?? $texto;

    if (function_exists("mb_substr")) {
        return mb_strlen($texto, "UTF-8") > $limite
            ? mb_substr($texto, 0, $limite, "UTF-8") . "..."
            : $texto;
    }

    return strlen($texto) > $limite
        ? substr($texto, 0, $limite) . "..."
        : $texto;
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

    $token = $_GET["hub_verify_token"]
        ?? $_GET["hub.verify_token"]
        ?? "";

    $desafio = $_GET["hub_challenge"]
        ?? $_GET["hub.challenge"]
        ?? "";

    $esperado = getenv("WHATSAPP_VERIFY_TOKEN") ?: "";

    if (
        $modo === "subscribe" &&
        $esperado !== "" &&
        hash_equals($esperado, (string) $token)
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

$dados = json_decode(
    file_get_contents("php://input"),
    true
);

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

            $valor = $alteracao["value"] ?? [];

            $idEvento = (string) (
                $valor["metadata"]["phone_number_id"] ?? ""
            );

            $idConfig = (string) (
                getenv("WHATSAPP_PHONE_NUMBER_ID") ?: ""
            );

            if (
                $idEvento !== "" &&
                $idConfig !== "" &&
                $idEvento !== $idConfig
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------
            | STATUS DAS MENSAGENS
            |--------------------------------------------------------------
            */

            foreach (($valor["statuses"] ?? []) as $status) {

                error_log(
                    "WHATSAPP STATUS: "
                    . ($status["status"] ?? "desconhecido")
                );

                if (($status["status"] ?? "") === "failed") {
                    error_log(
                        "WHATSAPP FALHA: "
                        . json_encode($status["errors"] ?? [])
                    );
                }
            }

            /*
            |--------------------------------------------------------------
            | NOMES DOS CONTATOS
            |--------------------------------------------------------------
            */

            $nomes = [];

            foreach (($valor["contacts"] ?? []) as $contato) {

                if (!empty($contato["wa_id"])) {
                    $nomes[$contato["wa_id"]] =
                        $contato["profile"]["name"] ?? "Cliente";
                }
            }

            /*
            |--------------------------------------------------------------
            | PROCESSAMENTO DAS MENSAGENS
            |--------------------------------------------------------------
            */

            foreach (($valor["messages"] ?? []) as $mensagem) {

                $idMensagem = (string) ($mensagem["id"] ?? "");
                $telefone = (string) ($mensagem["from"] ?? "");

                if (
                    $idMensagem === "" ||
                    $telefone === "" ||
                    ($mensagem["type"] ?? "") !== "text"
                ) {
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
                ) ?: "Cliente";

                $resposta = null;

                try {

                    $pdo->beginTransaction();

                    /*
                    |------------------------------------------------------
                    | EVITA MENSAGENS DUPLICADAS
                    |------------------------------------------------------
                    */

                    try {

                        $stmt = $pdo->prepare(
                            "INSERT INTO whatsapp_mensagens_processadas
                             (message_id, telefone)
                             VALUES (?, ?)"
                        );

                        $stmt->execute([
                            $idMensagem,
                            $telefone
                        ]);

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
                    | CRIA ATENDIMENTO
                    |------------------------------------------------------
                    */

                    $stmt = $pdo->prepare(
                        "INSERT INTO atendimento_whatsapp
                         (telefone, nome, setor, etapa)
                         VALUES (?, ?, NULL, 'menu')
                         ON DUPLICATE KEY UPDATE
                         telefone = VALUES(telefone)"
                    );

                    $stmt->execute([
                        $telefone,
                        $nome
                    ]);

                    $stmt = $pdo->prepare(
                        "SELECT *
                         FROM atendimento_whatsapp
                         WHERE telefone = ?
                         FOR UPDATE"
                    );

                    $stmt->execute([$telefone]);

                    $atendimento = $stmt->fetch(PDO::FETCH_ASSOC);

                    $etapa = (string) (
                        $atendimento["etapa"] ?? "menu"
                    );

                    $opcao = trim($texto);

                    $comandoMenu = in_array(
                        strtolower($opcao),
                        ["menu", "0", "cancelar"],
                        true
                    );

                    /*
                    |------------------------------------------------------
                    | VOLTAR AO MENU
                    |------------------------------------------------------
                    */

                    if ($comandoMenu) {

                        atualizarEtapa(
                            $pdo,
                            $telefone,
                            $nome,
                            "menu"
                        );

                        $resposta = menuSct($nome);

                    /*
                    |------------------------------------------------------
                    | CONVERSAS ANTIGAS
                    |------------------------------------------------------
                    */

                    } elseif ($etapa === "aguardando_setor") {

                        atualizarEtapa(
                            $pdo,
                            $telefone,
                            $nome,
                            "menu"
                        );

                        $resposta = menuSct($nome);

                    /*
                    |------------------------------------------------------
                    | MENU PRINCIPAL
                    |------------------------------------------------------
                    */

                    } elseif ($etapa === "menu") {

                        /*
                        |--------------------------------------------------
                        | OPCAO 1 - ABRIR CHAMADO
                        |--------------------------------------------------
                        */

                        if ($opcao === "1") {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "recebendo_setor"
                            );

                            $resposta =
                                "🏢 Qual é o setor onde você trabalha?";

                        /*
                        |--------------------------------------------------
                        | OPCAO 2 - MEUS CHAMADOS
                        |--------------------------------------------------
                        */

                        } elseif ($opcao === "2") {

                            $stmt = $pdo->prepare(
                                "SELECT id, descricao, status
                                 FROM chamados
                                 WHERE whatsapp_telefone = ?
                                 ORDER BY id DESC
                                 LIMIT 10"
                            );

                            $stmt->execute([$telefone]);

                            $chamados = $stmt->fetchAll(
                                PDO::FETCH_ASSOC
                            );

                            if (!$chamados) {

                                $resposta =
                                    "📋 Não encontrei chamados vinculados "
                                    . "a este WhatsApp.\n\n"
                                    . "Digite *1* para abrir um chamado "
                                    . "ou *3* para consultar por código.";

                            } else {

                                $resposta =
                                    "📋 *Seus últimos chamados*\n\n";

                                foreach ($chamados as $chamado) {

                                    $resposta .=
                                        "#" . $chamado["id"]
                                        . " — "
                                        . textoCurto(
                                            (string) $chamado["descricao"],
                                            70
                                        )
                                        . "\n📌 "
                                        . $chamado["status"]
                                        . "\n\n";
                                }

                                $resposta .=
                                    "Para ver os detalhes, digite "
                                    . "o número do chamado (ex.: 15).\n\n"
                                    . "Digite *0* para voltar ao menu.";

                                atualizarEtapa(
                                    $pdo,
                                    $telefone,
                                    $nome,
                                    "escolhendo_chamado"
                                );
                            }

                        /*
                        |--------------------------------------------------
                        | OPCAO 3 - CONSULTA POR CODIGO
                        |--------------------------------------------------
                        */

                        } elseif ($opcao === "3") {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "consulta_id"
                            );

                            $resposta =
                                "🔎 Digite o número do chamado "
                                . "que deseja consultar (ex.: 15).";

                        } else {

                            $resposta = menuSct($nome);
                        }

                    /*
                    |------------------------------------------------------
                    | RECEBE O SETOR
                    |------------------------------------------------------
                    */

                    } elseif ($etapa === "recebendo_setor") {

                        $setor = textoCurto($texto, 250);

                        atualizarEtapa(
                            $pdo,
                            $telefone,
                            $nome,
                            "aguardando_descricao",
                            $setor
                        );

                        $resposta =
                            "✅ Setor registrado: {$setor}\n\n"
                            . "📝 Agora descreva o problema "
                            . "que você está enfrentando.";

                    /*
                    |------------------------------------------------------
                    | RECEBE DESCRICAO E ABRE CHAMADO
                    |------------------------------------------------------
                    */

                    } elseif ($etapa === "aguardando_descricao") {

                        $setor = trim(
                            (string) ($atendimento["setor"] ?? "")
                        );

                        $codigo = (string) random_int(100000, 999999);

                        $hash = password_hash(
                            $codigo,
                            PASSWORD_DEFAULT
                        );

                        $stmt = $pdo->prepare(
                            "INSERT INTO chamados
                             (
                                 nome,
                                 cpf,
                                 matricula,
                                 setor,
                                 categoria,
                                 descricao,
                                 prioridade,
                                 status,
                                 whatsapp_message_id,
                                 whatsapp_telefone,
                                 codigo_consulta_hash
                             )
                             VALUES (
                                 ?, ?, ?, ?, ?, ?, ?,
                                 'Aberto', ?, ?, ?
                             )"
                        );

                        $stmt->execute([
                            $nome,
                            "Nao informado",
                            "",
                            $setor,
                            "Atendimento WhatsApp",
                            $texto,
                            "Media",
                            $idMensagem,
                            $telefone,
                            $hash
                        ]);

                        $idChamado = $pdo->lastInsertId();

                        atualizarEtapa(
                            $pdo,
                            $telefone,
                            $nome,
                            "menu"
                        );

                        $resposta =
                            "✅ *Chamado aberto com sucesso!*\n\n"
                            . "🔢 Número: #{$idChamado}\n"
                            . "👤 Nome: {$nome}\n"
                            . "🏢 Setor: {$setor}\n"
                            . "📝 Problema: {$texto}\n"
                            . "📌 Status: Aberto\n\n"
                            . "🔐 *Código de consulta: {$codigo}*\n\n"
                            . "Guarde esse código: ele permite "
                            . "consultar o chamado por outro número "
                            . "de WhatsApp.\n"
                            . "Não compartilhe com outras pessoas.\n\n"
                            . "Digite *menu* para ver as opções.";

                        error_log(
                            "BANCO: Chamado criado com sucesso. ID "
                            . $idChamado
                        );

                    /*
                    |------------------------------------------------------
                    | ESCOLHE CHAMADO DO PROPRIO WHATSAPP
                    |------------------------------------------------------
                    */

                    } elseif ($etapa === "escolhendo_chamado") {

                        if (!ctype_digit($opcao)) {

                            $resposta =
                                "Digite somente o número do chamado "
                                . "ou *0* para voltar ao menu.";

                        } else {

                            $stmt = $pdo->prepare(
                                "SELECT id, nome, setor, descricao, status
                                 FROM chamados
                                 WHERE id = ?
                                   AND whatsapp_telefone = ?
                                 LIMIT 1"
                            );

                            $stmt->execute([
                                $opcao,
                                $telefone
                            ]);

                            $chamado = $stmt->fetch(
                                PDO::FETCH_ASSOC
                            );

                            if (!$chamado) {

                                $resposta =
                                    "Não encontrei esse chamado vinculado "
                                    . "ao seu WhatsApp.\n\n"
                                    . "Digite outro número ou *0* "
                                    . "para voltar.";

                            } else {

                                $resposta =
                                    "📋 *Chamado #"
                                    . $chamado["id"] . "*\n\n"
                                    . "👤 " . $chamado["nome"] . "\n"
                                    . "🏢 Setor: "
                                    . $chamado["setor"] . "\n"
                                    . "📝 Problema: "
                                    . $chamado["descricao"] . "\n"
                                    . "📌 Status: *"
                                    . $chamado["status"] . "*\n\n"
                                    . "Digite *0* para voltar ao menu.";
                            }
                        }

                    /*
                    |------------------------------------------------------
                    | CONSULTA POR CODIGO - NUMERO DO CHAMADO
                    |------------------------------------------------------
                    */

                    } elseif ($etapa === "consulta_id") {

                        if (
                            !ctype_digit($opcao) ||
                            strlen($opcao) > 18
                        ) {

                            $resposta =
                                "Informe um número de chamado válido "
                                . "(ex.: 15).";

                        } else {

                            $stmt = $pdo->prepare(
                                "SELECT id
                                 FROM chamados
                                 WHERE id = ?
                                   AND codigo_consulta_hash IS NOT NULL
                                 LIMIT 1"
                            );

                            $stmt->execute([$opcao]);

                            $achou = $stmt->fetchColumn();

                            if (!$achou) {

                                $resposta =
                                    "Chamado não disponível para "
                                    . "consulta por código.\n\n"
                                    . "Confira o número ou digite "
                                    . "*0* para voltar.";

                            } else {

                                atualizarEtapa(
                                    $pdo,
                                    $telefone,
                                    $nome,
                                    "codigo_0",
                                    $opcao
                                );

                                $resposta =
                                    "🔐 Informe o código de segurança "
                                    . "de 6 dígitos recebido quando "
                                    . "o chamado foi aberto.";
                            }
                        }

                    /*
                    |------------------------------------------------------
                    | CONSULTA POR CODIGO - VERIFICACAO
                    |------------------------------------------------------
                    */

                    } elseif (
                        preg_match(
                            "/^codigo_([012])$/",
                            $etapa,
                            $matches
                        )
                    ) {

                        $tentativas = (int) $matches[1];

                        $idConsulta = (string) (
                            $atendimento["setor"] ?? ""
                        );

                        $stmt = $pdo->prepare(
                            "SELECT id, status, codigo_consulta_hash
                             FROM chamados
                             WHERE id = ?
                             LIMIT 1"
                        );

                        $stmt->execute([$idConsulta]);

                        $chamado = $stmt->fetch(
                            PDO::FETCH_ASSOC
                        );

                        $valido = (
                            $chamado &&
                            ctype_digit($opcao) &&
                            strlen($opcao) === 6 &&
                            password_verify(
                                $opcao,
                                (string) $chamado["codigo_consulta_hash"]
                            )
                        );

                        if ($valido) {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "menu"
                            );

                            $resposta =
                                "✅ *Chamado #"
                                . $chamado["id"] . "*\n\n"
                                . "📌 Status atual: *"
                                . $chamado["status"] . "*\n\n"
                                . "Digite *menu* para voltar às opções.";

                        } elseif ($tentativas >= 2) {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "menu"
                            );

                            $resposta =
                                "🔒 Limite de tentativas atingido.\n\n"
                                . "Consulta encerrada.\n"
                                . "Digite *menu* para voltar.";

                        } else {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "codigo_" . ($tentativas + 1),
                                $idConsulta
                            );

                            $restantes = 2 - $tentativas;

                            $resposta =
                                "❌ Código incorreto.\n\n"
                                . "Restam {$restantes} tentativa(s).\n"
                                . "Digite *0* para cancelar.";
                        }

                    /*
                    |------------------------------------------------------
                    | RECUPERA ETAPA DESCONHECIDA
                    |------------------------------------------------------
                    */

                    } else {

                        atualizarEtapa(
                            $pdo,
                            $telefone,
                            $nome,
                            "menu"
                        );

                        $resposta = menuSct($nome);
                    }

                    $pdo->commit();

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        "WHATSAPP PROCESSAMENTO ERRO: "
                        . $e->getMessage()
                    );

                    continue;
                }

                if ($resposta !== null) {
                    enviarMensagemWhatsApp(
                        $telefone,
                        $resposta
                    );
                }
            }
        }
    }

    responderJson([
        "status" => "Webhook processado"
    ]);
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

    $stmt = $pdo->prepare(
        "INSERT INTO chamados
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
         VALUES (?, ?, ?, ?, ?, ?, ?, 'Aberto', NULL)"
    );

    $stmt->execute([
        $nome,
        $cpf,
        $matricula,
        $setor,
        $categoria,
        $descricao,
        $prioridade
    ]);

    $id = $pdo->lastInsertId();

    responderJson([
        "status" => "Chamado criado com sucesso!",
        "id" => $id,
        "protocolo" => date("YmdHis") . "-" . $id,
        "nome" => $nome,
        "cpf" => $cpf,
        "matricula" => $matricula,
        "setor" => $setor,
        "categoria" => $categoria,
        "descricao" => $descricao,
        "prioridade" => $prioridade,
        "status_chamado" => "Aberto"
    ]);

} catch (PDOException $e) {

    error_log(
        "BANCO ERRO API: " . $e->getMessage()
    );

    responderJson([
        "erro" => "Erro ao criar chamado"
    ], 500);
}
