param(
    [Parameter(Mandatory)]
    [ValidateSet("local", "railway")]
    [string]$Env
)

$backDir = $PSScriptRoot
$source = Join-Path $backDir ".env.$Env"
$target = Join-Path $backDir ".env"

Copy-Item $source $target -Force
& php artisan config:clear

$host_line = Select-String -Path $target -Pattern "^DB_HOST" | Select-Object -First 1
Write-Host "Ambiente: $Env ($($host_line.Line))"
