<?php
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

/* ------------------------------------------------------------------
 * Notifications: in-app bell + email.
 *   notify()               records a notification and queues its email
 *   process_email_queue()  sends queued emails (after each request and from cron.php)
 *   send_digests()         one morning email per person: overdue, due today, due tomorrow
 *   run_jobs()             both of the above, with a lock so they never overlap
 * ------------------------------------------------------------------ */

const NOTIFY_PREF = [
    'assigned'      => 'notify_assigned',
    'comment'       => 'notify_comments',
    'completed'     => 'notify_comments',
    'project_note'  => 'notify_projects',
    'project_added' => 'notify_projects',
];

/** Queue a notification for each recipient (never the person who caused it). */
function notify(array $recipientIds, string $type, ?int $actorId, ?int $taskId, ?int $projectId, string $subject, string $message = ''): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $recipientIds), fn($id) => $id > 0 && $id !== (int) $actorId)));
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $members = q("SELECT * FROM members WHERE active = 1 AND id IN ($ph)", $ids)->fetchAll();
    $mailOn = mail_enabled();
    $n = 0;
    foreach ($members as $m) {
        $pref = NOTIFY_PREF[$type] ?? null;
        $wantsEmail = $mailOn && $pref && (int) $m[$pref] === 1;
        q('INSERT INTO notifications (member_id, type, task_id, project_id, actor_id, subject, message, created_at, email_state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$m['id'], $type, $taskId, $projectId, $actorId, mb_substr($subject, 0, 300), $message, now_utc(), $wantsEmail ? 'pending' : 'skipped']);
        $n++;
    }
    if ($n) $GLOBALS['ops_mail_pending'] = true;
    return $n;
}

/* ---------------- email templates ---------------- */

function email_layout(string $heading, string $introHtml, string $bodyHtml, ?string $buttonUrl, string $buttonLabel, string $footerNote): string {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $btn = $buttonUrl ? '<tr><td style="padding:8px 28px 28px"><a href="' . $h($buttonUrl) . '" style="display:inline-block;background:#2a2769;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 20px;border-radius:8px">' . $h($buttonLabel) . '</a></td></tr>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($heading) . '</title></head>
<body style="margin:0;padding:0;background:#f5f5f9;font-family:Arial,Helvetica,sans-serif;color:#1b1a33">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f9;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #dcdbe8">
<tr><td style="background:#2a2769;padding:18px 28px;border-bottom:4px solid #f9c81d">
  <table role="presentation" cellpadding="0" cellspacing="0"><tr>
    <td style="width:70px;vertical-align:middle"><img src="' . $h(app_url('assets/logo-160.png')) . '" width="70" height="65" alt="Triple One Care" style="display:block;border:0;width:70px;height:auto"></td>
    <td style="padding-left:12px;color:#ffffff;font-weight:800;font-size:18px;vertical-align:middle">TOC Planning Tool<div style="font-weight:400;font-size:11px;letter-spacing:1px;opacity:.8;text-transform:uppercase">Triple One Care</div></td>
  </tr></table>
</td></tr>
<tr><td style="padding:26px 28px 6px"><h1 style="margin:0 0 10px;font-size:20px;line-height:1.3;color:#1b1a33">' . $h($heading) . '</h1>
<div style="font-size:14px;line-height:1.55;color:#5e5d7a">' . $introHtml . '</div></td></tr>
<tr><td style="padding:10px 28px">' . $bodyHtml . '</td></tr>
' . $btn . '
<tr><td style="padding:16px 28px;background:#eeeef5;font-size:12px;line-height:1.5;color:#5e5d7a">' . $h($footerNote) . '</td></tr>
</table></td></tr></table></body></html>';
}

