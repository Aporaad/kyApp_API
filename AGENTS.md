# AGENTS.md — kyApp_API (Laravel backend)

> **دليل وكلاء الذكاء الاصطناعي للواجهة الخلفية** — اقرأه قبل تعديل أي ملف داخل `kyApp_API/`.
> Backend agent guide. This file takes precedence over the root [`../AGENTS.md`](../AGENTS.md)
> for everything inside `kyApp_API/`.

REST façade over a **legacy Oracle 11g accounting schema** for الكبودي للخياطة الرجالية
(Al-Kaboodi Men's Tailoring). Consumed only by the Flutter client in the parent repository.

**Stack:** Laravel 12.69 · PHP `^8.2.12` · Sanctum 4 · `yajra/laravel-oci8` 12.0 · Pest 3 · Pint · Vite 7 + Tailwind 4 (unused by the API surface)

**Status:** 71 routes · **IBAC** permission catalog (93 codes / 17 modules) enforced on every business
route · session idle-sliding policy · admin surface (`/users`, `/permissions`, `/sessions`,
`/settings`, `/users/{id}/activity`). The design record is [`../ibac_permissions_plan.md`](../ibac_permissions_plan.md).

---

## 1. Setup

```bash
composer install
cp .env.example .env       # then ADD the Oracle variables — the template is missing them
php artisan key:generate
php artisan serve --host 0.0.0.0     # --host is REQUIRED: the Flutter app runs on a phone/emulator
composer dev                          # serve + queue:listen + pail + vite concurrently
```

### ⚠️ `.env.example` does not describe this project

It is the stock Laravel 12 template with `DB_CONNECTION=sqlite` and **none** of the Oracle variables
that `config/database.php` and `config/oracle.php` actually read. A working `.env` needs:

```dotenv
APP_NAME="KY API"
APP_URL=http://192.168.0.11:8000
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=oracle
DB_HOST=127.0.0.1
DB_PORT=1521
DB_SERVICE_NAME=orcl
DB_DATABASE=orcl
DB_USERNAME=ACCOUNTS
DB_PASSWORD=<ask the user>
DB_CHARSET=AL32UTF8
DB_SCHEMA_PREFIX=accounts
DB_SERVER_VERSION=11g

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

Ask the user for real Oracle credentials. **Never invent them and never commit them.**

> `config/database.php:122-123` currently falls back to `ACCOUNTS` / `acc` as hardcoded defaults.
> That is a committed credential — treat it as tech debt, not as configuration to copy elsewhere.

### Sanity checks

```bash
php artisan route:list --path=api            # 71 endpoints
php artisan config:show database.default     # should be "oracle"
curl http://localhost:8000/api/hello         # connectivity probe the Flutter app uses
php artisan tinker --execute 'DB::connection("oracle")->select("select 1 from dual");'
```

### Oracle client library

The PHP `oci8` / `pdo_oci` extension must load an **11g-compatible** Instant Client. A stale
`oci8_19` DLL left on `PATH` after an Oracle 10g → 11g upgrade is a documented failure mode in this
project — see `user_commends.md`. If `oci8` fails to load, diagnose `PATH` before touching code.

---

## 2. Structure

```
app/
├── Http/Controllers/          (11)  ← ALL request logic lives here
│   ├── AuthController.php            login/logout/me + biometric enroll/login/revoke + myPermissions
│   ├── UserAdminController.php       IBAC user administration (list/show/store/update/toggle/destroy/activity)
│   ├── PermissionController.php      catalog read + per-user grant read + atomic sync
│   ├── SessionAdminController.php    active sessions: index / terminate one / terminate a user's
│   ├── SettingController.php         security & session settings (read/update with bounds)
│   ├── AccountController.php         chart-of-accounts tree, CHART_ACC CRUD, statements
│   ├── CustomerController.php        customer CRUD + statement (ACCOUNTS.CUST)
│   ├── WorkerController.php          employees/cutters/tailors CRUD + toggle + statements
│   ├── CurrencyController.php        currencies + daily FX price CRUD
│   ├── InvoiceController.php         read-only SALES_BILL (envelope + encoding, phase 7)
│   ├── TableQueryController.php      "top N from any table" debug endpoint + deny list
│   └── Controller.php                empty abstract base (Laravel 12)
├── Http/Middleware/EnsurePermission.php  ← the `permission:<code>` guard (401 → 403 by name)
├── Models/User.php             ← the ONLY model (Oracle APP_USERS, HasApiTokens, isAdmin())
├── Providers/AppServiceProvider.php
│       singletons: SettingsStore, PermissionResolver, PermissionGrantSync, ActivityLogger,
│       SessionPolicy · and hooks `Sanctum::$accessTokenAuthenticationCallback` → SessionPolicy
└── Support/   ← the IBAC layer, all critical
    ├── ArabicEncoding.php      CP1256 ↔ UTF-8 repair. See §5.1.
    ├── PermissionCatalog.php   the 17 modules / 93 codes + Arabic names (single source of truth)
    ├── PermissionResolver.php  can()/canAny()/canAll()/payloadFor()/forget() — deny-by-default
    ├── PermissionGrantSync.php atomic replace of a user's grants (+ anti-escalation)
    ├── SessionPolicy.php       idle sliding renewal, absolute cap, prime() on issue
    ├── SettingsStore.php       APP_SETTINGS read/write with bounds + 3000-byte cache cap
    └── ActivityLogger.php      writes APP_USER_ACTIVITY with Arabic through the write path
database/oracle/               ← IBAC DDL & seeding, applied by hand (NOT migrations)
├── 01_ibac_schema.sql         tables + sequences + triggers
├── 02_ibac_seed.php           catalog (93) + settings (10)
├── 03_ibac_bootstrap.php      foundational grants
└── 04_ibac_rollback.sql
```

**Directories that do not exist and should not be created without approval:**
`app/Http/Requests`, `app/Http/Resources`, `app/Services`, `app/Policies`,
`app/Enums`, `app/Console`, `app/Jobs`, `app/Exceptions`.

There are **no Form Requests, no API Resources, no Policies, no Enums**. Validation is inline in
controllers; responses are hand-built arrays. `app/Http/Middleware` holds exactly one class (the
permission guard) and `app/Support` holds the seven classes above — do not grow either by accident.

`bootstrap/app.php` is no longer pristine: `withMiddleware()` registers the **`permission` alias**
(`EnsurePermission`). `withExceptions()` is still empty, and there is still no published CORS config
(framework-default `HandleCors` applies). `bootstrap/providers.php` registers only
`AppServiceProvider`.

This flatness is the current reality, not an ideal. When adding a feature, **match the neighbouring
controller**; propose a refactor separately rather than introducing a new layer drive-by.

---

## 3. Routes

`routes/api.php` is auto-prefixed with `/api` by `withRouting(api:)`. **No route names, no `/v1`
version prefix.** Comments are bilingual Arabic/English. **71 endpoint registrations** total.

### Authentication boundary

Two layers, always in this order: `auth:sanctum` (who is this?) then `permission:<code>` (may they?).

```php
// عام بحكم التصميم (3 فقط)
Route::post('/auth/login',           [AuthController::class, 'login']);
Route::post('/auth/biometric/login', [AuthController::class, 'biometricLogin']);
Route::get('/hello', …);                       // the client's connectivity probe

// جلسة المستخدم نفسه — بلا صلاحية، لأن حارسها يمنع المرء من معرفة حالته (6 فقط)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout');  Route::get('/auth/me');  Route::get('/user');
    Route::get('/auth/permissions', [AuthController::class, 'myPermissions']);
    Route::post('/auth/biometric/register');  Route::delete('/auth/biometric/revoke');
});

