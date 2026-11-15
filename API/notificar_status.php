
<?php

// SCT - Notificação e sincronização de status
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function resposta(array $dados, int $http): void
{
    http_response_code($http);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

// Permite apenas POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    resposta([
        'sucesso' => false,
        'erro' => 'Método não permitido'
    ], 405);
}

// Autenticação entre Admin e API
$segredo = getenv('SCT_INTERNAL_TOKEN');

$auth = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';

if (
    !is_string($segredo) ||
    strlen($segredo) < 32 ||
    !preg_match('/^Bearer (.+)$/', $auth, $m) ||
    !hash_equals($segredo, $m[1])
) {
    resposta([
        'sucesso' => false,
        'erro' => 'Não autorizado'
    ], 401);
}

// Recebe os dados enviados pelo Admin
$dados = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($dados)) {
    resposta([
        'sucesso' => false,
        'erro' => 'JSON inválido'
    ], 400);
}

$id = filter_var(
    $dados['id_chamado'] ?? null,
    FILTER_VALIDATE_INT
);

$status = $dados['status'] ?? null;

// Status do Admin => Status do WhatsApp
$permitidos = [
    'em_andamento' => 'Em andamento',
    'resolvido' => 'Resolvido',
    'fechado' => 'Fechado'
];

if (
    !$id ||
    $id < 1 ||
    !is_string($status) ||
    !isset($permitidos[$status])
) {
    resposta([
        'sucesso' => false,
        'erro' => 'Dados inválidos'
    ], 400);
}

// Conecta aos dois bancos
require_once __DIR__ . '/../config/conexao.php';

if (
    !isset($pdoTcc) ||
    !($pdoTcc instanceof PDO)
) {
    resposta([
        'sucesso' => false,
        'erro' => 'Banco do TCC indisponível'
    ], 503);
}

if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {
    resposta([
        'sucesso' => false,
        'erro' => 'Banco da API indisponível'
    ], 503);
}

try {

    /*
    |--------------------------------------------------------------------------
    | 1. CONFERE O STATUS REAL NO BANCO DO TCC
    |--------------------------------------------------------------------------
    */

    $stmt = $pdoTcc->prepare("
        SELECT
            c.status,
            cl.telefone
        FROM chamados c
        LEFT JOIN clientes cl
            ON cl.id_cliente = c.id_cliente
        WHERE c.id_chamado = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $chamado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chamado) {
        resposta([
            'sucesso' => false,
            'erro' => 'Chamado não encontrado'
        ], 404);
    }

    // Nunca sincroniza um status diferente do registrado no Admin.
    if ($chamado['status'] !== $status) {
        resposta([
            'sucesso' => false,
            'erro' => 'Status do banco diferente da solicitação'
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. ATUALIZA O STATUS NO BANCO DA API WHATSAPP
    |--------------------------------------------------------------------------
    |
    | Localiza pelo vínculo id_chamado_tcc.
    | Não compara os IDs dos dois bancos diretamente.
    |
    */

    $novoStatus = $permitidos[$status];

    $stmtAtualizar = $pdo->prepare("
        UPDATE chamados
        SET status = ?
        WHERE id_chamado_tcc = ?
    ");

    $stmtAtualizar->execute([
        $novoStatus,
        $id
    ]);

    error_log(
        'SCT: Sincronização executada. '
        . 'Chamado TCC #' . $id
        . ' | Status: ' . $novoStatus
    );

    /*
    |--------------------------------------------------------------------------
    | 3. IDENTIFICA O TELEFONE DO CLIENTE
    |--------------------------------------------------------------------------
    */

    $telefone = preg_replace(
        '/\D+/',
        '',
        (string) ($chamado['telefone'] ?? '')
    );

    if (
        strlen($telefone) < 10 ||
        strlen($telefone) > 15
    ) {
        resposta([
            'sucesso' => false,
            'erro' => 'Status sincronizado, mas o cliente não possui telefone válido'
        ], 422);
    }

} catch (Throwable $e) {

    error_log(
        'SCT ERRO SINCRONIZAÇÃO: ' . $e->getMessage()
    );

    resposta([
        'sucesso' => false,
        'erro' => 'Falha ao consultar ou sincronizar o chamado'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| 4. CONFIGURA O ENVIO PELO WHATSAPP
|--------------------------------------------------------------------------
*/

$token = getenv('WHATSAPP_ACCESS_TOKEN');
$phoneId = getenv('WHATSAPP_PHONE_NUMBER_ID');
$versao = getenv('WHATSAPP_GRAPH_VERSION') ?: 'v26.0';

if (
    !$token ||
    !$phoneId ||
    !preg_match('/^v[0-9]+\.[0-9]+$/', $versao) ||
    !function_exists('curl_init')
) {
    resposta([
        'sucesso' => false,
        'erro' => 'Status sincronizado, mas o WhatsApp não está configurado'
    ], 503);
}

/*
|--------------------------------------------------------------------------
| 5. PREPARA A MENSAGEM AUTOMÁTICA
|--------------------------------------------------------------------------
*/

$mensagem =
    "🛠️ *SCT | ATUALIZAÇÃO DE CHAMADO*\n\n"
    . "🔢 *Chamado do sistema:* #{$id}\n"
    . "📌 *Status:* {$novoStatus}\n\n";

if ($status === 'em_andamento') {

    $mensagem .=
        "🔵 Seu chamado foi aceito pela equipe técnica.\n\n"
        . "O atendimento está em andamento.";

} elseif ($status === 'resolvido') {

    $mensagem .=
        "🟢 Seu chamado foi resolvido pela equipe técnica.\n\n"
        . "A solicitação foi marcada como resolvida.";

} elseif ($status === 'fechado') {

    $mensagem .=
        "⚫ Seu chamado foi encerrado.\n\n"
        . "Agradecemos por utilizar o sistema SCT.";
}

$mensagem .=
    "\n\nPara consultar o andamento, "
    . "envie *menu* e escolha a opção *2* ou *3*.";

/*
|--------------------------------------------------------------------------
| 6. ENVIA A MENSAGEM PELA META
|--------------------------------------------------------------------------
*/

$payload = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $telefone,
    'type' => 'text',
    'text' => [
        'preview_url' => false,
        'body' => $mensagem
    ]
];

$url = 'https://graph.facebook.com/'
    . $versao
    . '/'
    . rawurlencode($phoneId)
    . '/messages';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
    ),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15
]);

$resultado = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$erro = curl_error($ch);

curl_close($ch);

$respostaMeta = is_string($resultado)
    ? json_decode($resultado, true)
    : null;

$aceito = (
    $erro === '' &&
    $http >= 200 &&
    $http < 300 &&
    !empty($respostaMeta['messages'][0]['id'])
);

if (!$aceito) {

    error_log(
        'SCT WhatsApp falha HTTP '
        . $http
        . ' | API code '
        . ($respostaMeta['error']['code'] ?? 'indefinido')
    );

    resposta([
        'sucesso' => false,
        'status_sincronizado' => true,
        'erro' => 'Status sincronizado, mas o WhatsApp recusou o envio. Verifique a janela de 24 horas ou um template aprovado.'
    ], 502);
}

/*
|--------------------------------------------------------------------------
| 7. FINALIZA
|--------------------------------------------------------------------------
*/

resposta([
    'sucesso' => true,
    'status_sincronizado' => true,
    'status' => $novoStatus,
    'mensagem' => 'Status sincronizado e mensagem aceita pela Meta. A entrega ainda não foi confirmada.'
], 200);
