
<?php
require_once __DIR__ . '/../config/auth.php';
requireRole('Administrador');
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$error = '';
$editar = false;
$datos = [
    'id_producto' => '', 'codigo' => '', 'nombre' => '',
    'id_categoria' => '', 'precio_venta' => '',
    'stock' => '', 'descripcion' => ''
];

// PROCESAR FORMULARIOS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Solicitud no válida.');
    }

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'guardar' || $accion === 'actualizar') {
            $id = (int)($_POST['id_producto'] ?? 0);
            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
            $nombre = trim($_POST['nombre'] ?? '');
            $categoria = filter_var($_POST['id_categoria'] ?? '', FILTER_VALIDATE_INT);
            $precio = trim($_POST['precio_venta'] ?? '');
            $stock = filter_var($_POST['stock'] ?? '', FILTER_VALIDATE_INT);
            $descripcion = trim($_POST['descripcion'] ?? '');

            $datos = [
                'id_producto' => $id,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'id_categoria' => $_POST['id_categoria'] ?? '',
                'precio_venta' => $precio,
                'stock' => $_POST['stock'] ?? '',
                'descripcion' => $descripcion
            ];

            if (
                $codigo === '' || $nombre === '' ||
                $categoria === false || $categoria <= 0 ||
                !is_numeric($precio) || (float)$precio < 0 ||
                $stock === false || $stock < 0
            ) {
                throw new RuntimeException('Verifica los datos ingresados.');
            }

            if ($accion === 'actualizar' && $id <= 0) {
                throw new RuntimeException('Producto no válido.');
            }

            $stmt = $conexion->prepare(
                "SELECT 1 FROM categorias WHERE id_categoria=? AND estado=1"
            );
            $stmt->execute([$categoria]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Selecciona una categoría activa.');
            }

            // EVITAR DUPLICADOS
            $stmt = $conexion->prepare(
                "SELECT 1 FROM productos
                 WHERE id_producto <> ?
                 AND (
                    UPPER(TRIM(codigo)) = ?
                    OR (estado=1 AND UPPER(TRIM(nombre)) = ?)
                 ) LIMIT 1"
            );
            $stmt->execute([$id, $codigo, mb_strtoupper($nombre, 'UTF-8')]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException('El producto ya existe.');
            }

            if ($accion === 'guardar') {
                $stmt = $conexion->prepare(
                    "INSERT INTO productos
                    (id_categoria, codigo, nombre, descripcion, precio_venta, stock)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $categoria, $codigo, $nombre,
                    $descripcion, $precio, $stock
                ]);
            } else {
                $stmt = $conexion->prepare(
                    "UPDATE productos SET
                    id_categoria=?, codigo=?, nombre=?, descripcion=?,
                    precio_venta=?, stock=?
                    WHERE id_producto=? AND estado=1"
                );
                $stmt->execute([
                    $categoria, $codigo, $nombre,
                    $descripcion, $precio, $stock, $id
                ]);
            }

            header('Location: index.php?ok=1');
            exit;
        }

        // ACTIVAR O DESACTIVAR
        if ($accion === 'cambiar_estado') {
            $id = (int)($_POST['id_producto'] ?? 0);

            $stmt = $conexion->prepare(
                "SELECT estado FROM productos WHERE id_producto=?"
            );
            $stmt->execute([$id]);
            $estadoActual = $stmt->fetchColumn();

            if ($estadoActual === false) {
                throw new RuntimeException('Producto no encontrado.');
            }

            $nuevoEstado = (int)$estadoActual === 1 ? 0 : 1;

            $stmt = $conexion->prepare(
                "UPDATE productos SET estado=? WHERE id_producto=?"
            );
            $stmt->execute([$nuevoEstado, $id]);

            header('Location: index.php?estado_cambiado=1');
            exit;
        }

    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
        $editar = $accion === 'actualizar';

    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $error = (($ex->errorInfo[1] ?? null) == 1062)
            ? 'El producto ya existe.'
            : 'No se pudo completar la operación.';
        $editar = $accion === 'actualizar';
    }
}

// BUSCAR PRODUCTO PARA EDITAR
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['editar'])) {
    $stmt = $conexion->prepare(
        "SELECT * FROM productos WHERE id_producto=? AND estado=1"
    );
    $stmt->execute([(int)$_GET['editar']]);
    $productoEditar = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($productoEditar) {
        $datos = $productoEditar;
        $editar = true;
    } else {
        $error = 'Producto no encontrado.';
    }
}

