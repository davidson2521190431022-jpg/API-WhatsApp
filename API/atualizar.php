
<?php

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../config/conexao.php";

// Aceita somente requisições PUT
if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Utilize o metodo PUT."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Recebe o JSON enviado
$dados = json_decode(file_get_contents("php://input"), true);

if (!is_array($dados)) {
    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "JSON invalido."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Valida o ID do chamado
$id = filter_var(
    $dados["id"] ?? null,
    FILTER_VALIDATE_INT
);

if ($id === false || $id === null || $id <= 0) {
    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Informe um ID valido."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Recebe o novo status
$status = trim($dados["status"] ?? "");

$statusPermitidos = [
    "Aberto",
    "Em Andamento",
    "Aguardando Cliente",
    "Resolvido",
    "Fechado"
];

if (!in_array($status, $statusPermitidos, true)) {
    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Status invalido."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    // Confere se o chamado existe
    $consulta = $pdo->prepare(
        "SELECT id FROM chamados WHERE id = :id"
    );

    $consulta->execute([":id" => $id]);

    if (!$consulta->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);

        echo json_encode([
            "sucesso" => false,
            "erro" => "Chamado nao encontrado."
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Atualiza o status
    $sql = "UPDATE chamados
            SET status = :status
            WHERE id = :id";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":status" => $status,
        ":id" => $id
    ]);

    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Status atualizado com sucesso!",
        "id" => $id,
        "status" => $status
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Erro ao atualizar chamado: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Nao foi possivel atualizar o chamado."
    ], JSON_UNESCAPED_UNICODE);
}
