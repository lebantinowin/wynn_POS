param(
    [ValidateRange(8001,8999)][int]$Port = 8001,
    [switch]$ResetOwner
)

$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
$practiceOverrides = @{
    APP_ENV = 'local'
    APP_NAME = "Wynn's Mart Practice"
    APP_URL = "http://127.0.0.1:$Port"
    DB_CONNECTION = 'sqlite'
    DB_DATABASE = Join-Path $PSScriptRoot 'storage\app\practice\shop.sqlite'
    DB_URL = $null
    POS_DB_CONNECTION = 'sqlite'
    POS_STORAGE = 'database'
    POS_PRACTICE = 'true'
    SESSION_DRIVER = 'file'
    SESSION_COOKIE = 'wynn_practice_session'
    CACHE_STORE = 'file'
    QUEUE_CONNECTION = 'sync'
}
$practicePrevious = @{}
try {
    if (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'bootstrap\cache\config.php')) {
        throw 'Cached configuration prevents database isolation. Run php artisan config:clear in the project terminal before launching practice.'
    }
    $practicePhp = (Get-Command php -ErrorAction Stop).Source
    foreach ($practiceKey in $practiceOverrides.Keys) {
        $practicePrevious[$practiceKey] = [Environment]::GetEnvironmentVariable($practiceKey, 'Process')
        [Environment]::SetEnvironmentVariable($practiceKey, $practiceOverrides[$practiceKey], 'Process')
    }
    $practicePhpFlags = @('-d', 'extension=mbstring', '-d', 'extension=fileinfo', '-d', 'extension=pdo_sqlite')
    Write-Host 'Preparing the separate practice shop. Main shop records and credentials are not copied.'
    & $practicePhp @practicePhpFlags artisan practice:prepare --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Practice preparation failed. Review the message above.' }
    $practiceOwnerStatus = & $practicePhp @practicePhpFlags artisan practice:prepare --check-owner --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Practice login status could not be checked.' }
    if ($ResetOwner -or $practiceOwnerStatus -contains 'needs-setup') {
        Write-Host 'Create or reset the separate practice login at the private prompts below.'
        $practiceOwnerArguments = @('artisan', 'owner:setup')
        if ($ResetOwner) { $practiceOwnerArguments += '--reset' }
        & $practicePhp @practicePhpFlags @practiceOwnerArguments
        if ($LASTEXITCODE -ne 0) { throw 'Practice account setup did not finish.' }
    }
    Write-Host "Practice is ready: http://127.0.0.1:$Port . Use your practice login."
    Write-Host 'Open Dashboard for the walkthrough. Keep this terminal open; Ctrl+C stops practice.'
    & $practicePhp @practicePhpFlags -S "127.0.0.1:$Port" -t public
}
finally {
    foreach ($practiceKey in $practicePrevious.Keys) {
        [Environment]::SetEnvironmentVariable($practiceKey, $practicePrevious[$practiceKey], 'Process')
    }
    Pop-Location
}