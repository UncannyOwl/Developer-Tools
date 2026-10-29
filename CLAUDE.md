# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

This is the **Uncanny Automator Developer Tools** plugin - a centralized development toolset for Uncanny Owl WordPress plugins. It provides code quality tools, testing infrastructure, and standardized development workflows for WordPress plugin development.

**Location**: `/wp-content/plugins/automator-dev-tools/` within the Uncanny Automator WordPress installation.

## Architecture

This is a **standalone development tools plugin** that provides:
- **Code Quality Tools**: PHPCS and PHPMD with Uncanny Owl coding standards
- **CRAP Score Analysis**: Cyclomatic complexity and test coverage analysis  
- **Cross-platform Scripts**: Windows/Unix compatible PR checking tools
- **WordPress test kernel** (`kernel/`, `bin/wp-test-kernel`): WordPress tests on PHPUnit 13, run by PHPUnit, ParaTest or Pest 5, one database per worker, booted from a cached snapshot
- **Mutation testing** (`bin/mutate`, Infection) and **Plugin Check** (`bin/plugin-check.php`)
- **Automated Workflows**: GitHub Actions for code quality and PR management

### Versions

- `main`: the test kernel. Development and CI need PHP 8.4; a consuming plugin's own PHP floor and runtime packages do not change. Automator Free and Pro require `dev-main`.
- `1.x`: the Codeception 4 + wp-browser v3 (PHPUnit 9) toolset as `main` had it before the kernel (7b03368), for plugins whose tests still run on Codeception. They require `dev-1.x`. Fixes only; a plugin leaves `1.x` when its tests move to the kernel.

The plugin operates on **other Uncanny Owl plugins** in the same WordPress installation, not on itself.

- **Hook Documentation Toolchain**: Automated pipeline to extract, AI-enrich, and publish hook docs. See [`docs/hook-docs-toolchain.md`](docs/hook-docs-toolchain.md) for complete usage guide.

## Essential Commands

### Code Quality & Standards
```bash
# Run PHPCS on all files
composer phpcs

# Auto-fix coding standards
composer phpcbf

# Run PHPCS on PR changes only
composer phpcs:pr

# Auto-fix PR changes only  
composer phpcbf:pr

# CRAP score analysis (all files)
composer crap-score

# CRAP score analysis (PR changes only)
composer crap-score:pr

# PHP Mess Detector
composer phpmd
```

### Testing
```bash
# The kernel's own tests (attributes, config and profiles, JUnit reports and parity, mutate)
composer test:kernel

# From a consuming plugin: its test scripts (unit-tests, unit-tests:parallel, mutate, ...) run the kernel
composer unit-tests
```

A consuming plugin points PHPUnit's bootstrap at its own file, which calls the kernel's `Boot` with the plugin's profiles; Automator Free's `tests/bootstrap-wordpress.php` and its `docs/reference/testing.md` are the worked example.

Which WordPress the tests run on: with `wp_root` unset (no `WP_ROOT_FOLDER`), the kernel's own, release `wp_version` from wordpress.org (`kernel/src/Wordpress_Core.php`): downloaded once per machine into `~/.cache/wp-test-kernel` (or `$XDG_CACHE_HOME`, or `WP_TEST_KERNEL_DOWNLOADS`), checked against wordpress.org's SHA-1, extracted once per checkout beside it, never inside the checkout. Its `wp-content` is the release's own, so a dev site's must-use plugins, drop-ins and themes do not reach the tests. `WP_ROOT_FOLDER` names an existing install instead. Either way plugins load from the folder holding the checkout (`plugins_dir`). Only PHP 8.4 and a MySQL user that can create databases are needed (`host`, `host:port` or `localhost:/path/to/socket`).

### Integration lint
```bash
# Lint one integration (Free + Pro); P0/P1 fail, P2 and reports do not
php bin/lint-integration.php --plugin-path /path/to/free --pro-path /path/to/pro --slug foo-bookings

# Integrations changed since the merge base with origin/pre-release; existing ones gated on changed lines only
php bin/lint-integration.php --plugin-path /path/to/free --pro-path /path/to/pro --changed --format=github

# Apply the mechanical fixes (L-login, L-hookconst)
php bin/lint-integration.php --plugin-path /path/to/free --slug foo-bookings --fix

# From a consuming plugin: composer lint:integration foo-bookings

# The lint's own tests
composer test:lint
```
Every finding carries a rule id from `.claude/skills/integration-rules` in the Automator repo and a check id. Checks live in `bin/lint/checks.php`; add a fixture case under `tests/fixtures/lint/` and a test before adding a check.

