# API Response Contract

All API endpoints must return one of these shapes.

## Success

```json
{
    "status": "success",
    "message": "Human-readable message",
    "data": {},
    "meta": {}
}
```

## Error

```json
{
    "status": "error",
    "message": "Human-readable message",
    "errors": {},
    "meta": {}
}
```

## Notes

- HTTP status code must match semantic outcome.
- `errors` is used for validation and field-level failure details.
- Do not expose stack traces or sensitive internals.

## Report Export Formats

The following endpoints support `format=csv|xlsx|pdf`:

- `GET /api/reports/stock-movements/export`
- `GET /api/audit-logs/export`

Expected response headers:

- `Content-Type`: `text/csv`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, or `application/pdf`
- `Content-Disposition`: attachment filename with matching extension

Example request:

```http
GET /api/reports/stock-movements/export?date_from=2026-03-01&date_to=2026-03-31&format=xlsx
Authorization: Bearer <token>
```

## Search Endpoints Contract

All search endpoints are paginated and require:

- `query` (required)
- `per_page` (optional, default 15, max 200)

Endpoints:

- `GET /api/search/products` (product code/name)
- `GET /api/search/serials` (serial/factory serial)
- `GET /api/search/invoices` (invoice number)
- `GET /api/search/purchase-orders` (PO number)
- `GET /api/search/delivery-orders` (DO number)

Example request:

```http
GET /api/search/products?query=PRD-100&per_page=10
Authorization: Bearer <token>
```

Example response:

```json
{
    "status": "success",
    "message": "Product search completed successfully.",
    "data": [
        {
            "id": 1,
            "product_code": "PRD-100",
            "product_name": "Router AC",
            "product_type": "DEVICE",
            "unit_of_measure": "PCS"
        }
    ],
    "meta": {
        "pagination": {
            "current_page": 1,
            "per_page": 10,
            "total": 1,
            "last_page": 1
        }
    }
}
```

## Exception Workflow Contract

### Customer Return Create

Endpoint: `POST /api/customer-returns`

`lines.*.next_action` is required and must be one of:

- `RESTOCK`
- `REPAIR`
- `REPLACE`
- `SCRAP`

`lines.*.reason_for_return` must be one of:

- `PHYSICAL_DAMAGE`
- `FUNCTIONAL_ISSUE`
- `WRONG_ITEM_DELIVERED`
- `INCOMPLETE_ACCESSORIES`
- `COSMETIC_DEFECT`
- `WARRANTY_CLAIM`

### Return To Supplier Create

Endpoint: `POST /api/return-to-suppliers`

`lines.*.reason_for_return` uses the same approved reason set above.

### Cancellation Endpoints

- `PATCH /api/customer-returns/{id}/cancel`
- `PATCH /api/return-to-suppliers/{id}/cancel`

Request body:

```json
{
    "remarks": "Optional cancellation note"
}
```

Success response includes updated transaction with `status = CANCELLED`.

## Exception Aging Metrics Contract

Supported endpoints:

- `GET /api/reports/repairs/summary`
- `GET /api/reports/rts/summary`
- `GET /api/reports/customer-returns/summary`

Filters:

- `date_from`
- `date_to`
- `age_bucket` = `0_7|8_30|31_plus`
- `per_page`

Each row includes:

- `age_days`
- `age_bucket`

## Error Model Examples

Validation error (422):

```json
{
    "status": "error",
    "message": "The given data was invalid.",
    "errors": {
        "lines.0.next_action": ["The selected lines.0.next_action is invalid."]
    },
    "meta": {}
}
```

Business-rule error (422):

```json
{
    "status": "error",
    "message": "The given data was invalid.",
    "errors": {
        "status": ["Only POSTED customer return transactions can be cancelled."]
    },
    "meta": {}
}
```
