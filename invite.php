<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/auth_page.php';

start_session();
$token = (string) ($_GET['t'] ?? '');
$member = member_for_invite($token);

if (!$member) {
    auth_page('Link expired', 'This link has expired or has already been used. Ask your team lead to send you a new one from the Team panel.',
        '<a class="btn primary" href="index.php">Go to sign in</a>');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Try again.';
    } else {
        $pw = (string) ($_POST['password'] ?? '');
        if ($p = password_problem($pw)) $error = $p;
        elseif ($pw !== (string) ($_POST['password2'] ?? '')) $error = 'The two passwords do not match.';
        else {
            q('UPDATE members SET password_hash = ?, invite_hash = NULL, invite_expires = NULL WHERE id = ?',
                [password_hash($pw, PASSWORD_DEFAULT), $member['id']]);
            bump_revision();
            log_in($member);
            header('Location: index.php');
            exit;
        }
    }
}

$first = explode(' ', trim($member['name']))[0];
auth_page('Welcome, ' . $first,
    'Choose a password for <strong>' . h($member['email']) . '</strong>. You will use it with your email to sign in to the board.',
    '<input type="email" name="username" value="' . h($member['email']) . '" autocomplete="username" hidden>
     <label class="f">New password<input class="field" type="password" name="password" required minlength="10" autocomplete="new-password"></label>
     <label class="f">Confirm password<input class="field" type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
     <p class="hint">At least 10 characters. A short phrase is easiest to remember.</p>
     <button class="btn primary" type="submit">Save password and open the board</button>',
    $error);
