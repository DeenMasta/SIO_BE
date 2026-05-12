# Telegram Invoice Integration Plan

## Objective

Ingest invoices shared in a company Telegram group into the Laravel backend so they become searchable, traceable, and linkable to sales operations.

This design treats Telegram as an external intake channel. Telegram should not be the system of record. The Laravel app remains the source of truth.

## Short Answer

Yes, this backend can support the integration.

Reasons:

- The system already stores and searches invoice numbers in sales and stock-out flows.
- The system already has queue support for asynchronous processing.
- The system already has notification and audit log patterns.
- The system already has filesystem support for private file storage and optional S3.

Relevant code:

- `routes/api.php`
- `app/Models/SaleOrder.php`
- `app/Models/StockOut.php`
- `app/Http/Controllers/Api/ReportingAudit/SearchController.php`
- `config/filesystems.php`
- `config/queue.php`
- `app/Application/Support/UserNotificationService.php`

## Telegram Constraints

Use a Telegram bot created through `@BotFather`.

Important limitations:

- The bot only receives new updates after it is added and configured.
- Telegram update retention is limited, so the bot is not a reliable historical archive.
- In groups, bots do not automatically see every message unless privacy mode is disabled or the bot is made an admin.
- If invoices are images or scanned PDFs, Laravel cannot "read" them directly without OCR.
- If invoices are digital PDFs, text extraction is usually possible and much more reliable than OCR.

Because of these constraints, do not link Telegram messages directly to `sale_orders` or `stock_out` at the moment they arrive. First land them in an intake table, then process and match them.

## Recommended Architecture

### 1. Telegram Intake Layer

Create a bot and add it to the invoice group.

Recommended settings:

- Disable bot privacy mode if users will post invoices as normal group messages.
- Prefer webhook delivery instead of polling.
- Restrict processing to approved group chat IDs.

Webhook flow:

1. Telegram sends message update to Laravel webhook.
2. Laravel validates webhook secret and chat ID.
3. Laravel stores raw update payload.
4. Laravel downloads attached files from Telegram.
5. Laravel dispatches parsing jobs to the queue.

### 2. New Intake Module

Add a new module instead of forcing raw Telegram data into sales tables.

Suggested tables:

#### `telegram_invoice_messages`

- `id`
- `telegram_update_id`
- `telegram_chat_id`
- `telegram_message_id`
- `telegram_user_id`
- `telegram_username`
- `message_text`
- `caption`
- `message_date`
- `raw_payload` JSON
- `received_at`
- unique index on `telegram_chat_id + telegram_message_id`

#### `invoice_inbox_items`

- `id`
- `source` enum: `telegram`
- `telegram_invoice_message_id`
- `file_disk`
- `file_path`
- `original_file_name`
- `mime_type`
- `file_size`
- `parse_status` enum: `pending`, `processing`, `parsed`, `needs_review`, `failed`
- `readability_status` enum: `text_pdf`, `image_pdf`, `photo`, `unknown`
- `ocr_text` long text nullable
- `extracted_json` JSON nullable
- `invoice_number` nullable indexed
- `customer_name` nullable indexed
- `invoice_date` nullable indexed
- `matched_sale_order_id` nullable
- `matched_stock_out_id` nullable
- `confidence_score` decimal nullable
- `review_notes` nullable
- timestamps

This keeps intake separate from finalized business transactions.

### 3. Parsing Pipeline

Process files asynchronously with queued jobs.

Suggested job stages:

1. `StoreTelegramInvoiceAttachmentJob`
2. `ClassifyInvoiceFileJob`
3. `ExtractInvoiceTextJob`
4. `ParseInvoiceFieldsJob`
5. `MatchInvoiceToBusinessRecordJob`
6. `NotifyInvoiceReviewJob`

Parsing strategy:

- Digital PDF: extract text first.
- Scanned PDF or image: OCR.
- Message caption/text: parse invoice number hints from the post itself.

Extract these fields when possible:

- invoice number
- customer name
- invoice date
- line items
- total amount
- currency
- delivery reference
- purchase order or sales order reference

### 4. Matching Rules

Do not auto-attach aggressively. Use confidence-based matching.

Primary matching order:

1. Exact `invoice_number` to `sale_orders.invoice_number`
2. Exact `invoice_number` to `stock_out.invoice_number`
3. Combination of customer + date + amount
4. SO number or stock out number found in caption/text/PDF

