# Review: Test Suite and DevOps Setup — xml2emmet HTTP API

## Overview

This review covers the test infrastructure and DevOps configuration for a PHP 8.2 frameworkless JSON HTTP API. The system has three distinct test layers: pure-unit engine tests (no I/O), Db-layer integration tests (real MySQL), and full HTTP integration tests driven by `php -S` + cURL. The engine layer is well-engineered. The infrastructure surrounding it has concrete bugs and structural omissions that will cause pain at CI scale.

---

## What Exists

| Layer | Test Files | Test Count | Dependencies |
|---|---|---|---|
| Engine (pure unit) | `EmmetParseTest`, `EmmetEmitTest`, `XmlParseTest`, `XmlEmitTest`, `ApplyRulesTest`, `ClickOpsTest`, `RoundTripTest`, `StatsTest`, `NodeTest`, `SmokeTest` | ~97 test methods | None |
| Support | `NodeAssertTest`, `NodeAssert` (helper) | 5+ | None |
| Db integration | `UserStoreTest`, `RuleStoreTest`, `HistoryStoreTest`, `DbTest` | ~15 | Live MySQL |
| Http unit (in-process) | `RouterTest`, `ValidationTest`, `RequestTest`, `ResponseTest`, `JsonTest`, `NodeJsonTest` | ~20 | None |
| Http integration | `AuthHttpTest`, `TransformHttpTest`, `RulesHttpTest`, `HistoryHttpTest`, `StatsHttpTest`, `SmokeHttpTest` | ~30 | Live MySQL + `php -S` |
| DevOps | `phpunit.xml`, `docker-compose.yml`, `bin/migrate.php` | — | — |

`phpunit.xml` defines a single test suite named `engine` pointing at the entire `tests/` directory — the name is misleading and there is no suite split.

---

## What's Good

**Engine test thoroughness.** The parameterised-style coverage of the parsing and emission layers is genuine: EmmetParseTest covers 24 cases (A1–A20 plus edge-case suffixed variants like A4b, A5b, A6b), ApplyRulesTest covers the full E1–E8 range including placeholder substitution and attribute-subset semantics, and ClickOpsTest covers 19 cases including the subtle move-with-sibling-shift and source-before-destination shift edge cases. RoundTripTest's 6 canonical G1–G6 pairs give a regression anchor across the full parse-emit pipeline.

**`NodeAssert` is a smart investment.** Rather than comparing serialised strings, `tests/Support/NodeAssert.php` provides structural equality on `Node` trees, making assertion failures readable and explicitly catching attribute-order sensitivity. Having its own test file (`NodeAssertTest`) means the helper itself is pinned against regression.

**Db-layer isolation.** `DbTestCase::setUp` issues `SET FOREIGN_KEY_CHECKS=0`, truncates `transformations`, `rules`, and `users` in that order, then restores the check. This gives each individual test method a clean slate without needing a transaction rollback strategy.

**Cross-user security tested at two independent layers.** `RuleStoreTest::testCrossUserIsolation` proves user A cannot read, update, or delete user B's rules at the SQL level. `RulesHttpTest::testCrossUserIsolation` and `HistoryHttpTest::testDetailFetchAndCrossUser404` pin the same guarantee at the HTTP level. These tests existing at both layers means a regression in either the store or the handler will be caught independently.

**HTTP integration error path coverage.** `TransformHttpTest` covers parse error → 422, invalid click-op path with `op_index` in body → 422, non-owned `rule_id` → 404, and `Content-Length > 2 MB` → 413. The payload size test uses `str_repeat('<x/>', 600_000)` which actually exceeds the 2 MB limit, making it a genuine test of the front-controller guard.

**`registerAndLogin` helper keeps per-test boilerplate minimal.** `HttpTestCase::registerAndLogin` does a single POST and returns the `user.id`, so each Http test starts from a clean authenticated state in one line.

**In-process Http unit tests are fast and reliable.** `RouterTest` covers static dispatch, parameterised capture (`{id}`), 404, 405, and both gate paths (`gate: true` with `userId = null` → 401, with `userId = 7` → 200 + injected uid) — all without a network round-trip.

---

## What's Bad

### 🔴 No CI/CD configuration

There is no `.github/workflows/` directory, no `Makefile`, no `Jenkinsfile`, and no `.travis.yml`. Every developer runs tests manually. A broken branch is undetected until a reviewer pulls it. This is the highest-impact gap: none of the bugs below are caught automatically on push.

### 🔴 TOCTOU port race in `HttpTestCase::setUpBeforeClass`

Lines 17–21 of `HttpTestCase.php`:

