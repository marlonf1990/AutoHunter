<?php
// Busca preços de peças no Mercado Livre. Uso: api/pecas.php?q=Filtro de óleo Fiat Argo 2020
// Se a API exigir autenticação, defina ML_ACCESS_TOKEN no servidor (variável de ambiente).
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 120) out(400, ['error' => 'Consulta inválida.']);

// Limite simples por sessão: 20 buscas a cada 10 minutos
$now = time();
$_SESSION['pq'] = array_values(array_filter($_SESSION['pq'] ?? [], fn($t) => $now - $t < 600));
if (count($_SESSION['pq']) >= 20) out(429, ['error' => 'Muitas buscas. Aguarde alguns minutos.']);
$_SESSION['pq'][] = $now;

// Cache em arquivo por 1 hora
$cf = sys_get_temp_dir() . '/ah_pecas_' . md5(mb_strtolower($q)) . '.json';
if (is_file($cf) && $now - filemtime($cf) < 3600) { echo file_get_contents($cf); exit; }

$headers = ['Accept: application/json'];
$tok = getenv('ML_ACCESS_TOKEN');
if ($tok) $headers[] = 'Authorization: Bearer ' . $tok;

$ch = curl_init('https://api.mercadolibre.com/sites/MLB/search?limit=8&q=' . urlencode($q));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => $headers]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    out(502, ['error' => $code === 403 || $code === 401
        ? 'A API do Mercado Livre exige autenticação (configure ML_ACCESS_TOKEN no servidor).'
        : 'Não foi possível consultar o Mercado Livre agora.']);
}
$j = json_decode((string)$raw, true);
$res = [];
foreach (($j['results'] ?? []) as $r) {
    $link = (string)($r['permalink'] ?? '');
    if (strpos($link, 'https://') !== 0) continue;
    $res[] = ['title' => (string)($r['title'] ?? ''), 'price' => (float)($r['price'] ?? 0), 'link' => $link];
}
$json = json_encode(['results' => $res], JSON_UNESCAPED_UNICODE);
@file_put_contents($cf, $json);
echo $json;
