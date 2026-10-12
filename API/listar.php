
<?php

header("Content-Type: application/json; charset=utf-8");

// Carrega a conexão com o banco
require_once __DIR__ . "/../config/conexao.php";

// Permite somente requisições GET
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Utilize o metodo GET."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    // Verifica se foi solicitado um chamado específico
    if (isset($_GET["id"])) {
        $id = filter_var(
            $_GET["id"],
            FILTER_VALIDATE_INT
        );

        if ($id === false || $id === null || $id <= 0) {
            http_response_code(400);

            echo json_encode([
                "sucesso" => false,
                "erro" => "ID invalido."
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $sql = "SELECT * FROM chamados WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([":id" => $id]);

        $chamado = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$chamado) {
            http_response_code(404);

            echo json_encode([
                "sucesso" => false,
                "erro" => "Chamado nao encontrado."
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        echo json_encode([
            "sucesso" => true,
            "chamado" => $chamado
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Permite filtrar os chamados por status
    if (isset($_GET["status"])) {
        $status = trim($_GET["status"]);

        $sql = "SELECT * FROM chamados
                WHERE status = :status
                ORDER BY id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([":status" => $status]);

    } else {
        // Lista todos os chamados, dos mais recentes aos antigos
        $sql = "SELECT * FROM chamados ORDER BY id DESC";

        $stmt = $pdo->query($sql);
    }

    $chamados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "sucesso" => true,
        "total" => count($chamados),
        "chamados" => $chamados
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("Erro ao listar chamados: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Nao foi possivel consultar os chamados."
    ], JSON_UNESCAPED_UNICODE);
}
