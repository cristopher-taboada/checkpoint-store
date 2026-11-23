
<?php
require_once __DIR__ . '/../config/auth.php';
requireRole('Administrador');
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['csrf_proveedores'])) {
    $_SESSION['csrf_proveedores'] = bin2hex(random_bytes(32));
}

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$error = '';
$form = [];
$accion = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_proveedores'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Solicitud no válida.');
    }

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'guardar' || $accion === 'actualizar') {
            $form = $_POST;
            $id = (int)($_POST['id_proveedor'] ?? 0);
            $nombre = trim($_POST['nombre_razon_social'] ?? '');
            $nit = trim($_POST['nit'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');

            if ($nombre === '') {
                throw new RuntimeException('El nombre o razón social es obligatorio.');
            }
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El correo electrónico no es válido.');
            }
            if ($accion === 'actualizar' && $id <= 0) {
                throw new RuntimeException('Proveedor no válido.');
            }

            $nit = $nit === '' ? null : $nit;
            if ($nit !== null) {
                $stmt = $conexion->prepare(
                    "SELECT id_proveedor FROM proveedores WHERE nit = ? AND id_proveedor <> ?"
                );
                $stmt->execute([$nit, $id]);
                if ($stmt->fetch()) {
                    throw new RuntimeException('Este NIT ya está registrado.');
                }
            }

            if ($accion === 'guardar') {
                $stmt = $conexion->prepare(
                    "INSERT INTO proveedores
                    (nombre_razon_social, nit, telefono, correo, direccion)
                    VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$nombre, $nit, $telefono, $correo, $direccion]);
            } else {
                $stmt = $conexion->prepare(
                    "UPDATE proveedores SET nombre_razon_social=?, nit=?, telefono=?,
                    correo=?, direccion=? WHERE id_proveedor=?"
                );
                $stmt->execute([$nombre, $nit, $telefono, $correo, $direccion, $id]);
            }

            header('Location: index.php?mensaje=guardado');
            exit;
        }

        if ($accion === 'cambiar_estado') {
            $id = (int)($_POST['id_proveedor'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Proveedor no válido.');
            }

            $stmt = $conexion->prepare(
                "UPDATE proveedores SET estado = IF(estado = 1, 0, 1) WHERE id_proveedor = ?"
            );
            $stmt->execute([$id]);

            header('Location: index.php?mensaje=estado');
            exit;
        }

        if ($accion === 'asignar_producto') {
            $idProveedor = (int)($_POST['id_proveedor'] ?? 0);
            $idProducto = (int)($_POST['id_producto'] ?? 0);
            $costo = trim($_POST['costo_referencia'] ?? '');

            if ($idProveedor <= 0 || $idProducto <= 0) {
                throw new RuntimeException('Selecciona un proveedor y un producto.');
            }
            if ($costo !== '' && (!is_numeric($costo) || (float)$costo < 0 || (float)$costo > 9999999999.99)) {
                throw new RuntimeException('El costo de referencia no es válido.');
            }

            $stmt = $conexion->prepare("SELECT estado FROM proveedores WHERE id_proveedor = ?");
            $stmt->execute([$idProveedor]);
            $proveedor = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proveedor || (int)$proveedor['estado'] !== 1) {
                throw new RuntimeException('El proveedor no existe o está inactivo.');
            }

            $stmt = $conexion->prepare("SELECT estado FROM productos WHERE id_producto = ?");
            $stmt->execute([$idProducto]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$producto || (int)$producto['estado'] !== 1) {
                throw new RuntimeException('El producto no existe o está inactivo.');
            }

            $stmt = $conexion->prepare(
                "SELECT 1 FROM proveedor_productos WHERE id_proveedor = ? AND id_producto = ?"
            );
            $stmt->execute([$idProveedor, $idProducto]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Este producto ya está asignado a ese proveedor.');
            }

            $stmt = $conexion->prepare(
                "INSERT INTO proveedor_productos (id_proveedor, id_producto, costo_referencia)
                VALUES (?, ?, ?)"
            );
            $stmt->execute([
                $idProveedor,
                $idProducto,
                $costo === '' ? null : number_format((float)$costo, 2, '.', '')
            ]);

            header("Location: index.php?productos=$idProveedor&mensaje=asignado");
            exit;
        }

        if ($accion === 'quitar_producto') {
            $idProveedor = (int)($_POST['id_proveedor'] ?? 0);
            $idProducto = (int)($_POST['id_producto'] ?? 0);

            if ($idProveedor <= 0 || $idProducto <= 0) {
                throw new RuntimeException('Datos de asignación no válidos.');
            }

            $stmt = $conexion->prepare(
                "DELETE FROM proveedor_productos WHERE id_proveedor = ? AND id_producto = ?"
            );
            $stmt->execute([$idProveedor, $idProducto]);

            header("Location: index.php?productos=$idProveedor&mensaje=quitado");
            exit;
        }

        throw new RuntimeException('Acción no válida.');
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        if ($accion === 'asignar_producto') {
            $error = 'No se pudo asignar el producto. Comprueba que no esté asignado.';
        } elseif ($accion === 'guardar' || $accion === 'actualizar') {
            $error = $ex->getCode() === '23000'
                ? 'El NIT ya está registrado o existe un dato duplicado.'
                : 'No se pudo guardar el proveedor.';
        } else {
            $error = 'No se pudo completar la operación.';
        }
    }
}

