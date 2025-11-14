# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

This is the **Uncanny Automator Developer Tools** plugin - a centralized development toolset for Uncanny Owl WordPress plugins. It provides code quality tools, testing infrastructure, and standardized development workflows for WordPress plugin development.

**Location**: `/wp-content/plugins/automator-dev-tools/` within the Uncanny Automator WordPress installation.

## Architecture

This is a **standalone development tools plugin** that provides:
- **Code Quality Tools**: PHPCS, PHPMD, PHPCPD with Uncanny Owl coding standards
- **CRAP Score Analysis**: Cyclomatic complexity and test coverage analysis  
- **Cross-platform Scripts**: Windows/Unix compatible PR checking tools
- **Testing Framework**: Codeception-based testing infrastructure
- **Automated Workflows**: GitHub Actions for code quality and PR management

The plugin operates on **other Uncanny Owl plugins** in the same WordPress installation, not on itself.

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

# PHP Copy/Paste Detector
composer phpcpd
```

### Testing
```bash
# Run unit tests (excluding full coverage group)
composer unit-tests

# Run all unit tests including full coverage
composer unit-tests-full

# Run tests with coverage report
composer unit-tests:coverage
```

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
automator-dev-tools/          # This plugin
├── bin/                      # Cross-platform executable scripts
│   ├── pr-code-check.php    # Main PR code checking tool
│   ├── crap-score.php       # CRAP analysis tool
│   └── platform-detect.php # OS detection utility
├── build/                    # Testing and build artifacts
└── vendor/                   # Composer dependencies
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

## Important Development Notes

1. **Working Directory**: Scripts change to the plugin root directory (two levels up from this plugin)
2. **Binary Detection**: Handles multiple vendor binary paths and fallbacks
3. **File Filtering**: Only processes existing, non-deleted PHP files
4. **CRAP Analysis**: Focuses on cyclomatic complexity and test coverage metrics
5. **Yoda Conditions**: Follow Yoda-style comparisons per coding standards
6. **KISS Principle**: Keep implementations simple and maintainable