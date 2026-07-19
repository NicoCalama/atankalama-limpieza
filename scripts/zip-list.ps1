<#
    Lista los nombres de entrada (FullName, con separadores '/') de un ZIP,
    uno por línea. Se usa en la auditoría de build-cpanel-zip.php.

    Por qué .NET y no tar.exe: el bsdtar de este Windows no lee de forma
    confiable los zip generados por System.IO.Compression (data descriptors),
    aunque el unzip de cPanel/Linho sí. Leemos con la misma librería que escribe.
#>
param(
    [Parameter(Mandatory = $true)][string]$Zip
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$z = [System.IO.Compression.ZipFile]::OpenRead($Zip)
try {
    $z.Entries | ForEach-Object { $_.FullName }
}
finally {
    $z.Dispose()
}