// Proveedor seleccionado para editar
$editar = null;
if (isset($_GET['editar'])) {
    $stmt = $conexion->prepare("SELECT * FROM proveedores WHERE id_proveedor = ?");
    $stmt->execute([(int)$_GET['editar']]);
    $editar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editar) {
        $error = 'Proveedor no encontrado.';
    }
}
if ($editar && !$form) {
    $form = $editar;
}

// Búsqueda y filtros
$buscar = trim($_GET['buscar'] ?? '');
$estado = $_GET['estado'] ?? 'todos';
if (!in_array($estado, ['todos', '1', '0'], true)) {
    $estado = 'todos';
}

$sql = "SELECT p.*,
        (SELECT COUNT(*) FROM proveedor_productos pp WHERE pp.id_proveedor = p.id_proveedor) AS total_productos
        FROM proveedores p WHERE 1=1";
$params = [];

if ($estado !== 'todos') {
    $sql .= " AND p.estado = ?";
    $params[] = (int)$estado;
}
if ($buscar !== '') {
    $sql .= " AND (p.nombre_razon_social LIKE ? OR p.nit LIKE ? OR p.telefono LIKE ? OR p.correo LIKE ?)";
    $valor = "%$buscar%";
    array_push($params, $valor, $valor, $valor, $valor);
}
$sql .= " ORDER BY p.id_proveedor DESC";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Productos del proveedor seleccionado
$proveedorProductos = null;
$asignados = [];
$disponibles = [];
$idSeleccionado = (int)($_GET['productos'] ?? 0);

if ($idSeleccionado > 0) {
    $stmt = $conexion->prepare("SELECT * FROM proveedores WHERE id_proveedor = ?");
    $stmt->execute([$idSeleccionado]);
    $proveedorProductos = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($proveedorProductos) {
        $stmt = $conexion->prepare(
            "SELECT p.id_producto, p.codigo, p.nombre, p.stock, c.nombre AS categoria,
                    pp.costo_referencia
             FROM proveedor_productos pp
             INNER JOIN productos p ON p.id_producto = pp.id_producto
             LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
             WHERE pp.id_proveedor = ? ORDER BY p.nombre"
        );
        $stmt->execute([$idSeleccionado]);
        $asignados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conexion->prepare(
            "SELECT p.id_producto, p.codigo, p.nombre, p.stock, c.nombre AS categoria
             FROM productos p
             LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
             WHERE p.estado = 1
             AND NOT EXISTS (
                 SELECT 1 FROM proveedor_productos pp
                 WHERE pp.id_proveedor = ? AND pp.id_producto = p.id_producto
             )
             ORDER BY p.nombre"
        );
        $stmt->execute([$idSeleccionado]);
        $disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error = 'No se encontró el proveedor seleccionado.';
    }
}