```php
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$name = stream_socket_get_name($sock, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fclose($sock);   // <-- port is released here
// ... gap ...
$cmd = sprintf('php -S 127.0.0.1:%d -t %s', $port, ...);
$proc = proc_open($cmd, $descs, $pipes, ...);
```

`fclose` releases the ephemeral port before `php -S` claims it. On a loaded CI runner or a developer machine running parallel PHPUnit processes, another process can bind the port in this window. The readiness loop (lines 33–39) that polls `stream_socket_client` only helps when `php -S` is slow to start — it does not help when the port was stolen before `php -S` even launches. The result is intermittent "connection refused" failures with no diagnostic message.

### 🟡 Six separate `php -S` processes spawned per test run

Each of the 6 Http test classes inherits `HttpTestCase::setUpBeforeClass`, which forks a separate `php -S` process on a separate port. This means 6 port allocations, 6 `proc_open` calls, and 6 three-second readiness timeout windows per `vendor/bin/phpunit` invocation. As the Http suite grows, the startup overhead compounds and parallel execution becomes harder to reason about.

### 🟡 `curl_exec()` silent failure in `HttpTestCase::request`

Line 75 of `HttpTestCase.php`:

```php
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$hSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);
$rawHeaders = substr($raw, 0, $hSize);
```

`curl_exec` returns `false` on timeout or connection error. `substr(false, 0, 0)` produces `''`; `$code` is 0; `json_decode` of the empty body returns `null`. The test then fails with `assertSame(200, 0)` or `assertNotEmpty(null)` — messages that give no indication the HTTP transport itself failed. This turns server startup problems into cryptic assertion failures.

### 🟡 `phpunit.xml` has a single all-inclusive suite named `engine`

```xml
<testsuites>
  <testsuite name="engine">
    <directory>tests</directory>
  </testsuite>
</testsuites>
```

The suite name implies pure engine tests but the directory includes the Db integration tests and all 6 Http integration test classes. Running `vendor/bin/phpunit` always attempts to connect to MySQL and spawn 6 `php -S` processes. There is no way to run only the offline unit tests without modifying the configuration file each time.

### 🟡 No coverage configuration in `phpunit.xml`

There is no `<coverage>` element and no `<source>` element. Xdebug/PCOV are presumably installed in the Docker image (the `php-code-coverage` package is in `vendor/`) but PHPUnit has no source tree to instrument, no threshold to enforce, and no report format to produce. Coverage regressions are invisible.

### 🟡 `docker-compose.yml` compiles PHP extensions on every container start

```yaml
app:
  image: php:8.2-cli
  command: >
    sh -c "
      docker-php-ext-install pdo pdo_mysql &&
      ...
    "
```

`docker-php-ext-install` compiles `pdo_mysql` from source on every `docker compose up`. This adds 30–120 seconds to every container start, including every CI run that uses this compose file. There is no custom Dockerfile for the `app` service.

### 🟢 `declare(strict_types=1)` missing from 12 test files

The 11 engine-layer test files (`ApplyRulesTest`, `ClickOpsTest`, `EmmetEmitTest`, `EmmetParseTest`, `RoundTripTest`, `NodeTest`, `SmokeTest`, `StatsTest`, `XmlParseTest`, `XmlEmitTest`) and both Support files (`NodeAssert.php`, `NodeAssertTest.php`) omit `declare(strict_types=1)`. Since production code already declares it, the practical risk in pure test files is limited — a wrong type passed from the test file to a production function will still cause a `TypeError` in the production function. However, `NodeAssert.php` is a test helper containing assertion logic: type coercions inside that file's own comparisons could silently produce wrong equality results.

### 🟢 `ValidationTest` does not cover `optionalBool` or type mismatch on `requireString`

`optionalBool` has no unit test in `ValidationTest`. Passing a non-string value (e.g., an integer) to `requireString` is also untested at the unit level; coverage for these paths flows only through the Http integration tests.

---

## Bugs & Risks

### 🔴 TOCTOU port race — `HttpTestCase::setUpBeforeClass` lines 17–21

Described above under What's Bad. On a machine with parallel PHPUnit workers or high port churn, another process can claim the ephemeral port between `fclose($sock)` and `proc_open($cmd)`. Causes intermittent "php -S did not become ready" failures that are impossible to reproduce deterministically.

**Fix:** Pass `0` as port to `php -S` and parse the actual assigned port from the server's stderr line `PHP … Development Server (http://127.0.0.1:PORT) started`. This removes the race entirely. An alternative is `SO_REUSEPORT`, but PHP's `stream_socket_server` does not expose it portably.

### 🟡 `curl_exec()` silent failure — `HttpTestCase::request` line 75

`$raw = curl_exec($ch)` returns `false` on timeout or connection refusal. No check follows. Subsequent `substr(false, ...)` produces an empty string; `$code` becomes 0.

