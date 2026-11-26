<#
.SYNOPSIS
    Prove di Scarica-Backup.ps1 contro un server FINTO (nessuna rete, nessun server vero).

.DESCRIPTION
    Il finto ssh (fake-ssh.cmd) inoltra "list" e "get" al vero backup-readonly.sh su una cartella di prova, quindi
    si provano insieme lo script del PC e il comando forzato del server. Serve Git for Windows (sh e find GNU).
    Si lancia a mano: powershell -NoProfile -ExecutionPolicy Bypass -File test\Test-ScaricaBackup.ps1
    Esce con 0 se tutte le prove passano.
#>
$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot
$script = Join-Path (Split-Path -Parent $here) 'Scarica-Backup.ps1'
$fakeSsh = Join-Path $here 'fake-ssh.cmd'

$sh = $null
foreach ($c in @(
        (Get-Command sh.exe -ErrorAction SilentlyContinue | ForEach-Object { $_.Source }),
        'C:\Program Files\Git\bin\sh.exe', 'C:\Program Files\Git\usr\bin\sh.exe')) {
    if ($c -and (Test-Path -LiteralPath $c)) { $sh = $c; break }
}
if (-not $sh) { Write-Host 'sh.exe (Git for Windows) non trovato'; exit 1 }

$failures = 0
$script:roots = @()
function Assert-That {
    param([bool]$Condition, [string]$Name)
    if ($Condition) { Write-Host ('  ok   ' + $Name) } else { Write-Host ('  FAIL ' + $Name); $script:failures++ }
}

function New-Fixture {
    $root = Join-Path ([IO.Path]::GetTempPath()) ('scarica-test-' + [guid]::NewGuid().ToString('N'))
    foreach ($d in 'home\saas\shared\storage\app\backups\production\shared', 'dst', 'lad', 'outside') {
        New-Item -ItemType Directory -Path (Join-Path $root $d) -Force | Out-Null
    }
    $script:roots += $root
    Set-Content -LiteralPath (Join-Path $root 'key') -Value 'chiave finta' -Encoding ASCII
    Set-Content -LiteralPath (Join-Path $root 'outside\sentinella.txt') -Value 'intatta' -Encoding ASCII
    return $root
}

# Crea un dump (e il suo .json) alla radice $Base: <Base>\shared\AAAA\MM\shared-<UTC>.dump.enc
function Add-Dump {
    param([string]$Base, [datetime]$At, [switch]$WrongHash)
    $dir = Join-Path $Base ('shared\' + $At.ToString('yyyy') + '\' + $At.ToString('MM'))
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    $file = Join-Path $dir ('shared-' + $At.ToString("yyyyMMdd'T'HHmmss'Z'") + '.dump.enc')
    $bytes = New-Object byte[] 2048
    (New-Object Random).NextBytes($bytes)
    [IO.File]::WriteAllBytes($file, $bytes)
    $sha = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($WrongHash) { $sha = ('0' * 64) }
    $json = '{"database":"shared","size":' + $bytes.Length + ',"sha256":"' + $sha + '"}'
    [IO.File]::WriteAllText($file + '.json', $json)
    return $file
}

function Invoke-Script {
    param([string]$Root, [string]$Mode = 'real')
    $env:FAKE_SH = $sh
    $env:FAKE_MODE = $Mode
    $env:FAKE_HOME = (Join-Path $Root 'home') -replace '\\', '/'
    $env:FAKE_LOG = Join-Path $Root 'ssh-calls.txt'
    $env:LOCALAPPDATA = Join-Path $Root 'lad'
    $p = Start-Process -FilePath 'powershell.exe' -Wait -PassThru -NoNewWindow -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $script + '"'),
        '-Server', 'finto', '-User', 'prova', '-KeyPath', ('"' + (Join-Path $Root 'key') + '"'),
        '-Destination', ('"' + (Join-Path $Root 'dst') + '"'), '-SshExe', ('"' + $fakeSsh + '"'), '-NoNotify'
    ) -RedirectStandardOutput (Join-Path $Root 'out.txt') -RedirectStandardError (Join-Path $Root 'err.txt')
    return $p.ExitCode
}

$srv = 'home\saas\shared\storage\app\backups\production'
$now = (Get-Date).ToUniversalTime()

