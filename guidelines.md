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

**Exception — temporary TrustedX call logging.** `TrustedXClientService` logs every outbound TrustedX HTTP call at INFO (`TrustedX call`), including password/PIN and access_token, so live finalisation/approval can be verified in `storage/logs`. Kill switch: `TRUSTEDX_LOG_CALLS` (`config('trustedx.log_calls')`, default `false`). Set `true` only while debugging; remove `logCall()` and its call sites when debugging is done.

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
| Demande en instruction | `enrollment_requests` (`EN_ATTENTE_AGENT`, `EN_COURS_AGENT`, `EN_ATTENTE_RESPONSABLE`, `EN_COURS_RESPONSABLE`, `APPROUVEE`, `REJETEE`, …) |
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
2. Policies **MUST** use the canonical Spatie role names: `agent`, `responsable_de_validation`, `manager`, `administrateur_plateforme`, `client`, and (placeholder) `demandeur_authentifie`.
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

**Query parameters (GET / list / filter endpoints):**

List and search endpoints **MUST** use a Form Request for query params — same rules as body fields. Scramble documents them as query parameters in `/docs/api`.

```php
// ListAgentsRequest — GET /agents
'q' => ['nullable', 'string', 'max:255'],
'role' => ['nullable', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER'],
'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
'order_by' => ['nullable', 'string', 'in:created_at,name,email,last_login_at'],
'order_dir' => ['nullable', 'string', 'in:asc,desc'],
```

Every filter the client may send must appear in the Form Request. Do not accept undocumented query params in the service layer.

**Server-side defaults:**

When the API applies a default the client can omit, document it in the controller method **description** (PHPDoc), not only in service code:

```php
/**
 * List staff users (admin only)
 *
 * Query defaults: per_page=15, order_by=created_at, order_dir=desc.
 */
```

If behavior differs when a param is absent vs. sent empty, state that explicitly in the description.

**Examples and field semantics:**

| Need | Where to document |
|------|-------------------|
| Allowed values | `in:` / `Rule::in()` in the Form Request (Scramble renders enums) |
| Date / ID / token format | Controller **description**, e.g. `date_of_birth` as `YYYY-MM-DD`, reset `token` from the email link |
| Ambiguous field names | Controller **description** or custom `messages()` on the Form Request, e.g. `q` = free-text search, `type` = enrollment type not user type, `statut` vs `status` |
| Full multipart / multi-step flows | Optional example block in controller **description**; Form Request rules remain the source of truth |

**Examples (good):**

```php
'sexe' => ['required', 'string', 'in:M,F'],
'document_type' => ['required', 'string', 'in:PASSPORT,CNI_ECOWAS'],
'verso' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
'role' => ['required', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER'],
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

After changing validation rules, export or refresh `/docs/api` and confirm required fields, enums, query params, defaults (in descriptions), and file constraints match what the endpoint actually accepts.

---

## 10. Background processing & performance

### 10.1 Queues for slow work

Any operation that is slow, external, or retryable **MUST** be a queued job implementing `ShouldQueue`:

- Email/SMS/notifications (via Kafka jobs)
- File upload to cloud storage, Regula analysis
- Third-party API calls that can complete after the HTTP response

HTTP responses **MUST NOT** wait on these operations.

**Exception — TrustedX at finalisation (and register at approval).** `POST /enrolements/finalisation` calls TrustedX `getUserWithNPI` + `setDefaultPassword` (password and generated PIN) **in the HTTP request**, then returns `200` with `statut ENROLEE`. The applicant must not see ENROLEE before the TrustedX secret exists; the password must not sit in a queue payload. The same exception applies to TrustedX `register` on responsable `APPROUVEE`. Temporary call logging for these HTTP TrustedX calls is the §7.2 exception.

**Exception — synchronous third-party gates that must finish before the HTTP response.** These stay in the request (always with an explicit HTTP timeout; TLS verify on):

- KYC / Regula on `POST /kyc/verify` and `POST /kyc/document/read` — the enrollment gate must accept or reject before persist
- Keycloak JWKS fetch on token validation (cached 1 hour per URI)
- ANIP lookup on `POST /clients/send-otp`
- TrustedX `obtainToken` / `userInfo` on client login — the token is returned in the same response

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
POST /otp/verify            { email|phonenumber|both, otp }
POST /kyc/document/read     multipart recto (+ verso?) — assisted pre-read, no gate
POST /kyc/verify            multipart selfie + recto (+ verso?) after OTP gate; optional `capture_le` (ISO-8601 instant with timezone, within last 60 min / next 5 min; default = verification time)
POST /enrolements/etrangers multipart KYC + documents → 202 { demande_id, numero_suivi, statut: EN_ATTENTE_AGENT, email_verifie, telephone_verifie }
POST /enrolements/suivi     { numero_suivi, email }  (guest tracking; 404 if the pair does not match)
```

