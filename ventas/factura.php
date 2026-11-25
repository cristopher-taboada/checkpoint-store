
<?php
require_once __DIR__ . '/../config/auth.php';
requireRole('Administrador', 'Vendedor');
require_once __DIR__ . '/../config/conexion.php';

// VALIDAR IDENTIFICADOR
$idVenta = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);

if ($idVenta === false || $idVenta < 1) {
    http_response_code(400);
    exit('Comprobante no válido.');
}

function facturaEsc($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

// CONSULTAR CABECERA
$stmt = $conexion->prepare(
    "SELECT v.id_venta, v.fecha, v.total, v.cliente, v.cliente_nit,
            v.metodo_pago, v.observacion, v.estado,
            u.nombre_completo AS cajero
     FROM ventas v
     INNER JOIN usuarios u ON v.id_usuario = u.id_usuario
     WHERE v.id_venta = ? LIMIT 1"
);

$stmt->execute([$idVenta]);
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    http_response_code(404);
    exit('La venta solicitada no existe.');
}

// CONSULTAR PRODUCTOS VENDIDOS
$stmt = $conexion->prepare(
    "SELECT dv.cantidad, dv.precio_unitario, dv.subtotal,
            p.nombre, p.codigo
     FROM detalle_venta dv
     INNER JOIN productos p ON dv.id_producto = p.id_producto
     WHERE dv.id_venta = ?
     ORDER BY dv.id_detalle_venta ASC"
);

$stmt->execute([$idVenta]);
$detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$detalles) {
    exit('Esta venta no contiene productos registrados.');
}

// DATOS DEL COMPROBANTE
$numero = str_pad((string)$venta['id_venta'], 6, '0', STR_PAD_LEFT);
$fecha = $venta['fecha'] ? strtotime($venta['fecha']) : time();

$cliente = trim((string)($venta['cliente'] ?? '')) ?: 'Consumidor final';
$clienteNit = trim((string)($venta['cliente_nit'] ?? '')) ?: 'No registrado';
$metodo = trim((string)($venta['metodo_pago'] ?? '')) ?: 'No registrado';
$observacion = trim((string)($venta['observacion'] ?? ''));

// CONVERTIR MONTO A LETRAS
function menorMilEnLetras($n) {
    $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];

    $especiales = [
        10=>'diez',11=>'once',12=>'doce',13=>'trece',14=>'catorce',
        15=>'quince',16=>'dieciséis',17=>'diecisiete',18=>'dieciocho',
        19=>'diecinueve',20=>'veinte',21=>'veintiuno',22=>'veintidós',
        23=>'veintitrés',24=>'veinticuatro',25=>'veinticinco',
        26=>'veintiséis',27=>'veintisiete',28=>'veintiocho',29=>'veintinueve'
    ];

    $decenas = [
        30=>'treinta',40=>'cuarenta',50=>'cincuenta',
        60=>'sesenta',70=>'setenta',80=>'ochenta',90=>'noventa'
    ];

    $centenas = [
        200=>'doscientos',300=>'trescientos',400=>'cuatrocientos',
        500=>'quinientos',600=>'seiscientos',700=>'setecientos',
        800=>'ochocientos',900=>'novecientos'
    ];

    if ($n < 10) return $n === 0 ? '' : $unidades[$n];
    if (isset($especiales[$n])) return $especiales[$n];

    if ($n < 100) {
        $d = intdiv($n,10)*10;
        $u = $n%10;
        return $decenas[$d].($u ? ' y '.$unidades[$u] : '');
    }

    if ($n === 100) return 'cien';

    $c = intdiv($n,100)*100;
    $r = $n%100;
    $texto = $c === 100 ? 'ciento' : $centenas[$c];

    return $texto.($r ? ' '.menorMilEnLetras($r) : '');
}

function apocoparUno($texto) {
    if (substr($texto, -9) === 'veintiuno') {
        return substr($texto, 0, -9).'veintiún';
    }

    if (substr($texto, -3) === 'uno') {
        return substr($texto, 0, -3).'un';
    }

    return $texto;
}

function numeroEnLetras($n) {
    if ($n === 0) return 'cero';

    $partes = [];

    $milesMillones = intdiv($n, 1000000000);
    $n %= 1000000000;

    if ($milesMillones > 0) {
        $partes[] = $milesMillones === 1
            ? 'mil millones'
            : apocoparUno(menorMilEnLetras($milesMillones)).' mil millones';
    }

    $millones = intdiv($n, 1000000);
    $n %= 1000000;

    if ($millones > 0) {
        $partes[] = $millones === 1
            ? 'un millón'
            : apocoparUno(menorMilEnLetras($millones)).' millones';
    }

    $miles = intdiv($n, 1000);
    $n %= 1000;

    if ($miles > 0) {
        $partes[] = $miles === 1
            ? 'mil'
            : apocoparUno(menorMilEnLetras($miles)).' mil';
    }

    if ($n > 0) $partes[] = menorMilEnLetras($n);

    return implode(' ', $partes);
}

