# AED Foreigner Backend — Development Guidelines

This document defines the practices we **strictly follow** when building and maintaining `aed-foreigner-be`. It applies to all contributors and AI-assisted changes.

Sources consolidated from:

- [`laravel12bestpractices.txt`](./laravel12bestpractices.txt)
- [TatvaSoft — Laravel Best Practices](https://www.tatvasoft.com/outsourcing/2025/09/laravel-best-practices.html)
- [Smithery — Laravel 12 skill (matula/laravel-12)](https://smithery.ai/skills/matula/laravel-12)

---

## 1. Scope & stack

| Item | Standard                                                                            |
|------|-------------------------------------------------------------------------------------|
| Framework | Laravel **12**                                                                      |
| PHP | **8.4+**                                                                            |
| API prefix | `/api/v1` (configured in `bootstrap/app.php`)                                       |
| Auth | Laravel Sanctum + Spatie Permission                                                 |
| Notifications | Kafka publisher (`KafkaNotificationPublisher`) — not direct `Mail::` in domain code |
| Service discovery | Consul (when infra is available)                                                    |

This backend serves the **foreigner enrollment and identity review** domain. Business rules may evolve during genesis; code structure must remain clean enough to adapt without rewrites.

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
php artisan make:model Post -mfsc --policy   # model + migration + factory + seeder + controller
php artisan make:request StorePostRequest
php artisan make:controller PostController --resource
php artisan make:controller SendInvoice --invokable   # single-action endpoints
php artisan make:job ProcessEnrollment
php artisan make:class Services/EnrollmentService
php artisan make:resource PostResource
```

When running commands in CI or automation, pass `--no-interaction`.

---

## 4. Architecture & separation of concerns

### 4.1 Thin controllers

Controllers **MUST** only:

- Accept HTTP input (`Request`, Form Requests)
- Authorize (policies / middleware / roles)
- Delegate to services, actions, or jobs
- Return typed responses (`JsonResponse`, API Resources, etc.)

Controllers **MUST NOT** contain:

- Complex business workflows
- Raw multi-step DB orchestration (use services + transactions)
- Direct third-party integration logic (Consul, Kafka, Kkiapay, etc.)

Move logic to:

- `app/Services/` — domain/application services
- `app/Jobs/` — async work (`ShouldQueue`)
- `app/Actions/` — optional single-purpose invokable classes
- Eloquent model scopes — reusable query constraints

### 4.2 Form Requests for validation

**Never** validate inside controller methods with inline `Validator::make()` for new code.

```bash
php artisan make:request StoreForeignerRegistrationRequest
```

Form Requests **MUST** include:

- `rules(): array`
- Custom messages when defaults are unclear
- `authorize(): bool` when access depends on input or role

Existing controllers with inline validation should be migrated when touched.

### 4.3 Service classes for business logic

Extract workflows (registration, identity review, subscription validation) into dedicated services. Inject them via constructor property promotion.

```php
public function __construct(
    private readonly EnrollmentService $enrollment,
) {}
```

### 4.4 Single-responsibility HTTP actions

For one endpoint = one action, prefer **invokable controllers**:

```bash
php artisan make:controller FinalizeForeignerRegistration --invokable
```

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
        'published_at' => 'datetime',
        'metadata' => 'array',
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
| Models | Singular PascalCase | `Identity`, `PendingRegistration` |
| Controllers | PascalCase + `Controller` | `ForeignerEnrollmentController` |
| Tables | Plural snake_case | `pending_registrations` |
| Columns | snake_case | `assigned_agent_id` |
| Methods / variables | camelCase | `finalizeRegistration`, `$pendingRegistration` |
| Routes | kebab-case URI segments | `/foreigner/register/finalize` |
| Config keys | snake_case | `config('notifications.topics.email')` |
| Jobs | Verb + noun + `Job` | `ForeignerFinalizedJob` |
| Enums | PascalCase cases | `NotificationTemplate::UserAddedToAed` |

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
User::query()->where('status', 'ACTIVE')->get();

// ❌ Avoid unless there is a measured performance reason
DB::table('users')->where('status', 'ACTIVE')->get();
```

When raw SQL is required, **always** use parameter binding — never concatenate user input.

### 8.2 Prevent N+1 queries

**Always** eager load relationships used in loops or API collections:

```php
Identity::with(['user:id,name,email,phonenumber,npi'])->paginate();
```

Review list endpoints and exports for N+1 before merging.

### 8.3 Large datasets

**Never** use `all()` or unbounded `get()` on large tables.

Use:

- `cursor()` / lazy collections
- `chunk()` / `chunkById()` for batch processing
- Pagination for HTTP list endpoints

### 8.4 Migrations

When **changing** a column, include **all** previous attributes or they will be lost:

```php
// ❌ Loses nullable
$table->string('email')->unique()->change();

// ✅ Preserves nullable
$table->string('email')->nullable()->unique()->change();
```

One concern per migration. Name migrations descriptively.

Use factories and seeders for test and local data:

```bash
php artisan make:factory IdentityFactory
php artisan make:seeder IdentitySeeder
```

### 8.5 Keep models focused

Models hold relationships, scopes, casts, and accessors — not orchestration logic. Move workflows to services.

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

Tests may use `Sanctum::actingAs()` or project test helpers (e.g. `actingAsAgent()`).

### 9.2 API responses

- All API routes return JSON (`ForceJsonResponse` middleware is global)
- Use **API Resources** to decouple DB shape from public JSON:

```bash
php artisan make:resource IdentityReviewResource
```

Standard success envelope (existing project pattern):

```json
{
  "success": true,
  "message": "...",
  "data": { }
}
```

Use appropriate HTTP status codes; validation errors return **422**.

### 9.3 Authorization

- Route middleware: `role:`, `permission:`, `auth:sanctum`
- Fine-grained checks: policies + `$this->authorize()`
- Spatie `UnauthorizedException` is rendered as JSON 403 in `bootstrap/app.php` — preserve this behavior

### 9.4 Security baseline

- Validate and sanitize **all** input via Form Requests
- Never trust query params, headers, or file metadata without validation
- CSRF applies to stateful web routes; API uses token auth
- Enforce HTTPS in production (reverse proxy / middleware)
- Avoid raw dynamic SQL; use Eloquent or bound query builder
- Apply least-privilege DB credentials in deployment

---

## 10. Background processing & performance

### 10.1 Queues for slow work

Any operation that is slow, external, or retryable **MUST** be a queued job implementing `ShouldQueue`:

- Email/SMS/notifications (via Kafka jobs)
- File processing, Regula analysis
- Third-party API calls (Kkiapay, Consul side effects)

HTTP responses **MUST NOT** wait on these operations.

Configure sensible `$tries`, `$timeout`, and `$backoff` on jobs.

### 10.2 Job chains

For ordered multi-step workflows, use:

```php
Bus::chain([
    new ProcessVideo($video),
    new AddWatermark($video),
    new DeployToProduction($video),
])->dispatch();
```

If one step fails, subsequent steps must not run.

### 10.3 Kafka notifications

- Publish through `NotificationPublisherInterface` / `KafkaNotificationPublisher`
- Always call `->send()` on the Kafka producer builder
- Do not reintroduce `Mail::` in controllers or domain jobs for new features
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
- Fake external systems: `Bus::fake()`, `Kafka::fake()`, `Storage::fake()`, HTTP fakes
- Assign Spatie roles in test setup when hitting role-protected routes

### 11.3 Test database

- Prefer a dedicated MySQL test database (`.env.testing` or `phpunit.xml`)
- Seed only what each test needs; avoid depending on production-like fixtures

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

## 13. Project-specific rules

### 13.1 Foreigner enrollment

- `ForeignerEnrollmentController` currently forces `type = ONLINE` and `level = ADVANCED` for the foreigner flow — treat this as intentional until product spec changes
- Do not expand scope into citizen flows; those belong elsewhere (`UserController`, etc.)

### 13.2 Identity review

- Agent routes require Spatie roles (`tech_one`, `tech_two`, `tech_three`, `superviseur`)
- List/filter logic belongs in query scopes or a dedicated service, not duplicated in controllers

### 13.3 Infrastructure integration

- **Consul**: register/deregister via artisan commands; config in `config/consul.php`
- **Kafka**: config in `config/kafka.php` and `config/notifications.php`
- Gracefully handle missing local infra (Consul/Kafka offline in dev) without breaking unrelated tests

### 13.4 Legacy code

`app/Mail/` and `resources/views/emails/` are reference material during the Kafka migration. Do not build new features on `Mail::` facades.

---

## 14. Code review checklist

Before opening or approving a PR, verify:

- [ ] Controller is thin; validation is in Form Requests
- [ ] No `env()` outside `config/`
- [ ] No secrets in code or commits
- [ ] Types on all new/changed methods and relationships
- [ ] Eager loading where relationships are accessed
- [ ] Slow/external work dispatched to queues
- [ ] API changes use Resources and `/api/v1` paths
- [ ] `$request->user()` used instead of facades
- [ ] Tests added/updated; `php artisan test` passes
- [ ] Pint (and Larastan when configured) clean on touched files
- [ ] Migrations reversible and safe for existing data

---

## 15. References

| Resource | URL |
|----------|-----|
| Internal notes | [`laravel12bestpractices.txt`](./laravel12bestpractices.txt) |
| TatvaSoft Laravel practices | https://www.tatvasoft.com/outsourcing/2025/09/laravel-best-practices.html |
| Smithery Laravel 12 skill | https://smithery.ai/skills/matula/laravel-12 |
| Laravel 12 docs | https://laravel.com/docs/12.x |
| Laravel Kafka (notifications) | https://laravelkafka.com/docs/v2.11 |

When guidelines conflict with legacy code, **follow this document for all new work** and refactor touched legacy code toward these standards incrementally.