### Plugin Check (WordPress.org)
```bash
# Build the plugin's release zip (config `build`), run Plugin Check on it, fail on errors the baseline does not hold
php bin/plugin-check.php --plugin-path /path/to/free --build --wp-path /path/to/wordpress

# Check a zip you already built; GitHub annotations plus a Markdown step summary; checks split over 4 processes
php bin/plugin-check.php --plugin-path . --zip zip-package/foo.zip --wp-path … --jobs 4 --format=github --summary-file "$GITHUB_STEP_SUMMARY"

# Accept the current errors as the baseline (after fixing some, or after bumping plugin-check)
php bin/plugin-check.php --plugin-path . --build --wp-path … --update-baseline

# Gate a saved run again without WordPress (what the tests do)
php bin/plugin-check.php --plugin-path . --results plugin-check-results.json

# From a consuming plugin: WP_PATH=/path/to/wordpress composer plugin-check
```
Needs a WordPress install with the `plugin-check` plugin active; the checked plugin need not be installed there, so only static checks run. The plugin keeps `.plugin-check/config.json` (slug, mode, build command, zip path, ignores and excluded checks, each with a `reason`) and `.plugin-check/baseline.json` (errors by path, code and message, counted; no line numbers, so moved code keeps its entry). A new ERROR exits 1; warnings, ignored rows and baselined errors never fail; `--strict` also fails when baselined errors no longer occur. Pure logic is in `bin/plugin-check/functions.php`; tests in `tests/plugin-check/` with fixtures in `tests/fixtures/plugin-check/`.

### Cross-platform PR Tools
```bash
# Check PR code quality (PHPCS)
php bin/pr-code-check.php phpcs

# Auto-fix PR code (PHPCBF)  
php bin/pr-code-check.php phpcbf

# Platform-specific execution
./bin/pr-code-check         # Unix/Mac
bin/pr-code-check.bat      # Windows
```

## Key Architecture Details

### Project Structure
```
automator-dev-tools/
├── bin/
│   ├── pr-code-check.php       # PR code quality checker
│   ├── crap-score.php          # CRAP complexity analysis
│   ├── generate-item-map.php   # Integration item catalog builder
│   ├── generate-load-files.php # Integration loader generator
│   ├── stamp-version.php       # Bleeding-edge version stamper
│   ├── lint-integration.php    # Integration lint: house rules by id (R-…/L-…), Free + Pro
│   ├── lint/                   # The lint's context, checks, fixes and reporters
│   ├── plugin-check.php        # WordPress.org Plugin Check on the release zip, gated by a baseline
│   ├── plugin-check/           # The gate's pure functions: normalise, ignore, baseline, render
│   ├── scan-hooks.php          # Hook documentation scanner (ripgrep + PHP)
│   ├── enrich-hooks.py         # Hook documentation AI enricher (Python)
│   └── sync-hooks.php          # Hook documentation WordPress sync
├── docs/
│   └── hook-docs-toolchain.md  # Full hook docs pipeline guide
├── tests/
│   ├── lint/                   # PHPUnit tests for the integration lint
│   ├── plugin-check/           # PHPUnit tests for the Plugin Check gate (saved results, no WordPress)
│   ├── fixtures/lint/          # A clean and a dirty fixture integration (Free + Pro)
│   └── fixtures/plugin-check/  # A plugin's .plugin-check/ config and baseline, and saved results
├── build/                      # Testing and build artifacts
├── .env.example                # AI provider config template
└── vendor/                     # Composer dependencies
```

### Code Quality Standards
- Uses **Uncanny-Automator** PHPCS standard from `uocs/uncanny-owl-coding-standards`
- CRAP Score threshold management for complexity analysis
- Excludes: `/tests/`, `/vendor/`, `/node_modules/`
- Targets: `.php` files only

### Cross-platform Compatibility
Scripts handle Windows/Unix differences for:
- Binary paths (`.bat` extensions on Windows)
- File path separators
- Command execution methods

### PR Workflow Integration
- **Target Branch**: `pre-release` (not `main`)
- Automated PR merging via GitHub Actions
- Code quality gates before merging
- Git diff analysis: `origin/pre-release...`

### WordPress Plugin Context
This plugin is designed to work **within** the Uncanny Automator WordPress ecosystem:
- **Working Directory**: Plugin root, NOT this dev-tools directory
- **Target Files**: Other Uncanny Owl plugins in the same installation
- **Configuration**: Project root contains vendor dependencies and coding standards

### Hook Documentation
```bash
# Scan hooks from a plugin (outputs to {plugin}/hook-docs/docs/)
php bin/scan-hooks.php --plugin-path /path/to/plugin

# Scan free + pro (each repo gets its own hook-docs/)
php bin/scan-hooks.php --plugin-path /path/to/free --pro-path /path/to/pro

# Dry run — stats only, no files written
php bin/scan-hooks.php --plugin-path /path/to/plugin --dry-run

# AI enrich (fill {{PLACEHOLDERS}} in .md files)
python3 bin/enrich-hooks.py --input /path/to/hook-docs/docs

# Enrich multiple plugin paths
python3 bin/enrich-hooks.py --input hook-docs/docs ../pro/hook-docs/docs

# Sync to WordPress docs site
php bin/sync-hooks.php --input hook-docs/docs --wp-path /path/to/wordpress

# Full pipeline via composer (from consuming plugin)
composer generate-hook-docs
```

See [`docs/hook-docs-toolchain.md`](docs/hook-docs-toolchain.md) for complete documentation.

## Important Development Notes

1. **Working Directory**: Scripts change to the plugin root directory (two levels up from this plugin)
2. **Binary Detection**: Handles multiple vendor binary paths and fallbacks
3. **File Filtering**: Only processes existing, non-deleted PHP files
4. **CRAP Analysis**: Focuses on cyclomatic complexity and test coverage metrics
5. **Yoda Conditions**: Follow Yoda-style comparisons per coding standards
6. **KISS Principle**: Keep implementations simple and maintainable