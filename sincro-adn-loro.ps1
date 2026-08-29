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
    [ValidateSet("todo","productos","marcas","categorias","imagenes","pedidos","clientes","existencias","eliminados","pedidos-adn","status")]
    [string]$Modo = "todo",
    [string]$LogFile = ""
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

# Configuración ADN para pedidos web
$ADN_SCS     = "000001"    # Sucursal
$ADN_VEN     = "001"       # Vendedor por defecto
$ADN_AMC     = "001"       # Almacén
$ADN_CTR     = "001"       # Contrato/Centro
$ADN_UBP     = "000001"    # Ubicación
$ADN_CCT     = "000001"    # Centro de costo
$ADN_CLT_GUEST = ""        # Código cliente para pedidos de invitados (vacío = saltar)
$ADN_TASA_MANUAL = 775.3356        # Tasa de cambio manual (0 = auto-detectar del último PEDW en ADN)

$LOG_FILE    = if ($LogFile) { $LogFile } else { Join-Path $PSScriptRoot "sincro-loro.log" }
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

function Execute-MySQL([string]$sql) {
    try {
        $connStr = "Driver={MySQL ODBC 5.3 Unicode Driver};Server=$mysql_host;Port=$mysql_port;Database=$mysql_db;Uid=$mysql_user;Pwd=$mysql_pass;charset=utf8;"
        $conn = New-Object System.Data.Odbc.OdbcConnection($connStr)
        $conn.Open()
        $cmd = $conn.CreateCommand()
        $cmd.CommandTimeout = 60
        $cmd.CommandText = $sql
        $affected = $cmd.ExecuteNonQuery()
        $conn.Close()
        return $affected
    } catch {
        Write-Log "ERROR MySQL Execute: $_"
        return -1
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
            name        = $_.name.Trim()
            price       = [double]($_.price -replace '[^0-9.]','')
            stock       = [int][double]($_.stock -replace ',','.')
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

# ── FUNCIÓN: Sincronizar solo existencias (stock) ───────────────────────────
function Sync-Existencias {
    Write-Log "=== Sincronizando EXISTENCIAS (stock) ==="

    $sql = @"
SELECT
    TRIM(p.PDT_CODIGO) AS sku,
    COALESCE((SELECT SUM(u.UGR_EXIS) FROM adn_undagru u
              WHERE u.UGR_PDT_CODIGO = p.PDT_CODIGO), 0) AS stock
FROM adn_productos p
WHERE p.PDT_ESTADO   = 1
  AND p.PDT_OCULTO   = 0
  AND p.PDT_SUBIRWEB = 1
  AND TRIM(p.PDT_CODIGO) <> ''
ORDER BY p.PDT_CODIGO;
"@

    $rows = Run-MySQL $sql
    if ($null -eq $rows -or $rows.Count -eq 0) {
        Write-Log "No se obtuvieron existencias de ADN."
        return
    }

    $stock_list = $rows | ForEach-Object {
        [PSCustomObject]@{
            sku   = $_.sku.Trim()
            stock = [int][double]($_.stock -replace ',','.')
        }
    } | Where-Object { $_.sku -ne "" }

    Write-Log "Productos con existencia a actualizar: $($stock_list.Count)"

    $total_ok = 0; $total_err = 0
    $lotes = [Math]::Ceiling($stock_list.Count / $LOTE_SIZE)

    for ($i = 0; $i -lt $lotes; $i++) {
        $inicio = $i * $LOTE_SIZE
        $lote   = $stock_list | Select-Object -Skip $inicio -First $LOTE_SIZE
        Write-Log "Lote $($i+1)/$lotes — $($lote.Count) SKUs..."

        $resp = Invoke-WP "ingest-products" "POST" @{ products = $lote; only_stock = $true }
        if ($resp) {
            $total_ok  += $resp.updated
            $total_err += $resp.errors
            Write-Log "  → updated=$($resp.updated) errors=$($resp.errors)"
        } else {
            Write-Log "  → ERROR: sin respuesta del servidor"
            $total_err += $lote.Count
        }
        Start-Sleep -Milliseconds 300
    }

    Write-Log "TOTAL existencias: ok=$total_ok err=$total_err"
}

# ── FUNCIÓN: Subir imágenes de productos ──────────────────────────────────
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

# ── FUNCIÓN: Sincronizar Clientes ADN → Usuarios WordPress ──────────────────
function Sync-Clientes {
    Write-Log "=== Sincronizando CLIENTES ==="

    $sql = @"
SELECT
    TRIM(c.CLT_CODIGO)                                                         AS codigo,
    REPLACE(TRIM(c.CLT_NOMBRE), '"', '')                                       AS nombre,
    TRIM(COALESCE(NULLIF(TRIM(c.CLT_EMAILWEB),''), NULLIF(TRIM(c.CLT_EMAIL),''), '')) AS email,
    TRIM(CONCAT_WS(' ',
        NULLIF(TRIM(c.CLT_PNOMBRE),''),
        NULLIF(TRIM(c.CLT_SNOMBRE),'')))                                        AS primer_nombre,
    TRIM(CONCAT_WS(' ',
        NULLIF(TRIM(c.CLT_PAPELLIDO),''),
        NULLIF(TRIM(c.CLT_SAPELLIDO),'')))                                      AS apellido,
    TRIM(COALESCE(NULLIF(TRIM(c.CLT_CELULAR),''), NULLIF(TRIM(c.CLT_TELEFONO1),''), '')) AS telefono,
    REPLACE(TRIM(COALESCE(NULLIF(TRIM(c.CLT_DIRECCION1),''), '')), '"', '')   AS direccion,
    TRIM(c.CLT_RIF)                                                            AS rif,
    TRIM(c.CLT_ZONA)                                                           AS ciudad,
    TRIM(c.CLT_CODIGOPOSTAL)                                                   AS codigo_postal,
    TRIM(c.CLT_PASSWORD)                                                       AS clave_adn
FROM adn_clientes c
WHERE c.CLT_ACTIVO = 1
  AND c.CLT_SITUACION = 'ACT'
  AND TRIM(c.CLT_CODIGO) <> ''
  AND TRIM(c.CLT_NOMBRE)  <> ''
ORDER BY c.CLT_CODIGO;
"@

    $rows = Run-MySQL $sql
    if ($null -eq $rows -or $rows.Count -eq 0) {
        Write-Log "No se obtuvieron clientes de ADN."
        return
    }

    $all_customers = $rows | ForEach-Object {
        [PSCustomObject]@{
            codigo        = $_.codigo.Trim()
            nombre        = $_.nombre.Trim()
            primer_nombre = $_.primer_nombre.Trim()
            apellido      = $_.apellido.Trim()
            email         = $_.email.Trim()
            telefono      = $_.telefono.Trim()
            direccion     = $_.direccion.Trim()
            rif           = $_.rif.Trim()
            ciudad        = $_.ciudad.Trim()
            codigo_postal = $_.codigo_postal.Trim()
            clave_adn     = $_.clave_adn.Trim()
        }
    } | Where-Object { $_.codigo -ne "" }

    if ($all_customers.Count -eq 0) { Write-Log "Sin clientes para sincronizar."; return }
    Write-Log "Total clientes en ADN: $($all_customers.Count)"

    # Enviar en lotes de 200
    $LOTE_CLI  = 200
    $total_created = 0; $total_updated = 0; $total_errors = 0
    $lotes = [Math]::Ceiling($all_customers.Count / $LOTE_CLI)

    for ($i = 0; $i -lt $lotes; $i++) {
        $inicio = $i * $LOTE_CLI
        $lote   = $all_customers | Select-Object -Skip $inicio -First $LOTE_CLI
        Write-Log "Lote $($i+1)/$lotes — enviando $($lote.Count) clientes..."

        $resp = Invoke-WP "ingest-customers" "POST" @{ customers = $lote }
        if ($resp) {
            $total_created += $resp.created
            $total_updated += $resp.updated
            $total_errors  += $resp.errors
            Write-Log "  → created=$($resp.created) updated=$($resp.updated) errors=$($resp.errors)"
        } else {
            Write-Log "  → ERROR: sin respuesta del servidor"
            $total_errors += $lote.Count
        }

        Start-Sleep -Milliseconds 300
    }

    Write-Log "TOTAL clientes: created=$total_created updated=$total_updated errors=$total_errors"
}

# ── FUNCIÓN: Obtener próximo número de pedido web en ADN ──────────────────────
function Get-NextPedwNumero {
    $sql = "SELECT LPAD(CAST(COALESCE(MAX(CAST(DCL_NUMERO AS UNSIGNED)), 0) + 1 AS UNSIGNED), 10, '0') AS NEXT_NUM FROM ADN_DOCCLI WHERE DCL_TDT_CODIGO = 'PEDW';"
    $rows = Run-MySQL $sql
    if ($rows -and $rows[0].NEXT_NUM) { return $rows[0].NEXT_NUM.Trim() }
    return "0000000001"
}

# ── FUNCIÓN: Obtener tasa de cambio actual ─────────────────────────────────────
function Get-TasaActual {
    $sql = "SELECT DCL_VALORCAM, DCL_TTV_CODIGO FROM ADN_DOCCLI WHERE DCL_TDT_CODIGO = 'PEDW' AND DCL_VALORCAM > 0 ORDER BY DCL_FECHAHORA DESC LIMIT 1;"
    $rows = Run-MySQL $sql
    if ($rows -and $rows[0].DCL_VALORCAM) {
        return @{ tasa = [double]$rows[0].DCL_VALORCAM; ttv = $rows[0].DCL_TTV_CODIGO.Trim() }
    }
    return @{ tasa = 1.0; ttv = "" }
}

# ── FUNCIÓN: Escapar string para SQL ──────────────────────────────────────────
function Escape-SQL([string]$str) {
    return $str.Replace("'", "''").Replace("\\", "\\\\")
}

# ── FUNCIÓN: Crear cliente nuevo en ADN desde datos del pedido web ────────────
function New-AdnCliente($order) {
    # Generar próximo CLT_CODIGO para cliente web (WEB + 9 dígitos = 12 chars)
    $sql_max = "SELECT LPAD(CAST(COALESCE(MAX(CAST(SUBSTRING(CLT_CODIGO, 4) AS UNSIGNED)), 0) + 1 AS UNSIGNED), 9, '0') AS NEXT_N FROM adn_clientes WHERE CLT_CODIGO LIKE 'WEB%';"
    $rows_max = Run-MySQL $sql_max
    $seq = if ($rows_max -and $rows_max[0].NEXT_N) { $rows_max[0].NEXT_N.Trim() } else { "000000001" }
    $clt_codigo = "WEB$seq"

    # RIF: prefijo del email en mayúsculas (máx 14 chars)
    $email_prefix = ($order.customer_email -split '@')[0].ToUpper()
    $clt_rif = $email_prefix.Substring(0, [Math]::Min($email_prefix.Length, 14))

    # Nombre completo
    $nombre_raw = if (![string]::IsNullOrWhiteSpace($order.customer_name)) { $order.customer_name } else { $order.customer_email }
    $clt_nombre    = (Escape-SQL $nombre_raw).Substring(0, [Math]::Min($nombre_raw.Length, 250))

    # Primer nombre / apellido separados
    $fn_raw = if (![string]::IsNullOrWhiteSpace($order.customer_first_name)) { $order.customer_first_name } else { ($nombre_raw -split ' ')[0] }
    $ln_raw = if (![string]::IsNullOrWhiteSpace($order.customer_last_name))  { $order.customer_last_name  } else { ($nombre_raw -split ' ', 2)[1] }
    $clt_pnombre   = (Escape-SQL $fn_raw).Substring(0, [Math]::Min($fn_raw.Length, 20))
    $clt_papellido = (Escape-SQL $ln_raw).Substring(0, [Math]::Min($ln_raw.Length, 20))

    # Teléfono, email, dirección
    $tel_raw  = if ($order.customer_phone) { $order.customer_phone } else { '' }
    $clt_tel  = (Escape-SQL $tel_raw).Substring(0, [Math]::Min($tel_raw.Length, 15))
    $clt_email = (Escape-SQL $order.customer_email).Substring(0, [Math]::Min($order.customer_email.Length, 50))
    $dir_raw  = if ($order.address) { $order.address } else { '' }
    $clt_dir  = (Escape-SQL $dir_raw).Substring(0, [Math]::Min($dir_raw.Length, 250))
    $ciu_raw  = if ($order.city) { $order.city } else { '' }
    $clt_zona = (Escape-SQL $ciu_raw).Substring(0, [Math]::Min($ciu_raw.Length, 120))

    $clt_fecha     = Get-Date -Format "yyyy-MM-dd"
    $clt_fcreacion = Get-Date -Format "yyyy-MM-dd HH:mm:ss"

    $sql_ins = @"
INSERT INTO adn_clientes (
  CLT_CODIGO, CLT_NOMBRE, CLT_RIF,
  CLT_TELEFONO1, CLT_TELEFONO2, CLT_TELEFONO3,
  CLT_DIRECCION1, CLT_DIRECCION2, CLT_DIRECCION3,
  CLT_CELULAR, CLT_EMAIL, CLT_CONTRIBUYENTE, CLT_TIPOPER,
  CLT_ACTIVO, CLT_CCL_CODIGO, CLT_CUENTA, CLT_PLT_LISTA,
  CLT_DIASCRE, CLT_LIMCRE, CLT_CONDICION,
  CLT_SCL_CODIGO, CLT_SHORTNAME, CLT_SITUACION,
  CLT_PORDESC, CLT_VEN_CODIGO, CLT_AUDFECHA, CLT_FECHA,
  CLT_ESTACION, CLT_IP,
  CLT_PNOMBRE, CLT_PAPELLIDO,
  CLT_TIPOREG, CLT_FCREACION, CLT_AEC_CODIGO, CLT_SINCRO,
  CLT_ZONA, CLT_EMAILWEB
) VALUES (
  '$clt_codigo', '$clt_nombre', '$clt_rif',
  '$clt_tel', '', '',
  '$clt_dir', '', '',
  '$clt_tel', '$clt_email', 0, 'N',
  1, '000001', 'Indefinida', 'A',
  0, 1.00, 'CONTADO',
  '000001', '$clt_pnombre', 'ACT',
  0.00, '$ADN_VEN', '$clt_fecha', '$clt_fecha',
  'WEB', 'WEB',
  '$clt_pnombre', '$clt_papellido',
  '4', '$clt_fcreacion', '000001', 1,
  '$clt_zona', '$clt_email'
);
"@

    $res = Execute-MySQL $sql_ins
    if ($res -lt 0) {
        Write-Log "    ERROR: no se pudo crear cliente web en ADN"
        return $null
    }
    Write-Log "    INFO: cliente web creado en ADN → $clt_codigo ($clt_nombre | $($order.customer_email))"
    return $clt_codigo
}

# ── FUNCIÓN: Sincronizar pedidos WooCommerce → ADN_DOCCLI / ADN_MOVCLI ────────
function Sync-PedidosWeb {
    Write-Log "=== Sincronizando PEDIDOS WEB WordPress → ADN ==="

    # 1. Obtener pedidos no sincronizados del WordPress
    $resp = Invoke-WP "orders?only_pending=1&status=processing,on-hold&limit=50"
    if ($null -eq $resp -or $resp.total -eq 0) {
        Write-Log "No hay pedidos pendientes."
        return
    }
    Write-Log "Pedidos a insertar en ADN: $($resp.total)"

    # 2. Obtener tasa de cambio actual
    if ($ADN_TASA_MANUAL -gt 0) {
        $tasa    = $ADN_TASA_MANUAL
        $ttv_cod = ""
        Write-Log "Tasa de cambio (manual): $tasa"
    } else {
        $tasaInfo = Get-TasaActual
        $tasa     = $tasaInfo.tasa
        $ttv_cod  = $tasaInfo.ttv
        Write-Log "Tasa de cambio (auto): $tasa (TTV: $ttv_cod)"
        if ($tasa -le 1) {
            Write-Log "  ADVERTENCIA: tasa=$tasa parece incorrecta. Configura \$ADN_TASA_MANUAL en el script."
        }
    }

    $synced_ids = @()
    $ok_count   = 0
    $err_count  = 0

    foreach ($order in $resp.orders) {
        $order_num = $order.order_number
        $adn_cli   = $order.customer_adn_code

        # Si no hay código ADN, intentar buscar por RIF del email en adn_clientes
        if ([string]::IsNullOrWhiteSpace($adn_cli) -and $order.customer_email -match '^(.+)@') {
            $rif_raw = $matches[1].ToUpper()
            $sql_cli = "SELECT CLT_CODIGO FROM adn_clientes WHERE REPLACE(REPLACE(UPPER(TRIM(CLT_RIF)),'-',''),' ','') = '$rif_raw' OR UPPER(TRIM(CLT_CODIGO)) = '$rif_raw' LIMIT 1;"
            $cli_rows = Run-MySQL $sql_cli
            if ($cli_rows -and $cli_rows[0].CLT_CODIGO) {
                $adn_cli = $cli_rows[0].CLT_CODIGO.Trim()
                Write-Log "  INFO #$order_num : cliente encontrado en ADN por RIF '$rif_raw' → $adn_cli"
            }
        }

        # Si aún no hay código, crear cliente nuevo en ADN
        if ([string]::IsNullOrWhiteSpace($adn_cli)) {
            Write-Log "  INFO #$order_num : creando nuevo cliente en ADN para $($order.customer_email)"
            $adn_cli = New-AdnCliente $order
            if ([string]::IsNullOrWhiteSpace($adn_cli)) {
                # Fallback a cliente genérico o saltar
                if ([string]::IsNullOrWhiteSpace($ADN_CLT_GUEST)) {
                    Write-Log "  SKIP #$order_num : no se pudo crear cliente (email: $($order.customer_email))"
                    $err_count++
                    continue
                }
                $adn_cli = $ADN_CLT_GUEST
                Write-Log "  INFO #$order_num : fallback a cliente genérico '$ADN_CLT_GUEST'"
            }
        }

        # Saltar si no hay items
        if ($null -eq $order.items -or $order.items.Count -eq 0) {
            Write-Log "  SKIP #$order_num : sin ítems"
            $err_count++
            continue
        }

        # 3. Generar próximo DCL_NUMERO (con pausa para evitar duplicados)
        $dcl_numero = Get-NextPedwNumero
        $dcl_fecha    = $order.date.Substring(0, 10)
        $dcl_hora     = if ($order.date.Length -ge 19) { $order.date.Substring(11, 8) } else { "00:00:00" }
        $dcl_fechahora = $order.date
        $dcl_neto     = [math]::Round([double]$order.total, 3)
        $dcl_comen    = Escape-SQL ($order.note)
        $dcl_cli      = Escape-SQL $adn_cli
        $ttv_sql      = if ([string]::IsNullOrWhiteSpace($ttv_cod)) { "0" } else { $ttv_cod }

        # 4. INSERT en ADN_DOCCLI
        $sql_dcl = @"
INSERT INTO ADN_DOCCLI (
  DCL_NUMERO, DCL_TDT_CODIGO, DCL_SCS_CODIGO, DCL_VEN_CODIGO, DCL_CLT_CODIGO,
  DCL_FECHA, DCL_NETO, DCL_EXENTO, DCL_BRUTO, DCL_BASEG, DCL_BASER,
  DCL_IVAG, DCL_IVAR, DCL_BASES, DCL_IVAS, DCL_LIBVTA,
  DCL_TIPTRA, DCL_ACTIVO, DCL_STD_ESTADO, DCL_PORDESC, DCL_FECHAHORA,
  DCL_HORA, DCL_PLAZO, DCL_CONDICION, DCL_ORDEN, DCL_TIENERTI,
  DCL_CUENTA, DCL_PORCENTAJE, DCL_TIPOINV, DCL_IVAMODIF,
  DCL_DSPACH, DCL_IDCAJA, DCL_FALLAE, DCL_URGENTE, DCL_EXONERADO,
  DCL_ORIGEN, DCL_RECIBO_EMAIL, DCL_COMEN, DCL_IP, DCL_MONTODCTO,
  DCL_ENUSO, DCL_CCT_CODIGO, DCL_VALORCAM, DCL_TTV_CODIGO, DCL_MONEDA
) VALUES (
  '$dcl_numero', 'PEDW', '$ADN_SCS', '$ADN_VEN', '$dcl_cli',
  '$dcl_fecha', $dcl_neto, $dcl_neto, $dcl_neto, 0.000, 0.000,
  0.000, 0.000, 0.000, 0.000, 0.00,
  'D', 1, 'PEN', 0.00, '$dcl_fechahora',
  '$dcl_hora', 0, 'CONTADO', 0, 0,
  'Indefinida', 0.00, 1, 0,
  1, 'WEB', 1, 0, 0,
  'WEB', 1, '$dcl_comen', 'WEB', 0.000,
  0, '$ADN_CCT', $tasa, $ttv_sql, ''
);
"@

        $res_dcl = Execute-MySQL $sql_dcl
        if ($res_dcl -lt 0) {
            Write-Log "  ERROR DCL #$order_num → $dcl_numero : fallo INSERT ADN_DOCCLI"
            $err_count++
            continue
        }
        Write-Log "  OK DCL #$order_num → PEDW $dcl_numero | Cliente: $adn_cli | Total: $dcl_neto"

        # 5. INSERT cada ítem en ADN_MOVCLI
        $items_ok = $true
        foreach ($item in $order.items) {
            if ([string]::IsNullOrWhiteSpace($item.sku)) {
                Write-Log "    SKIP item sin SKU: $($item.name)"
                continue
            }
            $mcl_sku    = Escape-SQL $item.sku
            $mcl_qty    = [math]::Round([double]$item.qty, 3)
            $mcl_base   = [math]::Round([double]$item.unit_price, 3)
            $mcl_usd    = if ($tasa -gt 0) { [math]::Round($mcl_base / $tasa, 4) } else { 0 }
            $mcl_descri = Escape-SQL ($item.name.Substring(0, [Math]::Min($item.name.Length, 100)))

            $sql_mcl = @"
INSERT INTO ADN_MOVCLI (
  MCL_HTI_ID, MCL_DCL_SCS_CODIGO, MCL_DCL_NUMERO, MCL_DCL_TDT_CODIGO,
  MCL_AMC_CODIGO, MCL_UPP_PDT_CODIGO, MCL_UPP_UND_ID,
  MCL_DCL_REC_NUMERO, MCL_CTR_CODIGO,
  MCL_CANTIDAD, MCL_COSTOT, MCL_COSTOP, MCL_FISICO, MCL_LOGICO, MCL_CONTABLE,
  MCL_ACTIVO, MCL_PORDCTO, MCL_BASE, MCL_DCL_TIPTRA, MCL_PLT_LISTA, MCL_CANTXUND,
  MCL_PORIVA, MCL_TIVACOD, MCL_COMPUESTO, MCL_ASOCIADO, MCL_VEN_CODIGO,
  MCL_PDT_FRACCION, MCL_SALDOV, MCL_METCOS, MCL_OFERTA1, MCL_OFERTA2, MCL_DESCMAYOR,
  MCL_DESCRI, MCL_LAB_ESTADO, MCL_NETO, MCL_COMANDA, MCL_BASEAUX,
  MCL_COSTOMER, MCL_COSTOU, MCL_BASEC, MCL_EXPORT, MCL_ENTREGADO, MCL_PF,
  MCL_PORDCTO2, MCL_PRECIOUSD, MCL_FECHAHORA, MCL_UBP_CODIGO
) VALUES (
  1, '$ADN_SCS', '$dcl_numero', 'PEDW',
  '$ADN_AMC', '$mcl_sku', 'UND',
  '', '$ADN_CTR',
  $mcl_qty, 0.0000, 0.0000, 0, 0, 0,
  1, 0.00, $mcl_base, 'D', 'A', 1.000,
  0.00, 'EX', 0, 0, '$ADN_VEN',
  1, 0.00, 4, 0.00, 0.00, 0.00,
  '$mcl_descri', 1, 0.000, 0, 0.000,
  0.0000, 0.0000, $mcl_base, 0.000, 0.0000, 0,
  0.00, $mcl_usd, '$dcl_fechahora', '$ADN_UBP'
);
"@
            $res_mcl = Execute-MySQL $sql_mcl
            if ($res_mcl -lt 0) {
                Write-Log "    ERROR MCL SKU=$($item.sku): fallo INSERT ADN_MOVCLI"
                $items_ok = $false
            } else {
                Write-Log "    OK SKU=$($item.sku) | Qty=$mcl_qty | Precio=$mcl_base | USD=$mcl_usd"
            }
        }

        # 6. Marcar en WordPress (aunque algún ítem haya fallado, para no reintentar)
        $synced_ids += $order.order_id
        if ($items_ok) { $ok_count++ } else { $err_count++ }
        Start-Sleep -Milliseconds 100
    }

    # 7. Notificar a WordPress
    if ($synced_ids.Count -gt 0) {
        $mark = Invoke-WP "orders/mark-synced" "POST" @{ order_ids = $synced_ids }
        if ($mark) { Write-Log "Pedidos marcados en WP: $($mark.marked)" }
    }

    Write-Log "Sync pedidos: OK=$ok_count ERRORES=$err_count"
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

# ── FUNCIÓN: Archivar productos eliminados/desactivados en ADN ────────────────
function Sync-ProductosEliminados {
    Write-Log "=== Sincronizando PRODUCTOS ELIMINADOS ==="

    $sql = @"
SELECT TRIM(PDT_CODIGO) AS sku
FROM adn_productos
WHERE PDT_ESTADO   = 1
  AND PDT_OCULTO   = 0
  AND PDT_SUBIRWEB = 1
  AND TRIM(PDT_CODIGO) <> '';
"@

    $rows = Run-MySQL $sql
    if ($null -eq $rows) { Write-Log "Error consultando SKUs activos en ADN."; return }

    $active_skus = @($rows | ForEach-Object { $_.sku.Trim() } | Where-Object { $_ -ne "" })
    Write-Log "SKUs activos en ADN: $($active_skus.Count)"

    $resp = Invoke-WP "delete-absent-products" "POST" @{ active_skus = $active_skus }
    if ($resp) {
        Write-Log "Productos archivados en WP: drafted=$($resp.drafted) errors=$($resp.errors) skipped=$($resp.skipped)"
    }
}

# ── FUNCIÓN: Sincronizar documentos de venta ADN → WordPress ─────────────────
function Sync-PedidosADN {
    Write-Log "=== Sincronizando DOCUMENTOS DE VENTA ADN → Web ==="

    $sql = @"
SELECT
    dc.DCL_TDT_CODIGO                                    AS tipo,
    TRIM(dc.DCL_NUMERO)                                  AS numero,
    dc.DCL_FECHAHORA                                     AS fecha,
    TRIM(dc.DCL_CLT_CODIGO)                              AS cliente_codigo,
    TRIM(c.CLT_RIF)                                      AS rif,
    TRIM(COALESCE(c.CLT_EMAILWEB, c.CLT_EMAIL, ''))      AS email,
    COALESCE(dc.DCL_TOTAL, 0)                            AS total_bs,
    COALESCE(dc.DCL_TOTALME, 0)                          AS total_usd,
    TRIM(COALESCE(dc.DCL_ESTADO, ''))                    AS estado,
    TRIM(COALESCE(mc.MCL_PDT_CODIGO, ''))                AS sku,
    REPLACE(TRIM(COALESCE(mc.MCL_DESCRIPCION, '')), '"', '') AS descripcion,
    COALESCE(mc.MCL_CANTIDAD, 0)                         AS cantidad,
    COALESCE(mc.MCL_PRECIO, 0)                           AS precio,
    COALESCE(mc.MCL_PRECIOME, 0)                         AS precio_usd
FROM ADN_DOCCLI dc
JOIN adn_clientes c  ON dc.DCL_CLT_CODIGO = c.CLT_CODIGO
JOIN ADN_MOVCLI mc   ON mc.MCL_DCL_NUMERO = dc.DCL_NUMERO
                     AND mc.MCL_TDT_CODIGO = dc.DCL_TDT_CODIGO
WHERE dc.DCL_TDT_CODIGO IN ('PEDW','PED','FAC','FACO')
  AND dc.DCL_FECHAHORA >= DATE_SUB(NOW(), INTERVAL 365 DAY)
  AND TRIM(c.CLT_RIF) <> ''
  AND TRIM(c.CLT_RIF) NOT IN ('00000000','0000000-0','INDEFINIDO')
ORDER BY dc.DCL_CLT_CODIGO, dc.DCL_FECHAHORA DESC;
"@

    $rows = Run-MySQL $sql
    if ($null -eq $rows -or $rows.Count -eq 0) {
        Write-Log "No hay documentos de venta en el último año."
        return
    }

    Write-Log "Filas obtenidas: $($rows.Count)"

    # Agrupar líneas por tipo+numero → un documento con array de items
    $dict = [ordered]@{}
    foreach ($row in $rows) {
        $key = "$($row.tipo.Trim())|$($row.numero.Trim())"
        if (-not $dict.ContainsKey($key)) {
            $dict[$key] = [PSCustomObject]@{
                tipo           = $row.tipo.Trim()
                numero         = $row.numero.Trim()
                fecha          = $row.fecha.Trim()
                cliente_codigo = $row.cliente_codigo.Trim()
                rif            = $row.rif.Trim()
                email          = $row.email.Trim()
                total_bs       = [double]($row.total_bs  -replace '[^0-9.]','')
                total_usd      = [double]($row.total_usd -replace '[^0-9.]','')
                estado         = $row.estado.Trim()
                items          = [System.Collections.Generic.List[object]]::new()
            }
        }
        $dict[$key].items.Add([PSCustomObject]@{
            sku        = $row.sku.Trim()
            descripcion= $row.descripcion.Trim()
            cantidad   = [double]($row.cantidad   -replace '[^0-9.]','')
            precio     = [double]($row.precio     -replace '[^0-9.]','')
            precio_usd = [double]($row.precio_usd -replace '[^0-9.]','')
        })
    }

    $orders = @($dict.Values)
    Write-Log "Documentos únicos: $($orders.Count)"

    $LOTE_ORD     = 50
    $lotes        = [Math]::Ceiling($orders.Count / $LOTE_ORD)
    $total_synced = 0; $total_errors = 0

    for ($i = 0; $i -lt $lotes; $i++) {
        $inicio = $i * $LOTE_ORD
        $lote   = $orders | Select-Object -Skip $inicio -First $LOTE_ORD
        Write-Log "Lote $($i+1)/$lotes — $($lote.Count) documentos..."

        $resp = Invoke-WP "ingest-adn-orders" "POST" @{ orders = $lote }
        if ($resp) {
            $total_synced += $resp.synced
            $total_errors += $resp.errors
            Write-Log "  → synced=$($resp.synced) errors=$($resp.errors)"
        } else {
            Write-Log "  → ERROR: sin respuesta del servidor"
            $total_errors += $lote.Count
        }
        Start-Sleep -Milliseconds 500
    }

    Write-Log "TOTAL pedidos ADN: synced=$total_synced errors=$total_errors"
}

switch ($Modo) {
    "productos"   { Sync-Productos }
    "marcas"      { Sync-Marcas }
    "categorias"  { Sync-Categorias }
    "imagenes"    { Sync-Imagenes }
    "pedidos"     { Sync-PedidosWeb }
    "existencias" { Sync-Existencias }
    "clientes"    { Sync-Clientes }
    "eliminados"  { Sync-ProductosEliminados }
    "pedidos-adn" { Sync-PedidosADN }
    "status"      { Get-Status }
    "todo" {
        Get-Status
        Sync-Marcas
        Sync-Categorias
        Sync-Productos
        Sync-ProductosEliminados
        Sync-Imagenes
        Sync-Clientes
        Sync-PedidosWeb
        Sync-PedidosADN
    }
}

Write-Log "=============================="
Write-Log "Fin sincronización"
Write-Log "=============================="
