
<?php

// SCT - Notificação e sincronização de status
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function resposta(array $dados, int $http): void
{
    http_response_code($http);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| 1. VALIDA O MÉTODO DA REQUISIÇÃO
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

    header('Allow: POST');

    resposta([
        'sucesso' => false,
        'erro' => 'Método não permitido'
    ], 405);
}

/*
|--------------------------------------------------------------------------
| 2. AUTENTICAÇÃO ENTRE O ADMIN E A API
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| 3. RECEBE OS DADOS DO PAINEL ADMINISTRATIVO
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| STATUS DO ADMIN => STATUS DO WHATSAPP
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| 4. CONECTA AOS DOIS BANCOS
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| 5. CONSULTA O CHAMADO E SEU RESPONSÁVEL
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdoTcc->prepare("
        SELECT
            c.status,
            cl.telefone,
            u.nome AS responsavel_nome,
            u.nivel_acesso AS responsavel_perfil

        FROM chamados c

        LEFT JOIN clientes cl
            ON cl.id_cliente = c.id_cliente

        LEFT JOIN usuarios u
            ON u.id_usuario = c.id_usuario

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

    /*
    |--------------------------------------------------------------------------
    | CONFERE O STATUS REAL DO CHAMADO
    |--------------------------------------------------------------------------
    */

    if ($chamado['status'] !== $status) {

        resposta([
            'sucesso' => false,
            'erro' => 'Status do banco diferente da solicitação'
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. SINCRONIZA O STATUS NO BANCO DO WHATSAPP
    |--------------------------------------------------------------------------
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
    | 7. IDENTIFICA O TELEFONE DO CLIENTE
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
| 8. CONFIGURA O ENVIO PELO WHATSAPP
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
| 9. IDENTIFICA O ADMINISTRADOR OU TÉCNICO RESPONSÁVEL
|--------------------------------------------------------------------------
|
| Busca os dados diretamente do banco do painel.
|
| Não utiliza o nome enviado pela requisição.
| Assim, o responsável exibido será aquele que está
| registrado no chamado.
|
*/

$nomeResponsavel = trim(
    (string) ($chamado['responsavel_nome'] ?? '')
);

// Remove caracteres de controle.
$nomeResponsavel = preg_replace(
    '/[\x00-\x1F\x7F]/u',
    ' ',
    $nomeResponsavel
) ?? '';

$nomeResponsavel = preg_replace(
    '/\s+/u',
    ' ',
    $nomeResponsavel
) ?? '';

$nomeResponsavel = trim($nomeResponsavel);

// Evita que o nome altere a formatação do WhatsApp.
$nomeResponsavel = str_replace(
    ['*', '_', '~', '`'],
    '',
    $nomeResponsavel
);

/*
|--------------------------------------------------------------------------
| IDENTIFICA O CARGO DO RESPONSÁVEL
|--------------------------------------------------------------------------
*/

$perfilResponsavel = (string) (
    $chamado['responsavel_perfil'] ?? ''
);

$cargoResponsavel = match ($perfilResponsavel) {

    'admin' => 'Administrador',

    'tecnico' => 'Técnico',

    default => ''
};

/*
|--------------------------------------------------------------------------
| 10. PREPARA A MENSAGEM AUTOMÁTICA
|--------------------------------------------------------------------------
*/

$mensagem =
    "🛠️ *SCT | ATUALIZAÇÃO DE CHAMADO*\n\n"
    . "🔢 *Chamado do sistema:* #{$id}\n"
    . "📌 *Status:* {$novoStatus}\n";

/*
|--------------------------------------------------------------------------
| ADICIONA NOME E CARGO DO RESPONSÁVEL
|--------------------------------------------------------------------------
*/

if ($nomeResponsavel !== '') {

    $mensagem .=
        "👤 *Responsável:* {$nomeResponsavel}\n";

    if ($cargoResponsavel !== '') {

        $mensagem .=
            "🪪 *Cargo:* {$cargoResponsavel}\n";
    }
}

$mensagem .= "\n";

/*
|--------------------------------------------------------------------------
| MENSAGEM QUANDO O CHAMADO É ACEITO
|--------------------------------------------------------------------------
*/

if ($status === 'em_andamento') {

    $mensagem .=
        "🔵 Seu chamado foi aceito pela equipe técnica.\n\n"
        . "O atendimento está em andamento.";

/*
|--------------------------------------------------------------------------
| MENSAGEM QUANDO O CHAMADO É RESOLVIDO
|--------------------------------------------------------------------------
*/

} elseif ($status === 'resolvido') {

    $mensagem .=
        "🟢 Seu chamado foi resolvido pela equipe técnica.\n\n"
        . "A solicitação foi marcada como resolvida.";

/*
|--------------------------------------------------------------------------
| MENSAGEM QUANDO O CHAMADO É FECHADO
|--------------------------------------------------------------------------
*/

} elseif ($status === 'fechado') {

    $mensagem .=
        "⚫ Seu chamado foi encerrado.\n\n"
        . "Agradecemos por utilizar o sistema SCT.";
}

/*
|--------------------------------------------------------------------------
| INFORMA COMO CONSULTAR O CHAMADO
|--------------------------------------------------------------------------
*/

$mensagem .=
    "\n\nPara consultar o andamento, "
    . "envie *menu* e escolha a opção *2* ou *3*.";

/*
|--------------------------------------------------------------------------
| 11. ENVIA A MENSAGEM PELA META
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

$http = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

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

/*
|--------------------------------------------------------------------------
| 12. VERIFICA SE A META ACEITOU A MENSAGEM
|--------------------------------------------------------------------------
*/

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
| 13. FINALIZA A NOTIFICAÇÃO
|--------------------------------------------------------------------------
*/

resposta([
    'sucesso' => true,
    'status_sincronizado' => true,
    'status' => $novoStatus,
    'responsavel' => $nomeResponsavel,
    'cargo' => $cargoResponsavel,
    'mensagem' => 'Status sincronizado e mensagem aceita pela Meta. A entrega ainda não foi confirmada.'
], 200);
