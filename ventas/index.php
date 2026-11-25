
<?php
require_once __DIR__ . '/../config/auth.php';
requireRole('Administrador', 'Vendedor');
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['csrf_ventas'])) {
    $_SESSION['csrf_ventas'] = bin2hex(random_bytes(32));
}

function e($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$error = '';
$metodoSeleccionado = '';
$observacionIngresada = '';
$clienteIngresado = '';
$clienteNitIngresado = '';

// REGISTRO DE VENTA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) ||
        !hash_equals($_SESSION['csrf_ventas'], $_POST['csrf'])) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página.');
    }

    try {
        $idUsuario = (int)($_SESSION['usuario']['id_usuario'] ?? 0);
        $metodo = $_POST['metodo_pago'] ?? '';
        $observacion = $_POST['observacion'] ?? '';
        $clienteRaw = $_POST['cliente'] ?? '';
        $nitRaw = $_POST['cliente_nit'] ?? '';
        $ids = $_POST['id_producto'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];

        $metodoSeleccionado = is_string($metodo) ? $metodo : '';
        $observacionIngresada = is_string($observacion) ? $observacion : '';

        if (!is_string($clienteRaw) || !is_string($nitRaw)) {
            throw new InvalidArgumentException('Los datos del cliente no son válidos.');
        }

        $clienteIngresado = trim($clienteRaw);
        $clienteNitIngresado = trim($nitRaw);
        $cliente = $clienteIngresado !== '' ? $clienteIngresado : 'Consumidor final';

        $longitudCliente = function_exists('mb_strlen')
            ? mb_strlen($cliente, 'UTF-8') : strlen($cliente);

        if ($longitudCliente > 100 || strlen($clienteNitIngresado) > 20) {
            throw new InvalidArgumentException('El nombre o CI/NIT supera la longitud permitida.');
        }

        if ($idUsuario < 1 || !is_string($metodo) ||
            !in_array($metodo, ['Efectivo', 'QR', 'Tarjeta', 'Transferencia'], true) ||
            !is_string($observacion) || strlen($observacion) > 200) {
            throw new InvalidArgumentException('Verifica el método de pago y las observaciones.');
        }

        if (!is_array($ids) || !is_array($cantidades) || empty($ids) ||
            count($ids) > 50 || array_keys($ids) !== array_keys($cantidades)) {
            throw new InvalidArgumentException('El carrito está vacío o contiene datos inválidos.');
        }

        $detalles = [];
        $vistos = [];

        foreach ($ids as $i => $idOriginal) {
            if (!is_scalar($idOriginal) || !is_scalar($cantidades[$i])) {
                throw new InvalidArgumentException('Los productos o cantidades no son válidos.');
            }

            $id = filter_var((string)$idOriginal, FILTER_VALIDATE_INT);
            $cantidad = filter_var((string)$cantidades[$i], FILTER_VALIDATE_INT);

            if ($id === false || $id < 1 || $cantidad === false ||
                $cantidad < 1 || isset($vistos[$id])) {
                throw new InvalidArgumentException('Revisa los productos y las cantidades.');
            }

            $vistos[$id] = true;
            $detalles[] = ['id' => $id, 'cantidad' => $cantidad];
        }

        $conexion->beginTransaction();

        // Comprobar stock y precios
        $buscar = $conexion->prepare(
            "SELECT nombre, precio_venta, stock
             FROM productos
             WHERE id_producto = ? AND estado = 1
             FOR UPDATE"
        );

        $total = 0.0;

        foreach ($detalles as &$d) {
            $buscar->execute([$d['id']]);
            $producto = $buscar->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                throw new InvalidArgumentException('Uno de los productos ya no está disponible.');
            }

            if ($d['cantidad'] > (int)$producto['stock']) {
                throw new InvalidArgumentException(
                    'Stock insuficiente para ' . $producto['nombre'] .
                    '. Disponible: ' . $producto['stock']
                );
            }

            $d['precio'] = (float)$producto['precio_venta'];

            if (!is_finite($d['precio']) || $d['precio'] < 0) {
                throw new InvalidArgumentException('El precio de un producto no es válido.');
            }

            $d['subtotal'] = round($d['cantidad'] * $d['precio'], 2);
            $total = round($total + $d['subtotal'], 2);
        }

        unset($d);

        // Registrar cabecera de venta
        $insertVenta = $conexion->prepare(
            "INSERT INTO ventas
             (id_usuario, cliente, cliente_nit, fecha, total,
              metodo_pago, observacion, estado)
             VALUES (?, ?, ?, NOW(), ?, ?, ?, 'REGISTRADA')"
        );

        $insertVenta->execute([
            $idUsuario,
            $cliente,
            $clienteNitIngresado !== '' ? $clienteNitIngresado : null,
            $total,
            $metodo,
            trim($observacion)
        ]);

        $idVenta = (int)$conexion->lastInsertId();

        // Registrar detalle
        $insertDetalle = $conexion->prepare(
            "INSERT INTO detalle_venta
             (id_venta, id_producto, cantidad, precio_unitario, subtotal)
             VALUES (?, ?, ?, ?, ?)"
        );

        // Descontar stock
        $actualizarStock = $conexion->prepare(
            "UPDATE productos SET stock = stock - ?
             WHERE id_producto = ? AND estado = 1 AND stock >= ?"
        );

        // Registrar movimiento de inventario
        $insertMovimiento = $conexion->prepare(
            "INSERT INTO movimientos
             (id_producto, id_usuario, tipo_movimiento,
              cantidad, fecha_hora, descripcion)
             VALUES (?, ?, 'SALIDA', ?, NOW(), ?)"
        );

        foreach ($detalles as $d) {
            $actualizarStock->execute([
                $d['cantidad'], $d['id'], $d['cantidad']
            ]);

            if ($actualizarStock->rowCount() !== 1) {
                throw new RuntimeException('No se pudo actualizar el stock de uno de los productos.');
            }

            $insertDetalle->execute([
                $idVenta,
                $d['id'],
                $d['cantidad'],
                $d['precio'],
                $d['subtotal']
            ]);

            $insertMovimiento->execute([
                $d['id'],
                $idUsuario,
                $d['cantidad'],
                'Venta #' . $idVenta
            ]);
        }

        // Confirmar operación
        $conexion->commit();

        $_SESSION['csrf_ventas'] = bin2hex(random_bytes(32));

        if (function_exists('flashSet')) {
            flashSet('success', 'Venta #' . $idVenta . ' registrada correctamente.');
        }

        header('Location: index.php');
        exit;

    } catch (InvalidArgumentException $ex) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        $error = $ex->getMessage();

    } catch (Throwable $ex) {

    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $error = 'ERROR MYSQL: ' . $ex->getMessage();

    error_log($ex->getMessage());
}
}