// CATEGORÍAS
$categorias = $conexion->query(
    "SELECT id_categoria, nombre FROM categorias
     WHERE estado=1 ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$categoriasFiltro = $conexion->query(
    "SELECT id_categoria, nombre FROM categorias ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

// FILTROS
$buscar = trim($_GET['buscar'] ?? '');
$categoriaFiltro = $_GET['categoria'] ?? 'todos';
$stockFiltro = $_GET['stock'] ?? 'todos';
$estadoFiltro = $_GET['estado'] ?? '1';

if ($categoriaFiltro !== 'todos' &&
    (!ctype_digit((string)$categoriaFiltro) || (int)$categoriaFiltro <= 0)) {
    $categoriaFiltro = 'todos';
}

if (!in_array($stockFiltro, ['todos', 'bajo', 'agotado'], true)) {
    $stockFiltro = 'todos';
}

if (!in_array($estadoFiltro, ['1', '0', 'todos'], true)) {
    $estadoFiltro = '1';
}

$where = [];
$params = [];

if ($estadoFiltro !== 'todos') {
    $where[] = 'p.estado=?';
    $params[] = (int)$estadoFiltro;
}

if ($categoriaFiltro !== 'todos') {
    $where[] = 'p.id_categoria=?';
    $params[] = (int)$categoriaFiltro;
}

if ($buscar !== '') {
    $where[] = '(p.nombre LIKE ? OR p.codigo LIKE ?)';
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
}

if ($stockFiltro === 'bajo') {
    $where[] = 'p.stock BETWEEN 1 AND 5';
} elseif ($stockFiltro === 'agotado') {
    $where[] = 'p.stock=0';
}

$sql = "SELECT p.*, c.nombre AS categoria
        FROM productos p
        INNER JOIN categorias c ON p.id_categoria=c.id_categoria";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY p.id_producto DESC';

$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// RESUMEN DEL INVENTARIO ACTIVO
$resumen = $conexion->query(
    "SELECT COUNT(*) AS total,
     COALESCE(SUM(stock),0) AS unidades,
     COALESCE(SUM(CASE WHEN stock BETWEEN 1 AND 5 THEN 1 ELSE 0 END),0) AS bajo,
     COALESCE(SUM(CASE WHEN stock=0 THEN 1 ELSE 0 END),0) AS agotados
     FROM productos WHERE estado=1"
)->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CheckPoint Store | Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background:#090A0D; color:white; }
        .card { background:#171923; color:white; border:1px solid #30313D; }
        .card-header { background:#20212D; color:white; }
        .form-control, .form-select { background:#090A0D; color:white; border-color:#444451; }
        .form-control:focus, .form-select:focus { background:#090A0D; color:white; border-color:#FF2E93; box-shadow:none; }
        .form-select option { background:#171923; color:white; }
        .btn-pink { background:#FF2E93; color:white; border:0; }
        .btn-pink:hover { background:#E91E83; color:white; }
        .table { --bs-table-bg:#171923; --bs-table-color:white; --bs-table-hover-bg:#252735; --bs-table-border-color:#30313D; }
        .table thead th { color:#FF2E93; white-space:nowrap; }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-black border-bottom border-danger">
    <div class="container">
        <a href="../dashboard.php" class="navbar-brand fw-bold">CheckPoint Store</a>
        <span class="navbar-text">Control de inventario</span>
    </div>
</nav>

<div class="container py-4">

    <div class="mb-4">
        <h2 class="fw-bold">Inventario</h2>
        <p class="text-secondary">Consulta y controla las existencias de productos.</p>
        <a href="../dashboard.php" class="btn btn-outline-light btn-sm">Volver al panel</a>
    </div>

    <!-- INDICADORES -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card p-3">
                <span class="text-secondary">Productos activos</span>
                <h2><?= (int)$resumen['total'] ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3">
                <span class="text-secondary">Unidades disponibles</span>
                <h2 class="text-success"><?= (int)$resumen['unidades'] ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3">
                <span class="text-secondary">Stock bajo</span>
                <h2 class="text-warning"><?= (int)$resumen['bajo'] ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card p-3">
                <span class="text-secondary">Agotados</span>
                <h2 class="text-danger"><?= (int)$resumen['agotados'] ?></h2>
            </div>
        </div>
    </div>

    <!-- MENSAJES -->
    <?php if (isset($_GET['ok'])): ?>
        <div class="alert alert-success">Producto guardado correctamente.</div>
    <?php endif; ?>

    <?php if (isset($_GET['estado_cambiado'])): ?>
        <div class="alert alert-success">Estado del producto actualizado.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-warning"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- FORMULARIO -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?= $editar ? 'Editar producto' : 'Registrar producto' ?></h5>
        </div>

        <div class="card-body">
            <form method="POST" id="formProducto">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="accion" value="<?= $editar ? 'actualizar' : 'guardar' ?>">
                <input type="hidden" name="id_producto" value="<?= e($datos['id_producto']) ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Código</label>
                        <input type="text" name="codigo" class="form-control" maxlength="50" required value="<?= e($datos['codigo']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nombre del producto</label>
                        <input type="text" name="nombre" class="form-control" maxlength="120" required value="<?= e($datos['nombre']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Categoría</label>
                        <select name="id_categoria" class="form-select" required>
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
                        <input type="number" name="precio_venta" class="form-control" min="0" step="0.01" required value="<?= e($datos['precio_venta']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Stock disponible</label>
                        <input type="number" name="stock" class="form-control" min="0" step="1" required value="<?= e($datos['stock']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Descripción</label>
                        <input type="text" name="descripcion" class="form-control" value="<?= e($datos['descripcion']) ?>">
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-pink" id="btnGuardar">
                        <?= $editar ? 'Actualizar producto' : 'Registrar producto' ?>
                    </button>

                    <?php if ($editar): ?>
                        <a href="index.php" class="btn btn-secondary">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Buscar y filtrar productos</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <input type="search" name="buscar" class="form-control"
                        placeholder="Nombre o código" value="<?= e($buscar) ?>">
                </div>

                <div class="col-md-3">
                    <select name="categoria" class="form-select">
                        <option value="todos">Todas las categorías</option>
                        <?php foreach ($categoriasFiltro as $cat): ?>
                            <option value="<?= $cat['id_categoria'] ?>"
                                <?= $categoriaFiltro == $cat['id_categoria'] ? 'selected' : '' ?>>
                                <?= e($cat['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <select name="stock" class="form-select">
                        <option value="todos" <?= $stockFiltro === 'todos' ? 'selected' : '' ?>>Todo el stock</option>
                        <option value="bajo" <?= $stockFiltro === 'bajo' ? 'selected' : '' ?>>Stock bajo (1 a 5)</option>
                        <option value="agotado" <?= $stockFiltro === 'agotado' ? 'selected' : '' ?>>Agotados</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <select name="estado" class="form-select">
                        <option value="1" <?= $estadoFiltro === '1' ? 'selected' : '' ?>>Activos</option>
                        <option value="0" <?= $estadoFiltro === '0' ? 'selected' : '' ?>>Inactivos</option>
                        <option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-pink">Aplicar filtros</button>
                    <a href="index.php" class="btn btn-secondary">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- LISTADO -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Productos encontrados (<?= count($productos) ?>)</h5>
        </div>

        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Precio</th>
                        <th>Stock</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($productos as $p): ?>
                        <tr>
                            <td><?= e($p['codigo']) ?></td>
                            <td><?= e($p['nombre']) ?></td>
                            <td><?= e($p['categoria']) ?></td>
                            <td>Bs <?= number_format((float)$p['precio_venta'], 2) ?></td>
                            <td>
                                <?php if ((int)$p['stock'] === 0): ?>
                                    <span class="badge bg-danger">Agotado</span>
                                <?php elseif ((int)$p['stock'] <= 5): ?>
                                    <span class="badge bg-warning text-dark"><?= (int)$p['stock'] ?> unidades</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><?= (int)$p['stock'] ?> unidades</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $p['estado'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $p['estado'] ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <?php if ((int)$p['estado'] === 1): ?>
                                        <a href="?editar=<?= (int)$p['id_producto'] ?>" class="btn btn-sm btn-warning">Editar</a>
                                    <?php endif; ?>

                                    <form method="POST" onsubmit="return confirm('¿Cambiar el estado del producto?')">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="id_producto" value="<?= (int)$p['id_producto'] ?>">
                                        <button class="btn btn-sm <?= $p['estado'] ? 'btn-danger' : 'btn-success' ?>">
                                            <?= $p['estado'] ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$productos): ?>
                        <tr>
                            <td colspan="7" class="text-center text-secondary">
                                No se encontraron productos.
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
document.getElementById('formProducto').addEventListener('submit', function () {
    const btn = document.getElementById('btnGuardar');
    btn.disabled = true;
    btn.textContent = 'Guardando...';
});
</script>

</body>
</html>
