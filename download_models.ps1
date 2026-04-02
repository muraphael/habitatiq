# ============================================================
# HabitatIQ — face-api.js Model Downloader
# Run this script ONCE to download AI models for local KYC verification
# Place this file in: C:\xampp\htdocs\habitatiq\
# Then right-click → Run with PowerShell
# ============================================================

$modelDir = "$PSScriptRoot\assets\models"

# Create directory if it doesn't exist
if (-not (Test-Path $modelDir)) {
    New-Item -ItemType Directory -Force -Path $modelDir | Out-Null
    Write-Host "Created: $modelDir" -ForegroundColor Green
}

$base = "https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights"

# Models needed: ssd_mobilenetv1, face_landmark_68, face_recognition
$files = @(
    # SSD MobileNet V1 — face detector
    "ssd_mobilenetv1_model-weights_manifest.json",
    "ssd_mobilenetv1_model-shard1",
    "ssd_mobilenetv1_model-shard2",

    # Face Landmark 68 — finds facial features
    "face_landmark_68_model-weights_manifest.json",
    "face_landmark_68_model-shard1",

    # Face Recognition Net — generates 128-point face descriptor
    "face_recognition_model-weights_manifest.json",
    "face_recognition_model-shard1",
    "face_recognition_model-shard2"
)

$total = $files.Count
$current = 0

Write-Host ""
Write-Host "Downloading face-api.js models ($total files)..." -ForegroundColor Cyan
Write-Host "Destination: $modelDir" -ForegroundColor Cyan
Write-Host ""

foreach ($file in $files) {
    $current++
    $url  = "$base/$file"
    $dest = "$modelDir\$file"

    if (Test-Path $dest) {
        Write-Host "[$current/$total] Already exists: $file" -ForegroundColor Gray
        continue
    }

    Write-Host "[$current/$total] Downloading: $file" -NoNewline
    try {
        Invoke-WebRequest -Uri $url -OutFile $dest -UseBasicParsing -ErrorAction Stop
        Write-Host " ✓" -ForegroundColor Green
    } catch {
        Write-Host " FAILED" -ForegroundColor Red
        Write-Host "  Error: $_" -ForegroundColor Red
        Write-Host "  Try manually: $url" -ForegroundColor Yellow
    }
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host " Setup complete!" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Models saved to:" -ForegroundColor White
Write-Host "  $modelDir" -ForegroundColor Yellow
Write-Host ""
Write-Host "File count: $(Get-ChildItem $modelDir | Measure-Object).Count" -ForegroundColor White
Write-Host ""
Write-Host "Next steps:" -ForegroundColor White
Write-Host "  1. Make sure XAMPP Apache is running" -ForegroundColor Gray
Write-Host "  2. Open: http://localhost/habitatiq/" -ForegroundColor Gray
Write-Host "  3. Log in as a tenant and upload KYC documents" -ForegroundColor Gray
Write-Host "  4. The AI verification runs automatically in the browser" -ForegroundColor Gray
Write-Host ""
Write-Host "The models are served from your local XAMPP — no internet needed after this." -ForegroundColor Green
Write-Host ""
Read-Host "Press Enter to close"