function email_task_card(array $t, ?string $projectName, array $assigneeNames): string {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $priColor = [1 => '#c62c3b', 2 => '#e07a1f', 3 => '#2f6fd0', 4 => '#7a7994'][(int) $t['priority']] ?? '#2f6fd0';
    $row = fn($k, $v) => '<tr><td style="padding:4px 12px 4px 0;font-size:12px;color:#5e5d7a;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;vertical-align:top">' . $k . '</td><td style="padding:4px 0;font-size:14px;color:#1b1a33">' . $v . '</td></tr>';
    $due = $t['due_date'] ? $h(date('D j M Y', strtotime($t['due_date']))) : 'No due date';
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dcdbe8;border-left:4px solid ' . $priColor . ';border-radius:8px"><tr><td style="padding:14px 16px">
      <div style="font-size:16px;font-weight:700;margin-bottom:8px;color:#1b1a33">' . $h($t['title']) . '</div>
      <table role="presentation" cellpadding="0" cellspacing="0">'
        . ($projectName ? $row('Project', $h($projectName)) : '')
        . $row('Team', $h(OPS_TEAM_NAMES[$t['team']] ?? $t['team']))
        . $row('Priority', '<span style="color:' . $priColor . ';font-weight:700">' . $h(OPS_PRI_NAMES[(int) $t['priority']] ?? '') . '</span>')
        . $row('Status', $h(OPS_STATUS_NAMES[$t['status']] ?? $t['status']))
        . $row('Due', $due)
        . ($assigneeNames ? $row('Assigned', $h(implode(', ', $assigneeNames))) : '')
      . '</table></td></tr></table>';
}

function email_quote(string $text, string $who): string {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    return '<div style="margin-top:14px;background:#fff8dc;border-left:4px solid #f9c81d;border-radius:6px;padding:12px 14px;font-size:14px;line-height:1.55;color:#1b1a33">'
        . '<div style="font-size:12px;color:#5e5d7a;margin-bottom:6px;font-weight:700">' . $h($who) . ' wrote</div>'
        . nl2br($h($text)) . '</div>';
}

/** Turns a stored notification into [subject, html, text]. */
function render_notification_email(array $n, array $recipient): array {
    $actor = $n['actor_id'] ? q('SELECT name FROM members WHERE id = ?', [$n['actor_id']])->fetchColumn() : null;
    $actor = $actor ?: 'Someone';
    $task = $n['task_id'] ? q('SELECT * FROM tasks WHERE id = ?', [$n['task_id']])->fetch() : null;
    $projectId = $n['project_id'] ?: ($task['project_id'] ?? null);
    $project = $projectId ? q('SELECT * FROM projects WHERE id = ?', [$projectId])->fetch() : null;
    $first = explode(' ', trim($recipient['name']))[0];
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $body = '';
    $text = "Hi $first,\n\n" . $n['subject'] . "\n";
    if ($task) {
        $names = q('SELECT m.name FROM task_assignees a JOIN members m ON m.id = a.member_id WHERE a.task_id = ? ORDER BY m.name', [$task['id']])->fetchAll(PDO::FETCH_COLUMN);
        $body .= email_task_card($task, $project['name'] ?? null, $names);
        $text .= "\nTask: {$task['title']}\n"
            . ($project ? "Project: {$project['name']}\n" : '')
            . 'Priority: ' . (OPS_PRI_NAMES[(int) $task['priority']] ?? '') . "\n"
            . 'Status: ' . (OPS_STATUS_NAMES[$task['status']] ?? '') . "\n"
            . 'Due: ' . ($task['due_date'] ? date('D j M Y', strtotime($task['due_date'])) : 'No due date') . "\n"
            . ($names ? 'Assigned: ' . implode(', ', $names) . "\n" : '');
        $url = app_url('index.php#task-' . $task['id']);
        $label = 'Open task';
    } elseif ($project) {
        $body .= '<div style="font-size:16px;font-weight:700;border:1px solid #dcdbe8;border-left:4px solid ' . $h($project['color']) . ';border-radius:8px;padding:14px 16px">' . $h($project['name'])
            . '<div style="font-weight:400;font-size:13px;color:#5e5d7a;margin-top:4px">' . $h(OPS_TEAM_NAMES[$project['team']] ?? '') . ' · ' . $h(OPS_PROJECT_STATUS_NAMES[$project['status']] ?? '')
            . ($project['due_date'] ? ' · due ' . $h(date('j M Y', strtotime($project['due_date']))) : '') . '</div></div>';
        $text .= "\nProject: {$project['name']}\n";
        $url = app_url('index.php#project-' . $project['id']);
        $label = 'Open project';
    } else {
        $url = app_url('index.php');
        $label = 'Open the board';
    }
    if (trim((string) $n['message']) !== '') {
        $body .= email_quote($n['message'], $actor);
        $text .= "\n$actor wrote:\n" . $n['message'] . "\n";
    }
    $text .= "\n$label: $url\n\nYou can change which emails you get under Settings on the board.\n";

    $intro = 'Hi ' . $h($first) . ',';
    $html = email_layout($n['subject'], $intro, $body, $url, $label,
        'You are getting this because of your notification settings on the TOC Planning Tool. Change them any time under Settings.');
    return [$n['subject'], $html, $text];
}

