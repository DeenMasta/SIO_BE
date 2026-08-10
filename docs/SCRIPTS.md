# Backend Script Runbook

Run these commands from the backend folder (`sio_backend`). All examples use PowerShell.

Before any command that changes data, create a database backup and first run the command in staging where possible. Replace placeholder values such as `<po-id>` and `<product-id>` with real IDs.

## Find commands and command help

```powershell
php artisan list
php artisan <command-name> --help
```

`php artisan list` also includes Laravel's built-in maintenance and development commands. This document lists the scripts created specifically for this SIO project.

## Purchase orders and stock in

### Recalculate PO received quantities

Rebuilds each PO line's `received_qty` from linked stock-in lines with `POSTED` or `RECEIVED` status. It also corrects the PO status to `ISSUED`, `PARTIAL`, or `COMPLETED`. Draft and cancelled POs retain their existing status.

Preview an affected PO without saving changes:

```powershell
php artisan purchase-orders:recalculate-receipts --purchase-order-id=<po-id> --dry-run
```

Repair an affected PO:

```powershell
php artisan purchase-orders:recalculate-receipts --purchase-order-id=<po-id>
```

Repair every PO (use only after a backup):

```powershell
php artisan purchase-orders:recalculate-receipts
```

### Correct a PO product and its linked records

Updates a PO line's product and cascades the change to linked stock-in, stock-item, stock-movement, and return-to-supplier records.

```powershell
php artisan sio:correct-po-product <po-number> <old-product-id> <new-product-id>
```

Example:

```powershell
php artisan sio:correct-po-product PO-000123 15 28
```

### Delete obsolete non-serialized stock-in lines

Deletes explicitly selected non-serialized stock-in lines, their inbound movements, and recalculates affected stock balances and PO receipt totals. It cannot delete serialized lines, lines with stock items, or lines linked to return-to-supplier records.

```powershell
php artisan inventory:delete-obsolete-nonserialized-stock-in-lines <stock-in-id> --stock-in-line-id=<line-id>
```

To delete more than one line, repeat the option:

```powershell
php artisan inventory:delete-obsolete-nonserialized-stock-in-lines <stock-in-id> --stock-in-line-id=<line-id-1> --stock-in-line-id=<line-id-2>
```

Add `--yes` only when you intentionally want to skip the confirmation prompt.

### Rebuild a corrected inbound chain

Creates a replacement PO, stock-in, and QC document for an inbound line that was linked to the wrong product, then moves the inbound references to the replacement chain.

```powershell
php artisan inventory:rebuild-corrected-inbound <purchase-order-line-id> <stock-in-line-id> <supplier-id>
```

It supports optional dates and document numbers. See the full option list before using it:

```powershell
php artisan inventory:rebuild-corrected-inbound --help
```

### Correct a wrong product across a transaction chain

Corrects a product across PO, stock in, QC, sale order, stock out, and stock movements.

```powershell
php artisan inventory:correct-product-chain <purchase-order-line-id> <stock-in-line-id> <wrong-product-id> <correct-product-id>
```

Optional sale-order/stock-out IDs and corrected dates are available; run `--help` before using it. Add `--yes` only to skip its confirmation.

## Inventory

### Correct available quantity for a non-serialized product

Posts an adjustment movement to set a product's available quantity to the requested value, then rebuilds its stock balance.

```powershell
php artisan inventory:correct-qty-available <product-id> <target-quantity> --performed-by=<user-id>
```

Example:

```powershell
php artisan inventory:correct-qty-available 42 25 --performed-by=1
```

The command shows the current and target quantities and asks for confirmation. It only supports non-serialized products. Use `--yes` only to skip the prompt.

## Sales and customers

### Merge duplicate customers

Finds customers with the same trimmed, case-insensitive name, moves their linked records to the oldest customer record, and deletes the duplicates.

```powershell
php artisan customers:merge-duplicates
```

### Merge sale orders

Moves sale-order lines, stock-outs, invoice matches, and quick-stock-out links from source sale orders into a target sale order, then deletes the source orders.

```powershell
php artisan sio:merge-sale-orders <target-so-number> <source-so-number-1> [<source-so-number-2> ...]
```

Example:

```powershell
php artisan sio:merge-sale-orders SO-000100 SO-000101 SO-000102
```

## Customer-return data repair

### Fix legacy repair return actions

Changes legacy `REPAIR` customer-return actions to `REPLACE` and updates the related stock-item and stock-movement statuses.

```powershell
php artisan app:fix-legacy-repair-returns
```

## Telegram invoice integration

### Generate a webhook secret

Prints a new random secret. Copy it into the `TELEGRAM_WEBHOOK_SECRET` environment setting; it does not save the value automatically.

```powershell
php artisan telegram-invoices:generate-webhook-secret
```

### Register the Telegram webhook

Registers the configured webhook with Telegram. Requires `TELEGRAM_BOT_TOKEN`, `TELEGRAM_INVOICE_WEBHOOK_URL`, and `TELEGRAM_WEBHOOK_SECRET` to be set.

```powershell
php artisan telegram-invoices:register-webhook
```

### Prune stored Telegram payloads

Removes raw payload data older than the configured retention period. This runs daily through the scheduler, and can also be run manually:

```powershell
php artisan telegram-invoices:prune-raw-payloads
```

## Scheduled jobs

The application schedules the Telegram payload pruning job daily and processes queued jobs every minute. On a server, configure one scheduler process (for example, `php artisan schedule:work`) or a cron entry that runs `php artisan schedule:run` every minute.

```powershell
php artisan schedule:list
php artisan schedule:work
```

## Miscellaneous

### Display an inspiring quote

```powershell
php artisan inspire
```

## Project scripts

### Setup a new local backend

Installs PHP and JavaScript dependencies, creates `.env` if needed, generates an app key, runs migrations, and builds frontend assets.

```powershell
composer run setup
```

### Start local development services

Starts the Laravel server, queue listener, log viewer, and Vite development server together.

```powershell
composer run dev
```

### Run the test suite

```powershell
composer run test
```

### Build or watch frontend assets

```powershell
npm run build
npm run dev
```

### Composer lifecycle scripts

These are defined in `composer.json` and Composer normally runs them automatically. They are listed here for completeness; do not run them manually unless you specifically need their side effect.

| Script | Trigger / effect |
| --- | --- |
| `post-autoload-dump` | Runs package discovery after Composer regenerates autoload files. |
| `post-update-cmd` | Publishes Laravel assets after `composer update`. |
| `post-root-package-install` | Copies `.env.example` to `.env` only when `.env` does not exist. |
| `post-create-project-cmd` | Generates the app key, creates the SQLite file if needed, and runs graceful migrations after project creation. |
| `pre-package-uninstall` | Composer lifecycle hook before package removal. |
