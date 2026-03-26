# Laravel Backend Build Plan - SIO (Architecture First)

This plan is rebuilt to execute the backend flow-by-flow with clean architecture, strict security, and clear delivery gates.

## 1. Current Status (Completed)

- [x] Laravel project initialized.
- [x] Local Git repository initialized.
- [x] Remote repository connected.
- [x] MySQL configured in environment.
- [x] Base migrations executed successfully.

## 2. Target Architecture (Clean + Practical for Laravel)

Use a layered modular structure so business logic is isolated from framework details.

### 2.1 Layer Responsibilities

- Domain Layer: Entities, value objects, domain rules, domain events, enums.
- Application Layer: Use cases, DTOs, command/query handlers, interfaces (ports).
- Infrastructure Layer: Eloquent repositories, external services, queue adapters, cache adapters.
- Interface Layer: HTTP controllers, Form Requests, API Resources, route definitions.

### 2.2 Dependency Rule

- Interface -> Application -> Domain.
- Infrastructure depends on Application/Domain contracts, not the reverse.
- Controllers never contain business rules.
- Eloquent models are persistence objects, not business workflow engines.

### 2.3 Suggested Module Boundaries

- IdentityAccess
- MasterData
- PurchasingInbound
- InventoryCore
- QcOutbound
- ExceptionsReturns
- ReportingAudit

## 3. Security Baseline (Must Exist Before Feature Build)

- Authentication: Laravel Sanctum (token-based, ability scopes).
- Authorization: Policies + Gates + role/permission matrix.
- Input hardening: Form Requests, strict validation rules, deny unknown fields.
- Output hardening: API Resources to prevent overexposure of internal fields.
- Secrets: .env only, no secret in code, rotate APP_KEY only with procedure.
- Passwords: Argon2id/Bcrypt with strong defaults.
- Rate limiting: login and sensitive transaction endpoints.
- Auditability: immutable audit logs for auth and inventory status changes.
- Database security: least-privilege MySQL user for app runtime.
- API security headers and strict CORS allowlist.
- Logging safety: never log passwords, tokens, or full personal data.
- Test security gates: unauthenticated and unauthorized test cases required for every protected endpoint.

## 4. Build Flow (One by One, in Correct Order)

Each flow must pass tests and security checks before moving to the next one.

### Flow 0: Architecture Skeleton and Conventions

Objective: Lock structure before adding more features.

- Define folder/module conventions per layer.
- Add base abstractions (UseCase interface, Repository interfaces, base DTO pattern).
- Add global exception mapping for consistent API error format.
- Define API response contract (success/error metadata).
- Add coding standards (Pint/PHPStan level target) and CI checks.

Definition of Done:

- Architecture folders/modules are in place.
- CI runs lint + tests.
- Error response contract documented and tested.

### Flow 1: Identity and Access Control

Objective: Secure entry point for every future API.

- Install/configure Sanctum.
- Implement login/logout/me.
- Add role model (admin, staff) and user status guard (active/inactive).
- Apply policies/middleware to protect all non-public routes.
- Add rate limiting and lockout strategy for login.

Definition of Done:

- Auth endpoints work with token lifecycle.
- Unauthorized and forbidden requests are blocked and tested.
- Role matrix for admin/staff is documented.

### Flow 2: Master Data Core (Products, Suppliers, Customers)

Objective: Build stable reference data required by operations.

- Finalize entities after your upcoming data model plan.
- Implement migrations + constraints + indexes.
- Build use cases and repository contracts.
- Implement CRUD APIs with policy checks.
- Add soft delete strategy only if business requires recoverability.

Definition of Done:

- CRUD secured by role.
- Validation and unique constraints enforced at API and DB level.
- Feature tests and policy tests pass.

### Flow 3: Purchasing and Stock In (Inbound)

Objective: Register incoming stock with full traceability.

- Implement Purchase Order and Stock In aggregates.
- Add serial number generation service (for serialized products).
- Create stock item records and initial stock movements atomically.
- Enforce transaction boundaries (DB transaction in application service).

Definition of Done:

- PO -> Stock In flow is functional.
- Every inbound action creates stock movement ledger records.
- Duplicate serial protection is guaranteed.

### Flow 4: QC and Stock Out (Outbound)

Objective: Control release of valid stock only.

- Implement QC pass/fail process with state transitions.
- Restrict Stock Out to eligible states only.
- Create movement logs for each transition.
- Add idempotency strategy for outbound endpoint to avoid duplicate delivery records.

Definition of Done:

- Invalid transitions are rejected.
- Outbound flow is auditable end-to-end.
- Concurrency tests pass for race conditions.

### Flow 5: Exceptions (Repair and Returns)

Objective: Handle non-happy paths without breaking inventory truth.

- Implement repair lifecycle.
- Implement supplier return and customer return.
- Maintain strict status transition map.
- Keep movement ledger and audit logs synchronized.

Definition of Done:

- Exception flows preserve stock integrity.
- Traceability remains complete for each affected stock item.

### Flow 6: Dashboard, Reports, and Audit

Objective: Expose trustworthy analytics and history.

- Build dashboard summary query services.
- Build movement reports with filtering and pagination.
- Implement audit log browse APIs for admin.
- Add indexes for report-heavy queries.

Definition of Done:

- Dashboard/report endpoints meet response-time target.
- Audit events are complete and tamper-evident by design.

## 5. Data Model Integration Gate (Pending Your Model Plan)

Before final migrations for Flows 2-6:

- Review your model plan and map every table to a module.
- Confirm naming standards, foreign keys, delete behavior, and unique constraints.
- Define enum strategy (DB enum vs lookup/reference table).
- Freeze migration sequence once approved.

## 6. Non-Functional Requirements (Always On)

- Testing pyramid:
    - Unit tests for domain rules and services.
    - Feature tests for API endpoints and policies.
    - Integration tests for repository and transaction boundaries.
- Performance:
    - Pagination by default.
    - Prevent N+1 with eager loading policy.
    - Add index review checklist for each migration.
- Reliability:
    - Use DB transactions for all multi-write use cases.
    - Queue non-critical side effects.
- Observability:
    - Structured logs with correlation ID.
    - Error monitoring integration.

## 7. Immediate Next Build Step

Start with Flow 0 and Flow 1 only.

1. Create architecture skeleton and shared conventions.
2. Implement Sanctum auth + role policy matrix.
3. Add tests for auth and authorization gates.

After that, we continue to Flow 2 once you provide the data model plan.
