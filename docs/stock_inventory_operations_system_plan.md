# Stock Inventory Operations System Plan

## 1. Project Overview

The **Stock Inventory Operations System** is an internal business system used to manage:

- stock and inventory movements
- products and item traceability
- suppliers and customers
- purchase ordering and receiving
- stock in and stock out operations
- repair, customer return, and return-to-supplier processes
- operational reports and exports
- role-based administration and audit logs

The system will support two user roles only:

- **Admin**
- **Staff**

The main objective is to ensure every stock movement is traceable, product data is controlled, and operational processes are standardized from purchase order until stock is received, QC checked, stocked out, and monitored.

---

## 2. Business Goals

1. Centralize all inventory and product records in one system.
2. Track stock movement from purchase ordering until receiving, QC, delivery to customer, repair, customer return, or supplier return.
3. Enforce role-based control for sensitive data and actions.
4. Improve traceability for serialized items.
5. Provide clear operational dashboards, reports, and exportable data.
6. Reduce manual errors in stock handling and documentation.
7. Give management strong visibility over purchasing, stock availability, movement, and exceptions.

---

## 3. User Roles and Permissions

### 3.1 Admin

Admin has full access to the system, including:

- dashboard access
- activity logs and audit trail
- data export
- full CRUD for master data
- trace all stock movements
- view all reports
- manage all operational transactions
- manage users and role assignment

### 3.2 Staff

Staff has operational access only, including:

- view dashboard based on operational scope
- read-only access to master data
- create and process operational transactions such as purchase order monitoring, stock in, QC, stock out, repair, customer return, and return to supplier
- view stock availability and item history based on allowed scope
- cannot perform CRUD on master data
- cannot access sensitive logs or full administration features

---

## 4. Core Modules

### 4.1 Dashboard

Purpose: provide operational summary and management visibility.

Suggested dashboard analysis:

- total active products
- total units in stock
- total items received pending QC
- low stock alerts
- open purchase orders
- overdue purchase orders
- stock in trend
- QC pass and fail trend
- stock out trend
- top moved products
- items under repair
- items returned to supplier
- customer returned items
- recent stock movement summary
- recent audit activity summary

### 4.2 Master Data Management

Admin-only CRUD module for:

- products
- suppliers
- customers
- users

Staff permission:

- read-only access

### 4.3 Purchase Order Module

Purpose: basic purchasing control before receiving.

Access:

- operation manager workflow can be handled under staff access if assigned operational responsibility
- admin can view and monitor all purchase orders

Key data fields:

- PO number
- PO date
- supplier
- expected delivery date
- created by
- remarks
- item details per line
    - product
    - quantity
    - subtotal

Suggested PO statuses:

- draft
- issued
- completed
- cancelled

Functions:

- create purchase order
- monitor PO status
- compare ordered quantity vs received quantity
- link PO to stock in transaction

### 4.4 Stock In Module

Purpose: receive items into inventory based on **delivery order**.

Key data fields:

- stock in number (auto increment)
- stock in date
- delivery order number (optional)
- linked PO number (optional but recommended)
- supplier
- stock in PIC
- QC person
- remarks
- item details per line
- received quantity
- condition at receiving (all this condition can be set in product  registration)
- serial number details where applicable

Functions:

- receive products from supplier based on delivery order
- capture factory serial number for devices when available
- auto-generate internal serial number when needed
- update item status to **Received**
- record audit trail

### 4.5 QC Module

Purpose: validate received items before they become available stock.

Key data fields:

- QC reference number
- QC date
- stock in reference
- QC by
- product/item details
- serial number where applicable
- QC result
- remarks

Functions:

- inspect received items
- pass or fail items
- automatically change item status from **Received** to **In Stock** when QC passes
- record failed items for exception follow-up

### 4.6 Stock Out Module

Purpose: release products to customers based on **invoice number**.

Key data fields:

- stock out number
- stock out date
- customer
- invoice number
- PIC
- remarks
- item details per line
- serial numbers for serialized items
- pick list reference
- packing verification flag

Functions:

- deduct stock based on customer invoice
- ensure serialized item traceability
- validate stock availability
- support basic pick list
- support basic packing verification
- update item status to **Delivered** after stock out posting

### 4.7 Repair Module

Purpose: track items under repair.

Key data fields:

