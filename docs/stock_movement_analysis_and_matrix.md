# Comprehensive Stock Movement & Ledger Analysis

This document provides a perfect analysis of every possible stock movement transition in the SIO backend application. It explores the double-entry ledger system architecture, breaks down all inventory permutations, exposes critical business logic gaps, and maps out the ultimate matrix for every stock operation.

## 1. The Core Architecture: The Double-Entry Ledger System

The inventory engine does not use a singular `quantity` column. It behaves like a strict financial ledger using the `StockMovement` table.
- Every event inserts a row categorizing `qty_in`, `qty_out`, and the `MovementType`. 
- **Serialized Items (`ProductType::Device` / `Accessory`)**: Are represented physically by a `StockItem` row acting as a finite state machine (e.g., `is_available = true/false`, `current_status = InStock`). The ledger maps 1:1 to these.
- **Non-Serialized Items**: Are calculated **purely** by running a mathematical aggregate: `SUM(qty_in) - SUM(qty_out)` grouped by `product_id`.

---

## 2. Every Single Item Movement Scenario (Deep Dive)

### A. Stock In (`MovementType::StockIn`)
Triggered when receiving a Purchase Order.
- **Action**: Items physically enter the warehouse.
- **Ledger Impact**: `qty_in = X` | `qty_out = 0`
- **Serialized State**: Created as `Received` status. `is_available = true`.

### B. Quality Control (`MovementType::QcPass` / `MovementType::QcFail`)
Triggered to inspect `Received` stock before it can be sold.
- **Pass Action**: Moves item from holding to active inventory.
  - **Ledger Impact**: `qty_in = X` | `qty_out = 0`
  - **Serialized State**: Transitions from `Received` to `InStock` (`is_available = true`).
- **Fail Action**: Item is defective.
  - **Ledger Impact**: `qty_in = 0` | `qty_out = X`
  - **Serialized State**: Remains `Received`, but flips to `is_available = false`.

### C. Stock Out (`MovementType::StockOut`)
Delivering goods to a Customer.
- **Action**: Asset leaves the building. Requires validation of existing stock.
- **Ledger Impact**: `qty_in = 0` | `qty_out = X`.
- **Serialized State**: Transitions to `Delivered` (`is_available = false`).

### D. Customer Returns (`MovementType::CustomerReturn`)
Customer returns an `original_stock_out_id`. Next actions dictate where the item goes physically.
- **Action**: Asset geographically re-enters the building.
- **Ledger Impact**: ALWAYS creates `qty_in = X` | `qty_out = 0`.
- **Serialized State Mutations**:
  - *Restock*: -> `InStock` (`is_available = true`)
  - *Repair*: -> `UnderRepair` (`is_available = false`)
  - *Replace*: -> `Returned` (`is_available = false`)
  - *Scrap*: -> `ReturnedToSupplier` (`is_available = false`)

### E. Return to Supplier (RTS) (`MovementType::ReturnToSupplier`)
Rejecting bad stock from a supplier.
- **Action**: Asset geographically leaves the building back to origin.
- **Ledger Impact**: `qty_in = 0` | `qty_out = X`.
- **Serialized State**: Transitions FROM `Received` -> TO `ReturnedToSupplier` (`is_available = false`).

### F. Internal Repairs (`MovementType::RepairIn`, `RepairOut`, `RepairCancelled`)
Managing damaged goods internally.
- **Repair In (Create)**:
  - **Ledger Impact**: `qty_in = 0` | `qty_out = 1`
  - **Serialized State**: Transitions to `UnderRepair` (`is_available = false`).
- **Repair Out (Complete)**:
  - **Ledger Impact**: `qty_in = 1` | `qty_out = 0`.
  - **Serialized State**: Transitions to `InStock` (`is_available = true`).
- **Repair Cancelled**:
  - **Ledger Impact**: `qty_in = 0` | `qty_out = 0`
  - **Serialized State**: Transitions to `InStock` (`is_available = false`).

---

