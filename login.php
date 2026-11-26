<?php

// ==========================================
// 1. CONFIGURACIÓN DE SESIÓN
// ==========================================

require_once __DIR__ . '/config/auth.php';

// ==========================================
// 2. REDIRIGIR USUARIOS AUTENTICADOS
// ==========================================

if (!empty($_SESSION['usuario']['id_usuario'])) {
    header('Location: ' . appUrl('dashboard.php'));
    exit;
}

// ==========================================
// 3. GENERAR TOKEN CSRF
// ==========================================

if (
    empty($_SESSION['csrf_login']) ||
    !is_string($_SESSION['csrf_login'])
) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}

// ==========================================
// 4. RECIBIR MENSAJES
// ==========================================

$error = $_GET['error'] ?? '';

if (!is_string($error)) {
    $error = '';
}

$logout = (
    isset($_GET['logout']) &&
    is_string($_GET['logout']) &&
    $_GET['logout'] === '1'
);

// ==========================================
// 5. PROTEGER SALIDA HTML
// ==========================================

function e($valor): string {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Login | CheckPoint Store</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #090A0D;
            color: white;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #171923;
            padding: 35px;
            border: 1px solid #30313D;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0,0,0,.4);
        }

        .logo {
            width: 96px;
            height: 96px;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 12px auto;
        }

        .logo img {
            width: 96px;
            height: 96px;
            object-fit: contain;
            display: block;
        }

        .form-control {
            background: #090A0D;
            color: white;
            border: 1px solid #444451;
            padding: 12px;
        }

        .form-control:focus {
            background: #090A0D;
            color: white;
            border-color: #FF2E93;
            box-shadow: none;
        }

        .form-control::placeholder {
            color: #9999A6;
        }

        .btn-login {
            background: #FF2E93;
            color: white;
            border: none;
            padding: 12px;
            font-weight: bold;
        }

        .btn-login:hover {
            background: #E91E83;
            color: white;
        }

        @media (max-width: 576px) {
            .login-card {
                margin: 16px;
                padding: 28px 22px;
            }

            .logo {
                width: 82px;
                height: 82px;
            }

            .logo img {
                width: 82px;
                height: 82px;
            }
        }

    </style>

</head>

<body>

    <div class="login-card">

        <div class="logo">
            <img
                src="<?= e(appUrl('assets/img/checkpoint-icono-512.png')) ?>"
                alt="CheckPoint Store"
            >
        </div>

        <h3 class="text-center fw-bold">
            CheckPoint Store
        </h3>

        <p class="text-center text-secondary mb-4">
            Sistema de inventario y ventas
        </p>

        <!-- MENSAJES DE ERROR -->
        <?php if ($error === '1'): ?>

            <div class="alert alert-danger" role="alert">
                Usuario o contraseña incorrectos.
            </div>

        <?php elseif ($error === 'csrf'): ?>

            <div class="alert alert-warning" role="alert">
                El formulario venció. Intenta nuevamente.
            </div>

        <?php elseif ($error === 'sistema'): ?>

            <div class="alert alert-danger" role="alert">
                Error al conectar o procesar el inicio de sesión.
            </div>

        <?php endif; ?>

        <!-- MENSAJE DE CIERRE DE SESIÓN -->
        <?php if ($logout): ?>

            <div class="alert alert-success" role="alert">
                Sesión cerrada correctamente.
            </div>

        <?php endif; ?>

        <!-- FORMULARIO DE LOGIN -->
        <form
            action="<?= e(appUrl('controladores/AuthController.php')) ?>"
            method="POST"
        >

            <!-- TOKEN CSRF -->
            <input
                type="hidden"
                name="csrf"
                value="<?= e($_SESSION['csrf_login']) ?>"
            >

            <div class="mb-3">
                <label class="form-label">
                    Usuario
                </label>

                <input
                    type="text"
                    name="usuario"
                    class="form-control"
                    placeholder="Ingresa tu usuario"
                    maxlength="50"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="mb-4">
                <label class="form-label">
                    Contraseña
                </label>

                <input
                    type="password"
                    name="password"
                    class="form-control"
                    placeholder="Ingresa tu contraseña"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button
                type="submit"
                class="btn btn-login w-100"
            >
                Iniciar sesión
            </button>

        </form>

        <p class="text-center text-secondary mt-4 mb-0 small">
            © 2026 CheckPoint Store
        </p>

    </div>

</body>
</html>