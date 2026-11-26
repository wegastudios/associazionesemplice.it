<#
.SYNOPSIS
    Associazione Semplice - copia dei backup del gestionale SaaS dal server a una cartella OneDrive.

.DESCRIPTION
    Windows PowerShell 5.1. Si lancia ogni ora da Utilita' di pianificazione (vedi saas/docs/COPIA_ONEDRIVE.md).

    Cosa fa, in ordine:
      1. rifiuta di partire se la cartella di destinazione contiene file che sembrano chiavi, o se la chiave SSH sta
         dentro la destinazione o dentro OneDrive;
      2. chiede al server l'elenco dei dump (comando "list", sola lettura);
      3. scarica i dump mancanti con il loro .json (comando "get", sola lettura) in una cartella temporanea fuori da
         OneDrive, ne controlla l'impronta sha256 contro il campo "sha256" del .json e solo se coincide li sposta nella
         destinazione; se non coincide li scarta e lo segnala;
      4. pota la copia locale: dump delle ultime 48 ore e poi uno al giorno, mai oltre i giorni indicati (massimo 30);
      5. controlla che l'ultimo dump scaricato non abbia piu' di 26 ore: se e' vecchio scrive ATTENZIONE.txt nella
         cartella, mostra una notifica di Windows, scrive nel log e termina con codice diverso da 0.

    Sul server NON cancella, NON scrive, NON rinomina: il solo accesso concesso dalla chiave dedicata e' "list" e "get".
    Nel file non c'e' alcun segreto: la chiave SSH e' un file fuori da OneDrive, la chiave di cifratura dei backup
    (SAAS_BACKUP_SECRET_KEY) non serve a questo script e non deve mai stare su questo PC nella cartella di destinazione.

    Codici di uscita: 0 tutto bene; 1 configurazione o controllo di sicurezza non superato (non parte);
                      2 problema passeggero (server non raggiungibile, un dump non scaricato), nessun allarme ancora;
                      3 ALLARME (dump troppo vecchio, impronta errata, nessun dump di "shared").

.PARAMETER Server
    Nome o indirizzo del server SSH (lo stesso dei rilasci, dato dal titolare).
.PARAMETER Port
    Porta SSH.
.PARAMETER User
    Utente SSH dell'account sul server.
.PARAMETER KeyPath
    File della chiave PRIVATA dedicata alla sola lettura. Deve stare fuori da OneDrive.
.PARAMETER Destination
    Cartella dove tenere le copie (OBBLIGATORIO, nessun valore di partenza: la sceglie il titolare). Puo' stare in OneDrive oppure in una cartella locale o su un disco esterno, fuori dal cloud.
