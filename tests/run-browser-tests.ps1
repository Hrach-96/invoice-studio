# Runs tests/browser.html in headless Chrome/Edge against a throwaway SQLite DB.
#   powershell -ExecutionPolicy Bypass -File tests/run-browser-tests.ps1 [-Php path\to\php.exe]
param(
    [string]$Php = 'php',
    [int]$Port = 8766
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$tmp = Join-Path $PSScriptRoot '.tmp'
New-Item -ItemType Directory -Force $tmp | Out-Null
Remove-Item -Force -ErrorAction SilentlyContinue (Join-Path $tmp 'browser.sqlite'), (Join-Path $tmp 'browser-report.txt'), (Join-Path $tmp 'browser-progress.txt')

$browser = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $browser) { throw 'Chrome or Edge not found.' }

# Isolated environment for the test server
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = Join-Path $tmp 'browser.sqlite'
$env:MAIL_DRIVER = 'log'
$env:MAIL_ALLOWED_RECIPIENTS = '@example.test'   # (an empty value would unset it and fall back to .env)
$env:MAIL_LOG_DIR = Join-Path $tmp 'mail'

$server = Start-Process -FilePath $Php -ArgumentList '-S', "127.0.0.1:$Port", '-t', 'public', 'tests/serve.php' `
    -WorkingDirectory $root -PassThru -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $tmp 'browser-server.log') -RedirectStandardError (Join-Path $tmp 'browser-server.err')
Start-Sleep -Milliseconds 800

$profileDir = Join-Path $tmp 'chrome-profile'
$chrome = Start-Process -FilePath $browser -PassThru -WindowStyle Hidden -ArgumentList `
    '--headless=new', '--disable-gpu', '--window-size=1400,1000', "--user-data-dir=$profileDir", `
    '--no-first-run', '--remote-debugging-port=0', "http://127.0.0.1:$Port/tests/browser.html"

$report = Join-Path $tmp 'browser-report.txt'
for ($i = 0; $i -lt 90 -and -not (Test-Path $report); $i++) { Start-Sleep -Seconds 1 }

# Stop only the processes started here
Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like "*$profileDir*" } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue

if (-not (Test-Path $report)) {
    Write-Host 'No report after 90s. Last progress lines:'
    Get-Content (Join-Path $tmp 'browser-progress.txt') -Tail 5 -ErrorAction SilentlyContinue
    exit 1
}
$text = Get-Content $report -Raw -Encoding UTF8
Write-Host $text
if ($text -match 'FAILED') { exit 1 }
