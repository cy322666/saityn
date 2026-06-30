# Saityn

Laravel 11 application with SQLite storage and integration scaffolding for a Telegram bot and amoCRM API.

## Stack

- PHP 8.2+
- Laravel 11
- SQLite for local development
- Laravel HTTP client for Telegram Bot API and amoCRM API
- Official amoCRM PHP SDK: `amocrm/amocrm-api-library`

## Local Setup

Install dependencies:

```bash
composer install
npm install
```

Create the environment file and application key:

```bash
cp .env.example .env
php artisan key:generate
```

Create the SQLite database and run migrations:

```bash
touch database/database.sqlite
php artisan migrate
```

Start the app:

```bash
php artisan serve
```

## Environment

The project uses SQLite by default:

```env
DB_CONNECTION=sqlite
```

Telegram settings:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=
```

amoCRM settings:

```env
AMOCRM_BASE_DOMAIN=example.amocrm.ru
AMOCRM_CLIENT_ID=
AMOCRM_CLIENT_SECRET=
AMOCRM_REDIRECT_URI="${APP_URL}/amocrm/oauth/callback"
AMOCRM_MAX_EXPORT_BATCH=100
AMOCRM_PIPELINE_ID=10666522
AMOCRM_STATUS_ID=84063902
AMOCRM_CLOSED_PIPELINE_ID=10666522
AMOCRM_CLOSED_STATUS_ID=143
```

## Telegram

Webhook endpoint:

```text
POST /api/telegram/webhook
```

Set the public webhook URL after `TELEGRAM_BOT_TOKEN` is configured:

```bash
php artisan telegram:set-webhook https://your-domain.test/api/telegram/webhook
```

Delete the current webhook:

```bash
php artisan telegram:set-webhook --delete
```

Incoming updates are stored in `telegram_updates`; integration audit records are stored in `integration_events`.

The bot can export queued database records to amoCRM. Supported commands:

```text
/upload 10
/amo 10
/load 10
/выгрузить 10
```

The number in the command is the amount of pending records to take from the database.

If an existing contact or company already has a closed lost deal in `AMOCRM_CLOSED_PIPELINE_ID`
with `AMOCRM_CLOSED_STATUS_ID` (`143` by default), the exporter returns that deal to
`AMOCRM_PIPELINE_ID` / `AMOCRM_STATUS_ID` instead of creating a new deal. If no such deal is
found, a new deal is created.

## amoCRM

Start OAuth:

```text
GET /amocrm/oauth/redirect
```

OAuth callback:

```text
GET /amocrm/oauth/callback
```

Tokens are stored in `amo_crm_tokens`. `App\Services\AmoCrm\AmoCrmClient` wraps the official `AmoCRM\Client\AmoCRMApiClient` SDK and configures OAuth tokens from the database.

Records waiting for Telegram-triggered export live in `amo_export_records`. The exporter creates amoCRM leads through the official SDK method `leads()->addComplex(...)` and moves records through these statuses:

```text
pending -> processing -> exported
pending -> processing -> failed
```

## Tests

```bash
php artisan test
```

## Seller Import

Large seller spreadsheets should be imported through the streaming importer:

```bash
php artisan sellers:import storage/app/imports/sellers.xlsx --chunk=1000
```

Useful options:

```bash
php artisan sellers:import storage/app/imports/sellers.xlsx --truncate
php artisan sellers:import storage/app/imports/sellers.xlsx --sheet="Sheet1"
php artisan sellers:import storage/app/imports/sellers.xlsx --limit=1000
```

The importer maps the original spreadsheet headers from `App\Models\Seller::COLUMN_MAP` to the `sellers` table and writes rows in batches.
