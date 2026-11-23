
<?php
require_once __DIR__ . '/../config/auth.php';
requireRole('Administrador');
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['csrf_entradas'])) {
    $_SESSION['csrf_entradas'] = bin2hex(random_bytes(32));
}

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$error = '';
$mensaje = isset($_GET['ok']) ? 'Entrada registrada correctamente.' : '';

// ======================================
// CARGAR PRODUCTOS DE UN PROVEEDOR
// ======================================

if (isset($_GET['productos_proveedor'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $id = filter_var($_GET['productos_proveedor'], FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            throw new InvalidArgumentException('Proveedor no válido.');
        }

        $stmt = $conexion->prepare(
            "SELECT p.id_producto, p.codigo, p.nombre, p.stock,
                    pp.costo_referencia
             FROM proveedor_productos pp
             INNER JOIN productos p ON p.id_producto = pp.id_producto
             INNER JOIN proveedores pr ON pr.id_proveedor = pp.id_proveedor
             WHERE pp.id_proveedor = ?
             AND p.estado = 1 AND pr.estado = 1
             ORDER BY p.nombre"
        );

        $stmt->execute([$id]);

        echo json_encode(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

    } catch (Throwable $ex) {
        error_log('Carga de productos: ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudieron cargar los productos.']);
    }

    exit;
}

// ======================================
// REGISTRAR ENTRADA
// ======================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf = $_POST['csrf'] ?? '';

    if (!is_string($csrf) ||
        !hash_equals($_SESSION['csrf_entradas'], $csrf)) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga el formulario.');
    }

    try {
        $idProveedor = filter_var(
            $_POST['id_proveedor'] ?? '',
            FILTER_VALIDATE_INT
        );

        $idUsuario = (int)($_SESSION['usuario']['id_usuario'] ?? 0);
        $observacion = $_POST['observacion'] ?? '';

        if (!$idProveedor || $idProveedor < 1 ||
            !$idUsuario || !is_string($observacion)) {
            throw new InvalidArgumentException('Verifica los datos ingresados.');
        }

        $ids = $_POST['id_producto'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];

        if (!is_array($ids) || !is_array($cantidades) ||
            !$ids || count($ids) !== count($cantidades)) {
            throw new InvalidArgumentException('Agrega al menos un producto.');
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('No repitas productos en una entrada.');
        }

        $conexion->beginTransaction();

        // Comprobar proveedor
        $stmt = $conexion->prepare(
            "SELECT id_proveedor
             FROM proveedores
             WHERE id_proveedor = ? AND estado = 1
             FOR UPDATE"
        );

        $stmt->execute([$idProveedor]);

        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException(
                'El proveedor no existe o está inactivo.'
            );
        }

        // Consultar producto y costo acordado con el proveedor
        $buscarProducto = $conexion->prepare(
            "SELECT p.id_producto, pp.costo_referencia
             FROM proveedor_productos pp
             INNER JOIN productos p ON p.id_producto = pp.id_producto
             WHERE pp.id_proveedor = ?
             AND pp.id_producto = ?
             AND p.estado = 1
             FOR UPDATE"
        );

        $detalles = [];
        $total = 0;

        foreach ($ids as $i => $id) {

            $idProducto = filter_var($id, FILTER_VALIDATE_INT);
            $cantidad = filter_var(
                $cantidades[$i] ?? '',
                FILTER_VALIDATE_INT
            );

            if (!$idProducto || $idProducto < 1 ||
                !$cantidad || $cantidad < 1) {
                throw new InvalidArgumentException(
                    'Revisa los productos y las cantidades.'
                );
            }

            $buscarProducto->execute([$idProveedor, $idProducto]);
            $producto = $buscarProducto->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                throw new InvalidArgumentException(
                    'Un producto no pertenece al proveedor seleccionado.'
                );
            }

            if ($producto['costo_referencia'] === null) {
                throw new InvalidArgumentException(
                    'Un producto no tiene costo registrado. Actualízalo en Proveedores.'
                );
            }

            $costo = (float)$producto['costo_referencia'];

            if (!is_finite($costo) || $costo < 0) {
                throw new InvalidArgumentException('Costo de compra no válido.');
            }

            $subtotal = round($cantidad * $costo, 2);
            $total = round($total + $subtotal, 2);

            if ($subtotal > 9999999999.99 ||
                $total > 9999999999.99) {
                throw new InvalidArgumentException('El total supera el límite permitido.');
            }

            $detalles[] = [
                'id' => $idProducto,
                'cantidad' => $cantidad,
                'costo' => $costo,
                'subtotal' => $subtotal
            ];
        }

        // Crear cabecera
        $stmt = $conexion->prepare(
            "INSERT INTO entradas
            (id_proveedor, id_usuario, fecha, total, observacion, estado)
            VALUES (?, ?, NOW(), ?, ?, 'REGISTRADA')"
        );

        $stmt->execute([
            $idProveedor,
            $idUsuario,
            $total,
            trim($observacion)
        ]);

        $idEntrada = $conexion->lastInsertId();

        // Preparar operaciones
        $detalleSQL = $conexion->prepare(
            "INSERT INTO detalle_entrada
            (id_entrada, id_producto, cantidad, costo_unitario, subtotal)
            VALUES (?, ?, ?, ?, ?)"
        );

        $stockSQL = $conexion->prepare(
            "UPDATE productos
             SET stock = stock + ?
             WHERE id_producto = ? AND estado = 1"
        );

        $movimientoSQL = $conexion->prepare(
            "INSERT INTO movimientos
            (id_producto, id_usuario, tipo_movimiento,
             cantidad, fecha_hora, descripcion)
            VALUES (?, ?, 'ENTRADA', ?, NOW(), ?)"
        );

        // Guardar detalles, stock y movimientos
        foreach ($detalles as $d) {

            $detalleSQL->execute([
                $idEntrada,
                $d['id'],
                $d['cantidad'],
                $d['costo'],
                $d['subtotal']
            ]);

            $stockSQL->execute([
                $d['cantidad'],
                $d['id']
            ]);

            if ($stockSQL->rowCount() !== 1) {
                throw new RuntimeException(
                    'No se pudo actualizar el stock del producto.'
                );
            }

            $movimientoSQL->execute([
                $d['id'],
                $idUsuario,
                $d['cantidad'],
                "Entrada por compra #$idEntrada"
            ]);
        }

        // Confirmar todas las operaciones
        $conexion->commit();

        header('Location: index.php?ok=1');
        exit;

    } catch (InvalidArgumentException $ex) {

        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        $error = $ex->getMessage();

    } catch (Throwable $ex) {

        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        error_log('Error en entradas: ' . $ex->getMessage());

        $error = 'No se pudo registrar la entrada.';

        // Mostrar detalle técnico solamente en Laragon local
        if (in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'])) {
            $error .= ' Detalle técnico: ' . $ex->getMessage();
        }
    }
}