$mensajes = [
    'guardado' => 'Proveedor guardado correctamente.',
    'estado' => 'Estado del proveedor actualizado.',
    'asignado' => 'Producto asignado correctamente.',
    'quitado' => 'Producto retirado de este proveedor.'
];
$mensaje = $mensajes[$_GET['mensaje'] ?? ''] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Proveedores | CheckPoint Store</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body{background:#090A0D;color:#fff}
        .card{background:#171923;color:#fff;border:1px solid #30313D}
        .form-control,.form-select{background:#090A0D;color:#fff;border-color:#444451}
        .form-control:focus,.form-select:focus{background:#090A0D;color:#fff;border-color:#FF2E93;box-shadow:none}
        .form-control::placeholder{color:#9999A6}
        .form-select option{background:#171923;color:#fff}
        .table{--bs-table-bg:#171923;--bs-table-color:#fff;--bs-table-hover-bg:#252735;--bs-table-border-color:#30313D}
        .table thead th{background:#090A0D;color:#FF2E93;white-space:nowrap}
        .btn-pink{background:#FF2E93;color:#fff;border:0}
        .btn-pink:hover{background:#E91E83;color:#fff}
        .muted{color:#A1A1AA}
        .section-title{font-weight:700}
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-black border-bottom border-danger">
    <div class="container">
        <a href="../dashboard.php" class="navbar-brand fw-bold">CheckPoint Store</a>
        <span class="navbar-text">Gestión de proveedores</span>
    </div>
</nav>

<div class="container py-4">

    <div class="mb-4">
        <h2 class="fw-bold">Proveedores</h2>
        <p class="muted mb-2">Administra proveedores y los productos que suministran.</p>
        <a href="../dashboard.php" class="btn btn-outline-light btn-sm">Volver al panel</a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-success"><?= e($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- FORMULARIO DE PROVEEDORES -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <?= ($form['accion'] ?? '') === 'actualizar' || $editar ? 'Editar proveedor' : 'Registrar proveedor' ?>
            </h5>
        </div>

        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_proveedores']) ?>">
                <input type="hidden" name="accion" value="<?= e($form['accion'] ?? ($editar ? 'actualizar' : 'guardar')) ?>">
                <input type="hidden" name="id_proveedor" value="<?= e($form['id_proveedor'] ?? '') ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre o razón social *</label>
                        <input type="text" name="nombre_razon_social" class="form-control"
                               maxlength="150" required
                               value="<?= e($form['nombre_razon_social'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">NIT</label>
                        <input type="text" name="nit" class="form-control"
                               maxlength="30" value="<?= e($form['nit'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Teléfono</label>
                        <input type="text" name="telefono" class="form-control"
                               maxlength="30" value="<?= e($form['telefono'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Correo electrónico</label>
                        <input type="email" name="correo" class="form-control"
                               maxlength="120" value="<?= e($form['correo'] ?? '') ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Dirección</label>
                        <textarea name="direccion" class="form-control"
                                  maxlength="200" rows="2"><?= e($form['direccion'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="mt-3">
                    <button class="btn btn-pink">
                        <?= ($form['accion'] ?? '') === 'actualizar' || $editar ? 'Actualizar proveedor' : 'Registrar proveedor' ?>
                    </button>

                    <?php if ($editar || ($form['accion'] ?? '') === 'actualizar'): ?>
                        <a href="index.php" class="btn btn-secondary">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- BÚSQUEDA -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-6">
                    <input type="text" name="buscar" class="form-control"
                           placeholder="Buscar por nombre, NIT, teléfono o correo"
                           value="<?= e($buscar) ?>">
                </div>

                <div class="col-md-3">
                    <select name="estado" class="form-select">
                        <option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos los estados</option>
                        <option value="1" <?= $estado === '1' ? 'selected' : '' ?>>Activos</option>
                        <option value="0" <?= $estado === '0' ? 'selected' : '' ?>>Inactivos</option>
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-pink">Buscar</button>
                    <a href="index.php" class="btn btn-secondary">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- PRODUCTOS DEL PROVEEDOR -->
    <?php if ($proveedorProductos): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">Productos de <?= e($proveedorProductos['nombre_razon_social']) ?></h5>
                    <small class="muted">Administra los productos que suministra este proveedor.</small>
                </div>
                <a href="index.php" class="btn btn-outline-light btn-sm">Cerrar</a>
            </div>

            <div class="card-body">
                <div class="row g-4">

                    <div class="col-lg-5">
                        <h6 class="section-title mb-3">Asignar nuevo producto</h6>

                        <?php if ((int)$proveedorProductos['estado'] !== 1): ?>
                            <div class="alert alert-warning">
                                Este proveedor está inactivo. Actívalo para asignarle nuevos productos.
                            </div>
                        <?php elseif (!$disponibles): ?>
                            <div class="alert alert-info">
                                No hay otros productos activos disponibles para asignar.
                            </div>
                        <?php else: ?>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_proveedores']) ?>">
                                <input type="hidden" name="accion" value="asignar_producto">
                                <input type="hidden" name="id_proveedor"
                                       value="<?= (int)$proveedorProductos['id_proveedor'] ?>">

                                <div class="col-12">
                                    <label class="form-label">Producto *</label>
                                    <select name="id_producto" class="form-select" required>
                                        <option value="">Seleccionar producto</option>
                                        <?php foreach ($disponibles as $p): ?>
                                            <option value="<?= (int)$p['id_producto'] ?>">
                                                <?= e($p['codigo'] . ' - ' . $p['nombre'] . ' (' . $p['categoria'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Costo de referencia (Bs)</label>
                                    <input type="number" name="costo_referencia" class="form-control"
                                           min="0" max="9999999999.99" step="0.01"
                                           placeholder="Ej. 3200.00">
                                    <small class="muted">
                                        Es un costo orientativo; el costo real se registra en cada entrada.
                                    </small>
                                </div>

                                <div class="col-12">
                                    <button class="btn btn-pink">Asignar producto</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-7">
                        <h6 class="section-title mb-3">
                            Productos asignados (<?= count($asignados) ?>)
                        </h6>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Categoría</th>
                                        <th>Stock</th>
                                        <th>Costo ref.</th>
                                        <th></th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($asignados as $p): ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($p['nombre']) ?></strong><br>
                                                <small class="muted"><?= e($p['codigo']) ?></small>
                                            </td>
                                            <td><?= e($p['categoria'] ?? 'Sin categoría') ?></td>
                                            <td><?= (int)$p['stock'] ?></td>
                                            <td>
                                                <?= $p['costo_referencia'] !== null
                                                    ? 'Bs ' . number_format((float)$p['costo_referencia'], 2, '.', ',')
                                                    : '—' ?>
                                            </td>
                                            <td>
                                                <form method="POST"
                                                      onsubmit="return confirm('¿Retirar este producto de este proveedor?')">
                                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_proveedores']) ?>">
                                                    <input type="hidden" name="accion" value="quitar_producto">
                                                    <input type="hidden" name="id_proveedor"
                                                           value="<?= (int)$proveedorProductos['id_proveedor'] ?>">
                                                    <input type="hidden" name="id_producto"
                                                           value="<?= (int)$p['id_producto'] ?>">
                                                    <button class="btn btn-sm btn-outline-danger">Retirar</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (!$asignados): ?>
                                        <tr>
                                            <td colspan="5" class="text-center muted">
                                                Este proveedor todavía no tiene productos asignados.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- LISTADO DE PROVEEDORES -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Proveedores registrados (<?= count($proveedores) ?>)</h5>
        </div>

        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th>NIT</th>
                        <th>Teléfono</th>
                        <th>Correo</th>
                        <th>Estado</th>
                        <th>Productos</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($proveedores as $p): ?>
                        <tr>
                            <td><?= e($p['nombre_razon_social']) ?></td>
                            <td><?= e($p['nit']) ?></td>
                            <td><?= e($p['telefono']) ?></td>
                            <td><?= e($p['correo']) ?></td>
                            <td>
                                <span class="badge <?= $p['estado'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $p['estado'] ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td>
                                <a href="?productos=<?= (int)$p['id_proveedor'] ?>"
                                   class="btn btn-sm btn-outline-info">
                                    Ver (<?= (int)$p['total_productos'] ?>)
                                </a>
                            </td>
                            <td>
                                <div class="d-flex gap-2 flex-wrap">
                                    <a href="?editar=<?= (int)$p['id_proveedor'] ?>"
                                       class="btn btn-sm btn-warning">Editar</a>

                                    <form method="POST"
                                          onsubmit="return confirm('¿Cambiar el estado de este proveedor?')">
                                        <input type="hidden" name="csrf"
                                               value="<?= e($_SESSION['csrf_proveedores']) ?>">
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="id_proveedor"
                                               value="<?= (int)$p['id_proveedor'] ?>">

                                        <button class="btn btn-sm <?= $p['estado'] ? 'btn-danger' : 'btn-success' ?>">
                                            <?= $p['estado'] ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$proveedores): ?>
                        <tr>
                            <td colspan="7" class="text-center muted">
                                No se encontraron proveedores.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <footer class="text-center muted mt-4 small">
        CheckPoint Store © 2026
    </footer>

</div>
</body>
</html>