Business rules:

- Email **and** phone are mandatory and **both** must be OTP-verified before submit (PDF §2). Persist `email_verified_at` / `phone_verified_at` on the demande at submit. Expose `email_verifie` / `telephone_verifie` on submit 202, `POST /enrolements/suivi`, and agent/responsable/manager detail (same keys as personne morale).
- Submit creates `enrollment_requests` with `type = PERSONNE_PHYSIQUE`, `status = EN_ATTENTE_AGENT`, and a unique **`numero_suivi`** (`tracking_code`, format `PK…`) shown on the success screen.
- Do **not** create `User` / `Identity` / NPI at submit time.
- After submit: queue cloud upload + Regula analysis; send confirmation email including `numero_suivi`.
- Guest endpoints; no Sanctum token required for OTP/enroll.
- After submit the demandeur tracks with **`POST /enrolements/suivi`** `{ numero_suivi, email }` (body, not query string). Email must match the enrollment row (physique) or the official company email **or** `submitted_by` email (morale). Same 404 (`Demande introuvable`) for unknown code and wrong email. Response uses demandeur `statut_libelle` only — no `analyse_kyc`, avis agent, or documents. `finalisation_disponible` is true only when physique is `APPROUVEE`. `motifs` on `A_CORRIGER` / `REJETEE` is `{ id, title, description }[]` resolved from UUID reject motifs. Throttle `enrollment-suivi` (10/min per IP + numero_suivi).
- Optional `capture_le` on `POST /kyc/verify` (and as submit fallback): ISO-8601 instant with timezone, within the last 60 minutes and at most 5 minutes in the future. Omitted → KYC verification time. Persisted on `enrollment_requests.selfie_captured_at` (not inside Regula `analysis_details`). Agent/responsable detail exposes it as `analyse_kyc.selfie.capture_le`.
- `POST /kyc/document/read` is **assistive only**: it pre-fills the identity form and warns about an unusable photo on the capture screen. No OTP gate, nothing persisted, always 200 when well formed (`ok: false` + `quality_issues` on an unreadable photo). It exists so the browser never calls the Regula server directly — that would require opening the Regula server's CORS and would let any visitor burn licensed transactions outside our API. `POST /kyc/verify` stays the authoritative check and replays the read with the same scenario.

### 13.2 Agent / responsable review (diagram §§3.1–3.2)

**Position ≠ décision (règle structurante).** `enrollment_requests.status` décrit **où** est la demande, jamais **ce qui a été décidé**. L'avis de l'agent vit dans sa propre colonne `agent_avis` (`FAVORABLE` | `DEFAVORABLE` | `null`). Un statut ne doit jamais permettre à un niveau de lire le verdict d'un autre : l'ancien `VALIDATION_AGENT` signifiait à la fois « chez le responsable » et « l'agent a approuvé », et le responsable découvrait donc ses dossiers « approuvés » avant d'avoir agi.

> Un niveau ne voit un verdict que s'il est **le sien** ou s'il est **définitif pour tout le dossier**.

Status machine (positionnelle) :

