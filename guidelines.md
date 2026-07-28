# AED Foreigner Backend — Development Guidelines

This document defines the practices we **strictly follow** when building and maintaining `aed-foreigner-be`. It applies to all contributors and AI-assisted changes.

**Product source of truth** (business rules for enrollment):

- [`txdocs/Parcours d’enrolement des étrangers – vf.pdf`](./txdocs/Parcours%20d’enrolement%20des%20étrangers%20–%20vf.pdf)


Engineering sources consolidated from:

- [`laravel12bestpractices.txt`](./laravel12bestpractices.txt)
- [TatvaSoft — Laravel Best Practices](https://www.tatvasoft.com/outsourcing/2025/09/laravel-best-practices.html)
- [Smithery — Laravel 12 skill (matula/laravel-12)](https://smithery.ai/skills/matula/laravel-12)

---

## 1. Scope & stack

| Item | Standard |
|------|----------|
| Framework | Laravel **12** |
| PHP | **8.4+** |
| API prefix | `/api/v1` (configured in `bootstrap/app.php`) |
| Auth | Laravel Sanctum + Spatie Permission (roles) + **Laravel Policies** (resource actions) |
| Notifications | Kafka publisher (`KafkaNotificationPublisher`) — not direct `Mail::` in domain code |
| Service discovery | Consul (when infra is available) |

This backend is **exclusively for AED Étranger** (foreigner enrollment and identity review). Do not expand it into citizen / national enrollment flows that belong on other platforms.

Two enrollment tracks exist in the product spec:

| Track | Status in this backend |
|-------|------------------------|
| **Personne physique** | Implemented (OTP → `enrollment_requests` → agent → supervisor → finalization invite) |
| **Personne morale** | Implemented — authenticated `client` (physique finalisée) → verification gate → same review stack |

---

## 2. Laravel 12 application structure

Follow the **streamlined Laravel 12 skeleton**. Do not reintroduce legacy patterns.

| Do | Don't |
|----|-------|
| Register middleware, routing, and exceptions in `bootstrap/app.php` | Add `app/Http/Kernel.php`, `app/Console/Kernel.php`, or `RouteServiceProvider` |
| Register providers in `bootstrap/providers.php` | Put a large `providers` array in `config/app.php` |
| Define scheduled tasks in `routes/console.php` | Use `app/Console/Kernel.php` |
| Auto-register commands from `app/Console/Commands/` | Manually list every command unless required |
| Keep custom middleware classes under `app/Http/Middleware/` and alias them in `bootstrap/app.php` | Register middleware in deleted kernel files |

---

## 3. Code generation (Artisan first)

**Always** scaffold with Artisan. Do not hand-create boilerplate.

```bash
php artisan make:model EnrollmentRequest -mfsc --policy
php artisan make:request Enrollment/SubmitEnrollmentRequest
php artisan make:controller ForeignerEnrollmentController
php artisan make:controller Singletons/HealthCheckController --invokable
php artisan make:job UploadEnrollmentFilesJob
php artisan make:class Services/Enrollment/ForeignerEnrollmentService
php artisan make:resource EnrollmentRequestResource
```

When running commands in CI or automation, pass `--no-interaction`.

---

## 4. Architecture & separation of concerns

### 4.1 Thin controllers

Controllers **MUST** only:

- Accept HTTP input (`Request`, Form Requests)
- Authorize via **policies** (`$this->authorize(...)`) for resource actions
- Delegate to services, actions, or jobs
- Return typed responses (`JsonResponse`, API Resources, etc.)

Controllers **MUST NOT** contain:

- Complex business workflows
- Raw multi-step DB orchestration (use services + transactions)
- Direct third-party integration logic (Consul, Kafka, TrustedX, etc.)

Move logic to:

- `app/Services/` — domain/application services
- `app/Jobs/` — async work (`ShouldQueue`)
- `app/Actions/` — optional single-purpose invokable classes
- Eloquent model scopes — reusable query constraints

### 4.2 Form Requests for validation

**Never** validate inside controller methods with inline `Validator::make()`.

```bash
php artisan make:request Enrollment/SubmitEnrollmentRequest
```

Form Requests **MUST** include:

- `rules(): array`
- Custom messages when defaults are unclear
- `authorize(): bool` when access depends on input or role (guest enrollment requests typically `return true` and rely on OTP gates in the service)

No inline validation in controllers.

Form Requests are also the **single source of truth** for public API documentation (see §9.5). Every rule you add or omit is what appears in `/docs/api`.

### 4.3 Service classes for business logic

Extract workflows (enrollment submission, identity review, TrustedX provisioning) into dedicated services. Inject them via constructor property promotion.

```php
public function __construct(
    private readonly ForeignerEnrollmentService $enrollment,
) {}
```

### 4.4 Single-responsibility HTTP actions

For one endpoint = one action, prefer **invokable controllers** under `app/Http/Controllers/Singletons/` when appropriate.

---

## 5. PHP & typing standards

Every new or modified method **MUST** have:

- Parameter type hints
- Return type declarations
- Constructor property promotion for injected dependencies

Relationship methods on models **MUST** declare return types:

```php
public function identities(): HasMany
{
    return $this->hasMany(Identity::class);
}
```

Use the `casts()` method (not the deprecated `$casts` property) on new models:

```php
protected function casts(): array
{
    return [
        'kyc_data' => 'array',
        'documents' => 'array',
        'analysis_details' => 'array',
    ];
}
```

Additional rules:

- Always use braces for control structures, even single-line bodies
- Prefer PHPDoc for non-obvious business rules; avoid narrating obvious code
- Use curly braces and PSR-12 formatting — enforced by **Laravel Pint**

Run before commit:

```bash
./vendor/bin/pint
```

Static analysis with **Larastan** at **level 6 or higher**:

```bash
./vendor/bin/phpstan analyse
```

---

## 6. Naming conventions

| Element | Convention | Example |
|---------|------------|---------|
| Models | Singular PascalCase | `EnrollmentRequest`, `Identity` |
| Controllers | PascalCase + `Controller` | `ForeignerEnrollmentController` |
| Tables | Plural snake_case | `enrollment_requests` |
| Columns | snake_case | `assigned_agent_id` |
| Methods / variables | camelCase | `submitEnrollment`, `$enrollmentRequest` |
| Routes | kebab-case URI segments | `/foreigner/enroll`, `/management/identity-reviews/{id}/claim` |
| Config keys | snake_case | `config('notifications.topics.email')` |
| Jobs | Verb + noun + `Job` | `ForeignerFinalizedJob`, `UploadEnrollmentFilesJob` |
| Enums | PascalCase cases | `NotificationTemplate::ForeignerFinalized` |
| Policies | Model name + `Policy` | `EnrollmentRequestPolicy` |

Use **named routes** and the `route()` helper where applicable.

Prefer `Route::resource()` or grouped routes over scattered one-offs in `routes/api.php`.

---

## 7. Configuration & environment

### 7.1 Never call `env()` outside config files

```php
// ❌ FORBIDDEN in app/, routes/, resources/, tests/ (except .env bootstrap edge cases)
$brokers = env('KAFKA_BROKERS');

// ✅ REQUIRED
$brokers = config('kafka.brokers');
```

Define env vars in `config/*.php`, then read with `config()`. This survives `php artisan config:cache` in production.

### 7.2 Secrets

- **Never** hardcode API keys, tokens, or passwords
- **Never** commit `.env` — use `.env.example` / `.env.schema` / `.env.ai.md` for documentation
- **Never** read `.env` directly in tooling; use schema files for variable context

### 7.3 Cache store

For local development and migrations, prefer `CACHE_STORE=file` unless the `cache` table migration exists and runs **before** packages that flush cache on migrate (e.g. Spatie Permission).

---

## 8. Database & Eloquent

### 8.1 Prefer Eloquent over raw queries

```php
// ✅ Preferred
EnrollmentRequest::query()->where('status', 'PENDING')->get();

// ❌ Avoid unless there is a measured performance reason
DB::table('enrollment_requests')->where('status', 'PENDING')->get();
```

When raw SQL is required, **always** use parameter binding — never concatenate user input.

### 8.2 Prevent N+1 queries

**Always** eager load relationships used in loops or API collections.

Review list endpoints and exports for N+1 before merging.

### 8.3 Large datasets

**Never** use `all()` or unbounded `get()` on large tables.

Use:

- `cursor()` / lazy collections
- `chunk()` / `chunkById()` for batch processing
- Pagination for HTTP list endpoints (cap `per_page`, e.g. max 100)

### 8.4 Migrations

When **changing** a column, include **all** previous attributes or they will be lost.

One concern per migration. Name migrations descriptively.

Use factories and seeders for test and local data:

```bash
php artisan make:factory UserFactory
php artisan make:factory EnrollmentRequestFactory
```

### 8.5 Keep models focused

Models hold relationships, scopes, casts, and accessors — not orchestration logic. Move workflows to services.

### 8.6 Enrollment data separation (product rule)

Per the PDF: keep **demandes** and **identités validées** in distinct stores.

| Stage | Storage |
|-------|---------|
| Demande en instruction | `enrollment_requests` (`PENDING`, `VISIO_REQUESTED`, `APPROVED_BY_AGENT`, `RETURNED_TO_AGENT`, `REJECTED`, `APPROVED`, …) |
| Identité définitivement approuvée | `users` + `identities` (created on supervisor approval) |

Do not create `User` / `Identity` at submit time for personne physique.

---

## 9. HTTP, API & authentication

### 9.1 Authenticated user access

In controllers, **always** use:

```php
public function index(Request $request): JsonResponse
{
    $user = $request->user();
}
```

**Do not** use `auth()->user()` or `Auth::user()` in new code.

Tests may use `Sanctum::actingAs()` or project test helpers.

### 9.2 API responses

- All API routes return JSON (`ForceJsonResponse` middleware is global)
- Use **API Resources** to decouple DB shape from public JSON (e.g. `EnrollmentRequestResource`)

Standard success envelope:

```json
{
  "success": true,
  "message": "...",
  "data": { }
}
```

Use appropriate HTTP status codes; validation errors return **422**.

### 9.3 Authorization (policies first — P10-04)

**Source of truth for “can this user do this action on this resource?” is Laravel Policies**, not duplicated `role:` middleware lists.

| Layer | Responsibility |
|-------|----------------|
| `auth:sanctum` | Caller is authenticated (when required) |
| **Policy** (`$this->authorize(...)`) | Role + ownership + status rules (claim, approve, reject, supervisor actions, …) |
| Route `role:` middleware | **Transitional coarse gate only** — may remain while migrating legacy routes; must not diverge from the matching policy |

Rules for new / touched enrollment-review code:

1. Every resource action **MUST** call `$this->authorize(...)` (or Form Request `authorize()` that delegates to the policy).
2. Policies **MUST** use the canonical Spatie role names: `agent`, `responsable_de_validation`, `manager`, `administrateur_plateforme`, `auditeur`, `client`, and (placeholder) `demandeur_authentifie`.
3. Prefer expanding policies over adding more nested `role:` middleware groups.
4. Goal of **P10-04**: remove redundant `role:` checks on routes that already authorize via policies, once coverage is complete.

Spatie `UnauthorizedException` / authorization failures are rendered as JSON **403** — preserve this behavior.

### 9.4 Security baseline

- Validate and sanitize **all** input via Form Requests
- Never trust query params, headers, or file metadata without validation
- CSRF applies to stateful web routes; API uses token auth
- Enforce HTTPS in production (reverse proxy / middleware)
- Avoid raw dynamic SQL; use Eloquent or bound query builder
- Apply least-privilege DB credentials in deployment

### 9.5 API payload documentation

API docs are generated by **dedoc/scramble** from Form Request validation rules and controller PHPDoc. Do not maintain a parallel OpenAPI spec by hand.

**Minimum for every documented request body or query parameter:**

| Document | How to express it in the Form Request |
|----------|----------------------------------------|
| **Required vs optional** | `required` → required; `nullable`, `sometimes`, or omitted when not sent → optional. Do not leave a field implicit — if the client may omit it, mark it `nullable` or `sometimes`. |
| **Allowed values** | Use `Rule::in([...])` or `'in:VALUE1,VALUE2'`. Scramble renders these as enums in `/docs/api`. Never document allowed values only in prose or comments. |
| **Type / format** | Use the appropriate rule: `string`, `email`, `date`, `integer`, `boolean`, `file`, `array`, … |
| **Constraints** | `max:` / `min:` (length or numeric), `mimes:` / `max:` (kilobytes) for uploads, `confirmed` for password pairs |

**Examples (good):**

```php
'sexe' => ['required', 'string', 'in:M,F'],
'document_type' => ['required', 'string', 'in:PASSPORT,CNI_ECOWAS'],
'verso' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
'role' => ['required', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER,AUDITEUR'],
```

**Examples (bad):**

- `'document_type' => ['required', 'string']` — allowed values unknown to consumers
- Inline `$request->validate([...])` in a controller — invisible to Scramble
- `@OA\Property` annotations — removed; use Form Requests instead

**Controller-level docs (Scramble):**

- One-line PHPDoc **summary** on each action method (becomes the operation summary)
- Optional longer **description** for flow notes (OTP gates, auth requirements, …)
- `#[Group('...')]` on the controller for tag grouping in `/docs/api`

**Workflow:**

```bash
php artisan scramble:export --path=storage/api.json   # regenerate OpenAPI
# Browse http://localhost:8000/docs/api locally
```

After changing validation rules, export or refresh `/docs/api` and confirm required fields, enums, and file constraints match what the endpoint actually accepts.

---

## 10. Background processing & performance

### 10.1 Queues for slow work

Any operation that is slow, external, or retryable **MUST** be a queued job implementing `ShouldQueue`:

- Email/SMS/notifications (via Kafka jobs)
- File upload to cloud storage, Regula analysis
- Third-party API calls (TrustedX, etc.)

HTTP responses **MUST NOT** wait on these operations.

Configure sensible `$tries`, `$timeout`, and `$backoff` on jobs.

### 10.2 Job chains

For ordered multi-step workflows (e.g. after enrollment submit: upload files → Regula → confirmation email), use `Bus::chain([...])->dispatch()`.

If one step fails, subsequent steps must not run.

### 10.3 Kafka notifications

- Publish through `NotificationPublisherInterface` / `KafkaNotificationPublisher`
- Always call `->send()` on the Kafka producer builder
- Do not reintroduce `Mail::` in controllers or domain jobs for new features
- Prefer **reusing / adapting** existing Blade templates under `resources/views/emails/` before creating new ones
- Template names and payloads must match the notification module contract (`config/notifications.php`)

### 10.4 Production optimization

After deploy:

```bash
composer install --prefer-dist --no-dev -o
php artisan config:cache
php artisan route:cache    # only if no closure routes break caching
php artisan view:cache
```

Do not route-cache if closures in route files prevent it.

Monitor and fix N+1 queries and slow endpoints before scaling hardware.

---

## 11. Testing

### 11.1 Requirements

- New features **MUST** include feature tests covering happy path and primary failure modes
- Bug fixes **SHOULD** include a regression test
- Use `RefreshDatabase` for isolated DB tests
- Each test **MUST** set up its own data; no shared mutable state between tests

### 11.2 Conventions

- Use PHPUnit `#[Test]` attribute — **not** `/** @test */` docblocks (deprecated in PHPUnit 12)
- Use `$this->api('/path')` helper from `tests/TestCase.php` for `/api/v1` prefix
- Fake external systems: `Bus::fake()`, `Storage::fake()`, HTTP fakes, mock TrustedX where needed
- Assign Spatie roles in test setup when hitting authenticated management routes
- Prefer workflow-oriented feature tests for enrollment (see `PersonnePhysiqueEnrollmentWorkflowTest`)

### 11.3 Test database

- Prefer a dedicated MySQL test database (`.env.testing` or `phpunit.xml`)
- Seed only what each test needs; avoid depending on production-like fixtures
- Spatie permission tables **must** exist via migrations (do not publish migrations ad hoc inside tests)

Run suite:

```bash
php artisan test
```

---

## 12. Dependencies & packages

- Prefer Laravel built-ins before adding packages
- Evaluate third-party packages for maintenance, security, and PHP 8.4 / Laravel 12 compatibility
- Remove unused dependencies during refactors
- Pin critical integrations in `composer.json`; run `composer update` deliberately, not accidentally in production

Check new PHP dependencies for known vulnerabilities before adoption.

---

## 13. Project-specific rules (AED Étranger)

### 13.1 Personne physique enrollment (diagram §2)

Canonical HTTP flow:

```
POST /otp/send              { email, phonenumber }
POST /otp/verify            { email|phonenumber, otp }
POST /kyc/verify            multipart liveness + documents (sync Regula/KYC tier)
POST /enrolements/etrangers multipart KYC + documents → 202 { demande_id, statut: EN_ATTENTE }
```

Business rules:

- Email **and** phone are mandatory and **both** must be OTP-verified before submit (PDF §2).
- Submit creates `enrollment_requests` with `type = PERSONNE_PHYSIQUE`, `status = EN_ATTENTE`.
- Do **not** create `User` / `Identity` / NPI at submit time.
- After submit: queue cloud upload + Regula analysis; send confirmation using the existing foreigner finalized notification template (reuse, do not invent a parallel “advanced id request” mail for this path).
- Guest endpoints; no Sanctum token required for OTP/enroll.

### 13.2 Agent / responsable review (diagram §§3.1–3.2)

Status machine:

```
EN_ATTENTE → VALIDATION_AGENT | REJET_AGENT        (PATCH .../instruction)
VALIDATION_AGENT → APPROUVEE | EN_ATTENTE          (PATCH .../validation)
REJET_AGENT → REJETEE | EN_ATTENTE                 (PATCH .../validation)
APPROUVEE → ENROLEE                                (POST .../finalisation)
```

Staff routes:

```
GET   /enrolements?statut=EN_ATTENTE                              (agent default)
GET   /enrolements?statut=VALIDATION_AGENT|REJET_AGENT            (responsable default when statut omitted)
GET   /enrolements/{id}
PATCH /enrolements/{id}/prise-en-charge                           (agent self-assign)
PATCH /enrolements/{id}/prise-en-charge-validation                (responsable self-assign)
PATCH /enrolements/{id}/instruction   { statut: VALIDATION_AGENT|REJET_AGENT, motif?, commentaire? }
PATCH /enrolements/{id}/validation    { decision: APPROUVEE|REJET_CONFIRME|RETOUR_AGENT, commentaire?, motif? }
```

Responsable list columns (`EnrollmentDecisionListResource`): agent, date_decision (`agent_decided_at`), statut, responsable.

Responsable detail includes `decision_agent` (Approuvé/Rejeté, agent, date, motifs, description), `peut_prendre_en_charge`, `peut_valider`.

Decision mapping (responsable buttons):

| Current status | Approuver | Rejeter la décision |
|----------------|-----------|---------------------|
| `VALIDATION_AGENT` | `APPROUVEE` | `RETOUR_AGENT` (+ motif[], commentaire?) |
| `REJET_AGENT` | `REJET_CONFIRME` | `RETOUR_AGENT` (+ motif[], commentaire?) |

Staff auth:

```
POST /admin/login              { email, password } → access_token + must_change_password
POST /admin/password/change    { current_password, password, password_confirmation }  (auth)
POST /agents/register          { name, first_name, email, phonenumber, role }  (admin)
```

Staff registration creates user with generated default password (emailed); `must_change_password=true` until first change via `/admin/password/change`.

Role mapping (PDF → Spatie):

| PDF | Spatie role |
|-----|-------------|
| Agent de traitement | `agent` |
| Responsable de validation | `responsable_de_validation` |
| Manager (dashboard + SLA L3) | `manager` |
| Administrateur de la plateforme | `administrateur_plateforme` |
| Étranger enrôlé / portail | `client` |
| Demandeur authentifié (morale, placeholder) | `demandeur_authentifie` |
| Auditeur (compliance extension) | `auditeur` |

Staff registration API codes (`POST /agents/register`): `AGENT`, `RESPONSABLE_DE_VALIDATION`, `MANAGER`, `AUDITEUR`. Platform admin is created via `php artisan manage:admin` only.

- **Prise en charge:** agent-only `PATCH .../prise-en-charge` sets `assigned_agent_id` when null and status `EN_ATTENTE`. No assign-to-other-agent.
- **Prise en charge validation:** responsable-only `PATCH .../prise-en-charge-validation` sets `assigned_responsable_id` when null and status `VALIDATION_AGENT` or `REJET_AGENT`. No assign-to-other.
- **Instruction:** agent must be the assigned agent; `REJET_AGENT` requires validated `motif[]` + optional `commentaire`; sets `reject_stage=AGENT` and `agent_decided_at`.
- **Validation:** responsable must be the assigned responsable; `RETOUR_AGENT` requires validated `motif[]` + optional `commentaire`; sets `return_reasons`, `reject_stage=RESPONSABLE`, clears `assigned_agent_id` and `assigned_responsable_id`.
- Responsable `APPROUVEE`: local User + NPI + Identity + **TrustedX register** + finalisation invite.
- Responsable `REJET_CONFIRME`: `REJETEE` + applicant email.
- Responsable `RETOUR_AGENT`: back to `EN_ATTENTE`, clears `assigned_agent_id`.
- Manager: `GET /management/enrollment-stats` only; SLA level 3 notifies `manager`.
- Reject motifs: `GET /management/enrollment-reject-motifs`.
- Show attaches heuristic `similar_enrollments`.
- SLA: `enrollment:check-sla` hourly.
- Authorization: `EnrollmentRequestPolicy` (see §9.3).

### 13.3 Finalization (diagram §4)

```
GET  /enrolements/finalisation?token=
POST /enrolements/{id}/finalisation  { token, password, pin, security_questions? }
```

- TrustedX identity already registered at responsable `APPROUVEE`; finalisation is **update-only** (password/PIN/questions).
- Sets user `ACTIVE`, enrollment `ENROLEE`; publishes `enrolement.completed`.

### 13.4 Personne morale enrollment (PDF §4)

Prerequisites: authenticated `client` with finalized physique enrollment (`ENROLEE`).

Canonical HTTP flow:

```
POST /enrolements/morales                              (auth:sanctum + client)
GET  /enrolements/morales/{id}                         (owner only)
POST /enrolements/morales/{id}/verify-email            (token from email link)
POST /enrolements/morales/{id}/send-phone-otp            (owner only)
POST /enrolements/morales/{id}/verify-phone-otp          (owner only)
```

After contact verified → `EN_ATTENTE`; same instruction/validation contract as physique.

**Agent backoffice:** list and detail use `GET /enrolements` and `GET /enrolements/{id}` with `?type=PERSONNE_MORALE`. List `demandeur` = `submitted_by` user (demandeur authentifié). Detail returns `informations_entreprise` + `pieces_jointes`. Client tracking uses `GET /enrolements/morales/{id}` only.

On submit: assign Spatie role `demandeur_authentifie` to submitter (enterprise manager, distinct from staff `manager` role).

Company fields stored in `enrollment_requests.kyc_data` (`type = PERSONNE_MORALE`):

| Field | PDF | Required |
|-------|-----|----------|
| `legal_name` | Raison sociale | yes |
| `legal_form` | Forme juridique | no |
| `country_of_incorporation` | Pays d'origine / immatriculation | yes |
| `registration_number` | Numéro d'immatriculation légal | yes |
| `incorporation_date` | Date de création | no |
| `headquarters_address` | Adresse du siège social | yes |
| `activity_sector` | Secteur d'activité | yes |
| `legal_representative_name` | Nom du représentant légal | yes |
| `legal_representative_first_name` | Prénoms du représentant légal | yes |
| `is_legal_representative` | Demandeur = représentant légal | yes |

Documents (`documents` JSON): `trade_register_extract` (required), `statutes` (optional), `procuration` (required when `is_legal_representative = false`).

Contact: `email` / `phonenumber` on the row = **official company** email and phone. Verified asynchronously after submit (email link, then SMS OTP) before the demande enters the agent queue.

Status machine (morale-specific gate):

```
AWAITING_CONTACT_VERIFICATION → EN_ATTENTE → … (same as physique review)
APPROUVEE → (PSCEQ deferred; Identity type PERSONNE_MORALE created on responsable approve)
```

On responsable approve: **do not** create a new `User`; create `Identity` with `type = PERSONNE_MORALE` linked to `submitted_by_user_id`. No TrustedX / PSCEQ in this phase.

### 13.5 Espace administrateur (backoffice)

Role: `administrateur_plateforme` only (created via `php artisan manage:admin`, not `POST /agents/register`).

```
POST /admin/login
GET  /agents? q, role, per_page          → StaffUserListResource
GET  /agents/{id}                        → StaffUserDetailResource
POST /agents/register                    → create staff (AGENT|RESPONSABLE_DE_VALIDATION|MANAGER|AUDITEUR)
POST /agents/{id}                        → update staff
DELETE /agents/{id}                      → delete staff
GET  /admin/activity-logs? q, action, from, to, per_page   → journaux métier (action, description, date)
GET  /admin/enrolled-persons? q, per_page                  → personnes enrôlées (read-only)
GET  /admin/enrolled-persons/{id}                          → détail read-only
GET  /audits                                               → journal OwenIt (compliance / auditeur)
```

- Login sets `users.last_login_at`.
- Staff list excludes `administrateur_plateforme`; role column uses UI codes (`AGENT`, `RESPONSABLE_DE_VALIDATION`, …).
- **Journaux** (`activity_logs`): business actions (demande, validation agent/responsable, création utilisateur, finalisation). Distinct from `GET /audits` (model CRUD audit trail).
- **Personnes enrôlées**: clients `ACTIVE` with enrollment `ENROLEE` / `PERSONNE_PHYSIQUE`; no write endpoints.

### 13.6 Explicitly out of current API scope

Do not pretend these exist in code without implementing them:

- Real **videoconferencing** product (Zoom/Meet) — only workflow status/notes/notification
- Kafka topic / object-storage hardening for local dev
- **PSCEQ / professional certificate** acquisition for personne morale
- Reopen of a rejected demande (applicant submits a **new** demande)
- SLA thresholds admin UI (env/config only for now)
- National company registry auto-check beyond duplicate detection on `registration_number` + `country_of_incorporation`

### 13.7 Infrastructure integration

- **Consul**: register/deregister via artisan commands; config in `config/consul.php`
- **Kafka**: config in `config/kafka.php` and `config/notifications.php`
- Gracefully handle missing local infra (Consul/Kafka offline in dev) without breaking unrelated tests

### 13.8 Legacy code

`app/Mail/` and `resources/views/emails/` remain reference material during the Kafka migration. Prefer Kafka notification jobs + existing Blade templates. Do not build new features on `Mail::` facades.

Legacy citizen B2B modules (`Structure*`, subscriptions, signatures, entity attachments, employee invitations) have been **removed** from this backend. Do not reintroduce them here; personne morale enrollment will use `enrollment_requests` when implemented.

---

## 14. Code review checklist

Before opening or approving a PR, verify:

- [ ] Behavior matches PDF/`pics` for the touched enrollment path (or documents a deliberate gap)
- [ ] Controller is thin; validation is in Form Requests
- [ ] Form Request rules document every field: required/optional, type, allowed values (`in:`), and upload constraints where applicable (§9.5)
- [ ] Resource actions use **policies** (`authorize`); role middleware does not contradict the policy
- [ ] No `env()` outside `config/`
- [ ] No secrets in code or commits
- [ ] Types on all new/changed methods and relationships
- [ ] Eager loading where relationships are accessed
- [ ] Slow/external work dispatched to queues / `Bus::chain` where ordered
- [ ] API changes use Resources and `/api/v1` paths
- [ ] `$request->user()` used instead of auth facades
- [ ] Emails: reuse/adapt `resources/views/emails/**` before adding templates
- [ ] Tests added/updated; `php artisan test` passes
- [ ] Pint (and Larastan when configured) clean on touched files
- [ ] Migrations reversible and safe for existing data

---

## 15. References

| Resource | URL / path |
|----------|------------|
| Enrollment parcours (product SoT) | [`txdocs/Parcours d’enrolement des étrangers – vf.pdf`](./txdocs/Parcours%20d’enrolement%20des%20étrangers%20–%20vf.pdf) |
| UI references | [`pics/`](./pics/) |
| Internal engineering notes | [`laravel12bestpractices.txt`](./laravel12bestpractices.txt) |
| Refactor backlog | [`REFACTOR_BACKLOG.md`](./REFACTOR_BACKLOG.md) |
| TatvaSoft Laravel practices | https://www.tatvasoft.com/outsourcing/2025/09/laravel-best-practices.html |
| Smithery Laravel 12 skill | https://smithery.ai/skills/matula/laravel-12 |
| Laravel 12 docs | https://laravel.com/docs/12.x |
| Laravel Kafka (notifications) | https://laravelkafka.com/docs/v2.11 |

When guidelines conflict with legacy code, **follow this document for all new work** and refactor touched legacy code toward these standards incrementally. When this document conflicts with the enrollment PDF/`pics`, **update this document** — do not silently diverge from the product SoT.
