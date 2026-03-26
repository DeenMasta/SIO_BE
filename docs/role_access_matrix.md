# Role Access Matrix

## Roles

- admin: Full system access.
- staff: Operational access with restricted administrative capabilities.

## Baseline Rules

- Inactive users cannot access protected APIs.
- All protected APIs require `auth:sanctum`.
- Admin-only operations require `can:access-admin`.
- Staff and admin shared operational endpoints can use `can:access-staff`.

## Initial Endpoint Matrix

- `POST /api/login`: public
- `POST /api/logout`: staff, admin (active only)
- `GET /api/me`: staff, admin (active only)
- `GET /api/admin/ping`: admin (active only)
- `GET /api/products`: staff, admin (active only)
- `GET /api/products/{id}`: staff, admin (active only)
- `POST /api/products`: admin (active only)
- `PUT/PATCH /api/products/{id}`: admin (active only)
- `DELETE /api/products/{id}`: admin (active only)
- `GET /api/suppliers`: staff, admin (active only)
- `GET /api/suppliers/{id}`: staff, admin (active only)
- `POST /api/suppliers`: admin (active only)
- `PUT/PATCH /api/suppliers/{id}`: admin (active only)
- `DELETE /api/suppliers/{id}`: admin (active only)
- `GET /api/customers`: staff, admin (active only)
- `GET /api/customers/{id}`: staff, admin (active only)
- `POST /api/customers`: admin (active only)
- `PUT/PATCH /api/customers/{id}`: admin (active only)
- `DELETE /api/customers/{id}`: admin (active only)
- `GET /api/purchase-orders`: staff, admin (active only)
- `GET /api/purchase-orders/{id}`: staff, admin (active only)
- `POST /api/purchase-orders`: admin (active only)
- `GET /api/stock-ins`: staff, admin (active only)
- `GET /api/stock-ins/{id}`: staff, admin (active only)
- `POST /api/stock-ins`: admin (active only)
- `GET /api/qc-transactions`: staff, admin (active only)
- `GET /api/qc-transactions/{id}`: staff, admin (active only)
- `POST /api/qc-transactions`: admin (active only)
- `GET /api/stock-outs`: staff, admin (active only)
- `GET /api/stock-outs/{id}`: staff, admin (active only)
- `POST /api/stock-outs`: admin (active only)
- `GET /api/repairs`: staff, admin (active only)
- `GET /api/repairs/{id}`: staff, admin (active only)
- `POST /api/repairs`: admin (active only)
- `PATCH /api/repairs/{id}/status`: admin (active only)
- `GET /api/return-to-suppliers`: staff, admin (active only)
- `GET /api/return-to-suppliers/{id}`: staff, admin (active only)
- `POST /api/return-to-suppliers`: admin (active only)
- `GET /api/customer-returns`: staff, admin (active only)
- `GET /api/customer-returns/{id}`: staff, admin (active only)
- `POST /api/customer-returns`: admin (active only)
- `GET /api/dashboard/summary`: staff, admin (active only)
- `GET /api/reports/stock-movements`: staff, admin (active only)
- `GET /api/reports/stock-movements/export`: staff, admin (active only)
- `GET /api/audit-logs`: admin (active only)
- `GET /api/audit-logs/export`: admin (active only)
