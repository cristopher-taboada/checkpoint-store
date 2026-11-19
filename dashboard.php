<?php

require_once __DIR__ . '/config/auth.php';

requireLogin();

$usuario = $_SESSION['usuario'];

$esAdmin = $usuario['rol'] === 'Administrador';

if (empty($_SESSION['csrf_logout'])) {
    $_SESSION['csrf_logout'] = bin2hex(random_bytes(32));
}

function h($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Panel | CheckPoint Store</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet">

    <style>

        body {
            background: #090A0D;
            color: white;
            min-height: 100vh;
        }

        .navbar {
            background: #050609;
            border-bottom: 1px solid #30313D;
        }

        .panel {
            background: #171923;
            border: 1px solid #30313D;
            border-radius: 18px;
        }

        .module {
            background: #171923;
            border: 1px solid #30313D;
            border-radius: 15px;
            padding: 23px;
            height: 100%;
            color: white;
            transition: .2s;
        }

        .module:hover {
            border-color: #FF2E93;
            transform: translateY(-3px);
            color: white;
        }

        .module-icon {
            font-size: 28px;
            margin-bottom: 15px;
        }

        .muted {
            color: #A1A1AA;
        }

        a {
            text-decoration: none;
        }

    </style>

</head>

<body>

<nav class="navbar navbar-dark">

    <div class="container py-2">

        <span class="navbar-brand fw-bold">
            CheckPoint Store
        </span>

        <form method="POST"
        action="<?= h(appUrl('controladores/LogoutController.php')) ?>">

            <input type="hidden" name="csrf"
            value="<?= h($_SESSION['csrf_logout']) ?>">

            <button class="btn btn-outline-light btn-sm">
                Cerrar sesión
            </button>

        </form>

    </div>

</nav>

<div class="container py-5">

    <div class="panel p-4 p-md-5 mb-4">

        <p class="muted mb-2">PANEL PRINCIPAL</p>

        <h2 class="fw-bold">
            ¡Bienvenido, <?= h($usuario['nombre_completo']) ?>!
        </h2>

        <p class="muted">
            Gestiona las operaciones de CheckPoint Store.
        </p>

        <span class="badge <?= $esAdmin ? 'bg-danger' : 'bg-primary' ?>">
            <?= h($usuario['rol']) ?>
        </span>

    </div>

    <h4 class="fw-bold mb-4">
        Módulos del sistema
    </h4>

    <div class="row g-3">

        <?php if ($esAdmin): ?>

            <div class="col-md-6 col-lg-3">

                <a href="<?= h(appUrl('categorias/index.php')) ?>">

                    <div class="module">
                        <div class="module-icon"></div>
                        <h5>Categorías</h5>
                        <p class="muted small">
                            Administrar categorías.
                        </p>
                    </div>

                </a>

            </div>

            <div class="col-md-6 col-lg-3">

                <a href="<?= h(appUrl('productos/index.php')) ?>">

                    <div class="module">
                        <div class="module-icon"></div>
                        <h5>Productos</h5>
                        <p class="muted small">
                            Gestionar productos e inventario.
                        </p>
                    </div>

                </a>

            </div>

            <div class="col-md-6 col-lg-3">

                <a href="<?= h(appUrl('proveedores/index.php')) ?>">

                    <div class="module">
                        <div class="module-icon"></div>
                        <h5>Proveedores</h5>
                        <p class="muted small">
                            Administrar proveedores.
                        </p>
                    </div>

                </a>

            </div>

            <div class="col-md-6 col-lg-3">

                <a href="<?= h(appUrl('entradas/index.php')) ?>">

                    <div class="module">
                        <div class="module-icon"></div>
                        <h5>Entradas</h5>
                        <p class="muted small">
                            Registrar entradas de productos.
                        </p>
                    </div>

                </a>

            </div>

        <?php else: ?>

            <div class="col-md-6">

                <div class="module">

                    <div class="module-icon">🛒</div>

                    <h5>Ventas</h5>

                    <p class="muted">
                        Aquí se habilitará el registro de ventas
                    </p>

                </div>

            </div>

        <?php endif; ?>

    </div>

    <footer class="text-center muted mt-5 small">
        CheckPoint Store © 2026
    </footer>

</div>

</body>
</html>