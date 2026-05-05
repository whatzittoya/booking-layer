param(
    [string]$TaskName = "BookingLayer Pull Reservations",
    [string]$PhpPath = "php.exe"
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$scriptPath = Join-Path $projectRoot "bin\pull_reservations.php"

if (-not (Test-Path $scriptPath)) {
    throw "Reservation pull script not found: $scriptPath"
}

$action = New-ScheduledTaskAction `
    -Execute $PhpPath `
    -Argument "`"$scriptPath`"" `
    -WorkingDirectory $projectRoot

$trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
$trigger.Repetition = [Microsoft.Management.Infrastructure.CimInstance]::New(
    "MSFT_TaskRepetitionPattern",
    "root/Microsoft/Windows/TaskScheduler",
    @{
        Interval = "PT10M"
        Duration = "P1D"
        StopAtDurationEnd = $false
    }
)

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries

$principal = New-ScheduledTaskPrincipal `
    -UserId $env:USERNAME `
    -LogonType Interactive `
    -RunLevel Limited

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Force

Write-Host "Scheduled task created: $TaskName"
Write-Host "Runs every 10 minutes using: $scriptPath"
