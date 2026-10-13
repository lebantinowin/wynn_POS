$ErrorActionPreference = 'Stop'
$projectPath = (Resolve-Path -LiteralPath $PSScriptRoot).Path
$runnerPath = Join-Path $projectPath 'run-backups.ps1'
$powershellPath = Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe'
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$sha = [System.Security.Cryptography.SHA256]::Create()
try { $projectHash = ([System.BitConverter]::ToString($sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($projectPath)))).Replace('-', '').Substring(0, 12) }
finally { $sha.Dispose() }
$taskName = 'WynnMartBackups-' + $projectHash
$arguments = '-WindowStyle Hidden -NoProfile -NonInteractive -File "' + $runnerPath + '" -Scheduled'
$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing -and ($existing.Actions.Count -ne 1 -or $existing.Actions[0].Execute -ne $powershellPath -or $existing.Actions[0].Arguments -ne $arguments)) {
    throw 'A different task already uses this name. No task was changed.'
}
$action = New-ScheduledTaskAction -Execute $powershellPath -Argument $arguments -WorkingDirectory $projectPath
$hourly = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Hours 1)
$logon = New-ScheduledTaskTrigger -AtLogOn -User $identity
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger @($hourly, $logon) -Principal $principal -Settings $settings -Description 'Wynn Mart private PostgreSQL backups; checks the owner-selected schedule.' -Force | Out-Null
$registered = Get-ScheduledTask -TaskName $taskName
if ($registered.State -eq 'Disabled' -or $registered.Actions[0].Arguments -ne $arguments) { throw 'Backup task registration could not be verified.' }
$backupFolder = Join-Path $projectPath 'storage/app/backups'
[System.IO.Directory]::CreateDirectory($backupFolder) | Out-Null
$metadata = @{ installed = $true; taskName = $taskName; installedAt = (Get-Date).ToUniversalTime().ToString('o') }
$metadataPath = Join-Path $backupFolder 'scheduler.json'
$temporaryPath = $metadataPath + '.tmp'
[System.IO.File]::WriteAllText($temporaryPath, ($metadata | ConvertTo-Json), (New-Object System.Text.UTF8Encoding($false)))
Move-Item -LiteralPath $temporaryPath -Destination $metadataPath -Force
Write-Output 'Automatic backup task registered for your Windows account. Review Settings for backup status.'
