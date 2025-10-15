[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string]
    $ProjectRoot = (Resolve-Path "$PSScriptRoot/.."),

    [Parameter(Position = 1)]
    [string]
    $OutputPath = "dist"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Write-Verbose "Project root: $ProjectRoot"
Write-Verbose "Desired output folder: $OutputPath"

if (-not (Test-Path $ProjectRoot)) {
    throw "Project root '$ProjectRoot' does not exist."
}

Push-Location $ProjectRoot
try {
    if (-not (Test-Path 'package.json')) {
        throw "No package.json found in $ProjectRoot. This does not appear to be the panel project root."
    }

    if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
        throw 'npm was not found on PATH. Install Node.js 18+ (https://nodejs.org/) before continuing.'
    }

    if (Test-Path 'package-lock.json') {
        Write-Host 'Installing dependencies with npm ci...'
        npm ci --no-fund --no-audit
    }
    else {
        Write-Host 'Installing dependencies with npm install...'
        npm install --no-fund --no-audit
    }

    Write-Host 'Building production bundle...'
    $env:NODE_ENV = 'production'
    npm run build -- --emptyOutDir

    if ($OutputPath -and (Resolve-Path $OutputPath -ErrorAction SilentlyContinue) -ne (Resolve-Path 'dist' -ErrorAction SilentlyContinue)) {
        Write-Host "Copying build output to '$OutputPath'..."
        New-Item -ItemType Directory -Path $OutputPath -Force | Out-Null
        Get-ChildItem -Path $OutputPath | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
        Copy-Item -Recurse -Force -Path (Join-Path 'dist' '*') -Destination $OutputPath
    }

    Write-Host 'Build completed successfully.'
}
finally {
    Pop-Location
}