```
EN_ATTENTE_AGENT → EN_COURS_AGENT                        (PATCH .../prise-en-charge)
EN_COURS_AGENT → EN_ATTENTE_RESPONSABLE                  (PATCH .../instruction, écrit agent_avis)
EN_ATTENTE_RESPONSABLE → EN_COURS_RESPONSABLE            (PATCH .../prise-en-charge-validation)
EN_COURS_RESPONSABLE → APPROUVEE                         (validation, exige agent_avis=FAVORABLE)
EN_COURS_RESPONSABLE → REJETEE                           (validation, exige agent_avis=DEFAVORABLE)
EN_COURS_RESPONSABLE → EN_ATTENTE_AGENT                  (RETOUR_AGENT, remet agent_avis à null)
APPROUVEE → ENROLEE                                      (finalisation OTP + password flow)
```

Staff routes:

```
GET   /enrolements?statut=EN_ATTENTE_AGENT|EN_COURS_AGENT              (agent default)
GET   /enrolements?statut=EN_ATTENTE_RESPONSABLE|EN_COURS_RESPONSABLE  (responsable default when statut omitted)
GET   /enrolements?avis=FAVORABLE|DEFAVORABLE                          (filtre sur l'avis agent)
GET   /enrolements/{id}
PATCH /enrolements/{id}/prise-en-charge                           (agent self-assign)
PATCH /enrolements/{id}/prise-en-charge-validation                (responsable self-assign)
PATCH /enrolements/{id}/instruction   { avis: FAVORABLE|DEFAVORABLE, motif?, commentaire? }
PATCH /enrolements/{id}/validation    { decision: APPROUVEE|REJET_CONFIRME|RETOUR_AGENT, commentaire?, motif? }
```

`motif[]` values are **UUID ids** from `GET /management/enrollment-reject-motifs` (not string codes).

`GET /enrolements/{id}` `analyse_kyc.document_identite` is **OCR-only** (never form `kyc_data`). After a successful `POST /kyc/verify`, OCR is stored in `analysis_details.document.ocr` (every Regula text container, recto + verso, first-wins). Canonical keys: `type_piece`, `pays`, `verifie`, `numero_document`, `nom`, `prenoms`, `date_naissance`, `nationalite`, `date_expiration`, `sexe`, `date_emission`, `lieu_naissance`, `autorite`, `numero_personnel`, `nom_complet`. Any other extracted Regula text field is merged on the same object, including MRZ, address, checksums, and check digits. `informations` / `informations_entreprise` remain the declared form. The identity panel is empty only when no identity OCR bag exists — it does not fall back to the form. `analyse_kyc.selfie.capture_le` is the ISO-8601 value of `enrollment_requests.selfie_captured_at`.

**Libellés contextuels.** Toute ressource de demande expose `statut` (machine) **et** `statut_libelle`, calculé par `EnrollmentStatusPresenter` selon le rôle de l'appelant. Le statut machine est unique ; seul le mot change :

| Statut | Agent | Responsable | Manager / admin | Demandeur |
|--------|-------|-------------|-----------------|-----------|
| `EN_ATTENTE_AGENT` | À traiter | En instruction | En attente d'un agent | En cours de traitement |
| `EN_COURS_AGENT` | En cours d'instruction | En instruction | En cours d'instruction | En cours de traitement |
| `EN_ATTENTE_RESPONSABLE` | Transmise au responsable | **À valider** | En attente du responsable | En cours de traitement |
| `EN_COURS_RESPONSABLE` | Transmise au responsable | En cours de validation | En cours de validation | En cours de traitement |
| `APPROUVEE` / `REJETEE` / `ENROLEE` | Approuvée / Rejetée / Enrôlée (identique pour tous : le verdict est définitif) |

Responsable list columns (`EnrollmentDecisionListResource`): agent, date_decision (`agent_decided_at`), statut, statut_libelle, `avis_agent`, responsable, `pris_en_charge_par_moi`. Personne morale rows also expose `raison_sociale`, `pays_origine`, `numero_suivi` (null on physique except `numero_suivi`). Agent list (`EnrollmentRequestListResource`) uses the same three company columns.

