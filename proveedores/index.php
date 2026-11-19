
<?php
session_start();
require_once "../config/conexion.php";

// SEGURIDAD CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// PROTEGER SALIDA HTML
function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$error = "";

// PROCESAR FORMULARIOS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        exit("Solicitud no válida.");
    }

    $accion = $_POST['accion'] ?? '';

    try {

        // REGISTRAR Y ACTUALIZAR PROVEEDOR
        if ($accion === 'guardar' || $accion === 'actualizar') {

            $nombre = trim($_POST['nombre_razon_social'] ?? '');
            $nit = trim($_POST['nit'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');

            // VALIDAR NOMBRE
            if ($nombre === '') {
                throw new RuntimeException(
                    "El nombre del proveedor es obligatorio."
                );
            }

            // VALIDAR CORREO
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    "El correo electrónico no es válido."
                );
            }

            // REGISTRAR
            if ($accion === 'guardar') {

                $sql = "INSERT INTO proveedores
                (nombre_razon_social, nit, telefono, correo, direccion)
                VALUES (?, ?, ?, ?, ?)";

                $stmt = $conexion->prepare($sql);

                $stmt->execute([
                    $nombre,
                    $nit,
                    $telefono,
                    $correo,
                    $direccion
                ]);

            } else {

                // ACTUALIZAR
                $id = (int)($_POST['id_proveedor'] ?? 0);

                $sql = "UPDATE proveedores SET
                nombre_razon_social=?,
                nit=?,
                telefono=?,
                correo=?,
                direccion=?
                WHERE id_proveedor=? AND estado=1";

                $stmt = $conexion->prepare($sql);

                $stmt->execute([
                    $nombre,
                    $nit,
                    $telefono,
                    $correo,
                    $direccion,
                    $id
                ]);
            }

            header("Location: index.php?ok=1");
            exit;
        }

        // DESACTIVAR PROVEEDOR
        if ($accion === 'eliminar') {

            $id = (int)($_POST['id_proveedor'] ?? 0);

            $stmt = $conexion->prepare(
                "UPDATE proveedores SET estado=0
                WHERE id_proveedor=?"
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

// BUSCAR PROVEEDOR PARA EDITAR
$editar = null;

if (isset($_GET['editar'])) {

    $stmt = $conexion->prepare("
        SELECT * FROM proveedores
        WHERE id_proveedor=? AND estado=1
    ");

    $stmt->execute([(int)$_GET['editar']]);

    $editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// LISTAR PROVEEDORES ACTIVOS
$proveedores = $conexion->query("
    SELECT * FROM proveedores
    WHERE estado=1
    ORDER BY id_proveedor DESC
")->fetchAll(PDO::FETCH_ASSOC);

// TOTAL DE PROVEEDORES ACTIVOS
$totalProveedores = count($proveedores);

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>CheckPoint Store | Proveedores</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet">

    <style>

        body {
            background: #090A0D;
            color: white;
        }

        .card {
            background: #171923;
            color: white;
            border: 1px solid #30313D;
        }

        .card-header {
            background: #171923;
            color: white;
            border-bottom: 1px solid #30313D;
        }

        .form-control {
            background: #090A0D;
            color: white;
            border-color: #444451;
        }

        .form-control:focus {
            background: #090A0D;
            color: white;
            border-color: #FF2E93;
            box-shadow: 0 0 0 0.15rem rgba(255,46,147,0.15);
        }

        .form-control::placeholder {
            color: #9999A6;
        }

        .form-control:disabled {
            background: #171923;
            color: white;
        }

        .form-label {
            color: white;
        }

        .btn-pink {
            background: #FF2E93;
            color: white;
            border: 0;
        }

        .btn-pink:hover {
            background: #E91E83;
            color: white;
        }

        .table {
            --bs-table-bg: #171923;
            --bs-table-color: white;
            --bs-table-hover-bg: #252735;
            --bs-table-hover-color: white;
            --bs-table-border-color: #30313D;
        }

        .table thead th {
            background: #090A0D;
            color: #FF2E93;
        }

        .navbar {
            background: #000 !important;
        }

        .text-muted {
            color: #9999A6 !important;
        }

        .form-control::placeholder {
            color: #9999A6;
        }

    </style>

</head>

<body>

<!-- NAVEGACIÓN -->
<nav class="navbar navbar-dark bg-black border-bottom border-danger">

    <div class="container">

        <a href="../index.php" class="navbar-brand fw-bold">
            CheckPoint Store
        </a>

        <span class="navbar-text">
            Gestión de proveedores
        </span>

    </div>

</nav>


<div class="container py-4">

    <!-- TÍTULO -->
    <div class="mb-4">

        <h2 class="fw-bold">
            Proveedores
        </h2>

        <p class="text-secondary">
            Administra los proveedores de tu tienda gamer.
        </p>

    </div>


    <!-- INDICADOR -->
    <div class="row g-3 mb-4">

        <div class="col-md-6">

            <div class="card p-3">

                <span class="text-secondary">
                    Proveedores registrados
                </span>

                <h2 class="text-danger fw-bold">
                    <?= $totalProveedores ?>
                </h2>

            </div>

        </div>

    </div>


    <!-- MENSAJES -->
    <?php if (isset($_GET['ok'])): ?>

        <div class="alert alert-success">
            Proveedor guardado correctamente.
        </div>

    <?php endif; ?>


    <?php if (isset($_GET['eliminado'])): ?>

        <div class="alert alert-success">
            Proveedor desactivado correctamente.
        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <!-- FORMULARIO -->
    <div class="card mb-4">

        <div class="card-header py-3">

            <h5 class="mb-0 fw-bold">
                <?= $editar ? 'Editar proveedor' : 'Registrar proveedor' ?>
            </h5>

        </div>


        <div class="card-body">

            <form method="POST">

                <input type="hidden" name="csrf"
                value="<?= e($_SESSION['csrf']) ?>">

                <input type="hidden" name="accion"
                value="<?= $editar ? 'actualizar' : 'guardar' ?>">

                <?php if ($editar): ?>

                    <input type="hidden" name="id_proveedor"
                    value="<?= e($editar['id_proveedor']) ?>">

                <?php endif; ?>


                <div class="row g-3">

                    <!-- NOMBRE -->
                    <div class="col-md-6">

                        <label class="form-label">
                            Nombre o razón social *
                        </label>

                        <input
                            type="text"
                            name="nombre_razon_social"
                            class="form-control"
                            maxlength="150"
                            required
                            placeholder="Nombre del proveedor"
                            value="<?= e($editar['nombre_razon_social'] ?? '') ?>"
                        >

                    </div>


                    <!-- NIT -->
                    <div class="col-md-6">

                        <label class="form-label">
                            NIT
                        </label>

                        <input
                            type="text"
                            name="nit"
                            class="form-control"
                            maxlength="30"
                            placeholder="NIT del proveedor"
                            value="<?= e($editar['nit'] ?? '') ?>"
                        >

                    </div>


                    <!-- TELÉFONO -->
                    <div class="col-md-6">

                        <label class="form-label">
                            Teléfono
                        </label>

                        <input
                            type="text"
                            name="telefono"
                            class="form-control"
                            maxlength="30"
                            placeholder="Número de contacto"
                            value="<?= e($editar['telefono'] ?? '') ?>"
                        >

                    </div>


                    <!-- CORREO -->
                    <div class="col-md-6">

                        <label class="form-label">
                            Correo electrónico
                        </label>

                        <input
                            type="email"
                            name="correo"
                            class="form-control"
                            maxlength="120"
                            placeholder="proveedor@correo.com"
                            value="<?= e($editar['correo'] ?? '') ?>"
                        >

                    </div>


                    <!-- DIRECCIÓN -->
                    <div class="col-12">

                        <label class="form-label">
                            Dirección
                        </label>

                        <textarea
                            name="direccion"
                            class="form-control"
                            rows="2"
                            maxlength="200"
                            placeholder="Dirección del proveedor"
                        ><?= e($editar['direccion'] ?? '') ?></textarea>

                    </div>

                </div>


                <!-- BOTONES -->
                <div class="mt-3">

                    <button type="submit" class="btn btn-pink">

                        <?= $editar ? 'Actualizar proveedor' : 'Registrar proveedor' ?>

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

        <div class="card-header py-3">

            <h5 class="mb-0 fw-bold">
                Proveedores registrados
            </h5>

        </div>


        <div class="card-body table-responsive">

            <table class="table table-dark table-hover align-middle">

                <thead>

                    <tr>
                        <th>Proveedor</th>
                        <th>NIT</th>
                        <th>Teléfono</th>
                        <th>Correo</th>
                        <th>Dirección</th>
                        <th>Acciones</th>
                    </tr>

                </thead>


                <tbody>

                    <?php if ($proveedores): ?>

                        <?php foreach ($proveedores as $p): ?>

                            <tr>

                                <td class="fw-semibold">
                                    <?= e($p['nombre_razon_social']) ?>
                                </td>

                                <td>
                                    <?= e($p['nit']) ?>
                                </td>

                                <td>
                                    <?= e($p['telefono']) ?>
                                </td>

                                <td>
                                    <?= e($p['correo']) ?>
                                </td>

                                <td>
                                    <?= e($p['direccion']) ?>
                                </td>


                                <!-- ACCIONES -->
                                <td>

                                    <div class="d-flex gap-2">

                                        <!-- EDITAR -->
                                        <a
                                            href="index.php?editar=<?= e($p['id_proveedor']) ?>"
                                            class="btn btn-sm btn-warning"
                                        >
                                            Editar
                                        </a>


                                        <!-- DESACTIVAR -->
                                        <form
                                            method="POST"
                                            onsubmit="return confirm('¿Desactivar este proveedor?')"
                                        >

                                            <input type="hidden" name="csrf"
                                            value="<?= e($_SESSION['csrf']) ?>">

                                            <input type="hidden" name="accion"
                                            value="eliminar">

                                            <input type="hidden" name="id_proveedor"
                                            value="<?= e($p['id_proveedor']) ?>">

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-danger"
                                            >
                                                Desactivar
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="6" class="text-center text-secondary py-4">
                                No hay proveedores registrados.
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- PIE DE PÁGINA -->
    <footer class="text-center text-secondary mt-4">

        CheckPoint Store © 2026

    </footer>

</div>

</body>
</html>
