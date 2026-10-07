# Store

A PHP 8.1+ / MariaDB online store: category filtering, product pages, a database-backed cart,
checkout with configurable shipping and payment methods (Stripe, PayPal, Revolut Pay, OxaPay crypto,
bank transfer, cash on delivery), an admin panel, and switchable, uploadable Twig themes.

It is structured as a small MVC application on the PHP-FIG standards:

| Standard | Used for | Package |
|---|---|---|
| PSR-4 | Autoloading `App\` → `src/` | Composer |
| PSR-12 | Coding style (`phpcs.xml.dist`, `.php-cs-fixer.dist.php`) | — |
| PSR-7 / PSR-17 | HTTP requests, responses, uploaded files | nyholm/psr7, nyholm/psr7-server |
| PSR-15 | Middleware pipeline and request handlers | own `MiddlewarePipeline` |
| PSR-11 | Dependency-injection container (autowiring) | php-di/php-di |
| PSR-3 | Logging to `var/log/app-YYYY-MM-DD.log` | monolog/monolog |
| — | Routing | nikic/fast-route |
| — | Views and themes | twig/twig |

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Setup](#2-setup)
3. [Environment variables](#3-environment-variables)
4. [Running locally](#4-running-locally)
5. [Architecture](#5-architecture)
6. [Pre-Deployment Checklist](#6-pre-deployment-checklist)
7. [Roadmap](#7-roadmap)

---

## 1. Requirements

| Dependency | Version |
|---|---|
| PHP | 8.1+ with `pdo_mysql`, `curl`, `json`, `zip`, `gd` |
| MariaDB | 10.x |
| Composer + git | Dependencies are installed from GitHub (see `repositories` in `composer.json`) |
| Web server | Apache (`setup-apache.sh`), Nginx, or `php -S` for development |

---

## 2. Setup

On a Debian/Ubuntu server, `sudo ./setup-apache.sh` does everything below (see
[Deploying to finderskeep.online](#deploying-to-finderskeeponline)). By hand:

```bash
composer install
cp .env.example .env                       # then fill it in
mysql -u root -p < database/schema.sql     # tables + sample data
mysql -u root -p online_store < database/migrations/012_settings.sql   # etc., on older databases
```

Create a dedicated database user instead of using root:

```sql
CREATE USER 'store_user'@'localhost' IDENTIFIED BY 'a_strong_password';
GRANT ALL PRIVILEGES ON online_store.* TO 'store_user'@'localhost';
FLUSH PRIVILEGES;
```

The web server must be able to write to `var/`, `public/themes/` and
`public/assets/images/{products,generated,branding,thumbs,categories}/`.

---

## 3. Environment variables

`.env` in the project root is read by `App\Support\Config`. Variables already set in the process
environment take priority. Comments must be on their own line.

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MariaDB connection |
| `APP_URL` | Public base URL (e.g. `https://finderskeep.online`) — used for payment return and webhook URLs, canonical links, social cards and `sitemap.xml` |
| `APP_DEBUG` | `true` shows exception details on error pages and logs at debug level. Keep `false` in production |
| `STORE_NAME`, `STORE_EMAIL` | Defaults for the store name and contact email (Admin → Settings overrides them) |
| `STORE_LANGUAGE` | `<html lang>` of the storefront (default `en`; a `store_language` setting overrides it) |
| `STORE_CURRENCY` | ISO currency code for prices and payment providers (default `EUR`). Formatting and minor units follow the currency (e.g. `JPY` has no decimals); uses `ext-intl` when loaded |
| `APP_SECRET` | Signs customer order links (`?key=…` on the confirmation / track-order pages). If empty, one is generated into `var/app-secret` |
| `MAIL_TRANSPORT` | `smtp`, `mail` (PHP `mail()`) or `log` (default: writes emails to `var/log/mail-*.log`) |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP server (`tls` = STARTTLS, usually 587; `ssl` = 465; AUTH LOGIN when a username is set) |
| `MAIL_FROM`, `MAIL_FROM_NAME` | Sender (defaults to `STORE_EMAIL` / `STORE_NAME`) |
| `ADMIN_NOTIFY_EMAIL` | Receives new-order alerts (defaults to the store email) |
| `THEME` | Default theme (Admin → Settings overrides it) |
| `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH` | First admin login only: used while `admin_users` is empty, then saved as the first Owner (see [Admin users and roles](#admin-users-and-roles)) |
| `TRUSTED_PROXIES` | Comma-separated proxy IPs/CIDRs allowed to set `X-Forwarded-For` (login lockout uses the real client IP). Empty = trust no proxy |
| `PAYMENT_*` and provider keys | See [Payment methods](#payment-methods) |
| `GEMINI_*` | Optional: AI "magic edit" in the admin and `bin/console images:check` |

Create the admin password hash with:

```bash
bin/console admin:hash-password        # prompts for the password
```

---

## 4. Running locally

```bash
composer serve                 # or: php -S localhost:8000 -t public router.php
```

Open http://localhost:8000 (admin: http://localhost:8000/admin/login). `router.php` makes PHP's
built-in server behave like Apache: real files in `public/` are served directly, everything else
goes to `public/index.php`.

### Command line

```
bin/console list                                   all commands
bin/console admin:hash-password                    hash for ADMIN_PASSWORD_HASH
bin/console admin:user <username> [--role=owner] [--email=] [--password=]   create / reset an admin (recovery)
bin/console admin:unlock <username> [--ip=address] clear the failed-login lockout
bin/console images:add <product_id> <url> [--gallery]
bin/console images:check <product_id> [--apply]    remove branding with Gemini or find a replacement
bin/console images:regenerate [--force]            placeholder images for products without a photo
bin/console db:reimport --force                    DEV ONLY: drop all tables, rebuild from schema + migrations
bin/console orders:expire [--hours=48]             cancel unpaid online orders and restock them (run from cron)
bin/console db:backup [--output=path] [--gzip] [--with-uploads] [--keep=N]   back up to var/backups/
bin/console db:restore <file> --force [--with-uploads[=zip]]                  restore a backup
```

### Backups

`bin/console db:backup` dumps every table (structure + data) through PDO — no `mysqldump` needed —
to `var/backups/backup-<db>-<YYYYmmdd-HHiiss>.sql` (`.sql.gz` with `--gzip`). Large tables are
streamed, `TIMESTAMP`s are written in UTC, and the file only appears once it is complete.

- `--output=dir/` puts the file in another folder; `--output=file.sql` uses that exact name.
- `--with-uploads` also zips the uploaded images (`public/assets/images/{products,categories,branding,generated}`;
  `thumbs/` is a cache and is skipped) to `…-uploads.zip` next to the dump.
- `--keep=N` then deletes all but the newest N backups (and their uploads zips) in that folder —
  only files named like `backup-…-YYYYmmdd-HHiiss.sql[.gz]` are ever touched.

Daily at 03:30, keeping two weeks:

```
30 3 * * * cd /var/www/store && bin/console db:backup --gzip --with-uploads --keep=14 >> var/log/backup.log 2>&1
```

`bin/console db:restore var/backups/backup-….sql.gz --force` replaces every table in the backup
(in the database from `.env`, whatever the backup was called); without `--force` it only says what it
would do. `--with-uploads` also unpacks the matching `-uploads.zip` (or `--with-uploads=path.zip`)
into `public/assets/images/` — only image folders, never scripts. The restore is not one
transaction (MariaDB commits each `CREATE TABLE`), so take a fresh backup first. Plain
`mysqldump` files restore too, as long as they have no `DELIMITER` blocks (routines/triggers).
Copy backups off the server as well — `var/backups/` dies with the disk.

### Tests

```bash
composer test                        # or: php tests/run.php
php tests/run.php unit               # tests/Unit only (no database needed)
php tests/run.php --filter=Csv -v    # matching classes/methods, one line per test
```

The runner is dependency-free (`tests/run.php` + `tests/TestCase.php`, PHPUnit-style `assert*`
methods), because PHPUnit and its ~25 dependencies would each need a VCS repository in
`composer.json` with Packagist disabled. It discovers `tests/Unit/**/*Test.php` and
`tests/Integration/**/*Test.php`; public `test*` methods run on a fresh instance between `setUp()`
and `tearDown()`.

- **Unit** — `Money`, `Slug`, `HtmlSanitizer`, `ShippingService`, `TaxCalculator` / coupon
  discounts, `Csv` (escaping, BOM, parsing), the CSV import parser, `SqlSplitter`, `Router::url()`
  with optional segments, the theme-upload file filter, `ClientIp`, customer password / return-path rules.
- **Integration** — against a real MariaDB/MySQL database: checkout places the order, reserves stock
  and refuses to oversell; `PaymentRecorder` ignores duplicate events and refuses wrong amounts or
  currencies; cancelling restocks exactly once; CSV import/export; backup + restore round trip;
  customer accounts (registration, login lockout, reset tokens single-use / expiring, guest orders
  linked only after verification, order ownership, account deletion, saved addresses).
  They are skipped unless `TEST_DB_NAME` is set. The database is **wiped and rebuilt** from
  `schema.sql` + migrations on every run, so its name must contain `test`:

```bash
mysql -u root -p -e "CREATE DATABASE store_test; GRANT ALL ON store_test.* TO 'store_user'@'localhost';"
TEST_DB_NAME=store_test TEST_DB_USER=store_user TEST_DB_PASS=… composer test
# also: TEST_DB_HOST (default 127.0.0.1), TEST_DB_PORT (3306)
```

### Continuous integration

`.github/workflows/ci.yml` runs on every push to `main` and every pull request: on PHP 8.2, 8.4 and
8.5 it installs the dependencies, lints every PHP file (`php -l`), checks `public/assets/js/*.js`
with `node --check`, and runs all tests with a MariaDB 11.4 service for the integration suite. A
second job runs PHPStan (`phpstan.neon.dist`) and PHP_CodeSniffer (`phpcs.xml.dist`) installed by
`setup-php`; both must pass.

### Code quality

The tools aren't Composer dependencies (Packagist isn't reachable from every install); put the
phars on your `PATH` and run:

```bash
composer cs          # phpcs, PSR-12
composer cs-fix      # php-cs-fixer
composer analyse     # phpstan, level 6
```

---

## 5. Architecture

A request goes `public/index.php` → `App\Kernel` → PSR-15 middleware (`config/middleware.php`) →
controller → `Responder` → response:

1. **ErrorHandler** turns exceptions into an error page (or JSON for `/api/…`) and logs them.
2. **Session** starts a hardened session.
3. **LegacyUrl** redirects old `*.php` URLs to the clean ones (301, or 308 for POST).
4. **Routing** matches the URL against `config/routes.php` (404 / 405 otherwise).
5. **AdminAuth** sends anonymous visitors of `/admin/…` to the login page (401 for the admin API)
   and answers 403 when the admin's role is below the route's (`->admin('staff'|'manager'|'owner')`).
6. **Csrf** checks the token on every POST except payment webhooks and the cart API.
7. **AdminAudit** writes an Admin → Activity log entry for every successful admin POST.
8. **PageView** records analytics for tracked storefront pages.

Controllers stay thin: they read the PSR-7 request, call services/repositories, and return
`$responder->view()`, `redirectToRoute()` or `json()`. Forms use POST-Redirect-GET with flash
messages; invalid input is shown again with status 422.

```
bin/console                      CLI entry point (src/Console)
config/
  container.php                  PSR-11 service definitions (everything else is autowired)
  middleware.php                 middleware order
  routes.php                     every URL, by name — use path('name') in templates
database/                        schema.sql + migrations/
public/                          web root
  index.php                      front controller
  assets/                        JS, images (uploads in assets/images/products, branding)
  themes/<slug>/                 theme.json, layout/*.html.twig, assets/
resources/fonts/                 font for generated placeholder images
src/
  Kernel.php                     builds the container, runs the pipeline
  Controller/Shop/               home, product, cart, checkout, order confirmation, info pages,
                                 customer accounts (AccountAuthController, AccountController)
  Controller/Admin/              login, dashboard, products, categories, orders, shipping, analytics,
                                 settings, users, my account, activity log
  Controller/Api/                cart API used by assets/js/app.js
  Controller/Payment/            Stripe/Revolut/OxaPay webhooks, PayPal return
  Http/                          router, middleware, session, responder
  Repository/                    all SQL lives here (one class per table/aggregate)
  Service/                       business rules: cart, checkout, shipping, images, settings
  Payment/                       PaymentMethod, registry, recorder, Method/* implementations
  Security/                      CSRF, admin and customer authentication, HTML sanitizer, SSRF-safe URL check
  Infrastructure/                database, HTTP client, OxaPay and Gemini clients
  Theme/                         sandboxed theme renderer + theme installer
  View/                          Twig setup and template functions (path, asset, money, …)
  Support/                       Config (.env) and Paths
templates/                       app views: layout/, shop/, admin/, error/
tests/                           run.php (composer test), Unit/, Integration/
var/                             cache/, log/, tmp/, backups/ (not in git)
```

### URLs

| Page | URL | Route name |
|---|---|---|
| Shop | `/`, `/?category=slug` | `home` |
| Shop search / sort / pages | `/?q=tea&sort=price_asc&page=2` (`sort`: `name`, `price_asc`, `price_desc`, `newest`) | `home` |
| Product | `/product/{id}-{slug}` (`/product/{id}` and old slugs 301 to it) | `product.show` — use `product_url(product)` |
| Robots / sitemap | `/robots.txt`, `/sitemap.xml` | `robots`, `sitemap` |
| Product thumbnails | `/assets/images/thumbs/{400,800}/products/…` (made on first request) | `thumbnail` |
| Cart / Checkout | `/cart`, `/checkout` | `cart`, `checkout` |
| Order confirmation | `/order/confirmation?order=…&key=…` | `order.confirmation` |
| Track your order | `/order/track` (order number + email) | `order.track`, `order.lookup` |
| Customer account | `/account`, `/account/orders[/{number}]`, `/account/addresses`, `/account/profile` | `account`, `account.*` |
| Sign in / register | `/account/login`, `/account/register`, `/account/logout` (POST), `/account/forgot-password`, `/account/reset-password?token=…`, `/account/verify?token=…` | `account.login`, `account.register`, … |
| Info pages | `/about`, `/info`, `/support`, `/terms` | `page.*` |
| Cart API | `/api/cart` | `api.cart` |
| Checkout totals (JSON) | `/api/checkout/quote` | `api.checkout.quote` |
| Admin | `/admin` (dashboard), `/admin/login`, `/admin/products`, `/admin/orders/{id}`, `/admin/customers/{id}`, `/admin/users`, `/admin/account`, `/admin/activity`, … | `admin.*` |
| Webhooks | `/payment/stripe`, `/payment/revolut`, `/payment/oxapay`, `/payment/paypal/return` | `payment.*` |

To add a page: add a route in `config/routes.php`, a controller method, and a template in
`templates/`. Constructor dependencies are injected automatically.

---

### Admin → Settings

- **Store** — store name, support email and logo (PNG/JPG/WebP/GIF, up to 2 MB), plus whether the
  name is shown next to the logo. These override `STORE_NAME` / `STORE_EMAIL` in `.env`.
- **Inventory** — the low-stock threshold (default 5): active variants with that much stock or
  less get a "Low stock" badge and highlight in Admin → Products, which also has a **Low stock**
  filter. `VariantRepository::countLowStock()` / `findLowStock()` expose the same list.
- **Tax** — VAT on/off, default rate, per-country rates (`PT=23, ES=21, Germany=19`, matched like
  shipping countries against what the customer types), whether prices include VAT (default yes)
  and whether shipping is taxed. See [VAT and discount codes](#vat-and-discount-codes).
- **Themes** — preview and switch between installed themes (overrides `THEME` in `.env`), delete
  uploaded ones.
- **Upload your own theme** — download the blank theme (`blank-theme.zip`: folder structure,
  commented stylesheet, the default Twig templates and a README listing every variable), edit it,
  zip it and upload it. PHP files are never installed; templates are checked in the Twig sandbox
  on upload and rejected if they use anything that isn't allowed.

Uploads need the PHP `zip` extension and write access to `public/themes/`,
`public/assets/images/branding/` and `var/` (all handled by `setup-apache.sh`). Files with a
script extension anywhere in their name (`x.php.css`, …) are skipped, and `public/themes/.htaccess`
switches script handlers off. A form larger than PHP's `post_max_size` shows "The upload is too
large (limit …)".

### Admin → Products and Categories

- Every action (edit, status, images, variants, bulk actions) returns to the same page, sort and
  filters. Invalid edits are shown again with the typed values and an error (name max 180,
  short description max 280 characters — the column sizes).
- **Bulk actions** on the ticked products: delete, set status, and change prices — set every
  variant to a price, or raise/lower by a percentage or a fixed amount (rounded to 2 decimals,
  never below 0).
- **Images** — the first image is the main one; drag thumbnails to reorder, or use the ← / → / ★
  buttons (these work without JavaScript).
- **Categories** have an optional description and image (`categories.description`,
  `categories.image_path`, stored in `public/assets/images/categories/`), shown on the storefront.

### CSV export and import (managers and owners)

- **Products → Export CSV** downloads every variant of the products matching the current status /
  low-stock filter, one row per variant:
  `product_id,name,category,status,short_description,sku,label,unit,price,stock,active`.
- **Orders → Export orders CSV / Export order items CSV** downloads every order (or order line)
  matching the list's status, search and date filters — not just the page shown. Order rows have
  totals, statuses, payment and shipping details and dates; columns added to `orders` later (VAT,
  discounts…) are appended automatically.
- Files are UTF-8 with a byte-order mark (Excel opens them correctly) and are streamed, not built in
  memory. A cell starting with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with `'` so a
  spreadsheet never runs it as a formula (plain negative numbers are left alone); the import strips
  that prefix again.
- **Products → Import CSV** takes the same format (export, edit in a spreadsheet, save as *CSV UTF-8*;
  `,` or `;` separated; max 5 MB / 5 000 rows). It is two steps: the upload is checked and shown as a
  **preview** — per row: new product, new variant, update (with each change, e.g. `price 10.00 → 12.00`),
  unchanged, or the error — and nothing is written until you click **Apply**, which re-checks the file
  against the current data and writes everything in one transaction (rows with errors are skipped).
  - Rows are matched by **SKU**: a known SKU updates the variant's price, stock, label, unit and active
    flag and the product's name, category, status and short description. Columns left out — and empty
    name/category/status/short description/price/stock/active cells — keep the current value; an empty
    label or unit clears it.
  - An unknown SKU creates a variant: of `product_id` when given, otherwise of a new product (needs
    name, category, short description and a price above 0; rows with the same name + category share
    one product). New products start with status **created**, like the add form.
  - Categories are matched by name; unknown ones are an error unless **Create missing categories** is
    ticked. Nothing is ever deleted by an import.

### Admin security

- Admin pages are sent with a strict `Content-Security-Policy` (scripts from our own origin only —
  keep admin JavaScript in `public/assets/js/`, never inline) plus `X-Frame-Options: DENY`.
  Storefront pages get `X-Content-Type-Options: nosniff` and `Referrer-Policy:
  strict-origin-when-cross-origin` (order links carry a `?key=…` that must not leak to other
  sites), but no CSP, since themes may use inline scripts and styles.
- The admin header and footer always come from the default theme, so an uploaded theme can't
  add markup or scripts to the admin (its stylesheet is still used).
- Logging out is a POST with the form token. The session id and the form token are renewed on
  login and logout; `session.use_strict_mode` is on.
- Failed logins are limited per client IP (5 per 15 minutes) and per username (20 per 15 minutes).
  Behind a reverse proxy set `TRUSTED_PROXIES`, otherwise every visitor shares the proxy's IP.
  Unknown usernames take as long to reject as wrong passwords.

### Admin users and roles

Admins are rows in `admin_users` (migration `021`). **Bootstrap:** while that table is empty, the
`.env` account (`ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH`) can log in, and its first login saves it
as the first **Owner**; after that the `.env` values are ignored. Add the other admins in
**Admin → Users**.

| Role | Can use |
|---|---|
| Owner | everything, including **Users**, **Settings** (store, tax, themes) |
| Manager | everything except Users and Settings: shipping, discounts, analytics, activity log, … |
| Staff | dashboard (no revenue figures), products, categories and orders; can't delete products or categories |

- Roles are enforced per route in `config/routes.php`: `->admin()` means Manager, `->admin('staff')` /
  `->admin('owner')` lower or raise it, and `->actionsNeed('manager', ['delete'])` restricts single
  form actions. The admin menu only shows what the role can open. New admin routes default to Manager.
- **Users** (owners): create, change role/email, activate/deactivate, reset passwords. Nobody can
  demote or deactivate themselves, and the last active owner can't be demoted or deactivated.
- **My account** (everyone): change your own password (needs the current one). Passwords: at least
  10 characters (at most 72 bytes — bcrypt's limit), stored with `password_hash()`.
- The session keeps the user id; the user is re-read on every request, so a deactivated user, or one
  whose password was reset, is logged out on their next request. Role changes apply immediately.
- Locked out of every owner account? On the server, `bin/console admin:user <username> --role=owner`
  creates that user or resets it (new password, reactivated, lockout cleared; `--email=` sets the
  email). Without `--password=…` (which would land in your shell history) it generates a strong
  password and prints it once. `bin/console admin:unlock <username> [--ip=address]` only clears the
  failed-login lockout.

### Admin → Activity log

`admin_audit_log` (migration `022`, owners and managers; filter by user, action and date) records
logins, failed logins (with the attempted username), logouts, and every successful admin POST —
`AdminAuditMiddleware` writes one entry per request with the route's entity (`product`, `order`,
`category`, `settings`, `theme`, `coupon`, …), the form `action` and the posted fields. User and
password changes write richer entries themselves (`AuditLog::record()`, which replaces the generic
one). Fields that look like passwords, tokens, secrets or keys are never stored, long text is cut
to 200 characters, and requests that fail (status ≥ 400 or an error message) aren't logged. Mark
admin POSTs that change nothing with `->withoutAudit()`.

### Admin dashboard

`/admin` is the landing page after login: revenue (paid orders, by order date), number of orders
and average order value for today / 7 / 30 days, orders waiting to be shipped or for a bank-transfer
/ cash-on-delivery payment, low-stock variants (Settings → Inventory threshold), the best sellers and
recent orders, and a 30-day revenue chart (`public/assets/js/dashboard.js`). Staff see order counts
and stock but no money figures.

### Themes

Layouts are [Twig](https://twig.symfony.com) templates (`layout/*.html.twig`) rendered in Twig's
sandbox: only the tags, filters and functions listed in `Theme::SANDBOX_*` are available and no PHP
can run, which is what makes uploaded themes safe. A theme only ships the templates it changes — the
rest come from `default` — and if one of its templates fails, the default copy is used instead.
Twig is installed by Composer from GitHub (see `repositories` in `composer.json`), so the server
needs `git`.

Pick a theme in **Admin → Settings**, or set `THEME` in `.env`. These ship with the store:

- `default` — light, neutral starting point
- `minimal` — monochrome, hairline rules, sharp corners
- `warm` — earthy boutique: cream, terracotta, serif headings
- `bold` — high contrast: black outlines, hard shadows, yellow and orange
- `neolab` — dark teal/violet glow
- `succulent` — for plant and cactus lovers: sage green, terracotta, desert sand, rounded shapes

`minimal`, `warm`, `bold` and `succulent` import the default stylesheet and only override what differs.

To make a new one, copy `public/themes/default/` to `public/themes/<your-theme>/` and edit it.
A theme only needs the files it changes: any missing layout or asset falls back to `default`.
App views (`templates/`) include theme parts with `theme_part('header', {page_title: ...})`;
theme templates use `asset('css/style.css')` for asset URLs, `logo()` for the store logo and
`path('route.name')` for links.

Besides `page_title`, the storefront header receives `lang`, `meta_description`, `canonical`,
`robots`, `og_type`, `og_image`, `product_price`, `search_query` and `customer_name` (the signed-in
customer, for the "Account" / "Sign in" link; any may be empty — see the
comment at the top of `default/layout/header.html.twig`). Keep its skip link, search form and
`<main id="main">` when you override the header.

### Storefront catalog

- **Search, sorting, pages** — the header search box searches product names, short descriptions,
  category names and SKUs (every word must match); the shop shows 24 products per page.
- **Stock** — cards show "Out of stock" when no active variant priced above 0 has stock, and
  "Only N left" at or below the low-stock threshold (Admin → Settings, default 5).
- **SEO** — meta description, canonical URL, Open Graph/Twitter tags and JSON-LD (`Product` with
  offers on product pages, `Organization` + `WebSite` with a search action on the shop). Search
  results are `noindex`. `robots.txt` keeps crawlers out of `/admin`, `/cart`, `/checkout`, `/account`.
- **Images** — `thumb(path, 400)`, `srcset(path)` and `image_size(path)` in views serve 400/800px
  WebP (JPEG without GD WebP support) copies of product and placeholder images, cached in
  `public/assets/images/thumbs/` (safe to delete; never upscaled). Storefront images are lazy-loaded
  with explicit sizes.
- **Accessibility** — skip link, labelled controls, and `ajax-nav.js` moves focus to the new page's
  heading and announces its title after each AJAX navigation; cart changes are announced too.

---

### Shipping options

Managed in **Admin → Shipping** — no code or `.env` changes needed. Each option has a name, an
optional description (e.g. delivery estimate), a cost, an optional "free on orders over" amount and
an optional list of countries (blank = everywhere). Options can be enabled/disabled and reordered;
checkout shows only enabled options that deliver to the country the customer types. Orders keep a
copy of the shipping name and price, so editing or deleting an option never changes past orders.

### Payment methods

Each method is switched on with `PAYMENT_<METHOD>_ENABLED=true` in `.env` and only shows at checkout
once its required keys are filled in (see `.env.example`). Methods: `STRIPE`, `PAYPAL`, `REVOLUT`,
`OXAPAY`, `BANK_TRANSFER`, `COD`. OxaPay stays on by default for existing installs as long as
`OXAPAY_MERCHANT_KEY` is set.

| Method | Confirmation | Set up at the provider |
|---|---|---|
| Stripe | Signed webhook | Add endpoint `{APP_URL}/payment/stripe` for `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired` |
| PayPal | Captured when the customer returns | Create a REST app; nothing else to register |
| Revolut Pay | Signed webhook, then the order is re-fetched from Revolut | Register `{APP_URL}/payment/revolut` for the order events |
| OxaPay | Signed callback | Callback URL is sent with each invoice |
| Bank transfer / Cash on delivery | Admin clicks **Mark as paid** on the order | — |

To add a method, implement `App\Payment\PaymentMethod` (or extend `AbstractPaymentMethod`), add it to
`PaymentRegistry`'s constructor, and add a webhook route in `config/routes.php` if it needs one.

### VAT and discount codes

- **Pricing order** (`CheckoutService::price()`, used by the checkout page, the live quote and the
  order itself): items subtotal → shipping (its "free over" threshold uses the subtotal after the
  code's items discount, so a discount can drop an order below it) → discount code → VAT (`TaxCalculator`) on the discounted items (+ shipping when taxed),
  at the rate for the shipping country. Amounts round to the currency's minor unit and never go
  below 0.
- **Prices include VAT** (EU style): the total doesn't change; the VAT part is extracted and shown as
  "Includes VAT (23%)". **Prices exclude VAT**: VAT is added to the total, and every payment method
  (and `PaymentRecorder`'s amount check) charges that final `orders.total`.
- Orders keep `tax_rate`, `tax_amount`, `prices_include_tax`, `coupon_code` and `discount_amount`
  (free-shipping codes: the shipping saved, so subtotal − discount + shipping = total);
  `order_items.tax_amount` holds each line's VAT part. Checkout, the confirmation / track-order pages,
  Admin → Orders and every order email show the discount and VAT lines.
- **Admin → Discounts**: codes (case-insensitive) for a percentage or fixed amount off the subtotal,
  or free shipping, with optional minimum subtotal, start/end, total usage limit and uses per
  customer email. Codes used on orders can't be deleted or renamed — deactivate them.
- **Checkout** has a discount-code field (Apply / Remove; works without JavaScript, the applied
  code is kept in the session). `assets/js/checkout.js` refreshes shipping, discount, VAT and total
  from `GET /api/checkout/quote?country=…&shipping_method=…` (`api.checkout.quote`). A use is counted
  in the checkout transaction with the coupon row locked (so `usage_limit` holds) and given back
  once if the order is cancelled, fails or expires before payment (`orders.coupon_counted`); a
  zero-total order is marked paid straight away (provider `free`).

### Orders, stock and emails

- **Stock** is reserved when the order is placed (inside the checkout transaction, with the variant
  rows locked, so two customers can't buy the last item) and given back exactly once when the order
  is cancelled, its payment fails or expires, or it is refunded with "restock" ticked
  (`orders.stock_reserved` tracks this). Online orders abandoned at the provider are released by
  `bin/console orders:expire` — run it hourly from cron, e.g.
  `0 * * * * cd /var/www/store && bin/console orders:expire --hours=48`.
- **Payments** (`PaymentRecorder`) are idempotent: webhook retries are ignored, a "paid" event must
  match the order total and `STORE_CURRENCY` (otherwise it is logged as `amount_mismatch` and the order
  stays unpaid), and once paid an order is never moved back by later events.
- **Admin → Orders**: search by order number / email / name, date range, pagination. On an order:
  mark paid, cancel (unpaid orders; restocks), mark refunded (paid orders; optional restock — refund
  the money at the provider yourself), mark shipped with carrier + tracking number, edit tracking.
- **Emails** (`templates/email/`, HTML + text, sent by `App\Infrastructure\Mailer` — see `MAIL_*`):
  order confirmation (bank transfer / cash on delivery: when placed, with payment instructions;
  online methods: when paid), payment received (manual methods), shipped (with tracking),
  cancelled / refunded, and a new-order alert to `ADMIN_NOTIFY_EMAIL`. A failed email is logged and
  never breaks checkout or a webhook.
- **Customers** see their order on the confirmation page and at `/order/track` (order number +
  email, rate-limited per session and IP; link in the footer). Order pages need the signed `key` from
  the link (or the session that placed the order), so order numbers alone reveal nothing.

### Customer accounts

Optional — guest checkout works exactly as before. Tables `customers`, `customer_addresses`,
`customer_tokens`, `customer_auth_attempts` and `orders.customer_id` (migrations `024`–`026`).

- **Sign-in** (`CustomerAuthenticator`) is separate from the admin login: its own session keys
  (`customer_id` + a password-hash fingerprint), its own attempt table, and signing out only removes
  those keys (and the "orders placed in this session" list). The session id and form token are
  renewed on sign-in / sign-out. Failed sign-ins are limited per email (5 per 15 minutes) and per IP
  (20). Passwords: 8+ characters, at most 72 bytes. Deactivating an account (Admin → Customers),
  deleting it or changing / resetting its password ends its other sessions on their next request.
- **Register / forgot password / verify** — registering signs you in and emails a verify link
  (7 days; sign-in doesn't wait for it). "Forgot password" answers the same whether or not the email
  has an account, is limited to 3 per email and 10 per IP an hour, and emails a reset link valid for
  **1 hour, once** (only the token's SHA-256 is stored). Emails: `templates/email/customer_verify.*`,
  `customer_password_reset.*`.
- **Account area** — `/account` (overview), orders (only `orders.customer_id` = you; the detail reuses
  `shop/_order-details.html.twig`), saved addresses (add / edit / delete / default), profile (name and
  phone, email change with password + re-verification, password change, delete account). Deleting
  anonymises the row (`deleted-<id>@invalid`, no password, inactive), removes addresses and keeps
  the orders.
- **Checkout** — signed in: fields are prefilled from the default address, another saved address
  can be picked (`checkout.js`, or `?address=<id>` without JavaScript), "Save this address" adds it
  to the account, and the order gets `customer_id`. **Guest orders are never linked automatically**,
  even when the email has an account; they are linked once the account owner confirms that email
  (verify link or password reset).
- **ajax-nav.js** — sign-in, register, reset, sign-out and delete-account forms carry `data-no-ajax`,
  because they change the header and the form token; the other account forms swap `<main>` as usual.
- **Admin → Customers** (managers and owners): search by email / name / phone, order count and total
  spent (paid, not refunded), per customer the orders, saved addresses and deactivate / activate.
  Admin order pages link to the customer account. Note that logging out of the *admin* clears the
  whole session, customer sign-in included.

---

### Deploying to finderskeep.online

`setup-apache.sh` and `verify-env.sh` default to `finderskeep.online` (pass `DOMAIN=...` to use another
domain). On the server:

```bash
sudo ./setup-apache.sh            # packages, .env, database, Apache vhost for finderskeep.online + www
sudo apt-get install -y certbot python3-certbot-apache
sudo certbot --apache --redirect -d finderskeep.online -d www.finderskeep.online
```

Before running certbot, point the DNS **A** (and **AAAA** if you have IPv6) records for
`finderskeep.online` and `www.finderskeep.online` at the server. Keep `APP_URL=https://finderskeep.online`
in `.env` — payment providers use it for return and webhook URLs:

| Provider | Webhook / return URL |
|---|---|
| Stripe | `https://finderskeep.online/payment/stripe` |
| Revolut | `https://finderskeep.online/payment/revolut` |
| PayPal | `https://finderskeep.online/payment/paypal/return` (sent automatically) |
| OxaPay | `https://finderskeep.online/payment/oxapay` (sent automatically) |

The old addresses (`/payment/stripe-webhook.php`, `/payment/revolut-webhook.php`,
`/payment/paypal-return.php`, `/payment/callback.php`) still work, so webhooks already registered
with the providers don't need to change.

---

## 6. Pre-Deployment Checklist

- [ ] **Change the default admin password** — see [Default Admin Credentials](#default-admin-credentials)
- [ ] **Protect `/admin`** — e.g. restrict by IP with a `<Location /admin>` block (see the comment in the generated vhost)
- [ ] **Test each enabled payment method in sandbox mode** — provider APIs change; the integrations live in `src/Payment/Method/` and `src/Controller/Payment/`
- [ ] **Run `database/migrations/010_payment_methods.sql`, `011_shipping_methods.sql` and `012_settings.sql`** on existing databases
- [ ] **Run `016_login_attempts_username.sql` and `017_category_details.sql`** on existing databases (per-username login lockout; category description/image)
- [ ] **Run `021_admin_users.sql`, `022_admin_audit_log.sql` and `023_dashboard_indexes.sql`** on existing databases, log in once with the `.env` account (it becomes the first Owner), then add the other admins in **Admin → Users**
- [ ] **Run `018_order_tax.sql`, `019_coupons.sql` and `020_order_discounts.sql`** on existing databases, then set up VAT in **Admin → Settings → Tax** (off by default)
- [ ] **Review shipping options** in **Admin → Shipping** (cost, free-over threshold, countries)
- [ ] **Serve over HTTPS** — required for production webhooks
- [ ] **Set `APP_DEBUG=false`** and check that `var/` is writable by the web server
- [ ] **Run `database/migrations/013_order_stock_tracking.sql` and `014_order_lookup_attempts.sql`** on existing databases
- [ ] **Set up email**: `MAIL_TRANSPORT=smtp` + `MAIL_*`, `ADMIN_NOTIFY_EMAIL`, and set `APP_SECRET`
- [ ] **Add the cron job** for `bin/console orders:expire` (releases stock held by abandoned online payments)
- [ ] **Schedule backups** — `bin/console db:backup --gzip --with-uploads --keep=14` from cron (see [Backups](#backups)), copy them off the server, and test a restore once
- [ ] **Run `024_customers.sql`, `025_orders_customer.sql` and `026_customer_tokens.sql`** on existing databases (customer accounts; set `APP_URL` so emailed verify / reset links point at the store)

---

## 7. Roadmap

- Tests for the HTTP layer (controllers through the middleware pipeline) on top of the service tests
