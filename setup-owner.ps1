param([switch]$Reset)

$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
try {
    $phpCommand = Get-Command php -ErrorAction Stop
    $ownerArguments = @('-d', 'extension=mbstring', '-d', 'extension=fileinfo', '-d', 'extension=pdo_pgsql', 'artisan', 'owner:setup')
    if ($Reset) {
        $ownerArguments += '--reset'
    }
    & $phpCommand.Source @ownerArguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Owner setup did not finish. Review the message above; no password is shown.'
    }
}
finally {
    Pop-Location
}
