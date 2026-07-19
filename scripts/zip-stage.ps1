<#
    Empaqueta el stage <Stage>/limpieza en un ZIP estándar (Deflate) con
    separadores '/' en los nombres de entrada, legible por el unzip de
    cPanel/Linux (Info-ZIP).

    Por qué .NET y no tar.exe ni Compress-Archive (lección real 18/07/2026):
    - `tar.exe -a -cf x.zip` (bsdtar/libarchive) en este Windows NO escribe zip:
      cae a tar/pax disfrazado de .zip y el unzip del hosting lo rechaza con
      "End-of-central-directory signature not found".
    - `Compress-Archive` (PowerShell 5.1) mete separadores '\' que rompen la
      extracción en Linux.
    - System.IO.Compression.ZipArchive genera un zip estándar y acá forzamos '/'.

    Uso:
      powershell -NoProfile -ExecutionPolicy Bypass -File zip-stage.ps1 `
        -Stage C:\...\build\cpanel -Zip C:\...\build\limpieza-cpanel.zip
#>
param(
    [Parameter(Mandatory = $true)][string]$Stage,  # carpeta que CONTIENE 'limpieza'
    [Parameter(Mandatory = $true)][string]$Zip     # ruta de salida .zip
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Join-Path $Stage 'limpieza'
if (-not (Test-Path $root)) { throw "No existe el stage: $root" }
$base = (Resolve-Path $Stage).Path.TrimEnd('\') + '\'

$fs = [System.IO.File]::Open($Zip, [System.IO.FileMode]::Create)
$archive = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Create)
$nFiles = 0
try {
    Get-ChildItem -Path $root -Recurse -File | ForEach-Object {
        $rel = ($_.FullName.Substring($base.Length)) -replace '\\', '/'
        $entry = $archive.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal)
        $es = $entry.Open()
        $in = [System.IO.File]::OpenRead($_.FullName)
        $in.CopyTo($es); $in.Close(); $es.Close()
        $nFiles++
    }
    # Directorios vacíos (p. ej. uploads/) como entradas 'rel/' para preservarlos.
    Get-ChildItem -Path $root -Recurse -Directory |
        Where-Object { @(Get-ChildItem $_.FullName -Recurse -File).Count -eq 0 } |
        ForEach-Object {
            $rel = (($_.FullName.Substring($base.Length)) -replace '\\', '/') + '/'
            $null = $archive.CreateEntry($rel)
        }
}
finally {
    $archive.Dispose(); $fs.Close()
}
Write-Output ("OK: {0} bytes, {1} archivos" -f (Get-Item $Zip).Length, $nFiles)
