<?php
declare(strict_types=1);

$GLOBALS['config'] = require __DIR__ . '/../config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

function cfg(string $key): string { return (string)$GLOBALS['config'][$key]; }

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function url(string $path = ''): string { return cfg('base_url') . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }

// ---- Flash message ----
function flash(?string $msg = null, string $type = 'error'): ?array {
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

// ---- CSRF ----
function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $ok = isset($_POST['_csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf']);
    if (!$ok) { http_response_code(419); exit('Sesi tidak valid. Muat ulang halaman lalu coba lagi.'); }
}

// ---- Auth guru/admin ----
function user(): ?array { return $_SESSION['user'] ?? null; }

function login_guru(string $nip, string $password): bool {
    $st = db()->prepare('SELECT id, nip, nama, password, role FROM users WHERE nip = ? AND is_aktif = 1 LIMIT 1');
    $st->execute([$nip]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password'])) return false;
    session_regenerate_id(true);
    unset($u['password']);
    $_SESSION['user'] = $u;
    return true;
}

function require_login(): void {
    if (!user()) redirect('login.php');
}
function require_role(string $role): void {
    require_login();
    if (user()['role'] !== $role) { http_response_code(403); exit('Anda tidak punya akses ke halaman ini.'); }
}

function periode_aktif(): ?array {
    return db()->query('SELECT * FROM periode WHERE is_aktif = 1 LIMIT 1')->fetch() ?: null;
}
