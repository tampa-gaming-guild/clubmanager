# Game Club Member Tracking System (PHP & MariaDB)

A secure, high-fidelity club membership tracking web application modeled as a lightweight alternative to CiviCRM. The application runs natively on PHP 8.x and MariaDB, and imports from CiviMember and CiviContribute schemas.

---

## Directory Layout & Security

For public-facing security, the application structure isolates business logic and configuration from the public web-accessible directory:

```
c:\apps\tgg\                  (Keep OUTSIDE the web server document root)
├── config\
│   ├── bootstrap.php         # Security headers, Session init, CSRF token helpers
│   └── database.php          # Database PDO connection wrapper
├── src\                      # Autoloaded App classes (App\...)
│   ├── Auth.php              # Secure login, password hashing, session checking
│   ├── Database.php          # Dual DB connection pool (App DB & CiviCRM DB)
│   ├── Event.php             # Event/Session CRUD & volunteer signups
│   ├── StripeHelper.php      # Stripe Checkout & native signature verification
│   ├── MailHelper.php        # SMTP sending via templated emails
│   ├── BillingHelper.php     # Subscription plan management & billing ledger
│   └── CiviCRMImporter.php   # One-time CiviCRM -> local DB import (contacts, membership levels)
├── db\
│   ├── migrations\           # Phinx migrations -- the local app schema, versioned
│   └── seeds\                 # Phinx seeders -- reference data (roles, email templates, etc.)
├── sql\
│   └── civicrm_mock.sql      # Seedable CiviCRM tables for local test environments
├── phinx.php                 # Phinx config (reads DB_* from .env)
├── .env                      # Live database credentials & Stripe API secrets (KEEP PRIVATE)
├── .env.example              # Environment variables template
└── public_html\              (ONLY this directory is exposed to the internet)
    └── member\               # Root path: https://yourdomain.com/member/
        ├── index.php         # Homepage / member login portal
        ├── join.php          # New member registration & dynamic price checkout
        ├── renew.php         # Member renewal dashboard
        ├── checkin.php       # Entrance tablet check-in terminal (Supports AJAX + Sound)
        ├── profile.php       # Member profile (with public vs private visibility fields)
        ├── calendar.php      # Schedule of events & volunteer slots roster
        ├── stripe-webhook.php# Stripe webhook listener (updates CiviCRM contributions)
        ├── assets\           # Stylesheets and frontend scripts
        └── admin\            # Admin-only interfaces (Scheduler, Importer, Reports)
```

---

## Local Development with Docker Compose

The fastest way to run the app locally is `docker-compose.yml`, which starts three containers:

* **db** – MariaDB, auto-seeded on first start from `sql/civicrm_mock.sql` (mock CiviCRM tables for testing). The app's own `tgg_members` schema and reference data are built by Phinx, not by this auto-seeding.
* **mailpit** – catches outgoing emails instead of sending them. Web UI at `http://localhost:8025`.
* **app** – PHP 8.2 + Apache, document root locked to `public_html/` (same separation as production). On every container start, its entrypoint runs `vendor/bin/phinx migrate` and `vendor/bin/phinx seed:run` against the local database -- no manual schema setup needed, and pulling a new schema change just means restarting the `app` container.

### 1. Start the stack
```bash
cp .env.example .env   # if you don't already have one
docker compose up -d --build
```
The app is then available at `http://localhost:8080/member/`. `DB_HOST`, `CIVI_DB_HOST`, `SMTP_HOST`, and `BASE_URL` are overridden by `docker-compose.yml` so the same `.env` works for both Docker and a native install — fill in your Stripe test keys in `.env` if you need checkout/webhook testing.

### 2. Run the initial CiviCRM import
The local database starts empty — contacts and membership levels (`tgg_subscription_plans`) only exist after the CiviCRM import runs. Since the import tool itself is an admin-only page (a chicken-and-egg problem on a brand new install), trigger it once from the CLI instead:
```bash
docker compose exec app php -r "require '/var/www/html/config/bootstrap.php'; print_r(App\CiviCRMImporter::runSync());"
```
This populates `tgg_contacts` and `tgg_subscription_plans` from the mock CiviCRM data, and creates a `tgg_member_settings` row (with a random, unusable password) for every imported contact.

### 3. Bootstrap your first admin login
Every imported contact defaults to the `member` role with no usable password. Promote one to `superadmin` and set a password directly:
```bash
docker compose exec db mariadb -uroot -e "
  UPDATE tgg_members.tgg_member_settings SET role='superadmin' WHERE contact_id=1;
  UPDATE tgg_members.tgg_member_roles SET role_name='superadmin' WHERE contact_id=1 AND role_name='member';
"
docker compose exec app php -r "require '/var/www/html/config/bootstrap.php'; App\Auth::registerPassword(1, 'YourPasswordHere123!', 'superadmin');"
```
(Both `tgg_member_settings.role` and the `tgg_member_roles` mapping table need updating — the mapping table is only auto-populated on `INSERT`, not `UPDATE`.) Log in at `http://localhost:8080/member/` with that contact's email and the password you set.

---

## Database Migrations (Phinx)

