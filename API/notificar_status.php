<?php
// Rota interna de notificações da SCT. Não altera o status no banco.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function resposta(array $dados, int $http): void {
    http_response_code($http);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    resposta(['sucesso'=>false,'erro'=>'Método não permitido'], 405);
}
$segredo = getenv('SCT_INTERNAL_TOKEN');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!is_string($segredo) || strlen($segredo)<32 || !preg_match('/^Bearer (.+)$/', $auth, $m) || !hash_equals($segredo, $m[1])) {
    resposta(['sucesso'=>false,'erro'=>'Não autorizado'], 401);
}
$dados = json_decode(file_get_contents('php://input'), true);
$id = filter_var($dados['id_chamado'] ?? null, FILTER_VALIDATE_INT);
$status = $dados['status'] ?? null;
$permitidos = ['em_andamento'=>'Em andamento', 'resolvido'=>'Resolvido', 'fechado'=>'Fechado'];
if (!is_array($dados) || !$id || $id<1 || !is_string($status) || !isset($permitidos[$status])) {
    resposta(['sucesso'=>false,'erro'=>'Dados inválidos'], 400);
}
require_once __DIR__ . '/../config/conexao.php';
if (!isset($pdoTcc) || !($pdoTcc instanceof PDO)) {
    resposta(['sucesso'=>false,'erro'=>'Banco do TCC indisponível'], 503);
}
try {
    $stmt = $pdoTcc->prepare('SELECT c.status, cl.telefone FROM chamados c LEFT JOIN clientes cl ON cl.id_cliente=c.id_cliente WHERE c.id_chamado=? LIMIT 1');
    $stmt->execute([$id]);
    $chamado = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$chamado) resposta(['sucesso'=>false,'erro'=>'Chamado não encontrado'], 404);
    // A API nunca deve enviar notificação para um status que não corresponde ao banco.
    if ($chamado['status'] !== $status) resposta(['sucesso'=>false,'erro'=>'Status do banco diferente da solicitação'], 409);
    $telefone = preg_replace('/\D+/', '', (string)($chamado['telefone'] ?? ''));
    if (strlen($telefone)<10 || strlen($telefone)>15) resposta(['sucesso'=>false,'erro'=>'Cliente sem telefone válido'], 422);
} catch (Throwable $e) {
    error_log('Notificação SCT/consulta: '. $e->getMessage());
    resposta(['sucesso'=>false,'erro'=>'Falha ao consultar o chamado'], 500);
}
$token = getenv('WHATSAPP_ACCESS_TOKEN');
$phoneId = getenv('WHATSAPP_PHONE_NUMBER_ID');
$versao = getenv('WHATSAPP_GRAPH_VERSION') ?: 'v26.0';
if (!$token || !$phoneId || !preg_match('/^v[0-9]+\.[0-9]+$/', $versao) || !function_exists('curl_init')) {
    resposta(['sucesso'=>false,'erro'=>'WhatsApp não configurado'], 503);
}
// Texto comum funciona somente dentro da janela de atendimento de 24 horas.
$mensagem = "🛠️ Atualização do chamado #{$id}\nStatus: {$permitidos[$status]}.";
if ($status==='em_andamento') $mensagem .= "\nSeu chamado foi aceito pela equipe técnica.";
if ($status==='resolvido') $mensagem .= "\nSeu chamado foi resolvido pela equipe técnica.";
if ($status==='fechado') $mensagem .= "\nSeu chamado foi encerrado.";
$payload = ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$telefone,'type'=>'text','text'=>['preview_url'=>false,'body'=>$mensagem]];
$url = 'https://graph.facebook.com/'.$versao.'/'.rawurlencode($phoneId).'/messages';
$ch=curl_init($url);
curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15]);
$resultado=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);$erro=curl_error($ch);curl_close($ch);
$respostaMeta=is_string($resultado)?json_decode($resultado,true):null;
$aceito=($erro==='' && $http>=200 && $http<300 && !empty($respostaMeta['messages'][0]['id']));
if (!$aceito) {
    error_log('SCT WhatsApp falha HTTP '. $http . ' API code '.($respostaMeta['error']['code'] ?? 'indefinido'));
    resposta(['sucesso'=>false,'erro'=>'WhatsApp recusou o envio. Verifique a janela de 24 horas ou um template aprovado.'],502);
}
resposta(['sucesso'=>true,'mensagem'=>'Meta aceitou a mensagem; a entrega ainda depende dos status do webhook.'],200);
