<?php
session_start();
require_once "../config/conexion.php";

// TOKEN DE SEGURIDAD
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// PROTEGER SALIDA HTML
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$MSG_DUPLICADO = "Ojo: el producto ya existe.";

$error = "";
$editar = null;

$datos = [
    'id_producto' => '',
    'codigo' => '',
    'nombre' => '',
    'id_categoria' => '',
    'precio_venta' => '',
    'stock' => '',
    'descripcion' => ''
];

// BUSCAR PRODUCTO PARA EDITAR
if (isset($_GET['editar'])) {
    $stmt = $conexion->prepare(
        "SELECT * FROM productos
         WHERE id_producto=? AND estado=1"
    );
    $stmt->execute([(int)$_GET['editar']]);
    $editar = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editar) {
        $datos = $editar;
    }
}

// PROCESAR FORMULARIOS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        exit("Solicitud no válida.");
    }

    $accion = $_POST['accion'] ?? '';

    try {

        // REGISTRAR O ACTUALIZAR
        if ($accion === 'guardar' || $accion === 'actualizar') {

            $id = (int)($_POST['id_producto'] ?? 0);
            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
            $nombre = trim($_POST['nombre'] ?? '');
            $categoria = filter_var(
                $_POST['id_categoria'] ?? '',
                FILTER_VALIDATE_INT
            );
            $precio = trim($_POST['precio_venta'] ?? '');
            $stock = filter_var(
                $_POST['stock'] ?? '',
                FILTER_VALIDATE_INT
            );
            $descripcion = trim($_POST['descripcion'] ?? '');

            // CONSERVAR DATOS DEL FORMULARIO
            $datos = [
                'id_producto' => $id,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'id_categoria' => $_POST['id_categoria'] ?? '',
                'precio_venta' => $precio,
                'stock' => $_POST['stock'] ?? '',
                'descripcion' => $descripcion
            ];

            // VALIDAR CAMPOS
            if (
                $codigo === '' ||
                $nombre === '' ||
                $categoria === false || $categoria <= 0 ||
                !is_numeric($precio) || (float)$precio < 0 ||
                $stock === false || $stock < 0
            ) {
                throw new RuntimeException(
                    "Verifica los datos ingresados."
                );
            }

            if ($accion === 'actualizar' && $id <= 0) {
                throw new RuntimeException("Producto no válido.");
            }

            // VALIDAR CATEGORÍA
            $stmt = $conexion->prepare(
                "SELECT 1 FROM categorias
                 WHERE id_categoria=? AND estado=1"
            );
            $stmt->execute([$categoria]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException(
                    "Selecciona una categoría activa."
                );
            }

            // EVITAR PRODUCTOS DUPLICADOS
            // - Código: se compara contra todos los productos (activos e inactivos)
            // - Nombre: se compara solo contra productos activos
            $stmt = $conexion->prepare(
                "SELECT 1 FROM productos
                 WHERE id_producto <> ?
                 AND (
                     UPPER(TRIM(codigo)) = ?
                     OR (estado = 1 AND UPPER(TRIM(nombre)) = ?)
                 )
                 LIMIT 1"
            );
            $stmt->execute([$id, $codigo, mb_strtoupper($nombre, 'UTF-8')]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException($MSG_DUPLICADO);
            }

            // INSERTAR PRODUCTO
            if ($accion === 'guardar') {

                $stmt = $conexion->prepare(
                    "INSERT INTO productos
                    (id_categoria, codigo, nombre, descripcion,
                     precio_venta, stock)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $categoria,
                    $codigo,
                    $nombre,
                    $descripcion,
                    $precio,
                    $stock
                ]);

            } else {

                // ACTUALIZAR PRODUCTO
                $stmt = $conexion->prepare(
                    "UPDATE productos SET
                    id_categoria=?,
                    codigo=?,
                    nombre=?,
                    descripcion=?,
                    precio_venta=?,
                    stock=?
                    WHERE id_producto=? AND estado=1"
                );

                $stmt->execute([
                    $categoria,
                    $codigo,
                    $nombre,
                    $descripcion,
                    $precio,
                    $stock,
                    $id
                ]);
            }

            header("Location: index.php?ok=1");
            exit;
        }

        // DESACTIVAR PRODUCTO
        if ($accion === 'desactivar') {

            $id = (int)($_POST['id_producto'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException("Producto no válido.");
            }

            $stmt = $conexion->prepare(
                "UPDATE productos SET estado=0
                 WHERE id_producto=? AND estado=1"
            );
            $stmt->execute([$id]);

            header("Location: index.php?eliminado=1");
            exit;
        }

    } catch (RuntimeException $ex) {

        $error = $ex->getMessage();

    } catch (PDOException $ex) {

        // CONTROLAR DUPLICADOS DESDE MYSQL
        if (($ex->errorInfo[1] ?? null) == 1062) {
            $error = $MSG_DUPLICADO;
        } else {
            $error = "No se pudo completar la operación.";
        }
    }
}

