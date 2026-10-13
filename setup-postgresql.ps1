#Requires -Version 5.1
param(
    [string]$AdminUser = 'postgres',
    [string]$PsqlPath = 'C:\Program Files\PostgreSQL\18\bin\psql.exe'
)

$ErrorActionPreference = 'Stop'
if ($PSVersionTable.PSVersion.Major -lt 7) {
    $powerShellCommand = Get-Command pwsh -ErrorAction SilentlyContinue
    $powerShellPath = if ($powerShellCommand) { $powerShellCommand.Source } else {
        Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\native\powershell\pwsh.exe'
    }
    if (-not (Test-Path -LiteralPath $powerShellPath)) {
        throw 'PowerShell 7 was not found. Run this script from a PowerShell 7 terminal.'
    }
    & $powerShellPath -NoProfile -File $PSCommandPath -AdminUser $AdminUser -PsqlPath $PsqlPath
    exit $LASTEXITCODE
}
Set-Location $PSScriptRoot
$databaseName = 'wynn_mart'
$applicationUser = 'wynn_mart_app'
$envPath = Join-Path $PSScriptRoot '.env'

if ($AdminUser -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
    throw 'Use a PostgreSQL administrator account with a standard identifier.'
}
if (-not (Test-Path -LiteralPath $PsqlPath)) {
    throw 'psql was not found. Pass its full path with -PsqlPath.'
}
if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'The local Laravel .env file must exist before database setup.'
}
$phpPath = (Get-Command php -ErrorAction Stop).Source
& $phpPath -d extension=pdo_pgsql --ri pdo_pgsql
if ($LASTEXITCODE -ne 0) {
    throw 'PHP could not load pdo_pgsql.'
}

function Invoke-Postgres {
    param([string]$Sql, [string]$UserName, [string]$Password, [string]$Database)

    $startInfo = [Diagnostics.ProcessStartInfo]::new($PsqlPath)
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    foreach ($argument in @('-X', '-w', '-h', '127.0.0.1', '-p', '5432', '-U', $UserName, '-d', $Database, '-v', 'ON_ERROR_STOP=1', '-A', '-t')) {
        $startInfo.ArgumentList.Add($argument)
    }
    $startInfo.Environment['PGPASSWORD'] = $Password
    $startInfo.Environment['PGCONNECT_TIMEOUT'] = '5'
    $process = [Diagnostics.Process]::Start($startInfo)
    try {
        $outputTask = $process.StandardOutput.ReadToEndAsync()
        $errorTask = $process.StandardError.ReadToEndAsync()
        $process.StandardInput.WriteLine($Sql)
        $process.StandardInput.Close()
        $process.WaitForExit()
        $outputText = $outputTask.GetAwaiter().GetResult()
        $errorText = $errorTask.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) {
            if ($Password) {
                $errorText = $errorText.Replace($Password, '[redacted]')
            }
            throw "PostgreSQL setup failed: $errorText"
        }
        return $outputText.Trim()
    } finally {
        $process.Dispose()
        $startInfo.Environment.Remove('PGPASSWORD') | Out-Null
    }
}

function Set-LocalEnvironmentValue {
    param([string]$Text, [string]$Key, [string]$Value)
    $pattern = '(?m)^' + [regex]::Escape($Key) + '=.*$'
    $newLine = "$Key=$Value"
    if ([regex]::IsMatch($Text, $pattern)) {
        return [regex]::Replace($Text, $pattern, [Text.RegularExpressions.MatchEvaluator]{ param($match) $newLine })
    }
    return $Text.TrimEnd() + [Environment]::NewLine + $newLine + [Environment]::NewLine
}

$localEnvironment = [IO.File]::ReadAllText($envPath)
$configuredPassword = [regex]::Match($localEnvironment, '(?m)^DB_PASSWORD=([a-f0-9]{64})\r?$').Groups[1].Value
$alreadyConfigured = $localEnvironment -match '(?m)^DB_DATABASE=wynn_mart\r?$' -and
    $localEnvironment -match '(?m)^DB_USERNAME=wynn_mart_app\r?$' -and $configuredPassword

if ($alreadyConfigured) {
    Invoke-Postgres -Sql 'SELECT 1;' -UserName $applicationUser -Password $configuredPassword -Database $databaseName | Out-Null
    Write-Host 'Existing Wynn database connection verified. Applying pending migrations.'
} else {
    $securePassword = Read-Host "PostgreSQL administrator password ($AdminUser)" -AsSecureString
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $adminPassword = $null
    $applicationPassword = $null
    try {
        $adminPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
        $existing = Invoke-Postgres -Sql "SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$applicationUser') OR EXISTS (SELECT 1 FROM pg_database WHERE datname = '$databaseName');" -UserName $AdminUser -Password $adminPassword -Database 'postgres'
        if ($existing -ne 'f') {
            throw 'A wynn_mart database or wynn_mart_app role already exists without matching local configuration. Setup stopped without changing it.'
        }
        $applicationPassword = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant()
        Invoke-Postgres -Sql "CREATE ROLE $applicationUser LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '$applicationPassword';" -UserName $AdminUser -Password $adminPassword -Database 'postgres' | Out-Null
        try {
            Invoke-Postgres -Sql "CREATE DATABASE $databaseName OWNER $applicationUser;" -UserName $AdminUser -Password $adminPassword -Database 'postgres' | Out-Null
        } catch {
            Invoke-Postgres -Sql "DROP ROLE $applicationUser;" -UserName $AdminUser -Password $adminPassword -Database 'postgres' | Out-Null
            throw
        }
        Invoke-Postgres -Sql 'SELECT 1;' -UserName $applicationUser -Password $applicationPassword -Database $databaseName | Out-Null
        foreach ($entry in @{
            DB_CONNECTION = 'pgsql'; DB_HOST = '127.0.0.1'; DB_PORT = '5432'
            DB_DATABASE = $databaseName; DB_USERNAME = $applicationUser; DB_PASSWORD = $applicationPassword
        }.GetEnumerator()) {
            $localEnvironment = Set-LocalEnvironmentValue -Text $localEnvironment -Key $entry.Key -Value $entry.Value
        }
        $localEnvironment = Set-LocalEnvironmentValue -Text $localEnvironment -Key 'DB_URL' -Value ''
        [IO.File]::WriteAllText($envPath, $localEnvironment, [Text.UTF8Encoding]::new($false))
        Write-Host 'Dedicated Wynn database and application account created. App credentials saved only in the ignored .env file.'
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
        $securePassword.Dispose()
        $adminPassword = $null
        $applicationPassword = $null
        $localEnvironment = $null
    }
}

foreach ($artisanArguments in @(
    @('config:clear', '--no-interaction'),
    @('migrate', '--no-interaction'),
    @('migrate:status', '--no-interaction')
)) {
    & $phpPath -d extension=mbstring -d extension=fileinfo -d extension=pdo_pgsql artisan @artisanArguments
    if ($LASTEXITCODE -ne 0) {
        throw 'A Laravel setup command failed. Fix the reported error and rerun this script; the configured database will be reused.'
    }
}
Write-Host 'PostgreSQL connection and Laravel foundation migrations are ready. POS records still use the existing file-backed sessions.'
