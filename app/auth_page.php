<?php
declare(strict_types=1);

/* Branded shell for the sign-in, setup and invite screens. */
function auth_page(string $title, string $intro, string $formHtml, ?string $error = null, ?string $notice = null): void {
    security_headers();
    header('Content-Type: text/html; charset=utf-8'); ?>
<!doctype html>
<html lang="en-NZ">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> · TOC Planning Tool</title>
<link rel="icon" href="assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= h(asset('base.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('app.css')) ?>">
</head>
<body>
<main class="login">
  <form class="login-card" method="post" autocomplete="on">
    <div class="login-brand"><img src="<?= h(asset('logo-160.png')) ?>" alt="Triple One Care" width="120" height="111"><span>TOC Planning Tool</span></div>
    <h2 class="login-title"><?= h($title) ?></h2>
    <p><?= $intro ?></p>
    <?php if ($error): ?><div class="msg err" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="msg ok" role="status"><?= h($notice) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?= $formHtml ?>
  </form>
</main>
</body>
</html>
<?php
    exit;
}
