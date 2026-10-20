<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/notify.php';

/* ------------------------------------------------------------------
 * TOC Planning Tool JSON API
 *   GET  state | poll | notifications | task_detail&id= | project_detail&id= | export
 *   POST task_save | task_patch | task_delete
 *        comment_add | comment_delete
 *        project_save | project_delete
 *        notifications_read | prefs_save | password_change
 *        team_save | invite_link | invite_email            (admins)
 *        mail_settings_save | mail_test                    (admins)
 *        logout
 * POST bodies are JSON and must send the X-CSRF-Token header.
 * Emails queued during a request are sent after the response is delivered.
 * ------------------------------------------------------------------ */

register_shutdown_function('finish_request_and_send');
start_session();
$me = current_user();
$action = (string) ($_GET['action'] ?? '');

if (!$me) json_out(['error' => 'Your session has ended. Sign in again.', 'signed_out' => true], 401);

$method = $_SERVER['REQUEST_METHOD'];
$body = [];
if ($method === 'POST') {
    check_csrf();
    $raw = file_get_contents('php://input') ?: '';
    $body = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) json_out(['error' => 'Bad request.'], 400);
}
$admin = (bool) (int) $me['is_admin'];

try {
    switch ("$method $action") {
        case 'GET state':            json_out(state_payload($me));
        case 'GET poll':             $GLOBALS['ops_run_jobs'] = true; json_out(['revision' => revision(), 'unread' => unread_count((int) $me['id'])]);
        case 'GET notifications':    json_out(notifications_list((int) $me['id']));
        case 'GET task_detail':      json_out(task_detail($me, (int) ($_GET['id'] ?? 0)));
        case 'GET project_detail':   json_out(project_detail($me, (int) ($_GET['id'] ?? 0)));
        case 'GET export':           export_csv();

        case 'POST task_save':       json_out(['task' => task_save($me, $body['task'] ?? []), 'revision' => revision()]);
        case 'POST task_patch':      json_out(['task' => task_save($me, ['id' => (int) ($body['id'] ?? 0)] + ($body['fields'] ?? [])), 'revision' => revision()]);
        case 'POST task_delete':     task_delete($me, (int) ($body['id'] ?? 0)); json_out(['ok' => true, 'revision' => revision()]);
        case 'POST comment_add':     json_out(comment_add($me, $body));
        case 'POST comment_delete':  comment_delete($me, (int) ($body['id'] ?? 0)); json_out(['ok' => true, 'revision' => revision()]);
        case 'POST project_save':    json_out(['project' => project_save($me, $body['project'] ?? []), 'revision' => revision()]);
        case 'POST project_delete':  project_delete($me, (int) ($body['id'] ?? 0)); json_out(['ok' => true, 'revision' => revision()]);
        case 'POST notifications_read': notifications_read((int) $me['id'], $body); json_out(['unread' => unread_count((int) $me['id'])]);
        case 'POST prefs_save':      prefs_save($me, $body['prefs'] ?? []); json_out(['me' => public_member(fresh_member((int) $me['id']), true)]);
        case 'POST password_change': password_change($me, $body); json_out(['ok' => true]);

        case 'POST team_save':       require_admin($admin); json_out(team_save($me, $body['members'] ?? []));
        case 'POST invite_link':     require_admin($admin); json_out(['url' => invite_link((int) ($body['id'] ?? 0))]);
        case 'POST invite_email':    require_admin($admin); json_out(invite_email($me, (int) ($body['id'] ?? 0)));
        case 'POST mail_settings_save': require_admin($admin); mail_settings_save($body['settings'] ?? []); json_out(['mail' => mail_status(true)]);
        case 'POST mail_test':       require_admin($admin); json_out(mail_test($me));

        case 'POST logout':          log_out(); json_out(['ok' => true]);
        default:                     json_out(['error' => 'Unknown action.'], 404);
    }
} catch (InvalidArgumentException $e) {
    json_out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[toc-ops] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => 'Something went wrong on the server. Try again.'], 500);
}

/* =================================================================
 * helpers
 * ================================================================= */
function require_admin(bool $admin): void {
    if (!$admin) json_out(['error' => 'Only a team admin can do that.'], 403);
}
function fresh_member(int $id): array {
    return q('SELECT * FROM members WHERE id = ?', [$id])->fetch();
}
function in_list(array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return [$ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL', $ids];
}
function active_member_ids(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), fn($i) => $i > 0)));
    if (!$ids) return [];
    [$ph, $p] = in_list($ids);
    $ok = array_map('intval', q("SELECT id FROM members WHERE active = 1 AND id IN ($ph)", $p)->fetchAll(PDO::FETCH_COLUMN));
    if (count($ok) !== count($ids)) throw new InvalidArgumentException('Someone you picked is no longer on the team. Reload and try again.');
    return $ok;
}
function clean_date($v): ?string {
    $v = trim((string) ($v ?? ''));
    if ($v === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) throw new InvalidArgumentException('Use a real date.');
    return $v;
}
function nice_date(?string $d): string {
    return $d ? date('j M Y', strtotime($d)) : 'no date';
}
function member_name(?int $id): string {
    if (!$id) return 'Someone';
    static $c = [];
    return $c[$id] ??= (string) (q('SELECT name FROM members WHERE id = ?', [$id])->fetchColumn() ?: 'Someone');
}
function log_activity(?int $taskId, ?int $projectId, int $memberId, string $detail): void {
    q('INSERT INTO activity (task_id, project_id, member_id, detail, created_at) VALUES (?, ?, ?, ?, ?)',
        [$taskId, $projectId, $memberId, mb_substr($detail, 0, 400), now_utc()]);
}
function unread_count(int $memberId): int {
    return (int) q('SELECT COUNT(*) FROM notifications WHERE member_id = ? AND read_at IS NULL', [$memberId])->fetchColumn();
}

