<?php
declare(strict_types=1);

/* Creates the TOC Planning Tool tables. Safe to run more than once. */
function create_schema(): void {
    $mysql = db_driver() === 'mysql';
    $pk    = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk    = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $dt    = $mysql ? 'DATETIME' : 'TEXT';
    $date  = $mysql ? 'DATE' : 'TEXT';
    $text  = $mysql ? 'MEDIUMTEXT' : 'TEXT';
    $str   = fn(int $n) => $mysql ? "VARCHAR($n)" : 'TEXT';
    $tail  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $sql = [];
    $sql[] = "CREATE TABLE IF NOT EXISTS meta (
        name {$str(40)} NOT NULL PRIMARY KEY,
        value BIGINT NOT NULL DEFAULT 0
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS settings (
        name {$str(60)} NOT NULL PRIMARY KEY,
        value $text NOT NULL
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS members (
        id $pk,
        name {$str(120)} NOT NULL,
        email {$str(190)} NOT NULL UNIQUE,
        role {$str(20)} NOT NULL DEFAULT '',
        color {$str(9)} NOT NULL DEFAULT '#2a2769',
        is_admin TINYINT NOT NULL DEFAULT 0,
        active TINYINT NOT NULL DEFAULT 1,
        password_hash {$str(255)} NULL,
        invite_hash {$str(64)} NULL,
        invite_expires $dt NULL,
        last_login $dt NULL,
        notify_assigned TINYINT NOT NULL DEFAULT 1,
        notify_comments TINYINT NOT NULL DEFAULT 1,
        notify_projects TINYINT NOT NULL DEFAULT 1,
        notify_digest TINYINT NOT NULL DEFAULT 1,
        last_digest_date $date NULL,
        created_at $dt NOT NULL
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS projects (
        id $pk,
        name {$str(200)} NOT NULL,
        team {$str(20)} NOT NULL DEFAULT 'General',
        status {$str(20)} NOT NULL DEFAULT 'active',
        color {$str(9)} NOT NULL DEFAULT '#2a2769',
        description $text NOT NULL,
        lead_id $fk NULL,
        start_date $date NULL,
        due_date $date NULL,
        created_by $fk NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        FOREIGN KEY (lead_id) REFERENCES members(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES members(id) ON DELETE SET NULL
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS project_members (
        project_id $fk NOT NULL,
        member_id $fk NOT NULL,
        PRIMARY KEY (project_id, member_id),
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS tasks (
        id $pk,
        project_id $fk NULL,
        title {$str(300)} NOT NULL,
        team {$str(20)} NOT NULL DEFAULT 'QA',
        priority TINYINT NOT NULL DEFAULT 3,
        status {$str(20)} NOT NULL DEFAULT 'todo',
        rank_order DOUBLE NOT NULL DEFAULT 0,
        start_date $date NULL,
        due_date $date NULL,
        notes $text NOT NULL,
        subtasks $text NOT NULL,
        done_at $dt NULL,
        created_by $fk NULL,
        updated_by $fk NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES members(id) ON DELETE SET NULL,
        FOREIGN KEY (updated_by) REFERENCES members(id) ON DELETE SET NULL
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS task_assignees (
        task_id $fk NOT NULL,
        member_id $fk NOT NULL,
        PRIMARY KEY (task_id, member_id),
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS comments (
        id $pk,
        task_id $fk NULL,
        project_id $fk NULL,
        member_id $fk NULL,
        body $text NOT NULL,
        created_at $dt NOT NULL,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS activity (
        id $pk,
        task_id $fk NULL,
        project_id $fk NULL,
        member_id $fk NULL,
        detail {$str(400)} NOT NULL,
        created_at $dt NOT NULL,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS notifications (
        id $pk,
        member_id $fk NOT NULL,
        type {$str(20)} NOT NULL,
        task_id $fk NULL,
        project_id $fk NULL,
        actor_id $fk NULL,
        subject {$str(300)} NOT NULL,
        message $text NOT NULL,
        created_at $dt NOT NULL,
        read_at $dt NULL,
        email_state {$str(10)} NOT NULL DEFAULT 'skipped',
        email_attempts TINYINT NOT NULL DEFAULT 0,
        emailed_at $dt NULL,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (actor_id) REFERENCES members(id) ON DELETE SET NULL
    )$tail";
    $sql[] = "CREATE TABLE IF NOT EXISTS login_attempts (
        id $pk,
        email {$str(190)} NOT NULL,
        ip {$str(64)} NOT NULL,
        attempted_at $dt NOT NULL
    )$tail";

    foreach ($sql as $s) db()->exec($s);

    foreach ([
        'CREATE INDEX idx_tasks_project ON tasks (project_id)',
        'CREATE INDEX idx_tasks_status ON tasks (status)',
        'CREATE INDEX idx_assignee_member ON task_assignees (member_id)',
        'CREATE INDEX idx_pm_member ON project_members (member_id)',
        'CREATE INDEX idx_comments_task ON comments (task_id)',
        'CREATE INDEX idx_comments_project ON comments (project_id)',
        'CREATE INDEX idx_activity_task ON activity (task_id)',
        'CREATE INDEX idx_notif_member ON notifications (member_id, read_at)',
        'CREATE INDEX idx_notif_email ON notifications (email_state)',
        'CREATE INDEX idx_attempts_time ON login_attempts (attempted_at)',
    ] as $s) {
        try { db()->exec($s); } catch (Throwable $e) { /* already exists */ }
    }

    foreach (['revision' => 1, 'jobs_ran_at' => 0] as $k => $v) {
        if (q('SELECT 1 FROM meta WHERE name = ?', [$k])->fetchColumn() === false) {
            q('INSERT INTO meta (name, value) VALUES (?, ?)', [$k, $v]);
        }
    }
}
