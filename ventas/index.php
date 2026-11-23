
<?php
require_once __DIR__ . '/../config/auth.php';
requireRole('Administrador', 'Vendedor');
require_once __DIR__ . '/../config/conexion.php';

// ==========================================
// SEGURIDAD Y FUNCIONES
// ==========================================

if (empty($_SESSION['csrf_ventas'])) {
    $_SESSION['csrf_ventas'] = bin2hex(random_bytes(32));
}

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$error = '';
$metodoSeleccionado = '';
$observacionIngresada = '';

// ==========================================
// REGISTRAR VENTA
// ==========================================

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
        $ids = $_POST['id_producto'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];

        $metodoSeleccionado = is_string($metodo) ? $metodo : '';
        $observacionIngresada = is_string($observacion) ? $observacion : '';

        // Validar datos
        if ($idUsuario < 1 ||
            !is_string($metodo) ||
            !in_array($metodo, ['Efectivo','QR','Tarjeta','Transferencia'], true) ||
            !is_string($observacion)) {
            throw new InvalidArgumentException('Verifica los datos de la venta.');
        }

        if (!is_array($ids) || !is_array($cantidades) ||
            empty($ids) || count($ids) > 50 ||
            array_keys($ids) !== array_keys($cantidades)) {
            throw new InvalidArgumentException('El carrito está vacío o contiene datos inválidos.');
        }

        // Validar productos y cantidades
        $detalles = [];
        $vistos = [];

        foreach ($ids as $i => $idOriginal) {
            if (!is_scalar($idOriginal) || !is_scalar($cantidades[$i])) {
                throw new InvalidArgumentException('Datos de productos inválidos.');
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

        // Verificar stock y precios directamente en MySQL
        $conexion->beginTransaction();

        $buscar = $conexion->prepare(
            "SELECT nombre, precio_venta, stock
             FROM productos
             WHERE id_producto = ? AND estado = 1
             FOR UPDATE"
        );

        $total = 0;

        foreach ($detalles as &$d) {
            $buscar->execute([$d['id']]);
            $p = $buscar->fetch(PDO::FETCH_ASSOC);

            if (!$p) {
                throw new InvalidArgumentException('Producto no disponible.');
            }

            if ($d['cantidad'] > (int)$p['stock']) {
                throw new InvalidArgumentException(
                    'Stock insuficiente para ' . $p['nombre'] .
                    '. Disponible: ' . $p['stock']
                );
            }

            $d['precio'] = (float)$p['precio_venta'];

            if (!is_finite($d['precio']) || $d['precio'] < 0) {
                throw new InvalidArgumentException('Precio de producto inválido.');
            }

            $d['subtotal'] = round($d['cantidad'] * $d['precio'], 2);
            $total = round($total + $d['subtotal'], 2);

            if ($total > 9999999999.99) {
                throw new InvalidArgumentException('El total supera el límite permitido.');
            }
        }
        unset($d);

        // Registrar cabecera de venta
        $stmt = $conexion->prepare(
            "INSERT INTO ventas
             (id_usuario, fecha, total, metodo_pago, observacion, estado)
             VALUES (?, NOW(), ?, ?, ?, 'REGISTRADA')"
        );

        $stmt->execute([
            $idUsuario, $total, $metodo, trim($observacion)
        ]);

        $idVenta = $conexion->lastInsertId();

        // Preparar detalles, stock y movimientos
        $detalleSQL = $conexion->prepare(
            "INSERT INTO detalle_venta
             (id_venta, id_producto, cantidad, precio_unitario, subtotal)
             VALUES (?, ?, ?, ?, ?)"
        );

        $stockSQL = $conexion->prepare(
            "UPDATE productos SET stock = stock - ?
             WHERE id_producto = ? AND estado = 1 AND stock >= ?"
        );

        $movimientoSQL = $conexion->prepare(
            "INSERT INTO movimientos
             (id_producto, id_usuario, tipo_movimiento, cantidad, fecha_hora, descripcion)
             VALUES (?, ?, 'SALIDA', ?, NOW(), ?)"
        );

        // Procesar cada producto
        foreach ($detalles as $d) {

            $stockSQL->execute([
                $d['cantidad'], $d['id'], $d['cantidad']
            ]);

            if ($stockSQL->rowCount() !== 1) {
                throw new InvalidArgumentException('No se pudo actualizar el inventario.');
            }

            $detalleSQL->execute([
                $idVenta, $d['id'], $d['cantidad'],
                $d['precio'], $d['subtotal']
            ]);

            $movimientoSQL->execute([
                $d['id'], $idUsuario, $d['cantidad'], "Venta #$idVenta"
            ]);
        }

        // Confirmar y renovar token
        $conexion->commit();
        $_SESSION['csrf_ventas'] = bin2hex(random_bytes(32));

        header('Location: index.php?ok=' . $idVenta);
        exit;

    } catch (InvalidArgumentException $ex) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        $error = $ex->getMessage();

    } catch (Throwable $ex) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        error_log('Error en ventas: ' . $ex->getMessage());
        $error = 'No se pudo registrar la venta. Inténtalo nuevamente.';
    }
}

