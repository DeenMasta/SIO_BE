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
