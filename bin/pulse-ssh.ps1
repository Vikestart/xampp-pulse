# XAMPP Pulse - pulsessh:// launcher.
# Registered as the handler for pulsessh:// links. The BROWSER invokes this (as the
# logged-in user), so the terminal opens in the interactive desktop session - which a
# LocalSystem service could never do itself. We validate hard before running ssh:
#   1. the alias must match a safe charset (no shell metacharacters), and
#   2. it must be a Host actually defined in the current user's ~/.ssh/config.
# So the worst a rogue web page could do is open a terminal to a host you already have.
param([string]$Url)

$h = $Url -replace '^(?i)pulsessh://', '' -replace '[/?#].*$', ''
try { $h = [System.Uri]::UnescapeDataString($h) } catch { }
$h = $h.Trim()

if ($h -notmatch '^[A-Za-z0-9._-]+$') { exit 1 }

$cfg = Join-Path $env:USERPROFILE '.ssh\config'
$known = $false
if (Test-Path -LiteralPath $cfg) {
    foreach ($line in (Get-Content -LiteralPath $cfg)) {
        if ($line -match '^\s*[Hh]ost\s+(.+?)\s*$') {
            foreach ($p in ($matches[1] -split '\s+')) {
                if ($p -eq $h) { $known = $true; break }
            }
        }
        if ($known) { break }
    }
}
if (-not $known) { exit 1 }

if (Get-Command wt.exe -ErrorAction SilentlyContinue) {
    Start-Process -FilePath 'wt.exe' -ArgumentList @('ssh', $h)
} else {
    Start-Process -FilePath 'cmd.exe' -ArgumentList @('/k', 'ssh', $h)
}
