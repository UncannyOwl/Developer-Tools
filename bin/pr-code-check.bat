@echo off
setlocal

REM Get the directory where this batch file is located
set "SCRIPT_DIR=%~dp0"

REM Use PHP to execute the script directly with all arguments
php "%SCRIPT_DIR%pr-code-check.php" %* 