
<?php

// ==========================================
// 1. CONFIGURACIÓN Y CONEXIÓN
// ==========================================

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexion.php';

// ==========================================
// 2. VALIDAR MÉTODO HTTP
// ==========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: ' . appUrl('login.php'));
    exit;
}

// ==========================================
// 3. VALIDAR TOKEN CSRF
// ==========================================

$csrf = $_POST['csrf'] ?? null;
$token = $_SESSION['csrf_login'] ?? null;

if (
    !is_string($csrf) ||
    !is_string($token) ||
    $token === '' ||
    !hash_equals($token, $csrf)
) {

    header('Location: ' . appUrl('login.php?error=csrf'));
    exit;
}

// ==========================================
// 4. RECIBIR Y VALIDAR DATOS
// ==========================================

$usuario = $_POST['usuario'] ?? null;
$password = $_POST['password'] ?? null;

if (
    !is_string($usuario) ||
    !is_string($password)
) {

    header('Location: ' . appUrl('login.php?error=1'));
    exit;
}

$usuario = trim($usuario);

if ($usuario === '' || $password === '') {

    header('Location: ' . appUrl('login.php?error=1'));
    exit;
}

// ==========================================
// 5. VERIFICAR CREDENCIALES
// ==========================================

try {

    $sql = "
        SELECT
            u.id_usuario,
            u.id_rol,
            u.nombre_completo,
            u.usuario,
            u.password_hash,
            r.nombre AS rol

        FROM usuarios u

        INNER JOIN roles r
            ON u.id_rol = r.id_rol

        WHERE u.usuario = ?
          AND u.estado = 1
          AND r.estado = 1

        LIMIT 1
    ";

    $stmt = $conexion->prepare($sql);

    $stmt->execute([$usuario]);

    $datos = $stmt->fetch(PDO::FETCH_ASSOC);

    // ======================================
    // 6. VERIFICAR CONTRASEÑA
    // ======================================

    if (
        !$datos ||
        !isset($datos['password_hash']) ||
        !is_string($datos['password_hash']) ||
        !password_verify($password, $datos['password_hash'])
    ) {

        header('Location: ' . appUrl('login.php?error=1'));
        exit;
    }

    // ======================================
    // 7. VALIDAR ROL
    // ======================================

    $rolesPermitidos = [
        'Administrador',
        'Vendedor'
    ];

    if (
        !in_array($datos['rol'], $rolesPermitidos, true)
    ) {

        header('Location: ' . appUrl('login.php?error=1'));
        exit;
    }

    // ======================================
    // 8. RENOVAR IDENTIFICADOR DE SESIÓN
    // ======================================

    session_regenerate_id(true);

    // Limpiar datos anteriores de la sesión
    $_SESSION = [];

    // ======================================
    // 9. GUARDAR INFORMACIÓN DEL USUARIO
    // ======================================

    $_SESSION['usuario'] = [

        'id_usuario' => (int)$datos['id_usuario'],

        'id_rol' => (int)$datos['id_rol'],

        'nombre_completo' => $datos['nombre_completo'],

        'usuario' => $datos['usuario'],

        'rol' => $datos['rol']

    ];

    // ======================================
    // 10. REDIRECCIONAR AL DASHBOARD
    // ======================================

    header('Location: ' . appUrl('dashboard.php'));
    exit;

} catch (Throwable $e) {

    // Registrar error técnico
    error_log(
        'Error en AuthController: ' . $e->getMessage()
    );

    header('Location: ' . appUrl('login.php?error=sistema'));
    exit;
}