/* =================================================================
 * state
 * ================================================================= */
function assignee_map(?array $taskIds = null): array {
    $map = [];
    $rows = $taskIds === null
        ? q('SELECT task_id, member_id FROM task_assignees')->fetchAll()
        : (function () use ($taskIds) { [$ph, $p] = in_list($taskIds); return q("SELECT task_id, member_id FROM task_assignees WHERE task_id IN ($ph)", $p)->fetchAll(); })();
    foreach ($rows as $r) $map[(int) $r['task_id']][] = (int) $r['member_id'];
    return $map;
}
function task_out(array $t, array $assignees, int $comments = 0): array {
    $subs = json_decode((string) $t['subtasks'], true);
    return [
        'id'        => (int) $t['id'],
        'project'   => $t['project_id'] !== null ? (int) $t['project_id'] : null,
        'title'     => $t['title'],
        'team'      => $t['team'],
        'assignees' => $assignees,
        'priority'  => (int) $t['priority'],
        'status'    => $t['status'],
        'rank'      => (float) $t['rank_order'],
        'start'     => $t['start_date'] ?: '',
        'due'       => $t['due_date'] ?: '',
        'notes'     => $t['notes'],
        'subtasks'  => is_array($subs) ? $subs : [],
        'comments'  => $comments,
        'doneAt'    => iso($t['done_at']),
        'created'   => iso($t['created_at']),
        'updated'   => iso($t['updated_at']),
        'createdBy' => $t['created_by'] !== null ? (int) $t['created_by'] : null,
        'updatedBy' => $t['updated_by'] !== null ? (int) $t['updated_by'] : null,
    ];
}
function one_task(int $id): array {
    $t = q('SELECT * FROM tasks WHERE id = ?', [$id])->fetch();
    if (!$t) throw new InvalidArgumentException('That task has been deleted.');
    $c = (int) q('SELECT COUNT(*) FROM comments WHERE task_id = ?', [$id])->fetchColumn();
    return task_out($t, assignee_map([$id])[$id] ?? [], $c);
}
function project_out(array $p, array $members, int $notes = 0): array {
    return [
        'id'          => (int) $p['id'],
        'name'        => $p['name'],
        'team'        => $p['team'],
        'status'      => $p['status'],
        'color'       => $p['color'],
        'description' => $p['description'],
        'lead'        => $p['lead_id'] !== null ? (int) $p['lead_id'] : null,
        'members'     => $members,
        'start'       => $p['start_date'] ?: '',
        'due'         => $p['due_date'] ?: '',
        'notes'       => $notes,
        'created'     => iso($p['created_at']),
        'updated'     => iso($p['updated_at']),
        'createdBy'   => $p['created_by'] !== null ? (int) $p['created_by'] : null,
    ];
}
function project_member_map(): array {
    $map = [];
    foreach (q('SELECT project_id, member_id FROM project_members')->fetchAll() as $r) $map[(int) $r['project_id']][] = (int) $r['member_id'];
    return $map;
}
function one_project(int $id): array {
    $p = q('SELECT * FROM projects WHERE id = ?', [$id])->fetch();
    if (!$p) throw new InvalidArgumentException('That project has been deleted.');
    $members = array_map('intval', q('SELECT member_id FROM project_members WHERE project_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    $n = (int) q('SELECT COUNT(*) FROM comments WHERE project_id = ?', [$id])->fetchColumn();
    return project_out($p, $members, $n);
}
function mail_status(bool $full): array {
    $s = mail_settings();
    $out = ['enabled' => mail_enabled()];
    if ($full) {
        $cronSeen = meta_get('cron_seen_at');
        $out += [
            'driver' => $s['driver'], 'host' => $s['host'], 'port' => $s['port'], 'secure' => $s['secure'],
            'user' => $s['user'], 'has_pass' => $s['pass'] !== '', 'from' => $s['from'], 'from_name' => $s['from_name'],
            'digest_hour' => (int) setting('digest_hour', '7'), 'digest_weekends' => setting('digest_weekends', '0') === '1',
            'last_error' => setting('mail_last_error', ''),
            'cron_seen' => $cronSeen ? date('j M Y, g:i a', $cronSeen) : null,
            'cron_ok' => $cronSeen > time() - 3600,
            'cron_path' => __DIR__ . '/cron.php',
            'pending' => (int) q("SELECT COUNT(*) FROM notifications WHERE email_state IN ('pending','sending')")->fetchColumn(),
            'failed_7d' => (int) q("SELECT COUNT(*) FROM notifications WHERE email_state = 'failed' AND created_at > ?", [gmdate('Y-m-d H:i:s', time() - 7 * 86400)])->fetchColumn(),
        ];
    }
    return $out;
}
function state_payload(array $me): array {
    $admin = (bool) (int) $me['is_admin'];
    $members = array_map(function ($m) use ($admin) {
        $o = public_member($m);
        if (!$admin) unset($o['last_login'], $o['has_login']);
        return $o;
    }, q('SELECT * FROM members WHERE active = 1 ORDER BY name, id')->fetchAll());

    $amap = assignee_map();
    $cmap = [];
    foreach (q('SELECT task_id, COUNT(*) AS n FROM comments WHERE task_id IS NOT NULL GROUP BY task_id')->fetchAll() as $r) $cmap[(int) $r['task_id']] = (int) $r['n'];
    $tasks = array_map(fn($t) => task_out($t, $amap[(int) $t['id']] ?? [], $cmap[(int) $t['id']] ?? 0), q('SELECT * FROM tasks')->fetchAll());

    $pmap = project_member_map();
    $nmap = [];
    foreach (q('SELECT project_id, COUNT(*) AS n FROM comments WHERE project_id IS NOT NULL GROUP BY project_id')->fetchAll() as $r) $nmap[(int) $r['project_id']] = (int) $r['n'];
    $projects = array_map(fn($p) => project_out($p, $pmap[(int) $p['id']] ?? [], $nmap[(int) $p['id']] ?? 0), q('SELECT * FROM projects ORDER BY name')->fetchAll());

    return [
        'me'       => public_member(fresh_member((int) $me['id']), true),
        'members'  => $members,
        'projects' => $projects,
        'tasks'    => $tasks,
        'unread'   => unread_count((int) $me['id']),
        'mail'     => mail_status($admin),
        'revision' => revision(),
        'today'    => local_today(),
    ];
}

/* =================================================================
 * tasks
 * ================================================================= */
function next_rank(int $priority, int $exceptId = 0): float {
    $max = q('SELECT MAX(rank_order) FROM tasks WHERE priority = ? AND id <> ?', [$priority, $exceptId])->fetchColumn();
    return $max === null || $max === false ? 1.0 : (float) $max + 1;
}
function clean_subtasks($v): string {
    $out = [];
    foreach (is_array($v) ? $v : [] as $s) {
        $t = trim((string) ($s['t'] ?? ''));
        if ($t === '') continue;
        $out[] = ['t' => mb_substr($t, 0, 300), 'done' => !empty($s['done'])];
        if (count($out) >= 60) break;
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}
function task_columns(array $f, ?array $old): array {
    $c = [];
    if (array_key_exists('title', $f)) {
        $t = trim((string) $f['title']);
        if ($t === '') throw new InvalidArgumentException('Give the task a name first.');
        $c['title'] = mb_substr($t, 0, 300);
    }
    if (array_key_exists('team', $f)) {
        if (!in_array($f['team'], OPS_TEAMS, true)) throw new InvalidArgumentException('Pick a team.');
        $c['team'] = $f['team'];
    }
    if (array_key_exists('project', $f)) {
        $pid = (int) ($f['project'] ?? 0);
        if ($pid && !q('SELECT 1 FROM projects WHERE id = ?', [$pid])->fetchColumn()) throw new InvalidArgumentException('That project has been deleted.');
        $c['project_id'] = $pid ?: null;
    }
    if (array_key_exists('priority', $f)) {
        $p = (int) $f['priority'];
        if ($p < 1 || $p > 4) throw new InvalidArgumentException('Pick a priority.');
        $c['priority'] = $p;
    }
    if (array_key_exists('status', $f)) {
        if (!in_array($f['status'], OPS_STATUSES, true)) throw new InvalidArgumentException('Pick a status.');
        $c['status'] = $f['status'];
        $wasDone = $old && $old['status'] === 'done';
        if ($f['status'] === 'done' && !$wasDone) $c['done_at'] = now_utc();
        if ($f['status'] !== 'done') $c['done_at'] = null;
    }
    if (array_key_exists('rank', $f))     $c['rank_order'] = (float) $f['rank'];
    if (array_key_exists('start', $f))    $c['start_date'] = clean_date($f['start']);
    if (array_key_exists('due', $f))      $c['due_date'] = clean_date($f['due']);
    if (array_key_exists('notes', $f))    $c['notes'] = mb_substr(trim((string) $f['notes']), 0, 20000);
    if (array_key_exists('subtasks', $f)) $c['subtasks'] = clean_subtasks($f['subtasks']);
    return $c;
}

/** Create (no id) or update (id) a task. Only fields present are changed. Handles assignees, activity and notifications. */
function task_save(array $me, array $f): array {
    $meId = (int) $me['id'];
    $id = (int) ($f['id'] ?? 0);
    $old = $id ? q('SELECT * FROM tasks WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$old) throw new InvalidArgumentException('That task has been deleted by someone else.');
    $oldAssignees = $id ? (assignee_map([$id])[$id] ?? []) : [];
    $newAssignees = array_key_exists('assignees', $f) ? active_member_ids($f['assignees'] ?? []) : null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($old) {
            $c = task_columns($f, $old);
            if (isset($c['priority']) && (int) $c['priority'] !== (int) $old['priority'] && !array_key_exists('rank', $f)) {
                $c['rank_order'] = next_rank((int) $c['priority'], $id);
            }
            $c['updated_by'] = $meId;
            $c['updated_at'] = now_utc();
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($c)));
            q("UPDATE tasks SET $set WHERE id = ?", [...array_values($c), $id]);
        } else {
            $f += ['title' => '', 'team' => 'QA', 'priority' => 3, 'status' => 'todo', 'notes' => '', 'subtasks' => [], 'start' => '', 'due' => '', 'project' => null];
            $c = task_columns($f, null);
            $c['rank_order'] = next_rank((int) $c['priority']);
            $c['created_by'] = $c['updated_by'] = $meId;
            $c['created_at'] = $c['updated_at'] = now_utc();
            $c += ['notes' => '', 'subtasks' => '[]'];
            $cols = array_keys($c);
            q('INSERT INTO tasks (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($c));
            $id = (int) $pdo->lastInsertId();
            log_activity($id, null, $meId, 'created the task');
        }

        $added = $removed = [];
        if ($newAssignees !== null) {
            $added = array_values(array_diff($newAssignees, $oldAssignees));
            $removed = array_values(array_diff($oldAssignees, $newAssignees));
            foreach ($removed as $mid) q('DELETE FROM task_assignees WHERE task_id = ? AND member_id = ?', [$id, $mid]);
            foreach ($added as $mid) q('INSERT INTO task_assignees (task_id, member_id) VALUES (?, ?)', [$id, $mid]);
        }
        $new = q('SELECT * FROM tasks WHERE id = ?', [$id])->fetch();

        // Activity
        if ($old) {
            if ($new['title'] !== $old['title']) log_activity($id, null, $meId, 'renamed the task to “' . $new['title'] . '”');
            if ($new['status'] !== $old['status']) log_activity($id, null, $meId, 'moved it to ' . OPS_STATUS_NAMES[$new['status']]);
            if ((int) $new['priority'] !== (int) $old['priority']) log_activity($id, null, $meId, 'set priority to ' . OPS_PRI_NAMES[(int) $new['priority']]);
            if ($new['due_date'] !== $old['due_date']) log_activity($id, null, $meId, $new['due_date'] ? 'set the due date to ' . nice_date($new['due_date']) : 'removed the due date');
            if ($new['project_id'] !== $old['project_id']) {
                $pn = $new['project_id'] ? q('SELECT name FROM projects WHERE id = ?', [$new['project_id']])->fetchColumn() : null;
                log_activity($id, null, $meId, $pn ? 'moved it to the project ' . $pn : 'removed it from its project');
            }
        }
        if ($added) log_activity($id, null, $meId, 'assigned ' . implode(', ', array_map('member_name', $added)));
        if ($removed) log_activity($id, null, $meId, 'unassigned ' . implode(', ', array_map('member_name', $removed)));

        // Notifications
        $actor = $me['name'];
        if ($added) notify($added, 'assigned', $meId, $id, null, "$actor assigned you: {$new['title']}");
        if ($old && $new['status'] === 'done' && $old['status'] !== 'done') {
            $who = array_merge([(int) $new['created_by']], $newAssignees ?? $oldAssignees);
            if ($new['project_id']) $who[] = (int) q('SELECT lead_id FROM projects WHERE id = ?', [$new['project_id']])->fetchColumn();
            notify($who, 'completed', $meId, $id, null, "$actor completed: {$new['title']}");
        }

        bump_revision();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return one_task($id);
}
function task_delete(array $me, int $id): void {
    $t = q('SELECT * FROM tasks WHERE id = ?', [$id])->fetch();
    if (!$t) throw new InvalidArgumentException('That task has already been deleted.');
    q('DELETE FROM tasks WHERE id = ?', [$id]);
    if ($t['project_id']) log_activity(null, (int) $t['project_id'], (int) $me['id'], 'deleted the task “' . $t['title'] . '”');
    bump_revision();
}
function task_detail(array $me, int $id): array {
    $task = one_task($id);
    q('UPDATE notifications SET read_at = ? WHERE member_id = ? AND task_id = ? AND read_at IS NULL', [now_utc(), $me['id'], $id]);
    return ['task' => $task, 'timeline' => timeline('task_id', $id), 'unread' => unread_count((int) $me['id'])];
}
function timeline(string $col, int $id): array {
    $items = [];
    // Latest 60 activity entries, oldest first, then notes oldest first. usort is stable, so same-second items keep this order.
    foreach (array_reverse(q("SELECT * FROM activity WHERE $col = ? ORDER BY id DESC LIMIT 60", [$id])->fetchAll()) as $a) {
        $items[] = ['kind' => 'activity', 'id' => (int) $a['id'], 'by' => $a['member_id'] !== null ? (int) $a['member_id'] : null, 'at' => iso($a['created_at']), 'text' => $a['detail']];
    }
    foreach (q("SELECT * FROM comments WHERE $col = ? ORDER BY id", [$id])->fetchAll() as $c) {
        $items[] = ['kind' => 'note', 'id' => (int) $c['id'], 'by' => $c['member_id'] !== null ? (int) $c['member_id'] : null, 'at' => iso($c['created_at']), 'text' => $c['body']];
    }
    usort($items, fn($a, $b) => strcmp($a['at'], $b['at']));
    return $items;
}

/* =================================================================
 * notes (comments) on tasks and projects
 * ================================================================= */
function comment_add(array $me, array $b): array {
    $text = trim((string) ($b['body'] ?? ''));
    if ($text === '') throw new InvalidArgumentException('Write a note first.');
    if (mb_strlen($text) > 5000) throw new InvalidArgumentException('Keep notes under 5,000 characters.');
    $taskId = (int) ($b['task_id'] ?? 0);
    $projectId = (int) ($b['project_id'] ?? 0);
    $meId = (int) $me['id'];

    if ($taskId) {
        $t = q('SELECT * FROM tasks WHERE id = ?', [$taskId])->fetch();
        if (!$t) throw new InvalidArgumentException('That task has been deleted.');
        q('INSERT INTO comments (task_id, member_id, body, created_at) VALUES (?, ?, ?, ?)', [$taskId, $meId, $text, now_utc()]);
        $who = array_merge(assignee_map([$taskId])[$taskId] ?? [], [(int) $t['created_by']]);
        $sent = notify($who, 'comment', $meId, $taskId, null, "{$me['name']} added a note on: {$t['title']}", $text);
        bump_revision();
        return ['timeline' => timeline('task_id', $taskId), 'notified' => $sent, 'task' => one_task($taskId), 'revision' => revision()];
    }
    if ($projectId) {
        $p = q('SELECT * FROM projects WHERE id = ?', [$projectId])->fetch();
        if (!$p) throw new InvalidArgumentException('That project has been deleted.');
        q('INSERT INTO comments (project_id, member_id, body, created_at) VALUES (?, ?, ?, ?)', [$projectId, $meId, $text, now_utc()]);
        $who = array_map('intval', q('SELECT member_id FROM project_members WHERE project_id = ?', [$projectId])->fetchAll(PDO::FETCH_COLUMN));
        $who[] = (int) $p['lead_id'];
        if (!empty($b['include_assignees'])) {
            $who = array_merge($who, array_map('intval', q('SELECT DISTINCT a.member_id FROM task_assignees a JOIN tasks t ON t.id = a.task_id WHERE t.project_id = ? AND t.status <> ?', [$projectId, 'done'])->fetchAll(PDO::FETCH_COLUMN)));
        }
        $sent = notify($who, 'project_note', $meId, null, $projectId, "{$me['name']} posted an update in {$p['name']}", $text);
        bump_revision();
        return ['timeline' => timeline('project_id', $projectId), 'notified' => $sent, 'project' => one_project($projectId), 'revision' => revision()];
    }
    throw new InvalidArgumentException('Pick a task or project for the note.');
}
function comment_delete(array $me, int $id): void {
    $c = q('SELECT * FROM comments WHERE id = ?', [$id])->fetch();
    if (!$c) throw new InvalidArgumentException('That note has already been deleted.');
    if ((int) $c['member_id'] !== (int) $me['id'] && !(int) $me['is_admin']) throw new InvalidArgumentException('You can only delete your own notes.');
    q('DELETE FROM comments WHERE id = ?', [$id]);
    bump_revision();
}

/* =================================================================
 * projects
 * ================================================================= */
function project_save(array $me, array $f): array {
    $meId = (int) $me['id'];
    $id = (int) ($f['id'] ?? 0);
    $old = $id ? q('SELECT * FROM projects WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$old) throw new InvalidArgumentException('That project has been deleted.');

    $name = trim((string) ($f['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Give the project a name.');
    $team = in_array($f['team'] ?? '', OPS_TEAMS, true) ? $f['team'] : 'General';
    $status = in_array($f['status'] ?? '', OPS_PROJECT_STATUSES, true) ? $f['status'] : 'active';
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($f['color'] ?? '')) ? $f['color'] : OPS_COLORS[0];
    $lead = (int) ($f['lead'] ?? 0);
    if ($lead) active_member_ids([$lead]);
    $members = active_member_ids($f['members'] ?? []);
    if ($lead && !in_array($lead, $members, true)) $members[] = $lead;

    $c = [
        'name' => mb_substr($name, 0, 200), 'team' => $team, 'status' => $status, 'color' => $color,
        'description' => mb_substr(trim((string) ($f['description'] ?? '')), 0, 20000),
        'lead_id' => $lead ?: null, 'start_date' => clean_date($f['start'] ?? ''), 'due_date' => clean_date($f['due'] ?? ''),
        'updated_at' => now_utc(),
    ];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $oldMembers = [];
        if ($old) {
            $oldMembers = array_map('intval', q('SELECT member_id FROM project_members WHERE project_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN));
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($c)));
            q("UPDATE projects SET $set WHERE id = ?", [...array_values($c), $id]);
            if ($status !== $old['status']) log_activity(null, $id, $meId, 'set the project status to ' . OPS_PROJECT_STATUS_NAMES[$status]);
            if ($c['due_date'] !== $old['due_date']) log_activity(null, $id, $meId, $c['due_date'] ? 'set the project due date to ' . nice_date($c['due_date']) : 'removed the project due date');
            if ((int) $old['lead_id'] !== $lead) log_activity(null, $id, $meId, $lead ? 'made ' . member_name($lead) . ' the project lead' : 'removed the project lead');
        } else {
            $c['created_by'] = $meId;
            $c['created_at'] = now_utc();
            $cols = array_keys($c);
            q('INSERT INTO projects (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($c));
            $id = (int) $pdo->lastInsertId();
            log_activity(null, $id, $meId, 'created the project');
        }
        $added = array_values(array_diff($members, $oldMembers));
        $removed = array_values(array_diff($oldMembers, $members));
        foreach ($removed as $mid) q('DELETE FROM project_members WHERE project_id = ? AND member_id = ?', [$id, $mid]);
        foreach ($added as $mid) q('INSERT INTO project_members (project_id, member_id) VALUES (?, ?)', [$id, $mid]);
        if ($added && $old) log_activity(null, $id, $meId, 'added ' . implode(', ', array_map('member_name', $added)));
        if ($removed) log_activity(null, $id, $meId, 'removed ' . implode(', ', array_map('member_name', $removed)));
        if ($added) notify($added, 'project_added', $meId, null, $id, "{$me['name']} added you to the project {$c['name']}", $c['description'] && !$old ? $c['description'] : '');
        bump_revision();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return one_project($id);
}
function project_delete(array $me, int $id): void {
    $p = q('SELECT * FROM projects WHERE id = ?', [$id])->fetch();
    if (!$p) throw new InvalidArgumentException('That project has already been deleted.');
    $allowed = (int) $me['is_admin'] || (int) $p['lead_id'] === (int) $me['id'] || (int) $p['created_by'] === (int) $me['id'];
    if (!$allowed) throw new InvalidArgumentException('Only the project lead or a team admin can delete a project.');
    q('UPDATE tasks SET project_id = NULL WHERE project_id = ?', [$id]);
    q('DELETE FROM projects WHERE id = ?', [$id]);
    bump_revision();
}
function project_detail(array $me, int $id): array {
    $p = one_project($id);
    q('UPDATE notifications SET read_at = ? WHERE member_id = ? AND project_id = ? AND task_id IS NULL AND read_at IS NULL', [now_utc(), $me['id'], $id]);
    return ['project' => $p, 'timeline' => timeline('project_id', $id), 'unread' => unread_count((int) $me['id'])];
}

/* =================================================================
 * notifications + personal settings
 * ================================================================= */
function notifications_list(int $memberId): array {
    $rows = q('SELECT * FROM notifications WHERE member_id = ? ORDER BY id DESC LIMIT 50', [$memberId])->fetchAll();
    return ['items' => array_map(fn($n) => [
        'id' => (int) $n['id'], 'type' => $n['type'], 'subject' => $n['subject'],
        'message' => mb_substr((string) $n['message'], 0, 240),
        'task' => $n['task_id'] !== null ? (int) $n['task_id'] : null,
        'project' => $n['project_id'] !== null ? (int) $n['project_id'] : null,
        'actor' => $n['actor_id'] !== null ? (int) $n['actor_id'] : null,
        'at' => iso($n['created_at']), 'read' => $n['read_at'] !== null, 'email' => $n['email_state'],
    ], $rows), 'unread' => unread_count($memberId)];
}
function notifications_read(int $memberId, array $b): void {
    if (!empty($b['all'])) {
        q('UPDATE notifications SET read_at = ? WHERE member_id = ? AND read_at IS NULL', [now_utc(), $memberId]);
        return;
    }
    $ids = array_values(array_filter(array_map('intval', $b['ids'] ?? [])));
    if (!$ids) return;
    [$ph, $p] = in_list($ids);
    q("UPDATE notifications SET read_at = ? WHERE member_id = ? AND read_at IS NULL AND id IN ($ph)", [now_utc(), $memberId, ...$p]);
}
function prefs_save(array $me, array $p): void {
    q('UPDATE members SET notify_assigned = ?, notify_comments = ?, notify_projects = ?, notify_digest = ? WHERE id = ?', [
        empty($p['assigned']) ? 0 : 1, empty($p['comments']) ? 0 : 1, empty($p['projects']) ? 0 : 1, empty($p['digest']) ? 0 : 1, $me['id'],
    ]);
}
function password_change(array $me, array $b): void {
    $m = fresh_member((int) $me['id']);
    if (!$m['password_hash'] || !password_verify((string) ($b['current'] ?? ''), $m['password_hash'])) throw new InvalidArgumentException('Your current password is not right.');
    $new = (string) ($b['new'] ?? '');
    if ($p = password_problem($new)) throw new InvalidArgumentException($p);
    q('UPDATE members SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
}

/* =================================================================
 * team (admins)
 * ================================================================= */
function team_save(array $me, array $list): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $keep = [(int) $me['id']];
        $seen = [];
        foreach ($list as $i => $m) {
            $name = trim((string) ($m['name'] ?? ''));
            $email = strtolower(trim((string) ($m['email'] ?? '')));
            if ($name === '' && $email === '') continue;
            if ($name === '') throw new InvalidArgumentException('Add a name for ' . ($email ?: 'each person') . '.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Check the email for ' . $name . '.');
            if (isset($seen[$email])) throw new InvalidArgumentException($email . ' is listed twice.');
            $seen[$email] = true;
            $role = in_array($m['role'] ?? '', OPS_ROLES, true) ? ($m['role'] ?? '') : '';
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($m['color'] ?? '')) ? $m['color'] : OPS_COLORS[$i % count(OPS_COLORS)];
            $id = (int) ($m['id'] ?? 0);
            $isMe = $id === (int) $me['id'];
            $adminFlag = $isMe ? 1 : (!empty($m['is_admin']) ? 1 : 0);

            $clash = q('SELECT id, active FROM members WHERE email = ? AND id <> ?', [$email, $id])->fetch();
            if ($clash && (int) $clash['active']) throw new InvalidArgumentException($email . ' is already on the team.');
            if ($clash && !$id) $id = (int) $clash['id'];
            elseif ($clash) throw new InvalidArgumentException($email . ' belongs to a removed member. Remove this row and add them as a new person.');

            if ($id) {
                q('UPDATE members SET name = ?, email = ?, role = ?, color = ?, is_admin = ?, active = 1 WHERE id = ?',
                    [mb_substr($name, 0, 120), $email, $role, $color, $adminFlag, $id]);
            } else {
                q('INSERT INTO members (name, email, role, color, is_admin, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)',
                    [mb_substr($name, 0, 120), $email, $role, $color, $adminFlag, now_utc()]);
                $id = (int) $pdo->lastInsertId();
            }
            $keep[] = $id;
        }
        [$ph, $p] = in_list($keep);
        $gone = q("SELECT id FROM members WHERE active = 1 AND id NOT IN ($ph)", $p)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($gone as $gid) {
            q('DELETE FROM task_assignees WHERE member_id = ?', [$gid]);
            q('DELETE FROM project_members WHERE member_id = ?', [$gid]);
            q('UPDATE projects SET lead_id = NULL WHERE lead_id = ?', [$gid]);
            q("UPDATE notifications SET email_state = 'skipped' WHERE member_id = ? AND email_state = 'pending'", [$gid]);
            q('UPDATE members SET active = 0, is_admin = 0, password_hash = NULL, invite_hash = NULL, invite_expires = NULL WHERE id = ?', [$gid]);
        }
        bump_revision();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return state_payload(fresh_member((int) $me['id']));
}
function invite_link(int $id): string {
    if (!q('SELECT 1 FROM members WHERE id = ? AND active = 1', [$id])->fetchColumn()) throw new InvalidArgumentException('Save the team list first, then create the link.');
    return issue_invite($id);
}
function invite_email(array $me, int $id): array {
    if (!mail_enabled()) throw new InvalidArgumentException('Set up email first under Settings → Email server, or copy the link instead.');
    $m = q('SELECT * FROM members WHERE id = ? AND active = 1', [$id])->fetch();
    if (!$m) throw new InvalidArgumentException('Save the team list first.');
    $url = issue_invite($id);
    $first = explode(' ', trim($m['name']))[0];
    $reset = !empty($m['password_hash']);
    $subject = $reset ? 'Reset your TOC Planning Tool password' : "{$me['name']} invited you to the TOC Planning Tool";
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $intro = 'Hi ' . $h($first) . ', ' . ($reset
        ? 'use the button below to choose a new password for the TOC Planning Tool.'
        : $h($me['name']) . ' has added you to the TOC Planning Tool, where the QA, Training and Events team plans and tracks its work. Choose a password to get started.');
    $hours = (int) cfg('invite_hours', 72);
    $html = email_layout($reset ? 'Reset your password' : 'You’re invited to the TOC Planning Tool', $intro,
        '<p style="font-size:13px;color:#5e5d7a;margin:0">Sign in with <strong>' . $h($m['email']) . '</strong>. The link works once and expires in ' . $hours . ' hours.</p>',
        $url, $reset ? 'Choose a new password' : 'Set my password', 'If you weren’t expecting this, you can ignore it.');
    $text = "Hi $first,\n\n" . strip_tags(html_entity_decode($intro)) . "\n\n$url\n\nThe link works once and expires in $hours hours.\n";
    try { send_mail($m['email'], $m['name'], $subject, $html, $text); }
    catch (Throwable $e) { throw new InvalidArgumentException('Could not send the email: ' . $e->getMessage()); }
    return ['ok' => true, 'url' => $url];
}

/* =================================================================
 * email server settings (admins)
 * ================================================================= */
function mail_settings_save(array $s): void {
    $driver = in_array($s['driver'] ?? '', ['off', 'smtp', 'mail'], true) ? $s['driver'] : 'off';
    $from = trim((string) ($s['from'] ?? ''));
    if ($driver !== 'off' && !filter_var($from, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter the email address the board sends from.');
    $host = trim((string) ($s['host'] ?? ''));
    if ($driver === 'smtp' && ($host === '' || !preg_match('/^[a-z0-9.\-]+$/i', $host))) throw new InvalidArgumentException('Enter the SMTP server, e.g. smtp.hostinger.com');
    $port = (int) ($s['port'] ?? 465);
    if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Enter a valid port, e.g. 465.');
    $hour = max(0, min(23, (int) ($s['digest_hour'] ?? 7)));

    set_setting('mail_driver', $driver);
    set_setting('smtp_host', $host);
    set_setting('smtp_port', (string) $port);
    set_setting('smtp_secure', in_array($s['secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $s['secure'] : 'ssl');
    set_setting('smtp_user', trim((string) ($s['user'] ?? '')));
    if (isset($s['pass']) && $s['pass'] !== '') set_setting('smtp_pass', (string) $s['pass']);
    set_setting('mail_from', $from);
    set_setting('mail_from_name', mb_substr(trim((string) ($s['from_name'] ?? 'TOC Planning Tool')), 0, 80) ?: 'TOC Planning Tool');
    set_setting('digest_hour', (string) $hour);
    set_setting('digest_weekends', !empty($s['digest_weekends']) ? '1' : '0');
    set_setting('mail_last_error', '');
}
function mail_test(array $me): array {
    if (!mail_enabled()) throw new InvalidArgumentException('Turn email on and save the settings first.');
    $first = explode(' ', trim($me['name']))[0];
    $html = email_layout('Email is working', 'Hi ' . htmlspecialchars($first) . ', this is a test from the TOC Planning Tool. Notifications and due-date reminders will arrive like this.',
        '', app_url('index.php'), 'Open the board', 'Sent from Settings → Email server.');
    try {
        send_mail($me['email'], $me['name'], 'Test email from the TOC Planning Tool', $html, "Hi $first,\n\nThis is a test from the TOC Planning Tool. Email is working.\n");
    } catch (Throwable $e) {
        set_setting('mail_last_error', date('j M H:i') . ' — ' . $e->getMessage());
        throw new InvalidArgumentException($e->getMessage());
    }
    set_setting('mail_last_error', '');
    return ['ok' => true, 'sent_to' => $me['email']];
}

/* =================================================================
 * export
 * ================================================================= */
function export_csv(): void {
    $names = [];
    foreach (q('SELECT id, name FROM members')->fetchAll() as $m) $names[(int) $m['id']] = $m['name'];
    $projects = [];
    foreach (q('SELECT id, name FROM projects')->fetchAll() as $p) $projects[(int) $p['id']] = $p['name'];
    $amap = assignee_map();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="TOC-planning-tool-' . local_today() . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Task', 'Project', 'Team', 'Assigned to', 'Priority', 'Status', 'Start', 'Due', 'Checklist', 'Notes', 'Updated', 'Updated by']);
    foreach (q('SELECT * FROM tasks ORDER BY priority, rank_order, id')->fetchAll() as $t) {
        $subs = json_decode((string) $t['subtasks'], true) ?: [];
        $done = count(array_filter($subs, fn($s) => !empty($s['done'])));
        $who = array_map(fn($id) => $names[$id] ?? '', $amap[(int) $t['id']] ?? []);
        $row = [
            $t['title'], $projects[(int) $t['project_id']] ?? '', OPS_TEAM_NAMES[$t['team']] ?? $t['team'], implode(', ', $who),
            OPS_PRI_NAMES[(int) $t['priority']] ?? '', OPS_STATUS_NAMES[$t['status']] ?? $t['status'],
            $t['start_date'] ?? '', $t['due_date'] ?? '', $subs ? "$done/" . count($subs) : '',
            $t['notes'], substr((string) $t['updated_at'], 0, 10), $names[(int) $t['updated_by']] ?? '',
        ];
        $row = array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $row);
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
