<?php

require_once __DIR__ . '/../config/auth.php';

// SOLO ADMINISTRADOR
requireRole('Administrador');

// Conexión a la base de datos
require_once __DIR__ . '/../config/conexion.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$error = "";

// REGISTRAR, EDITAR Y CAMBIAR ESTADO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        exit("Solicitud no válida.");
    }

    $accion = $_POST['accion'] ?? '';

    try {

        if ($accion === 'guardar' || $accion === 'actualizar') {

            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $id = (int)($_POST['id_categoria'] ?? 0);

            if ($nombre === '') {
                throw new RuntimeException("Ingresa el nombre de la categoría.");
            }

            // EVITAR DUPLICADOS
            $stmt = $conexion->prepare(
                "SELECT COUNT(*) FROM categorias
                 WHERE nombre = ? AND id_categoria <> ?"
            );
            $stmt->execute([$nombre, $id]);

            if ($stmt->fetchColumn() > 0) {
                throw new RuntimeException("La categoría ya existe.");
            }

            if ($accion === 'guardar') {

                $stmt = $conexion->prepare(
                    "INSERT INTO categorias (nombre, descripcion)
                     VALUES (?, ?)"
                );
                $stmt->execute([$nombre, $descripcion]);

            } else {

                $stmt = $conexion->prepare(
                    "UPDATE categorias SET nombre=?, descripcion=?
                     WHERE id_categoria=?"
                );
                $stmt->execute([$nombre, $descripcion, $id]);
            }

            header("Location: index.php?ok=1");
            exit;
        }

        if ($accion === 'estado') {

            $id = (int)($_POST['id_categoria'] ?? 0);
            $estado = (int)($_POST['estado'] ?? -1);

            if ($id <= 0 || !in_array($estado, [0, 1], true)) {
                throw new RuntimeException("Datos no válidos.");
            }

            $stmt = $conexion->prepare(
                "UPDATE categorias SET estado=? WHERE id_categoria=?"
            );
            $stmt->execute([$estado, $id]);

            header("Location: index.php");
            exit;
        }

    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();

    } catch (PDOException $ex) {
        $error = "Error al guardar los datos.";
    }
}

// CATEGORÍA PARA EDITAR
$editar = null;

if (isset($_GET['editar'])) {
    $stmt = $conexion->prepare(
        "SELECT * FROM categorias WHERE id_categoria=?"
    );
    $stmt->execute([(int)$_GET['editar']]);
    $editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// LISTAR CATEGORÍAS
$categorias = $conexion->query(
    "SELECT * FROM categorias ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$total = count($categorias);

$activas = (int)$conexion->query(
    "SELECT COUNT(*) FROM categorias WHERE estado=1"
)->fetchColumn();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CheckPoint Store | Categorías</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background:#090A0D; color:white; }
        .card { background:#171923; color:white; border:1px solid #30313D; }
        .form-control { background:#090A0D; color:white; border-color:#444451; }
        .form-control:focus { background:#090A0D; color:white; border-color:#FF2E93; }
        .form-control::placeholder { color:#9999A6; }
        .btn-pink { background:#FF2E93; color:white; border:0; }
        .btn-pink:hover { background:#E91E83; color:white; }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-black border-bottom border-danger">
    <div class="container">
        <a href="../index.php" class="navbar-brand fw-bold">
            CheckPoint Store
        </a>
        <span class="navbar-text">Gestión de categorías</span>
    </div>
</nav>

<div class="container py-4">

    <div class="mb-4">
        <h2 class="fw-bold">Categorías</h2>
        <p class="text-secondary">Organiza los productos de tu tienda gamer.</p>
    </div>

    <!-- INDICADORES -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card p-3">
                <span class="text-secondary">Total de categorías</span>
                <h2 class="text-danger"><?= $total ?></h2>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card p-3">
                <span class="text-secondary">Categorías activas</span>
                <h2 class="text-success"><?= $activas ?></h2>
            </div>
        </div>
    </div>

    <!-- MENSAJES -->
    <?php if (isset($_GET['ok'])): ?>
        <div class="alert alert-success">Categoría guardada correctamente.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- FORMULARIO -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?= $editar ? 'Editar categoría' : 'Nueva categoría' ?></h5>
        </div>

        <div class="card-body">
            <form method="POST">

                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="accion" value="<?= $editar ? 'actualizar' : 'guardar' ?>">

                <?php if ($editar): ?>
                    <input type="hidden" name="id_categoria" value="<?= e($editar['id_categoria']) ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre</label>
                        <input type="text" name="nombre" class="form-control"
                        maxlength="80" required placeholder="Ej. Consolas"
                        value="<?= e($editar['nombre'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Descripción</label>
                        <input type="text" name="descripcion" class="form-control"
                        placeholder="Descripción de la categoría"
                        value="<?= e($editar['descripcion'] ?? '') ?>">
                    </div>
                </div>

                <div class="mt-3">
                    <button class="btn btn-pink" type="submit">
                        <?= $editar ? 'Actualizar' : 'Registrar categoría' ?>
                    </button>

                    <?php if ($editar): ?>
                        <a href="index.php" class="btn btn-secondary">Cancelar</a>
                    <?php endif; ?>
                </div>

            </form>
        </div>
    </div>

    <!-- TABLA -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Categorías registradas</h5>
        </div>

        <div class="card-body table-responsive">
            <table class="table table-dark table-hover align-middle">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Descripción</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($categorias as $cat): ?>
                        <tr>
                            <td><?= e($cat['nombre']) ?></td>
                            <td><?= e($cat['descripcion']) ?></td>
                            <td>
                                <span class="badge <?= $cat['estado'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $cat['estado'] ? 'Activa' : 'Inactiva' ?>
                                </span>
                            </td>
                            <td>
                                <a href="index.php?editar=<?= $cat['id_categoria'] ?>"
                                class="btn btn-sm btn-warning">Editar</a>

                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="accion" value="estado">
                                    <input type="hidden" name="id_categoria" value="<?= $cat['id_categoria'] ?>">
                                    <input type="hidden" name="estado" value="<?= $cat['estado'] ? 0 : 1 ?>">

                                    <button class="btn btn-sm <?= $cat['estado'] ? 'btn-danger' : 'btn-success' ?>">
                                        <?= $cat['estado'] ? 'Desactivar' : 'Activar' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$categorias): ?>
                        <tr>
                            <td colspan="4" class="text-center">No hay categorías registradas.</td>
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
</body>
</html>
