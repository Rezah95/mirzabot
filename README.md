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
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2"/>
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
| 🖥️ **OS** | A **clean** Ubuntu **22.04** or **24.04** server |
| 🌐 **Domain** | A domain name pointed to your server's IP |
| ⚙️ **Stack** | PHP 8.2, Apache, MySQL, SSL — *installed automatically by the script* |

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

The installer and the administrator's cron repair now select a PHP CLI >= 8.2 that actually provides `mysqli_connect` and `pdo_mysql`. The generated crontab uses the verified versioned executable. VPS updates register the dispatcher as `www-data` using the compatible PHP selected for database migration. Registration replaces this bot's old commands atomically, preserves unrelated entries and reports failure instead of claiming success. A busy bulk-message worker returns to the dispatcher so later jobs can run.

For an existing VPS, inspect the exact binary in `sudo crontab -u www-data -l`, then check its modules as the same user, for example:

```bash
sudo -u www-data /usr/bin/php -r 'echo PHP_VERSION, PHP_EOL; var_dump(function_exists("mysqli_connect"), extension_loaded("pdo_mysql"));'
```

Install/enable the MySQL package matching that PHP version if either check is false. Once available, the existing minute schedule resumes automatically. After upgrading to 0.5.10, the admin **تنظیم مجدد کرون** button can replace a stale PHP path; it does not run payment or service jobs immediately. CLI registration is also available through `sudo -u www-data /path/to/verified/php /path/to/bot/cronbot/register.php`.

Cron regression checks: `php tests/cron_dispatcher_test.php` and `php tests/cron_registration_test.php`. These use isolated fixtures and never change system crontabs or production data.
