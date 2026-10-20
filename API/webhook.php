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