Write-Host 'Prova 1: dump nuovo valido, potatura di uno vecchio'
$r = New-Fixture
$new = Add-Dump (Join-Path $r $srv) $now.AddHours(-1)
$old = Add-Dump (Join-Path $r 'dst') $now.AddDays(-35)
$code = Invoke-Script $r
Assert-That ($code -eq 0) ('uscita 0 (era ' + $code + ')')
Assert-That (Test-Path (Join-Path $r ('dst\shared\' + $now.AddHours(-1).ToString('yyyy\\MM') + '\' + (Split-Path -Leaf $new)))) 'il dump nuovo e'' stato scaricato'
Assert-That (-not (Test-Path $old)) 'il dump di 35 giorni e'' stato potato'
Assert-That (-not (Test-Path (Join-Path $r 'dst\ATTENZIONE.txt'))) 'nessun ATTENZIONE.txt'
Assert-That (@(Get-Content (Join-Path $r 'ssh-calls.txt') | Where-Object { $_ -ne 'list' -and $_ -notlike 'get shared/*' }).Count -eq 0) 'sul server solo list e get'

Write-Host 'Prova 2: server morto, la copia vecchia NON si cancella e scatta l''allarme'
$r = New-Fixture
$fresh = Add-Dump (Join-Path $r 'dst') $now.AddHours(-2)
$old = Add-Dump (Join-Path $r 'dst') $now.AddDays(-35)
$code = Invoke-Script $r 'dead'
Assert-That ($code -eq 3) ('uscita 3 (era ' + $code + ')')
Assert-That (Test-Path $old) 'il dump di 35 giorni c''e'' ancora'
Assert-That (Test-Path ($old + '.json')) 'il suo .json c''e'' ancora'
Assert-That (Test-Path $fresh) 'il dump recente c''e'' ancora'
Assert-That (Test-Path (Join-Path $r 'dst\ATTENZIONE.txt')) 'ATTENZIONE.txt scritto'
Assert-That ((Get-Content (Join-Path $r 'dst\ATTENZIONE.txt') -Raw) -match 'potatura SOSPESA') 'il motivo e'' la potatura sospesa'

Write-Host 'Prova 3: impronta sha256 sbagliata, il file e'' scartato'
$r = New-Fixture
[void](Add-Dump (Join-Path $r $srv) $now.AddHours(-1) -WrongHash)
$code = Invoke-Script $r
Assert-That ($code -eq 3) ('uscita 3 (era ' + $code + ')')
Assert-That (@(Get-ChildItem (Join-Path $r 'dst') -Recurse -File -Filter '*.dump.enc*' -ErrorAction SilentlyContinue).Count -eq 0) 'nessun dump tenuto'
Assert-That ((Get-Content (Join-Path $r 'dst\ATTENZIONE.txt') -Raw) -match 'SCARTATO') 'segnalato come scartato'

Write-Host 'Prova 4: percorsi cattivi dal server (../, assoluti, nomi riservati di Windows)'
$r = New-Fixture
$code = Invoke-Script $r 'evil'
Assert-That ($code -eq 3) ('uscita 3 (era ' + $code + ')')
$calls = Get-Content (Join-Path $r 'ssh-calls.txt')
Assert-That (@($calls | Where-Object { $_ -like 'get *' }).Count -eq 0) 'nessun "get" lanciato per quei percorsi'
Assert-That (@(Get-ChildItem (Join-Path $r 'dst') -Recurse -Force | Where-Object { $_.FullName -notmatch '\\_log(\\|$)' -and $_.Name -ne 'ATTENZIONE.txt' }).Count -eq 0) 'nulla creato nella destinazione'
Assert-That ((Get-Content (Join-Path $r 'outside\sentinella.txt') -Raw).Trim() -eq 'intatta') 'niente toccato fuori dalla destinazione'
Assert-That (-not (Test-Path (Join-Path $r 'evil')) -and -not (Test-Path (Join-Path $r 'x-20261124T010101Z.dump.enc'))) 'niente scritto accanto alla destinazione'
Assert-That ((Get-Content (Join-Path $r 'out.txt') -Raw) -match 'non valido') 'percorsi segnalati come non validi'

Write-Host 'Prova 5: file che sembra una chiave nella destinazione, lo script non parte'
$r = New-Fixture
Set-Content -LiteralPath (Join-Path $r 'dst\chiave-privata-backup.txt') -Value 'finta' -Encoding ASCII
$code = Invoke-Script $r
Assert-That ($code -eq 1) ('uscita 1 (era ' + $code + ')')
Assert-That (-not (Test-Path (Join-Path $r 'ssh-calls.txt'))) 'nessuna connessione al server'

foreach ($d in $script:roots) { Remove-Item -LiteralPath $d -Recurse -Force -ErrorAction SilentlyContinue }
Write-Host ''
if ($failures -gt 0) { Write-Host ($failures.ToString() + ' prove NON passate'); exit 1 }
Write-Host 'Tutte le prove passate'
exit 0
