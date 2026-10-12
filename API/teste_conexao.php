
<?php

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../config/conexao.php";

try {
    $resultado = $pdo->query("SELECT 1")->fetchColumn();

    if ((int) $resultado === 1) {
        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Conexao com o banco funcionando!",
            "banco" => "Conectado"
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    error_log("Falha no teste de conexao: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Falha ao testar a conexao."
    ], JSON_UNESCAPED_UNICODE);
}
