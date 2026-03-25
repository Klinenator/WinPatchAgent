param(
    [string]$Configuration = "Release",
    [string]$Runtime = "win-x64",
    [string]$OutputZip = ""
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Resolve-Path (Join-Path $scriptDir "..")
$projectPath = Join-Path $repoRoot "src/PatchAgent.Service/PatchAgent.Service.csproj"

if (-not (Test-Path $projectPath)) {
    throw "Project file not found: $projectPath"
}

if ([string]::IsNullOrWhiteSpace($OutputZip)) {
    $artifactsDir = Join-Path $repoRoot "artifacts"
    New-Item -ItemType Directory -Path $artifactsDir -Force | Out-Null
    $OutputZip = Join-Path $artifactsDir "winpatchagent-windows-x64.zip"
}

$publishRoot = Join-Path $env:TEMP ("winpatchagent-publish-" + [guid]::NewGuid().ToString("N"))
try {
    New-Item -ItemType Directory -Path $publishRoot -Force | Out-Null

    & dotnet publish $projectPath `
        -c $Configuration `
        -r $Runtime `
        --self-contained true `
        -o $publishRoot

    if (-not (Test-Path (Join-Path $publishRoot "PatchAgent.Service.exe"))) {
        throw "Publish output missing PatchAgent.Service.exe"
    }

    $outputDir = Split-Path -Parent $OutputZip
    if (-not [string]::IsNullOrWhiteSpace($outputDir)) {
        New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
    }

    if (Test-Path $OutputZip) {
        Remove-Item -Path $OutputZip -Force
    }

    Compress-Archive -Path (Join-Path $publishRoot "*") -DestinationPath $OutputZip -Force
} finally {
    Remove-Item -Path $publishRoot -Recurse -Force -ErrorAction SilentlyContinue
}

$hash = (Get-FileHash -Path $OutputZip -Algorithm SHA256).Hash
Write-Host "Created prebuilt package: $OutputZip"
Write-Host "SHA256: $hash"