// كل ما عدا ذلك (62): auth:sanctum + permission:<code>
Route::get('/customers')->middleware('permission:customers.view');
```

`EnsurePermission` answers **401** with the session message when there is no user (a route that forgot
`auth:sanctum`), and **403** naming the missing permission in Arabic when the grant is absent:
`errors: { permission: ["customers.update"] }`. `IS_ADMIN = 1` passes unconditionally.

> ✅ `selectFromTable` is now behind `system.*` and additionally refuses 11 sensitive tables
> (`APP_USERS`, token/cache/jobs infrastructure, …) with `errors.table_name = restricted_table`
> *before* any existence check, and redacts columns matching `PASSWORD` / `DEVICE_TOKEN` / `TOKEN` /
> `SECRET` / `KEY` for allowed tables — reporting `redacted_columns` so the omission is declared, not
> silent. Its injection guard (regex + `max:30` + `USER_TABLES` check + `limit ≤ 1000`) is unchanged.

`tests/Feature/BusinessRoutesSealedTest.php` asserts all of the above. Treat it as the gate.

### Endpoint inventory

| Group | Routes | Controller methods |
|---|---|---|
| `/auth/login`, `/auth/biometric/login`, `/auth/logout`, `/auth/me`, `/user`, `/auth/biometric/register`, `/auth/biometric/revoke` | 7 | the session's own surface — 2 public, 5 `auth:sanctum` without a guard |
| `/hello` | 1 | closure — connectivity probe |
| `/auth/permissions` + `/users/**` + `/permissions` (`{userId}` `[0-9]+`) | 11 | `AuthController@myPermissions` · `PermissionController@index/forUser/syncUser` · `UserAdminController@index,show,store,update,toggle,destroy,activity` |
| `/sessions/**` | 3 | `SessionAdminController@index`, `terminate` (DELETE `/{tokenId}`), `terminateUser` (DELETE `/user/{userId}`) |
| `/settings` | 2 | `SettingController@index` (`settings.view`), `update` (`settings.update`) |
| `/invoices`, `/invoices/{billNo}` | 2 | `index`, `show` — `invoices.view` |
| `/selectFromTable` (`Route::match(['get','post'])`) | 1 | `selectFromTable` — `system.*` |
| `/customers/**` (`{custNo}` constrained `[0-9]+`) | 6 | `index`, `show`, `store`, `update`, `destroy`, `statement` |
| `/workers/{employees\|cutters\|tailors}/**` (`{id}` constrained `[0-9]+`) | 18 | `employees`/`cutters`/`tailors`, `store*`, `update*`, `destroy*`, `toggle*` (PATCH `/{id}/toggle`), `statement*` (GET `/{id}/statement`) — thin public wrappers over shared privates `listWorkers`, `storeWorker`, `updateWorker`, `destroyWorker`, `toggleWorker`, `fetchStatement` (each taking `string $type` first). **Each of the three registries is its own permission module** (`employees.*`, `cutters.*`, `tailors.*`) |
| `/accounts/**` | 12 | `getAccounts`, `getAccMain`, `getAccSub`, `getAccSubGroup`, `getAccountTree`, `getChartAccounts`, `createChartAccount`, `updateChartAccount`, `createSubGroup`, `updateSubGroup`, `getAccountStatement`, `getDetailedStatement` |
| `/currencies/**` (`{curNo}`, `{seq}` constrained `[0-9]+`) | 8 | `index`, `store`, `update`, `destroy`, `getPrices`, `addPrice`, `updatePrice`, `deletePrice` |

`routes/web.php`: `/` → stock `welcome.blade.php`, plus a duplicate text `/hello`.
`routes/console.php`: stock `inspire` only.

---

## 4. Response conventions

**There are no Eloquent API Resources.** Every controller hand-builds its payload.

```jsonc
// success — 200 / 201
{ "success": true, "message": "تم جلب البيانات / Data retrieved", "data": [...],
  "meta": { "total": 0, "page": 1, "limit": 100 } }

// validation failure — 422
{ "success": false, "message": "…", "errors": { "field": ["…"] } }

// server failure — 500 ("error" key present ONLY when app.debug is true)
{ "success": false, "message": "…", "error": "…" }
```

- Keys are **snake_case**. Arabic strings are run through `ArabicEncoding::cleanData()` /
  `fixMojibake()` before being returned.
- Status codes in use: `200`, `201` (create), `400` (empty update), `401` (bad credentials /
  unauthenticated), `403` (deactivated account), `404` (missing record or table), `409` (device
  already bound to another user), `422` (validation), `500`.
- **Pagination is manual** via `page` / `limit` query params — it is *not* Laravel paginator format,
  and the limits are **not uniform across controllers**:

  | Controller | Default | Cap | Read via |
  |---|---|---|---|
  | `CustomerController`, `WorkerController`, `UserAdminController` | `DEFAULT_LIMIT = 100` | `MAX_LIMIT = 500` | `$request->input('limit')` |
  | `AccountController` (list endpoints) | `50` | `min(100, max(10, …))` | `$request->query('limit', 50)` |
  | `AccountController` (statements) | `50` | `min(200, max(10, …))` | `$request->query('limit', 50)` |
  | `CurrencyController` (prices) | `30` | `min(100, max(10, …))` | `$request->query('limit', 30)` |
  | `TableQueryController` | — | `MAX_ROW_LIMIT = 1000` (required param) | validated `min:1` |

  The `meta` shape is **inconsistent** too:
  - Customer / Worker / Invoice / UserAdmin: `meta: { total, page, limit, pages }`
  - Account / Currency: `meta: { total, page, limit, total_pages, has_more }`

  **Match whichever the neighbouring controller uses.** Don't unify limits or `meta` as a side effect
  of another change — the Flutter repositories parse these shapes and assume these caps.
- ✅ **`InvoiceController` used to be the envelope outlier** (raw `response()->json($collection)`, no
  auth, no encoding). It now follows the standard envelope, `ArabicEncoding::cleanData()`, search by
  bill number or customer name in both byte forms, `meta: { total, page, limit, pages }` and an
  explicit 404 on an unknown bill.

---

## 5. The two things that break most often

### 5.1 Arabic encoding (CP1256 ↔ UTF-8)

The Oracle instance has `NLS_CHARACTERSET = WE8MSWIN1252`, so Arabic is stored as **Windows-1256
bytes** and surfaces as mojibake (`ÎÇáÏ` instead of `خالد`). `app/Support/ArabicEncoding.php` is the
repair layer:

| Method | Use |
|---|---|
| `fixMojibake(?string $str): ?string` | decode a single CP1256-mangled Arabic string (read path) |
| `toOracleSearchChars(?string $str): ?string` | **the only write path and the only search path** — maps each CP1256 byte to the Latin-1 character of the same code |
| `cleanData($data)` | recursively repair rows before returning them |

> 🚫 **`encodeForOracle()` no longer exists and must never come back.** It produced a string of raw
> CP1256 *bytes* and bound that to Oracle. The app connects with `charset=AL32UTF8`, so Oracle
> re-interprets those bytes as invalid UTF-8 and substitutes the replacement character. Measured live
> with `DUMP()`: storing `خالد سالم` through it landed `bf,bf,bf` — three characters instead of nine,
> value destroyed. `toOracleSearchChars()` lands the original bytes exactly like the legacy system's
> own rows, so it is both correct for writes and correct for `LIKE` searches.
> `tests/Unit/ArabicWritePathTest.php` pins this invariant (and asserts the method stays deleted).
>
> ✅ **`fixMojibake()` is idempotent since the [2026-10-20] fix**: a string that is valid UTF-8, carries
> Arabic letters (U+0600–U+06FF) and contains **no character in 0x80–0xFF** is returned unchanged —
> the repair cannot run twice on good text. Before this guard, a second pass mapped the *bytes* of
> correct Arabic through the CP1256 table and turned `موظف` into `ظ…ظˆط¸ظپ` (the sessions and
> security-settings screens showed exactly this after `cleanData()` ran over already-repaired rows).
> Rules below still demand *one* repair per value; the guard is the safety net, not a licence.
> `tests/Unit/ArabicReadPathTest.php` pins idempotence (runs with **no** Oracle connection).

Rules:
- **Every** Arabic value leaving the API goes through `cleanData()` / `fixMojibake()`.
- **Every** Arabic value entering the API — for storage *or* for a `LIKE` search — goes through
  `toOracleSearchChars()`. 16 call sites in `CustomerController` / `AccountController` /
  `CurrencyController`, plus `SettingsStore` for `string` settings.
- Searching Arabic must match **both** character forms, because stored bytes ≠ typed UTF-8:
  ```sql
  WHERE CUST_NAME LIKE '%Ç%' OR CUST_NAME LIKE '%ا%'
  ```
- `SettingsStore` caps values by **column width in characters** (`mb_strlen`), not bytes — a 500-char
  Arabic value is well over 500 bytes and is valid.
- The Flutter client has a mirror layer (`lib/core/utils/arabic_encoding_utils.dart`). If you change
  the mapping on one side, you must change it on the other.

If a bug report says "the name shows as garbage", this is where you look first. If a report says
"the saved name became `???`", it went through a raw-byte bind — same section.

### 5.2 Oracle 11g identity columns

**Oracle 11g has no identity columns.** Auto-increment needs a Sequence + Trigger.

- Laravel infrastructure (added to fix `ORA-01400` on login):
  `PERSONAL_ACCESS_TOKENS_ID_TRG` · `MIGRATIONS_ID_TRG` · `JOBS_ID_TRG` · `FAILED_JOBS_ID_TRG`
- IBAC tables (`database/oracle/01_ibac_schema.sql`):
  `TRG_APP_PERMISSIONS` · `TRG_APP_USERS_PERM` · `TRG_APP_SETTINGS` · `TRG_APP_ACTIVITY`

> ⚠️ Inside PL/SQL the sequence must be **unqualified**: `SEQ_APP_USERS_ID.NEXTVAL`, never
> `ACCOUNTS.SEQ_APP_USERS_ID.NEXTVAL`. There is a **VIEW named `ACCOUNTS` inside the `ACCOUNTS`
> schema**, so a qualified reference parses as a column of that view — the trigger is created
> `INVALID` (`PLS-00302` → `ORA-04098` on every insert). Check `list_invalid_objects` after DDL.

Business tables have **no** triggers — `CustomerController`, `WorkerController`,
`CurrencyController` and `AccountController` all compute the next primary key as
`MAX(pk) + 1`. That is race-prone under concurrent writes. Don't propagate the pattern into new
code without flagging it: `UserAdminController@store` takes `SEQ_APP_USERS_ID.NEXTVAL` explicitly
(`APP_USERS` has no trigger, so the legacy ERP is unaffected).

### Other Oracle constraints

- Max identifier length **30** (`config/oracle.php`); `server_version => '11g'`; NLS session vars set
  `NLS_DATE_FORMAT=YYYY-MM-DD HH24:MI:SS`.
- `prefix_schema => 'accounts'` — tables resolve as `ACCOUNTS.CUST`, etc.
- **Column-name case is unreliable.** Oracle PDO returns lowercase attribute keys while the catalog
  is UPPERCASE. `User::getAttribute()` is overridden with a lowercase→uppercase fallback. Keep it.
- **`TRUNC()` in this codebase is arithmetic, not temporal** — it derives the chart-of-accounts
  hierarchy, e.g. `TRUNC(c.ACC_NO/10000) = s.ACC_PART` to walk `CHART_ACC` → `ACC_SUB`. Do not read
  it as a date function. (The `TRUNC(ENTRY_DATE)` idiom appears in the SQL logs in `db_commends.md`,
  not in the controllers.)
- **Date binds use `TO_DATE()` with table-specific formats** — and they are *not* consistent:
  - `AccountController` statements: `TRXN_DATE >= TO_DATE(:from_date, 'DD/MM/YYYY')`
  - `CurrencyController` prices: `TO_DATE(:day_date, 'YYYY-MM-DD')`

  Check the neighbouring query for the format before writing a new date filter.
- Statement queries bind `:acc_no`, `:from_date`, `:to_date`, `:id`, `:cur_no`, `:day_date`, `:seq`.
  **Always bind; never interpolate.**
- `config/oracle.php` reads `DB_TNS`, `DB_SCHEMA_PREFIX`, `DB_EDITION`, `DB_LOAD_BALANCE` — all
  absent from `.env.example`.
- **Cache values are capped at 3000 bytes.** The `CACHE` table writes `VALUE` through
  `merge … (select :value from dual)`, and Oracle refuses a bound string over 4000 bytes
  (`ORA-01461`). `PermissionResolver` (`MAX_CACHE_BYTES`) and `SettingsStore` cap themselves at 3000
  and fall back to reading the table on overflow. Raise the payload and every authenticated request
  500s.
- **An empty Oracle DATE comes back as `false`, not `null`.** Passing it straight to Carbon throws
  `Call to a member function isPast() on bool` and 500s the request. All date reads go through
  `SessionPolicy::toDate()`, which normalises `null`/`false`/`''`/string/date-object → `Carbon|null`.

---

## 6. Authentication

**Sanctum personal access tokens.** `User` uses `HasApiTokens`; routes use `auth:sanctum`.
Token names: `ky_app_{user_type}` for password login, `ky_biometric_login` for biometric.

`config/sanctum.php` keeps `expiration => null` — **and it must stay null**. `Sanctum\Guard` compares
that setting against `created_at`, so any value becomes a *hard absolute age* and would log an active
worker out mid-task, which the requirement rejects explicitly. Lifetime is enforced instead on the
`PERSONAL_ACCESS_TOKENS.EXPIRES_AT` column, which Sanctum does honour:

```php
// AppServiceProvider::boot()
Sanctum::$accessTokenAuthenticationCallback = fn ($token, $isValid) => $sessionPolicy->validate($token, $isValid);
```

`App\Support\SessionPolicy` (singleton, reads `APP_SETTINGS` through `SettingsStore`):

| Step | Condition | Result |
|---|---|---|
| 1 | `IS_ACTIVATED != 1` | 401 — a disabled account loses access immediately, valid token or not |
| 2 | `EXPIRES_AT` in the past | 401 — idle window elapsed |
| 3 | `CREATED_AT + session_absolute_days` elapsed | 401 — absolute cap |
| 4 | otherwise | accept + **sliding renewal**, write-throttled |

- Idle window `session_idle_minutes` (default **240**), absolute cap `session_absolute_days`
  (default **14**), throttle `session_touch_threshold_minutes` (default **5**) ⇒ roughly one
  `UPDATE` per session per five minutes. `EXPIRES_AT IS NULL` (legacy sessions) are accepted and
  granted a full window.
- `prime()` is called immediately after every `createToken()` — password login and biometric login —
  so a new token is never left with a null expiry.
- Writes use `saveQuietly()` and set `updated_at` explicitly (Oracle gives that column no default and
  Sanctum does not fill it on this path).
- Rejection rides the client's existing path: 401 → `ErrorInterceptor` → `clearAll()` →
  `forceLogout()` → `/login`.

### `app/Models/User.php` — the only model

```php
protected $connection = 'oracle';
protected $table      = 'APP_USERS';
protected $primaryKey = 'user_id';
public    $timestamps = false;
```

`$fillable` is **UPPERCASE Oracle columns**: `USER_NAME_AR`, `USER_NAME_EN`, `USER_PASSWORD`,
`IS_ACTIVATED`, `NOTE`, `BRANCH_NO`, `CREATED_ON`, `CREATED_BY`, `LAST_UPDATED_ON`,
`LAST_UPDATED_BY`, `CASH_ACC_NO`, `USER_TYPE`, `ALLOW_BANK_ACC`, **`IS_ADMIN`** (the IBAC bypass flag),
plus the biometric columns `DEVICE_TOKEN`, `DEVICE_NAME`, `PLATFORM`, `BIOMETRIC_ENABLED`,
`BIOMETRIC_REGISTERED_AT`, `BIOMETRIC_LAST_USED`. `$hidden` lists both cases of the password and
device token.

> ⚠️ `USER_NO` is **not** fillable and no longer exists — it was the legacy ERP number, dropped from
> `APP_USERS`. Re-adding it makes inserts fail with `ORA-00904`. The identifier everywhere is
> `USER_ID` (login, biometric login, every IBAC lookup, `idOf()`).

It overrides `getAttribute()` (case fallback: lowercase → uppercase), `getAuthPassword()`,
`getAuthIdentifierName()`, and carries `isAdmin()`. No `$casts`, no relationships.

### Login (`AuthController@login`)

Validates `username`, `password`, and optional `user_type in:employee,customer,1,2`. Looks the user
up by `UPPER(USER_NAME_EN)`, `USER_NAME_AR`, or numeric **`USER_ID`**. Requires `IS_ACTIVATED === 1`
(else 403). Password check **accepts legacy plaintext equality first**, then falls back to
`Hash::check()` only if the stored value looks hashed. On success it calls
`SessionPolicy::prime()` on the freshly created token.

> ⚠️ The plaintext comparison is **deliberate legacy support** for rows written by the old system.
> Removing it silently locks out real users. If you are asked to harden it, plan a migration of
> existing hashes rather than deleting the branch.

### Biometric / device binding

Server-side state lives in columns on `APP_USERS` — there is no separate devices table
(`USER_BIOMETRIC_DEVICES` appears in older planning docs but was **not** built).

- `registerBiometric`: validates `device_token` (max 512), `device_name`, `platform in:android,ios`;
  sets `BIOMETRIC_ENABLED=1`, `BIOMETRIC_REGISTERED_AT=now()`. Rejects a `device_token` already
  bound to a different user with **409**. **One device per user.**
- `biometricLogin` (**public route**): matches `user_id` + `DEVICE_TOKEN` + `BIOMETRIC_ENABLED=1`,
  updates `BIOMETRIC_LAST_USED`, issues a fresh Sanctum token.
- `revokeBiometric`: nulls the device columns.

The client's `device_token` is `sha256("$rawDeviceId:ky_apps_biometric_v1_2026")`. **That salt is
part of the contract** — if it changes on either side, every enrolled device stops working.

### Authorization — IBAC, not RBAC

**There is no RBAC.** No spatie/laravel-permission, no gates, no policies, no role middleware.
Permissions attach **directly to a user row** (Identity-Based Access Control). The legacy
`USER_TYPE` column (`employee` / `customer`) is *not* a permission and stays as it was. Despite its
filename, `roles.md` is **not** a permissions model — it is a Flutter + API integration standards
reference. The full design and its accepted decisions D1–D8 live in `../ibac_permissions_plan.md`.

| Piece | Role |
|---|---|
| `APP_PERMISSIONS` | the catalog — 93 rows, 17 modules, ASCII `PERMISSION_CODE` (`module.action`) + Arabic `NAME_AR`/`DESCRIPTION_AR`. Seeded, never edited from the UI |
| `APP_USERS_PERMISSIONS` | the grants — `USER_ID`, `PERMISSION_ID`, `GRANTED_BY`, `IS_ACTIVE`, `EXPIRES_ON` |
| `PermissionCatalog` | the 17 modules / 93 codes and their Arabic names — single source of truth |
| `PermissionResolver` | `can()/canAny()/canAll()/payloadFor()/codesFor()/isAdmin()/forget()` — deny-by-default, cached under `MAX_CACHE_BYTES = 3000` |
| `PermissionGrantSync` | `currentFor()/resolve()/replace()` — atomic replace of a user's grants inside one Oracle transaction |
| `EnsurePermission` | the `permission:<code>` route guard |

`PermissionResolver::can()` ladder, in order: **`IS_ADMIN = 1`** ⇒ true · **`system.*`** held ⇒ true ·
literal code granted ⇒ true · **`module.*`** wildcard granted ⇒ true · otherwise **false**.

Enforced on the write surface, not just the read one:
- `PUT /users/{userId}/permissions` refuses codes that are not in the catalog (**422**) and refuses to
  grant anything the actor does not themselves hold (**anti-escalation**).
- A successful grant change **terminates that user's sessions** and invalidates the permission cache,
  so a re-permissioned user sees the change without a relogin and a revoked one loses it immediately.
- `UserAdminController` protects the acting user (cannot disable/delete self) and guards the **last
  active admin** so the system can never be locked out of itself.
- `GET /auth/permissions` returns `payloadFor()` for the current user — the client mirrors the same
  rules for *display only*; the server re-decides every request.

---

## 7. Validation

**No Form Requests exist.** Two inline styles are in use — match the file you are editing:

```php
// Style A — explicit Validator (AuthController, TableQueryController, CustomerController)
$validator = Validator::make($request->all(), [
    'cust_name' => ['required', 'string', 'max:150'],
], [
    'cust_name.required' => 'اسم العميل مطلوب / Customer name is required',
]);
if ($validator->fails()) {
    return response()->json(['success' => false, 'message' => '…', 'errors' => $validator->errors()], 422);
}

// Style B — $request->validate() inside try/catch (AccountController, CurrencyController)
try {
    $validated = $request->validate([...]);
} catch (ValidationException $e) {
    return response()->json(['success' => false, 'message' => '…', 'errors' => $e->errors()], 422);
}
```

Validation messages are **bilingual Arabic/English**.

> ⚠️ **`WorkerController@storeWorker` / `updateWorker` have no validation at all.** They call
> `$request->only([...UPPERCASE Oracle columns...])` and insert directly — so the Flutter client must
> send keys shaped like `EMP_NAME`, `CUTTER_PHONE1`, `TAILOR_ACC_NO`. Add validation before extending
> these, and coordinate the key casing with the client (`toJsonForCreate()` / `toJsonForUpdate()` in
> `lib/features/workers/data/models/worker_model.dart`).

---

## 8. Database & schema

**Migrations do not describe the business data.** `database/migrations/` holds only the stock
Laravel skeleton (`users`/`password_reset_tokens`/`sessions`, `cache`, `jobs`) plus
`2026_09_01_212624_create_personal_access_tokens_table.php`. These target the *default* connection.
The real tables are in Oracle and are **not migrated from this repo — ever.**

Legacy tables referenced by the code:

| Table | Purpose |
|---|---|
| `APP_USERS` | logins + biometric enrollment + `IS_ADMIN` (PK `USER_ID`) |
| `APP_PERMISSIONS` | the IBAC catalog (93 codes / 17 modules) — seeded, not UI-edited |
| `APP_USERS_PERMISSIONS` | the IBAC grants: `USER_ID`, `PERMISSION_ID`, `GRANTED_BY`, `IS_ACTIVE`, `EXPIRES_ON` |
| `APP_SETTINGS` | 10 security/session settings with `VALUE_TYPE` (`int`/`bool`/`string`) and bounds, via `SettingsStore` |
| `APP_USER_ACTIVITY` | audit trail (`ACTION_TYPE`, `DESCRIPTION_AR`, `STATUS`, `IP_ADDRESS`, `CREATED_ON`), via `ActivityLogger` |
| `PERSONAL_ACCESS_TOKENS` | Sanctum tokens — **`EXPIRES_AT` is what `SessionPolicy` actually enforces** |
| `CACHE` | the database cache store; the 3000-byte payload ceiling comes from here |
| `CUST` | customers (`LIMITED_BALANCE` credit limit, `STOPPING_CUST` deactivation) |
| `TAILOR_EMPLOYEES`, `CUTTER`, `TAILOR` | three worker registries, each with its own `ACC_NO` |
| `ACCOUNT` → `ACC_MAIN` → `ACC_SUB` → `ACC_SUB_GROUP` → `CHART_ACC` | 5-level chart of accounts |
| `ENTRY_MAIN`, `ENTRY_SUB`, `ENTRY_TYPE` | journal entries — the source of every statement |
| `CURRENCY`, `CUR_CODE`, `CUR_PRICE` | currencies and daily FX rates |
| `SALES_BILL` | invoices (read-only) |
| `ACC_TRXN` | queried by `getAccountStatement` inside a swallowed try/catch; may not exist |
| `USER_TABLES` | used by `TableQueryController` as a table-existence whitelist |

### The IBAC tables are DDL scripts, not migrations

`database/oracle/` holds them, applied **by hand** through the Oracle MCP and recorded line by line in
`db_commends.md`:

| File | What it does |
|---|---|
| `01_ibac_schema.sql` | the five tables + their sequences + their triggers |
| `02_ibac_seed.php` | seeds the 93 catalog rows and the 10 settings |
| `03_ibac_bootstrap.php` | foundational grants so the first admin is not locked out |
| `04_ibac_rollback.sql` | the way back |

Do not convert them into migrations and do not re-run them without approval.

Chart-account `ACC_NO` is derived as `ACC_PART * 10000 + n`. The account tree special-cases the
customers group (`ACC_PART` 123 / `GROUP_ID` 5); account **`12310000` — "عملاء الكبودي للخياطة"** is
the customer control account and is the one customer account the tree deliberately exposes.

`DBdevloping_history.md` (this directory) and `../DBdevloping_history.md` are the **de-facto schema
documentation**. Read them before writing SQL.

> ⚠️ `database/factories/UserFactory.php` and `database/seeders/DatabaseSeeder.php` are **broken
> skeleton leftovers** — they set `name` / `email`, which do not exist on the Oracle `APP_USERS`
> model. Do not rely on them; don't "fix" them without asking, since factories against a legacy
> production schema are of limited use here.

### Inspecting the database

An `oracle` MCP server is configured (`list_tables`, `execute_sql`, `get_query_plan`,
`list_invalid_objects`, `list_top_sql_by_resource`). Prefer it over ad-hoc clients for read-only
inspection. Laravel Boost's `database-query` and `database-schema` tools work too.

**Never run DDL, `UPDATE`, or `DELETE` against the Oracle schema without explicit user approval** —
it is a live legacy production database shared with another system.

---

## 9. Commands

```bash
composer install
composer dev                                  # serve + queue + pail + vite
composer test                                 # config:clear && php artisan test
php artisan serve --host 0.0.0.0              # LAN-accessible (required for device testing)

php artisan route:list --path=api
php artisan route:list --method=GET --except-vendor
php artisan list                              # discover commands
php artisan config:show database.default      # dot-notation config reads

vendor/bin/pint --dirty --format agent        # REQUIRED after modifying any PHP file
vendor/bin/pint --format agent                # full-project fix

vendor/bin/pest                               # or: php artisan test --compact
vendor/bin/pest tests/Feature/AuthTest.php
php artisan test --compact --filter="login rejects invalid credentials"
php artisan make:test --pest CustomerStatementTest   # no directory in the name

php artisan tinker --execute 'User::where("IS_ACTIVATED", 1)->count();'   # SINGLE quotes

php artisan tinker database/oracle/02_ibac_seed.php        # re-seed catalog(93)+settings(10) — idempotent, WRITES: approval required
php artisan tinker database/oracle/03_ibac_bootstrap.php   # foundational grants — WRITES: approval required
```

Notes:
- Run `vendor/bin/pint --dirty --format agent` — **not** `--test`. Just fix the formatting.
- Run the **narrowest** test set covering your change; ask the user to run the full suite after.
- Always pass `--no-interaction` to `make:*` commands.
- `npm run build` / `npm run dev` only matter if you touch Blade/Vite assets. A
  "Unable to locate file in Vite manifest" error means the front-end assets need rebuilding.

---

## 10. Testing (Pest 3)

`tests/Pest.php` binds `Tests\TestCase` to `tests/Feature`, with **`RefreshDatabase` commented out**
(you cannot refresh a legacy Oracle schema). **12 files, 114 tests, 759 assertions.**

| File | Covers |
|---|---|
| `tests/Feature/AuthTest.php` | login validation → 422; bad credentials → 401; `/api/auth/me` and `/api/auth/logout` reject unauthenticated → 401 |
| `tests/Feature/TableQueryTest.php` | `/api/selectFromTable` success shape; 404 unknown table; 422 on an injection attempt; 422 on `limit > 1000`; plus the sealed behaviour (restricted table refused, sensitive columns redacted) |
| `tests/Feature/IbacCoreTest.php` | catalog integrity, resolver rules (admin bypass, `system.*`, literal, module wildcard, deny-by-default), session policy, `EnsurePermission` 401/403 |
| `tests/Feature/UsersAdminTest.php` | user administration: auth required, named-permission 403, validation, self-protection, last-admin guard, activity trail shape |
| `tests/Feature/PermissionSyncTest.php` | `PUT /users/{id}/permissions` atomic replace, unknown codes 422, anti-escalation, session termination on change |
| `tests/Feature/ActiveSessionsTest.php` | session listing shape, idle/expiry derivation, terminate by token and by user, self-session rules |
| `tests/Feature/SecuritySettingsTest.php` | settings read/write, per-type bounds, locked settings, `settings.update` guard |
| `tests/Feature/BusinessRoutesSealedTest.php` | **the route-sealing gate**: no public route outside the three, every business route carries a `permission:` guard, 401/403 samples per module, the invoice envelope |
| `tests/Unit/ArabicWritePathTest.php` | the Arabic **write contract** for Oracle — bound codepoints equal the CP1256 byte list, no raw-byte binding, read-back equality, and `encodeForOracle()` stays deleted. Runs with **no** Oracle connection |
| `tests/Unit/ArabicReadPathTest.php` | the Arabic **read contract** — `fixMojibake()` repairs the Latin-1 mojibake form back to proper Arabic, is **idempotent** (second pass on good text changes nothing, incl. through `cleanData()`), and mixed Arabic+mojibake rows still repair. Runs with **no** Oracle connection |
| `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | skeleton |

> ⚠️ **The IBAC and table-query feature tests need a live Oracle connection.** The controllers and
> tests use `DB::connection('oracle')` directly, while `phpunit.xml` sets `DB_CONNECTION=sqlite` /
> `DB_DATABASE=:memory:` — that env only reaches the *default* connection, so the override never
> applies. They fail on a machine without the instance; that is environmental, **not your regression**.

> 🚫 **IBAC feature tests are deliberately read-only** against the live legacy schema — they never
> create or delete a user. Write routes are covered by their **refusal** branches (422/403/404/409),
> which return before any `INSERT`. Do not add tests that write to `CUST`, `ENTRY_MAIN`, or any other
> ERP table without explicit user approval.

**Nothing covers** customers, workers, accounts, currencies, or biometrics end to end. If you touch a
controller, adding a Pest feature test for it is welcome. Read the `testing-best-practices` skill in
`.agents/skills/` first. Never delete tests without approval.

---

## 11. Code style

- **Pint** enforces formatting (no `pint.json` → default `laravel` preset). Run it before finalizing.
- `.editorconfig`: UTF-8, LF, 4-space indent, final newline; Markdown keeps trailing whitespace;
  YAML 2-space.
- Curly braces on **all** control structures, even single-line bodies.
- Explicit return types and parameter type hints:
  `public function statement(Request $request, int $custNo): JsonResponse`
  (Note: `AccountController` and `CurrencyController` methods currently omit return types —
  `CustomerController`, `WorkerController`, and `AuthController` declare `: JsonResponse`. Prefer
  declaring them in new code.)
- PHP 8 constructor property promotion; no empty zero-parameter `__construct()`.
- TitleCase for enum keys.
- PHPDoc over inline comments; use array-shape types in PHPDoc.
- Descriptive names — `isRegisteredForDiscounts`, not `discount()`.
- Comments in this codebase are **bilingual Arabic/English**; match that in `routes/` and controllers.
- Laravel 12 structure: middleware/exceptions/routing are configured in `bootstrap/app.php`
  (**not** `app/Http/Kernel.php`, which does not exist); console commands in `app/Console/Commands/`
  auto-register; `bootstrap/providers.php` lists providers.
- Casts belong in a `casts()` method, not the `$casts` property.
- When modifying a column in a migration, re-declare **all** its prior attributes or they are lost.
- Use `php artisan make:*` for new files. Check for an existing class to reuse before writing one.

---

## 12. Known issues & traps

### Security

| # | Issue |
|---|---|
| S1 | ~~All business routes were unauthenticated~~ **CLOSED (phase 7)** — every business route now carries `auth:sanctum` + `permission:<code>`; only `POST /auth/login`, `POST /auth/biometric/login` and `GET /hello` are public, and 6 session-ownership routes are auth-only by design (gating `/auth/me` behind a grant would lock out a user who has none). `BusinessRoutesSealedTest` is the gate. |
| S2 | ~~`selectFromTable` exposes arbitrary tables~~ **CLOSED (phase 7)** — behind `system.*`, with an 11-table deny list and `PASSWORD`/`DEVICE_TOKEN`/`TOKEN`/`SECRET`/`KEY` column redaction that reports `redacted_columns`. The injection guard was always sound. |
| S3 | **Plaintext password comparison** in `AuthController@login`, ahead of `Hash::check`. Deliberate legacy support — do not remove without a migration plan for the existing rows. |
| S4 | **Oracle credentials committed** as `config/database.php` defaults (`ACCOUNTS` / `acc`). Real credentials belong in `.env` only; treat this as tech debt, not as a template to copy. |
| S5 | **`expiration => null` is required, not a hole** — lifetime is enforced by `SessionPolicy` on `EXPIRES_AT`. Setting `expiration` re-opens the "kicks active users out" bug and is the one way to break S5 in the other direction. |
| S6 | **No validation** in `WorkerController@storeWorker` / `updateWorker` — direct `$request->only([UPPERCASE columns])` inserts. |
| S7 | **No CORS config published** — the Flutter web build logs preflight warnings. Framework defaults apply. |
| S8 | **Cache payload ceiling: 3000 bytes** — see the `ORA-01461` bullet in §5. |

### Correctness

| # | Issue |
|---|---|
| C1 | **Arabic mojibake** — anything bypassing `ArabicEncoding` renders as garbage; anything bound as **raw CP1256 bytes** is *stored* as `???`. Read: `fixMojibake()`/`cleanData()`. Write and search: `toOracleSearchChars()` only. See §5.1. |
| C2 | **`AccountController@getDetailedStatement` is a stub in disguise.** It reads `from_date`, `to_date` and `cur_no` but **no branch of its `switch ($type)` ever uses them** — they are only echoed back inside `summary`. `$totalDebit` / `$totalCredit` stay `0`, so those two sums are **always 0**; only `total_balance` is real. Pagination applies **only** to the `type=account`-with-no-`id` branch. |
| C3 | **`getAccountStatement` queries `ACC_TRXN`** inside a silently-swallowed `try/catch` returning "جدول الحركات غير موجود", while every other statement uses `ENTRY_MAIN` / `ENTRY_SUB` / `ENTRY_TYPE`. It returns empty transaction lists when the table is absent. |
| C4 | **`getAccountTree` is N+1** — one query per node per level. |
| C5 | ~~`InvoiceController` skips the envelope~~ **CLOSED (phase 7)** — envelope + `cleanData()` + search + explicit 404. |
| C6 | **`MAX(pk)+1` primary keys** on business tables — race-prone. New code should take an explicit `SEQ_*.NEXTVAL` (`UserAdminController@store` does). |
| C7 | **`UserFactory` / `DatabaseSeeder` are broken** against the Oracle `User` model (`name`/`email` do not exist). |
| C8 | **`meta` pagination shape differs** between controller groups — see §4. |
| C9 | **Docs drift**: `devloping_history.md` / `db_commends.md` describe statements over `JOURNAL_ENTRY_REC`; the code uses the base tables. `implementation_plan.md` is referenced by `todo.md` but does not exist. `roles.md` is misnamed — it is an integration-standards reference, not a permissions model. |
| C10 | **`USER_ID` ≠ `USER_NO`** — `USER_NO` was dropped from `APP_USERS`; anything referencing it fails with `ORA-00904`. |
| C11 | **Oracle returns an empty DATE as `false`** — normalise through `SessionPolicy::toDate()` or the request 500s. |

---

## 13. Laravel Boost & agent tooling

Boost is configured via `boost.json`: `agents: ["codex"]`, `mcp: true`, `guidelines: true`,
cloud/sail/nightwatch disabled, skills `infer-conventions`, `laravel-best-practices`,
`testing-best-practices`, `tailwindcss-development`.

- `.codex/config.toml` registers one MCP server: `laravel-boost` via `php artisan boost:mcp`.
- `.agents/rules/appsroles.md` is `roles.md` copied with `trigger: glob` frontmatter.
- `.agents/skills/` holds the four skills (`laravel-best-practices` has 19 rule docs;
  `testing-best-practices` has 9). **Activate the relevant skill when you work in that domain.**
- There is **no `.ai/rules` directory** in this project, despite the Boost guidelines referencing one
  conditionally. Skip that step.
- `composer post-update-cmd` runs `php artisan boost:update`, which regenerates the Boost guideline
  block. That is why the old `AGENTS.md` here was pure boilerplate. **This file replaces it with
  project-specific guidance — keep the two concerns separate; do not let a Boost regeneration
  overwrite it.**

`kyApp_API/‏‏AGENTS - نسخة.md` ("copy") is an **untracked** leftover containing the old generic Boost
guidelines. Its filename begins with RTL mark characters (`U+200F U+200F`) — always quote the path.
It is not a guide; delete it only with the user's approval.

---

## 14. Submodule discipline

`kyApp_API/` is a **git submodule** of `ky_apps` (`github.com/Aporaad/kyApp_API`), with its own
working tree and branch. It also declares a nested submodule `ky_Apps/` pointing back at the Flutter
repo, which is **empty and uninitialized** — ignore it.

```bash
cd kyApp_API && git status          # this repo's own state
git submodule update --init --recursive   # from the parent, after pulling
```

- **Commit and push inside the submodule first**, then commit the pointer bump in the parent —
  otherwise the parent references a commit nobody else can fetch.
- The whole IBAC build (4 new controllers, `app/Http/Middleware/`, the `Support/` classes, the new
  tests, `routes/api.php`, `bootstrap/app.php`, `config/sanctum.php`, this file) is still **uncommitted
  work** in this working tree. Commit only when the user asks.
- Never force-push. Never commit `.env`.

---

## 15. Quick reference

```
Framework      Laravel 12.69 · PHP ^8.2.12
Auth           Sanctum 4 PATs · expiration=null by design · SessionPolicy on EXPIRES_AT
               (idle 240′ sliding, absolute 14d, touch throttle 5′)
Access         IBAC — APP_PERMISSIONS (93 codes / 17 modules) + APP_USERS_PERMISSIONS,
               deny-by-default, IS_ADMIN=1 bypass, permission:<code> guard
Oracle         11g · driver yajra/laravel-oci8 12.0 · schema ACCOUNTS · charset WE8MSWIN1252
Connection     'oracle' (config/database.php:116) · prefix_schema 'accounts'
Encoding       app/Support/ArabicEncoding.php — read fixMojibake/cleanData ·
               write+search toOracleSearchChars · encodeForOracle is DELETED (data loss)
Identity       No 11g identity columns → Sequence+Trigger (4 Laravel + 4 IBAC; unqualified inside
               PL/SQL because a VIEW named ACCOUNTS exists), MAX(pk)+1 for legacy business tables
Cache          3000-byte payload cap (ORA-01461 at 4000) — PermissionResolver / SettingsStore
Envelope       {success, message, data, meta} · snake_case · bilingual messages · no API Resources
Routes         71 endpoints in routes/api.php — 3 public · 6 auth-only · 62 guarded · no names · no /v1
Tests          Pest 3 · 114 tests · RefreshDatabase disabled · read-only against live Oracle
Format         vendor/bin/pint --dirty --format agent
Dev server     php artisan serve --host 0.0.0.0
Health probe   GET /api/hello
Special acct   12310000 — عملاء الكبودي للخياطة
```
