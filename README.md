# TOC Planning Tool

Project management for the Triple One Care Quality Assurance, Training and Events team. Native PHP 8 and MySQL, no framework, no build step. Built for Hostinger, works on any LAMP or LEMP host.

## What it does

**Projects**
- Group work into projects with a lead, members, team, status (Planning, Active, On hold, Complete, Archived), start and due dates, colour and description.
- Projects view shows every project as a card with progress, open and overdue counts, and who's involved.
- Each project has an **Updates** thread. Posting an update emails the project members, and optionally everyone with open tasks in it.

**Tasks**
- Assign a task to **any number of people**.
- Priority (P1 to P4), status (Backlog, To do, In progress, Review, Blocked, Done), start and due dates, description and checklist.
- **Notes** thread on every task. Posting a note emails the people assigned and the task's creator.
- Activity history on every task: who created, moved, reassigned, re-prioritised or re-dated it.
- Views: **Priority** (ranked queue with ↑ ↓ reordering), **Board** (drag between status columns), **Table** (sortable).
- Filters: team, project, person (including **My tasks**), search, show done. Click a name in Workload to filter to that person.

**Notifications**
- In-app bell with unread count for every notification.
- Email when someone:
  - assigns you a task
  - adds a note to a task you're on or created
  - completes a task you're on, created or lead the project for
  - posts an update in your project, or adds you to one
- **Daily due-date reminder**, one email each morning (7am by default) listing your tasks that are overdue, due today or due tomorrow. Skipped when nothing is due. Weekdays only unless turned on for weekends.
- Everyone chooses which emails they get under **Settings**.
- Emails are branded, show the task details, and have a button that opens the task directly.

**Team and access**
- Unlimited people. Only people on the team list can sign in.
- Team admins add people and send invite emails (or copy invite links). Invites let people set their own password; the same button sends a password reset.
- Removing someone signs them out and unassigns them. Their history stays.

## Install on Hostinger

1. **Subdomain.** hPanel → **Domains → Subdomains**: create one, e.g. `planning.tripleonecare.online`. Note its folder (e.g. `domains/tripleonecare.online/public_html/planning`).
2. **SSL.** hPanel → **Security → SSL**: install the free certificate for the subdomain. Wait until it's active.
3. **PHP.** hPanel → **Advanced → PHP Configuration**: PHP 8.1 or newer.
4. **Database.** hPanel → **Databases → MySQL Databases**: create a database and user. Keep the names and password (Hostinger adds a prefix like `u123456789_`).
5. **Upload.** **File Manager** → open the subdomain's folder → upload the zip → **Extract**. `index.php` must sit directly in that folder.
6. **Setup.** Visit `https://planning.tripleonecare.online/install.php`: enter the database details, then create your account. Then **delete `install.php`** in File Manager.

### Turn on email

7. **Mailbox.** hPanel → **Emails**: create a mailbox for the board, e.g. `board@tripleonecare.online`.
8. **Connect it.** On the board: **your name (top right) → Settings → Email server**:
   - Sending method: **SMTP**
   - SMTP server: `smtp.hostinger.com`, Port: `465`, Security: `SSL`
   - Username: the full mailbox address, Password: the mailbox password
   - From address: the same mailbox
   - Save, then **Send test email to me**.

### Turn on reminders (scheduled job)

9. hPanel → **Advanced → Cron Jobs** → add a job that runs **every 15 minutes** (`*/15 * * * *`):
   - Type **PHP**, command: the path to `cron.php` shown in **Settings → Email server** on the board, for example
     `/home/u123456789/domains/tripleonecare.online/public_html/planning/cron.php`
   - Or type **Custom**: `/usr/bin/php /home/u123456789/domains/tripleonecare.online/public_html/planning/cron.php`
10. Within 15 minutes, Settings → Email server shows **"Scheduled job is running"**.

Notification emails go out straight away even without the cron job. The cron job is needed for the morning reminders, and it retries any email that failed to send.

### Add your team

11. Open **Team** → **+ Add person** for each teammate → **Save team** → **Email invite** next to each person.

## Install on any other server

1. Create a MySQL 5.7+ / MariaDB 10.3+ database (utf8mb4).
2. Upload the files to the web root. Apache: `.htaccess` is included (needs `AllowOverride All`). nginx: see `nginx.conf.sample`.
3. Visit `/install.php`, enter the database details and create your account, then delete `install.php`.
4. Set up email under Settings, and a cron job: `*/15 * * * * php /path/to/cron.php`.

## Files

| Path | Purpose |
| --- | --- |
| `index.php` | Sign-in screen and the board |
| `api.php` | JSON API used by the board, plus CSV export |
| `cron.php` | Scheduled job: sends queued emails and morning reminders (command line only) |
| `install.php` | One-time setup (delete after use) |
| `invite.php` | Set a password from an invite or reset link |
| `app/bootstrap.php` | Config, database, sessions, security helpers |
| `app/schema.php` | Table definitions |
| `app/mailer.php` | SMTP / mail() sender (no external libraries) |
| `app/notify.php` | Notifications, email templates, reminders |
| `app/auth_page.php` | Branded layout for sign-in screens |
| `assets/` | Styles, script and icon |

## Handy commands

- Send everyone's morning reminder now (for testing): `php cron.php --digest-now`

## Security notes

- Passwords are hashed with PHP's `password_hash`. Invite and reset links are random, single-use, expire after 72 hours, and only their hash is stored.
- Every change needs the session's CSRF token. Sessions use HTTP-only, SameSite cookies, HTTPS-only when the site is on HTTPS.
- Sign-in locks for 15 minutes after 8 failed attempts from the same email or IP.
- All queries use prepared statements. CSV export guards against spreadsheet formula injection.
- `config.php`, `cron.php` and the `app/` folder are blocked from the web.
- The SMTP password is stored in the database, and only team admins can change it. It is never sent back to the browser.

## Backups

Everything lives in the one MySQL database. On Hostinger, hPanel backups include it; you can also export it from **Databases → phpMyAdmin → Export**.
