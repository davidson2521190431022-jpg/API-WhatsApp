
<?php

header("Content-Type: application/json; charset=utf-8");

// Carrega a conexão com o banco de dados
require_once __DIR__ . "/../config/conexao.php";

// Aceita somente requisições POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Utilize o metodo POST."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Recebe os dados enviados em JSON
$conteudo = file_get_contents("php://input");
$dados = json_decode($conteudo, true);

// Verifica se o JSON é válido
if (!is_array($dados)) {
    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "JSON invalido."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Recebe e organiza os campos
$nome = trim($dados["nome"] ?? "");
$cpf = trim($dados["cpf"] ?? "");
$matricula = trim($dados["matricula"] ?? "");
$setor = trim($dados["setor"] ?? "");
$categoria = trim($dados["categoria"] ?? "");
$descricao = trim($dados["descricao"] ?? "");
$prioridade = trim($dados["prioridade"] ?? "Media");

// Valida os campos obrigatórios
if (
    $nome === "" ||
    $cpf === "" ||
    $setor === "" ||
    $categoria === "" ||
    $descricao === ""
) {
    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Informe nome, CPF, setor, categoria e descricao."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Valida a prioridade
$prioridadesPermitidas = [
    "Baixa",
    "Media",
    "Alta",
    "Urgente"
];

if (!in_array($prioridade, $prioridadesPermitidas, true)) {
    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Prioridade invalida."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    // Insere o chamado no banco de dados
    $sql = "INSERT INTO chamados
            (nome, cpf, matricula, setor, categoria,
             descricao, prioridade, status)
            VALUES
            (:nome, :cpf, :matricula, :setor, :categoria,
             :descricao, :prioridade, 'Aberto')";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":nome" => $nome,
        ":cpf" => $cpf,
        ":matricula" => $matricula,
        ":setor" => $setor,
        ":categoria" => $categoria,
        ":descricao" => $descricao,
        ":prioridade" => $prioridade
    ]);

    // Retorna a confirmação do cadastro
    http_response_code(201);

    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Chamado criado com sucesso!",
        "id" => $pdo->lastInsertId(),
        "status" => "Aberto"
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // Registra o erro nos logs do servidor
    error_log("Erro ao criar chamado: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Nao foi possivel criar o chamado."
    ], JSON_UNESCAPED_UNICODE);
}