Responsable detail includes `decision_agent` (`avis` **nullable** + `avis_libelle`, agent, date, motifs `{ id, title, description }`, description), `peut_prendre_en_charge`, `peut_valider`. `avis` vaut `null` tant que l'agent n'a pas instruit — ne jamais retomber sur une valeur par défaut, qui annoncerait une décision que personne n'a prise.

Decision mapping (responsable buttons), depuis `EN_COURS_RESPONSABLE` uniquement :

| `agent_avis` | Approuver | Rejeter la décision |
|--------------|-----------|---------------------|
| `FAVORABLE` | `APPROUVEE` | `RETOUR_AGENT` (+ motif[] UUID, commentaire?) |
| `DEFAVORABLE` | `REJET_CONFIRME` | `RETOUR_AGENT` (+ motif[] UUID, commentaire?) |

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

Staff registration API codes (`POST /agents/register`): `AGENT`, `RESPONSABLE_DE_VALIDATION`, `MANAGER`. Platform admin is created via `php artisan manage:admin` only.

- **Prise en charge:** agent-only `PATCH .../prise-en-charge` sets `assigned_agent_id` when null and status `EN_ATTENTE_AGENT`, then moves the request to `EN_COURS_AGENT` — la prise en charge doit être lisible dans le statut. No assign-to-other-agent.
- **Prise en charge validation:** responsable-only `PATCH .../prise-en-charge-validation` sets `assigned_responsable_id` when null and status `EN_ATTENTE_RESPONSABLE`, then moves the request to `EN_COURS_RESPONSABLE`. No assign-to-other.
- **Instruction:** agent must be the assigned agent and the request must be `EN_COURS_AGENT`; `avis=DEFAVORABLE` requires validated `motif[]` + optional `commentaire`; sets `agent_avis`, `reject_stage=AGENT` and `agent_decided_at`. L'agent rend un **avis**, il ne tranche pas.
- **Validation:** responsable must be the assigned responsable and the request must be `EN_COURS_RESPONSABLE`; `RETOUR_AGENT` requires validated `motif[]` + optional `commentaire`; sets `return_reasons`, `reject_stage=RESPONSABLE`, clears `assigned_agent_id`, `assigned_responsable_id`, `agent_avis` et `agent_decided_at` — le retour annule l'avis rendu.
- Responsable `APPROUVEE`: local User + **NPI (10 digits, sequential in `1000000001`–`1999999999`; starts with a digit, no `F-` prefix)** + Identity + **TrustedX register** + finalisation invite email containing **`numero_suivi`**, **NPI**, and a **secure link** (`FRONTEND_URL/etranger/finalisation?token=`). The NPI is in the email body, not the URL. After opening the link the applicant **types the generated NPI** (not `numero_suivi` / demande code).
- Responsable `REJET_CONFIRME`: `REJETEE` + applicant email.
- Responsable `RETOUR_AGENT`: back to `EN_ATTENTE_AGENT`, clears `assigned_agent_id` and the agent's avis.
- Manager (read-only supervision; SLA level 3 notifies `manager`):

```
GET /management/enrollment-stats                         (?granularite=semaine|mois)
GET /management/enrollment-reject-motifs
GET /management/enrolements/physiques
GET /management/enrolements/physiques/{id}
GET /management/enrolements/morales
GET /management/enrolements/morales/{id}
```

  Manager lists default to every **listable** status (not the agent queue). `{id}` of the wrong type → 404. List rows expose `agent`, `responsable`, `delai_ecoule_jours`. Detail is identity + `pieces_jointes` only (no KYC analysis, no instruction). `GET /enrolements` remains the agent/responsable queue. Owner `GET /enrolements/morales` is unchanged.
- Reject motifs (list for reviewers): `GET /management/enrollment-reject-motifs` → `{ id, title, description }`.
- Show attaches heuristic `similar_enrollments`; agent and responsable detail resources expose it (PDF §5.1 morale cross-check; physique uses the same key).
- SLA: `enrollment:check-sla` hourly.
- Authorization: `EnrollmentRequestPolicy` (see §9.3).

