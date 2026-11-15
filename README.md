<div align="center">

# 🤖 Mirza Bot

### A powerful Telegram bot for selling VPN services — with fully automated config creation.

<p>
  <a href="https://t.me/mirzapanel">
    <img src="https://img.shields.io/badge/Telegram-Channel-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white" alt="Telegram Channel"/>
  </a>
  <a href="https://t.me/mirzapanelgroup">
    <img src="https://img.shields.io/badge/Telegram-Group-229ED9?style=for-the-badge&logo=telegram&logoColor=white" alt="Telegram Group"/>
  </a>
  <a href="https://mirzabot.com/docs/">
    <img src="https://img.shields.io/badge/Docs-mirzabot.com-38BDF8?style=for-the-badge&logo=readthedocs&logoColor=white" alt="Documentation"/>
  </a>
</p>

<p>
  <a href="https://github.com/mahdiMGF2/mirzabot/stargazers">
    <img src="https://img.shields.io/github/stars/mahdiMGF2/mirzabot?style=flat-square&color=f5c518" alt="Stars"/>
  </a>
  <a href="https://github.com/mahdiMGF2/mirzabot/network/members">
    <img src="https://img.shields.io/github/forks/mahdiMGF2/mirzabot?style=flat-square" alt="Forks"/>
  </a>
  <a href="https://github.com/mahdiMGF2/mirzabot/issues">
    <img src="https://img.shields.io/github/issues/mahdiMGF2/mirzabot?style=flat-square" alt="Issues"/>
  </a>
  <a href="https://github.com/mahdiMGF2/mirzabot/blob/main/LICENSE">
    <img src="https://img.shields.io/github/license/mahdiMGF2/mirzabot?style=flat-square" alt="License"/>
  </a>
  <img src="https://img.shields.io/badge/PHP-8.5.12%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.5.12+"/>
</p>

</div>

---

## 📚 Table of Contents