The local `tgg_members` schema and its reference data (roles, permissions, email templates, volunteer credit weights, membership statuses) are managed with [Phinx](https://book.cakephp.org/phinx/0/en/index.html), not a hand-maintained `.sql` file. `phinx.php` reads the same `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS` from `.env` that `App\Database` uses.

**Adding a schema change:**
```bash
docker compose exec app vendor/bin/phinx create AddFooColumnToBar
# edit the generated file in db/migrations/, then:
docker compose exec app vendor/bin/phinx migrate
```
Use `change()` for simple reversible DDL (add/drop column, index, table); use explicit `up()`/`down()` when the migration includes raw SQL (e.g. a trigger) that Phinx can't auto-reverse. Every migration's rollback path is a required code-review item — there's no CI safety net to catch a broken `down()` before it's needed.

**Adding/changing reference data:** add or edit a seeder class in `db/seeds/`, then run `docker compose exec app vendor/bin/phinx seed:run -s YourSeeder` to verify locally. Tables admins can edit live in production (`tgg_email_templates`, `tgg_volunteer_credits`) use an insert-only-if-missing pattern so reseeding never clobbers a live customization; fixed system tables (`tgg_roles`, `tgg_permissions`, `tgg_role_permissions`, `tgg_membership_statuses`) use a full upsert.

Commit new migration/seed files as part of the normal PR.

### Deploying to an existing (already-bootstrapped) database

If you're applying this to an environment that already has the schema (e.g. it was bootstrapped from the old `sql/schema.sql` before Phinx was introduced), the baseline migration must be marked as applied without being executed:
```bash
vendor/bin/phinx status -e production              # confirm only BaselineSchema shows "down"
vendor/bin/phinx migrate -e production --target <baseline_version> --fake
vendor/bin/phinx status -e production              # confirm it now shows "up"
```
Do this once, before any other migration exists, then use plain `phinx migrate -e production` from then on.

### Production deploy runbook

There's no CI/CD yet, so this is manual:
1. **Backup first, always** (the trigger needs `--routines --triggers`):
   ```bash
   mysqldump --single-transaction --routines --triggers -h $DB_HOST -P $DB_PORT -u $DB_USER -p $DB_NAME > backups/tgg_members_$(date +%Y%m%d_%H%M%S).sql
   ```
2. Deploy the new code.
3. `vendor/bin/phinx status -e production` — confirm only the expected pending migration(s); anything surprising is a hard stop.
4. `vendor/bin/phinx migrate -e production`
5. `vendor/bin/phinx status -e production` — confirm everything now shows `up`.
6. `vendor/bin/phinx seed:run -e production` (safe to run unconditionally; see insert-if-missing note above).
7. Smoke-test the app manually.

MySQL/MariaDB DDL isn't fully transactional (some statements implicitly commit), so if a migration fails partway through in production, restoring the mysqldump from step 1 — not `phinx rollback` — is the real recovery path. Fix the migration and retry from step 3. `phinx rollback -e production -t <version>` is only for cleanly undoing an already-`up` migration whose `down()` is known-correct.

---

## Production Installation & Deployment

### 1. Database Configuration
1. Create an empty database for the local application (default name: `tgg_members`), then apply the schema and seed reference data via Phinx:
   ```bash
   vendor/bin/phinx migrate -e production
   vendor/bin/phinx seed:run -e production
   ```
   (If the database already has this schema from before Phinx was introduced, see "Deploying to an existing database" above instead.)
2. If deploying to a local test environment, create the mock CiviCRM database and seed data:
   ```bash
   mysql -u your_user -p < sql/civicrm_mock.sql
   ```
   *For live deployment, ensure this application has SELECT, INSERT, and UPDATE permissions to the CiviCRM tables inside your WordPress database.*
3. Run the CiviCRM import once (see "Run the initial CiviCRM import" above) to populate contacts and membership levels, then bootstrap your first admin login (see "Bootstrap your first admin login" above).

### 2. Configure Environment Variables
1. Copy `.env.example` to `.env` in the root project folder:
   ```bash
   cp .env.example .env
   ```
2. Fill in the MySQL/MariaDB credentials for both the local app database and the CiviCRM database.
3. Add your Stripe test or live credentials:
   - `STRIPE_PUBLISHABLE_KEY`
   - `STRIPE_SECRET_KEY`
   - `STRIPE_WEBHOOK_SECRET` (obtained from Stripe webhook panel)

### 3. Web Server Virtual Host Setup
Configure Apache or Nginx to point its document root to the `public_html/member/` directory.

**Apache VirtualHost example:**
```apache
<VirtualHost *:4043>
    ServerName club.example.com
    DocumentRoot "/var/www/tgg/public_html"
    
    # Optional URL mapping: ensures /member/ works
    Alias /member "/var/www/tgg/public_html/member"
    
    <Directory "/var/www/tgg/public_html">
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx Configuration example:**
```nginx
server {
    listen 80;
    server_name club.example.com;
    root /var/www/tgg/public_html/member;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }
}
```

### 4. Setup Stripe Webhook
1. Log in to your Stripe Dashboard and navigate to **Developers > Webhooks**.
2. Click **Add endpoint** and enter your public URL pointing to:
   `https://yourdomain.com/member/stripe-webhook.php`
3. Select the event: `checkout.session.completed`.
4. Copy the **Signing secret** (starts with `whsec_`) and paste it into your `.env` file as `STRIPE_WEBHOOK_SECRET`.

---

## Security Features Included

* **Web Root Separation**: Prevents anyone from downloading source code (`.php` files in `src/` and configuration values in `config/` or `.env` files).
* **SQL Injection Block**: Custom parameterized PDO executions isolate queries from user strings.
* **XSS Mitigation**: Dynamic outputs are escaped with `e()` (HTML entities filter).
* **CSRF Shielding**: Check-in, joining, renewing, scheduling, and profile settings require cryptographic form tokens validated in the session.
* **Secure Cookie Directives**: Cookies configured with `HttpOnly`, `Secure` (when SSL is enabled), and `SameSite=Strict` browser constraints.