// CONSULTAR PRODUCTOS
$productos = $conexion->query(
    "SELECT p.id_producto, p.codigo, p.nombre,
            p.precio_venta, p.stock,
            c.nombre AS categoria
     FROM productos p
     LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
     WHERE p.estado = 1
     ORDER BY p.nombre"
)->fetchAll(PDO::FETCH_ASSOC);

// RESUMEN DEL DÍA
$resumen = $conexion->query(
    "SELECT COUNT(*) AS cantidad,
            COALESCE(SUM(total), 0) AS total
     FROM ventas
     WHERE DATE(fecha) = CURDATE()
     AND estado = 'REGISTRADA'"
)->fetch(PDO::FETCH_ASSOC);

// HISTORIAL
$historial = $conexion->query(
    "SELECT v.*, u.nombre_completo
     FROM ventas v
     INNER JOIN usuarios u ON u.id_usuario = v.id_usuario
     ORDER BY v.id_venta DESC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CheckPoint Store | Ventas</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root{
    --fondo:#090A0D;
    --panel:#171923;
    --panel2:#20212D;
    --borde:#30313D;
    --rosa:#FF2E93;
    --texto2:#A1A1AA;
}

*{box-sizing:border-box}

body{background:var(--fondo);color:#fff}

.navbar{
    background:#050609;
    border-bottom:1px solid var(--borde);
}

.card{
    background:var(--panel);
    color:#fff;
    border:1px solid var(--borde);
    border-radius:11px;
}

.card-header{
    background:var(--panel2);
    color:#fff;
    border-bottom:1px solid var(--borde);
}

.form-control,.form-select{
    background:var(--fondo);
    color:#fff;
    border-color:#444451;
    min-width:0;
}

.form-control:focus,.form-select:focus{
    background:var(--fondo);
    color:#fff;
    border-color:var(--rosa);
    box-shadow:0 0 0 .15rem rgba(255,46,147,.12);
}

.form-control::placeholder{color:#777987}

.form-select option{
    background:var(--panel);
    color:#fff;
}

.btn-pink{
    background:var(--rosa);
    color:#fff;
    border:0;
}

.btn-pink:hover{
    background:#E91E83;
    color:#fff;
}

.btn-pink:disabled,.agregar:disabled{
    background:#555565;
    color:#bbb;
    cursor:not-allowed;
    opacity:.8;
}

.table{
    --bs-table-bg:var(--panel);
    --bs-table-color:#fff;
    --bs-table-border-color:var(--borde);
}

.table thead th{
    color:var(--rosa);
    white-space:nowrap;
}

.total{color:var(--rosa)}
.muted{color:var(--texto2)}

.summary{
    padding:18px;
    border-top:2px solid var(--rosa);
}

.summary-label{
    font-size:13px;
    color:var(--texto2);
}

.summary-value{
    font-size:27px;
    font-weight:700;
    margin:6px 0 0;
}

.producto{
    height:100%;
    background:var(--panel2);
    border:1px solid var(--borde);
    border-radius:10px;
    min-width:0;
}

.producto:hover{border-color:var(--rosa)}

.producto-nombre{
    font-size:14px;
    font-weight:700;
    overflow-wrap:anywhere;
}

.detalle{
    font-size:11px;
    color:var(--texto2);
}

/* CARRITO */
.item-carrito{
    display:grid;
    grid-template-columns:minmax(0,1fr) 112px;
    gap:10px;
    align-items:center;
    background:var(--panel2);
    border:1px solid var(--borde);
    border-radius:10px;
    padding:12px;
    min-width:0;
    width:100%;
}

.item-info{
    min-width:0;
    overflow-wrap:anywhere;
}

.item-info strong{
    display:block;
    font-size:13px;
    line-height:1.35;
    overflow-wrap:anywhere;
}

.item-info small{
    display:block;
    color:var(--texto2);
    font-size:11px;
    margin-top:4px;
}

.item-controles{
    display:flex;
    flex-direction:column;
    gap:6px;
    min-width:0;
    width:100%;
}

.item-controles label{
    font-size:10px;
    color:var(--texto2);
    margin:0;
}

.item-controles input{
    width:100%;
    min-width:0;
    text-align:center;
    padding:5px;
}

.item-subtotal{
    font-size:12px;
    font-weight:700;
    text-align:right;
    overflow-wrap:anywhere;
}

.btn-quitar{
    display:block;
    width:100%;
    max-width:100%;
    padding:5px 6px;
    font-size:12px;
    white-space:nowrap;
}

#carrito{
    width:100%;
    min-width:0;
}

#camposOcultos{display:none}

#sinResultados{
    padding:20px;
    text-align:center;
    color:var(--texto2);
}

@media(max-width:420px){
    .item-carrito{
        grid-template-columns:minmax(0,1fr) 94px;
        gap:7px;
        padding:9px;
    }

    .item-info strong{font-size:12px}

    .btn-quitar{
        font-size:11px;
        padding:5px 3px;
    }

    .summary-value{font-size:23px}
}
</style>
</head>

<body>

<nav class="navbar navbar-dark">
    <div class="container py-2">
        <a href="../dashboard.php" class="navbar-brand fw-bold">
            CheckPoint Store
        </a>
        <span class="navbar-text">Punto de venta</span>
    </div>
</nav>

<main class="container py-4">

    <div class="mb-4">
        <h2 class="fw-bold">Gestión de ventas</h2>
        <p class="muted">Registro y control de operaciones comerciales.</p>
        <a href="../dashboard.php" class="btn btn-outline-light btn-sm">
            Volver al panel
        </a>
    </div>

    <?= function_exists('flashRender') ? flashRender() : '' ?>

    <?php if ($error): ?>
        <div class="alert alert-warning"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- RESUMEN -->
    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="card summary h-100">
                <div class="summary-label">Ventas realizadas hoy</div>
                <div class="summary-value">
                    <?= (int)$resumen['cantidad'] ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card summary h-100">
                <div class="summary-label">Ingresos del día</div>
                <div class="summary-value total">
                    Bs <?= number_format((float)$resumen['total'],2) ?>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3">

        <!-- PRODUCTOS -->
        <section class="col-lg-7">

            <div class="card h-100">

                <div class="card-header">
                    <h5 class="mb-0">Selección de productos</h5>
                </div>

                <div class="card-body">

                    <div class="input-group mb-3">
                        <input type="search"
                               id="buscarProducto"
                               class="form-control"
                               placeholder="Buscar por código, nombre o categoría..."
                               autocomplete="off">

                        <button type="button"
                                id="limpiarBusqueda"
                                class="btn btn-outline-secondary">
                            Limpiar
                        </button>
                    </div>

                    <div class="d-flex justify-content-between mb-3">
                        <small class="muted">Catálogo disponible</small>
                        <small class="muted" id="contadorProductos">
                            <?= count($productos) ?> productos
                        </small>
                    </div>

                    <div class="row g-3" id="listaProductos">

                        <?php foreach ($productos as $p): ?>

                            <div class="col-md-6 producto-item"
                                 data-busqueda="<?= e($p['codigo'].' '.$p['nombre'].' '.($p['categoria'] ?? '')) ?>">

                                <article class="producto p-3">

                                    <div class="d-flex justify-content-between gap-2 mb-2">

                                        <span class="detalle">
                                            <?= e($p['codigo']) ?>
                                        </span>

                                        <?php if ((int)$p['stock'] === 0): ?>
                                            <span class="badge bg-danger">Agotado</span>
                                        <?php elseif ((int)$p['stock'] <= 5): ?>
                                            <span class="badge bg-warning text-dark">Stock bajo</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Disponible</span>
                                        <?php endif; ?>

                                    </div>

                                    <div class="producto-nombre mb-1">
                                        <?= e($p['nombre']) ?>
                                    </div>

                                    <div class="detalle">
                                        <?= e($p['categoria'] ?? 'Sin categoría') ?>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center gap-2 mt-3">

                                        <div>
                                            <strong class="total">
                                                Bs <?= number_format((float)$p['precio_venta'],2) ?>
                                            </strong>

                                            <div class="detalle">
                                                <?= (int)$p['stock'] ?> unidades
                                            </div>
                                        </div>

                                        <button type="button"
                                                class="btn btn-pink btn-sm agregar"
                                                data-id="<?= (int)$p['id_producto'] ?>"
                                                data-nombre="<?= e($p['nombre']) ?>"
                                                data-codigo="<?= e($p['codigo']) ?>"
                                                data-precio="<?= e($p['precio_venta']) ?>"
                                                data-stock="<?= (int)$p['stock'] ?>"
                                                <?= (int)$p['stock'] < 1 ? 'disabled' : '' ?>>
                                            Agregar
                                        </button>

                                    </div>
                                </article>
                            </div>

                        <?php endforeach; ?>

                    </div>

                    <div id="sinResultados" hidden>
                        No se encontraron productos coincidentes.
                    </div>

                    <?php if (!$productos): ?>
                        <div class="text-center muted py-4">
                            No hay productos disponibles.
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </section>

        <!-- CARRITO -->
        <section class="col-lg-5">

            <div class="card">

                <div class="card-header d-flex justify-content-between align-items-center gap-2">

                    <h5 class="mb-0">Carrito de venta</h5>

                    <button type="button"
                            id="vaciarCarrito"
                            class="btn btn-outline-danger btn-sm"
                            disabled>
                        Vaciar carrito
                    </button>

                </div>

                <div class="card-body">

                    <div class="d-flex justify-content-between mb-3">
                        <small class="muted">
                            Productos: <strong id="cantidadProductos">0</strong>
                        </small>

                        <small class="muted">
                            Unidades: <strong id="cantidadUnidades">0</strong>
                        </small>
                    </div>

                    <form method="POST" id="formVenta">

                        <input type="hidden"
                               name="csrf"
                               value="<?= e($_SESSION['csrf_ventas']) ?>">

                        <div id="carrito" class="d-flex flex-column gap-2">
                            <div class="text-center muted py-4">
                                No hay productos agregados.
                            </div>
                        </div>

                        <div id="camposOcultos"></div>

                        <!-- CLIENTE -->
                        <div class="row g-3 mt-1 mb-3">

                            <div class="col-md-7">
                                <label class="form-label">Nombre del cliente</label>

                                <input type="text"
                                       name="cliente"
                                       class="form-control"
                                       maxlength="100"
                                       placeholder="Consumidor final"
                                       value="<?= e($clienteIngresado) ?>">
                            </div>

                            <div class="col-md-5">
                                <label class="form-label">CI / NIT</label>

                                <input type="text"
                                       name="cliente_nit"
                                       class="form-control"
                                       maxlength="20"
                                       placeholder="Documento del cliente"
                                       value="<?= e($clienteNitIngresado) ?>">
                            </div>

                        </div>

                        <!-- PAGO -->
                        <div class="mt-3">

                            <label class="form-label">Método de pago</label>

                            <select name="metodo_pago"
                                    id="metodoPago"
                                    class="form-select"
                                    required>

                                <option value="">Seleccionar método</option>

                                <?php foreach (['Efectivo','QR','Tarjeta','Transferencia'] as $m): ?>

                                    <option value="<?= e($m) ?>"
                                        <?= $metodoSeleccionado === $m ? 'selected' : '' ?>>
                                        <?= e($m) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <!-- OBSERVACIONES -->
                        <div class="mt-3">

                            <label class="form-label">Observaciones</label>

                            <textarea name="observacion"
                                      class="form-control"
                                      rows="2"
                                      maxlength="200"><?= e($observacionIngresada) ?></textarea>

                        </div>

                        <!-- TOTAL -->
                        <div class="border-top border-secondary mt-3 pt-3 text-end">

                            <span class="muted">TOTAL A PAGAR</span>

                            <h2 class="fw-bold total" id="total">
                                Bs 0.00
                            </h2>

                        </div>

                        <button type="submit"
                                id="registrar"
                                class="btn btn-pink w-100 mt-2"
                                disabled>
                            Registrar venta
                        </button>

                        <p id="avisoCarrito"
                           class="muted small text-center mt-2 mb-0">
                            Agrega productos y selecciona un método de pago.
                        </p>

                    </form>
                </div>
            </div>
        </section>

    </div>

    <!-- HISTORIAL -->
    <section class="card mt-4">

        <div class="card-header">
            <h5 class="mb-0">Últimas ventas registradas</h5>
        </div>

        <div class="card-body table-responsive">

            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>N.º</th>
                        <th>Fecha</th>
                        <th>Responsable</th>
                        <th>Pago</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>Comprobante</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($historial as $v): ?>

                        <tr>
                            <td>#<?= (int)$v['id_venta'] ?></td>
                            <td><?= e($v['fecha']) ?></td>
                            <td><?= e($v['nombre_completo']) ?></td>
                            <td><?= e($v['metodo_pago']) ?></td>
                            <td>Bs <?= number_format((float)$v['total'],2) ?></td>

                            <td>
                                <span class="badge bg-success">
                                    <?= e($v['estado']) ?>
                                </span>
                            </td>

                            <td>
                                <a href="factura.php?id=<?= (int)$v['id_venta'] ?>"
                                   target="_blank"
                                   rel="noopener"
                                   class="btn btn-sm btn-outline-info">
                                    Ver comprobante
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$historial): ?>
                        <tr>
                            <td colspan="7" class="text-center muted">
                                Todavía no hay ventas registradas.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>
            </table>
        </div>
    </section>

    <footer class="text-center text-secondary mt-4">
        CheckPoint Store © 2026
    </footer>

