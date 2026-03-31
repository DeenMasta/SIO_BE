# Serial Number Tracking Analysis & Improvement Proposals

## How the System Currently Tracks a Serial Number

The core of serial number tracking lives in the **`stock_items`** table. Every serial number is one row there. Its **`current_status`** column is the single source of truth for "where is this unit right now".

### `stock_items` — the "unit passport"

| Column | Purpose |
|---|---|
| `serial_number` | System SN (unique, always present) |
| `factory_serial_number` | Manufacturer's SN (nullable, unique) |
| `serial_source` | `FACTORY` or `GENERATED` — who assigned the SN |
| `current_status` | Current location/state (see lifecycle below) |
| `received_condition` | Physical condition when it arrived |
| `is_available` | Boolean flag — can it be dispatched? |
| `last_movement_at` | Timestamp of the last state change |
| `stock_in_line_id` | FK → which goods-receipt line brought it in |

---

## The Full Serial Number Lifecycle

The `StockItemStatus` enum defines every possible state:

```
RECEIVED → IN_STOCK → DELIVERED → UNDER_REPAIR → IN_STOCK (repaired)
                    ↘ RETURNED_TO_SUPPLIER
                    ↘ RETURNED (customer return → back to IN_STOCK or UNDER_REPAIR)
```

| Status | Meaning | Where the unit physically is |
|---|---|---|
| `RECEIVED` | Arrived from supplier, not yet QC'd/put away | Receiving dock |
| `IN_STOCK` | Available in warehouse | Warehouse shelf |
| `DELIVERED` | Shipped to customer via Stock Out | **With the customer** |
| `UNDER_REPAIR` | Sent to repair workshop | Repair workshop |
| `RETURNED` | Returned by customer | Back in-house (pending re-evaluation) |
| `RETURNED_TO_SUPPLIER` | Sent back to supplier | **With the supplier** |

### How the system links a serial number to its actors

| Where to look | What it tells you |
|---|---|
| `stock_out_line_items` → `stock_out_lines` → `stock_outs` | Which customer received this SN (DELIVERED state) |
| `customer_return_lines` → `customer_returns` | Which customer returned it and why |
| `return_to_supplier_lines` → `return_to_supplier` | Which supplier it was returned to |
| `repairs` | Repair job tied to this SN (+ optional customer link) |
| `stock_movements` | Full audit trail of every state transition |

### How `stock_movements` records history

Every state change writes a row:
- `movement_type` (e.g. `STOCK_IN`, `STOCK_OUT`, `CUSTOMER_RETURN`, `REPAIR_IN`)
- `from_status` / `to_status`
- `reference_table` + `reference_id` — polymorphic FK to the triggering document
- `performed_by` — the user who performed the action

---

## Current Gaps & Weaknesses

### 🔴 Critical Issues

1. **`RETURNED` is a dead-end status.** When a unit comes back from a customer, `current_status = RETURNED`. But there is no enforced workflow to decide: does it go back to `IN_STOCK`? Or to `UNDER_REPAIR`? It just sits as `RETURNED` with no next-action tracking at the `stock_item` level.

2. **`customer_return_lines.stock_item_id` is nullable.** This means a customer return line can exist *without* pointing to a specific serial number. If someone returns a serialized unit but the line has `stock_item_id = NULL`, the SN's `current_status` will never be updated — it stays `DELIVERED` forever, creating a ghost unit.

3. **`return_to_supplier_lines.stock_item_id` is nullable.** Same problem for supplier returns. A serialized unit can be returned to supplier with no SN linkage.

4. **`is_available` is not being auto-managed.** There is no DB constraint or enforced business rule ensuring `is_available = false` when `current_status` is `DELIVERED`, `UNDER_REPAIR`, or `RETURNED_TO_SUPPLIER`. It relies on application-layer discipline.

5. **No "location" concept.** `IN_STOCK` tells you it's in the warehouse, but which warehouse? Which bin? For operations at any scale this is a gap.

### 🟡 Operational Gaps

6. **`RECEIVED` is overloaded.** Right now `RECEIVED` means "arrived but not QC'd or shelved". With QC tables removed, there's no formal promotion path from `RECEIVED → IN_STOCK`. Who triggers it and when is unclear.

7. **No `WRITTEN_OFF` / `SCRAPPED` status.** If a unit is beyond repair or lost, there's no terminal status to permanently remove it from circulation. It would forever appear as `UNDER_REPAIR` or `RETURNED`.

8. **`serial_source = GENERATED` has no audit.** If the system generates a SN (instead of using the factory SN), there's no record of *who* generated it or *when*. A rogue or accidental generation would be untraceable.