/* ---------------- queue ---------------- */

function process_email_queue(int $limit = 25): array {
    $sent = 0; $failed = 0;
    if (!mail_enabled()) {
        q("UPDATE notifications SET email_state = 'skipped' WHERE email_state IN ('pending','sending')");
        return [0, 0];
    }
    // Release rows stuck mid-send (e.g. a request that timed out)
    q("UPDATE notifications SET email_state = 'pending' WHERE email_state = 'sending' AND emailed_at < ?", [gmdate('Y-m-d H:i:s', time() - 600)]);

    $rows = q("SELECT * FROM notifications WHERE email_state = 'pending' AND email_attempts < 3 ORDER BY id LIMIT " . max(1, $limit))->fetchAll();
    foreach ($rows as $n) {
        $claim = q("UPDATE notifications SET email_state = 'sending', emailed_at = ?, email_attempts = email_attempts + 1 WHERE id = ? AND email_state = 'pending'", [now_utc(), $n['id']]);
        if ($claim->rowCount() !== 1) continue;
        $m = q('SELECT * FROM members WHERE id = ? AND active = 1', [$n['member_id']])->fetch();
        if (!$m) { q("UPDATE notifications SET email_state = 'skipped' WHERE id = ?", [$n['id']]); continue; }
        try {
            [$subject, $html, $text] = render_notification_email($n, $m);
            send_mail($m['email'], $m['name'], $subject, $html, $text);
            q("UPDATE notifications SET email_state = 'sent', emailed_at = ? WHERE id = ?", [now_utc(), $n['id']]);
            $sent++;
        } catch (Throwable $e) {
            $final = ((int) $n['email_attempts'] + 1) >= 3;
            q('UPDATE notifications SET email_state = ? WHERE id = ?', [$final ? 'failed' : 'pending', $n['id']]);
            set_setting('mail_last_error', date('j M H:i') . ' — ' . $e->getMessage());
            error_log('[toc-ops] email failed: ' . $e->getMessage());
            $failed++;
            if ($e instanceof MailError && str_contains($e->getMessage(), 'connect')) break; // server down: stop for now
        }
    }
    return [$sent, $failed];
}

/* ---------------- daily due-date digest ---------------- */