</main>

<script>
const carrito = [];
const contenedorCarrito = document.getElementById('carrito');
const ocultos = document.getElementById('camposOcultos');
const botonRegistrar = document.getElementById('registrar');
const metodoPago = document.getElementById('metodoPago');

let procesando = false;

const dinero = n => 'Bs ' + Number(n).toFixed(2);

const normalizar = t =>
    String(t).toLocaleLowerCase('es')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g,'');

// ACTUALIZAR ESTADO
function actualizarEstado() {

    const valido = carrito.length > 0 &&
        carrito.every(p =>
            Number.isInteger(p.cantidad) &&
            p.cantidad >= 1 &&
            p.cantidad <= p.stock
        );

    botonRegistrar.disabled =
        procesando || !valido || !metodoPago.value;

    document.getElementById('avisoCarrito').textContent =
        procesando ? 'Registrando venta...' :
        !carrito.length ? 'Agrega productos para continuar.' :
        !metodoPago.value ? 'Selecciona un método de pago.' :
        !valido ? 'Revisa las cantidades ingresadas.' :
        'Venta lista para registrar.';

    document.getElementById('vaciarCarrito').disabled =
        !carrito.length || procesando;

    document.querySelectorAll('.agregar').forEach(btn => {

        const p = carrito.find(x =>
            x.id === Number(btn.dataset.id)
        );

        btn.disabled = procesando ||
            Number(btn.dataset.stock) < 1 ||
            (p && p.cantidad >= p.stock);

        btn.textContent = p
            ? 'Agregado (' + p.cantidad + ')'
            : 'Agregar';
    });
}