// LISTAR CATEGORÍAS ACTIVAS
$categorias = $conexion->query(
    "SELECT id_categoria, nombre
     FROM categorias
     WHERE estado=1
     ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

// LISTAR PRODUCTOS
$productos = $conexion->query(
    "SELECT p.*, c.nombre AS categoria
     FROM productos p
     INNER JOIN categorias c
     ON p.id_categoria=c.id_categoria
     WHERE p.estado=1
     ORDER BY p.id_producto DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// INDICADORES
$totalProductos = count($productos);
$totalStock = array_sum(array_column($productos, 'stock'));

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>CheckPoint Store | Productos</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet">

    <style>
        body { background:#090A0D; color:white; }
        .card { background:#171923; color:white; border:1px solid #30313D; }
        .card-header { background:#20212D; color:white; }
        .form-control, .form-select {
            background:#090A0D;
            color:white;
            border-color:#444451;
        }
        .form-control:focus, .form-select:focus {
            background:#090A0D;
            color:white;
            border-color:#FF2E93;
        }
        .form-select option { background:#171923; }
        .btn-pink { background:#FF2E93; color:white; border:0; }
        .btn-pink:hover { background:#E91E83; color:white; }
        .table {
            --bs-table-bg:#171923;
            --bs-table-color:white;
            --bs-table-border-color:#30313D;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-black border-bottom border-danger">
    <div class="container">
        <a href="../index.php" class="navbar-brand fw-bold">
            CheckPoint Store
        </a>
        <span class="navbar-text">Gestión de productos</span>
    </div>
</nav>

<div class="container py-4">

    <div class="mb-4">
        <h2 class="fw-bold">Productos</h2>
        <p class="text-secondary">
            Administración del inventario de la tienda.
        </p>
    </div>

    <!-- INDICADORES -->
    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="card p-3">
                <span class="text-secondary">Productos registrados</span>
                <h2 class="text-danger"><?= $totalProductos ?></h2>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card p-3">
                <span class="text-secondary">Unidades en inventario</span>
                <h2 class="text-success"><?= $totalStock ?></h2>
            </div>
        </div>

    </div>

    <!-- MENSAJES -->
    <?php if (isset($_GET['ok']) && !$error): ?>
        <div class="alert alert-success">
            Producto guardado correctamente.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['eliminado']) && !$error): ?>
        <div class="alert alert-info">
            Producto desactivado correctamente.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-warning">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- FORMULARIO -->
    <div class="card mb-4">

        <div class="card-header">
            <h5 class="mb-0">
                <?= $editar ? 'Editar producto' : 'Registrar producto' ?>
            </h5>
        </div>

        <div class="card-body">

            <form method="POST" id="formProducto">

                <input type="hidden" name="csrf"
                value="<?= e($_SESSION['csrf']) ?>">

                <input type="hidden" name="accion"
                value="<?= $editar ? 'actualizar' : 'guardar' ?>">

                <?php if ($editar): ?>
                    <input type="hidden" name="id_producto"
                    value="<?= e($editar['id_producto']) ?>">
                <?php endif; ?>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Código</label>
                        <input type="text" name="codigo"
                        class="form-control" maxlength="50" required
                        value="<?= e($datos['codigo']) ?>"
                        placeholder="Ej. CONS001">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nombre del producto</label>
                        <input type="text" name="nombre"
                        class="form-control" maxlength="120" required
                        value="<?= e($datos['nombre']) ?>"
                        placeholder="Ej. PlayStation 5">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Categoría</label>
                        <select name="id_categoria"
                        class="form-select" required>

                            <option value="">Seleccionar categoría</option>

                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= e($cat['id_categoria']) ?>"
                                <?= $datos['id_categoria'] == $cat['id_categoria'] ? 'selected' : '' ?>>
                                    <?= e($cat['nombre']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Precio de venta (Bs)</label>
                        <input type="number" name="precio_venta"
                        class="form-control" min="0" step="0.01" required
                        value="<?= e($datos['precio_venta']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Stock disponible</label>
                        <input type="number" name="stock"
                        class="form-control" min="0" step="1" required
                        value="<?= e($datos['stock']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Descripción</label>
                        <input type="text" name="descripcion"
                        class="form-control"
                        value="<?= e($datos['descripcion']) ?>">
                    </div>

                </div>

                <div class="mt-4">

                    <button type="submit" class="btn btn-pink" id="btnGuardar">
                        <?= $editar ? 'Actualizar producto' : 'Registrar producto' ?>
                    </button>

                    <?php if ($editar): ?>
                        <a href="index.php" class="btn btn-secondary">
                            Cancelar
                        </a>
                    <?php endif; ?>

                </div>

            </form>

        </div>
    </div>

    <!-- TABLA -->
    <div class="card">

        <div class="card-header">
            <h5 class="mb-0">Productos registrados</h5>
        </div>

        <div class="card-body table-responsive">

            <table class="table table-dark table-hover align-middle">

                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Precio</th>
                        <th>Stock</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($productos as $p): ?>
                        <tr>
                            <td><?= e($p['codigo']) ?></td>
                            <td><?= e($p['nombre']) ?></td>
                            <td><?= e($p['categoria']) ?></td>
                            <td>
                                Bs <?= number_format((float)$p['precio_venta'], 2) ?>
                            </td>
                            <td><?= e($p['stock']) ?></td>

                            <td>
                                <div class="d-flex gap-2">

                                    <a href="index.php?editar=<?= e($p['id_producto']) ?>"
                                    class="btn btn-sm btn-warning">
                                        Editar
                                    </a>

                                    <form method="POST"
                                    onsubmit="return confirm('¿Desactivar este producto?')">

                                        <input type="hidden" name="csrf"
                                        value="<?= e($_SESSION['csrf']) ?>">

                                        <input type="hidden" name="accion"
                                        value="desactivar">

                                        <input type="hidden" name="id_producto"
                                        value="<?= e($p['id_producto']) ?>">

                                        <button type="submit"
                                        class="btn btn-sm btn-danger">
                                            Desactivar
                                        </button>

                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$productos): ?>
                        <tr>
                            <td colspan="6" class="text-center text-secondary">
                                No hay productos registrados.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

    <footer class="text-center text-secondary mt-4">
        CheckPoint Store © 2026
    </footer>

</div>

<script>
// Limpiar ?ok=1 / ?eliminado=1 de la URL para que el mensaje no reaparezca al recargar
if (location.search.includes('ok=') || location.search.includes('eliminado=')) {
    history.replaceState({}, document.title, location.pathname);
}

// Evitar doble clic en "Registrar" (que enviaría el formulario dos veces)
document.getElementById('formProducto').addEventListener('submit', function () {
    const btn = document.getElementById('btnGuardar');
    btn.disabled = true;
    btn.textContent = 'Guardando...';
});
</script>

</body>
</html>