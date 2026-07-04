# XAMPP Pulse - pulsefolder:// launcher.
# Registered as the handler for pulsefolder:// links. The BROWSER invokes this (as the
# logged-in user), so Explorer opens on the interactive desktop session - which a
# LocalSystem service could never do itself. We validate hard before opening:
#   1. the target must resolve to an existing directory, and
#   2. it must live INSIDE the htdocs root that was baked in at registration time.
# So the worst a rogue web page could do is open Explorer to a folder under htdocs.
param([string]$Root, [string]$Url)

$p = $Url -replace '^(?i)pulsefolder:/*', ''
try { $p = [System.Uri]::UnescapeDataString($p) } catch { }
$p = $p.Trim().Trim('"')

if ($p -eq '' -or $Root -eq '') { exit 1 }

# Canonicalise both paths so the containment check can't be fooled by ..\ or slashes.
try {
    $target   = [System.IO.Path]::GetFullPath($p)
    $rootFull = [System.IO.Path]::GetFullPath($Root)
} catch { exit 1 }

# Must be an existing directory...
if (-not (Test-Path -LiteralPath $target -PathType Container)) { exit 1 }

# ...and contained within (or equal to) the htdocs root - boundary-safe, case-insensitive.
$rootPrefix   = $rootFull.TrimEnd('\') + '\'
$targetPrefix = $target.TrimEnd('\') + '\'
if (-not $targetPrefix.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) { exit 1 }

Start-Process -FilePath 'explorer.exe' -ArgumentList @($target)
