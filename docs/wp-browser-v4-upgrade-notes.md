# `lucatume/wp-browser` v3 → v4 Upgrade Notes

**Status**: investigation only, no work scheduled
**Date**: 2026-04-29
**Trigger**: PHP 8.4 deprecation warning surfacing in test runs

```
Deprecated: tad\WPBrowser\emptyWpTables(): Implicitly marking parameter
$tables as nullable is deprecated, the explicit nullable type must be used
instead in .../uncanny-automator-pro/vendor/lucatume/wp-browser/src/tad/WPBrowser/wp.php on line 45
```

## Source of the warning

The warning originates from `uncanny-automator-pro/vendor/lucatume/wp-browser/`, **not** this `automator-dev-tools` plugin's vendor. The dev-tools `composer.json` also pins `lucatume/wp-browser: ^3.7`, but the runtime warning is coming from the pro plugin's own vendor tree.

If we silence or fix this, the change has to land in **the pro plugin's `composer.json`** (and any other plugin that vendors wp-browser independently).

## Why a patch-level bump won't help

- v3 branch last released Feb 2024, no longer maintained.
- The implicit-nullable signature in `src/tad/WPBrowser/wp.php:45` (`emptyWpTables($tables = null)` without `?array`) is unlikely to be backported.
- Bumping within `^3.7` will not fix it.

## v4 path — what would actually break

Audited: `/Users/saadsiddique/Sites/uncanny-automator/wp-content/plugins/uncanny-automator/tests/`

### In our favor

- **Zero `tad\` namespace usage** anywhere in tests. v4's biggest breaking change (full `tad\` removal) does not touch us.
- **Zero acceptance/functional test files** — both directories are empty even though the suite YAMLs declare `WPBrowser`/`WPDb`/`WPFilesystem`. Those configs can be deleted or stubbed cheaply.
- **No direct `use Codeception\Module\WP*`** imports in tests.
- PHPUnit constraint already at `^9.6` — satisfies v4.5's `>=9.5` floor.

### What needs migration

1. **`tests/_support/Helper/AutomatorTestCase.php:3`**
   `extends \Codeception\TestCase\WPTestCase` → `extends \lucatume\WPBrowser\TestCase\WPTestCase`. One line.

2. **~102 test files extend bare `WPTestCase`**
   Currently resolved via global-namespace fallback. In v4 they likely need an explicit `use lucatume\WPBrowser\TestCase\WPTestCase;` — OR have the shared base class re-export an alias so the rest of the suite stays untouched. Prefer the alias approach to keep the diff small.

3. **`tests/wpunit.suite.yml` — highest-risk file**
   v3 keys in current config: `wpRootFolder`, `dbName`, `dbHost`, `dbUser`, `dbPassword`, `tablePrefix`, `domain`, `adminEmail`, `title`, `plugins`, `activatePlugins`, `configFile`.
   v4 introduces `loadOnly`, an `installation` mode, different `bootstrapActions`/`configFile` semantics, and DB keys may move under a nested `db` block. Get this wrong and zero tests load.

4. **`tests/acceptance.suite.yml` / `tests/functional.suite.yml`**
   Module configs (`WPDb` `dump` is now an array, etc.) differ in v4. Since no tests exist in those suites, simplest fix is to delete or stub the YAMLs until the suites are actually used.

5. **`codeception.dist.yml:11-18`**
   `Codeception\Command\GenerateWP*` moved to `lucatume\WPBrowser\Command\*` in v4. Update the `commands:` list.

### Effort estimate

Realistic: **1–2 hours** of YAML migration + a search/replace pass for the namespace + running the suite and fixing the first wave of failures. The 102 test files are unlikely to need logic changes — only namespace plumbing.

## Decision options

| Option | Effort | Notes |
|---|---|---|
| Silence `E_DEPRECATED` in test bootstrap | Minutes | KISS. Noise from transitive dep, harmless. Recommended unless test-infra refresh is on the roadmap. |
| Downgrade test PHP to 8.3 | Minutes | Same effect, doesn't touch code. |
| Bump to `^4.5` in pro + dev-tools | 1–2 hrs | Proper fix. Own PR. Suite YAML migration is the gate. |
| Composer patch on v3 to add `?array` | Brittle | Not recommended. |

## Files referenced

- `composer.json` (this plugin) — `lucatume/wp-browser: ^3.7`
- `/uncanny-automator-pro/vendor/lucatume/wp-browser/src/tad/WPBrowser/wp.php:45` — actual deprecation site
- `/uncanny-automator/tests/wpunit.suite.yml` — main migration target
- `/uncanny-automator/tests/_support/Helper/AutomatorTestCase.php:3` — base class swap
- `/uncanny-automator/codeception.dist.yml:11-18` — generator command namespace update
- `/uncanny-automator/tests/acceptance.suite.yml`, `/tests/functional.suite.yml` — empty suites, simplify or delete
