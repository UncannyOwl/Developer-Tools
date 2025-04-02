@echo off
setlocal EnableDelayedExpansion

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
php "!SCRIPT_DIR!pr-code-check.php" %*

if %ERRORLEVEL% NEQ 0 (
    echo Error occurred while running the PHP script. Exit code: %ERRORLEVEL%
    exit /b %ERRORLEVEL%
)

exit /b 0 