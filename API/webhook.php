<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/conexao.php';
date_default_timezone_set('America/Sao_Paulo');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function responderJson(array $dados, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function enviarMensagemWhatsApp(string $destinatario, string $mensagem): bool {
    $token = getenv('WHATSAPP_ACCESS_TOKEN');
    $phoneId = getenv('WHATSAPP_PHONE_NUMBER_ID');
    $versao = getenv('WHATSAPP_GRAPH_VERSION') ?: 'v26.0';
    if (!$token || !$phoneId || !function_exists('curl_init')) {
        error_log('WHATSAPP ENVIO: token, ID ou cURL ausente');
        return false;
    }
    $url = 'https://graph.facebook.com/' . $versao . '/' . $phoneId . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
        'to' => preg_replace('/\D/', '', $destinatario), 'type' => 'text',
        'text' => ['preview_url' => false, 'body' => $mensagem]
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20
    ]);
    $resposta = curl_exec($ch);
    $erro = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($resposta) ? json_decode($resposta, true) : null;
    $ok = $erro === '' && $http >= 200 && $http < 300 && !empty($json['messages'][0]['id']);
    error_log('WHATSAPP ENVIO HTTP: ' . $http . ' | sucesso=' . ($ok ? 'sim' : 'nao'));
    if (!$ok) error_log('WHATSAPP ENVIO ERRO: ' . json_encode($json ?? ['curl' => $erro], JSON_UNESCAPED_UNICODE));
    return $ok;
}

function menuSct(string $nome): string {
    return "Olá, {$nome}! 👋\n\n*Sistema de Chamados Técnico (SCT)*\n\n*1* - Abrir novo chamado\n*2* - Consultar meus chamados\n*3* - Consultar por código\n\nDigite 1, 2 ou 3.";
}

function atualizarEtapa(PDO $pdo, string $telefone, string $nome, string $etapa, ?string $setor = null): void {
    $stmt = $pdo->prepare('UPDATE atendimento_whatsapp SET nome = ?, etapa = ?, setor = ? WHERE telefone = ?');
    $stmt->execute([$nome, $etapa, $setor, $telefone]);
}

function textoCurto(string $texto, int $limite = 110): string {
    $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;
    if (function_exists('mb_substr')) return mb_strlen($texto, 'UTF-8') > $limite ? mb_substr($texto, 0, $limite, 'UTF-8') . '...' : $texto;
    return strlen($texto) > $limite ? substr($texto, 0, $limite) . '...' : $texto;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $modo = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $desafio = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $esperado = getenv('WHATSAPP_VERIFY_TOKEN') ?: '';
    if ($modo === 'subscribe' && $esperado !== '' && hash_equals($esperado, (string) $token)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $desafio;
        exit;
    }
    responderJson(['erro' => 'Token de verificacao invalido'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') responderJson(['erro' => 'Metodo nao permitido'], 405);
$dados = json_decode(file_get_contents('php://input'), true);
if (!is_array($dados)) responderJson(['erro' => 'JSON invalido'], 400);

if (($dados['object'] ?? '') === 'whatsapp_business_account') {
    foreach (($dados['entry'] ?? []) as $entrada) {
        foreach (($entrada['changes'] ?? []) as $alteracao) {
            if (($alteracao['field'] ?? '') !== 'messages') continue;
            $valor = $alteracao['value'] ?? [];
            $idEvento = (string) ($valor['metadata']['phone_number_id'] ?? '');
            $idConfig = (string) (getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '');
            if ($idEvento !== '' && $idConfig !== '' && $idEvento !== $idConfig) continue;
            foreach (($valor['statuses'] ?? []) as $status) {
                error_log('WHATSAPP STATUS: ' . ($status['status'] ?? 'desconhecido'));
                if (($status['status'] ?? '') === 'failed') error_log('WHATSAPP FALHA: ' . json_encode($status['errors'] ?? []));
            }
            $nomes = [];
            foreach (($valor['contacts'] ?? []) as $contato) {
                if (!empty($contato['wa_id'])) $nomes[$contato['wa_id']] = $contato['profile']['name'] ?? 'Cliente';
            }
            foreach (($valor['messages'] ?? []) as $mensagem) {
                $idMensagem = (string) ($mensagem['id'] ?? '');
                $telefone = (string) ($mensagem['from'] ?? '');
                if ($idMensagem === '' || $telefone === '' || ($mensagem['type'] ?? '') !== 'text') continue;
                $texto = trim((string) ($mensagem['text']['body'] ?? ''));