.PARAMETER Days
    Giorni di conservazione, al massimo 30 (e' la scadenza promessa ai clienti).
.PARAMETER MaxAgeHours
    Soglia dell'avviso: ore dall'ultimo dump scaricato.
.PARAMETER VerifyAll
    Ricalcola l'impronta anche dei file gia' presenti (lento: da fare a mano ogni tanto).
.PARAMETER NoNotify
    Non mostra la notifica di Windows (resta il file ATTENZIONE.txt, il log e il codice di uscita).
#>
[CmdletBinding()]
param(
    [string]$Server = 'DA_COMPILARE',
    [int]$Port = 22,
    [string]$User = 'DA_COMPILARE',
    [string]$KeyPath = (Join-Path $env:USERPROFILE '.ssh\backup_readonly_ed25519'),
    [Parameter(Mandatory = $true)][string]$Destination,
    [int]$Days = 30,
    [int]$MaxAgeHours = 26,
    [switch]$VerifyAll,
    [switch]$NoNotify,
    [string]$SshExe = (Join-Path $env:SystemRoot 'System32\OpenSSH\ssh.exe')
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

# ---- Costanti (non sono segreti) ------------------------------------------------------------------------------
$HourlyHours = 48                      # come sul server: tutti i dump delle ultime 48 ore
$MaxDaysAllowed = 30                   # tetto assoluto, promesso ai clienti
$MandatoryDb = 'shared'                # il database comune deve sempre avere un dump recente
$NotifyEveryHours = 4                  # per non riempire lo schermo di avvisi uguali
$TempDir = Join-Path $env:LOCALAPPDATA 'AssociazioneSemplice-Backup\incoming'
$RelPattern = '^(?<db>[A-Za-z0-9_-]{1,64})/(?<y>[0-9]{4})/(?<m>[0-9]{2})/(?<db2>[A-Za-z0-9_-]{1,64})-(?<ts>[0-9]{8}T[0-9]{6}Z)\.dump\.enc(?<json>\.json)?$'
$ReservedNames = '^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])$'   # nomi riservati di Windows (anche in minuscolo)
$Culture = [Globalization.CultureInfo]::InvariantCulture

$script:LogFile = $null
$script:Hard = New-Object System.Collections.Generic.List[string]   # problemi che fanno scattare l'allarme
$script:Soft = New-Object System.Collections.Generic.List[string]   # problemi passeggeri: solo log e codice 2

function Write-Log {
    param([string]$Level, [string]$Message)
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Write-Host $line
    if ($script:LogFile) {
        try { Add-Content -LiteralPath $script:LogFile -Value $line -Encoding UTF8 } catch { }
    }
}

function Stop-Config {
    param([string]$Message)
    Write-Log 'ERRORE' $Message
    $script:ExitCode = 1
    throw 'config'
}

function ConvertFrom-DumpTime {
    param([string]$Stamp)
    $style = [Globalization.DateTimeStyles]::AssumeUniversal -bor [Globalization.DateTimeStyles]::AdjustToUniversal
    return [datetime]::ParseExact($Stamp, "yyyyMMdd'T'HHmmss'Z'", $Culture, $style)
}

function Test-InsideFolder {
    param([string]$Path, [string]$Folder)
    if ([string]::IsNullOrWhiteSpace($Path) -or [string]::IsNullOrWhiteSpace($Folder)) { return $false }
    $p = [IO.Path]::GetFullPath($Path).TrimEnd('\') + '\'
    $f = [IO.Path]::GetFullPath($Folder).TrimEnd('\') + '\'
    return $p.StartsWith($f, [StringComparison]::OrdinalIgnoreCase)
}

# Un percorso ricevuto dal server e' un DATO, non un ordine: va bene solo se ha la forma rigida
# <db>/<aaaa>/<mm>/<db>-<UTC>.dump.enc[.json], il nome del database non e' riservato a Windows (CON, NUL, COM1...)
# e il percorso locale che ne risulta sta davvero dentro la cartella di destinazione.
function Test-SafeRel {
    param([string]$Rel)
    if ([string]::IsNullOrEmpty($Rel)) { return $false }
    $m = [regex]::Match($Rel, $RelPattern)
    if (-not $m.Success) { return $false }
    if ($m.Groups['db'].Value -ne $m.Groups['db2'].Value) { return $false }
    if ($m.Groups['db'].Value -match $ReservedNames) { return $false }
    try {
        $at = ConvertFrom-DumpTime $m.Groups['ts'].Value
        if ($m.Groups['y'].Value -ne $at.ToString('yyyy') -or $m.Groups['m'].Value -ne $at.ToString('MM')) { return $false }
        return (Test-InsideFolder (Join-Path $Destination ($Rel -replace '/', '\')) $Destination)
    }
    catch { return $false }
}

# Esegue "ssh ... <comando remoto>" con l'uscita scritta byte per byte in un file (Start-Process non altera i dati binari).
function Invoke-Remote {
    param([string]$RemoteCommand, [string]$OutFile)
    $errFile = Join-Path $TempDir ('stderr-' + [guid]::NewGuid().ToString('N') + '.txt')
    $sshArgs = @(
        '-i', ('"{0}"' -f $KeyPath),
        '-p', [string]$Port,
        '-o', 'BatchMode=yes',
        '-o', 'IdentitiesOnly=yes',
        '-o', 'StrictHostKeyChecking=yes',
        '-o', 'ConnectTimeout=20',
        '-o', 'ServerAliveInterval=15',
        '-o', 'ServerAliveCountMax=4',
        ('{0}@{1}' -f $User, $Server),
        ('"{0}"' -f $RemoteCommand)
    )
    try {
        $p = Start-Process -FilePath $SshExe -ArgumentList $sshArgs -NoNewWindow -Wait -PassThru `
            -RedirectStandardOutput $OutFile -RedirectStandardError $errFile
        $err = ''
        if (Test-Path -LiteralPath $errFile) {
            $err = [IO.File]::ReadAllText($errFile)
            if ($err.Length -gt 200) { $err = $err.Substring(0, 200) }
            $err = ($err -replace '\s+', ' ').Trim()
        }
        return [pscustomobject]@{ Code = $p.ExitCode; Error = $err }
    }
    finally {
        Remove-Item -LiteralPath $errFile -Force -ErrorAction SilentlyContinue
    }
}

# Stessa regola del server: tutti i dump delle ultime 48 ore, poi il primo di ogni giorno, mai oltre $Days giorni.
# $Items: oggetti con Db, At (UTC), Key. Restituisce l'insieme delle Key da tenere.
function Get-KeepSet {
    param($Items, [datetime]$Now)
    $keep = @{}
    $hourlyLimit = $Now.AddHours(-$HourlyHours)
    $dailyLimit = $Now.AddDays(-$Days)
    foreach ($group in ($Items | Group-Object -Property Db)) {
        $seenDays = @{}
        foreach ($it in ($group.Group | Sort-Object -Property At)) {
            $day = $it.At.ToString('yyyy-MM-dd')
            $ok = ($it.At -ge $hourlyLimit) -or (($it.At -ge $dailyLimit) -and (-not $seenDays.ContainsKey($day)))
            if ($it.At -lt $dailyLimit) { $ok = $false }   # mai oltre la scadenza
            if ($ok) {
                $seenDays[$day] = $true
                $keep[$it.Key] = $true
            }
        }
    }
    return $keep
}

function Get-LocalDumps {
    $found = New-Object System.Collections.Generic.List[object]
    if (-not (Test-Path -LiteralPath $Destination)) { return $found }
    $rootFull = [IO.Path]::GetFullPath($Destination).TrimEnd('\') + '\'
    foreach ($f in (Get-ChildItem -LiteralPath $Destination -Recurse -File -Force -Filter '*.dump.enc' -ErrorAction SilentlyContinue)) {
        $rel = $f.FullName.Substring($rootFull.Length).Replace('\', '/')
        $m = [regex]::Match($rel, $RelPattern)
        if (-not $m.Success -or $m.Groups['json'].Success -or $m.Groups['db'].Value -ne $m.Groups['db2'].Value) { continue }
        $at = ConvertFrom-DumpTime $m.Groups['ts'].Value
        if ($m.Groups['y'].Value -ne $at.ToString('yyyy') -or $m.Groups['m'].Value -ne $at.ToString('MM')) { continue }
        $found.Add([pscustomobject]@{
                Key = $rel; Rel = $rel; Db = $m.Groups['db'].Value; At = $at
                Path = $f.FullName; Json = $f.FullName + '.json'; Size = $f.Length
            })
    }
    return $found
}

function Read-Manifest {
    param([string]$JsonPath)
    $j = [IO.File]::ReadAllText($JsonPath) | ConvertFrom-Json
    $sha = $j.PSObject.Properties['sha256']
    if ($null -eq $sha -or ([string]$sha.Value) -notmatch '^[0-9a-fA-F]{64}$') { throw 'campo sha256 assente o non valido nel .json' }
    $size = $j.PSObject.Properties['size']
    $db = $j.PSObject.Properties['database']
    return [pscustomobject]@{
        Sha256 = ([string]$sha.Value).ToLowerInvariant()
        Size   = $(if ($null -ne $size) { [int64]$size.Value } else { -1 })
        Db     = $(if ($null -ne $db) { [string]$db.Value } else { '' })
    }
}

function Remove-Pair {
    param([string]$DumpPath)
    Remove-Item -LiteralPath $DumpPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath ($DumpPath + '.json') -Force -ErrorAction SilentlyContinue
}

function Receive-Dump {
    param($Item)
    $tmpDump = Join-Path $TempDir ($Item.Rel -replace '/', '__')
    $tmpJson = $tmpDump + '.json'
    Remove-Item -LiteralPath $tmpDump, $tmpJson -Force -ErrorAction SilentlyContinue
    try {
        $r = Invoke-Remote ('get ' + $Item.Rel + '.json') $tmpJson
        if ($r.Code -ne 0) { $script:Soft.Add('download del .json non riuscito: ' + $Item.Rel + ' (' + $r.Error + ')'); return }
        try { $man = Read-Manifest $tmpJson }
        catch { $script:Hard.Add('file scartato, .json illeggibile: ' + $Item.Rel + ' (' + $_.Exception.Message + ')'); return }
        if ($man.Db -ne '' -and $man.Db -ne $Item.Db) { $script:Hard.Add('file scartato, il .json dichiara un altro database: ' + $Item.Rel); return }

        $r = Invoke-Remote ('get ' + $Item.Rel) $tmpDump
        if ($r.Code -ne 0) { $script:Soft.Add('download del dump non riuscito: ' + $Item.Rel + ' (' + $r.Error + ')'); return }

        $real = (Get-FileHash -LiteralPath $tmpDump -Algorithm SHA256).Hash.ToLowerInvariant()
        $len = (Get-Item -LiteralPath $tmpDump).Length
        if ($real -ne $man.Sha256 -or ($man.Size -ge 0 -and $len -ne $man.Size)) {
            $script:Hard.Add('file SCARTATO, impronta sha256 o dimensione diversa da quella del .json: ' + $Item.Rel)
            return
        }

        $final = Join-Path $Destination ($Item.Rel -replace '/', '\')
        New-Item -ItemType Directory -Path (Split-Path -Parent $final) -Force | Out-Null
        Move-Item -LiteralPath $tmpDump -Destination $final -Force
        Move-Item -LiteralPath $tmpJson -Destination ($final + '.json') -Force   # il .json per ultimo: segna la copia completa
        Write-Log 'INFO' ('scaricato e verificato: ' + $Item.Rel + ' (' + $len + ' byte)')
        return $true
    }
    finally {
        Remove-Item -LiteralPath $tmpDump, $tmpJson -Force -ErrorAction SilentlyContinue
    }
}

function Show-Notice {
    param([string]$Title, [string]$Text)
    if ($NoNotify) { return }
    try {
        Add-Type -AssemblyName System.Windows.Forms
        Add-Type -AssemblyName System.Drawing
        $n = New-Object System.Windows.Forms.NotifyIcon
        $n.Icon = [System.Drawing.SystemIcons]::Warning
        $n.Visible = $true
        $n.BalloonTipIcon = [System.Windows.Forms.ToolTipIcon]::Warning
        $n.BalloonTipTitle = $Title
        $n.BalloonTipText = $Text
        $n.ShowBalloonTip(15000)
        Start-Sleep -Seconds 8
        $n.Dispose()
    }
    catch {
        Write-Log 'AVVISO' 'notifica di Windows non riuscita (resta ATTENZIONE.txt)'
    }
}

# ---- Corpo ----------------------------------------------------------------------------------------------------
$script:ExitCode = 0
$mutex = $null
$haveMutex = $false
try {
    if ($Days -lt 1 -or $Days -gt $MaxDaysAllowed) { Stop-Config "Days deve essere tra 1 e $MaxDaysAllowed (scadenza promessa ai clienti)." }
    if ($MaxAgeHours -lt 2) { Stop-Config 'MaxAgeHours troppo basso.' }
    if ($Server -eq 'DA_COMPILARE' -or $User -eq 'DA_COMPILARE') { Stop-Config 'Compila Server e User in testa allo script (o passali come parametri).' }
    if (-not (Test-Path -LiteralPath $SshExe)) { Stop-Config 'ssh.exe non trovato: attiva "Client OpenSSH" in Windows (Impostazioni, App, Funzionalita'' facoltative).' }
    if (-not (Test-Path -LiteralPath $KeyPath -PathType Leaf)) { Stop-Config 'File della chiave SSH non trovato (vedi KeyPath).' }

    # (e) la chiave non deve stare dove stanno i dump, ne' dentro OneDrive
    if (Test-InsideFolder $KeyPath $Destination) { Stop-Config 'La chiave SSH sta dentro la cartella di destinazione: spostala fuori.' }
    foreach ($od in @($env:OneDrive, $env:OneDriveConsumer, $env:OneDriveCommercial)) {
        if ($od -and (Test-InsideFolder $KeyPath $od)) { Stop-Config 'La chiave SSH sta dentro OneDrive: spostala fuori (per esempio in .ssh del profilo).' }
    }

    $parent = Split-Path -Parent ([IO.Path]::GetFullPath($Destination))
    if (-not (Test-Path -LiteralPath $Destination)) {
        if (-not (Test-Path -LiteralPath $parent)) { Stop-Config 'La cartella superiore della destinazione non esiste (OneDrive non attivo?).' }
        New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    }

    $suspect = @(Get-ChildItem -LiteralPath $Destination -Recurse -Force -File -ErrorAction SilentlyContinue | Where-Object {
            $_.Name -like 'chiave-privata-backup*' -or $_.Name -like '*chiave*' -or $_.Name -like '*.key' -or $_.Name -like '*.pem' -or
            $_.Name -like '*.ppk' -or $_.Name -like 'id_ed25519*' -or $_.Name -like 'id_rsa*' -or $_.Name -like '*backup_readonly*' -or
            $_.Name -like '*secret*' -or $_.Name -like '.env*'
        })
    $logDir = Join-Path $Destination '_log'
    $script:LogFile = Join-Path $logDir ('scarica-backup-' + (Get-Date -Format 'yyyy-MM') + '.log')
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    if ($suspect.Count -gt 0) {
        Stop-Config ('Nella cartella di destinazione ci sono file che sembrano chiavi: ' + (($suspect | Select-Object -First 5 | ForEach-Object { $_.Name }) -join ', ') + '. Sposta o cancella quei file: la chiave dei backup non deve stare con i dump. Non scarico nulla.')
    }

    New-Item -ItemType Directory -Path $TempDir -Force | Out-Null
    Get-ChildItem -LiteralPath $TempDir -Force -File -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-1) } | Remove-Item -Force -ErrorAction SilentlyContinue

    $mutex = New-Object System.Threading.Mutex($false, 'Global\AssociazioneSempliceScaricaBackup')
    $haveMutex = $mutex.WaitOne(0)
    if (-not $haveMutex) { Write-Log 'INFO' 'un''altra esecuzione e'' in corso: esco'; $mutex = $null; exit 0 }

    $now = (Get-Date).ToUniversalTime()
    Write-Log 'INFO' ('inizio; server ' + $Server + ':' + $Port + ', destinazione ' + $Destination + ', conservazione ' + $Days + ' giorni')

    # 2. elenco remoto (sola lettura)
    $listFile = Join-Path $TempDir 'elenco.txt'
    $remote = New-Object System.Collections.Generic.List[object]
    $listed = $false
    $freshVerified = $false
    $r = Invoke-Remote 'list' $listFile
    if ($r.Code -ne 0) {
        $script:Soft.Add('server non raggiungibile o elenco non ottenuto (codice ' + $r.Code + '): ' + $r.Error)
    }
    else {
        $listed = $true
        $byRel = @{}
        foreach ($line in [IO.File]::ReadAllLines($listFile)) {
            $parts = $line -split "`t"
            if ($parts.Count -ne 2) { continue }
            if (-not (Test-SafeRel $parts[0])) {
                Write-Log 'AVVISO' 'percorso ricevuto dal server scartato perche'' non valido (non viene usato)'
                $script:Soft.Add('il server ha elencato un percorso non valido: ignorato')
                continue
            }
            $m = [regex]::Match($parts[0], $RelPattern)
            $at = ConvertFrom-DumpTime $m.Groups['ts'].Value
            $dumpRel = $parts[0] -replace '\.json$', ''
            if (-not $byRel.ContainsKey($dumpRel)) {
                $byRel[$dumpRel] = [pscustomobject]@{ Key = $dumpRel; Rel = $dumpRel; Db = $m.Groups['db'].Value; At = $at; HasDump = $false; HasJson = $false }
            }
            if ($m.Groups['json'].Success) { $byRel[$dumpRel].HasJson = $true } else { $byRel[$dumpRel].HasDump = $true }
        }
        foreach ($v in $byRel.Values) { if ($v.HasDump -and $v.HasJson) { $remote.Add($v) } }
        Write-Log 'INFO' ('sul server: ' + $remote.Count + ' dump completi')
    }
    Remove-Item -LiteralPath $listFile -Force -ErrorAction SilentlyContinue

    # 3. scarico di quelli mancanti e da tenere
    if ($listed) {
        $wanted = Get-KeepSet $remote $now
        foreach ($it in ($remote | Sort-Object -Property At -Descending)) {
            if (-not $wanted.ContainsKey($it.Key)) { continue }
            $final = Join-Path $Destination ($it.Rel -replace '/', '\')
            if ((Test-Path -LiteralPath $final) -and (Test-Path -LiteralPath ($final + '.json'))) { continue }
            # Vale solo un dump NUOVO, verificato in questo giro e di meno di $MaxAgeHours ore: serve alla potatura (sotto).
            if ((Receive-Dump $it) -eq $true -and $it.At -ge $now.AddHours(-$MaxAgeHours)) { $freshVerified = $true }
        }
    }

    # controllo dei file gia' presenti (dimensione sempre; impronta con -VerifyAll)
    foreach ($it in (Get-LocalDumps)) {
        if (-not (Test-Path -LiteralPath $it.Json)) {
            if (((Get-Date) - (Get-Item -LiteralPath $it.Path).LastWriteTime).TotalHours -gt 1) {
                Write-Log 'AVVISO' ('dump incompleto (senza .json), tolto: ' + $it.Rel)
                Remove-Pair $it.Path
            }
            continue
        }
        try {
            $man = Read-Manifest $it.Json
            $bad = ($man.Size -ge 0 -and $it.Size -ne $man.Size)
            if (-not $bad -and $VerifyAll) {
                $bad = ((Get-FileHash -LiteralPath $it.Path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $man.Sha256)
            }
        }
        catch { $bad = $true }
        if ($bad) {
            $script:Hard.Add('copia locale SCARTATA, non coincide con il .json: ' + $it.Rel)
            Remove-Pair $it.Path
        }
    }

    # 4. potatura locale: stessa regola del server, mai oltre $Days giorni
    $local = Get-LocalDumps
    $keepLocal = Get-KeepSet $local $now
    $pruned = 0
    $toPrune = @($local | Where-Object { -not $keepLocal.ContainsKey($_.Key) -and (Test-InsideFolder $_.Path $Destination) })
    if ($toPrune.Count -gt 0 -and -not $freshVerified) {
        # Se il server smette di fare dump, la copia vecchia puo' essere l'unica rimasta: non si cancella niente.
        $script:Hard.Add('potatura SOSPESA: in questo giro nessun dump nuovo verificato di meno di ' + $MaxAgeHours + ' ore. ' +
            $toPrune.Count + ' copie oltre la regola restano sul PC (anche oltre ' + $Days + ' giorni) finche'' non decide il titolare.')
    }
    else {
        foreach ($it in $toPrune) {
            Remove-Pair $it.Path
            $pruned++
        }
    }
    foreach ($j in (Get-ChildItem -LiteralPath $Destination -Recurse -File -Force -Filter '*.dump.enc.json' -ErrorAction SilentlyContinue)) {
        if (-not (Test-Path -LiteralPath ($j.FullName -replace '\.json$', ''))) { Remove-Item -LiteralPath $j.FullName -Force -ErrorAction SilentlyContinue }
    }
    foreach ($d in (Get-ChildItem -LiteralPath $Destination -Recurse -Directory -Force -ErrorAction SilentlyContinue | Sort-Object { $_.FullName.Length } -Descending)) {
        $dirRel = $d.FullName.Substring([IO.Path]::GetFullPath($Destination).TrimEnd('\').Length).TrimStart('\').Replace('\', '/')
        if ($dirRel -notmatch '^[A-Za-z0-9_-]{1,64}(/\d{4}(/\d{2})?)?$' -or $dirRel -eq '_log') { continue }   # solo cartelle database/anno/mese
        if (-not (Get-ChildItem -LiteralPath $d.FullName -Force -ErrorAction SilentlyContinue)) { Remove-Item -LiteralPath $d.FullName -Force -ErrorAction SilentlyContinue }
    }
    if ($pruned -gt 0) { Write-Log 'INFO' ('potati ' + $pruned + ' dump oltre la regola 48 ore + uno al giorno / ' + $Days + ' giorni') }
    foreach ($old in (Get-ChildItem -LiteralPath $logDir -File -Filter 'scarica-backup-*.log' -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-90) })) {
        Remove-Item -LiteralPath $old.FullName -Force -ErrorAction SilentlyContinue
    }

    # 5. freschezza dell'ultimo dump (soglia in ore)
    $local = Get-LocalDumps
    $newestLocal = @{}
    foreach ($it in $local) {
        if (-not (Test-Path -LiteralPath $it.Json)) { continue }
        if (-not $newestLocal.ContainsKey($it.Db) -or $it.At -gt $newestLocal[$it.Db]) { $newestLocal[$it.Db] = $it.At }
    }
    $newestRemote = @{}
    foreach ($it in $remote) {
        if (-not $newestRemote.ContainsKey($it.Db) -or $it.At -gt $newestRemote[$it.Db]) { $newestRemote[$it.Db] = $it.At }
    }
    $limit = $now.AddHours(-$MaxAgeHours)
    $summary = New-Object System.Collections.Generic.List[string]
    foreach ($db in (@($newestLocal.Keys) + @($newestRemote.Keys) + @($MandatoryDb) | Sort-Object -Unique)) {
        $hasLocal = $newestLocal.ContainsKey($db)
        $age = if ($hasLocal) { [math]::Round(($now - $newestLocal[$db]).TotalHours, 1) } else { $null }
        $summary.Add($db + ': ' + $(if ($hasLocal) { "ultimo dump $age ore fa" } else { 'nessun dump scaricato' }))
        $stale = (-not $hasLocal) -or ($newestLocal[$db] -lt $limit)
        if (-not $stale) { continue }
        if ($db -eq $MandatoryDb) {
            $script:Hard.Add('database "' + $db + '": ' + $(if ($hasLocal) { "ultimo dump scaricato di $age ore fa (soglia $MaxAgeHours)" } else { 'nessun dump scaricato' }))
        }
        elseif ($newestRemote.ContainsKey($db) -and $newestRemote[$db] -ge $limit) {
            $script:Hard.Add('database "' + $db + '": sul server c''e'' un dump recente ma non e'' stato scaricato (ultimo scaricato: ' + $(if ($hasLocal) { "$age ore fa" } else { 'nessuno' }) + ')')
        }
        else {
            Write-Log 'NOTA' ('database "' + $db + '": nessun dump recente nemmeno sul server (associazione cancellata?). Non e'' un allarme di questo script.')
        }
    }
    Write-Log 'INFO' ('stato: ' + ($summary -join ' | '))

    # Esito
    foreach ($s in $script:Soft) { Write-Log 'AVVISO' $s }
    foreach ($h in $script:Hard) { Write-Log 'ALLARME' $h }
    $alarmFile = Join-Path $Destination 'ATTENZIONE.txt'
    $stateFile = Join-Path $logDir 'stato.json'
    if ($script:Hard.Count -gt 0) {
        $text = @(
            'ATTENZIONE - copia dei backup di Associazione Semplice',
            ('Controllo del ' + (Get-Date -Format 'dd/MM/yyyy HH:mm') + ' (ora del PC)'),
            '',
            'Cosa non va:'
        ) + ($script:Hard | ForEach-Object { '  - ' + $_ }) + @(
            '',
            'Cosa fare: guarda il log nella cartella _log e segui la guida (saas/docs/COPIA_ONEDRIVE.md, sezione "Se compare l''avviso").',
            'Questo file sparisce da solo quando tutto torna a posto.'
        )
        [IO.File]::WriteAllLines($alarmFile, [string[]]$text, (New-Object System.Text.UTF8Encoding($true)))
        $notify = $true
        try {
            if (Test-Path -LiteralPath $stateFile) {
                $st = [IO.File]::ReadAllText($stateFile) | ConvertFrom-Json
                $last = [datetime]::Parse([string]$st.lastNotify, $Culture, [Globalization.DateTimeStyles]::RoundtripKind)
                if (((Get-Date).ToUniversalTime() - $last).TotalHours -lt $NotifyEveryHours) { $notify = $false }
            }
        }
        catch { $notify = $true }
        if ($notify) {
            Show-Notice 'Backup Associazione Semplice: ATTENZIONE' ($script:Hard[0] + ' (dettagli in ATTENZIONE.txt)')
            [IO.File]::WriteAllText($stateFile, ('{"lastNotify":"' + (Get-Date).ToUniversalTime().ToString('o') + '"}'))
        }
        Write-Log 'ALLARME' 'esco con codice 3'
        $script:ExitCode = 3
    }
    else {
        Remove-Item -LiteralPath $alarmFile -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $stateFile -Force -ErrorAction SilentlyContinue
        if ($script:Soft.Count -gt 0) { $script:ExitCode = 2; Write-Log 'AVVISO' 'esco con codice 2 (problema passeggero)' }
        else { Write-Log 'INFO' 'tutto in ordine' }
    }
}
catch {
    if ($_.Exception.Message -ne 'config') {
        Write-Log 'ERRORE' ('errore imprevisto: ' + $_.Exception.Message)
        $script:ExitCode = 1
    }
}
finally {
    if ($mutex -and $haveMutex) { try { $mutex.ReleaseMutex() } catch { } }
}
exit $script:ExitCode