// MOSTRAR CARRITO
function renderCarrito() {

    contenedorCarrito.replaceChildren();
    ocultos.replaceChildren();

    let total = 0;
    let unidades = 0;

    carrito.forEach(p => {

        const subtotal = p.precio * p.cantidad;

        total += subtotal;
        unidades += p.cantidad;

        const item = document.createElement('div');
        item.className = 'item-carrito';

        const info = document.createElement('div');
        info.className = 'item-info';

        const nombre = document.createElement('strong');
        nombre.textContent = p.nombre;

        const codigo = document.createElement('small');
        codigo.textContent =
            p.codigo + ' · ' + dinero(p.precio) + ' por unidad';

        info.append(nombre,codigo);

        const controles = document.createElement('div');
        controles.className = 'item-controles';

        const label = document.createElement('label');
        label.textContent = 'Cantidad';

        const input = document.createElement('input');

        Object.assign(input,{
            type:'number',
            min:1,
            max:p.stock,
            step:1,
            required:true,
            value:p.cantidad
        });

        input.className = 'form-control form-control-sm cantidad';
        input.dataset.id = p.id;

        const sub = document.createElement('div');
        sub.className = 'item-subtotal';
        sub.textContent = dinero(subtotal);

        const quitar = document.createElement('button');

        quitar.type = 'button';
        quitar.className =
            'btn btn-outline-danger btn-sm btn-quitar eliminar';

        quitar.dataset.id = p.id;
        quitar.textContent = 'Quitar';

        controles.append(label,input,sub,quitar);

        item.append(info,controles);
        contenedorCarrito.append(item);

        [
            ['id_producto[]',p.id],
            ['cantidad[]',p.cantidad]
        ].forEach(([name,value]) => {

            const hidden = document.createElement('input');

            hidden.type = 'hidden';
            hidden.name = name;
            hidden.value = value;

            ocultos.append(hidden);
        });
    });

    if (!carrito.length) {

        const empty = document.createElement('div');

        empty.className = 'text-center muted py-4';
        empty.textContent = 'No hay productos agregados.';

        contenedorCarrito.append(empty);
    }

    document.getElementById('total').textContent = dinero(total);

    document.getElementById('cantidadProductos').textContent =
        carrito.length;

    document.getElementById('cantidadUnidades').textContent =
        unidades;

    actualizarEstado();
}

