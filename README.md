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
`public/assets/images/{products,generated,branding}/`.

---

## 3. Environment variables

`.env` in the project root is read by `App\Support\Config`. Variables already set in the process
environment take priority. Comments must be on their own line.

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MariaDB connection |
| `APP_URL` | Public base URL (e.g. `https://finderskeep.online`) — used for payment return and webhook URLs |
| `APP_DEBUG` | `true` shows exception details on error pages and logs at debug level. Keep `false` in production |
| `STORE_NAME`, `STORE_EMAIL` | Defaults for the store name and contact email (Admin → Settings overrides them) |
| `STORE_CURRENCY` | ISO currency code sent to payment providers (default `EUR`) |
| `THEME` | Default theme (Admin → Settings overrides it) |
| `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH` | Admin login |
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
bin/console images:add <product_id> <url> [--gallery]
bin/console images:check <product_id> [--apply]    remove branding with Gemini or find a replacement
bin/console images:regenerate [--force]            placeholder images for products without a photo
bin/console db:reimport --force                    DEV ONLY: drop all tables, rebuild from schema + migrations
```

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
5. **AdminAuth** sends anonymous visitors of `/admin/…` to the login page (401 for the admin API).
6. **Csrf** checks the token on every POST except payment webhooks and the cart API.
7. **PageView** records analytics for tracked storefront pages.

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
  Controller/Shop/               home, product, cart, checkout, order confirmation, info pages
  Controller/Admin/              login, products, categories, orders, shipping, analytics, settings
  Controller/Api/                cart API used by assets/js/app.js
  Controller/Payment/            Stripe/Revolut/OxaPay webhooks, PayPal return
  Http/                          router, middleware, session, responder
  Repository/                    all SQL lives here (one class per table/aggregate)
  Service/                       business rules: cart, checkout, shipping, images, settings
  Payment/                       PaymentMethod, registry, recorder, Method/* implementations
  Security/                      CSRF, admin authentication, HTML sanitizer, SSRF-safe URL check
  Infrastructure/                database, HTTP client, OxaPay and Gemini clients
  Theme/                         sandboxed theme renderer + theme installer
  View/                          Twig setup and template functions (path, asset, money, …)
  Support/                       Config (.env) and Paths
templates/                       app views: layout/, shop/, admin/, error/
var/                             cache/, log/, tmp/ (not in git)
```

### URLs

| Page | URL | Route name |
|---|---|---|
| Shop | `/`, `/?category=slug` | `home` |
| Product | `/product/{id}` | `product.show` |
| Cart / Checkout | `/cart`, `/checkout` | `cart`, `checkout` |
| Order confirmation | `/order/confirmation?order=…` | `order.confirmation` |
| Info pages | `/about`, `/info`, `/support`, `/terms` | `page.*` |
| Cart API | `/api/cart` | `api.cart` |
| Admin | `/admin/login`, `/admin/products`, `/admin/orders/{id}`, … | `admin.*` |
| Webhooks | `/payment/stripe`, `/payment/revolut`, `/payment/oxapay`, `/payment/paypal/return` | `payment.*` |

To add a page: add a route in `config/routes.php`, a controller method, and a template in
`templates/`. Constructor dependencies are injected automatically.

---

### Admin → Settings

- **Store** — store name, support email and logo (PNG/JPG/WebP/GIF, up to 2 MB), plus whether the
  name is shown next to the logo. These override `STORE_NAME` / `STORE_EMAIL` in `.env`.
- **Themes** — preview and switch between installed themes (overrides `THEME` in `.env`), delete
  uploaded ones.
- **Upload your own theme** — download the blank theme (`blank-theme.zip`: folder structure,
  commented stylesheet, the default Twig templates and a README listing every variable), edit it,
  zip it and upload it. PHP files are never installed; templates are checked in the Twig sandbox
  on upload and rejected if they use anything that isn't allowed.

Uploads need the PHP `zip` extension and write access to `public/themes/`,
`public/assets/images/branding/` and `var/` (all handled by `setup-apache.sh`).

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

`minimal`, `warm` and `bold` import the default stylesheet and only override what differs.

To make a new one, copy `public/themes/default/` to `public/themes/<your-theme>/` and edit it.
A theme only needs the files it changes: any missing layout or asset falls back to `default`.
App views (`templates/`) include theme parts with `theme_part('header', {page_title: ...})`;
theme templates use `asset('css/style.css')` for asset URLs, `logo()` for the store logo and
`path('route.name')` for links.

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

Stock is reduced once, the first time an order becomes paid; repeated webhooks don't reduce it again.
To add a method, implement `App\Payment\PaymentMethod` (or extend `AbstractPaymentMethod`), add it to
`PaymentRegistry`'s constructor, and add a webhook route in `config/routes.php` if it needs one.

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
- [ ] **Review shipping options** in **Admin → Shipping** (cost, free-over threshold, countries)
- [ ] **Serve over HTTPS** — required for production webhooks
- [ ] **Set `APP_DEBUG=false`** and check that `var/` is writable by the web server
- [ ] **Wire up email confirmation** in `src/Payment/PaymentRecorder.php` at the existing `TODO` so customers receive a receipt after payment
- [ ] **Consider customer accounts** if you need order history beyond the order-number + email lookup flow

---

## 7. Roadmap

- Order tracking page (look up by order number + email)
- Pagination on the product listing
- Automated tests (PHPUnit) for the services and controllers
