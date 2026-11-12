
<?php

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../config/conexao.php";

date_default_timezone_set("America/Sao_Paulo");

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

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
| MENU PRINCIPAL SCT
|--------------------------------------------------------------------------
*/

function menuSct($nome)
{
    return "Olá, {$nome}! 👋\n\n"
        . "🖥️ *SCT | Sistema de Chamados Técnicos*\n\n"
        . "Bem-vindo ao nosso sistema de suporte técnico!\n\n"
        . "Escolha uma opção:\n\n"
        . "1️⃣ Abrir novo chamado\n"
        . "2️⃣ Consultar meus chamados\n"
        . "3️⃣ Consultar chamado por código\n\n"
        . "Digite *1*, *2* ou *3*.\n\n"
        . "💡 Digite *menu* para voltar ao início.";
}

/*
|--------------------------------------------------------------------------
| ATUALIZA ETAPA DO ATENDIMENTO
|--------------------------------------------------------------------------
*/

function atualizarEtapa(
    $pdo,
    $telefone,
    $nome,
    $etapa,
    $setor = null
) {
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
| RESUMO COMPLETO DO CHAMADO
|--------------------------------------------------------------------------
*/

function resumoChamadoWhatsApp($chamado)
{
    $id = (int) ($chamado["id"] ?? 0);

    $nome = trim(
        (string) ($chamado["nome"] ?? "")
    ) ?: "Não informado";

    $setor = trim(
        (string) ($chamado["setor"] ?? "")
    ) ?: "Não informado";

    $categoria = trim(
        (string) ($chamado["categoria"] ?? "")
    ) ?: "Não informada";

    $descricao = trim(
        (string) ($chamado["descricao"] ?? "")
    ) ?: "Não informada";

    $prioridade = trim(
        (string) ($chamado["prioridade"] ?? "")
    ) ?: "Não informada";

    $status = trim(
        (string) ($chamado["status"] ?? "")
    ) ?: "Não informado";

    /*
    |--------------------------------------------------------------
    | EXPLICAÇÕES PARA CADA STATUS
    |--------------------------------------------------------------
    */

    $situacoes = [
        "aberto" => [
            "🟡",
            "Seu chamado foi registrado com sucesso e está "
            . "aguardando atendimento da equipe técnica."
        ],

        "em andamento" => [
            "🔵",
            "Seu chamado foi aceito e a equipe técnica "
            . "está trabalhando na sua solicitação."
        ],

        "aguardando cliente" => [
            "🟠",
            "O atendimento está aguardando informações "
            . "ou retorno do solicitante."
        ],

        "resolvido" => [
            "🟢",
            "Sua solicitação foi marcada como resolvida "
            . "pela equipe técnica."
        ],

        "fechado" => [
            "⚫",
            "O atendimento foi encerrado. "
            . "Agradecemos por utilizar o suporte da SCT."
        ]
    ];

    $chave = function_exists("mb_strtolower")
        ? mb_strtolower($status, "UTF-8")
        : strtolower($status);

    [$icone, $explicacao] = $situacoes[$chave]
        ?? [
            "📌",
            "Consulte novamente para acompanhar "
            . "as atualizações do atendimento."
        ];

    /*
    |--------------------------------------------------------------
    | DATA DE ABERTURA
    |--------------------------------------------------------------
    */

    $data = "Não informada";

    if (!empty($chamado["criado_em"])) {
        try {
            $data = (new DateTime(
                (string) $chamado["criado_em"]
            ))->format("d/m/Y H:i");
        } catch (Exception $e) {
            $data = "Não informada";
        }
    }

    /*
    |--------------------------------------------------------------
    | MENSAGEM COMPLETA
    |--------------------------------------------------------------
    */

    return "📋 *SCT | CONSULTA DE CHAMADO*\n\n"
        . "🔢 *Protocolo:* #{$id}\n"
        . "👤 *Solicitante:* "
        . textoCurto($nome, 100) . "\n"
        . "🏢 *Setor:* "
        . textoCurto($setor, 100) . "\n"
        . "🛠️ *Categoria:* "
        . textoCurto($categoria, 100) . "\n"
        . "📝 *Problema:* "
        . textoCurto($descricao, 450) . "\n"
        . "🚩 *Prioridade:* "
        . textoCurto($prioridade, 40) . "\n"
        . "📅 *Aberto em:* {$data}\n\n"
        . "━━━━━━━━━━━━━━━━━━\n"
        . "{$icone} *Situação atual: {$status}*\n\n"
        . "{$explicacao}\n"
        . "━━━━━━━━━━━━━━━━━━\n\n"
        . "ℹ️ O status é atualizado conforme "
        . "o andamento do atendimento.\n\n"
        . "Digite *menu* para voltar às opções.\n\n"
      ];


/*
|--------------------------------------------------------------------------
| VERIFICAÇÃO DO WEBHOOK
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
    responderJson([
        "erro" => "Metodo nao permitido"
    ], 405);
}

$dados = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($dados)) {
    responderJson([
        "erro" => "JSON invalido"
    ], 400);
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
            |------------------------------------------------------
            | STATUS DAS MENSAGENS
            |------------------------------------------------------
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
            |------------------------------------------------------
            | NOMES DOS CONTATOS
            |------------------------------------------------------
            */

            $nomes = [];

            foreach (($valor["contacts"] ?? []) as $contato) {

                if (!empty($contato["wa_id"])) {
                    $nomes[$contato["wa_id"]] =
                        $contato["profile"]["name"] ?? "Cliente";
                }
            }

            /*
            |------------------------------------------------------
            | PROCESSAMENTO DAS MENSAGENS
            |------------------------------------------------------
            */

            foreach (($valor["messages"] ?? []) as $mensagem) {

                $idMensagem = (string) (
                    $mensagem["id"] ?? ""
                );

                $telefone = (string) (
                    $mensagem["from"] ?? ""
                );

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
                    |--------------------------------------------------
                    | EVITA MENSAGENS DUPLICADAS
                    |--------------------------------------------------
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

                            error_log(
                                "WHATSAPP: Mensagem duplicada."
                            );

                            continue;
                        }

                        throw $e;
                    }

                    /*
                    |--------------------------------------------------
                    | CRIA ATENDIMENTO
                    |--------------------------------------------------
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

                    $atendimento = $stmt->fetch(
                        PDO::FETCH_ASSOC
                    );

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
                    |--------------------------------------------------
                    | VOLTAR AO MENU
                    |--------------------------------------------------
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
                    |--------------------------------------------------
                    | CONVERSAS ANTIGAS
                    |--------------------------------------------------
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
                    |--------------------------------------------------
                    | MENU PRINCIPAL
                    |--------------------------------------------------
                    */

                    } elseif ($etapa === "menu") {

                        /*
                        |----------------------------------------------
                        | OPÇÃO 1 - ABRIR CHAMADO
                        |----------------------------------------------
                        */

                        if ($opcao === "1") {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "recebendo_setor"
                            );

                            $resposta =
                                "🏢 *ABERTURA DE CHAMADO*\n\n"
                                . "Qual é o setor onde você trabalha?\n\n"
                                . "Digite o nome do seu setor.";

                        /*
                        |----------------------------------------------
                        | OPÇÃO 2 - MEUS CHAMADOS
                        |----------------------------------------------
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
                                    "📋 *MEUS CHAMADOS*\n\n"
                                    . "Não encontrei chamados vinculados "
                                    . "a este WhatsApp.\n\n"
                                    . "Digite *1* para abrir um chamado "
                                    . "ou *3* para consultar por código.";

                            } else {

                                $resposta =
                                    "📋 *SCT | SEUS CHAMADOS*\n\n"
                                    . "Encontrei os seguintes registros:\n\n";

                                foreach ($chamados as $chamado) {

                                    $statusAtual = (string) (
                                        $chamado["status"] ?? ""
                                    );

                                    $iconeStatus = match (
                                        mb_strtolower($statusAtual, "UTF-8")
                                    ) {
                                        "aberto" => "🟡",
                                        "em andamento" => "🔵",
                                        "aguardando cliente" => "🟠",
                                        "resolvido" => "🟢",
                                        "fechado" => "⚫",
                                        default => "📌"
                                    };

                                    $resposta .=
                                        "🔢 *Chamado #"
                                        . $chamado["id"]
                                        . "*\n"
                                        . "📝 "
                                        . textoCurto(
                                            (string) $chamado["descricao"],
                                            70
                                        )
                                        . "\n"
                                        . "{$iconeStatus} *"
                                        . $statusAtual
                                        . "*\n\n";
                                }

                                $resposta .=
                                    "━━━━━━━━━━━━━━━━━━\n"
                                    . "🔎 Para ver todos os detalhes, "
                                    . "digite o número do chamado.\n\n"
                                    . "Exemplo: *15*\n\n"
                                    . "Digite *0* para voltar ao menu.";

                                atualizarEtapa(
                                    $pdo,
                                    $telefone,
                                    $nome,
                                    "escolhendo_chamado"
                                );
                            }

                        /*
                        |----------------------------------------------
                        | OPÇÃO 3 - CONSULTA POR CÓDIGO
                        |----------------------------------------------
                        */

                        } elseif ($opcao === "3") {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "consulta_id"
                            );

                            $resposta =
                                "🔎 *SCT | CONSULTA POR CÓDIGO*\n\n"
                                . "Digite o número do chamado "
                                . "que deseja consultar.\n\n"
                                . "Exemplo: *15*\n\n"
                                . "🔐 Será necessário informar "
                                . "o código de segurança recebido "
                                . "na abertura do chamado.";

                        } else {

                            $resposta = menuSct($nome);
                        }

                    /*
                    |--------------------------------------------------
                    | RECEBE O SETOR
                    |--------------------------------------------------
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
                            "✅ *Setor registrado!*\n\n"
                            . "🏢 Setor: {$setor}\n\n"
                            . "📝 Agora descreva o problema "
                            . "que você está enfrentando.\n\n"
                            . "Procure informar o máximo de detalhes "
                            . "para facilitar o atendimento.";

                    /*
                    |--------------------------------------------------
                    | RECEBE DESCRIÇÃO E ABRE CHAMADO
                    |--------------------------------------------------
                    */

                    } elseif ($etapa === "aguardando_descricao") {

                        $setor = trim(
                            (string) ($atendimento["setor"] ?? "")
                        );

                        $codigo = (string) random_int(
                            100000,
                            999999
                        );

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
                            "✅ *CHAMADO ABERTO COM SUCESSO!*\n\n"
                            . "🖥️ *SCT | CENTRAL DE ATENDIMENTO*\n\n"
                            . "🔢 *Protocolo:* #{$idChamado}\n"
                            . "👤 *Solicitante:* {$nome}\n"
                            . "🏢 *Setor:* {$setor}\n"
                            . "📝 *Problema:* "
                            . textoCurto($texto, 450) . "\n"
                            . "🚩 *Prioridade:* Média\n"
                            . "🟡 *Status:* Aberto\n\n"
                            . "━━━━━━━━━━━━━━━━━━\n"
                            . "🔐 *CÓDIGO DE CONSULTA*\n\n"
                            . "*{$codigo}*\n\n"
                            . "Guarde esse código em segurança.\n"
                            . "Ele permite consultar o chamado "
                            . "por outro número de WhatsApp.\n\n"
                            . "⚠️ Não compartilhe com terceiros.\n"
                            . "━━━━━━━━━━━━━━━━━━\n\n"
                            . "Nossa equipe analisará sua solicitação.\n\n"
                            . "Digite *menu* para ver as opções.\n\n"
                            . "🖥️ *SCT | Soluções em Tecnologia*";

                        error_log(
                            "BANCO: Chamado criado com sucesso. ID "
                            . $idChamado
                        );

                    /*
                    |--------------------------------------------------
                    | ESCOLHE CHAMADO DO PRÓPRIO WHATSAPP
                    |--------------------------------------------------
                    */

                    } elseif ($etapa === "escolhendo_chamado") {

                        if (
                            !ctype_digit($opcao) ||
                            strlen($opcao) > 18
                        ) {

                            $resposta =
                                "Digite somente o número do chamado "
                                . "ou *0* para voltar ao menu.";

                        } else {

                            $stmt = $pdo->prepare(
                                "SELECT
                                    id,
                                    nome,
                                    setor,
                                    categoria,
                                    descricao,
                                    prioridade,
                                    status,
                                    criado_em
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
                                    "❌ *CHAMADO NÃO ENCONTRADO*\n\n"
                                    . "Não encontrei esse chamado "
                                    . "vinculado ao seu WhatsApp.\n\n"
                                    . "Digite outro número ou *0* "
                                    . "para voltar.";

                            } else {

                                $resposta = resumoChamadoWhatsApp(
                                    $chamado
                                );
                            }
                        }

                    /*
                    |--------------------------------------------------
                    | CONSULTA POR CÓDIGO - NÚMERO DO CHAMADO
                    |--------------------------------------------------
                    */

                    } elseif ($etapa === "consulta_id") {

                        if (
                            !ctype_digit($opcao) ||
                            strlen($opcao) > 18
                        ) {

                            $resposta =
                                "Informe um número de chamado válido.\n\n"
                                . "Exemplo: *15*";

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
                                    "❌ *CHAMADO NÃO DISPONÍVEL*\n\n"
                                    . "Confira o número informado.\n\n"
                                    . "Digite outro número ou *0* "
                                    . "para voltar ao menu.";

                            } else {

                                atualizarEtapa(
                                    $pdo,
                                    $telefone,
                                    $nome,
                                    "codigo_0",
                                    $opcao
                                );

                                $resposta =
                                    "🔐 *VERIFICAÇÃO DE SEGURANÇA*\n\n"
                                    . "Chamado: *#{$opcao}*\n\n"
                                    . "Informe o código de segurança "
                                    . "de *6 dígitos* recebido quando "
                                    . "o chamado foi aberto.\n\n"
                                    . "Você possui até 3 tentativas.\n\n"
                                    . "Digite *0* para cancelar.";
                            }
                        }

                    /*
                    |--------------------------------------------------
                    | CONSULTA POR CÓDIGO - VERIFICAÇÃO
                    |--------------------------------------------------
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
                            "SELECT
                                id,
                                nome,
                                setor,
                                categoria,
                                descricao,
                                prioridade,
                                status,
                                criado_em,
                                codigo_consulta_hash
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

                        /*
                        |----------------------------------------------
                        | CÓDIGO CORRETO
                        |----------------------------------------------
                        */

                        if ($valido) {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "menu"
                            );

                            $resposta = resumoChamadoWhatsApp(
                                $chamado
                            );

                        /*
                        |----------------------------------------------
                        | LIMITE DE TENTATIVAS
                        |----------------------------------------------
                        */

                        } elseif ($tentativas >= 2) {

                            atualizarEtapa(
                                $pdo,
                                $telefone,
                                $nome,
                                "menu"
                            );

                            $resposta =
                                "🔒 *LIMITE DE TENTATIVAS*\n\n"
                                . "Você atingiu o limite de "
                                . "3 tentativas nesta consulta.\n\n"
                                . "A consulta foi encerrada.\n\n"
                                . "Digite *menu* para voltar.";

                        /*
                        |----------------------------------------------
                        | CÓDIGO INCORRETO
                        |----------------------------------------------
                        */

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
                                "❌ *CÓDIGO INCORRETO*\n\n"
                                . "Verifique o código informado "
                                . "e tente novamente.\n\n"
                                . "Tentativas restantes: "
                                . "*{$restantes}*\n\n"
                                . "Digite *0* para cancelar.";
                        }

                    /*
                    |--------------------------------------------------
                    | RECUPERA ETAPA DESCONHECIDA
                    |--------------------------------------------------
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

                /*
                |--------------------------------------------------
                | ENVIA A RESPOSTA AO USUÁRIO
                |--------------------------------------------------
                */

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
