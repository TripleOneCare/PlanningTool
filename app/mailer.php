<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 * Minimal, dependency-free mailer.
 *   driver "smtp": direct SMTP with SSL (port 465) or STARTTLS (587) and AUTH LOGIN.
 *                  Hostinger: smtp.hostinger.com, port 465, SSL, full mailbox address as username.
 *   driver "mail": PHP mail() (works on most shared hosts, lower deliverability).
 *   driver "off":  no email.
 * ------------------------------------------------------------------ */

final class MailError extends RuntimeException {}

function mail_settings(): array {
    return [
        'driver'    => setting('mail_driver', 'off'),
        'host'      => setting('smtp_host', 'smtp.hostinger.com'),
        'port'      => (int) setting('smtp_port', '465'),
        'secure'    => setting('smtp_secure', 'ssl'),
        'user'      => setting('smtp_user', ''),
        'pass'      => setting('smtp_pass', ''),
        'from'      => setting('mail_from', ''),
        'from_name' => setting('mail_from_name', 'TOC Planning Tool'),
    ];
}
function mail_enabled(): bool {
    $s = mail_settings();
    return $s['driver'] !== 'off' && filter_var($s['from'], FILTER_VALIDATE_EMAIL);
}

function encode_header_text(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}
function format_address(string $email, string $name = ''): string {
    $name = trim(str_replace(["\r", "\n", '"'], '', $name));
    return $name === '' ? "<$email>" : '"' . encode_header_text($name) . '" <' . $email . '>';
}

/** Builds the headers and body of a multipart text + HTML message. */
function build_message(array $s, string $to, string $toName, string $subject, string $html, string $text): array {
    $boundary = 'b' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($s['from'], '@') ?: '@localhost', 1);
    $headers = [
        'Date: ' . date('r'),
        'From: ' . format_address($s['from'], $s['from_name']),
        'To: ' . format_address($to, $toName),
        'Subject: ' . encode_header_text(str_replace(["\r", "\n"], ' ', $subject)),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Auto-Response-Suppress: All',
        'Auto-Submitted: auto-generated',
    ];
    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n"
        . "--$boundary--\r\n";
    return [$headers, $body];
}

function send_mail(string $to, string $toName, string $subject, string $html, string $text): void {
    $s = mail_settings();
    if ($s['driver'] === 'off') throw new MailError('Email is turned off.');
    if (!filter_var($s['from'], FILTER_VALIDATE_EMAIL)) throw new MailError('Set a "from" address in Email settings.');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new MailError("Invalid recipient address: $to");

    [$headers, $body] = build_message($s, $to, $toName, $subject, $html, $text);

    if ($s['driver'] === 'mail') {
        $extra = array_values(array_filter($headers, fn($h) => !preg_match('/^(To|Subject):/i', $h)));
        $subjectHeader = substr((string) current(array_filter($headers, fn($h) => str_starts_with($h, 'Subject: '))), 9);
        $ok = @mail($to, $subjectHeader, $body, implode("\r\n", $extra), '-f' . $s['from']);
        if (!$ok) throw new MailError('The server refused to send the email with mail(). Switch to SMTP in Email settings.');
        return;
    }
    smtp_deliver($s, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
}

function smtp_deliver(array $s, string $to, string $data): void {
    $secure = $s['secure'];
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $s['host'] . ':' . $s['port'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $s['host']]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new MailError("Could not connect to {$s['host']}:{$s['port']} ($errstr). Check the server, port and security setting.");
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $lines = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $lines .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        if ($lines === '') {
            $timedOut = (stream_get_meta_data($fp)['timed_out'] ?? false);
            throw new MailError($timedOut ? 'The mail server stopped responding.' : 'The mail server closed the connection unexpectedly.');
        }
        return [(int) substr($lines, 0, 3), trim($lines)];
    };
    $cmd = function (string $line, array $expect, string $what) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        [$code, $resp] = $read();
        if (!in_array($code, $expect, true)) throw new MailError("Mail server rejected $what: $resp");
        return $resp;
    };

    try {
        [$code, $greet] = $read();
        if ($code !== 220) throw new MailError("Unexpected greeting from mail server: $greet");
        $helo = preg_replace('/[^a-z0-9.\-]/i', '', (string) parse_url((string) cfg('app_url', ''), PHP_URL_HOST)) ?: 'localhost';
        $ehlo = $cmd("EHLO $helo", [250], 'EHLO');

        if ($secure === 'tls') {
            $cmd('STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailError('Could not start an encrypted connection (STARTTLS).');
            }
            $ehlo = $cmd("EHLO $helo", [250], 'EHLO');
        }
        if ($s['user'] !== '') {
            if (stripos($ehlo, 'AUTH') === false) throw new MailError('The mail server does not offer login on this port. Try port 465 with SSL.');
            $cmd('AUTH LOGIN', [334], 'login');
            $cmd(base64_encode($s['user']), [334], 'the username');
            try {
                $cmd(base64_encode($s['pass']), [235], 'the password');
            } catch (MailError $e) {
                throw new MailError('Login to the mail server failed. Check the username is the full mailbox address and the password is right. (' . $e->getMessage() . ')');
            }
        }
        $cmd('MAIL FROM:<' . $s['from'] . '>', [250], 'the from address');
        $cmd('RCPT TO:<' . $to . '>', [250, 251], "the recipient $to");
        $cmd('DATA', [354], 'DATA');
        // Normalise line endings and dot-stuff
        $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data);
        $cmd($data . "\r\n.", [250], 'the message');
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
