# ============================================================
# sincro-adn-loro.ps1
# Sincronización ADN Local → Distribuidora El Loro (WordPress)
#
# USO:
#   .\sincro-adn-loro.ps1                        # Sincroniza todo
#   .\sincro-adn-loro.ps1 -Modo productos        # Solo productos
#   .\sincro-adn-loro.ps1 -Modo marcas           # Solo marcas
#   .\sincro-adn-loro.ps1 -Modo categorias       # Solo categorías
#   .\sincro-adn-loro.ps1 -Modo imagenes         # Solo imágenes
#   .\sincro-adn-loro.ps1 -Modo pedidos          # Solo pedidos pendientes
#   .\sincro-adn-loro.ps1 -Modo status           # Ver estado del sitio
# ============================================================

param(
    [ValidateSet("todo","productos","marcas","categorias","imagenes","pedidos","status")]
    [string]$Modo = "todo"
)

# ── CONFIGURACIÓN ─────────────────────────────────────────────────────────────
# MySQL local ADN
$mysql_exe  = "mysql"                          # o ruta completa: "C:\Program Files\MySQL\...\mysql.exe"
$mysql_host = "127.0.0.1"
$mysql_port = "3307"
$mysql_user = "loro_sync"                       # usuario MySQL del ADN
$mysql_pass = "SyncLoro25"           # contraseña MySQL del ADN
$mysql_db   = "adn"                            # nombre de la base de datos ADN

# WordPress – Distribuidora El Loro
$WP_URL      = "http://wordpress-fywhhaufr2a037pljdg17lmb.152.53.55.6.sslip.io"
$ADN_KEY     = "CLAVE_SECRETA_LORO"     # misma clave guardada con /set-key
$FOTOS_DIR   = "C:\ADN Software\FOTOS\SINCRONIZADAS"         # carpeta donde ADN guarda las fotos

# Tamaño de lote para productos (evitar timeout)
$LOTE_SIZE   = 100

$LOG_FILE    = Join-Path $PSScriptRoot "sincro-loro.log"
# ─────────────────────────────────────────────────────────────────────────────

function Write-Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$ts] $msg"
    Write-Host $line
    Add-Content -Path $LOG_FILE -Value $line -Encoding UTF8
}

function Invoke-WP($endpoint, $method = "GET", $body = $null) {
    $uri     = "$WP_URL/wp-json/adn-loro/v1/$endpoint"
    $headers = @{
        "X-ADN-Key"    = $ADN_KEY
        "Content-Type" = "application/json"
    }
    try {
        if ($method -eq "GET") {
            return Invoke-RestMethod -Uri $uri -Method GET -Headers $headers -TimeoutSec 120
        } else {
            $json      = $body | ConvertTo-Json -Compress -Depth 10
            $jsonBytes  = [System.Text.Encoding]::UTF8.GetBytes($json)
            $headers["Content-Type"] = "application/json; charset=utf-8"
            return Invoke-RestMethod -Uri $uri -Method POST -Headers $headers -Body $jsonBytes -TimeoutSec 120
        }
    } catch {
        Write-Log "ERROR HTTP $endpoint : $_"
        return $null
    }
}

function Run-MySQL([string]$sql) {
    try {
        $connStr = "Driver={MySQL ODBC 5.3 Unicode Driver};Server=$mysql_host;Port=$mysql_port;Database=$mysql_db;Uid=$mysql_user;Pwd=$mysql_pass;charset=utf8;"
        $conn = New-Object System.Data.Odbc.OdbcConnection($connStr)
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandTimeout = 180
        $cmd.CommandText = $sql
        $reader = $cmd.ExecuteReader()
        $rows = @()
        while ($reader.Read()) {
            $row = [ordered]@{}
            for ($i = 0; $i -lt $reader.FieldCount; $i++) {
                $row[$reader.GetName($i)] = if ($reader.IsDBNull($i)) { "" } else { "$($reader.GetValue($i))" }
            }
            $rows += [PSCustomObject]$row
        }
        $reader.Close()
        $conn.Close()
        return $rows
    } catch {
        Write-Log "ERROR MySQL ODBC: $_"
        return $null
    }
}