// AGREGAR PRODUCTOS
document.querySelectorAll('.agregar').forEach(btn => {

    btn.addEventListener('click',() => {

        const id = Number(btn.dataset.id);
        const stock = Number(btn.dataset.stock);

        const p = carrito.find(x => x.id === id);

        if (stock < 1 || (p && p.cantidad >= p.stock)) {
            alert('No hay suficientes existencias.');
            return;
        }

        if (p) {
            p.cantidad++;
        } else {

            carrito.push({
                id,
                nombre:btn.dataset.nombre,
                codigo:btn.dataset.codigo,
                precio:Number(btn.dataset.precio),
                stock,
                cantidad:1
            });
        }

        renderCarrito();
    });
});

// MODIFICAR CANTIDADES
contenedorCarrito.addEventListener('change',e => {

    if (!e.target.classList.contains('cantidad')) return;

    const p = carrito.find(x =>
        x.id === Number(e.target.dataset.id)
    );

    if (!p) return;

    let n = Number(e.target.value);

    if (!Number.isInteger(n) || n < 1) n = 1;

    if (n > p.stock) {
        n = p.stock;
        alert('La cantidad supera las existencias disponibles.');
    }

    p.cantidad = n;

    renderCarrito();
});

// QUITAR PRODUCTOS
contenedorCarrito.addEventListener('click',e => {

    const btn = e.target.closest('.eliminar');

    if (!btn) return;

    const i = carrito.findIndex(x =>
        x.id === Number(btn.dataset.id)
    );

    if (i !== -1) carrito.splice(i,1);

    renderCarrito();
});

