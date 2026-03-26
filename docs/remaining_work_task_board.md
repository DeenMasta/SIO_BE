# Remaining Work Task Board (Phase 2/3/4)

Status legend:

- TODO = not started
- IN PROGRESS = active work
- DONE = completed

Priority legend:

- P0 = blocking for MVP completeness
- P1 = high value next
- P2 = important but can follow

## Current Remaining Status (2026-03-27)

- Remaining tasks: none.
- Open checkbox count: 0.
- All tracked Phase 2/3/4 items are marked DONE.

## Phase 2 - Core Operations

### P0

- [x] T2-001 - Staff operational permissions
    - Status: DONE
    - Scope: allow staff create/post for PO, Stock In, QC, Stock Out, Repair, RTS, Customer Return; keep master data CRUD admin-only.
    - Acceptance criteria:
        - Staff can post operational transactions.
        - Staff cannot create/update/delete product/supplier/customer.
        - Admin keeps full access.
    - Test cases:
        - Feature test: staff can create each operational module.
        - Feature test: staff forbidden on master data write endpoints.

- [x] T2-002 - PO lifecycle transitions
    - Status: DONE
    - Scope: add issue/complete/cancel actions with transition rules.
    - Acceptance criteria:
        - Valid transitions only: DRAFT->ISSUED->COMPLETED, cancel allowed from DRAFT/ISSUED.
        - Invalid transitions return 422.
        - Audit log created per transition.
    - Test cases:
        - Feature test for each valid and invalid transition.
        - Audit assertion for transition actions.

- [x] T2-003 - Ordered vs received tracking
    - Status: DONE
    - Scope: maintain received_qty per PO line and auto-complete PO when fulfilled.
    - Acceptance criteria:
        - Stock In linked to PO increments line received_qty.
        - Over-receive blocked.
        - PO status updates to COMPLETED when all lines fulfilled.
    - Test cases:
        - Partial receive then complete receive.
        - Attempt receive beyond ordered_qty returns 422.

### P1

- [x] T2-004 - Low stock alert module
    - Status: DONE
    - Scope: compare on-hand vs reorder_level and expose low stock list.
    - Acceptance criteria:
        - New endpoint for low stock list.
        - Dashboard includes low_stock_count.
        - Sorted by severity (largest shortage first).
    - Test cases:
        - Products crossing threshold appear.
        - Products above threshold do not appear.

- [x] T2-005 - Dashboard operational metrics expansion
    - Status: DONE
    - Scope: open PO, overdue PO, stock in trend, QC pass/fail trend, stock out trend, top moved products.
    - Acceptance criteria:
        - Metrics returned in dashboard response.
        - Optional date range filters supported.
    - Test cases:
        - Seeded data returns non-zero metric counts.
        - Date filter changes result set.

- [x] T2-006 - Non-negative stock enforcement for non-serialized
    - Status: DONE
    - Scope: prevent stock out when balance insufficient.
    - Acceptance criteria:
        - Outbound quantity > available is rejected.
        - Stock balances never negative.
    - Test cases:
        - Pass when enough stock.
        - Fail with 422 when insufficient stock.

### P2

- [x] T2-007 - Search optimization endpoints
    - Status: DONE
    - Scope: filters/search by product code/name, serial, invoice, PO number, DO number.
    - Acceptance criteria:
        - All key search fields available and paginated.
    - Test cases:
        - Query each searchable field and verify expected records.

## Phase 3 - Exception Handling

### P1

- [x] T3-001 - Repair state machine hardening
    - Status: DONE
    - Scope: strict transitions with clear allowed map and cancellation path handling.
    - Acceptance criteria:
        - Only allowed transitions accepted.
        - Status history visible in response or related endpoint.
    - Test cases:
        - Valid transitions pass.
        - Invalid transitions fail.

- [x] T3-002 - Customer return next_action workflow
    - Status: DONE
    - Scope: enforce next_action options (RESTOCK/REPAIR/REPLACE/SCRAP) and map resulting stock status logic.
    - Acceptance criteria:
        - next_action required by business rules.
        - Status/movement side effects deterministic.
    - Test cases:
        - One test per next_action path.

- [x] T3-003 - Exception reason taxonomy
    - Status: DONE
    - Scope: controlled reasons for RTS and customer return for reporting consistency.
    - Acceptance criteria:
        - Reason validation from approved set.
        - Unknown reason rejected.
    - Test cases:
        - Accepted reason passes.
        - Invalid reason fails with 422.

