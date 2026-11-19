
<?php
require_once __DIR__ . '/../config/conexion.php';

$mensaje = "";
$error = "";

if (isset($_GET['ok'])) {
    $mensaje = "Compra registrada correctamente.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $idProveedor = (int)$_POST['id_proveedor'];
        $observacion = trim($_POST['observacion'] ?? '');

        $usuario = $conexion->query(
            "SELECT id_usuario FROM usuarios
             WHERE usuario = 'sistema' AND estado = 1 LIMIT 1"
        )->fetchColumn();

        if (!$usuario) {
            throw new Exception("No existe el usuario del sistema.");
        }

        $proveedor = $conexion->prepare(
            "SELECT id_proveedor FROM proveedores
             WHERE id_proveedor = ? AND estado = 1"
        );
        $proveedor->execute([$idProveedor]);

        if (!$proveedor->fetch()) {
            throw new Exception("Seleccione un proveedor válido.");
        }

        $ids = $_POST['id_producto'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];
        $costos = $_POST['costo_unitario'] ?? [];

        $detalles = [];
        $total = 0;

        foreach ($ids as $i => $id) {
            if ($id === '') continue;

            $idProducto = (int)$id;
            $cantidad = (int)($cantidades[$i] ?? 0);
            $costo = (float)($costos[$i] ?? -1);

            if ($cantidad <= 0 || $costo < 0) {
                throw new Exception("Revise cantidades y costos.");
            }

            $consulta = $conexion->prepare(
                "SELECT id_producto FROM productos
                 WHERE id_producto = ? AND estado = 1"
            );
            $consulta->execute([$idProducto]);

            if (!$consulta->fetch()) {
                throw new Exception("Producto no válido.");
            }

            $subtotal = round($cantidad * $costo, 2);
            $total += $subtotal;

            $detalles[] = [
                $idProducto,
                $cantidad,
                $costo,
                $subtotal
            ];
        }

        if (empty($detalles)) {
            throw new Exception("Agregue al menos un producto.");
        }

        $conexion->beginTransaction();

        $sql = $conexion->prepare(
            "INSERT INTO entradas
            (id_proveedor, id_usuario, fecha, total, observacion, estado)
            VALUES (?, ?, NOW(), ?, ?, 'Registrada')"
        );

        $sql->execute([
            $idProveedor,
            $usuario,
            $total,
            $observacion
        ]);

        $idEntrada = $conexion->lastInsertId();

        foreach ($detalles as $d) {

            $sql = $conexion->prepare(
                "INSERT INTO detalle_entrada
                (id_entrada, id_producto, cantidad,
                 costo_unitario, subtotal)
                VALUES (?, ?, ?, ?, ?)"
            );

            $sql->execute([
                $idEntrada,
                $d[0],
                $d[1],
                $d[2],
                $d[3]
            ]);

            $sql = $conexion->prepare(
                "UPDATE productos
                 SET stock = stock + ?
                 WHERE id_producto = ?"
            );

            $sql->execute([$d[1], $d[0]]);

            $sql = $conexion->prepare(
                "INSERT INTO movimientos
                (id_producto, id_usuario, tipo_movimiento,
                 cantidad, fecha_hora, descripcion)
                VALUES (?, ?, 'entrada', ?, NOW(), ?)"
            );

            $sql->execute([
                $d[0],
                $usuario,
                $d[1],
                "Entrada por compra #".$idEntrada
            ]);
        }

        $conexion->commit();

        header("Location: index.php?ok=1");
        exit;

    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e->getMessage();
    }
}

$proveedores = $conexion->query(
    "SELECT id_proveedor, nombre_razon_social
     FROM proveedores WHERE estado = 1
     ORDER BY nombre_razon_social"
)->fetchAll(PDO::FETCH_ASSOC);

$productos = $conexion->query(
    "SELECT id_producto, codigo, nombre
     FROM productos WHERE estado = 1
     ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registrar compras - CheckPoint Store</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container py-4">

    <div class="bg-dark text-white p-4 rounded mb-4">
        <h2>CheckPoint Store</h2>
        <p class="mb-0">Registro de compras y entradas de inventario</p>
    </div>

    <a href="../index.php" class="btn btn-secondary mb-3">
        Volver al inicio
    </a>

    <?php if ($mensaje): ?>
        <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Registrar nueva compra</h5>
        </div>

        <div class="card-body">

            <form method="POST">

                <div class="mb-3">
                    <label class="form-label">Proveedor</label>
                    <select name="id_proveedor" class="form-select" required>
                        <option value="">Seleccionar proveedor</option>
                        <?php foreach ($proveedores as $p): ?>
                            <option value="<?= $p['id_proveedor'] ?>">
                                <?= htmlspecialchars($p['nombre_razon_social']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <h5 class="mt-4">Productos adquiridos</h5>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Producto</th>
                                <th width="130">Cantidad</th>
                                <th width="170">Costo unitario (Bs)</th>
                                <th width="100">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="detalles"></tbody>
                    </table>
                </div>

                <button type="button" id="agregar"
                        class="btn btn-outline-primary mb-3">
                    + Agregar producto
                </button>

                <div class="mb-3">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observacion" class="form-control"
                              rows="2"></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    Registrar compra
                </button>

            </form>
        </div>
    </div>

</div>

<template id="fila">
<tr>
    <td>
        <select name="id_producto[]" class="form-select" required>
            <option value="">Seleccionar producto</option>
            <?php foreach ($productos as $p): ?>
                <option value="<?= $p['id_producto'] ?>">
                    <?= htmlspecialchars($p['codigo'].' - '.$p['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </td>
    <td>
        <input type="number" name="cantidad[]"
               class="form-control" min="1" required>
    </td>
    <td>
        <input type="number" name="costo_unitario[]"
               class="form-control" min="0" step="0.01" required>
    </td>
    <td>
        <button type="button" class="btn btn-danger eliminar">
            X
        </button>
    </td>
</tr>
</template>

<script>
const tabla = document.getElementById('detalles');
const plantilla = document.getElementById('fila');

function agregarFila() {
    tabla.appendChild(plantilla.content.cloneNode(true));
}

document.getElementById('agregar').addEventListener('click', agregarFila);

tabla.addEventListener('click', function(e) {
    if (e.target.classList.contains('eliminar')) {
        if (tabla.children.length > 1) {
            e.target.closest('tr').remove();
        }
    }
});

agregarFila();
</script>

</body>
</html>
