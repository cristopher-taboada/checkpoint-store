
<?php

// ==========================================
// 1. CONFIGURACIÓN DE RUTA DEL PROYECTO
// ==========================================

function appBasePath(): string {

    $script = str_replace(
        '\\',
        '/',
        $_SERVER['SCRIPT_NAME'] ?? ''
    );

    $base = rtrim(dirname($script), '/');

    $carpetas = [
        'categorias',
        'productos',
        'proveedores',
        'entradas',
        'ventas',
        'controladores'
    ];

    if (in_array(basename($base), $carpetas, true)) {
        $base = rtrim(dirname($base), '/');
    }

    return ($base === '/' || $base === '.') ? '' : $base;
}


// ==========================================
// 2. GENERAR RUTAS DEL SISTEMA
// ==========================================

function appUrl(string $ruta = ''): string {

    return appBasePath() . '/' . ltrim($ruta, '/');
}


// ==========================================
// 3. CONFIGURACIÓN SEGURA DE SESIÓN
// ==========================================

if (session_status() === PHP_SESSION_NONE) {

    // Detectar si la conexión utiliza HTTPS
    $esHttps = (
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== '' &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off'
    );

    // Evitar reutilización de identificadores de sesión
    ini_set('session.use_strict_mode', '1');

    // Utilizar exclusivamente cookies
    ini_set('session.use_only_cookies', '1');

    // Deshabilitar sesiones transmitidas por URL
    ini_set('session.use_trans_sid', '0');

    // Protección de cookies
    ini_set('session.cookie_httponly', '1');

    ini_set(
        'session.cookie_secure',
        $esHttps ? '1' : '0'
    );

    ini_set('session.cookie_samesite', 'Lax');

    // Tiempo máximo de almacenamiento de sesión
    ini_set('session.gc_maxlifetime', '1800');

    // Configurar parámetros de la cookie
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => appBasePath() . '/',
        'secure' => $esHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Iniciar sesión
    session_start();
}


// ==========================================
// 4. SISTEMA DE MENSAJES TEMPORALES (FLASH)
// ==========================================

/**
 * Guarda un mensaje para mostrarlo una sola vez.
 *
 * Tipos permitidos:
 * success, danger, warning e info.
 */
function flashSet(string $tipo, string $mensaje): void {

    $tiposPermitidos = [
        'success',
        'danger',
        'warning',
        'info'
    ];

    if (!in_array($tipo, $tiposPermitidos, true)) {
        $tipo = 'info';
    }

    $_SESSION['_flash_message'] = [
        'type' => $tipo,
        'message' => $mensaje
    ];
}


/**
 * Muestra el mensaje temporal y lo elimina
 * de la sesión para evitar que reaparezca
 * al actualizar la página.
 */
function flashRender(): string {

    if (
        empty($_SESSION['_flash_message']) ||
        !is_array($_SESSION['_flash_message'])
    ) {
        return '';
    }

    // Recuperar mensaje
    $flash = $_SESSION['_flash_message'];

    // Eliminarlo inmediatamente de la sesión
    unset($_SESSION['_flash_message']);

    $tiposPermitidos = [
        'success',
        'danger',
        'warning',
        'info'
    ];

    $tipo = $flash['type'] ?? 'info';
    $mensaje = $flash['message'] ?? '';

    if (
        !in_array($tipo, $tiposPermitidos, true) ||
        !is_string($mensaje) ||
        $mensaje === ''
    ) {
        return '';
    }

    // Identificador único
    $id = 'flash-' . bin2hex(random_bytes(4));

    // Protección del contenido
    $mensajeSeguro = htmlspecialchars(
        $mensaje,
        ENT_QUOTES,
        'UTF-8'
    );

    // Construir alerta Bootstrap
    return
        '<div id="' . $id . '" class="alert alert-' . $tipo .
        ' alert-dismissible fade show shadow-sm" role="alert">' .

        $mensajeSeguro .

        '<button type="button" class="btn-close" aria-label="Cerrar" ' .
        'onclick="this.parentNode.remove()"></button>' .

        '</div>' .

        '<script>
        (function () {

            var alerta = document.getElementById(' .
                json_encode($id) . ');

            if (!alerta) return;

            window.setTimeout(function () {

                alerta.classList.remove("show");

                window.setTimeout(function () {

                    if (alerta && alerta.parentNode) {
                        alerta.parentNode.removeChild(alerta);
                    }

                }, 250);

            }, 4500);

        })();
        </script>';
}


// ==========================================
// 5. VERIFICAR INICIO DE SESIÓN
// ==========================================

function requireLogin(): void {

    $usuario = $_SESSION['usuario'] ?? null;

    $idUsuario = is_array($usuario)
        ? ($usuario['id_usuario'] ?? null)
        : null;

    // Validar que exista un identificador válido
    $idValido = filter_var(
        $idUsuario,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($idValido === false) {

        unset($_SESSION['usuario']);

        header('Location: ' . appUrl('login.php'));

        exit;
    }

    // Evitar que páginas protegidas queden almacenadas
    // en la caché normal del navegador
    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    header('Pragma: no-cache');
}


// ==========================================
// 6. VERIFICAR PERMISOS SEGÚN EL ROL
// ==========================================

function requireRole(string ...$rolesPermitidos): void {

    // Verificar autenticación
    requireLogin();

    // Obtener rol actual
    $rolActual = $_SESSION['usuario']['rol'] ?? '';

    if (!is_string($rolActual)) {

        http_response_code(403);

        exit('403 - Acceso denegado.');
    }

    $rolActual = strtolower(trim($rolActual));

    // Normalizar roles permitidos
    $roles = array_map(
        fn($rol) => strtolower(trim($rol)),
        $rolesPermitidos
    );

    // Verificar autorización
    if (!in_array($rolActual, $roles, true)) {

        http_response_code(403);

        exit(
            '<h1>403 - Acceso denegado</h1>
            <p>No tienes permisos para acceder a este módulo.</p>'
        );
    }
}
