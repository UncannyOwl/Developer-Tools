@echo off
setlocal EnableDelayedExpansion

REM This is a Windows-specific script to run PHP code checks
REM Usage: run-phpcs.cmd [phpcs|phpcbf]

REM Validate arguments
if "%~1"=="" (
    echo Usage: run-phpcs.cmd [phpcs^|phpcbf]
    exit /b 1
)

if not "%~1"=="phpcs" if not "%~1"=="phpcbf" (
    echo Error: First argument must be either "phpcs" or "phpcbf"
    echo Usage: run-phpcs.cmd [phpcs^|phpcbf]
    exit /b 1
)

REM Get the directory where this batch file is located
set "SCRIPT_DIR=%~dp0"

REM Verify PHP is available
where php >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo Error: PHP is not available in your PATH.
    echo Please ensure PHP is installed and added to your system PATH.
    exit /b 1
)

REM Execute PHP explicitly with the full path to the script
echo Running PR code check with %~1...
php -f "!SCRIPT_DIR!pr-code-check.php" %1

if %ERRORLEVEL% NEQ 0 (
    echo Error occurred while running the PHP script. Exit code: %ERRORLEVEL%
    exit /b %ERRORLEVEL%
)

echo Completed successfully.
exit /b 0 