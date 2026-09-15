# Server Conventions — Dashboard Jefe de Zona

Infrastructure and deployment conventions for the Tren Maya "Dashboard Jefe
de Zona" production VPS. These are enforced alongside `security-auditor` —
any change touching server/Nginx/MySQL/deploy config should be reviewed by
that agent before merge.

## Non-Negotiables (from `CLAUDE.md` — never relax these)

- [ ] The app is **never reachable except through Tailscale VPN**. No
      Nginx `server_block`, firewall rule, or CloudPanel setting opens a
      public port beyond what VPN-only access requires.
- [ ] MySQL binds to `127.0.0.1` only (`bind-address = 127.0.0.1` in
      `mysqld.cnf`) — never `0.0.0.0`, never a public interface.
- [ ] SSH access is **key-only** — password auth stays disabled
      (`PasswordAuthentication no` in `sshd_config`). Never re-enable it,
      even temporarily for debugging.
- [ ] `.env` never enters git; only `.env.example` with placeholder values
      is committed.
- [ ] `APP_DEBUG=false` and `APP_ENV=production` on the production server,
      always.

## Environment Baseline

| Layer | Choice |
|---|---|
| OS | Ubuntu 24.04 LTS |
| Web server | Nginx (managed via CloudPanel) |
| Panel | CloudPanel (free tier) |
| PHP | 8.3, PHP-FPM |
| Database | MySQL 8, local to the VPS |
| Host | Hostinger VPS, KVM 2 (2 vCPU / 8 GB RAM / NVMe), US datacenter |
| Remote access | Tailscale VPN only — no direct public exposure |
| TLS | Let's Encrypt, auto-renewal |

Don't introduce a different web server, panel, or DB engine without an
explicit decision recorded — this stack is fixed for v1.

## Access

- SSH in only via key; no shared root logins — each engineer gets their
  own key added through CloudPanel/`authorized_keys`, never a shared
  private key passed around.
- Application/deploy actions run as the app's dedicated CloudPanel site
  user, not `root`, except for OS-level package management.
- Tailscale is the only path to the app, the DB port, and any admin panel
  (CloudPanel UI itself should also sit behind the VPN, not the public
  internet).

## `.env` Management

- Production `.env` lives only on the server, set once via CloudPanel's
  file manager or SSH — never transmitted through chat, commit, or a
  world-readable path.
- Required keys must be validated present at boot (fail fast on a missing
  `APP_KEY`, DB credentials, mail credentials) rather than failing
  silently deep in a request.
- Rotate any secret that may have touched a non-production channel
  (pasted in chat, logged, committed by accident) — don't assume "it was
  only briefly exposed" is safe enough.
- `.env.example` stays in sync with every new config key the app
  introduces, with an obviously-fake placeholder value, never a real one.

## Deployment Flow

Deploys are VPN-reachable-only actions — never run these against a host
you haven't confirmed is the intended environment.

```bash
# Standard deploy sequence (run as the site user, over SSH via VPN)
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart      # if queue workers are running
```

- `--no-dev` on `composer install` in production — dev dependencies
  (testing, debugging tools) never ship to prod.
- Run `migrate --force` deliberately, never as a blind default in a script
  that could target the wrong environment — confirm target host first.
- After `config:cache`, a raw `env()` call outside `config/*.php` will
  silently return `null` in request code — config values must be read via
  `config('key')`, sourced from a `config/*.php` file, never `env()`
  directly in application code (Laravel 11 standard practice, and
  mandatory here since config caching is part of the deploy flow).
- Re-run `config:cache`/`route:cache`/`view:cache` after every deploy —
  stale cached config/routes are a common source of "works locally, wrong
  in prod."

## File Permissions

- `storage/` and `bootstrap/cache/` writable by the PHP-FPM process user
  only — never `chmod 777`. Use the CloudPanel site user's group
  ownership instead of loosening world permissions.
- Uploaded files (once the CRUD module handles them) are stored via
  Laravel's `Storage` facade with a configured disk — never written
  directly to a path assembled from user input.

## Scheduler & Queue

- Laravel's scheduler runs via a single cron entry pointing at
  `artisan schedule:run` every minute (CloudPanel's cron UI or
  `crontab -e` for the site user) — don't add duplicate per-task cron
  entries outside the scheduler.
- If/when queue workers are introduced, run them under a process
  supervisor (`supervisor` or systemd unit), not a bare backgrounded
  `artisan queue:work` that dies on SSH disconnect or server reboot.

## Backups

- MySQL: automated daily dump to a location outside the web root
  (CloudPanel's backup feature or a cron'd `mysqldump`), retained on a
  defined schedule — confirm restore actually works, don't assume backups
  are good untested.
- Application code is recoverable from git; only the database and `.env`
  need a real backup story.

## Logging & Monitoring

- Laravel logs (`storage/logs/laravel.log`) never contain passwords,
  2FA secrets, session tokens, or full request bodies from auth
  endpoints — sanitize before logging.
- Failed-login and suspicious-activity events are logged (paired with
  `spatie/laravel-activitylog` for in-app mutations) — this is the
  system's audit trail for a federal-infrastructure oversight tool, not
  optional observability.
- Nginx access/error logs stay enabled at their CloudPanel defaults;
  don't disable them to reduce noise.

## Nginx / CloudPanel Config

- Any change to the site's Nginx vhost, PHP-FPM pool, or CloudPanel
  firewall rule is a `security-auditor`-reviewed change — treat it with
  the same scrutiny as an auth code change, since a misconfiguration here
  is exactly what could break the VPN-only guarantee.
- Standard security headers (`X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, CSP once the frontend asset story is finalized) are
  set at the Nginx or Laravel middleware level and not something a
  feature change should silently strip.
- TLS stays on Let's Encrypt auto-renewal — never disable the renewal
  cron or hardcode a certificate path that bypasses it.

## What NOT to Do

| Anti-pattern | Why |
|---|---|
| Opening a port/route to the public internet "temporarily" for testing | Breaks the VPN-only non-negotiable; use Tailscale instead |
| `chmod -R 777` to fix a permissions error | Papers over the real ownership issue and widens attack surface |
| Committing a real `.env` or hardcoding prod credentials in a script | Secret leak into git history |
| Running deploy/migrate commands without confirming target host | Risk of migrating/deploying against the wrong environment |
| Disabling `APP_DEBUG` only in some environments | Stack traces/secrets leak if any prod-reachable path has it `true` |
| Backgrounding `queue:work` by hand over SSH | Dies on disconnect/reboot; use a supervisor |
