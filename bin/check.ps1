<#
.SYNOPSIS
    Runs the three checks a change has to pass: PHPUnit, PHPCS, PHPStan.

.DESCRIPTION
    One command, because the alternative is what actually happened for months —
    the suite ran, the two analysers didn't, and nobody noticed they were never
    installed. If the toolchain is missing this bootstraps it rather than
    skipping the check quietly.

    PHPCS is REPORTING, not blocking: the tree carries pre-existing findings in
    a deliberately narrow ruleset (security, prepared SQL, i18n, prefixes, PHP
    version compatibility). The count is printed so a rise is visible. PHPStan
    IS blocking — its pre-existing findings are frozen in phpstan-baseline.neon,
    so anything it reports is new.

.PARAMETER Quick
    Skip PHPCS, which is the slow one (~45s).

.EXAMPLE
    pwsh bin/check.ps1
#>
[CmdletBinding()]
param(
    [switch] $Quick
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Push-Location $root

$failed = @()

function Section($name) {
    Write-Host ''
    Write-Host "=== $name " -NoNewline -ForegroundColor Cyan
    Write-Host ('=' * [Math]::Max(0, 60 - $name.Length)) -ForegroundColor Cyan
}

try {
    # --- toolchain --------------------------------------------------------
    $composer = Join-Path (Split-Path $root -Parent) 'composer.phar'
    $phpunit  = Join-Path (Split-Path $root -Parent) 'phpunit-10.phar'

    if (-not (Test-Path (Join-Path $root 'tools\vendor\bin\phpstan'))) {
        Section 'Installing analysis toolchain'
        if (-not (Test-Path $composer)) {
            Write-Host "composer.phar not found at $composer" -ForegroundColor Red
            Write-Host 'Get it from https://getcomposer.org/download/latest-stable/composer.phar (verify the .sha256sum).'
            exit 1
        }
        php $composer --working-dir=tools update --no-interaction
    }

    # --- PHPUnit ----------------------------------------------------------
    Section 'PHPUnit'
    if (Test-Path $phpunit) {
        php $phpunit --configuration phpunit.xml
    } elseif (Test-Path 'vendor\bin\phpunit') {
        php vendor\bin\phpunit
    } else {
        Write-Host "phpunit not found (looked for $phpunit and vendor/bin/phpunit)" -ForegroundColor Red
        $failed += 'PHPUnit (not found)'
    }
    if ($LASTEXITCODE -ne 0) { $failed += 'PHPUnit' }

    # --- PHPStan (blocking) -----------------------------------------------
    Section 'PHPStan'
    php tools\vendor\bin\phpstan analyse --no-progress --memory-limit=2G
    if ($LASTEXITCODE -ne 0) { $failed += 'PHPStan' }

    # --- PHPCS (reporting) ------------------------------------------------
    if (-not $Quick) {
        Section 'PHPCS (reporting only)'
        $out = php tools\vendor\bin\phpcs --no-colors --report=summary 2>&1
        $total = ($out | Select-String 'A TOTAL OF').ToString()
        if ($total) {
            Write-Host $total.Trim() -ForegroundColor Yellow
            Write-Host 'Pre-existing. Full report: php tools\vendor\bin\phpcs' -ForegroundColor DarkGray
        } else {
            Write-Host 'clean' -ForegroundColor Green
        }
    }

    # --- verdict ----------------------------------------------------------
    Section 'Result'
    if ($failed.Count -eq 0) {
        Write-Host 'PASS' -ForegroundColor Green
        exit 0
    }
    Write-Host ("FAIL: {0}" -f ($failed -join ', ')) -ForegroundColor Red
    exit 1
}
finally {
    Pop-Location
}