- [✨ Overview](#-overview)
- [📖 Documentation](#-documentation)
- [🧩 Supported Panels](#-supported-panels)
- [💳 Payment Gateways](#-payment-gateways)
- [⚙️ Features](#️-features)
- [🚀 Installation](#-installation)
  - [Prerequisites](#prerequisites)
  - [Install](#install)
  - [Update](#update)
  - [Remove](#remove)
  - [Non-Interactive (CLI) Usage](#non-interactive-cli-usage)
- [💎 Free vs. Pro](#-free-vs-pro)
- [🌍 Languages](#-languages)
- [💵 Support the Project](#-support-the-project)
- [👥 Contributors](#-contributors)

---

## ✨ Overview

**Mirza Bot** is a feature-rich Telegram bot for selling VPN subscriptions and automating the entire sales workflow — from purchase and payment to config creation and service management.

It connects directly to your panels, builds configurations automatically, accepts a wide range of payment methods, and gives both customers and admins a clean experience through a **Telegram Mini App** and a **web admin panel**.

> Whether you're handing out trial accounts or running a large-scale reseller business, Mirza Bot has the tools to run it end to end.

---

## 📖 Documentation

The complete user manual lives at **[mirzabot.com/docs](https://mirzabot.com/docs/)** — 61 pages covering every part of the bot, written in **Persian (فارسی)**.

It is not a feature list: every menu is documented with the exact order of the steps the bot asks for, what each field accepts, the error message you get when it rejects your input, and the mistake that most often breaks that feature.

| Section | What it covers |
|---------|----------------|
| **شروع** (Getting started) | Architecture, requirements, server install, shared-host install, CLI flags, updating and removing |
| **ساختار** (Structure) | Repository layout, database tables, which file does what |
| **پنل‌ها** (Panels) | Adding each of the 13 panel types, protocol & inbound setup, per-panel menus, manual config creation, node management |
| **فروشگاه** (Shop) | Products, categories, trial accounts, On-Hold services, bulk purchase, manual sale, renewals and location changes |
| **پرداخت** (Payments) | Card-to-card with receipt approval, every online and crypto gateway, wallet and refunds |
| **مدیریت** (Administration) | Admin roles, feature switches, report channel and topics, texts, forced-join channel, web panel |
| **رشد و بازاریابی** (Growth) | Referrals, cashback, discount and gift codes, lottery and wheel, reseller system |
| **نگهداری** (Operations) | Cron jobs, backup and restore, optimization, security checklist, troubleshooting |

> The docs are generated from the bot's own source, so menu names, limits and error strings match the code rather than an older release.

---

## 🧩 Supported Panels

Mirza Bot integrates with the most popular VPN and network management panels:

| Panel | Panel |
|-------|-------|
| 🟢 **Marzban** | 🟢 **Marzneshin** |
| 🟢 **Sanaei / Alireza** |
| 🟢 **S-UI** | 🟢 **Hiddify** |
| 🟢 **WGDashboard** (WireGuard) | 🟢 **MikroTik** |
| 🟢 **IBSng** | 🟢 **Pasarguard** |

> Configs are generated automatically and are compatible with all common protocols.

---

## 💳 Payment Gateways

| Gateway                | Type                              |
|------------------------|-----------------------------------|
| 💵 **Card-to-Card**    | Manual (receipt + admin approval) |
| 🪙 **NowPayments**     | Crypto                            |
| 🪙 **Plisio**          | Crypto                            |
| 🪙 **Tronado**         | TRON / crypto                     |
| 🪙 **Tetraminator**    | USDT(BEP20) / crypto              |
| 🇮🇷 **Zarinpal**      | Online gateway                    |
| 🇮🇷 **Aqayepardakht** | Online gateway                    |
| 🇮🇷 **IranPay**       | Online gateway                    |
| Gateway | Type |
|---------|------|
| 💵 **Card-to-Card** | Manual (receipt + admin approval) |
| 🪙 **NowPayments** | Crypto |
| 🪙 **Plisio** | Crypto |
| 🪙 **CubePay** | TRON / crypto |
| 🇮🇷 **Zarinpal** | Online gateway |
| 🇮🇷 **Aqayepardakht** | Online gateway |
| 🇮🇷 **IranPay** | Online gateway |

---

## ⚙️ Features

### 🛒 Sales & Configuration
- ✅ VPN purchase with **fully automated** config creation
- ✅ Trial / test accounts for new users
- ✅ Compatibility with all common protocols
- ✅ QR codes for fast config import
- ✅ Protocol-based configuration settings
- ✅ Product, panel & gateway management

### 👤 User Experience
- ✅ **Telegram Mini App** for a modern, in-app interface
- ✅ View & manage purchased services:
  - Renew a service
  - Buy additional volume
  - Retrieve config / update subscription links
- ✅ Wallet & balance system
- ✅ Detailed purchase & trial reports
- ✅ Support section, FAQ & customizable tutorials
- ✅ Phone-number verification
- ✅ Mandatory channel membership for purchases

### 📈 Growth & Marketing
- ✅ Affiliate / referral system
- ✅ Cashback rewards
- ✅ Discount codes
- ✅ Gift codes
- ✅ Lottery system
- ✅ **Agent / reseller** system

### 🛠️ Administration
- ✅ **Web admin panel** (login-protected dashboard)
- ✅ Multiple admins support
- ✅ Balance & user management
- ✅ Full text/message customization from the bot
- ✅ Configurable username-generation methods
- ✅ Automatic backups
- ✅ Notification & expiry-reminder services (cron)
- ✅ On-hold configurations

---

## 🚀 Installation

### Prerequisites

| Requirement | Details |
|-------------|---------|
| 🖥️ **OS** | A **clean** Ubuntu **22.04/24.04/26.04** or **Debian 12/13** server |
| 🌐 **Domain** | A domain name pointed to your server's IP |
| ⚙️ **Stack** | PHP 8.5.12+, Apache, MySQL/MariaDB, SSL — *installed automatically by the script* |

> 💡 Start from a fresh server with no existing web server, database, or panel installed.

### Install

Run the following command on your server as **root**:

```bash
curl -o install.sh -L https://raw.githubusercontent.com/Rezah95/mirzabot/master/install.sh && bash install.sh
```

An interactive menu will appear:

```
1) Install Mirza
2) Update Mirza
3) Remove Mirza
4) Migrate: Free → Pro (Beta)
5) Renew SSL certificate
6) Help & Parameters
7) Exit
```

➡️ Select **`1`** to install the bot, then follow the prompts.

### Update

Run the same command and select **`2`**:

```bash
curl -o install.sh -L https://raw.githubusercontent.com/Rezah95/mirzabot/master/install.sh && bash install.sh
```

### Remove

Run the same command and select **`3`** to completely remove the bot and its services.

### Non-Interactive (CLI) Usage

You can also drive the installer entirely from the command line — handy for automation and scripted deployments.

**Commands**

| Command | Description |
|---------|-------------|
| `install` | Install Mirza |
| `update` | Update Mirza (choose channel / version) |
| `remove` | Remove Mirza and its services |
| `migrate` | Migrate Free → Pro |
| `renew` | Renew the bot's SSL certificate |
| `menu` | Open the interactive panel (default) |

**Install parameters**

| Parameter | Description |
|-----------|-------------|
| `--name` | Bot username |
| `--token` | Telegram bot token |
| `--admin` | Admin chat ID |
| `--domain` | Domain name (e.g. `bot.example.com`) |
| `--db-user` | Database username |
| `--db-pass` | Database password |
| `--version` | Specific release tag (e.g. `0.1.7`) |
| `--channel` | `beta` · `release` · `auto` |
| `-h`, `--help` | Show CLI help and exit |

**Examples**

```bash
# Auto-pick the best channel
mirza install --channel auto

# Fully non-interactive install
mirza install --name myvpnbot --token 123:ABC \
              --admin 111 --domain bot.example.com --version 0.1.7

# Update to a specific version or channel
mirza update --version 0.1.6
mirza update --channel release

# Remove
mirza remove
```

---

## 💎 Free vs. Pro

| | Free 🆓 | Pro 💎 |
|---|:---:|:---:|
| Automated VPN sales & config creation | ✅ | ✅ |
| Trial accounts, wallet & service management | ✅ | ✅ |
| All supported panels & payment gateways | ✅ | ✅ |
| Advanced customization & analytics | — | ✅ |
| Enhanced management & extra modules | — | ✅ |

📌 **Pro purchase guide:** [View on Telegram »](https://t.me/mirzaperimium/4)

---

## 🌍 Languages

Mirza Bot ships with full translations for:

🇬🇧 English · 🇮🇷 Persian (فارسی) · 🇷🇺 Russian (Русский) · 🇨🇳 Chinese (中文)

---

## 💵 Support the Project

If **Mirza Bot** helps your business, please consider supporting its development with a crypto donation:

<a href="https://nowpayments.io/donation/mahdi">
  <img src="https://img.shields.io/badge/Donate-NowPayments-1A1A2E?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate"/>
</a>

Your support keeps the updates and improvements coming. Thank you! 🙌

---

## 👥 Contributors

Thanks to everyone who has contributed to making Mirza Bot better:

<a href="https://github.com/mahdiMGF2/mirzabot/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=mahdiMGF2/mirzabot" alt="Contributors"/>
</a>

---

<div align="center">

**Made with ❤️ by the Mirza Panel community**

📖 [Documentation](https://mirzabot.com/docs/) · 💬 [Channel](https://t.me/mirzapanel) · 👥 [Group](https://t.me/mirzapanelgroup) · ⭐ [Star on GitHub](https://github.com/mahdiMGF2/mirzabot)

</div>

## Release process and Tronado recovery

The root `version` file is authoritative. Development changes are committed on `dev`; approved releases are promoted to `master` and receive an annotated version tag. This release follows the maintainer's standing instruction to publish fixes to both branches.

Version **0.5.9** migrates the legacy four-component **0.5.8.17** to three-component SemVer without rewriting old tags. The installer accepts `v0.5.9` and sorts it after the legacy versions. Future releases use `vX.Y.Z` tags; before 1.0, compatible fixes increment patch and features or breaking changes increment minor. This release repairs callback delivery and adds admin diagnostics.

Tronado now attempts fulfillment immediately after acknowledging a signed callback on FPM, LiteSpeed and other PHP handlers. The cron dispatcher remains a recovery path. Reverse-proxy buffering can affect when the provider receives a flushed response. See the administrator's Tronado settings → **وضعیت کال‌بک و سفارش‌ها** for stored callbacks and recent invoice states. Receive, acceptance, delivery and report errors are recorded in `payment/error_log`.

**Upgrade reconciliation:** only new invoices created with this release are automatically delivered. Older unpaid invoices receiving payment and old queued invoices are held as `review` because they may already have been compensated manually. Reconcile manual credits before settling any historical invoice; do not bulk replay old payments. Already completed payments are never replayed. Provider order numbers and local payment IDs are different identifiers.

Targeted checks: `php tests/tronado_gateway_test.php`, `php tests/gateway_names_admin_test.php`, `php tests/renewal_flow_test.php`. The HTTP/MySQL integration test `tests/tronado_callback_test.php` requires a dedicated temporary MySQL instance and `TRONADO_TEST_SOCKET` pointing to its socket; it never loads production configuration.

### Cron runtime diagnosis (0.5.10)

An active system cron daemon does not prove that the PHP jobs succeeded. Check `storage/cron_status.json` and `cronbot/error_log`. The dispatcher records startup, the active job, completion and interruption; missing `mysqli` or `pdo_mysql` blocks jobs with a specific runtime error before partial database bootstrap can cause cascading `prepare() on null` failures.

The installer and the administrator's cron repair now select a PHP CLI with MySQL extensions that actually provides `mysqli_connect` and `pdo_mysql`. The generated crontab uses the verified versioned executable. VPS updates register the dispatcher as `www-data` using the compatible PHP selected for database migration. Registration replaces this bot's old commands atomically, preserves unrelated entries and reports failure instead of claiming success. A busy bulk-message worker returns to the dispatcher so later jobs can run.

For an existing VPS, inspect the exact binary in `sudo crontab -u www-data -l`, then check its modules as the same user, for example:

```bash
sudo -u www-data /usr/bin/php -r 'echo PHP_VERSION, PHP_EOL; var_dump(function_exists("mysqli_connect"), extension_loaded("pdo_mysql"));'
```

Install/enable the MySQL package matching that PHP version if either check is false. Once available, the existing minute schedule resumes automatically. After upgrading to 0.5.10, the admin **تنظیم مجدد کرون** button can replace a stale PHP path; it does not run payment or service jobs immediately. CLI registration is also available through `sudo -u www-data /path/to/verified/php /path/to/bot/cronbot/register.php`.

Cron regression checks: `php tests/cron_dispatcher_test.php` and `php tests/cron_registration_test.php`. These use isolated fixtures and never change system crontabs or production data.


### Integrated panel drivers and cron dispatcher (0.7.1)

The upstream 0.7.1 panel-driver layout is integrated with this fork's discount,
payment, renewal-reminder and cron diagnostics behavior. APIs now live under
`panels/Api/`, and `panels/PanelRegistry.php` selects provider drivers. Nexora and
WGMate are included; the unused `alireza_single` and `s_ui` integrations are removed.
After successful creation, renewal or quota changes, invoice expiry/depletion
caches are maintained before returning the driver result. WireGuard link handling
is retained. The dispatcher records `started_at` separately from completion time
and skips a job already started in the same minute.

Requires PHP >= 8.5.12 with `mysqli` and `pdo_mysql` for the CLI as well as the web
runtime. Use the normal updater and its `table.php` schema migration; database
structure is defined under `db/`, rather than duplicated in `table.php`. Back up
before upgrading. Existing invoices, custom settings and provider credentials must
be preserved. Removal of the two unused panel types is a pre-1.0 compatibility
change; retain the upstream minor-line target 0.7.1, above the prior fork 0.5.10.

The validated development candidate is tagged `v0.7.1-dev.1`. The maintainer-approved
local release uses `0.7.1` in `version` and tag `v0.7.1` on `master`; `dev` also
contains this release. The previous fork release was `0.5.10`.
Graphify artifacts are refreshed locally and remain untracked under the existing
`graphify-out/` ignore rule. Local tests use fixtures and an isolated MySQL server;
they do not certify live provider connectivity or a production deployment.


### Staged updater repair (0.9.1)

The development updater prepares a complete replacement in a private `.mirza-update.*` directory on the live filesystem. It restores config, selected runtime files, language overrides and child-bot customizations, removes the web installer and validates critical PHP files before publication. Both flat hosting ZIPs and single-root GitHub archives are identified by project files rather than by the first directory in the ZIP.

Publication renames the old directory into the private workspace and then renames the prepared tree to the serving path. It never recursively deletes the active installation. The previous tree and full site/database backup are retained; if the second rename fails, rollback preserves an unexpectedly recreated path as `interrupted-writes` and attempts to restore the old tree. A rollback failure prints the retained recovery path. There is a short rename gap; this does not promise uninterrupted requests or automatic database rollback.

The entry command remains `curl -o install.sh -L https://raw.githubusercontent.com/Rezah95/mirzabot/master/install.sh && bash install.sh`. The approved 0.9.1 repair is published on master, so this command downloads the corrected installer. Filesystem regression checks run with `python3 tests/updater_files_test.py`; host services, network and database migration execution are fixture-controlled, not a live VPS upgrade.

### Release integration (0.9.0)

Release 0.9.0 promotes the approved 0.9.0-dev.2 candidate, integrating upstream 0.9 with fork payment, discount, audience and cron protections. It requires PHP 8.5.12+ for the application; syntax and isolated regression checks on older PHP do not certify that runtime. Approved source releases are published on master and tagged v<version>; development continues on dev. Publishing a release does not deploy it to an existing server.

Gateway payments link to their pending invoice or service order. Telegram Stars validates the payer, currency and stored amount before claiming delivery; failed or refunded deliveries do not receive cashback. Database upgrades preserve fulfillment/discount indexes alongside the new payment relationships. The updater preserves fork backups and text overrides, and provides both `migrate-db` and `update-php` commands.

### Before-expiry renewal offers (0.9.0)

Admin path: **پنل ادمین → مدیریت کاربران → بخش ارسال پیام → یادآوری و پیشنهاد تمدید → تخفیف پیش از اتمام**. Use **فعال‌سازی ۱۰٪: ۳ روز / ۵ گیگ** to enable the preset in one action. Time, remaining data, percentage and code validity can be changed individually; either threshold can be disabled with zero. Both conditions cannot be disabled while offers are enabled. After-expiry reminders retain their separate settings and customized message.

Offers are disabled by default on installation and upgrade. The preset sends a personal 10% renewal coupon at **at most 3 days remaining** or **at most 5 GiB remaining**, with a seven-day validity. The existing service-monitor cron checks fresh panel data during its normal scan; processing capacity and panel availability determine when an eligible service is checked. Unlimited, unstarted, exhausted and expired services are excluded where applicable. Each condition sends once per observed expiry/quota cycle; successful paid renewal history also rearms unlimited-time services whose quota stays unchanged.

Messages show the service, remaining time/data, a copyable coupon and a renewal button. Coupons are single-use and restricted to the recipient's renewals, **not to one invoice/service**. Each threshold has its own coupon. Rate limits retry with the saved code; ambiguous deliveries are held for review rather than automatically replayed. The admin menu displays successful sends and delivery problems.

Edit all new message templates in **Web dashboard → Bot texts → Renewal offers** (Persian: **داشبورد وب → متن‌های ربات → پیشنهاد تمدید**), or use the direct editor button in the offer settings. Defaults live under `renewalOffers` in all four `lang/*.php` files and runtime overrides use the existing `lang/override` path. Supported fields: `{username}`, `{service}`, `{days}`, `{remaining_gb}`, `{percent}`, `{code}`, `{valid_until}`. Preserve these fields when customizing templates; HTML-like user values are escaped and the code is rendered as a copyable code span.

Upgrade with the normal database bootstrap before enabling offers: additive settings columns and `renewal_warning_state` are required. No new cron registration is needed. Checks: `php tests/renewal_warnings_test.php` (full SQL checks require an isolated `WARNING_TEST_SOCKET`) and `php tests/renewal_warning_text_test.php` (actual editor save/runtime-load round trip in a temporary tree). Local fixtures do not certify live Telegram delivery or provider availability.

### Purchase-based audiences (0.8.0)

From **مدیریت کاربران → بخش ارسال پیام**, choose send/forward and then:

- **بدون خرید با موجودی صفر**: current wallet balance is exactly zero and there is no successful service purchase or paid user renewal in the retained history. Negative balances are excluded; previous wallet deposits alone do not count as purchases.
- **فقط یک خرید قدیمی**: exactly one successful service purchase or paid user renewal in total, older than the entered number of days (1–3650). For example, `30` means strictly more than 30 × 24 hours. A purchase followed by a successful renewal is two purchases and is excluded. This filter uses purchase time, not last bot activity.

Choose the user group, enter the day threshold if requested, then configure the message and review the recipient count before confirming. Both audiences are also available under **شارژ گروهی** with the existing amount/notification/confirmation flow. Broadcasts target active users; bulk credit retains its existing user-status policy. Broadcast recipients are selected again on confirmation; credit batches retain their prepared recipient snapshot.

Trials (in all four supported languages), unpaid/waiting/failed orders, wallet top-ups, quota add-ons and administrator renewals are not counted as purchases. Successful non-trial service orders count even when a discount makes their price zero. Disabled/expired services still count. Selection uses retained `invoice` and `service_other` records; permanently deleted history cannot be reconstructed. No new cron or database migration is required.

Version `0.8.0` is a backward-compatible feature release after `0.7.1`, following the pre-1.0 minor-for-features policy. The authoritative version is `version`; development is on `dev`, and approved production releases are promoted to `master` and tagged `v<version>`. This release follows the maintainer's standing instruction to publish both branches.

Checks: `php tests/broadcast_flow_test.php`, `php tests/bulk_operations_test.php` and `php tests/purchase_audience_test.php`. The last test runs real SQL only when `AUDIENCE_TEST_SOCKET` points to a disposable MySQL server under `/tmp/mirza-audience-test.*`; otherwise it runs input validation and reports the skipped SQL checks.

### تمدید از نامحدود به حجمی (0.9.2-dev.1، آزمایشی)

در روش‌های «ریست زمان و اضافه کردن حجم قبلی» و «اضافه شدن زمان و حجم به ماه بعد»، هنگام تبدیل سقف نامحدود (صفر) به حجم محدود، مصرف قبلی ریست می‌شود و حجم خرید جدید کامل قابل استفاده است. برای نمونه، مصرف ۲۵۰ گیگ در طرح نامحدود، از طرح جدید ۱۰۰ گیگ کسر نمی‌شود. سیاست زمان روش انتخابی و انتقال مانده طرح‌های حجمی حفظ می‌شود؛ خطای ریست مانع اعمال سقف جدید است. این اصلاح برای تمدیدهای بعدی است و سرویس‌های قبلاً تغییرکرده را خودکار ترمیم نمی‌کند.

نسخه در `version` نگهداری می‌شود؛ این تغییر در نسخه پروداکشن 0.9.2 روی `master` منتشر شده است. بررسی محلی: `php tests/unlimited_renewal_test.php`.

### اصلاح ارسال و گزارش یادآوری تمدید (0.9.2-dev.2، آزمایشی)

هشدار زمان و حجم فقط پس از پاسخ موفق تلگرام در ربات علامت می‌خورد. شکست یکی از بخش‌های پیام به عنوان موفقیت پنهان نمی‌شود؛ ارسال ناقص در پیشنهادها و یادآوری‌های جدید، مبهم محسوب می‌شود تا کل پیام دوباره ارسال نشود. وضعیت خطا یا انتظار پیشنهاد تخفیف از مسیر هشدار قدیمی دور زده نمی‌شود. خطای پردازش یک سرویس مانع رسیدگی به بقیه سرویس‌های همان نوبت نیست. نمایش حجم منفی یا غیرعددی دیگر NAN تولید نمی‌کند؛ این تغییر نمایشی، حجم واقعی پنل را تغییر نمی‌دهد.

پس از تأیید تلگرام برای پیشنهاد زمان/حجم یا یادآوری پس از اتمام، گزارش شامل سفارش، سرویس، کاربر، مقصد، شناسه پیام و زمان، به **کانال گزارش تنظیم‌شده ← موضوع گزارش کرون** ارسال می‌شود. شکست گزارش، پیام کاربر را تکرار نمی‌کند و در لاگ ثبت می‌شود. گزارش خرید و تمدید موفق سرویس همچنان در موضوع سرویس‌های دیگر ارسال می‌شود. متن گزارش جدید در `lang/{fa,en,ru,zh}.php`، گروه `renewalOffers` قرار دارد و از **داشبورد وب ← متن‌های ربات ← پیشنهاد تمدید** قابل ویرایش است؛ overrideهای موجود حفظ می‌شوند.

ارتقا به اجرای bootstrap معمول دیتابیس پروژه (`table.php` از مسیر عادی آپدیت) نیاز دارد؛ چهار ستون nullable به هر یک از `renewal_warning_state` و `renewal_reminder_state` اضافه می‌شود: `delivery_at` زمان آخرین تلاش، `delivery_chat_id` و `delivery_message_id` از پاسخ تلگرام و `delivery_error_code` کد خطا. پاسخ موفق به معنی خوانده‌شدن پیام نیست. برای داده‌های قبلی، ستون‌ها NULL می‌مانند؛ `created_at` زمان ارسال نیست و هیچ رکورد یا پرچم تاریخی خودکار بازنشانی نمی‌شود.

تنظیمات فعلی یادآوری پس از اتمام (فاصله، حداکثر ارسال، شمول سرویس‌های قبلی) حفظ می‌شود. مانیتور همچنان در هر دقیقه تا ۳۰ سفارش واجدشرایط بررسی می‌کند؛ با ۴۰۶۵ سفارش، یک دور کامل حداقل حدود ۱۳۶ دقیقه زمان می‌برد. تمدید سرویس، وضعیت فعلی را تغییر می‌دهد و از آن به‌تنهایی نمی‌توان شرایط پیش از تمدید را بازسازی کرد.

اعتبارسنجی: `php tests/renewal_delivery_test.php` و تست‌های SQL ایزوله پیشنهادها و یادآوری‌ها، به‌علاوه تست‌های تمدید و dispatcher. نسخهٔ پروداکشن 0.9.2 شامل این اصلاحات است؛ انتشار در گیت، تأیید نصب یا عملکرد سرور واقعی نیست.

### بررسی خطاهای موردی ترونادو (0.9.3)

این قابلیت در نسخهٔ پروداکشن 0.9.3 روی `master` منتشر شده است. شرایط پذیرش پرداخت، امضای کال‌بک، ساخت سفارش و تحویل سرویس تغییر نمی‌کند. خطای `409` با شناسهٔ داخلی پرداخت و دلیل مشخص در `payment/error_log` ثبت می‌شود. خط `Tronado callback mismatch` مبلغ فاکتور، حداقل مبلغ مجاز، مبالغ تومانی دریافتی، مقدار ترون و نتیجهٔ تطبیق کیف پول را نشان می‌دهد؛ کلید API، امضا، توکن، آدرس کیف پول و بدنهٔ خام در این گزارش ثبت نمی‌شوند.

در سرور، پس از قرار گرفتن فایل‌های این نسخه، ابزار زیر فقط وضعیت را از API رسمی می‌خواند و با فاکتور و سابقهٔ کال‌بک دیتابیس مقایسه می‌کند:

```bash
cd /var/www/html/mirzaprobotconfig
# شماره سفارش ترونادو (مانند شمارهٔ پیام خطا)
php tools/tronado_diagnose.php --provider-order 3181762
# یا شناسهٔ داخلی پرداخت ربات؛ این مقدار را با شناسهٔ واقعی جایگزین کنید
php tools/tronado_diagnose.php --payment-id YOUR_INTERNAL_PAYMENT_ID
```

ابزار از کلید تنظیم‌شدهٔ فعلی ترونادو استفاده می‌کند و از وب قابل اجرا نیست. سفارش اول را با `POST /Order/GetStatus` و شناسهٔ داخلی را با `POST /Order/GetStatusByPaymentID` استعلام می‌کند. خروجی شامل `payment_id`، وضعیت ترونادو، وضعیت پرداخت/تحویل ربات، تطبیق کیف پول و سابقهٔ کال‌بک است. هیچ موجودی، سفارش یا سرویسی تغییر نمی‌کند و هیچ پرداختی خودکار تکرار نمی‌شود. اجرای دوباره برای مقایسه با یک سفارش موفق، فقط دو استعلام خواندنی است.

قرارداد رسمی پاسخ استعلام، فیلدهای `UserPaidTomanAmount` و `TomanAmountWithoutWage` را تضمین نمی‌کند؛ در نبود آن‌ها، خروجی `status_response_is_not_a_complete_callback` است. از این پاسخ نمی‌توان مقدار نامعلوم را حدس زد یا تأیید خودکار شارژ ساخت. رد شدن دقیقِ پرداخت‌های قبلی که لاگ جزئی ندارند ممکن است فقط با پشتیبانی ترونادو یا دریافت مجدد کال‌بک امضاشده روشن شود. `provider_is_paid` به‌تنهایی اثبات شارژشدن موجودی ربات نیست؛ `local_fulfillment_status` و سابقهٔ شارژ دستی هم باید بررسی شوند. بازیابی خودکار پرداخت‌ها در این نسخه فعال نمی‌شود.

دلیل‌های گزارش: `paid_amount_below_minimum` مبلغ کمتر از حد فعلی فاکتور منهای ۵۰۰۰ تومان؛ `invalid_paid_amount` یا `invalid_received_amount` مبلغ مفقود/نامعتبر؛ `wallet_mismatch` کیف پول متفاوت؛ `invalid_order_metadata` اطلاعات سفارش نامعتبر؛ خطاهای `legacy_*` مربوط به قرارداد قدیمی سفارش‌اند. برای دیدن خطاهای جدید:

```bash
sudo grep -E 'Tronado callback (mismatch|result)' /var/www/html/mirzaprobotconfig/payment/error_log | tail -n 50
```

مستندات مرجع: https://miniapp.tronado.cloud/assets/api-docs.md . بررسی محلی: `php tests/tronado_gateway_test.php`؛ تست HTTP کال‌بک و SQL با `TRONADO_TEST_SOCKET` روی MySQL موقت ایزوله اجرا می‌شود. این اصلاح عیب‌یابی سازگار است و به مهاجرت دیتابیس نیاز ندارد.

### گزارش رخدادهای مهم (0.9.3)

مسیرهای پرداخت، تمدید و کرون بررسی شدند. گزارش‌های خرید/تمدید موفق موجود حفظ شده‌اند و موارد جاافتاده به کانال گزارش تنظیم‌شده وصل شده‌اند. **تنظیمات ادمین ← کانال گزارش‌ها** و موضوع‌های مالی و خطا همان تنظیمات قبلی‌اند؛ برای این قابلیت کلید جدیدی لازم نیست. مسیر متن‌ها: **داشبورد وب ← متن‌های ربات ← گزارش رخدادهای مهم** (`reportEvents` در چهار فایل `/lang`؛ overrideهای قبلی حفظ می‌شوند). جزئیات بررسی و پوشش در `REPORTING_REVIEW_FA.md` آمده است.

گزارش‌های خطای جدید و پرداخت موفق TonPays در `report_event_outbox` ثبت و با کار `report_events` در dispatcher فعلی هر دقیقه ارسال می‌شوند. قبل از اجرای نسخه جدید، bootstrap عادی `table.php` باید جدول را ایجاد کند؛ ثبت دستی کرون جدا لازم نیست. ساخت گزارش در مسیر پرداخت، درخواست تلگرام نمی‌فرستد. گزارش رد کال‌بک و لغو ترونادو بعد از پاسخ HTTP ثبت می‌شود؛ خطای پیام هدیه مانع گزارش مالی آن نمی‌شود.

پرداخت موفق TonPays و بازگشت مبلغ به کیف پول به موضوع **مالی** می‌روند؛ عدم تطبیق، بررسی دستی، خطای ساخت/تحویل پرداخت و خطای کرون/سرویس به موضوع **خطاها** می‌روند. شناسهٔ سفارش/کاربر/سرویس و دلیل مرتبط در متن هست؛ کلید، امضا، توکن و بدنهٔ خام کال‌بک وارد این گزارش‌ها نمی‌شوند. گزارش‌های کرون و یادآوری موفق قبلی در موضوع گزارش کرون باقی‌اند. واریزا در صورت نداشتن موضوع، گزارش را به خود کانال می‌فرستد.

صف جدید فقط پس از پاسخ موفق تلگرام، گزارش را ارسال‌شده می‌زند. خطای ارسال با فاصلهٔ افزایشی و رعایت `retry_after` دوباره امتحان می‌شود؛ موضوع حذف‌شده/بسته به ارسال در خود کانال برمی‌گردد. یک رخداد مالی تکراری دوباره صف نمی‌شود؛ خطاهای عملیاتی هر سرویس/کرون ساعتی و اختلال پیام به صورت یک نمونه برای هر کد خطا در ساعت جمع می‌شوند. کال‌بک بدون امضای معتبر و بلاک معمول کاربر گزارش کانال تولید نمی‌کنند.

این صف، ارسال مجدد **گزارش** را انجام می‌دهد؛ هیچ پرداخت یا شارژ مالی را دوباره اجرا نمی‌کند. خطای مبهم شبکه ممکن است باعث تکرار یک پیام گزارش شود. اگر کانال تنظیم نشده باشد گزارش غیرفعال است؛ خطای دسترسی تلگرام، توقف کرون یا نبود دیتابیس را نمی‌توان تضمین‌شده دور زد. شکست ثبت صف در لاگ ثبت می‌شود. همهٔ گزارش‌های قدیمی به صف منتقل نشده‌اند؛ مسیرهای قدیمی مستقیم همچنان برقرارند. سوابق قبلی پرداخت یا اعلان، خودکار بازپخش نمی‌شوند.

بررسی محلی: `tests/report_events_test.php` با `REPORT_TEST_SOCKET` روی MySQL موقت؛ تست‌های HTTP کال‌بک ترونادو، callback در TonPays، پیشنهاد/یادآوری تمدید، پرداخت مشترک، Stars و ثبت/اجرای کرون. این قابلیت‌ها در نسخهٔ پروداکشن 0.9.3 منتشر شده‌اند؛ تست محلی و انتشار سورس، تأیید نصب یا عملکرد VPS و تلگرام واقعی نیست.
