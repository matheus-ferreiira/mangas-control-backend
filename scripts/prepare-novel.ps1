param(
    [string]$Source = (Join-Path $PSScriptRoot '../../capitulos'),
    [string]$Destination = (Join-Path $PSScriptRoot '../resources/novels/swordmasters-youngest-son'),
    [switch]$OriginalOnly
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$manifest = Get-Content (Join-Path $Source 'drive-manifest.json') -Raw | ConvertFrom-Json
New-Item -ItemType Directory -Path $Destination -Force | Out-Null
$audit = @()
$utf8 = [Text.UTF8Encoding]::new($false)
foreach ($item in $manifest) {
    $number = [int]([regex]::Match($item.title, '\d+').Value)
    $file = Join-Path (Join-Path (Join-Path $Source 'drive') $item.folder) ($item.title.Trim()+'.docx')
    $zip = [IO.Compression.ZipFile]::OpenRead($file)
    try {
        $reader = [IO.StreamReader]::new($zip.GetEntry('word/document.xml').Open())
        [xml]$doc = $reader.ReadToEnd()
        $reader.Dispose()
        $ns = [Xml.XmlNamespaceManager]::new($doc.NameTable)
        $ns.AddNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main')
        # Reject suggestions: restore deletions, ignore inserted content and moved-to copies.
        $paragraphs = @($doc.SelectNodes('//w:body//w:p[not(ancestor::w:ins) and not(ancestor::w:moveTo)]', $ns) | ForEach-Object {
            $nodes = $_.SelectNodes('.//w:t[not(ancestor::w:ins) and not(ancestor::w:moveTo)] | .//w:delText[not(ancestor::w:ins) and not(ancestor::w:moveTo)] | .//w:tab[not(ancestor::w:ins)] | .//w:br[not(ancestor::w:ins)]', $ns)
            (($nodes | ForEach-Object { if ($_.LocalName -eq 'tab') { "`t" } elseif ($_.LocalName -eq 'br') { "`n" } else { $_.InnerText } }) -join '').Trim()
        } | Where-Object { $_ -and $_ -notmatch '^\[Support/Donate\]$|^https?://(?:www\.)?ko-fi\.com/' })
        $text = $paragraphs -join "`n`n"
        if ($text.Length -lt 2000 -or $text.Contains([char]0xFFFD)) { throw "Invalid prepared chapter $number" }
        $data = [ordered]@{ number=$number; title="Chapter $number"; language='en'; paragraphs=$paragraphs; source_url=$item.url; edition='original'; }
        [IO.File]::WriteAllText((Join-Path $Destination "$number.json"), ($data | ConvertTo-Json -Depth 5 -Compress), $utf8)
        $audit += [pscustomobject]@{ number=$number; characters=$text.Length; paragraphs=$paragraphs.Count; source_id=$item.id; sha256=(Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant() }
    } finally { $zip.Dispose() }
}
$numbers = @($audit.number | Sort-Object)
if ($numbers.Count -ne 747 -or @($numbers | Select-Object -Unique).Count -ne 747 -or @(401..1147 | Where-Object { $_ -notin $numbers }).Count) { throw 'Chapter sequence is incomplete' }
$index = [ordered]@{ slug='swordmasters-youngest-son'; title="Swordmaster's Youngest Son"; language='en'; edition='original'; first_chapter=401; last_chapter=1147; chapters=$numbers }
[IO.File]::WriteAllText((Join-Path $Destination 'index.json'), ($index | ConvertTo-Json -Depth 4), $utf8)
[IO.File]::WriteAllText((Join-Path $Destination 'audit.json'), ($audit | Sort-Object number | ConvertTo-Json -Depth 4), $utf8)
Write-Output "Prepared $($numbers.Count) chapters. Minimum characters: $(($audit | Measure-Object characters -Minimum).Minimum)"
if (-not $OriginalOnly) {
    $expectedDestination = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../resources/novels/swordmasters-youngest-son'))
    if ([IO.Path]::GetFullPath($Destination) -ne $expectedDestination) { throw 'Use -OriginalOnly for a custom destination.' }
    & node (Join-Path $PSScriptRoot 'novel-editorial.cjs')
    if ($LASTEXITCODE -ne 0) { throw 'Editorial preparation failed.' }
}
