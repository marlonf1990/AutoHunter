<?php
// Valida o ID token do Google e abre sessão. Defina GOOGLE_CLIENT_ID no servidor (mesmo valor do index.html).
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax', 'path' => '/']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($code, $data) { http_response_code($code); echo json_encode($data); exit; }

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    out(200, ['ok' => true]);
}

// Verifica sessão existente (usado ao carregar a página)
if (isset($_GET['check'])) {
    if (!empty($_SESSION['uid'])) out(200, ['ok' => true, 'user' => $_SESSION['user'] ?? ['name' => 'Usuário', 'picture' => '']]);
    out(401, ['ok' => false]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false]);

$cid = getenv('GOOGLE_CLIENT_ID') ?: 'SEU_CLIENT_ID.apps.googleusercontent.com';
if (strpos($cid, 'SEU_CLIENT_ID') === 0) out(500, ['ok' => false, 'error' => 'GOOGLE_CLIENT_ID não configurado']);

$body = json_decode((string)file_get_contents('php://input'), true);
$tok = is_array($body) ? (string)($body['credential'] ?? '') : '';
if ($tok === '' || strlen($tok) > 4096) out(400, ['ok' => false]);

$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($tok));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$u = json_decode((string)$raw, true);
if (!is_array($u)) out(401, ['ok' => false]);

$okAud = hash_equals($cid, (string)($u['aud'] ?? ''));
$okIss = in_array($u['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
$okExp = (int)($u['exp'] ?? 0) > time();
$okMail = ($u['email_verified'] ?? 'false') === 'true' || ($u['email_verified'] ?? false) === true;
if ($code !== 200 || !$okAud || !$okIss || !$okExp || !$okMail || empty($u['sub'])) out(401, ['ok' => false]);

session_regenerate_id(true); // evita fixação de sessão
$_SESSION['uid'] = $u['sub'];
$_SESSION['user'] = ['name' => $u['name'] ?? 'Usuário', 'picture' => $u['picture'] ?? ''];
// Opcional: grave $u['sub'], $u['email'], $u['name'] em um banco para sua lista de usuários.
out(200, ['ok' => true, 'user' => $_SESSION['user']]);
