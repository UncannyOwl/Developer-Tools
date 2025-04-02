@echo off
setlocal

REM Get the directory where this batch file is located
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%..\.."

REM Change to the project root directory
cd /d "%PROJECT_ROOT%"

REM Check if PHP is available
where php >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo Error: PHP is not found in your system PATH.
    echo Please ensure PHP is installed and added to your system PATH.
    exit /b 1
)

REM Execute the PHP script with the provided arguments
php "%SCRIPT_DIR%pr-code-check.php" %* 