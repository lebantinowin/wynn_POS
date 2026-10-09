$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
php -d extension=mbstring -d extension=fileinfo -S 127.0.0.1:8000 -t public