## 3. The Ultimate Stock Movement & Status Matrix

This comprehensive matrix details **every single possible operation** affecting stock and inventory within the SIO backend application. 
It exhaustively documents the relationship between the master operational transactions, the finite states of physical items (`StockItemStatus`), their immediate availability flags, and the exact ledger variables recorded in the `StockMovement` table.

### Primary Inbound & Outbound Operations (The Happy Flow)

| Operation / Action | Backing Use Case | Transaction Status Created/Updated | Initial `StockItem` State (Status / Available) | Final `StockItem` State (Status / Available) | `MovementType` Triggered | `qty_in` | `qty_out` |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Receive Purchase Order** | `PostStockInUseCase` | `StockInStatus::Received` | *(New Item)* | `RECEIVED` / `true` | `STOCK_IN` | X | 0 |
| **QC Assessment (Pass)** | `PostQcTransactionUseCase` | `QcTransactionStatus::Posted` | `RECEIVED` / `true` | `IN_STOCK` / `true` | `QC_PASS` | 1 | 0 |
| **QC Assessment (Fail)** | `PostQcTransactionUseCase` | `QcTransactionStatus::Posted` | `RECEIVED` / `true` | `RECEIVED` / `false` | `QC_FAIL` | 0 | 1 |
| **Deliver to Customer** | `PostStockOutUseCase` | `StockOutStatus::Posted` | `IN_STOCK` / `true` | `DELIVERED` / `false` | `STOCK_OUT` | 0 | X |

*(Note: X implies aggregate quantity behavior for non-serialized items. For serialized items, X is always strictly 1 per `StockItem` row).*

### Exceptions & Returns Management

| Operation / Action | Backing Use Case | Transaction Status Created/Updated | Initial `StockItem` State (Status / Available) | Final `StockItem` State (Status / Available) | `MovementType` Triggered | `qty_in` | `qty_out` |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Customer Return (Restock)** | `CreateCustomerReturnUseCase` | `ExceptionTransactionStatus::Posted` | `DELIVERED` / `false` | `IN_STOCK` / `true` | `CUSTOMER_RETURN` | X | 0 |
| **Customer Return (Repair)** | `CreateCustomerReturnUseCase` | `ExceptionTransactionStatus::Posted` | `DELIVERED` / `false` | `UNDER_REPAIR` / `false` | `CUSTOMER_RETURN` | X | 0 |
| **Customer Return (Replace)** | `CreateCustomerReturnUseCase` | `ExceptionTransactionStatus::Posted` | `DELIVERED` / `false` | `RETURNED` / `false` | `CUSTOMER_RETURN` | X | 0 |
| **Customer Return (Scrap)** | `CreateCustomerReturnUseCase` | `ExceptionTransactionStatus::Posted` | `DELIVERED` / `false` | `RETURNED_TO_SUPPLIER` / `false` | `CUSTOMER_RETURN` | X | 0 |
| **Return to Supplier (RTS)** | `CreateReturnToSupplierUseCase`| `ExceptionTransactionStatus::Posted` | `RECEIVED` / *any*| `RETURNED_TO_SUPPLIER` / `false` | `RETURN_TO_SUPPLIER` | 0 | X |

### The Repair Pipeline

| Operation / Action | Backing Use Case | Incident Status Created/Updated | Initial `StockItem` State (Status / Available) | Final `StockItem` State (Status / Available) | `MovementType` Triggered | `qty_in` | `qty_out` |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Initiate Repair** | `CreateRepairUseCase` | `RepairStatus::Open` | `IN_STOCK` or `DELIVERED` or `RETURNED` | `UNDER_REPAIR` / `false` | `REPAIR_IN` | 0 | 1 |
| **Update Progress** | `UpdateRepairStatusUseCase` | `RepairStatus::InProgress` | `UNDER_REPAIR` / `false` | *(Unchanged)* | *None* | 0 | 0 |
| **Complete Repair** | `UpdateRepairStatusUseCase` | `RepairStatus::Completed` | `UNDER_REPAIR` / `false` | `IN_STOCK` / `true` | `REPAIR_OUT` | 1 | 0 |
| **Cancel Repair (Unrepairable)** | `UpdateRepairStatusUseCase` | `RepairStatus::Cancelled` | `UNDER_REPAIR` / `false` | `IN_STOCK` / `false` | `REPAIR_CANCELLED`| 0 | 0 |

