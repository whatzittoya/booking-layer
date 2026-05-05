@echo off
setlocal

set "TASK_NAME=BookingLayer Pull Reservations"
set "PHP_EXE=%PHP_PATH%"
if "%PHP_EXE%"=="" set "PHP_EXE=php.exe"

set "SCRIPT_PATH=%~dp0pull_reservations.php"

schtasks /Create ^
  /F ^
  /SC MINUTE ^
  /MO 10 ^
  /ST 00:00 ^
  /TN "%TASK_NAME%" ^
  /TR "\"%PHP_EXE%\" \"%SCRIPT_PATH%\""

endlocal
