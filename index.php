<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/auth_page.php';

start_session();
if (!tables_exist()) { header('Location: install.php'); exit; }

/* ---------- sign in ---------- */
$user = current_user();
if (!$user) {
    $error = null;
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
            $error = 'Your session expired. Try again.';
        } elseif (too_many_attempts($email)) {
            $error = 'Too many attempts. Wait 15 minutes and try again.';
        } else {
            $m = q('SELECT * FROM members WHERE email = ? AND active = 1', [$email])->fetch();
            if ($m && $m['password_hash'] && password_verify((string) ($_POST['password'] ?? ''), $m['password_hash'])) {
                if (password_needs_rehash($m['password_hash'], PASSWORD_DEFAULT)) {
                    q('UPDATE members SET password_hash = ? WHERE id = ?', [password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $m['id']]);
                }
                clear_attempts($email);
                log_in($m);
                header('Location: index.php');
                exit;
            }
            record_failed_attempt($email);
            $error = ($m && !$m['password_hash'])
                ? 'You have not set a password yet. Use the invite link your team lead sent you.'
                : 'That email and password do not match.';
        }
    }
    auth_page('Sign in',
        'Quality Assurance, Training and Events. Sign in with your work email.',
        '<label class="f">Work email<input class="field" type="email" name="email" required value="' . h($email) . '" autocomplete="username" placeholder="name@tripleonecare.nz"></label>
         <label class="f">Password<input class="field" type="password" name="password" required autocomplete="current-password"></label>
         <button class="btn primary" type="submit">Sign in</button>
         <p class="hint">Forgotten your password? Ask your team lead to send you a new link from the Team panel.</p>',
        $error);
}

/* ---------- app shell ---------- */
security_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en-NZ">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<meta name="poll-seconds" content="<?= (int) cfg('poll_seconds', 15) ?>">
<meta name="local-today" content="<?= h(date('Y-m-d')) ?>">
<title>TOC Planning Tool</title>
<link rel="icon" href="assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= h(asset('base.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('app.css')) ?>">
</head>
<body>
<header class="bar">
  <div class="bar-in">
    <div class="brand">
      <img class="logo" src="<?= h(asset('logo-160.png')) ?>" alt="Triple One Care" width="58" height="54">
      <div><h1>TOC Planning Tool</h1><small>Quality Assurance · Training · Events</small></div>
    </div>
    <button class="btn bell" id="btnBell" type="button" aria-label="Notifications"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/></svg><span class="badge" id="bellCount" hidden>0</span></button>
    <button class="btn" id="btnTeam" type="button">Team</button>
    <button class="btn" id="btnSettings" type="button" title="Settings"><span id="who"><?= h($user['name']) ?></span></button>
    <button class="btn primary" id="btnNew" type="button">+ New task</button>
  </div>
</header>

<main class="wrap">
  <div id="status" class="notice" hidden></div>
  <section class="summary" id="summary" aria-label="Summary"></section>
  <section class="load" id="load" aria-label="Workload"></section>
  <section class="controls" aria-label="Filters">
    <div class="tabs" role="tablist">
      <button class="tab" role="tab" data-view="projects" aria-selected="false" type="button">Projects</button>
      <button class="tab" role="tab" data-view="priority" aria-selected="true" type="button">Priority</button>
      <button class="tab" role="tab" data-view="board" aria-selected="false" type="button">Board</button>
      <button class="tab" role="tab" data-view="table" aria-selected="false" type="button">Table</button>
    </div>
    <div class="chips" id="teamChips"></div>
    <select class="field" id="fProject" aria-label="Project"></select>
    <select class="field" id="fOwner" aria-label="Person"></select>
    <input class="field search" id="fSearch" type="search" placeholder="Search tasks" aria-label="Search tasks">
    <label class="toggle"><input type="checkbox" id="fDone"> Show done</label>
    <a class="btn ghost" id="btnExport" href="api.php?action=export">Export CSV</a>
  </section>
  <section id="view" aria-live="polite"><div class="empty"><h2>Loading the board…</h2><p>Pulling in the team's tasks.</p></div></section>
</main>

<div id="drawerHost"></div>
<div id="toastHost"></div>
<script src="<?= h(asset('app.js')) ?>"></script>
</body>
</html>