---

## 4. Critical Architectural Flaws & Bugs Discovered

During this deep logic analysis, several severe business logic leaks have been uncovered that will critically corrupt inventory counts over time.

### CRITICAL BUG 1: The "Non-Serialized Stock Override" Flaw
**The Problem:** 
For non-serialized products, the available stock check in `PostStockOutUseCase` purely relies on: `SUM(qty_in) - SUM(qty_out)`. 
However, look at `CreateCustomerReturnUseCase`. Whenever a customer returns an item (even if it is destroyed, requires a repair, or needs scrapping), the system inserts a `CustomerReturn` movement with `qty_in = X`. 
**The Result:** 
If a customer returns 50 broken batteries to be Scrapped, the system writes `qty_in = 50`. The main Stock Out aggregate immediately calculates that you have 50 MORE units available to sell. A warehouse worker can now successfully "sell" 50 completely destroyed batteries. `is_available` protections ONLY exist for serialized `StockItems`, not the raw movements!

### CRITICAL BUG 2: The Repair Process Ledger Inconsistency
**The Problem:** 
When `CreateRepairUseCase` runs, it issues a ledger log of `qty_out = 1`. This incorrectly implies the physical asset left the building. If the repair is cancelled, the ledger logs `qty_in = 0, qty_out = 0`.
**The Result:**
A cancelled repair essentially creates a phantom "black hole". It took 1 quantity OUT during creation, but failed to put it back IN during cancellation. You will mathematically lose 1 inventory unit forever every time a repair is cancelled.

### CRITICAL BUG 3: Impossible Repair Cancelled Status
**The Problem:** 
In `UpdateRepairStatusUseCase`, when a repair is cancelled (unrepairable), it updates the `StockItem` to `current_status = StockItemStatus::InStock` while simultaneously setting `is_available = false`.
**The Result:** 
`InStock` conceptually implies health. Setting an item to `InStock` while marking it unavailable breaks expected state assumptions. A broken, unrepairable item should transition to a status like `Scrapped` or `Quarantined`, not `InStock`.

---

## 5. Perfecting the Backend (Recommended Solutions)

To achieve a "perfect" inventory engine, implement the following systemic overhauls:

### A. Fix the Ledger Ledger Rules for Non-Serialized Items
Non-serialized aggregates cannot blindly sum `qty_in`. Movements that signify defective returns (Repairs, Scraps) must explicitly log as **quarantine movements** or not add to the sellable ledger balance.
**Fix:** 
1. `StockMovement` requires a new boolean column: `is_sellable_impact` (defaulting to true). 
2. Change the math formula to: `SUM(qty_in WHERE is_sellable_impact = true) - SUM(qty_out WHERE is_sellable_impact = true)`.
3. When creating a Customer Return for "Scrap", generate the movement with `is_sellable_impact = false`.

### B. Repair Event Harmonization
Ensure mathematical parity for repairs.
**Fix:**
- If `CreateRepairUseCase` deducts `qty_out = 1`, then `RepairCancelled` must refund it with `qty_in = 1`.
- Introduce a new `StockItemStatus::Scrapped` enum. When a repair cancels, do not assign it to `InStock`, assign it to `Scrapped`.

### C. Implement an Aggregate Pivot (Stock Balance Table)
**Fix:** Calculating `SUM()` on every `StockOut` query is an N+1 scaling nightmare waiting to happen.
Write an Eloquent Observer on the `StockMovement` model. Every time a movement is saved, asynchronously update a `StockBalance` table containing `available_qty`, `quarantine_qty`, and `out_qty`. Querying limits should check this table, not perform live math.