9. **`repairs` table has no `resolved_stock_item_status`.** When a repair is closed, what does the unit become? There's no field to capture the post-repair outcome (e.g., repaired → `IN_STOCK`; unrepairable → `SCRAPPED`).

10. **No "in-transit" status.** When a Stock Out is created but goods haven't physically left, the SN jumps to `DELIVERED`. If delivery fails or is delayed, the status is wrong.

---

## Improvement Proposals

### 1. Fix the `RETURNED` dead-end — add `next_action` enforcement

**Problem:** `RETURNED` has no path forward.
**Fix:** After a customer return is accepted, immediately trigger a "Return Assessment" workflow. Add a required `post_return_action` to `customer_return_lines`:

```php
// customer_return_lines
$table->string('post_return_action', 30)->nullable(); 
// Values: 'RESTOCK', 'SEND_REPAIR', 'SCRAP', 'RETURN_TO_SUPPLIER'
```

And enforce in the use-case that when a return is posted, `post_return_action` must be set, and the system must immediately transition `stock_item.current_status` accordingly.

### 2. Make `stock_item_id` non-nullable for serialized product returns

The system already knows if a product is serialized (`serial_source` exists). Add a validation layer:

```php
// In CustomerReturn / ReturnToSupplier Use Case:
if ($product->is_serialized && $line->stock_item_id === null) {
    throw new \DomainException("Serial number is required for serialized product returns.");
}
```

### 3. Add `SCRAPPED` and `IN_TRANSIT` statuses

```php
enum StockItemStatus: string
{
    case Received = 'RECEIVED';
    case InStock = 'IN_STOCK';
    case InTransit = 'IN_TRANSIT';   // NEW: stock out created, not yet shipped
    case Delivered = 'DELIVERED';
    case UnderRepair = 'UNDER_REPAIR';
    case Returned = 'RETURNED';
    case ReturnedToSupplier = 'RETURNED_TO_SUPPLIER';
    case Scrapped = 'SCRAPPED';      // NEW: terminal state
}
```

### 4. Add a `serial_number_timeline` view (or dedicated endpoint)

Create a dedicated API endpoint that, given a serial number, returns its **complete lifecycle** in chronological order — far more useful than the current `serials` search which only shows basic fields:

```json
{
  "serial_number": "SIO-2026-001",
  "product": { "name": "Router X500" },
  "current_status": "DELIVERED",
  "timeline": [
    { "date": "2026-03-01", "event": "STOCK_IN",         "actor": "Ali",   "ref": "GRN-001" },
    { "date": "2026-03-05", "event": "STOCK_OUT",        "actor": "Siti",  "ref": "DO-042",  "customer": "Acme Corp" },
    { "date": "2026-03-20", "event": "CUSTOMER_RETURN",  "actor": "Siti",  "ref": "RTN-011", "reason": "Defective" },
    { "date": "2026-03-21", "event": "REPAIR_IN",        "actor": "Zul",   "ref": "RPR-007" }
  ]
}
```

This is built directly from `stock_movements` (all the data is already there).

### 5. Add a `location_code` field to `stock_items`

Simple but high-impact for warehouse operations:

```php
// New migration
$table->string('location_code', 30)->nullable();
// e.g., 'WH-A-01-03' (Warehouse A, Aisle 01, Bin 03)
```

When a unit moves to `IN_STOCK`, the operator sets (or scans) the bin location. When picked for delivery, it's cleared.

### 6. Add `WrittenOff` movement type + Post-Repair outcome

```php
// MovementType enum
case WrittenOff = 'WRITTEN_OFF';

// repairs table — new migration
$table->string('outcome', 30)->nullable(); // 'REPAIRED', 'SCRAPPED', 'PENDING'
$table->date('completed_date')->nullable();
```

### 7. Expose a rich Serial Number detail page on the frontend

Currently `SearchController::serials()` only returns `id, product_id, serial_number, factory_serial_number, current_status, is_available`. A new endpoint should return the full picture described in Proposal 4, enabling a "Serial Number Passport" page that operations staff can use instantly to answer: *"Where is this unit, who has it, and what's its history?"*

---

## Summary Priority Table

| # | Change | Impact | Effort |
|---|---|---|---|
| 2 | Non-nullable SN on serialized returns | 🔴 Data Integrity | Low |
| 1 | `post_return_action` enforcement | 🔴 Ops Workflow | Medium |
| 4 | SN Timeline API endpoint | 🟠 Visibility | Medium |
| 3 | `SCRAPPED` + `IN_TRANSIT` statuses | 🟠 Accuracy | Low |
| 7 | Serial Passport UI page | 🟠 Operations UX | Medium |
| 6 | Post-repair outcome field | 🟡 Completeness | Low |
| 5 | `location_code` field | 🟡 Future-proofing | Low |
