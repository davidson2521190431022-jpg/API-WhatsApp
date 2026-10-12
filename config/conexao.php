
<?php

date_default_timezone_set('America/Sao_Paulo');

$url = getenv('DATABASE_URL');

if (!$url) {
    error_log('A variável DATABASE_URL não está configurada.');

    http_response_code(500);
    exit('Configuração do banco de dados indisponível.');
}

$partes = parse_url($url);

if (
    $partes === false ||
    empty($partes['host']) ||
    empty($partes['user']) ||
    empty($partes['path'])
) {
    error_log('A variável DATABASE_URL está inválida.');

    http_response_code(500);
    exit('Configuração do banco de dados inválida.');
}

$servidor = $partes['host'];
$porta = $partes['port'] ?? 3306;
$usuario = rawurldecode($partes['user']);
$senha = rawurldecode($partes['pass'] ?? '');
$banco = rawurldecode(ltrim($partes['path'], '/'));

try {
    $dsn = "mysql:host=$servidor;"
         . "port=$porta;"
         . "dbname=$banco;"
         . "charset=utf8mb4";

    $pdo = new PDO($dsn, $usuario, $senha, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

} catch (PDOException $e) {
    error_log('Erro de conexão com o banco: ' . $e->getMessage());

    http_response_code(500);
    exit('Não foi possível conectar ao banco de dados.');
}