### 13.3 Finalization (diagram §4)

FE-aligned flow after invitation email (secure link opens the finalisation UI; applicant **types the generated NPI**, then email OTP, then password + security questions):

```
GET  /enrolements/finalisation?token=[&npi=]          (éligibilité; npi optional/legacy, must match token; returns demande_id, numero_suivi, email, statut — not npi)
POST /enrolements/finalisation/otp/send               { npi, token }
POST /enrolements/finalisation/otp/verify             { npi, token, otp }
POST /enrolements/finalisation                        { npi, token, password, security_questions }
```

Rules:

- Prérequis: statut `APPROUVEE`; User + TrustedX already created at responsable approval.
- `POST …/otp/send` / `…/otp/verify` / `POST /enrolements/finalisation`: **guest-capable** (no Sanctum required; an existing session does not block). **NPI + invitation token** required together (sequential NPIs must not send OTP alone). `npi` is digits only; `otp` is 6 digits. Email OTP is AED, distinct from TrustedX login MFA. Typed NPI must match the invitation — `numero_suivi` is not accepted.
- After successful OTP verify: short-lived cache proof keyed by NPI (like KYC gate).
- Finalize requires matching `npi` + `token` + OTP proof; `password` + two `security_questions` required; **no client PIN** — server generates a 4-digit PIN and sets TrustedX password/PIN **in this HTTP request** (exception to §10.1).
- Sets user `ACTIVE`, enrollment `ENROLEE`; publishes `enrolement.completed`.
- Post-enrollment auth OTP (2FA) is **TrustedX-only** — not an AED OTP flow.

### 13.4 Personne morale enrollment (PDF §4)

Prerequisites: authenticated `client` with an approved `IN_PERSON` identity (`ACTIVE`). KYC/Regula (demandeur identity document + selfie) must succeed before submit — OTP is skipped for that client; session cached ~30 min on the user.

Canonical HTTP flow:

```
POST /kyc/document/read                                (assistive OCR; no OTP)
POST /kyc/verify                                       (auth client: no OTP; guest physique: OTP first; optional capture_le)
POST /enrolements/morales                              (auth:sanctum + client; returns numero_suivi PKI…)
GET  /enrolements/morales                              (owner list — Mes entreprises)
GET  /enrolements/morales/{id}                         (owner only: company fields + pièces jointes)
POST /enrolements/suivi                                (guest: numero_suivi + email — physique or morale)
PUT  /enrolements/morales/{id}                         (owner, statut A_CORRIGER: corriger champs + pièces)
POST /enrolements/morales/{id}/verify-email            (token from email link, public)
POST /enrolements/morales/{id}/send-phone-otp            (owner only)
POST /enrolements/morales/{id}/verify-phone-otp          (owner only)
```

After **both** company contacts verified → `EN_ATTENTE_AGENT` and confirmation email to the **demandeur** (`submittedBy.email`) with `numero_suivi`. The 24h verification link goes to the official company email at submit. Same instruction/validation contract as physique afterwards.

**Agent backoffice:** list and detail use `GET /enrolements` and `GET /enrolements/{id}` with `?type=PERSONNE_MORALE`. List `demandeur` = `submitted_by` user (demandeur authentifié); list also exposes `raison_sociale`, `pays_origine`, `numero_suivi`. Detail returns `informations_entreprise` + `pieces_jointes` + `analyse_kyc` (OCR/selfie of the demandeur — not company `kyc_data`) + `similar_enrollments`. Authenticated owner tracking uses `GET /enrolements/morales` and `GET /enrolements/morales/{id}`. Guest tracking (numero_suivi + email) uses `POST /enrolements/suivi` for both types.

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

Documents (`documents` JSON): `trade_register_extract` (required), `statutes` (optional), `procuration` (required when `is_legal_representative = false`), plus `selfie` / `recto` / `verso` from the KYC step (agent `analyse_kyc`).

