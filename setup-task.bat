@echo off
REM ------------------------------------------------------------------
REM  NSE Screener: run snapshot.php every minute, Mon-Fri 09:14-15:36,
REM  so breakout counting starts at market open even with no browser open.
REM
REM  Put this file next to snapshot.php and double-click it once.
REM  To remove the task later:  schtasks /delete /tn "NSE Screener" /f
REM ------------------------------------------------------------------

set "PHP=C:\xampp\php\php-win.exe"
if not exist "%PHP%" set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" (
  echo PHP not found in C:\xampp\php. Open this file and fix the PHP path.
  pause
  exit /b 1
)

set "SCRIPT=%~dp0snapshot.php"
if not exist "%SCRIPT%" (
  echo snapshot.php not found next to this file: %SCRIPT%
  pause
  exit /b 1
)

schtasks /create /f /tn "NSE Screener" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:14 /ri 1 /du 06:22 /tr "\"%PHP%\" -f \"%SCRIPT%\""
if errorlevel 1 (
  echo Could not create the task.
  pause
  exit /b 1
)

echo.
echo Done. snapshot.php will run every minute on weekdays, 09:14 to 15:36.
echo Check it in Task Scheduler under "NSE Screener".
pause
