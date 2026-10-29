# wp-test-kernel — third-party code

`kernel/core-phpunit/` is a copy of the WordPress core PHPUnit test library as packaged by
[lucatume/wp-browser](https://github.com/lucatume/wp-browser) (`includes/core-phpunit/`), taken at commit
`26eab0f282a7cc7608b8cafa43d50dcfe0897366` (2026-09-29).

- wp-browser: MIT License, Copyright (c) 2020 Luca Tumedei.
- The WordPress test library it packages: GPL-2.0-or-later, Copyright the WordPress contributors.

Local changes are marked `uncanny-kernel:` in the code:

| File | Change |
|---|---|
| `includes/phpunit-adapter-testcase.php` | Extends `PHPUnit\Framework\TestCase` directly and maps the fixture methods to WordPress' snake_case ones (the Yoast polyfills stop at PHPUnit 12). |
| `includes/abstract-testcase.php` | No wp-browser `WPTestCase` dependency; deprecation expectations read from kernel attributes and docblock tags (`Expectations::for_test()`), since PHPUnit 12+ has no docblock parser. |
| `includes/testcase-*.php` | Namespace `lucatume\WPBrowser\TestCase` → `Uncanny_Owl\Wp_Test_Kernel\TestCase`. |
| `includes/functions.php`, `includes/bootstrap.php` | Core-suite-only hooks (unhooking core blocks, fonts, icons, connectors and abilities; blocking auth cookies; disabling updaters; Spy_REST_Server) run only when `WP_RUN_CORE_TESTS` is set, so plugin tests run against an unmodified WordPress — as they did on wp-browser v3. |

To update: copy the new upstream `includes/core-phpunit/`, re-apply the marked changes, run `composer test:kernel`,
then the consuming plugin's parity check (`wp-test-kernel parity`) before merging.
