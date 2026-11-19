
<?php
session_start();
require_once "config/conexion.php";

// Seguridad CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$error = "";

// Asegurar que existan las categorias y esten activas
$conexion->exec("
    INSERT INTO categorias (nombre, descripcion, estado) VALUES
    ('Consolas', 'Consolas de videojuegos', 1),
    ('Accesorios', 'Controles y perifericos', 1),
    ('Videojuegos', 'Juegos para consolas', 1),
    ('Repuestos', 'Repuestos y componentes', 1)
    ON DUPLICATE KEY UPDATE estado = 1
");

// PROCESAR FORMULARIOS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        exit("Solicitud no valida");
    }

    $accion = $_POST['accion'] ?? '';

    try {

        if ($accion === 'guardar' || $accion === 'actualizar') {

            $id_categoria = filter_var(
                $_POST['id_categoria'] ?? '',
                FILTER_VALIDATE_INT
            );

            $codigo = trim($_POST['codigo'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $precio = $_POST['precio_venta'] ?? '';
            $stock = filter_var(
                $_POST['stock'] ?? '',
                FILTER_VALIDATE_INT
            );

            if (
                !$id_categoria || $codigo === '' ||
                $nombre === '' || !is_numeric($precio) ||
                $precio < 0 || $stock === false || $stock < 0
            ) {
                throw new RuntimeException("Verifica los datos ingresados.");
            }

            if ($accion === 'guardar') {

                $sql = "INSERT INTO productos
                (id_categoria, codigo, nombre, descripcion, precio_venta, stock)
                VALUES (?, ?, ?, ?, ?, ?)";

                $stmt = $conexion->prepare($sql);
                $stmt->execute([
                    $id_categoria, $codigo, $nombre,
                    $descripcion, $precio, $stock
                ]);

            } else {

                $id = (int)($_POST['id_producto'] ?? 0);

                $sql = "UPDATE productos SET
                id_categoria=?, codigo=?, nombre=?,
                descripcion=?, precio_venta=?, stock=?
                WHERE id_producto=? AND estado=1";

                $stmt = $conexion->prepare($sql);
                $stmt->execute([
                    $id_categoria, $codigo, $nombre,
                    $descripcion, $precio, $stock, $id
                ]);
            }

            header("Location: index.php?ok=1");
            exit;
        }

        if ($accion === 'eliminar') {

            $id = (int)($_POST['id_producto'] ?? 0);

            $stmt = $conexion->prepare(
                "UPDATE productos SET estado=0 WHERE id_producto=?"
            );

            $stmt->execute([$id]);

            header("Location: index.php?eliminado=1");
            exit;
        }

    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();

    } catch (PDOException $ex) {
        $error = "No se pudo completar la operación. Verifica los datos.";
    }
}

// CATEGORIAS
$categorias = $conexion->query("
    SELECT * FROM categorias
    WHERE estado=1 ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

// PRODUCTO PARA EDITAR
$editar = null;

if (isset($_GET['editar'])) {
    $stmt = $conexion->prepare("
        SELECT * FROM productos
        WHERE id_producto=? AND estado=1
    ");
    $stmt->execute([(int)$_GET['editar']]);
    $editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// LISTAR PRODUCTOS
$productos = $conexion->query("
    SELECT p.*, c.nombre AS categoria
    FROM productos p
    INNER JOIN categorias c ON p.id_categoria=c.id_categoria
    WHERE p.estado=1
    ORDER BY p.id_producto DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalProductos = count($productos);
$totalStock = array_sum(array_column($productos, 'stock'));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>CheckPoint Store</title>

    <!-- BOOTSTRAP 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<!-- NAVBAR -->
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            CheckPoint Store
        </a>
        <span class="navbar-text">
            Sistema de inventario y ventas
        </span>
    </div>
</nav>

<div class="container py-4">

    <!-- TITULO -->
    <div class="mb-4">
        <h2 class="fw-bold">Panel de inventario</h2>
        <p class="text-muted">Administración de productos</p>
    </div>

    <!-- INDICADORES -->
    <div class="row g-3 mb-4">

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Productos registrados</h6>
                    <h2 class="fw-bold text-primary">
                        <?= $totalProductos ?>
                    </h2>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted">Unidades en inventario</h6>
                    <h2 class="fw-bold text-success">
                        <?= $totalStock ?>
                    </h2>
                </div>
            </div>
        </div>

    </div>

    <!-- MENSAJES -->
    <?php if (isset($_GET['ok'])): ?>
        <div class="alert alert-success">
            Producto guardado correctamente.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['eliminado'])): ?>
        <div class="alert alert-success">
            Producto eliminado correctamente.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- FORMULARIO -->
    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">
                <?= $editar ? 'Editar producto' : 'Registrar producto' ?>
            </h5>
        </div>

        <div class="card-body">

            <form method="POST">

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
                        class="form-control" required maxlength="50"
                        placeholder="Ej. CONS001"
                        value="<?= e($editar['codigo'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nombre del producto</label>
                        <input type="text" name="nombre"
                        class="form-control" required maxlength="120"
                        placeholder="Ej. PlayStation 5"
                        value="<?= e($editar['nombre'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Categoría</label>

                        <select name="id_categoria" class="form-select" required>
                            <option value="">Seleccionar categoría</option>

                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= e($cat['id_categoria']) ?>"
                                <?= (isset($editar['id_categoria']) &&
                                $editar['id_categoria'] == $cat['id_categoria'])
                                ? 'selected' : '' ?>>

                                    <?= e($cat['nombre']) ?>

                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Precio de venta (Bs)</label>
                        <input type="number" name="precio_venta"
                        class="form-control" step="0.01" min="0"
                        required placeholder="Ej. 4500"
                        value="<?= e($editar['precio_venta'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Stock disponible</label>
                        <input type="number" name="stock"
                        class="form-control" min="0" step="1"
                        required placeholder="Ej. 5"
                        value="<?= e($editar['stock'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion"
                        class="form-control" rows="2"
                        placeholder="Descripción del producto"><?= e($editar['descripcion'] ?? '') ?></textarea>
                    </div>

                </div>

                <div class="mt-4">

                    <button type="submit" class="btn btn-primary">
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
    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">Productos registrados</h5>
        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-hover table-striped align-middle">

                    <thead class="table-dark">
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

                        <?php if ($productos): ?>

                            <?php foreach ($productos as $p): ?>

                                <tr>
                                    <td><?= e($p['codigo']) ?></td>
                                    <td class="fw-semibold"><?= e($p['nombre']) ?></td>
                                    <td>
                                        <span class="badge bg-info text-dark">
                                            <?= e($p['categoria']) ?>
                                        </span>
                                    </td>
                                    <td>Bs <?= number_format((float)$p['precio_venta'], 2) ?></td>
                                    <td><?= e($p['stock']) ?></td>

                                    <td>
                                        <div class="d-flex gap-2">

                                            <a href="index.php?editar=<?= e($p['id_producto']) ?>"
                                            class="btn btn-sm btn-warning">
                                                Editar
                                            </a>

                                            <form method="POST"
                                            onsubmit="return confirm('¿Eliminar este producto?')">

                                                <input type="hidden" name="csrf"
                                                value="<?= e($_SESSION['csrf']) ?>">

                                                <input type="hidden" name="accion"
                                                value="eliminar">

                                                <input type="hidden" name="id_producto"
                                                value="<?= e($p['id_producto']) ?>">

                                                <button type="submit"
                                                class="btn btn-sm btn-danger">
                                                    Eliminar
                                                </button>

                                            </form>

                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No hay productos registrados.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>
    </div>

    <!-- FOOTER -->
    <footer class="text-center text-muted mt-4">
        CheckPoint Store © 2026 | Sistema de inventario y ventas
    </footer>

</div>

</body>
</html>