# ── FUNCIÓN: Sincronizar Marcas ───────────────────────────────────────────────
function Sync-Marcas {
    Write-Log "=== Sincronizando MARCAS ==="

    $sql = @"
SELECT MAR_CODIGO, REPLACE(TRIM(MAR_DESCRIPCION), '"', '') AS nombre
FROM adn_marcas
WHERE MAR_ACTIVO = 1
  AND TRIM(MAR_DESCRIPCION) <> ''
  AND UPPER(TRIM(MAR_DESCRIPCION)) <> 'INDEFINIDO'
ORDER BY MAR_DESCRIPCION;
"@

    $rows = Run-MySQL $sql
    if ($null -eq $rows -or $rows.Count -eq 0) {
        Write-Log "No se obtuvieron marcas de ADN."
        return
    }

    $brands = $rows | ForEach-Object {
        [PSCustomObject]@{ codigo = $_.MAR_CODIGO.Trim(); nombre = $_.nombre.Trim() }
    } | Where-Object { $_.nombre -ne "" }

    if ($brands.Count -eq 0) { Write-Log "Sin marcas para sincronizar."; return }
    Write-Log "Marcas encontradas: $($brands.Count)"

    $resp = Invoke-WP "ingest-brands" "POST" @{ brands = $brands }
    if ($resp) {
        Write-Log "Marcas: synced=$($resp.synced) errors=$($resp.errors) total=$($resp.total)"
    }
}

# ── FUNCIÓN: Sincronizar Categorías ──────────────────────────────────────────
function Sync-Categorias {
    Write-Log "=== Sincronizando CATEGORÍAS ==="

    $sql = @"
SELECT REPLACE(TRIM(CAT_DESCRIPCION), '"', '') AS nombre
FROM adn_categorias
WHERE TRIM(CAT_DESCRIPCION) <> ''
ORDER BY CAT_DESCRIPCION;
"@

    $rows = Run-MySQL $sql
    if ($null -eq $rows -or $rows.Count -eq 0) {
        Write-Log "No se obtuvieron categorías de ADN."
        return
    }

    $cats = $rows | ForEach-Object {
        [PSCustomObject]@{ name = $_.nombre.Trim() }
    } | Where-Object { $_.name -ne "" }

    if ($cats.Count -eq 0) { Write-Log "Sin categorías para sincronizar."; return }
    Write-Log "Categorías encontradas: $($cats.Count)"

    $resp = Invoke-WP "ingest-categories" "POST" @{ categories = $cats }
    if ($resp) {
        Write-Log "Categorías: synced=$($resp.synced) errors=$($resp.errors) total=$($resp.total)"
    }
}

