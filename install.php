<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/schema.php';
require __DIR__ . '/app/auth_page.php';

/* ------------------------------------------------------------------
 * One-time setup.
 *   Step 1: database details -> writes config.php
 *   Step 2: lead account
 * Delete this file from the server once setup is finished.
 * ------------------------------------------------------------------ */

start_session();

function post_ok(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''));
}
function field(string $label, string $name, string $value = '', string $type = 'text', string $extra = ''): string {
    return '<label class="f">' . h($label) . '<input class="field" type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '" ' . $extra . '></label>';
}

/* ---------- Step 1: database ---------- */
if (!is_file(OPS_CONFIG_FILE)) {
    $error = null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $guessUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
    $v = [
        'db_host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
        'db_name' => trim((string) ($_POST['db_name'] ?? '')),
        'db_user' => trim((string) ($_POST['db_user'] ?? '')),
        'app_url' => rtrim(trim((string) ($_POST['app_url'] ?? $guessUrl)), '/'),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!post_ok()) $error = 'Your session expired. Try again.';
        elseif ($v['db_name'] === '' || $v['db_user'] === '') $error = 'Enter the database name and user from hPanel.';
        elseif (!preg_match('#^https?://[^\s/]+#', $v['app_url'])) $error = 'Enter the full site address, starting with https://';
        else {
            $dsn = 'mysql:host=' . $v['db_host'] . ';dbname=' . $v['db_name'] . ';charset=utf8mb4';
            try {
                new PDO($dsn, $v['db_user'], (string) ($_POST['db_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $config = [
                    'db_dsn'         => $dsn,
                    'db_user'        => $v['db_user'],
                    'db_pass'        => (string) ($_POST['db_pass'] ?? ''),
                    'app_url'        => $v['app_url'],
                    'timezone'       => 'Pacific/Auckland',
                    'secure_cookies' => str_starts_with($v['app_url'], 'https://'),
                    'poll_seconds'   => 15,
                    'invite_hours'   => 72,
                ];
                $php = "<?php\n// TOC Planning Tool configuration, written by install.php on " . date('Y-m-d H:i') . ".\nreturn " . var_export($config, true) . ";\n";
                if (@file_put_contents(OPS_CONFIG_FILE, $php, LOCK_EX) === false) {
                    $error = 'The database connected, but config.php could not be saved. Check the folder is writable in File Manager, or upload config.php by hand using config.sample.php.';
                } else {
                    @chmod(OPS_CONFIG_FILE, 0640);
                    header('Location: install.php');
                    exit;
                }
            } catch (Throwable $e) {
                $error = 'Could not connect to that database. Check the name, user and password match hPanel exactly. (' . $e->getMessage() . ')';
            }
        }
    }

    auth_page('Set up the board: database',
        'In Hostinger hPanel open <strong>Databases → MySQL Databases</strong>, create a database and user, then enter the same details here. Host is usually <code>localhost</code>.',
        field('Database name', 'db_name', $v['db_name'], 'text', 'required placeholder="u123456789_tocops" autocomplete="off"') .
        field('Database user', 'db_user', $v['db_user'], 'text', 'required placeholder="u123456789_tocops" autocomplete="off"') .
        field('Database password', 'db_pass', '', 'password', 'autocomplete="new-password"') .
        field('Database host', 'db_host', $v['db_host'], 'text', 'required') .
        field('Site address', 'app_url', $v['app_url'], 'url', 'required') .
        '<p class="hint">Site address is where the board lives, for example https://planning.tripleonecare.online. Invite links use it.</p>
         <button class="btn primary" type="submit">Connect and continue</button>',
        $error);
}

/* ---------- Step 2: lead account ---------- */
try {
    create_schema();
} catch (Throwable $e) {
    auth_page('Setup', 'The board could not reach its database.', '<a class="btn primary" href="install.php">Try again</a>',
        'Check the details in config.php. (' . $e->getMessage() . ')');
}

if ((int) q('SELECT COUNT(*) FROM members WHERE is_admin = 1')->fetchColumn() > 0) {
    auth_page('Setup complete', 'The board is ready. For security, delete <code>install.php</code> from the server in File Manager.',
        '<a class="btn primary" href="index.php">Go to sign in</a>');
}

$error = null;
$name = trim((string) ($_POST['name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string) ($_POST['password'] ?? '');
    if (!post_ok()) $error = 'Your session expired. Try again.';
    elseif ($name === '') $error = 'Enter your name.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif ($p = password_problem($pw)) $error = $p;
    elseif ($pw !== (string) ($_POST['password2'] ?? '')) $error = 'The two passwords do not match.';
    else {
        q('INSERT INTO members (name, email, role, color, is_admin, active, password_hash, created_at) VALUES (?, ?, ?, ?, 1, 1, ?, ?)',
            [$name, $email, 'Lead', OPS_COLORS[0], password_hash($pw, PASSWORD_DEFAULT), now_utc()]);
        bump_revision();
        log_in(q('SELECT * FROM members WHERE email = ?', [$email])->fetch());
        header('Location: index.php');
        exit;
    }
}

auth_page('Set up the board: lead account',
    'Database connected. Now create your own account. You can add the rest of the team once you are signed in.',
    field('Your name', 'name', $name, 'text', 'required autocomplete="name"') .
    field('Work email', 'email', $email, 'email', 'required autocomplete="email"') .
    field('Password', 'password', '', 'password', 'required minlength="10" autocomplete="new-password"') .
    field('Confirm password', 'password2', '', 'password', 'required minlength="10" autocomplete="new-password"') .
    '<button class="btn primary" type="submit">Create lead account</button>',
    $error);