function montoEnLetras($monto) {
    $centavosTotal = (int)round((float)$monto * 100);
    $entero = intdiv($centavosTotal, 100);
    $centavos = $centavosTotal % 100;

    $texto = numeroEnLetras($entero);
    $texto = function_exists('mb_strtoupper')
        ? mb_strtoupper($texto, 'UTF-8')
        : strtoupper($texto);

    return $texto.' '.str_pad((string)$centavos, 2, '0', STR_PAD_LEFT).'/100 BOLIVIANOS';
}

$totalLetras = montoEnLetras($venta['total']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprobante <?= facturaEsc($numero) ?> | CheckPoint Store</title>

<style>
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#ECECF0;color:#222;margin:0;padding:25px}
.comprobante{max-width:850px;margin:auto;background:#fff;padding:38px;border:1px solid #DDD}
.cabecera{width:100%;border-collapse:collapse;margin-bottom:25px}
.cabecera td{vertical-align:top}
.marca{width:27%;border-right:2px solid #FF2E93}
.logo{font-size:21px;font-weight:800;color:#20212D}
.logo span{color:#FF2E93}
.empresa{text-align:center;line-height:1.5;font-size:12px;padding:0 10px}
.empresa h2{font-size:19px;margin:0 0 5px;color:#20212D}
.documento{text-align:right;font-size:12px;line-height:1.6}
.documento h2{font-size:18px;margin:0 0 7px;color:#FF2E93}
.documento p{margin:2px 0}
.titulo{text-align:center;font-size:19px;font-weight:bold;letter-spacing:2px;padding:12px;border-top:2px solid #20212D;border-bottom:2px solid #20212D;margin:20px 0}
.subtitulo{text-align:center;font-size:10px;color:#666;margin-top:-12px;margin-bottom:22px}
.datos{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:22px}
.datos td{padding:7px 5px;border-bottom:1px solid #DDD}
.productos{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:20px}
.productos th{background:#20212D;color:white;padding:11px 7px;text-align:center}
.productos td{border-bottom:1px solid #DDD;padding:11px 7px}
.centro{text-align:center}
.derecha{text-align:right}
.totales{width:100%;border-collapse:collapse}
.totales td{padding:10px;border:1px solid #DDD}
.total-label{text-align:right;font-weight:bold;background:#F3F3F5}
.total-valor{text-align:right;font-size:17px;font-weight:bold;color:#FF2E93}
.letras{margin-top:20px;padding:13px;background:#F5F5F7;border-left:3px solid #FF2E93;font-size:12px}
.observacion{margin-top:15px;font-size:11px}
.estado{font-weight:bold;color:#16865F}
.pie{text-align:center;border-top:1px solid #DDD;margin-top:35px;padding-top:15px;font-size:11px;color:#666}
.pie strong{color:#20212D}
.acciones{text-align:center;margin:25px auto}
.btn{display:inline-block;padding:11px 20px;border-radius:5px;border:0;text-decoration:none;font-weight:bold;font-size:13px;cursor:pointer;margin:4px}
.btn-imprimir{background:#FF2E93;color:white}
.btn-word{background:#20212D;color:white}
.btn-volver{background:#DDD;color:#222}
@page{size:A4;margin:12mm}
@media print{
    body{background:white;padding:0}
    .comprobante{max-width:none;padding:10px;border:0}
    .acciones{display:none}
    .productos th{background:#20212D!important;color:white!important;print-color-adjust:exact;-webkit-print-color-adjust:exact}
    .total-label,.letras{print-color-adjust:exact;-webkit-print-color-adjust:exact}
}
@media(max-width:600px){
    body{padding:8px}
    .comprobante{padding:15px}
    .cabecera td{display:block;width:100%;text-align:center;padding:8px}
    .marca{border-right:0;border-bottom:1px solid #DDD}
    .documento{text-align:center}
    .productos{font-size:10px}
}
</style>
</head>

<body>

<div class="comprobante" id="comprobante">

    <table class="cabecera">
        <tr>
            <td class="marca">
                <div class="logo">Check<span>Point</span></div>
                <div class="logo">STORE</div>
            </td>

            <td class="empresa">
                <h2>CHECKPOINT STORE</h2>
                <strong>VENTA DE CONSOLAS Y ACCESORIOS</strong>
                <p>Cochabamba - Bolivia</p>
                <p>Documento de respaldo de operación comercial</p>
            </td>

            <td class="documento">
                <h2>COMPROBANTE</h2>
                <p><strong>N.º:</strong> <?= facturaEsc($numero) ?></p>
                <p><strong>Fecha:</strong> <?= date('d/m/Y',$fecha) ?></p>
                <p><strong>Hora:</strong> <?= date('H:i',$fecha) ?></p>
                <p><strong>Estado:</strong>
                    <span class="estado"><?= facturaEsc($venta['estado']) ?></span>
                </p>
            </td>
        </tr>
    </table>

    <div class="titulo">COMPROBANTE DE VENTA</div>
    <div class="subtitulo">REGISTRO DE OPERACIÓN · CHECKPOINT STORE</div>

    <table class="datos">
        <tr>
            <td width="18%"><strong>Lugar:</strong></td>
            <td width="32%">Cochabamba, Bolivia</td>
            <td width="18%"><strong>Responsable:</strong></td>
            <td width="32%"><?= facturaEsc($venta['cajero']) ?></td>
        </tr>

        <tr>
            <td><strong>Cliente:</strong></td>
            <td><?= facturaEsc($cliente) ?></td>
            <td><strong>CI / NIT:</strong></td>
            <td><?= facturaEsc($clienteNit) ?></td>
        </tr>

        <tr>
            <td><strong>Forma de pago:</strong></td>
            <td colspan="3"><?= facturaEsc($metodo) ?></td>
        </tr>
    </table>

    <table class="productos">
        <thead>
            <tr>
                <th width="12%">CANT.</th>
                <th width="43%">DESCRIPCIÓN</th>
                <th width="20%">PRECIO UNIT.</th>
                <th width="25%">SUBTOTAL</th>
            </tr>
        </thead>

        <tbody>
        <?php foreach ($detalles as $d): ?>
            <tr>
                <td class="centro"><?= (int)$d['cantidad'] ?></td>
                <td>
                    <strong><?= facturaEsc($d['nombre']) ?></strong><br>
                    <small>Código: <?= facturaEsc($d['codigo']) ?></small>
                </td>
                <td class="derecha">Bs <?= number_format((float)$d['precio_unitario'],2) ?></td>
                <td class="derecha">Bs <?= number_format((float)$d['subtotal'],2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totales">
        <tr>
            <td class="total-label" width="75%">TOTAL DE LA VENTA</td>
            <td class="total-valor" width="25%">
                Bs <?= number_format((float)$venta['total'],2) ?>
            </td>
        </tr>
    </table>

    <div class="letras">
        <strong>SON:</strong> <?= facturaEsc($totalLetras) ?>
    </div>

    <?php if ($observacion !== ''): ?>
        <div class="observacion">
            <strong>Observaciones:</strong> <?= facturaEsc($observacion) ?>
        </div>
    <?php endif; ?>

    <div class="pie">
        <strong>CHECKPOINT STORE</strong>
        <p>Gracias por su compra.</p>
        <p>Comprobante generado por el sistema de inventario y ventas.</p>
    </div>

</div>

<div class="acciones">
    <button class="btn btn-imprimir" onclick="window.print()">
        Imprimir / Guardar PDF
    </button>

    <button class="btn btn-word" onclick="exportarWord()">
        Descargar en Word
    </button>

    <a href="index.php" class="btn btn-volver">Volver a ventas</a>
</div>

<script>
function exportarWord() {
    const contenido = document.getElementById('comprobante').innerHTML;

    const estilos = `
        <style>
            body{font-family:Arial,sans-serif;color:#222;font-size:12px}
            table{width:100%;border-collapse:collapse}
            td,th{padding:8px}
            .productos th{background:#20212D;color:white;border:1px solid #222}
            .productos td{border-bottom:1px solid #DDD}
            .centro{text-align:center}
            .derecha{text-align:right}
            .titulo{text-align:center;font-size:19px;font-weight:bold;padding:12px;border-top:2px solid #222;border-bottom:2px solid #222}
            .total-label{text-align:right;font-weight:bold}
            .total-valor{text-align:right;font-weight:bold}
            .letras{padding:12px;border-left:3px solid #FF2E93}
        </style>
    `;

    const documento = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office"
              xmlns:w="urn:schemas-microsoft-com:office:word"
              xmlns="http://www.w3.org/TR/REC-html40">
        <head>
            <meta charset="utf-8">
            <title>Comprobante de venta</title>
            ${estilos}
        </head>
        <body>${contenido}</body>
        </html>
    `;

    const blob = new Blob(['\ufeff',documento], {
        type:'application/msword'
    });

    const url = URL.createObjectURL(blob);
    const enlace = document.createElement('a');

    enlace.href = url;
    enlace.download = 'Comprobante_<?= facturaEsc($numero) ?>.doc';

    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();

    setTimeout(() => URL.revokeObjectURL(url),1000);
}
</script>

</body>
</html>
