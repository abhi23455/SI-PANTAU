$ErrorActionPreference = "Stop"

$root = $PSScriptRoot
$backend = Join-Path $root "backend"
$frontend = Join-Path $root "frontend"
$phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
if (-not $phpCommand -and (Test-Path "C:\xampp\php\php.exe")) {
    $phpPath = "C:\xampp\php\php.exe"
} elseif ($phpCommand) {
    $phpPath = $phpCommand.Source
} else {
    throw "PHP tidak ditemukan. Tambahkan PHP XAMPP ke PATH atau instal PHP."
}

if (-not (Test-Path (Join-Path $backend ".env"))) {
    throw "backend\.env belum ada. Salin backend\.env.example ke backend\.env dan isi konfigurasi database/admin."
}
if (-not (Test-Path (Join-Path $backend "vendor\autoload.php"))) {
    throw "Dependensi Laravel belum terpasang. Jalankan composer install di folder backend."
}
if (-not (Test-Path (Join-Path $frontend "node_modules\next\package.json"))) {
    throw "Dependensi frontend belum terpasang. Jalankan npm install di folder frontend."
}

Push-Location $backend
try {
    & $phpPath artisan migrate --force
    if ($LASTEXITCODE -ne 0) {
        throw "Migrasi database gagal. Pastikan MySQL XAMPP aktif dan konfigurasi backend\.env benar."
    }
    & $phpPath artisan db:seed --class=AdminUserSeeder --force
    if ($LASTEXITCODE -ne 0) {
        throw "Pembuatan admin gagal. Periksa SIPANTAU_ADMIN_EMAIL dan SIPANTAU_ADMIN_PASSWORD di backend\.env."
    }
} finally {
    Pop-Location
}

$backendPort = Get-NetTCPConnection -State Listen -LocalPort 8000 -ErrorAction SilentlyContinue
if (-not $backendPort) {
    $backendPath = $backend.Replace("'", "''")
    $phpPathLiteral = $phpPath.Replace("'", "''")
    $apiCommand = "Set-Location -LiteralPath '$backendPath'; & '$phpPathLiteral' artisan serve --host=127.0.0.1 --port=8000"
    Start-Process powershell.exe -ArgumentList "-NoExit -Command `"$apiCommand`""
} else {
    Write-Host "Laravel API sudah berjalan di port 8000."
}

$frontendPort = Get-NetTCPConnection -State Listen -LocalPort 3000 -ErrorAction SilentlyContinue
if (-not $frontendPort) {
    $frontendPath = $frontend.Replace("'", "''")
    $webCommand = "Set-Location -LiteralPath '$frontendPath'; npm run dev -- --hostname 127.0.0.1"
    Start-Process powershell.exe -ArgumentList "-NoExit -Command `"$webCommand`""
} else {
    Write-Host "Frontend sudah berjalan di port 3000."
}

Write-Host "SIPANTAU: http://127.0.0.1:3000"
