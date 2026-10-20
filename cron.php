<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * TOC Planning Tool scheduled job: sends queued emails and the morning
 * due-date reminders. Run it every 15 minutes.
 *
 * Hostinger hPanel > Advanced > Cron Jobs > "PHP" type, command:
 *     domains/YOUR-DOMAIN/public_html/cron.php
 * (or a "Custom" type: /usr/bin/php /home/u123456789/domains/YOUR-DOMAIN/public_html/cron.php)
 *
 * Only runs from the command line, never from a browser.
 * ------------------------------------------------------------------ */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/notify.php';

if (!is_file(OPS_CONFIG_FILE)) { fwrite(STDERR, "Not set up yet.\n"); exit(1); }

$force = in_array('--digest-now', $argv, true);
try {
    if ($force) {
        q('UPDATE members SET last_digest_date = NULL');
        [$sent, $failed] = process_email_queue(100);
        $digests = send_digests(true);
        $r = ['sent' => $sent, 'failed' => $failed, 'digests' => $digests];
    } else {
        $r = run_jobs(60) ?? ['skipped' => 'another run is in progress or ran under a minute ago'];
    }
    meta_set('cron_seen_at', time());
    echo date('Y-m-d H:i:s') . ' ' . json_encode($r) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' error: ' . $e->getMessage() . "\n");
    exit(1);
}