- repair transaction number
- repair date
- product/item reference
- serial number where applicable
- customer reference if relevant
- issue description
- repair status
- remarks

Functions:

- move item into repair process
- update item status to **Under Repair**
- monitor repair history and progress

### 4.8 Return to Supplier Module

Purpose: manage items returned to supplier for defect, rejection, or other supplier-related issue.

Key data fields:

- RTS transaction number
- supplier
- related stock in reference
- product/item details
- serial number where applicable
- reason for return
- return date
- status
- remarks

Functions:

- return failed or problematic items to supplier
- update item status to **Returned to Supplier**
- monitor supplier return history

### 4.9 Customer Return Module

Purpose: manage items returned by customers.

Business rule:

- return is only allowed if the product has **stock out history**

Key data fields:

- return transaction number
- return date
- customer
- original invoice number
- original stock out reference
- product/item details
- serial number where applicable
- reason for return
- condition on return
- remarks

Functions:

- record customer returns
- validate that returned item was previously delivered
- update item status to **Returned**
- support later exchange or next action workflow

### 4.10 Stock Movement / Traceability Module

Purpose: provide complete item-level and transaction-level history.

Track movements such as:

- purchase order
- stock in
- QC result
- stock out
- repair
- customer return
- return to supplier
- stock adjustment (future option)

For every item, especially serialized items, the system should show:

- product
- serial number if applicable
- current status
- full movement timeline
- linked documents and references

### 4.11 Low Stock Alert Module

Purpose: notify operations when stock reaches threshold.

Functions:

- compare current stock against reorder level
- show low stock items in dashboard
- support operational follow-up for purchasing

### 4.12 Logs and Audit Trail

Admin-only module.

Track:

- who created, updated, or deleted records
- old value and new value for important fields
- transaction actions
- stock adjustments if added later
- export history

### 4.13 Report Module

Purpose: generate operational and management reports for every stock management module.

### 4.14 Export Module

Purpose: export operational and management data.

Supported formats:

- Excel
- CSV
- PDF

---

## 5. Master Data Design

### 5.1 Products

Each product belongs to one product type:

- **Device**
- **Accessory**
- **Consumable**

Common product fields:

- product code
- product name
- product type
- selling price
- unit of measure (UoM)
- remarks
- status (active/inactive)
- reorder level (optional)

#### Product Type Rules

**A. Device**

- serial number is mandatory
- may have factory serial number
- if factory serial number does not exist, system generates internal serial number
- full traceability required per unit

**B. Accessory**

- accessory does not use factory serial number
- system generates internal serial number for internal tracking
- examples: tab holder, LAN cable, power adapter, HDMI cable

**C. Consumable**

- no serial number at all
- tracked by quantity only
- examples: toner, label roll, cleaning fluid, tape

### 5.2 Suppliers

Fields:

- supplier code
- supplier name
- contact person
- phone
- email
- address
- status
- remarks

### 5.3 Customers

Fields:

- customer name
- contact person
- phone
- email
- address
- status
- remarks

---

## 6. Serial Number Logic

### 6.1 General Rule

- all **devices** must have serial number
- **accessories** do not have factory serial number but must receive generated internal serial number for internal use
- **consumables** do not use serial number

### 6.2 Factory Serial Number

For devices that already have manufacturer serial number:

- store as **factory_serial_number**
- must be unique when present

### 6.3 Internal Serial Number Generation

If an item needs generated internal serial number, the system uses:

`[PRODUCTCODE]-[YYYYMMDD]-[RUNNING_NO]`

Example:

- `LAP001-20260326-0001`
- `LANC01-20260326-0002`
- `TABH01-20260326-0003`

Generation rule:

- prefix comes from **product code**
- stock in date is used as date segment
- running increment is generated sequentially

Suggested fields:

- serial_number
- factory_serial_number (nullable for devices only)
- serial_source = factory / generated

### 6.4 Uniqueness Rules

- device serial number must be unique
- generated accessory internal serial number must be unique
- consumables do not use serial numbers

---

## 7. Inventory Rules and Business Logic

### 7.1 General Rules

- every stock movement must create transaction history
- stock cannot go negative unless specifically allowed by admin in future policy
- deleted transactions should be avoided; use cancel/void with audit trail instead
- all important changes must be logged
- every operational module must include a **remarks** field