# ── FUNCIÓN: Sincronizar Productos (en lotes) ─────────────────────────────────
function Sync-Productos {
    Write-Log "=== Sincronizando PRODUCTOS ==="

    $sql = @"
SELECT
    TRIM(p.PDT_CODIGO)                                                                  AS sku,
    REPLACE(TRIM(p.PDT_DESCRIPCION), '"', '')                                           AS name,
    COALESCE((SELECT pr.PRE_PRECIO FROM adn_precios pr
              WHERE pr.PRE_UGR_PDT_CODIGO = p.PDT_CODIGO
                AND pr.PRE_PLT_LISTA = 'A' LIMIT 1), 0)                                AS price,
    COALESCE((SELECT u.UGR_EXIS FROM adn_undagru u
              WHERE u.UGR_PDT_CODIGO = p.PDT_CODIGO
              LIMIT 1), 0)                                                              AS stock,
    REPLACE(TRIM(COALESCE(c.CAT_DESCRIPCION, '')), '"', '')                             AS category,
    REPLACE(TRIM(COALESCE(m.MAR_DESCRIPCION, '')), '"', '')                             AS brand,
    REPLACE(TRIM(IF(TRIM(COALESCE(p.PDT_DESCRI2,'')) <> '', p.PDT_DESCRI2,
                    COALESCE(p.PDT_DESCRIPCION_CORTA,''))), '"', '')                    AS description
FROM adn_productos p
LEFT JOIN adn_categorias c ON p.PDT_CAT_CODIGO = c.CAT_CODIGO
LEFT JOIN adn_marcas m     ON p.PDT_MAR_CODIGO = m.MAR_CODIGO
WHERE p.PDT_ESTADO   = 1
  AND p.PDT_OCULTO   = 0
  AND p.PDT_SUBIRWEB = 1
  AND TRIM(p.PDT_CODIGO) <> ''
ORDER BY p.PDT_CODIGO;
"@

    $rows = Run-MySQL $sql
    if ($null -eq $rows -or $rows.Count -eq 0) {
        Write-Log "No se obtuvieron productos de ADN."
        return
    }

    $all_products = $rows | ForEach-Object {
        [PSCustomObject]@{
            sku         = $_.sku.Trim()
            name        = if ($_.name.Trim() -ne "") { $_.name.Trim() } else { $_.sku.Trim() }
            price       = [double]($_.price -replace '[^0-9.]','')
            stock       = [int]($_.stock -replace '[^0-9-]','')
            category    = $_.category.Trim()
            brand       = $_.brand.Trim()
            description = $_.description.Trim()
            status      = "publish"
        }
    } | Where-Object { $_.sku -ne "" }

    if ($all_products.Count -eq 0) { Write-Log "Sin productos para sincronizar."; return }
    Write-Log "Total productos en ADN: $($all_products.Count)"

    $total_created = 0; $total_updated = 0; $total_errors = 0
    $lotes = [Math]::Ceiling($all_products.Count / $LOTE_SIZE)

    for ($i = 0; $i -lt $lotes; $i++) {
        $inicio = $i * $LOTE_SIZE
        $lote   = $all_products | Select-Object -Skip $inicio -First $LOTE_SIZE
        Write-Log "Lote $($i+1)/$lotes — enviando $($lote.Count) productos..."

        $resp = Invoke-WP "ingest-products" "POST" @{ products = $lote }
        if ($resp) {
            $total_created += $resp.created
            $total_updated += $resp.updated
            $total_errors  += $resp.errors
            Write-Log "  → created=$($resp.created) updated=$($resp.updated) errors=$($resp.errors)"
        } else {
            Write-Log "  → ERROR: sin respuesta del servidor"
            $total_errors += $lote.Count
        }

        Start-Sleep -Milliseconds 500
    }

    Write-Log "TOTAL productos: created=$total_created updated=$total_updated errors=$total_errors"
}

# ── FUNCIÓN: Subir imágenes de productos ─────────────────────────────────────
function Sync-Imagenes {
    Write-Log "=== Sincronizando IMÁGENES ==="

    $resp = Invoke-WP "pending-images"
    if ($null -eq $resp -or $resp.count -eq 0) {
        Write-Log "No hay imágenes pendientes en la cola."
        return
    }

    Write-Log "Imágenes pendientes: $($resp.count)"
    $ok = 0; $err = 0; $skip = 0

    foreach ($entry in $resp.pending.PSObject.Properties) {
        $sku      = $entry.Name
        $filename = $entry.Value

        $paths = @(
            (Join-Path $FOTOS_DIR $filename),
            (Join-Path $FOTOS_DIR "$sku.jpg"),
            (Join-Path $FOTOS_DIR "$sku.JPG"),
            (Join-Path $FOTOS_DIR "$sku.png")
        )

        $found = $null
        foreach ($p in $paths) {
            if (Test-Path -LiteralPath $p) { $found = $p; break }
        }

        if ($null -eq $found) {
            Write-Log "  SKIP $sku — imagen no encontrada en $FOTOS_DIR"
            $skip++
            continue
        }

        $endpoint_url = "$WP_URL/wp-json/adn-loro/v1/upload-image"
        $result_raw = (& curl.exe -s $endpoint_url `
            -H "X-ADN-Key: $ADN_KEY" `
            -F "sku=$sku" `
            -F "force=0" `
            -F "image=@`"$found`";type=image/jpeg" `
            --max-time 60) -join ''

        if ($result_raw -match '"success"\s*:\s*true')  { $ok++;   Write-Log "  OK   $sku" }
        elseif ($result_raw -match '"skipped"\s*:\s*true') { $skip++; Write-Log "  SKIP $sku (ya tiene imagen)" }
        else { $err++; Write-Log "  ERR  $sku → $result_raw" }
    }

    Write-Log "Imágenes: ok=$ok skip=$skip err=$err"
}

