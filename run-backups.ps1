param([switch]$Scheduled)

$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
try {
    $phpCommand = Get-Command php -ErrorAction Stop
    $backupArguments = @('-d', 'extension=mbstring', '-d', 'extension=fileinfo', '-d', 'extension=pdo_pgsql', 'artisan', 'pos:backup', '--no-interaction')
    if ($Scheduled) { $backupArguments += '--scheduled' }
    & $phpCommand.Source @backupArguments
    if ($LASTEXITCODE -ne 0) { throw 'Backup failed. Review Backup history in Settings.' }
}
finally { Pop-Location }