Match result states:

- `matched`
- `multi_match`
- `unmatched`
- `needs_review`

### 5. Review UI / API

Add a small review workflow for operations staff.

Suggested endpoints:

- `GET /api/invoice-inbox`
- `GET /api/invoice-inbox/{id}`
- `PATCH /api/invoice-inbox/{id}/link-sale-order`
- `PATCH /api/invoice-inbox/{id}/link-stock-out`
- `PATCH /api/invoice-inbox/{id}/mark-reviewed`
- `POST /api/telegram/webhook`

Suggested filters:

- parse status
- match status
- invoice number
- customer
- date range
- source chat

### 6. Notification and Audit

Reuse the existing notification and audit patterns.

Notify staff when:

- a new invoice arrives
- parsing fails
- duplicate invoice number is detected
- manual review is required
- a match is completed

Audit events:

- webhook received
- file downloaded
- OCR completed
- fields parsed
- record matched or rematched
- manual override

## Fit With Current Backend

### Best entity to link to

Use `sale_orders` as the primary invoice ownership record.

Why:

- `sale_orders` already has `invoice_number`
- invoice assignment logically happens before or during fulfillment
- `stock_out` can still inherit or mirror the invoice number for outbound traceability

Use `stock_out` as a secondary linkage target when:

- the operation posts invoice numbers at dispatch time only
- historical data exists only in `stock_out`
ac
### Existing strengths in this repo

- Invoice fields already exist in both `sale_orders` and `stock_out`.
- Search endpoint for invoices already exists.
- Queue connection is already configured to use the database driver.
- Private storage is already available.
- Internal user notifications are already implemented.

### Missing pieces

- No Telegram service configuration yet
- No intake tables for external invoice files
- No OCR or PDF text extraction pipeline
- No inbox/review API for unmatched invoices
- No background jobs for ingestion/parsing

## Implementation Plan

### Phase 1: Safe ingestion

- Create Telegram bot with `@BotFather`
- Add bot to invoice group
- Disable privacy mode or grant admin if full group message intake is required
- Add webhook endpoint
- Store raw Telegram payload and downloaded files
- Restrict accepted chat IDs
- Add idempotency on Telegram message ID

Deliverable:

- Every new invoice post in Telegram is captured and stored safely

### Phase 2: Readability

- Detect file type
- Add PDF text extraction
- Add OCR for images and scanned PDFs
- Store extracted text and structured parse result
- Mark unreadable files for manual review

Deliverable:

- System can read most invoice files

### Phase 3: Business matching

- Match extracted invoice number to `sale_orders` first
- Fallback match to `stock_out`
- Add duplicate detection
- Add match confidence score
- Create review endpoints and admin screen

Deliverable:

- Invoices become searchable and link to operational records

### Phase 4: Operational hardening

- Add retry logic and failed-job monitoring
- Add staff notifications for review queue
- Add audit logging for every state transition
- Add role-based permissions for review and override actions
- Add dashboard counters for unread, failed, unmatched, matched

Deliverable:

- Production-ready intake workflow

## Technical Recommendations

### Recommended Laravel components

- Controller for Telegram webhook
- Service class for Telegram API calls
- Queued jobs for file download and parsing
- Dedicated policy for invoice inbox review
- Resource classes for inbox API responses

### Suggested config additions

Add to `config/services.php`:

- `telegram.bot_token`
- `telegram.webhook_secret`
- `telegram.allowed_chat_ids`
- `telegram.base_url`

Add env vars:

- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `TELEGRAM_ALLOWED_CHAT_IDS`

### File storage recommendation

Store original invoice files on private storage first.

Use S3 if:

- files must be retained long-term
- multiple app servers are used
- backup and lifecycle rules matter

## Risks

- Group privacy settings may prevent the bot from seeing needed messages.
- Users may upload blurry photos that OCR cannot parse reliably.
- Different invoice formats from different customers will reduce extraction accuracy.
- Duplicate reposts in Telegram are common and must be deduplicated.
- Auto-matching directly into sales records can create bad links if confidence is too low.

## Recommended Decision

Proceed with a staged Telegram bot integration, but do not write directly into `sale_orders` or `stock_out` from the webhook.

Build:

1. Telegram webhook intake
2. invoice inbox tables
3. OCR/text extraction pipeline
4. review-and-match workflow

This is the safest design and fits the current Laravel backend cleanly.
