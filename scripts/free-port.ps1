<#
.SYNOPSIS
    Kills whatever process is listening on a given local TCP port.

.EXAMPLE
    .\scripts\free-port.ps1 -Port 8010
#>
param(
    [Parameter(Mandatory = $true)]
    [int]$Port
)

$connections = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue

if (-not $connections) {
    Write-Host "Nothing is listening on port $Port."
    exit 0
}

$processIds = $connections.OwningProcess | Sort-Object -Unique

foreach ($processId in $processIds) {
    $proc = Get-Process -Id $processId -ErrorAction SilentlyContinue
    if ($proc) {
        Write-Host "Killing $($proc.ProcessName) (PID $processId) on port $Port"
        Stop-Process -Id $processId -Force -Confirm:$false
    }
}

Write-Host "Port $Port is free."