### P2

- [x] T3-004 - Exception aging metrics
    - Status: DONE
    - Scope: repair/RTS/customer return aging buckets.
    - Acceptance criteria:
        - Aging fields/filters available in report endpoints.
    - Test cases:
        - Records fall into correct age bucket.

- [x] T3-005 - Full ledger and audit on reversals/cancellations
    - Status: DONE
    - Scope: ensure every cancellation/reversal writes stock movement + audit entry.
    - Acceptance criteria:
        - No state-changing exception action without ledger and audit rows.
    - Test cases:
        - Cancel paths assert both stock_movements and audit_logs.

## Phase 4 - Reporting and Governance

### P0

- [x] T4-001 - User management API (admin)
    - Status: DONE
    - Scope: list/create/update user role/status; activate/deactivate.
    - Acceptance criteria:
        - Admin-only access.
        - Staff forbidden.
        - Role assignment and status changes persisted.
    - Test cases:
        - Admin success tests.
        - Staff forbidden tests.

- [x] T4-002 - Report pack v1
    - Status: DONE
    - Scope (first batch): stock balance, stock card, low stock, PO summary/open/aging, stock in by supplier/DO, QC pass-fail, stock out by invoice/customer, repair summary, RTS summary, customer return summary.
    - Acceptance criteria:
        - Endpoints available with pagination/filtering.
    - Test cases:
        - One feature test per endpoint minimum.

### P1

- [x] T4-003 - Serial traceability report
    - Status: DONE
    - Scope: full timeline by serial number with linked references.
    - Acceptance criteria:
        - Query by serial returns complete chain in chronological order.
    - Test cases:
        - One serialized item across receive->qc->out->return path.

- [x] T4-004 - Export formats (Excel, PDF)
    - Status: DONE
    - Scope: extend export from CSV to XLSX and PDF for key reports/audit logs.
    - Acceptance criteria:
        - format parameter supports csv/xlsx/pdf.
        - Correct content-type/filename per format.
    - Test cases:
        - Endpoint response headers/content assertions per format.

- [x] T4-005 - Audit export actions
    - Status: DONE
    - Scope: record EXPORT audit action for every report export with filter metadata.
    - Acceptance criteria:
        - audit_logs row created per export request.
    - Test cases:
        - Export triggers audit row with action=EXPORT.

### P2

- [x] T4-006 - Security timeout hardening
    - Status: DONE
    - Scope: token/session timeout policy and configuration.
    - Acceptance criteria:
        - Sanctum expiration configured and documented.
    - Test cases:
        - Expired token rejected.

- [x] T4-007 - API/report contract documentation
    - Status: DONE
    - Scope: update docs for all new endpoints, filters, and export formats.
    - Acceptance criteria:
        - docs include request/response examples and error model.

## Suggested Sprint Order

- Sprint A (P0): T2-001, T2-002, T2-003, T4-001
- Sprint B (P1 core): T2-004, T2-005, T2-006, T4-002
- Sprint C (governance/reporting): T4-003, T4-004, T4-005, T4-006, T3-001
- Sprint D (refinement): T3-002, T3-003, T3-004, T3-005, T2-007, T4-007

## Latest Completion Update (2026-03-27)

- Sprint C implementation delivered and validated.
- Completed tasks in this cycle:
    - T3-001 Repair state machine hardening.
    - T4-003 Serial traceability report.
    - T4-004 Export formats (CSV, XLSX, PDF).
    - T4-005 Audit export actions with filter/format metadata.
    - T4-006 Security timeout hardening (Sanctum token expiration policy).
- Validation:
    - Targeted feature tests for each completed task passed.
    - Full regression suite passed: 92 tests, 476 assertions.

## Latest Completion Update (2026-03-27, Remaining Scope)

- Sprint D refinement implementation delivered.
- Completed tasks in this cycle:
    - T2-007 Search optimization endpoints (product code/name, serial, invoice, PO number, DO number; paginated).
    - T3-002 Customer return next_action workflow (RESTOCK/REPAIR/REPLACE/SCRAP with deterministic stock status effects).
    - T3-003 Exception reason taxonomy (approved reason set validation for RTS and customer return).
    - T3-004 Exception aging metrics (age buckets for repair/RTS/customer return reports with filters and response fields).
    - T3-005 Full ledger and audit on reversals/cancellations (RTS and customer return cancellation workflows with stock movement + audit rows).
    - T4-007 API/report contract documentation updated with endpoint/filter/examples/error model.