**Fix:**
```php
$raw = curl_exec($ch);
if ($raw === false) {
    self::fail('curl_exec failed on ' . $method . ' ' . $path . ': ' . curl_error($ch));
}
```

### 🟡 Migration partial-apply risk — `bin/migrate.php` lines 35–46

The migration loop splits on `/;\s*\n/` and executes each statement individually. The `schema_migrations` INSERT occurs only after all statements succeed. If a later `CREATE TABLE` in a multi-statement migration fails (or if a future migration adds an `ALTER TABLE`), the first statements have already auto-committed (MySQL DDL is non-transactional). On the next run, the first statement fails because the table already exists, `exit(1)` fires, and the migration is permanently stuck.

**Correct fix:** Add `IF NOT EXISTS` to all DDL statements in the SQL files, or record the migration as applied before executing statements and delete the record on failure. Wrapping DDL in a PDO transaction does not work — MySQL DDL causes an implicit commit.

### 🟢 `$headers` array clobbers duplicate header names — `HttpTestCase::request` lines 83–86

```php
$headers[trim(substr($line, 0, $p))] = trim(substr($line, $p + 1));
```

Duplicate header names (e.g., `Set-Cookie`) overwrite each other in `$headers`. The cookie-jar logic on lines 87–99 iterates the raw string correctly and maintains `$this->cookies` accurately, so no current test is broken. However, any future test that reads `Set-Cookie` from the returned `$headers` array will silently see only the last one. This is a latent correctness issue in the API surface of `request()`.

---

## Missing Features

| Gap | Scope | Severity |
|---|---|---|
| No CI configuration | DevOps | 🔴 |
| No coverage reporting | DevOps | 🟡 |
| No integration test for unauthenticated access to gated endpoints | HTTP integration | 🟡 |
| `show_text=false` not tested at HTTP layer | HTTP integration | 🟡 |
| `show_attr_values=false` not tested at HTTP layer | HTTP integration | 🟡 |
| `emmet2xml` with `save=true` not tested end-to-end | HTTP integration | 🟡 |
| Multiple sequential click-ops not tested via HTTP | HTTP integration | 🟢 |
| Non-integer/zero `id` in path (`/api/rules/abc`, `/api/history/0`) not tested | HTTP integration | 🟢 |
| `per_page` default (50) not tested — only clamped-to-100 path | HTTP integration | 🟢 |
| No fast/offline suite split in `phpunit.xml` | DevOps | 🟡 |

**Unauthenticated gate testing** deserves emphasis. `RouterTest::testGateBlocksUnauthenticated` pins the gate at unit level, and `AuthHttpTest::testMeWhileAnonymousIs401` pins it for `GET /api/auth/me`. But there is no integration test confirming that `POST /api/transform`, `GET /api/rules`, `GET /api/history`, or `POST /api/stats` return 401 without a session cookie. If the gate were accidentally removed from the route registration for any of these endpoints, no test would catch it.

**Filter paths at HTTP layer:** `TransformHttpTest::testShowAttrsToggle` covers `show_attrs=false` but there is no equivalent test for `show_text=false` (drops `#text` nodes in `TransformHandler::filterTree`) or `show_attr_values=false` (blanks attribute values while keeping keys). These two code paths in the handler are exercised only by the engine unit tests, not by an end-to-end HTTP call.

---

## Improvement Ideas

1. **Fix the port race.** Replace the fclose-then-pass pattern with: spawn `php -S 127.0.0.1:0 -t public`, read lines from `$pipes[2]` (stderr) until a line matching `/http:\/\/127\.0\.0\.1:(\d+)/` appears, extract the port, use that. This eliminates the TOCTOU window entirely.

2. **Add a GitHub Actions workflow.** A minimal `jobs.test` on `ubuntu-latest` with `services: mysql:8.0`, `composer install`, `php bin/migrate.php`, `vendor/bin/phpunit` is approximately 20 lines of YAML and gives automatic CI on every push and PR.

3. **Build a custom Docker image.** Add a `Dockerfile` (`FROM php:8.2-cli`, `RUN docker-php-ext-install pdo pdo_mysql`) and change `docker-compose.yml` to `build: .` instead of `image: php:8.2-cli`. This reduces every `docker compose up` by 60–120 seconds for the extension compile step.

4. **Split `phpunit.xml` into two suites:**
   ```xml
   <testsuite name="unit">
     <directory>tests/Http</directory>   <!-- RouterTest, ValidationTest, etc. -->
     <directory>tests</directory>
     <exclude>tests/Db</exclude>
     <exclude>tests/Http/AuthHttpTest.php</exclude>
     <!-- ... exclude all *HttpTest.php -->
   </testsuite>
   <testsuite name="integration">
     <directory>tests/Db</directory>
     <directory>tests/Http</directory>
   </testsuite>
   ```
   A developer without a MySQL instance can run `vendor/bin/phpunit --testsuite unit` offline in milliseconds.