Contact: `email` / `phonenumber` on the row = **official company** email and phone. Verified asynchronously after submit (email link, then SMS OTP) before the demande enters the agent queue. Confirmation of submission is sent to the demandeur only after both verifications.

Status machine (morale-specific gate):

```
AWAITING_CONTACT_VERIFICATION → EN_ATTENTE_AGENT → … (same as physique review)
APPROUVEE → Identity PERSONNE_MORALE + enrolled_companies (identifiant `PM…`)
REJET_CONFIRME (morale) → A_CORRIGER (délai config, défaut 7 jours) → PUT correction → EN_ATTENTE_AGENT
                         ↘ délai dépassé → REJETEE (archivage)
```

On responsable approve: **do not** create a new `User` / TrustedX. Insert `enrolled_companies` (source of vérité entreprise enrôlée, `identifiant` = `PM` + 9 caractères) and `Identity` `PERSONNE_MORALE` on `submitted_by_user_id`. Proof JSON includes `enrolled_company_id` + `identifiant`. Emails go to **demandeur** (`submittedBy.email`) **and** official company email. Response includes `identifiant`. Owner list/detail expose `identifiant` (null until approved) and `statut_libelle`.

Duplicate submit is blocked against `enrolled_companies` (`ACTIVE`, `registration_number` + `country_of_incorporation`) plus leftover `APPROUVEE` demandes without a company row.

Morale `REJET_CONFIRME` is **not** final: statut `A_CORRIGER`, mail demandeur with motifs + `correction_deadline_at`. Owner `PUT /enrolements/morales/{id}` updates company fields + pièces (not official email/phone, not KYC selfie) and returns the demande to `EN_ATTENTE_AGENT`. `enrollment:check-sla` reminds the demandeur 24 h before the deadline, then archives `REJETEE` and mails the assigned agent. Physique `REJET_CONFIRME` stays immediate `REJETEE`. `RETOUR_AGENT` emails the assigned agent (`EnrollmentReturnedToAgent`).

No TrustedX / PSCEQ in this phase.

### 13.5 Espace client (post-TrustedX)

Role: `client` (enrolled foreigner). TrustedX owns NPI + password (and optional MFA). AED exchanges the OAuth `code` for a Sanctum session.

```
POST /clients/login                      { code }  → Sanctum token + pki_token (existing)
POST /mobile/login                       { code }
GET  /me                                 profil + identites (id, type, statut, niveau, date)
                                         + questions_secretes_configurees + pin_configure
POST /clients/logout                     révoque le token Sanctum courant
POST /clients/password/change            { current_password, password, password_confirmation }
POST /clients/pin/change                 { current_pin, pin, pin_confirmation }  (exactly 4 digits)
GET  /clients/security-questions         questions without answers
PUT  /clients/security-questions         { current_password, security_questions }
```

- PIN is still **generated server-side at finalisation**; the client may change it later with the current PIN.
- Password/PIN changes dual-write TrustedX and a local hash (`users.password` / `users.pin_hash`). If the local hash is missing (legacy enrollments), return 422 and tell the caller to use the e-mail reset.
- Changing the password revokes all Sanctum tokens.
- Client activity **history** is out of this lot.

### 13.6 Espace administrateur (backoffice)

Role: `administrateur_plateforme` only (created via `php artisan manage:admin`, not `POST /agents/register`).

```
POST /admin/login
GET  /agents? q, role, per_page          → StaffUserListResource
GET  /agents/{id}                        → StaffUserDetailResource
POST /agents/register                    → create staff (AGENT|RESPONSABLE_DE_VALIDATION|MANAGER)
POST /agents/{id}                        → update staff
DELETE /agents/{id}                      → delete staff
GET  /admin/activity-logs? q, action, from, to, per_page   → journaux métier UI (défaut per_page=20)
GET  /admin/activity-logs/{id}                             → détail (actor, metadata, enrollment_request_id, ip_address)
GET  /admin/enrolled-persons? q, per_page                  → personnes enrôlées (read-only)
GET  /admin/enrolled-persons/{id}                          → détail read-only
POST /admin/enrollment-reject-motifs                       { title, description }
GET  /admin/enrollment-reject-motifs/{id}
PATCH /admin/enrollment-reject-motifs/{id}                 { title?, description? }
DELETE /admin/enrollment-reject-motifs/{id}                hard delete
GET  /audits                                               → journal OwenIt technique (admin only)
```

