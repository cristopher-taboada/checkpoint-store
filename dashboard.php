
<?php
require_once __DIR__ . '/config/auth.php';
requireRole('Administrador', 'Vendedor');
require_once __DIR__ . '/config/conexion.php';

// ==========================================
// 1. USUARIO Y SEGURIDAD
// ==========================================

$usuario = $_SESSION['usuario'];
$rol = $usuario['rol'] ?? '';
$nombre = $usuario['nombre_completo'] ?? 'Usuario';
$esAdmin = strcasecmp($rol, 'Administrador') === 0;

if (empty($_SESSION['csrf_logout'])) {
    $_SESSION['csrf_logout'] = bin2hex(random_bytes(32));
}

function dashEsc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ==========================================
// 2. VALORES INICIALES
// ==========================================

$resumen = [
    'productos' => 0,
    'unidades' => 0,
    'stock_bajo' => 0,
    'agotados' => 0,
    'categorias' => 0,
    'proveedores' => 0
];

$ventasHoy = ['cantidad' => 0, 'total' => 0];
$ventasRecientes = [];
$productosBajoStock = [];
$errores = [];

// ==========================================
// 3. INDICADORES DEL INVENTARIO
// ==========================================

try {
    $sql = "
        SELECT
            (SELECT COUNT(*) FROM productos WHERE estado=1) AS productos,
            (SELECT COALESCE(SUM(stock),0) FROM productos WHERE estado=1) AS unidades,
            (SELECT COUNT(*) FROM productos WHERE estado=1 AND stock BETWEEN 1 AND 5) AS stock_bajo,
            (SELECT COUNT(*) FROM productos WHERE estado=1 AND stock=0) AS agotados,
            (SELECT COUNT(*) FROM categorias WHERE estado=1) AS categorias,
            (SELECT COUNT(*) FROM proveedores WHERE estado=1) AS proveedores
    ";

    $datos = $conexion->query($sql)->fetch(PDO::FETCH_ASSOC);

    if ($datos) {
        foreach ($resumen as $campo => $valor) {
            $resumen[$campo] = (int)$datos[$campo];
        }
    }
} catch (Throwable $e) {
    error_log('Dashboard inventario: ' . $e->getMessage());
    $errores[] = 'No se pudo consultar el inventario.';
}

// ==========================================
// 4. RESUMEN DE VENTAS DEL DÍA
// ==========================================

try {
    $sql = "
        SELECT COUNT(*) AS cantidad,
               COALESCE(SUM(total),0) AS total
        FROM ventas
        WHERE DATE(fecha)=CURDATE()
        AND estado='REGISTRADA'
    ";

    $ventasHoy = $conexion->query($sql)->fetch(PDO::FETCH_ASSOC) ?: $ventasHoy;
} catch (Throwable $e) {
    error_log('Dashboard ventas: ' . $e->getMessage());
    $errores[] = 'No se pudo cargar el resumen de ventas.';
}

// ==========================================
// 5. ÚLTIMAS CINCO VENTAS
// ==========================================

try {
    $sql = "
        SELECT v.id_venta, v.fecha, v.total, v.estado,
               u.nombre_completo AS vendedor
        FROM ventas v
        INNER JOIN usuarios u ON v.id_usuario=u.id_usuario
        ORDER BY v.id_venta DESC
        LIMIT 5
    ";

    $ventasRecientes = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Dashboard historial: ' . $e->getMessage());
    $errores[] = 'No se pudieron consultar las últimas ventas.';
}

// ==========================================
// 6. PRODUCTOS CON STOCK BAJO
// ==========================================