// ======================================
// DATOS PARA LA VISTA
// ======================================

$proveedores = $conexion->query(
    "SELECT id_proveedor, nombre_razon_social
     FROM proveedores
     WHERE estado = 1
     ORDER BY nombre_razon_social"
)->fetchAll(PDO::FETCH_ASSOC);

$historial = $conexion->query(
    "SELECT e.id_entrada, e.fecha, e.total, e.estado,
            p.nombre_razon_social, u.nombre_completo
     FROM entradas e
     INNER JOIN proveedores p ON e.id_proveedor = p.id_proveedor
     INNER JOIN usuarios u ON e.id_usuario = u.id_usuario
     ORDER BY e.id_entrada DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

$proveedorSeleccionado = (int)($_POST['id_proveedor'] ?? 0);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entradas | CheckPoint Store</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{background:#090A0D;color:white}
.card{background:#171923;color:white;border:1px solid #30313D}
.card-header{background:#20212D}
.form-control,.form-select{background:#090A0D;color:white;border-color:#444451}
.form-control:focus,.form-select:focus{background:#090A0D;color:white;border-color:#FF2E93;box-shadow:none}
.form-select option{background:#171923;color:white}
.form-control:disabled{background:#20212D;color:#aaa}
.table{--bs-table-bg:#171923;--bs-table-color:white;--bs-table-border-color:#30313D}
.table thead th{color:#FF2E93}
.btn-pink{background:#FF2E93;color:white;border:0}
.btn-pink:hover{background:#E91E83;color:white}
</style>
</head>

<body>

<nav class="navbar navbar-dark bg-black border-bottom border-danger">
<div class="container">
<a href="../dashboard.php" class="navbar-brand fw-bold">CheckPoint Store</a>
<span class="navbar-text">Entradas de mercadería</span>
</div>
</nav>

<div class="container py-4">

<div class="mb-4">
<h2 class="fw-bold">Registro de entradas</h2>
<p class="text-secondary">Control de compras e ingreso de productos al inventario.</p>
<a href="../dashboard.php" class="btn btn-outline-light btn-sm">Volver al panel</a>
</div>

<?php if ($mensaje): ?>
<div class="alert alert-success"><?= e($mensaje) ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card mb-4">
<div class="card-header">
<h5 class="mb-0">Nueva entrada de mercadería</h5>
</div>

<div class="card-body">

<form method="POST" id="formEntrada">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_entradas']) ?>">

<div class="mb-4">
<label class="form-label">Proveedor</label>

<select name="id_proveedor" id="proveedor" class="form-select" required>
<option value="">Seleccionar proveedor</option>

<?php foreach ($proveedores as $p): ?>
<option value="<?= (int)$p['id_proveedor'] ?>"
<?= $proveedorSeleccionado === (int)$p['id_proveedor'] ? 'selected' : '' ?>>
<?= e($p['nombre_razon_social']) ?>
</option>
<?php endforeach; ?>

</select>
</div>

<h5>Productos adquiridos</h5>
<p class="text-secondary small" id="estadoProductos">
Selecciona un proveedor para consultar sus productos.
</p>

<div class="table-responsive">
<table class="table align-middle">
<thead>
<tr>
<th>Producto</th>
<th>Cantidad</th>
<th>Costo unitario (Bs)</th>
<th>Subtotal</th>
<th></th>
</tr>
</thead>
<tbody id="detalles"></tbody>
</table>
</div>

<button type="button" id="agregar" class="btn btn-outline-light btn-sm mb-3" disabled>
+ Agregar producto
</button>

<div class="mb-3">
<label class="form-label">Observaciones</label>
<textarea name="observacion" class="form-control" rows="2"></textarea>
</div>

<div class="text-end mb-3">
<span class="text-secondary">TOTAL DE LA ENTRADA</span>
<h3 class="fw-bold" id="total">Bs 0.00</h3>
</div>

<button type="submit" id="registrar" class="btn btn-pink" disabled>
Registrar entrada
</button>

</form>
</div>
</div>

<div class="card">
<div class="card-header">
<h5 class="mb-0">Últimas entradas registradas</h5>
</div>

<div class="card-body table-responsive">
<table class="table table-hover align-middle">
<thead>
<tr>
<th>N.º</th>
<th>Fecha</th>
<th>Proveedor</th>
<th>Responsable</th>
<th>Total</th>
<th>Estado</th>
</tr>
</thead>
<tbody>

<?php foreach ($historial as $h): ?>
<tr>
<td>#<?= (int)$h['id_entrada'] ?></td>
<td><?= e($h['fecha']) ?></td>
<td><?= e($h['nombre_razon_social']) ?></td>
<td><?= e($h['nombre_completo']) ?></td>
<td>Bs <?= number_format((float)$h['total'], 2) ?></td>
<td><span class="badge bg-success"><?= e($h['estado']) ?></span></td>
</tr>
<?php endforeach; ?>

<?php if (!$historial): ?>
<tr>
<td colspan="6" class="text-center text-secondary">
Todavía no hay entradas registradas.
</td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>
</div>

<footer class="text-center text-secondary mt-4 small">
CheckPoint Store © 2026
</footer>

</div>

<template id="fila">
<tr>
<td>
<select name="id_producto[]" class="form-select producto" required>
<option value="">Seleccionar producto</option>
</select>
</td>
<td>
<input type="number" name="cantidad[]" class="form-control cantidad" min="1" required>
</td>
<td>
<input type="number" name="costo_unitario[]" class="form-control costo"
min="0" step="0.01" readonly required>
</td>
<td class="subtotal">Bs 0.00</td>
<td>
<button type="button" class="btn btn-danger btn-sm eliminar">X</button>
</td>
</tr>
</template>

<script>
const tabla = document.getElementById('detalles');
const plantilla = document.getElementById('fila');
const proveedor = document.getElementById('proveedor');
const agregar = document.getElementById('agregar');
const registrar = document.getElementById('registrar');
const estado = document.getElementById('estadoProductos');

let productos = [];

async function cargarProductos() {
    tabla.innerHTML = '';
    productos = [];
    agregar.disabled = true;
    registrar.disabled = true;
    calcularTotal();

    if (!proveedor.value) {
        estado.textContent = 'Selecciona un proveedor.';
        return;
    }

    estado.textContent = 'Cargando productos...';

    try {
        const r = await fetch(
            'index.php?productos_proveedor=' + encodeURIComponent(proveedor.value)
        );

        if (!r.ok) throw new Error('Error HTTP ' + r.status);

        const datos = await r.json();

        if (!Array.isArray(datos)) {
            throw new Error(datos.error || 'Respuesta no válida.');
        }

        productos = datos;

        if (!productos.length) {
            estado.textContent = 'Este proveedor no tiene productos asignados.';
            return;
        }

        estado.textContent = productos.length + ' productos disponibles.';
        agregar.disabled = false;
        agregarFila();

    } catch (ex) {
        estado.textContent = 'No se pudieron cargar los productos: ' + ex.message;
    }
}

function agregarFila() {
    if (!productos.length) return;

    const fila = plantilla.content.firstElementChild.cloneNode(true);
    const select = fila.querySelector('.producto');
    const costo = fila.querySelector('.costo');

    productos.forEach(p => {
        const opcion = new Option(
            `${p.codigo} - ${p.nombre} (Stock: ${p.stock})`,
            p.id_producto
        );

        opcion.dataset.costo = p.costo_referencia ?? '';
        select.add(opcion);
    });

    select.addEventListener('change', () => {
        const valor = select.selectedOptions[0].dataset.costo;

        costo.value = valor !== '' && valor !== null
            ? Number(valor).toFixed(2)
            : '';

        calcularTotal();
    });

    tabla.appendChild(fila);
    validarFormulario();
}

function calcularTotal() {
    let total = 0;

    tabla.querySelectorAll('tr').forEach(fila => {
        const cantidad = Number(fila.querySelector('.cantidad').value) || 0;
        const costo = Number(fila.querySelector('.costo').value) || 0;
        const subtotal = cantidad * costo;

        fila.querySelector('.subtotal').textContent =
            'Bs ' + subtotal.toFixed(2);

        total += subtotal;
    });

    document.getElementById('total').textContent =
        'Bs ' + total.toFixed(2);

    validarFormulario();
}

function validarFormulario() {
    const filas = [...tabla.querySelectorAll('tr')];
    const ids = filas.map(f => f.querySelector('.producto').value);

    const completos = filas.length > 0 &&
        filas.every(f => {
            const producto = f.querySelector('.producto').value;
            const cantidad = Number(f.querySelector('.cantidad').value);
            const costo = f.querySelector('.costo').value;

            return producto && cantidad > 0 && costo !== '' &&
                Number.isFinite(Number(costo));
        });

    registrar.disabled = !proveedor.value || !completos ||
        new Set(ids).size !== ids.length;
}

proveedor.addEventListener('change', cargarProductos);
agregar.addEventListener('click', agregarFila);
tabla.addEventListener('input', calcularTotal);
tabla.addEventListener('change', validarFormulario);

tabla.addEventListener('click', e => {
    if (e.target.classList.contains('eliminar')) {
        e.target.closest('tr').remove();

        if (!tabla.children.length && productos.length) {
            agregarFila();
        }

        calcularTotal();
    }
});

document.getElementById('formEntrada').addEventListener('submit', e => {
    if (registrar.disabled) {
        e.preventDefault();
        alert('Completa correctamente todos los productos.');
        return;
    }

    if (!confirm('¿Confirmas el registro de esta entrada?')) {
        e.preventDefault();
    }
});

if (proveedor.value) {
    cargarProductos();
}
</script>

</body>
</html>