# ── FUNCIÓN: Consultar pedidos web pendientes ─────────────────────────────────
function Get-PedidosWeb {
    Write-Log "=== Consultando PEDIDOS WEB pendientes ==="

    $resp = Invoke-WP "orders?only_pending=1&status=processing"
    if ($null -eq $resp -or $resp.total -eq 0) {
        Write-Log "No hay pedidos pendientes."
        return
    }

    Write-Log "Pedidos pendientes: $($resp.total)"
    foreach ($order in $resp.orders) {
        Write-Log "  Pedido #$($order.order_number) | $($order.date) | $($order.customer_name) | Total: $($order.total) $($order.currency)"
        foreach ($item in $order.items) {
            Write-Log "    SKU=$($item.sku) | $($item.name) | Qty=$($item.qty) | Subtotal=$($item.subtotal)"
        }
    }

    # Exportar a JSON para importar manualmente en ADN
    $json_path = Join-Path $PSScriptRoot "pedidos_web_$(Get-Date -Format 'yyyyMMdd_HHmmss').json"
    $resp.orders | ConvertTo-Json -Depth 10 | Out-File -FilePath $json_path -Encoding UTF8
    Write-Log "Pedidos exportados a: $json_path"

    # Marcar como sincronizados
    $order_ids = $resp.orders | ForEach-Object { $_.order_id }
    $mark_resp = Invoke-WP "orders/mark-synced" "POST" @{ order_ids = $order_ids }
    if ($mark_resp) {
        Write-Log "Pedidos marcados como sincronizados: $($mark_resp.marked)"
    }
}

# ── FUNCIÓN: Ver estado ───────────────────────────────────────────────────────
function Get-Status {
    Write-Log "=== ESTADO DEL SITIO ==="
    $resp = Invoke-WP "status"
    if ($resp) {
        Write-Log "Plugin version : $($resp.plugin_version)"
        Write-Log "Clave config.  : $($resp.key_configured)"
        Write-Log "Última sincro  : $($resp.last_sync)"
        Write-Log "Imgs pendientes: $($resp.pending_images)"
        Write-Log "Ped. pendientes: $($resp.pending_orders)"
        Write-Log "WooCommerce    : $($resp.woocommerce)"
    }
}

# ── EJECUCIÓN ─────────────────────────────────────────────────────────────────
Write-Log "=============================="
Write-Log "Inicio sincronización — Modo: $Modo"
Write-Log "=============================="

switch ($Modo) {
    "productos"  { Sync-Productos }
    "marcas"     { Sync-Marcas }
    "categorias" { Sync-Categorias }
    "imagenes"   { Sync-Imagenes }
    "pedidos"    { Get-PedidosWeb }
    "status"     { Get-Status }
    "todo" {
        Get-Status
        Sync-Marcas
        Sync-Categorias
        Sync-Productos
        Sync-Imagenes
        Get-PedidosWeb
    }
}

Write-Log "=============================="
Write-Log "Fin sincronización"
Write-Log "=============================="
