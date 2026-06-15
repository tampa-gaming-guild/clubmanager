# Simple CiviCRM Member Tracking System (PHP & MariaDB)

A secure, high-fidelity club membership tracking web application modeled as a lightweight alternative to CiviCRM. The application runs natively on PHP 8.x and MariaDB, and integrates with CiviMember and CiviContribute schemas.

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
│   └── CiviCRMImporter.php   # MySQL sync utility from CiviCRM to local credentials
├── sql\
│   ├── schema.sql            # Local app database tables (check-ins, events, settings)
│   └── civicrm_mock.sql      # Seedable CiviCRM tables for local test environments
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

## Installation & Deployment

### 1. Database Configuration
1. Create a database for the local application (default name: `tgg_members`) and import the schema:
   ```bash
   mysql -u your_user -p tgg_members < sql/schema.sql
   ```
2. If deploying to a local test environment, create the mock CiviCRM database and seed data:
   ```bash
   mysql -u your_user -p < sql/civicrm_mock.sql
   ```
   *For live deployment, ensure this application has SELECT, INSERT, and UPDATE permissions to the CiviCRM tables inside your WordPress database.*

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