// VACIAR CARRITO
document.getElementById('vaciarCarrito').addEventListener('click',() => {

    if (!carrito.length ||
        !confirm('¿Deseas vaciar el carrito?')) return;

    carrito.length = 0;

    renderCarrito();
});

// MÉTODO DE PAGO
metodoPago.addEventListener('change',actualizarEstado);

// BÚSQUEDA
const buscador = document.getElementById('buscarProducto');

function filtrarProductos() {

    const texto = normalizar(buscador.value);

    let cantidad = 0;

    document.querySelectorAll('.producto-item').forEach(item => {

        const coincide =
            normalizar(item.dataset.busqueda).includes(texto);

        item.hidden = !coincide;

        if (coincide) cantidad++;
    });

    document.getElementById('contadorProductos').textContent =
        cantidad + (cantidad === 1 ? ' producto' : ' productos');

    document.getElementById('sinResultados').hidden =
        cantidad > 0;
}

buscador.addEventListener('input',filtrarProductos);

document.getElementById('limpiarBusqueda').addEventListener('click',() => {

    buscador.value = '';

    filtrarProductos();

    buscador.focus();
});

// VALIDAR REGISTRO
document.getElementById('formVenta').addEventListener('submit',e => {

    if (procesando || !carrito.length || !metodoPago.value) {
        e.preventDefault();
        return;
    }

    const valido = carrito.every(p =>
        Number.isInteger(p.cantidad) &&
        p.cantidad >= 1 &&
        p.cantidad <= p.stock
    );

    if (!valido) {
        e.preventDefault();
        alert('Revisa las cantidades y el stock disponible.');
        return;
    }

    if (!confirm('¿Confirmar esta venta?')) {
        e.preventDefault();
        return;
    }

    procesando = true;

    botonRegistrar.textContent = 'Registrando venta...';

    actualizarEstado();
});

renderCarrito();
filtrarProductos();
</script>

</body>
</html>