5. **Add coverage configuration to `phpunit.xml`:**
   ```xml
   <coverage>
     <include>
       <directory>src</directory>
     </include>
     <report>
       <clover outputFile="coverage.xml"/>
     </report>
   </coverage>
   ```
   And enforce a threshold with `<coverage processUncoveredFiles="true" pathCoverage="false"/>` plus a minimum line coverage check in CI.

6. **Guard `curl_exec` against false.** Add `if ($raw === false) { self::fail('curl_exec failed on ' . $method . ' ' . $path . ': ' . curl_error($ch)); }` immediately after line 75 in `HttpTestCase::request`. This turns silent transport failures into actionable messages.

7. **Fix migration partial-apply.** Add `IF NOT EXISTS` to all `CREATE TABLE` statements in `src/schema/*.sql`. This makes re-running a partially applied migration safe. For `ALTER TABLE` statements in future migrations, the compensating-delete approach (record the migration applied, delete on rollback) or idempotent guards are necessary.

8. **Add an unauthenticated gate integration test.** Add a `testUnauthenticatedAccessReturns401` method (in `AuthHttpTest` or a dedicated `GateHttpTest`) that calls `POST /api/transform`, `GET /api/rules`, `GET /api/history`, and `POST /api/stats` with `$this->cookies = []` and asserts 401 for each.

9. **Remove or repurpose `SmokeHttpTest`.** `testFullJourney` does test the workflow cohesion of register → create rule → transform with save → retrieve history detail with `rule_ids` intact — this is a genuine cross-feature path. However, it requires its own dedicated `php -S` process. Move it into a `@group smoke` annotation and exclude it from the default run, or absorb the cross-feature assertion into `TransformHttpTest::testSaveCreatesHistoryRow` (which already chains transform → history list).

10. **Add `declare(strict_types=1)` to all 12 engine-layer test and Support files.** This is a one-line change per file. The risk is low for pure test files but real for `NodeAssert.php`.

---

## Test Coverage

**Engine layer (strong):** All major parse and emit paths are covered with explicit case identifiers (A1–A20, E1–E8, G1–G6). Edge cases including valueless attributes, multi-text-block concatenation, sibling shift on move, and the depth histogram in `StatsTest` are pinned. The `NodeAssert` helper gives structural diffs rather than string comparisons, making failures readable.

**Db layer (good):** CRUD, cross-user isolation, and JSON round-trip for `settings`/`rule_ids` are covered for all three stores. Table truncation per test method provides genuine isolation.

**Http unit layer (good):** `RouterTest` covers all dispatch paths including both gate branches. `ValidationTest` covers `requireString`, `requireInt` with range, `requireMatch`, and `requireEnum` — but not `optionalBool` or type mismatch inputs.

**Http integration layer (partial gaps):**
- `show_text=false` and `show_attr_values=false` filter paths: untested at HTTP layer
- `emmet2xml` + `save=true`: not exercised end-to-end
- Auth gate on non-auth endpoints: not integration-tested
- Multiple sequential click-ops: unit-level only
- `per_page` default of 50: untested
- Non-numeric path `id` (`/api/rules/abc`): untested

**No coverage measurement tooling is configured.** `phpunit/php-code-coverage` is installed in `vendor/` but `phpunit.xml` has no `<coverage>` element, no `<source>`, and no threshold. Actual line/branch coverage figures are unknown.

---

## Verdict

The engine unit tests are genuinely well-written — thorough case numbering, a custom structural assertion helper, good edge-case coverage, and clean isolation. The Db-layer tests are solid. The problems are concentrated in infrastructure: there is no CI, the Docker setup compiles extensions on every start, `phpunit.xml` has a single misnamed suite that always runs the full integration stack, the port-acquisition logic in `HttpTestCase` has a real race condition, and silent `curl_exec` failures produce opaque test output. None of these individually breaks the test suite today, but together they make the suite brittle to run in automated environments and painful to iterate on locally. The migration partial-apply scenario is a real operational risk for any multi-statement future migration. The missing unauthenticated-gate integration tests leave a meaningful security regression undetected at the integration level.

**Rating: acceptable.** The core test logic is sound and the security-sensitive paths are well-considered. The infrastructure issues are all fixable in a focused sprint; none require redesigning the test architecture. The highest-priority items are: add CI, fix the port race, add `curl_exec` error handling, and split `phpunit.xml` into `unit` and `integration` suites.
