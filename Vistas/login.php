
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>CheckPoint Store | Iniciar sesión</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #090A0D;
            color: white;
            min-height: 100vh;
        }

        .login-card {
            background: #171923;
            border: 1px solid #30313D;
            border-radius: 15px;
        }

        .form-control {
            background: #090A0D;
            color: white;
            border: 1px solid #444451;
        }

        .form-control:focus {
            background: #090A0D;
            color: white;
            border-color: #FF2E93;
            box-shadow: 0 0 0 0.2rem #FF2E9325;
        }

        .form-control::placeholder {
            color: #9999A6;
        }

        .btn-pink {
            background: #FF2E93;
            color: white;
            border: none;
        }

        .btn-pink:hover {
            background: #E91E83;
            color: white;
        }

        .logo {
            color: #FF2E93;
            font-weight: bold;
        }
    </style>
</head>

<body class="d-flex align-items-center">

<div class="container">

    <div class="row justify-content-center">

        <div class="col-md-5 col-lg-4">

            <div class="login-card shadow-lg p-4">

                <div class="text-center mb-4">

                    <h2 class="logo">CheckPoint Store</h2>

                    <p class="text-secondary">
                        Sistema de gestión de inventario y ventas
                    </p>

                    <h5 class="mt-4">Iniciar sesión</h5>

                </div>

                <?php if (isset($_GET['error'])): ?>

                    <div class="alert alert-danger text-center">
                        Usuario o contraseña incorrectos.
                    </div>

                <?php endif; ?>

                <form action="../controladores/AuthController.php" method="POST">

                    <input type="hidden" name="accion" value="login">

                    <div class="mb-3">

                        <label class="form-label">Usuario</label>

                        <input
                            type="text"
                            name="correo"
                            class="form-control"
                            placeholder="Ingresa tu usuario"
                            autocomplete="username"
                            required
                        >

                    </div>

                    <div class="mb-4">

                        <label class="form-label">Contraseña</label>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            placeholder="Ingresa tu contraseña"
                            autocomplete="current-password"
                            required
                        >

                    </div>

                    <button type="submit" class="btn btn-pink w-100 py-2">
                        Ingresar al sistema
                    </button>

                </form>

                <hr class="border-secondary mt-4">

                <p class="text-center text-secondary small mb-0">
                    CheckPoint Store © 2026
                </p>

            </div>

        </div>

    </div>

</div>

</body>
</html>