try {
    $sql = "
        SELECT codigo, nombre, stock
        FROM productos
        WHERE estado=1 AND stock BETWEEN 1 AND 5
        ORDER BY stock ASC, nombre ASC
        LIMIT 5
    ";

    $productosBajoStock = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Dashboard stock: ' . $e->getMessage());
    $errores[] = 'No se pudo consultar el stock bajo.';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard | CheckPoint Store</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root {
    --fondo:#090A0D;
    --panel:#171923;
    --header:#20212D;
    --borde:#30313D;
    --rosa:#FF2E93;
    --muted:#A1A1AA;
    --verde:#20C997;
    --amarillo:#FFC107;
    --rojo:#FF5263;
}

body {
    background:var(--fondo);
    color:white;
    min-height:100vh;
}

a { text-decoration:none; color:inherit; }

.navbar {
    background:#050609;
    border-bottom:1px solid var(--borde);
}

.navbar-brand {
    color:white!important;
    font-weight:700;
}

.welcome-panel,.stat-card,.module-card,.content-card {
    background:var(--panel);
    border:1px solid var(--borde);
    border-radius:12px;
}

.welcome-panel {
    padding:28px;
    border-left:3px solid var(--rosa);
}

.eyebrow {
    color:var(--muted);
    font-size:11px;
    letter-spacing:1.5px;
    text-transform:uppercase;
}

.role-badge {
    color:var(--rosa);
    background:rgba(255,46,147,.10);
    border:1px solid rgba(255,46,147,.45);
    border-radius:20px;
    padding:7px 14px;
    font-size:12px;
}

.muted { color:var(--muted); }

.section-title {
    font-size:20px;
    font-weight:700;
    margin-bottom:18px;
}

.stat-card {
    padding:21px;
    height:100%;
    border-top:2px solid var(--rosa);
    transition:.2s;
}

.stat-card:hover,.module-card:hover {
    border-color:var(--rosa);
    transform:translateY(-2px);
}

.stat-label {
    color:var(--muted);
    font-size:13px;
    margin-bottom:12px;
}

.stat-value {
    font-size:30px;
    font-weight:750;
    margin:0;
}

.stat-note {
    color:var(--muted);
    font-size:11px;
    margin-top:9px;
}

.pink {color:var(--rosa);}
.green {color:var(--verde);}
.yellow {color:var(--amarillo);}
.red {color:var(--rojo);}

.module-card {
    display:block;
    padding:23px;
    height:100%;
    border-top:2px solid var(--rosa);
    transition:.2s;
}

.module-card:hover { color:white; }

.module-label {
    color:var(--muted);
    font-size:10px;
    letter-spacing:1.3px;
    text-transform:uppercase;
    margin-bottom:15px;
}

.module-card h3 {
    font-size:17px;
    font-weight:700;
}

.module-card p {
    color:var(--muted);
    font-size:12px;
    margin:10px 0 15px;
}

.module-action {
    color:var(--rosa);
    font-size:12px;
    font-weight:700;
}

.content-card { overflow:hidden; height:100%; }

.content-header {
    background:var(--header);
    border-bottom:1px solid var(--borde);
    padding:15px 18px;
    font-size:14px;
    font-weight:700;
}

.table-dashboard {
    --bs-table-bg:transparent;
    --bs-table-color:white;
    --bs-table-border-color:var(--borde);
    margin:0;
    font-size:12px;
    vertical-align:middle;
}

.table-dashboard thead th {
    color:var(--rosa);
    font-size:11px;
    white-space:nowrap;
}

.table-dashboard tbody td { color:#E5E7EB; }

.empty {
    text-align:center;
    padding:25px!important;
    color:var(--muted)!important;
}

.status-badge {
    padding:4px 8px;
    border-radius:20px;
    font-size:10px;
    font-weight:700;
}

.status-ok {
    color:var(--verde);
    background:rgba(32,201,151,.14);
}

.status-low {
    color:var(--amarillo);
    background:rgba(255,193,7,.14);
}

footer {
    color:var(--muted);
    font-size:11px;
}

@media(max-width:575px) {
    .welcome-panel {padding:20px;}
    .stat-value {font-size:26px;}
    .section-title {font-size:18px;}
}
</style>
</head>

<body>

<nav class="navbar navbar-dark">
    <div class="container-xl py-2">
        <a class="navbar-brand" href="dashboard.php">CheckPoint Store</a>
        <form method="POST" action="<?= dashEsc(appUrl('controladores/LogoutController.php')) ?>">
            <input type="hidden" name="csrf" value="<?= dashEsc($_SESSION['csrf_logout']) ?>">
            <button type="submit" class="btn btn-outline-light btn-sm">Cerrar sesión</button>
        </form>
    </div>
</nav>

<main class="container-xl py-4 py-lg-5">

<?= flashRender() ?>

<?php foreach ($errores as $error): ?>
    <div class="alert alert-warning"><?= dashEsc($error) ?></div>
<?php endforeach; ?>

<section class="welcome-panel mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="eyebrow mb-2">Panel principal · <?= date('d/m/Y') ?></div>
            <h1 class="h3 fw-bold mb-2">Bienvenido, <?= dashEsc($nombre) ?></h1>
            <p class="muted mb-0">Administra y consulta la información de CheckPoint Store.</p>
        </div>
        <span class="role-badge"><?= dashEsc($rol) ?></span>
    </div>
</section>

<section class="mb-5">
    <h2 class="section-title">Resumen del inventario</h2>
    <div class="row g-3">
        <?php
        $indicadores = [
            ['Productos activos',$resumen['productos'],'Productos registrados y habilitados','pink'],
            ['Unidades disponibles',$resumen['unidades'],'Existencias actuales','green'],
            ['Stock bajo',$resumen['stock_bajo'],'Productos con 1 a 5 unidades','yellow'],
            ['Productos agotados',$resumen['agotados'],'Productos sin existencias','red'],
            ['Categorías registradas',$resumen['categorias'],'Categorías activas','pink'],
            ['Proveedores registrados',$resumen['proveedores'],'Proveedores activos','pink']
        ];
        foreach ($indicadores as $item):
        ?>
        <div class="col-12 col-sm-6 col-xl-4">
            <article class="stat-card">
                <div class="stat-label"><?= dashEsc($item[0]) ?></div>
                <p class="stat-value <?= $item[3] ?>"><?= number_format((int)$item[1]) ?></p>
                <p class="stat-note"><?= dashEsc($item[2]) ?></p>
            </article>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-5">
    <h2 class="section-title">Resumen comercial del día</h2>
    <div class="row g-3">
        <div class="col-md-6">
            <article class="stat-card">
                <div class="stat-label">Ventas realizadas hoy</div>
                <p class="stat-value pink"><?= number_format((int)$ventasHoy['cantidad']) ?></p>
                <p class="stat-note">Operaciones registradas durante el día</p>
            </article>
        </div>
        <div class="col-md-6">
            <article class="stat-card">
                <div class="stat-label">Ingresos por ventas de hoy</div>
                <p class="stat-value green">Bs <?= number_format((float)$ventasHoy['total'],2) ?></p>
                <p class="stat-note">Total acumulado de ventas registradas</p>
            </article>
        </div>
    </div>
</section>

<section class="mb-5">
    <h2 class="section-title">Módulos del sistema</h2>
    <div class="row g-3">
        <?php
        $modulos = [];

        if ($esAdmin) {
            $modulos = [
                ['Categorías','Administración','Organizar las categorías de productos.','categorias/index.php'],
                ['Productos','Inventario','Registrar, consultar y actualizar productos.','productos/index.php'],
                ['Proveedores','Abastecimiento','Administrar proveedores y productos asociados.','proveedores/index.php'],
                ['Entradas','Inventario','Registrar ingresos de mercadería.','entradas/index.php']
            ];
        }

        $modulos[] = ['Ventas','Comercialización','Registrar operaciones de venta.','ventas/index.php'];

        foreach ($modulos as $m):
        ?>
        <div class="col-12 col-sm-6 col-xl-3">
            <a class="module-card" href="<?= dashEsc(appUrl($m[3])) ?>">
                <div class="module-label"><?= dashEsc($m[1]) ?></div>
                <h3><?= dashEsc($m[0]) ?></h3>
                <p><?= dashEsc($m[2]) ?></p>
                <span class="module-action">Acceder al módulo</span>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section>
    <h2 class="section-title">Seguimiento de operaciones e inventario</h2>
    <div class="row g-3">

        <div class="col-12 col-xl-7">
            <div class="content-card">
                <div class="content-header">Últimas ventas registradas</div>
                <div class="table-responsive">
                    <table class="table table-dashboard table-hover">
                        <thead>
                            <tr><th>N.º venta</th><th>Fecha</th><th>Vendedor</th><th>Total</th><th>Estado</th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$ventasRecientes): ?>
                            <tr><td colspan="5" class="empty">No hay ventas registradas.</td></tr>
                        <?php else: foreach ($ventasRecientes as $v): ?>
                            <tr>
                                <td>#<?= (int)$v['id_venta'] ?></td>
                                <td><?= dashEsc(date('d/m/Y H:i',strtotime($v['fecha']))) ?></td>
                                <td><?= dashEsc($v['vendedor']) ?></td>
                                <td>Bs <?= number_format((float)$v['total'],2) ?></td>
                                <td><?= dashEsc($v['estado']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="content-card">
                <div class="content-header">Productos con stock bajo</div>
                <div class="table-responsive">
                    <table class="table table-dashboard table-hover">
                        <thead><tr><th>Código</th><th>Producto</th><th>Stock</th></tr></thead>
                        <tbody>
                        <?php if (!$productosBajoStock): ?>
                            <tr><td colspan="3" class="empty">No hay productos con stock bajo.</td></tr>
                        <?php else: foreach ($productosBajoStock as $p): ?>
                            <tr>
                                <td><?= dashEsc($p['codigo']) ?></td>
                                <td><?= dashEsc($p['nombre']) ?></td>
                                <td class="yellow"><?= (int)$p['stock'] ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</section>

<footer class="text-center mt-5 py-3">
    CheckPoint Store © <?= date('Y') ?> · Sistema de inventario y ventas
</footer>

</main>
</body>
</html>