function send_digests(bool $ignoreHour = false): int {
    if (!mail_enabled()) return 0;
    $hour = (int) setting('digest_hour', '7');
    if (!$ignoreHour && (int) date('G') < $hour) return 0;
    $today = local_today();
    $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
    $isWeekend = in_array((int) date('N'), [6, 7], true);
    if ($isWeekend && setting('digest_weekends', '0') !== '1' && !$ignoreHour) return 0;

    $members = q('SELECT * FROM members WHERE active = 1 AND notify_digest = 1 AND (last_digest_date IS NULL OR last_digest_date < ?)', [$today])->fetchAll();
    $sent = 0;
    foreach ($members as $m) {
        $tasks = q("SELECT t.*, p.name AS project_name FROM tasks t
                    JOIN task_assignees a ON a.task_id = t.id AND a.member_id = ?
                    LEFT JOIN projects p ON p.id = t.project_id
                    WHERE t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date <= ?
                    ORDER BY t.due_date, t.priority", [$m['id'], $tomorrow])->fetchAll();
        if (!$tasks) { q('UPDATE members SET last_digest_date = ? WHERE id = ?', [$today, $m['id']]); continue; }

        $groups = ['Overdue' => [], 'Due today' => [], 'Due tomorrow' => []];
        foreach ($tasks as $t) {
            $k = $t['due_date'] < $today ? 'Overdue' : ($t['due_date'] === $today ? 'Due today' : 'Due tomorrow');
            $groups[$k][] = $t;
        }
        [$subject, $html, $text] = render_digest($m, $groups);
        try {
            send_mail($m['email'], $m['name'], $subject, $html, $text);
            q('UPDATE members SET last_digest_date = ? WHERE id = ?', [$today, $m['id']]);
            $sent++;
        } catch (Throwable $e) {
            set_setting('mail_last_error', date('j M H:i') . ' — ' . $e->getMessage());
            error_log('[toc-ops] digest failed: ' . $e->getMessage());
            break;
        }
    }
    return $sent;
}

function render_digest(array $m, array $groups): array {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $first = explode(' ', trim($m['name']))[0];
    $counts = array_filter(array_map('count', $groups));
    $parts = [];
    foreach ($counts as $k => $c) $parts[] = $c . ' ' . strtolower($k);
    $subject = 'Your tasks: ' . implode(', ', $parts);

    $colors = ['Overdue' => '#c62c3b', 'Due today' => '#b77a00', 'Due tomorrow' => '#2f6fd0'];
    $body = ''; $text = "Hi $first,\n\nHere's what needs your attention on the TOC Planning Tool.\n";
    foreach ($groups as $label => $list) {
        if (!$list) continue;
        $body .= '<div style="margin:6px 0 8px;font-size:12px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:' . $colors[$label] . '">' . $h($label) . ' (' . count($list) . ')</div>';
        $text .= "\n" . strtoupper($label) . "\n";
        foreach ($list as $t) {
            $url = app_url('index.php#task-' . $t['id']);
            $meta = (OPS_PRI_NAMES[(int) $t['priority']] ?? '') . ' · ' . date('D j M', strtotime($t['due_date'])) . ($t['project_name'] ? ' · ' . $t['project_name'] : '');
            $body .= '<a href="' . $h($url) . '" style="display:block;text-decoration:none;border:1px solid #dcdbe8;border-left:4px solid ' . $colors[$label] . ';border-radius:8px;padding:10px 14px;margin-bottom:8px">'
                . '<span style="display:block;font-size:15px;font-weight:700;color:#1b1a33">' . $h($t['title']) . '</span>'
                . '<span style="display:block;font-size:12px;color:#5e5d7a;margin-top:3px">' . $h($meta) . '</span></a>';
            $text .= "- {$t['title']} ($meta)\n  $url\n";
        }
    }
    $text .= "\nOpen the board: " . app_url('index.php') . "\n\nTurn these reminders off under Settings on the board.\n";
    $html = email_layout('Here’s what’s due', 'Hi ' . $h($first) . ', here’s what needs your attention today.', $body, app_url('index.php'), 'Open the board',
        'Daily reminder from the TOC Planning Tool. Turn it off any time under Settings.');
    return [$subject, $html, $text];
}

/* ---------------- job runner ---------------- */

/** Runs queued emails and the daily digest at most once per $minGap seconds across all requests. */
function run_jobs(int $minGap = 300): ?array {
    $now = time();
    $got = q('UPDATE meta SET value = ? WHERE name = ? AND value < ?', [$now, 'jobs_ran_at', $now - $minGap])->rowCount();
    if ($got !== 1) return null;
    [$sent, $failed] = process_email_queue(50);
    $digests = send_digests();
    // Housekeeping: drop read notifications older than 120 days
    q('DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < ?', [gmdate('Y-m-d H:i:s', $now - 86400 * 120)]);
    return ['sent' => $sent, 'failed' => $failed, 'digests' => $digests];
}

/** Call at the end of a web request: finishes the HTTP response, then sends any emails just queued. */
function finish_request_and_send(): void {
    $queued = !empty($GLOBALS['ops_mail_pending']);
    $opportunistic = !empty($GLOBALS['ops_run_jobs']);
    if (!$queued && !$opportunistic) return;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    ignore_user_abort(true);
    @set_time_limit(60);
    try {
        if ($queued) process_email_queue(15);
        if ($opportunistic) run_jobs(300);
    } catch (Throwable $e) {
        error_log('[toc-ops] background send failed: ' . $e->getMessage());
    }
}
