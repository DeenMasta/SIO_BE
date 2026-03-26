# Laravel Backend Implementation Plan - Stock Inventory Operations System

This document outlines the technical plan to build the backend using **Laravel 10/11** and **MySQL**.

## 1. Project Initialization & Environment

- [ ] **Initialize Laravel Project**
  - Install via Composer: `composer create-project laravel/laravel sio_backend`
  - Initialize Git repository.
- [ ] **Database Setup**
  - Create MySQL database `sio_db`.
  - Configure `.env` file with DB credentials.
- [ ] **API Setup**
  - Install **Laravel Sanctum** for API authentication (Token-based auth for Admin/Staff).
  - Configure CORS to allow frontend connections.

## 2. Database Design (Migrations)

We will create migrations for the entities defined in the business plan.

### 2.1 User Management

- [ ] `users`: standard fields + `role` (enum: 'admin', 'staff'), `status` (active/inactive).

### 2.2 Master Data

- [ ] `products`:
  - `type` (enum: 'device', 'accessory', 'consumable')
  - `is_serialized` (boolean)
  - `uom`, `price`, `reorder_level`.
- [ ] `suppliers`: basic contact info.
- [ ] `customers`: basic contact info.

### 2.3 Operations - Purchasing & Inbound

- [ ] `purchase_orders` (PO): `po_number`, `supplier_id`, `status` (draft, issued, completed, cancelled).
- [ ] `purchase_order_items`: `po_id`, `product_id`, `quantity`.
- [ ] `stock_ins` (Received Goods): `delivery_order_number`, `po_id` (nullable), `supplier_id`.
- [ ] `stock_in_items`: `stock_in_id`, `product_id`, `quantity_received`.

### 2.4 Inventory Core (The "Brain")

- [ ] `stock_items`: **Crucial Table** for tracking individual units.
  - `product_id`
  - `serial_number` (Factory or Internal)
  - `status` (received, in_stock, delivered, under_repair, returned_supplier, returned_customer)
  - `current_location` / `holder`.
- [ ] `stock_movements`: The Ledger.
  - `stock_item_id` (nullable for consumables)
  - `product_id`
  - `type` (in, out, qc, repair, return)
  - `quantity` (positive/negative)
  - `reference_id` (polymorphic or specific columns for related transaction).

### 2.5 Operations - QC & Outbound

- [ ] `qc_inspections`: `stock_in_item_id`, `result` (pass/fail), `inspector_id`.
- [ ] `stock_outs`: `invoice_number`, `customer_id`, `date`.
- [ ] `stock_out_items`: `stock_out_id`, `stock_item_id` (for serialized), `product_id`.

### 2.6 Exception Handling

- [ ] `repairs`: `stock_item_id`, `issue`, `status`.
- [ ] `supplier_returns`: `stock_item_id`, `supplier_id`, `reason`.
- [ ] `customer_returns`: `stock_item_id`, `customer_id`, `original_stock_out_id`.

### 2.7 System

- [ ] `audit_logs`: `user_id`, `action`, `model`, `old_values`, `new_values`.

## 3. Core Logic Implementation (Services & Traits)

### 3.1 Serial Number Service

- Logic to generate internal serials `[PRODUCTCODE]-[YYYYMMDD]-[RUNNING_NO]`.
- Validation ensures unique factory serials for Devices.

### 3.2 Inventory Movement Service

- A centralized service to handle all state changes.
- Example: `InventoryService::receive($data)`, `InventoryService::passQC($itemId)`, `InventoryService::deliver($data)`.
- Ensures that whenever a status changes, a `stock_movement` log is created automatically.

### 3.3 Role-Based Access Control (Policies)

- Create Policies for each model.
- **Admin**: Full access (viewAny, view, create, update, delete).
- **Staff**: Restricted access (viewAny, view, create operational records). Deny `delete` on master data.

## 4. API Endpoints (Controllers)

### 4.1 Auth

- `POST /login`
- `POST /logout`
- `GET /me`

### 4.2 Master Data (Admin CRUD, Staff Read)

- `apiResources` for `/products`, `/suppliers`, `/customers`.

### 4.3 Transactions

- `POST /purchase-orders` (Draft -> Issue)
- `POST /stock-ins` (Trigger Serial Generation here)
- `POST /qc` (Trigger Status Change: Received -> In Stock)
- `POST /stock-outs` (Trigger Status Change: In Stock -> Delivered)

### 4.4 Exception Handling

- `POST /repairs`
- `POST /returns/supplier`
- `POST /returns/customer`

### 4.5 Dashboard & Reports

- `GET /dashboard/summary`: Aggregated stats (low stock, pending QC).
- `GET /reports/movements`: Filtered history.

## 5. Development Phases

### Phase 1: Foundation

1. Install Laravel.
2. Setup Auth (Sanctum).
3. Migrations for Users, Products, Suppliers, Customers.
4. CRUD APIs for Master Data.

### Phase 2: Inbound Operations

1. Migrations for PO, Stock In, Stock Items.
2. Service for Serial Number Generation.
3. API for Creating PO.
4. API for Stock In (Receiving).

### Phase 3: QC & Outbound

1. QC Logic (Status transition `Received` -> `In Stock`).
2. Stock Out Logic (Status transition `In Stock` -> `Delivered`).
3. Stock Movement Logging.

### Phase 4: Exceptions & Reporting

1. Repair and Return modules.
2. Dashboard Aggregations.
3. Audit Logs (Observer pattern).