### 7.2 Item Status Rules

Main item statuses:

- **Received** = item has been received through stock in, QC not completed yet
- **In Stock** = item passed QC and is available
- **Delivered** = item has been stocked out to customer
- **Under Repair** = item is under repair process
- **Returned to Supplier** = item has been sent back to supplier
- **Returned** = item was previously delivered and later returned by customer

### 7.3 Status Transition Rules

- after stock in posting → status becomes **Received**
- after QC pass → status automatically becomes **In Stock**
- after stock out posting → status becomes **Delivered**
- when sent to repair → status becomes **Under Repair**
- when returned to supplier → status becomes **Returned to Supplier**
- when customer return is accepted and stock-out history exists → status becomes **Returned**

### 7.4 Stock by Product Type

**Device**

- quantity usually equals count of serialized units
- each unit has independent status and traceability

**Accessory**

- tracked with generated internal serial number for internal use

**Consumable**

- quantity-based only

---

## 8. Key Workflows

### 8.1 Product Setup Workflow

1. Admin creates product master.
2. Admin selects product type: device, accessory, or consumable.
3. System enforces type-based serial rules.
4. Product becomes available for transactions.

### 8.2 Purchase Order Workflow

1. Staff or operation manager creates PO.
2. Supplier and expected delivery information are recorded.
3. PO is monitored until goods arrive.
4. Receiving later references PO when stock in is created.

### 8.3 Stock In Workflow

1. Staff or admin creates stock in transaction.
2. Select supplier and enter delivery order number.
3. Enter stock in PIC and QC person.
4. Add items and quantities.
5. For devices, input factory serial number or allow system serial generation.
6. For accessories, system generates internal serial number.
7. Submit transaction.
8. Item status becomes **Received**.

### 8.4 QC Workflow

1. QC transaction is created for received items.
2. QC result is entered.
3. If QC passes, status automatically becomes **In Stock**.
4. If QC fails, item remains unavailable and may proceed to supplier return process.

### 8.5 Stock Out Workflow

1. Staff or admin creates stock out transaction.
2. Select customer and invoice number.
3. Add products and quantities.
4. For serialized products, select item serial numbers.
5. Complete pick list and packing verification.
6. Submit transaction.
7. Item status becomes **Delivered**.

### 8.6 Repair Workflow

1. Faulty item is identified.
2. Repair record is created.
3. Item status changes to **Under Repair**.
4. Repair progress is tracked until completion.

### 8.7 Return to Supplier Workflow

1. User creates return-to-supplier transaction.
2. Link supplier and stock in record if available.
3. Select items and serial numbers where relevant.
4. Enter reason and remarks.
5. Submit transaction.
6. Item status becomes **Returned to Supplier**.

### 8.8 Customer Return Workflow

1. User creates customer return transaction.
2. System validates original stock out / invoice history.
3. Returned item and serial number are recorded.
4. Enter reason, condition, and remarks.
5. Submit transaction.
6. Item status becomes **Returned**.

---

## 9. Suggested Data Entities

Main entities for database design:

- users
- roles
- permissions
- products
- suppliers
- customers
- purchase_orders
- purchase_order_details
- stock_items
- stock_balances
- stock_in
- stock_in_details
- qc_transactions
- qc_transaction_details
- stock_out
- stock_out_details
- repairs
- return_to_supplier
- customer_returns
- stock_movements
- audit_logs

### Important Notes on Data Modeling

- **products** stores product master information
- **stock_items** stores unit-level records for serialized items
- **stock_balances** stores quantity totals by product
- **stock_movements** is the central ledger of all inventory transactions
- **stock_in_details** and **stock_out_details** should reference products and stock item units where necessary
- **customer_returns** must link back to original stock out history

---

## 10. Non-Functional Requirements

### 10.1 Security

- role-based access control
- secure authentication
- password hashing
- session timeout
- audit logs for sensitive actions

### 10.2 Performance

- fast search by product name, code, serial number, invoice number, PO number, and delivery order number
- efficient reporting for large transaction volumes
- pagination for large tables

### 10.3 Traceability

- item-level history for all serialized items
- document linking for all transactions
- exportable audit trail

### 10.4 Usability

- simple forms for stock operations
- clean filtering for transaction and stock search
- clear status transitions

### 10.5 Reliability