- Login sets `users.last_login_at`.
- Staff list excludes `administrateur_plateforme`; role column uses UI codes (`AGENT`, `RESPONSABLE_DE_VALIDATION`, …).
- **Journaux métier** (`activity_logs` → « Historique des actions ») : événements métier/sécurité exhaustifs ; **lecture admin only**.
- **OwenIt** (`audits`) : diffs techniques sur modèles `Auditable` (`User`, `Identity`, `EnrollmentRequest`, `EnrollmentRejectMotif`, `EnrolledCompany`, `OTP`, `PasswordResetToken`) ; lecture admin only. Ne remplace pas `activity_logs`.
- **Personnes enrôlées**: clients `ACTIVE` with enrollment `ENROLEE` / `PERSONNE_PHYSIQUE`; no write endpoints.
- **Motifs de rejet**: catalogue `title` + `description` (UUID `id`); admin CRUD above; agents/responsables list via `GET /management/enrollment-reject-motifs` and pass ids in `motif[]` / `reasons[]`.

### 13.7 Explicitly out of current API scope

Do not pretend these exist in code without implementing them:

- Real **videoconferencing** product (Zoom/Meet) — only workflow status/notes/notification
- Kafka topic / object-storage hardening for local dev
- **PSCEQ / professional certificate** acquisition for personne morale (APIs §7, statut actif/suspendu/révoqué)
- Transfert / changement de gestionnaire entreprise (PDF §9)
- Reopen of a **physique** rejected demande (applicant submits a **new** demande). Morale uses `A_CORRIGER` + `PUT /enrolements/morales/{id}` instead.
- SLA thresholds admin UI (env/config only for now)
- Client-facing **activity history** (journaux are admin-only)
- National company registry auto-check beyond duplicate detection on `registration_number` + `country_of_incorporation`

### 13.8 Infrastructure integration

- **Consul**: register/deregister via artisan commands; config in `config/consul.php`
- **Kafka**: config in `config/kafka.php` and `config/notifications.php`
- Gracefully handle missing local infra (Consul/Kafka offline in dev) without breaking unrelated tests

### 13.9 Legacy code

`app/Mail/` and `resources/views/emails/` remain reference material during the Kafka migration. Prefer Kafka notification jobs + existing Blade templates. Do not build new features on `Mail::` facades.

Legacy citizen B2B modules (`Structure*`, subscriptions, signatures, entity attachments, employee invitations) have been **removed** from this backend. Do not reintroduce them here; personne morale enrollment will use `enrollment_requests` when implemented.

---

## 14. Code review checklist

Before opening or approving a PR, verify:

- [ ] Behavior matches PDF/`pics` for the touched enrollment path (or documents a deliberate gap)
- [ ] Controller is thin; validation is in Form Requests
- [ ] Form Request rules document every body and query field: required/optional, type, allowed values (`in:`), upload constraints, and server-side defaults noted in PHPDoc where applicable (§9.5)
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
| TatvaSoft Laravel practices | https://www.tatvasoft.com/outsourcing/2025/09/laravel-best-practices.html |
| Smithery Laravel 12 skill | https://smithery.ai/skills/matula/laravel-12 |
| Laravel 12 docs | https://laravel.com/docs/12.x |
| Laravel Kafka (notifications) | https://laravelkafka.com/docs/v2.11 |

When guidelines conflict with legacy code, **follow this document for all new work** and refactor touched legacy code toward these standards incrementally. When this document conflicts with the enrollment PDF/`pics`, **update this document** — do not silently diverge from the product SoT.
