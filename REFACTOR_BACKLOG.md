# Refactor Backlog — AED Foreigner Backend

Tracked remediation items derived from the Laravel 12 best-practices audit (`guidelines.md`).  
**Status key:** `todo` · `in-progress` · `blocked` · `done` · `deferred`

**Last updated:** 2026-07-10 (P3 partial: 01, 02, 03, 04, 07)

---

## How to use this document

1. Pick items top-down within each phase (highest impact / lowest risk first).
2. Mark status inline when starting or finishing work.
3. Link PRs in the **PR** column when available.
4. Do **not** start implementation until explicitly requested — this file is planning only.

---

## Summary dashboard

| Phase | Theme | Items | Done | Priority |
|-------|--------|------:|-----:|----------|
| [P0](#p0-production--stability-blockers) | Production & stability blockers | 6 | 5 | Critical |
| [P1](#p1-configuration--environment) | Configuration & environment | 8 | 8 | High |
| [P2](#p2-validation--http-layer) | Validation & HTTP layer | 10 | 10 | High |
| [P3](#p3-architecture--controller-decomposition) | Architecture & controller decomposition | 12 | 11 | High |
| [P4](#p4-api-contract--resources) | API contract & resources | 5 | 0 | Medium |
| [P5](#p5-database--eloquent) | Database & Eloquent | 9 | 0 | Medium |
| [P6](#p6-async--external-integrations) | Async & external integrations | 6 | 0 | Medium |
| [P7](#p7-php-typing--static-analysis) | PHP typing & static analysis | 7 | 0 | Medium |
| [P8](#p8-testing--ci) | Testing & CI | 8 | 0 | High |
| [P9](#p9-routing--laravel-12-hygiene) | Routing & Laravel 12 hygiene | 5 | 0 | Low |
| [P10](#p10-naming--documentation-cleanup) | Naming & documentation cleanup | 6 | 0 | Low |

**Total:** 82 items

---

## P0 — Production & stability blockers

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P0-01 | `done` | Add `cache` table migration **or** default `CACHE_STORE=file` for fresh installs | `.env`, `.env.example` | Workaround: `CACHE_STORE=file` in env (no cache table migration needed locally) |
| P0-02 | `done` | Add `sessions` table migration **or** default `SESSION_DRIVER=file` if DB sessions unused | `.env`, `.env.example` | Workaround: `SESSION_DRIVER=file` in env |
| P0-03 | `todo` | Remove hardcoded subscription validation (`package => 7`) from production path | `ForeignerEnrollmentController::validateSubscription()` | **Deferred** until Kkiapay flow is defined for foreigner packages; `$transactionId` currently ignored |
| P0-04 | `done` | Fix swapped `CLIENT_ID` / `CLIENT_SECRET` in `AttachmentTrait` constructor | `app/Traits/AttachmentTrait.php` | Now uses `TX_CLIENT_ID` / `TX_CLIENT_SECRET` correctly |
| P0-05 | `done` | Align `.env.example` with Laravel 12 (`CACHE_STORE`, not legacy `CACHE_DRIVER`) | `.env.example` | `CACHE_STORE=file`, `SESSION_DRIVER=file` added |
| P0-06 | `done` | Document required env vars in `.env.schema` | `.env.schema` | Grouped by domain; P0 cache/session/DB notes; legacy direct-usage list for P1 |

---

## P1 — Configuration & environment

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P1-01 | `done` | Move frontend URL env to config | Controllers, Blade emails | Consolidated on `FRONTEND_URL` → `config('app.frontend_url')`; `FRONT_URL` removed |
| P1-02 | `done` | Move `env('FRONTEND_URL')` to config | `SigningIdentityController`, Mail, Blade | Same as P1-01 after consolidation |
| P1-03 | `done` | Move `env('APP_TIMEZONE')` to config | `SignatureController`, `config/app.php` | `config('app.timezone')` |
| P1-04 | `done` | Move TrustedX / ANIP env reads to config | `AuthTrait`, `AttachmentTrait` | `config/trustedx.php` |
| P1-05 | `done` | Replace `env()` in Blade email templates | `resources/views/emails/**` | All use `config()` |
| P1-06 | `done` | Audit remaining `env()` outside `config/` | `app/`, `resources/` | Zero matches after P1 |
| P1-07 | `done` | Centralize frontend URL config | `config/app.php` | Single key: `frontend_url` from `FRONTEND_URL` only (no duplicate `FRONT_URL`) |
| P1-08 | `done` | Verify `config:cache` works | Local | `php artisan config:cache` succeeds |

---

## P2 — Validation & HTTP layer

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P2-01 | `done` | Wire or replace stub Form Requests (currently unused) | `app/Http/Requests/*` | 10 unused stubs deleted; 24 typed requests wired |
| P2-02 | `done` | Extract validation from `ForeignerEnrollmentController` → Form Requests | `sendOtp`, `verifyOtp`, `initRegistration`, `finalizeRegistration` | `App\Http\Requests\Foreigner\*` |
| P2-03 | `done` | Extract validation from `UserController` → Form Requests | OTP, login, status updates, finalize, employee create | `App\Http\Requests\User\*` |
| P2-04 | `done` | Extract validation from `AuthController` → Form Requests | `registerAgent`, OTP, password reset | `App\Http\Requests\Auth\*` |
| P2-05 | `done` | Extract validation from `IdentityReviewController` → Form Requests | `supervisorReject`, `reject` | `RejectIdentityRequest` |
| P2-06 | `done` | Extract validation from `StructureController` → Form Requests | `updateStructureStatus`, store/update flows | `UpdateStructureStatusRequest` (store/update deferred) |
| P2-07 | `done` | Extract validation from `AttachmentController`, `StructureSubscriptionController` | Inline validators | `App\Http\Requests\Management\*` |
| P2-08 | `done` | Fix stub Form Requests with `authorize(): false` | Deleted stubs | Replaced by real request classes |
| P2-09 | `done` | Standardize validation error response shape (422 + `data` errors) | `ApiFormRequest`, `bootstrap/app.php` | `{ success, message, status: 422, data: errors }` |
| P2-10 | `done` | Add Form Request coverage to refactor acceptance criteria | Tests | `test_send_otp_validation_error_returns_api_envelope` |

---

## P3 — Architecture & controller decomposition

| ID | Status | Item | Primary files / area | Est. effort | Notes |
|----|--------|------|----------------------|-------------|-------|
| P3-01 | `done` | Extract `EnrollmentService` from `ForeignerEnrollmentController` | ~550 lines → ~230 (mostly OpenAPI) | `ForeignerEnrollmentService`; controller ~80 LOC logic |
| P3-02 | `done` | Extract `IdentityReviewService` from `IdentityReviewController` | ~403 → ~80 lines | Claim, approve, reject, supervisor flows |
| P3-03 | `done` | Extract `UserRegistrationService` from `UserController` | ~1,493 → ~947 lines | OTP, login, finalize, in-person approve, employee create; implemented missing `storeSubscription` |
| P3-04 | `done` | Extract `StructureManagementService` from `StructureController` | ~1,295 → ~761 lines (mostly OpenAPI) | CRUD, OTP, invitations, employee management; `AttachmentTrait` on service |
| P3-05 | `done` | Extract `SignatureService` from `SignatureController` | ~783 lines | L | PKI HTTP, timestamps |
| P3-06 | `done` | Extract `SigningIdentityService` from `SigningIdentityController` | ~684 → ~180 lines | M | TrustedX provisioning |
| P3-07 | `done` | Extract `AdminAuthService` from `AuthController` | ~591 → ~200 lines | Agents, OTP, password reset |
| P3-08 | `done` | Decompose `AuthTrait` into injectable services | ~674 lines trait | L | Used by 10+ controllers; HTTP client logic |
| P3-09 | `done` | Decompose `AttachmentTrait` into `AttachmentUploadService` | 4 consumers migrated; trait now unused | S | File storage only |
| P3-10 | `done` | Decompose `ADTrait` into LDAP/AD service | Extracted into `ADService`; trait removed | M | |
| P3-11 | `done` | Move `DB::beginTransaction()` blocks from controllers into services | 6 controllers | Done for ForeignerEnrollment, IdentityReview, AdminAuth, UserRegistration, StructureManagement |
| P3-12 | `todo` | Introduce invokable controllers for single-action endpoints | New structure | S | e.g. health-adjacent actions, one-offs |

### Controller size targets (acceptance)

| Controller | Current ~lines | Target |
|------------|---------------:|-------:|
| `UserController` | 1,493 | < 300 (or split into multiple controllers) |
| `StructureController` | 761 | < 300 |
| `SignatureController` | 783 | < 300 |
| `SigningIdentityController` | 684 | < 300 |
| `AuthController` | 591 | < 250 |
| `ForeignerEnrollmentController` | 550 | < 250 |
| `IdentityReviewController` | 403 | < 250 |

---

## P4 — API contract & resources

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P4-01 | `todo` | Introduce `app/Http/Resources/` and baseline resource classes | New directory | Zero resources today |
| P4-02 | `todo` | Migrate `IdentityReviewController` responses to API Resources | `IdentityReviewResource`, collection | Already has structured JSON |
| P4-03 | `todo` | Migrate `ForeignerEnrollmentController` responses | Enrollment resources | |
| P4-04 | `todo` | Normalize `IdRequestController` to standard `{ success, message, data }` envelope | `IdRequestController` | Currently raw model JSON |
| P4-05 | `todo` | Document public API schema (OpenAPI) aligned to `/api/v1` paths | `routes/api.php`, controller `@OA` blocks | Many annotations still say `/api/...` |

---

## P5 — Database & Eloquent

**Goal:** Fix severe N+1 bottlenecks, replace huge `all()` queries with pagination, and modernize Eloquent models.

| ID | Task | Impact | Status | Notes |
|---|---|---|---|---|
| **P5-01** | Replace `paginate(..., 9999999999999)` | 🔴 High | `done` | Apply max limits (e.g. 100) to Structure, Revocation, Message, Subscription, Package controllers |
| **P5-02** | Replace `IdRequest::all()` with paginated query | 🔴 High | `done` | `IdRequestController::index` |
| **P5-03** | Replace `ActivityLog::all()` | 🟡 Med | `done` | Move to chunking / pagination in `AuditLogController` |
| **P5-04** | Audit N+1 on heavily used list endpoints | 🔴 High | `done` | Add `with()` to `StructureController`, `UserController`, `StatsController`, `RevocationController`, `UserSubscriptionController` |
| **P5-05** | Consolidate password reset storage logic | 🟡 Med | `done` | Created `PasswordResetToken` model to replace raw DB queries |
| **P5-06** | Reduce raw `DB::table()` queries | 🟡 Med | `done` | Cleaned up `AuthController`, `PasswordResetController`, `UserController`, `UserRegistrationService` |
| **P5-07** | Review model global scopes | 🟡 Med | `done` | Removed `auth()->id()` dependency from `UserSubscription` and `Revocation` booted methods and replaced with local scope `forUser()` |
| **P5-08** | Align Schema with Code | 🟡 Med | `deferred` | Verify `roles` and `permissions` tables match expected Spatie defaults |
| **P5-09** | Migrate `$casts` to `casts()` method | 🟢 Low | `done` | Laravel 12 convention on `User`, `PendingRegistration`, `StructureInvitation` |

---

## P6 — Async & external integrations

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P6-01 | `deferred` | Move sync TrustedX/PKI HTTP out of request cycle | `SignatureController`, `SigningIdentityController` | Frontend relies on immediate response; needs polling/webhook first |
| P6-02 | `done` | Move sync revocation HTTP to queued job | `RevocationController` → `ProcessRevocationJob` | PKI POST + DELETE + notification now runs in background |
| P6-03 | `todo` | Queue or background file uploads for heavy enrollment assets | `ForeignerEnrollmentController::uploadFilesAsync` | Misleading name; runs inline |
| P6-04 | `todo` | Introduce `Bus::chain()` for multi-step enrollment/signing flows | Enrollment, signature finalize | No chains used today |
| P6-05 | `todo` | Restore real Kkiapay verification in `validateSubscription` | `ForeignerEnrollmentController` | Depends on P0-03 |
| P6-06 | `done` | Ensure notification paths go through Kafka (fix provider bindings) | `bootstrap/providers.php` | Fixed namespace references; `KafkaNotificationPublisherTest` now passes |

---

## P7 — PHP typing & static analysis

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P7-01 | `todo` | Add `phpstan.neon` / `phpstan.dist.neon` (Larastan level 6+) | Project root | Package installed, not configured |
| P7-02 | `todo` | Add `composer` scripts: `lint` (Pint), `analyse` (PHPStan), `test` | `composer.json` | |
| P7-03 | `todo` | Add `declare(strict_types=1)` to new files; plan rollout for `app/` | All `app/` PHP | Zero strict files today |
| P7-04 | `todo` | Add return types to `BaseController` helpers | `BaseController.php` | Foundation for all controllers |
| P7-05 | `todo` | Add return types to all controller public methods | 30 controllers | ~14 typed vs ~150 untyped |
| P7-06 | `todo` | Add return types to all Eloquent relationship methods | `app/Models/` | Only `Stamp` typed today |
| P7-07 | `todo` | Fix PHPStan baseline / ratchet (allow incremental cleanup) | CI config | Optional after P7-01 |

---

## P8 — Testing & CI

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P8-01 | `todo` | Add CI job: `php artisan test` | `.github/workflows/` | No tests run in pipeline today |
| P8-02 | `todo` | Add CI job: `./vendor/bin/pint --test` | `.github/workflows/` | |
| P8-03 | `todo` | Add CI job: `./vendor/bin/phpstan analyse` | `.github/workflows/` | After P7-01 |
| P8-04 | `todo` | Stabilize test DB config (`.env.testing` + `phpunit.xml`) | `phpunit.xml`, `.env.testing` | Avoid `root`/placeholder DB |
| P8-05 | `todo` | Add feature tests for `StructureController` critical paths | `tests/Feature/` | Untested |
| P8-06 | `todo` | Add feature tests for `UserController` critical paths | `tests/Feature/` | Untested |
| P8-07 | `todo` | Add feature tests for `AuthController` / admin auth | `tests/Feature/` | Untested |
| P8-08 | `todo` | Create `tests/Unit/` for services (when P3 extracts them) | `tests/Unit/` | Directory does not exist |

### Current test coverage (baseline)

| Area | Test file | Status |
|------|-----------|--------|
| Foreigner enrollment | `ForeignerEnrollmentControllerTest` | Partial |
| Identity review | `IdentityReviewControllerTest` | Partial |
| Kafka notifications | `KafkaNotificationPublisherTest` | Minimal |
| All other controllers (~27) | — | **Untested** |

---

## P9 — Routing & Laravel 12 hygiene

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P9-01 | `todo` | Replace route closures with invokable controllers | `routes/api.php` (`/health`, `/me`, `can-buy-*`) | Blocks `route:cache` |
| P9-02 | `todo` | Update `.github/copilot-instructions.md` to Laravel 12 | `.github/copilot-instructions.md` | Still says Laravel 10 |
| P9-03 | `todo` | Remove or register orphan `BroadcastServiceProvider` | `app/Providers/` vs `bootstrap/providers.php` | Dead file |
| P9-04 | `todo` | Review CSRF exemption scope (`/api/v1/*`) | `VerifyCsrfToken.php` | Document intentional choice |
| P9-05 | `todo` | Evaluate `route:cache` compatibility after P9-01 | Deploy docs | |

---

## P10 — Naming & documentation cleanup

| ID | Status | Item | Primary files / area | Notes |
|----|--------|------|----------------------|-------|
| P10-01 | `done` | Fix typo: `UserSubscribtion*` → `UserSubscription*` job class names | `app/Jobs/`, `app/Mail/` | Renamed 3 Jobs + 3 Mailables; updated all imports |
| P10-02 | `todo` | Remove large commented-out code blocks | `UserController`, `SignatureController`, `SigningIdentityController`, `StatsController` | |
| P10-03 | `todo` | Update OpenAPI `@OA` paths to `/api/v1/...` | All controllers with Swagger annotations | |
| P10-04 | `todo` | Expand authorization beyond route middleware (policies) | `app/Policies/` | Only 2 policies today |
| P10-05 | `todo` | Replace `Auth::user()` / `auth()->user()` with `$request->user()` | See audit list (15+ locations) | As controllers are touched |
| P10-06 | `done` | Delete unused stub Form Requests or implement them | `app/Http/Requests/` | Completed in P2-01/P2-08 |

---

## Recommended execution order

Work phases in this sequence to minimize rework and production risk:

```
P0  →  P1  →  P8 (CI skeleton)  →  P2  →  P3  →  P5  →  P6  →  P4  →  P7  →  P9  →  P10
```

| Order | Phase | Rationale |
|------:|-------|-----------|
| 1 | P0 | Unblocks migrate/deploy; fixes correctness bugs |
| 2 | P1 | Required before `config:cache` in production |
| 3 | P8 (partial) | CI test + lint early to guard refactors |
| 4 | P2 | Form Requests before splitting controllers |
| 5 | P3 | Major structural improvement |
| 6 | P5–P6 | Performance and reliability |
| 7 | P4 | API Resources after services stabilize |
| 8 | P7 | Typing/static analysis across cleaner code |
| 9 | P9–P10 | Polish |

---

## Deferred / business-dependent

| ID | Status | Item | Reason |
|----|--------|------|--------|
| D-01 | `deferred` | Reconcile foreigner IN_PERSON vs ONLINE-only flow | Business logic still in genesis |
| D-02 | `deferred` | Remove legacy `app/Mail/` and Blade templates | Awaiting notification module template parity |
| D-03 | `deferred` | Full API versioning strategy beyond `/api/v1` | Product decision |
| D-04 | `deferred` | Split monolith into bounded contexts | Out of scope for current phase |

---

## Change log

| Date | Change |
|------|--------|
| 2026-07-08 | Initial backlog created from best-practices audit |
| 2026-07-08 | P0: marked 01/02/04/05/06 done; P0-03 deferred (Kkiapay); added `.env.schema` |
| 2026-07-08 | P1 complete: `config/trustedx.php`, `config/kkiapay.php`, all app/blade env() migrated |
| 2026-07-08 | P1-01/P1-07 notes updated: single `FRONTEND_URL` only |
| 2026-07-08 | P2 complete: `ApiFormRequest`, 24 Form Requests, zero inline `Validator::make` in controllers |
| 2026-07-10 | P3 partial: `ForeignerEnrollmentService`, `IdentityReviewService`, `AdminAuthService`, `ServiceResult` |
| 2026-07-13 | P3-08, P3-09, P3-10: Decomposed AuthTrait, AttachmentTrait, and ADTrait into respective services. Traits deleted. |

---

## References

- [`guidelines.md`](./guidelines.md)
- [`laravel12bestpractices.txt`](./laravel12bestpractices.txt)
- [TatvaSoft — Laravel Best Practices](https://www.tatvasoft.com/outsourcing/2025/09/laravel-best-practices.html)
- [Smithery — Laravel 12 skill](https://smithery.ai/skills/matula/laravel-12)