// ==========================================
// CONSULTAR PRODUCTOS, RESUMEN E HISTORIAL
// ==========================================

$productos = $conexion->query(
    "SELECT id_producto, codigo, nombre, precio_venta, stock
     FROM productos WHERE estado = 1 ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$resumen = $conexion->query(
    "SELECT COUNT(*) AS cantidad, COALESCE(SUM(total),0) AS total
     FROM ventas
     WHERE DATE(fecha) = CURDATE() AND estado = 'REGISTRADA'"
)->fetch(PDO::FETCH_ASSOC);

$historial = $conexion->query(
    "SELECT v.*, u.nombre_completo
     FROM ventas v
     INNER JOIN usuarios u ON v.id_usuario = u.id_usuario
     ORDER BY v.id_venta DESC LIMIT 10"
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
body { background:#090A0D; color:white; }
.card { background:#171923; color:white; border:1px solid #30313D; }
.card-header { background:#20212D; color:white; }
.form-control,.form-select { background:#090A0D; color:white; border-color:#444451; }
.form-control:focus,.form-select:focus { background:#090A0D; color:white; border-color:#FF2E93; box-shadow:none; }
.form-select option { background:#171923; color:white; }
.btn-pink { background:#FF2E93; color:white; border:0; }
.btn-pink:hover { background:#E91E83; color:white; }
.btn-pink:disabled,.agregar:disabled { background:#555565; color:#BBBBBB; cursor:not-allowed; opacity:.8; }
.table { --bs-table-bg:#171923; --bs-table-color:white; --bs-table-border-color:#30313D; }
.table thead th { color:#FF2E93; white-space:nowrap; }
.producto,.item-carrito { background:#20212D; border:1px solid #30313D; border-radius:12px; }
.producto:hover { border-color:#FF2E93; }
.total { color:#FF2E93; }
</style>
</head>

<body>

<!-- NAVEGACIÓN -->
<nav class="navbar navbar-dark bg-black border-bottom border-danger">
    <div class="container">
        <a href="../dashboard.php" class="navbar-brand fw-bold">CheckPoint Store</a>
        <span class="navbar-text">Punto de venta</span>
    </div>
</nav>

<div class="container py-4">

    <!-- ENCABEZADO -->
    <div class="mb-4">
        <h2 class="fw-bold">Ventas</h2>
        <p class="text-secondary">Registro y control de ventas.</p>
        <a href="../dashboard.php" class="btn btn-outline-light btn-sm">Volver al panel</a>
    </div>

    <!-- MENSAJES -->
    <?php if (isset($_GET['ok']) && ctype_digit((string)$_GET['ok']) && (int)$_GET['ok'] > 0): ?>
        <div class="alert alert-success">
            Venta #<?= (int)$_GET['ok'] ?> registrada correctamente.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-warning"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- RESUMEN DEL DÍA -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card p-3">
                <span class="text-secondary">Ventas de hoy</span>
                <h2><?= (int)$resumen['cantidad'] ?></h2>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card p-3">
                <span class="text-secondary">Ingresos de hoy</span>
                <h2 class="total">Bs <?= number_format((float)$resumen['total'],2) ?></h2>
            </div>
        </div>
    </div>

    <div class="row g-3">

        <!-- CATÁLOGO -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Seleccionar productos</h5>
                </div>

                <div class="card-body">
                    <input type="search" id="buscarProducto" class="form-control mb-3"
                           placeholder="Buscar por nombre o código...">

                    <div class="row g-3" id="listaProductos">
                        <?php foreach ($productos as $p): ?>
                            <div class="col-md-6 producto-item"
                                 data-busqueda="<?= e(strtolower($p['codigo'].' '.$p['nombre'])) ?>">

                                <div class="producto p-3 h-100">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-secondary small"><?= e($p['codigo']) ?></span>
                                        <span class="badge <?= $p['stock'] > 5 ? 'bg-success' : ($p['stock'] > 0 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                            Stock: <?= (int)$p['stock'] ?>
                                        </span>
                                    </div>

                                    <h6 class="fw-bold"><?= e($p['nombre']) ?></h6>

                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <strong class="total">Bs <?= number_format((float)$p['precio_venta'],2) ?></strong>

                                        <button type="button" class="btn btn-pink btn-sm agregar"
                                            data-id="<?= (int)$p['id_producto'] ?>"
                                            data-nombre="<?= e($p['nombre']) ?>"
                                            data-precio="<?= e($p['precio_venta']) ?>"
                                            data-stock="<?= (int)$p['stock'] ?>"
                                            <?= $p['stock'] < 1 ? 'disabled' : '' ?>>
                                            + Agregar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (!$productos): ?>
                            <div class="col-12 text-center text-secondary">No hay productos disponibles.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- CARRITO -->
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Carrito de venta</h5>
                </div>

                <div class="card-body">
                    <form method="POST" id="formVenta">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_ventas']) ?>">

                        <div id="carrito" class="d-flex flex-column gap-2"></div>
                        <div id="camposOcultos"></div>

                        <div class="mt-3">
                            <label class="form-label">Método de pago</label>
                            <select name="metodo_pago" id="metodoPago" class="form-select" required>
                                <option value="">Seleccionar método</option>
                                <?php foreach (['Efectivo','QR','Tarjeta','Transferencia'] as $m): ?>
                                    <option value="<?= e($m) ?>" <?= $metodoSeleccionado === $m ? 'selected' : '' ?>>
                                        <?= e($m) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Observaciones</label>
                            <textarea name="observacion" class="form-control" rows="2"><?= e($observacionIngresada) ?></textarea>
                        </div>

                        <div class="border-top border-secondary mt-3 pt-3 text-end">
                            <span class="text-secondary">TOTAL A PAGAR</span>
                            <h2 class="fw-bold total" id="total">Bs 0.00</h2>
                        </div>

                        <button type="submit" id="registrar" class="btn btn-pink w-100 mt-2" disabled>
                            Registrar venta
                        </button>

                        <p id="avisoCarrito" class="text-secondary small text-center mt-2 mb-0">
                            Agrega productos y selecciona un método de pago.
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- HISTORIAL -->
    <div class="card mt-4">
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
                            <td><span class="badge bg-success"><?= e($v['estado']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$historial): ?>
                        <tr><td colspan="6" class="text-center text-secondary">Todavía no hay ventas registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <footer class="text-center text-secondary mt-4">
        CheckPoint Store © 2026
    </footer>
</div>

<!-- FUNCIONES DEL CARRITO -->
<script>
const carrito = [];
const tabla = document.getElementById('carrito');
const ocultos = document.getElementById('camposOcultos');
const registrar = document.getElementById('registrar');
const metodoPago = document.getElementById('metodoPago');
const aviso = document.getElementById('avisoCarrito');
let procesando = false;

const dinero = n => 'Bs ' + n.toFixed(2);

// Validar botón de registro
function actualizarBoton() {
    const entradas = [...tabla.querySelectorAll('.cantidad')];
    const cantidadesValidas = carrito.length > 0 &&
        entradas.length === carrito.length &&
        carrito.every(p => {
            const input = entradas.find(x => Number(x.dataset.id) === p.id);
            const n = Number(input?.value);
            return input && Number.isInteger(n) && n >= 1 && n <= p.stock;
        });

    const metodoValido = metodoPago.value !== '';
    registrar.disabled = procesando || !cantidadesValidas || !metodoValido;

    aviso.textContent = procesando ? 'Registrando venta...' :
        !carrito.length ? 'Agrega productos para continuar.' :
        !metodoValido ? 'Selecciona un método de pago.' :
        !cantidadesValidas ? 'Revisa las cantidades ingresadas.' :
        'Venta lista para registrar.';

    document.querySelectorAll('.agregar').forEach(btn => {
        const p = carrito.find(x => x.id === Number(btn.dataset.id));
        btn.disabled = Number(btn.dataset.stock) < 1 ||
            (p && p.cantidad >= p.stock);
    });
}

// Mostrar carrito y calcular total
function renderCarrito() {
    tabla.replaceChildren();
    ocultos.replaceChildren();
    let total = 0;

    carrito.forEach(p => {
        total += p.precio * p.cantidad;

        const item = document.createElement('div');
        item.className = 'item-carrito p-3';

        const fila = document.createElement('div');
        fila.className = 'row g-2 align-items-center';

        const info = document.createElement('div');
        info.className = 'col-12 col-md-5';

        const nombre = document.createElement('strong');
        nombre.textContent = p.nombre;

        const precio = document.createElement('div');
        precio.className = 'text-secondary small';
        precio.textContent = dinero(p.precio);
        info.append(nombre, precio);

        const cantidadCol = document.createElement('div');
        cantidadCol.className = 'col-5 col-md-3';

        const cantidad = document.createElement('input');
        Object.assign(cantidad, {
            type: 'number', min: 1, max: p.stock,
            step: 1, required: true, value: p.cantidad
        });
        cantidad.className = 'form-control form-control-sm cantidad';
        cantidad.dataset.id = p.id;
        cantidadCol.append(cantidad);

        const subtotal = document.createElement('div');
        subtotal.className = 'col-5 col-md-3 text-end fw-semibold';
        subtotal.textContent = dinero(p.precio * p.cantidad);

        const botonCol = document.createElement('div');
        botonCol.className = 'col-2 col-md-1 text-end';

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'btn btn-danger btn-sm eliminar';
        boton.dataset.id = p.id;
        boton.textContent = 'X';
        botonCol.append(boton);

        fila.append(info, cantidadCol, subtotal, botonCol);
        item.append(fila);
        tabla.append(item);

        [['id_producto[]',p.id],['cantidad[]',p.cantidad]].forEach(([name,value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            ocultos.append(input);
        });
    });

    if (!carrito.length) {
        const vacio = document.createElement('p');
        vacio.className = 'text-secondary text-center';
        vacio.textContent = 'No hay productos agregados.';
        tabla.append(vacio);
    }

    document.getElementById('total').textContent = dinero(total);
    actualizarBoton();
}

// Agregar productos
document.querySelectorAll('.agregar').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = Number(btn.dataset.id);
        const p = carrito.find(x => x.id === id);
        const stock = Number(btn.dataset.stock);

        if (stock < 1 || (p && p.cantidad >= p.stock)) {
            alert('No hay suficientes existencias.');
            return;
        }

        if (p) {
            p.cantidad++;
        } else {
            carrito.push({
                id,
                nombre: btn.dataset.nombre,
                precio: Number(btn.dataset.precio),
                stock,
                cantidad: 1
            });
        }

        renderCarrito();
    });
});

// Modificar cantidades
tabla.addEventListener('change', e => {
    if (!e.target.classList.contains('cantidad')) return;

    const p = carrito.find(x => x.id === Number(e.target.dataset.id));
    if (!p) return;

    let n = Number(e.target.value);
    if (!Number.isInteger(n) || n < 1) n = 1;

    if (n > p.stock) {
        n = p.stock;
        alert('No hay suficientes existencias.');
    }

    p.cantidad = n;
    renderCarrito();
});

tabla.addEventListener('input', e => {
    if (e.target.classList.contains('cantidad')) actualizarBoton();
});

// Eliminar productos
tabla.addEventListener('click', e => {
    if (!e.target.classList.contains('eliminar')) return;

    const i = carrito.findIndex(x => x.id === Number(e.target.dataset.id));
    if (i !== -1) carrito.splice(i, 1);

    renderCarrito();
});

// Buscar productos
document.getElementById('buscarProducto').addEventListener('input', e => {
    const texto = e.target.value.toLowerCase().trim();

    document.querySelectorAll('.producto-item').forEach(item => {
        item.hidden = !item.dataset.busqueda.includes(texto);
    });
});

// Método de pago
metodoPago.addEventListener('change', actualizarBoton);

// Validación final y protección contra doble envío
document.getElementById('formVenta').addEventListener('submit', e => {
    if (procesando || !carrito.length || !metodoPago.value) {
        e.preventDefault();
        return;
    }

    for (const input of tabla.querySelectorAll('.cantidad')) {
        const p = carrito.find(x => x.id === Number(input.dataset.id));
        const n = Number(input.value);

        if (!p || !Number.isInteger(n) || n < 1 || n > p.stock) {
            e.preventDefault();
            alert('Revisa las cantidades y el stock disponible.');
            actualizarBoton();
            return;
        }

        p.cantidad = n;
    }

    renderCarrito();

    if (!confirm('¿Confirmar esta venta?')) {
        e.preventDefault();
        return;
    }

    procesando = true;
    registrar.disabled = true;
    registrar.textContent = 'Registrando...';
    actualizarBoton();
});

// Estado inicial
renderCarrito();
</script>

</body>
</html>
