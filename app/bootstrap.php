<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * TOC Planning Tool - shared bootstrap: config, database, session, helpers
 * ------------------------------------------------------------------ */

const OPS_TEAMS    = ['QA', 'Training', 'Events', 'General'];
const OPS_STATUSES = ['backlog', 'todo', 'doing', 'review', 'blocked', 'done'];
const OPS_ROLES    = ['', 'QA', 'Training', 'Events', 'Lead'];
const OPS_COLORS   = ['#2a2769', '#1f8a7a', '#d1567a', '#6a4bc4', '#e07a1f', '#2f6fd0', '#7a5c2e', '#4b5563'];
const OPS_PROJECT_STATUSES = ['planning', 'active', 'on_hold', 'done', 'archived'];
const OPS_TEAM_NAMES   = ['QA' => 'Quality Assurance', 'Training' => 'Training', 'Events' => 'Events', 'General' => 'General'];
const OPS_STATUS_NAMES = ['backlog' => 'Backlog', 'todo' => 'To do', 'doing' => 'In progress', 'review' => 'Review', 'blocked' => 'Blocked', 'done' => 'Done'];
const OPS_PRI_NAMES    = [1 => 'P1 Critical', 2 => 'P2 High', 3 => 'P3 Medium', 4 => 'P4 Low'];
const OPS_PROJECT_STATUS_NAMES = ['planning' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold', 'done' => 'Complete', 'archived' => 'Archived'];

const OPS_CONFIG_FILE = __DIR__ . '/../config.php';
if (is_file(OPS_CONFIG_FILE)) {
    $CONFIG = require OPS_CONFIG_FILE;
} else {
    // Not set up yet: send everything to the setup screen, which writes config.php.
    $CONFIG = ['secure_cookies' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'];
    if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'install.php') {
        header('Location: install.php');
        exit;
    }
}
date_default_timezone_set($CONFIG['timezone'] ?? 'Pacific/Auckland');

function cfg(string $key, $default = null) {
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

/* ---------------- database ---------------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(cfg('db_dsn'), cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if (db_driver() === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function db_driver(): string {
    return strtolower(explode(':', (string) cfg('db_dsn'), 2)[0]);
}
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function now_utc(): string {
    return gmdate('Y-m-d H:i:s');
}
function iso(?string $utc): ?string {
    return $utc ? str_replace(' ', 'T', $utc) . 'Z' : null;
}
function tables_exist(): bool {
    try { q('SELECT 1 FROM members LIMIT 1'); return true; }
    catch (Throwable $e) { return false; }
}
function bump_revision(): void {
    q('UPDATE meta SET value = value + 1 WHERE name = ?', ['revision']);
}
function revision(): int {
    $v = q('SELECT value FROM meta WHERE name = ?', ['revision'])->fetchColumn();
    return (int) $v;
}
function meta_get(string $name): int {
    return (int) q('SELECT value FROM meta WHERE name = ?', [$name])->fetchColumn();
}
function meta_set(string $name, int $value): void {
    if (q('SELECT 1 FROM meta WHERE name = ?', [$name])->fetchColumn()) q('UPDATE meta SET value = ? WHERE name = ?', [$value, $name]);
    else q('INSERT INTO meta (name, value) VALUES (?, ?)', [$name, $value]);
}
function local_today(): string {
    return date('Y-m-d');
}
function app_url(string $path = ''): string {
    return rtrim((string) cfg('app_url', ''), '/') . '/' . ltrim($path, '/');
}

/* Settings stored in the database (email server etc.), editable by admins in the app. */
function setting(string $name, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (q('SELECT name, value FROM settings')->fetchAll() as $r) $cache[$r['name']] = $r['value']; }
        catch (Throwable $e) { /* table not created yet */ }
    }
    if (func_num_args() === 3) { $cache[$name] = func_get_arg(2); return null; }
    return array_key_exists($name, $cache) ? $cache[$name] : $default;
}
function set_setting(string $name, string $value): void {
    $exists = q('SELECT 1 FROM settings WHERE name = ?', [$name])->fetchColumn();
    if ($exists) q('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
    else q('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
    setting($name, '', $value);
}

/* ---------------- session ---------------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('tocops');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 14,
        'path'     => '/',
        'secure'   => (bool) cfg('secure_cookies', true),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 14));
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_token(): string {
    start_session();
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        json_out(['error' => 'Your session has expired. Reload the page and try again.'], 419);
    }
}

/* ---------------- users ---------------- */
function current_user(): ?array {
    start_session();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return null;
    static $cache = null;
    if ($cache && (int) $cache['id'] === (int) $id) return $cache;
    $u = q('SELECT * FROM members WHERE id = ? AND active = 1', [$id])->fetch();
    if (!$u) { unset($_SESSION['uid']); return null; }
    return $cache = $u;
}
function public_member(array $m, bool $withPrefs = false): array {
    $out = [
        'id'        => (int) $m['id'],
        'name'      => $m['name'],
        'email'     => $m['email'],
        'role'      => $m['role'],
        'color'     => $m['color'],
        'is_admin'  => (bool) $m['is_admin'],
        'has_login' => !empty($m['password_hash']),
        'last_login'=> iso($m['last_login']),
    ];
    if ($withPrefs) {
        $out['prefs'] = [
            'assigned' => (bool) $m['notify_assigned'],
            'comments' => (bool) $m['notify_comments'],
            'projects' => (bool) $m['notify_projects'],
            'digest'   => (bool) $m['notify_digest'],
        ];
    }
    return $out;
}
function log_in(array $user): void {
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE members SET last_login = ? WHERE id = ?', [now_utc(), $user['id']]);
}
function log_out(): void {
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* Simple brute-force protection: 8 failed attempts per email or IP in 15 minutes. */
function too_many_attempts(string $email): bool {
    $since = gmdate('Y-m-d H:i:s', time() - 900);
    $ip = client_ip();
    $n = (int) q('SELECT COUNT(*) FROM login_attempts WHERE attempted_at > ? AND (email = ? OR ip = ?)', [$since, $email, $ip])->fetchColumn();
    return $n >= 8;
}
function record_failed_attempt(string $email): void {
    q('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)', [$email, client_ip(), now_utc()]);
    q('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
}
function clear_attempts(string $email): void {
    q('DELETE FROM login_attempts WHERE email = ?', [$email]);
}
function client_ip(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

/* Invite / reset links: a random token is shown once; only its hash is stored. */
function issue_invite(int $memberId): string {
    $token = bin2hex(random_bytes(24));
    $expires = gmdate('Y-m-d H:i:s', time() + 3600 * (int) cfg('invite_hours', 72));
    q('UPDATE members SET invite_hash = ?, invite_expires = ? WHERE id = ?', [hash('sha256', $token), $expires, $memberId]);
    return rtrim((string) cfg('app_url'), '/') . '/invite.php?t=' . $token;
}
function member_for_invite(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $m = q('SELECT * FROM members WHERE invite_hash = ? AND active = 1', [hash('sha256', $token)])->fetch();
    if (!$m || !$m['invite_expires'] || $m['invite_expires'] < now_utc()) return null;
    return $m;
}
function password_problem(string $pw): ?string {
    if (strlen($pw) < 10) return 'Use at least 10 characters.';
    if (strlen($pw) > 200) return 'That password is too long.';
    return null;
}

/* ---------------- output ---------------- */
function security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function h($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function asset(string $path): string {
    $file = dirname(__DIR__) . '/assets/' . $path;
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '1';
    return 'assets/' . $path . '?v=' . $v;
}
