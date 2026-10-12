
<?php

header("Content-Type: text/html; charset=utf-8");

// Endereço atual da API no Railway
$url = "https://sistema-de-chamado.up.railway.app/API/criar.php";

// Dados fictícios para testar o cadastro
$dados = [
    "nome" => "Davidson - Teste API",
    "cpf" => "000.000.000-00",
    "matricula" => "12345",
    "setor" => "Administracao",
    "categoria" => "Computador",
    "descricao" => "Teste de cadastro da API no Railway",
    "prioridade" => "Alta"
];

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt(
    $ch,
    CURLOPT_POSTFIELDS,
    json_encode($dados, JSON_UNESCAPED_UNICODE)
);

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json"
]);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$resposta = curl_exec($ch);

$erro = curl_error($ch);
$codigoHTTP = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

echo "<h2>Teste de cadastro da API</h2>";

if ($resposta === false) {
    echo "<p>Erro na requisicao: "
        . htmlspecialchars($erro)
        . "</p>";
    exit;
}

echo "<p>Codigo HTTP: " . $codigoHTTP . "</p>";

echo "<pre>";
echo htmlspecialchars($resposta, ENT_QUOTES, "UTF-8");
echo "</pre>";

?>