- transaction integrity for stock movement
- no duplicate serial number acceptance
- rollback on failed stock posting

---

## 11. Suggested Screens

### Admin Screens

- login
- dashboard
- user management
- product management
- supplier management
- customer management
- purchase order monitoring
- stock in
- QC
- stock out
- repair
- return to supplier
- customer return
- logs / audit trail
- stock movement explorer
- export center
- reports

### Staff Screens

- login
- dashboard
- product list (read only)
- supplier list (read only)
- customer list (read only)
- purchase order
- stock in
- QC
- stock out
- repair
- return to supplier
- customer return
- stock search / item tracking

---

## 12. Report List (Initial Scope)

### 12.1 Product / Inventory Reports

1. Stock Balance Report
2. Stock Card / Stock Movement Report
3. Product Inventory by Type
4. Serial Number Traceability Report
5. Low Stock Report
6. Slow Moving Stock Report

### 12.2 Purchase Order Reports

7. Purchase Order Summary Report
8. Open PO Report
9. PO Aging Report
10. Supplier PO Report
11. Ordered vs Received Report

### 12.3 Stock In Reports

12. Stock In Report
13. Stock In by Delivery Order Report
14. Stock In by Supplier Report
15. Received Pending QC Report
16. Serial Receiving Report

### 12.4 QC Reports

17. QC Result Report
18. QC Pass / Fail Report
19. Pending QC Report
20. QC by Supplier Report

### 12.5 Stock Out Reports

21. Stock Out Report
22. Stock Out by Invoice Report
23. Stock Out by Customer Report
24. Delivered Serial Number Report
25. Product Issue Report

### 12.6 Repair Reports

26. Repair Summary Report
27. Items Under Repair Report
28. Repair Aging Report
29. Repair History by Serial Number

### 12.7 Return to Supplier Reports

30. Return to Supplier Report
31. RTS by Supplier Report
32. RTS Aging Report
33. RTS by Reason Report

### 12.8 Customer Return Reports

34. Customer Return Report
35. Return by Invoice Report
36. Return by Customer Report
37. Return by Reason Report
38. Exchange Traceability Report

### 12.9 Monitoring and Audit Reports

39. Full Stock Movement Ledger
40. User Activity Log Report
41. Master Data Change Log
42. Transaction Audit Trail
43. Serial Number History Report

---

## 13. Implementation Phases

### Phase 1 - Foundation

- authentication and two-role access control
- master data: products, suppliers, customers
- product type rules
- serial number generation logic using product code
- basic dashboard

### Phase 2 - Core Operations

- purchase order
- stock in by delivery order
- QC process
- stock out by invoice number
- stock balance
- stock movement history
- low stock alerts

### Phase 3 - Exception Handling

- repair module
- customer return module
- return to supplier module
- dashboard analysis improvements

### Phase 4 - Reporting and Governance

- reports by module
- exports
- audit logs
- management monitoring improvements

---

## 14. Recommended MVP Scope

For the first release, focus on:

- login and role-based access
- product, supplier, customer master data
- product type rules
- selling price and UoM in product master
- serial number handling
- purchase order
- stock in by delivery order
- QC process
- stock out by invoice
- stock movement history
- repair
- return to supplier
- customer return
- low stock alerts
- dashboard
- reports
- audit logs for critical actions

This gives a usable operational system quickly while keeping the scope manageable.

---

## 15. Deferred Scope

These items are intentionally skipped for now:

- warehouse/location control
- reservation / allocation
- shipment / courier / transport module
- proof of delivery
- partial delivery support
- delivery milestone tracking

---

## 16. Final Summary

This system should be designed as a **traceable, role-based inventory operations platform** with strong support for purchasing, receiving, QC, stock-out control, serialized tracking, and exception handling.

Admin controls master data, exports, logs, and full stock traceability, while staff performs daily operational transactions with controlled access.

The most important design principles are:

- every stock movement must be recorded and traceable
- all devices must have serial number
- accessories use generated internal serial number for internal tracking
- consumables do not use serial number
- stock in creates **Received** status
- QC pass creates **In Stock** status
- stock out creates **Delivered** status
- repair creates **Under Repair** status
- supplier return creates **Returned to Supplier** status
- customer return creates **Returned** status

This makes the system reliable for stock control, customer fulfillment, audit monitoring, repair tracking, and supplier return handling.
