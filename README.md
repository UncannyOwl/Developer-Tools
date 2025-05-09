# Uncanny Automator Developer Tools

Centralized development tools for Uncanny Owl plugins, with namespaced Codeception to avoid global conflicts.

## Installation

```bash
composer require uncanny-owl/developer-tools
```

## Using the TestDetection utility

The TestDetection utility provides an isolated way to detect when code is running in a test environment, without interfering with other plugins that may also use Codeception.

```php
use UncannyOwl\DevTools\TestDetection;

if (TestDetection::isTestRunning()) {
    // Code that should only run during tests
}
```

## How it works

This package uses PHP-Scoper to prefix all Codeception dependencies with the `UncannyOwl\DevTools\Vendor` namespace. This means:

1. Your plugin can detect Codeception tests using our namespaced version
2. Other plugins can still use the global Codeception classes without conflicts
3. The detection is isolated and won't interfere with other plugins

## Release Process

For maintainers, follow these steps to create a new release:

1. Make your code changes on a development branch
2. Update version numbers in composer.json if needed
3. Run the prepare-release script with your version number:

```bash
# Prepare the release
composer run prepare-release
./prepare-release.sh 1.0.0  # Replace with your version

# Follow the instructions printed by the script to push the release
```

The script will:
- Create a release branch
- Build the prefixed version of dependencies
- Include the build directory in the release
- Commit the changes
- Provide instructions for tagging and pushing the release

Once you push the tag, Packagist will automatically update with the new version. 