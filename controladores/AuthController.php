<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/conexion.php';

// Comprobar que venga del formulario
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . appUrl('login.php'));
    exit;
}

// Comprobar token de seguridad
$csrf = $_POST['csrf'] ?? '';
$token = $_SESSION['csrf_login'] ?? '';

if (
    $token === '' ||
    $csrf === '' ||
    !hash_equals($token, $csrf)
) {
    header('Location: ' . appUrl('login.php?error=csrf'));
    exit;
}

// Recibir datos
$usuario = trim($_POST['usuario'] ?? '');
$password = $_POST['password'] ?? '';

if ($usuario === '' || $password === '') {
    header('Location: ' . appUrl('login.php?error=1'));
    exit;
}

try {

    // Buscar usuario y rol
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

    // Verificar contraseña
    if (
        !$datos ||
        !password_verify($password, $datos['password_hash'])
    ) {
        header('Location: ' . appUrl('login.php?error=1'));
        exit;
    }

    // Aceptar únicamente los dos roles previstos
    if (!in_array($datos['rol'], ['Administrador', 'Vendedor'], true)) {
        header('Location: ' . appUrl('login.php?error=1'));
        exit;
    }

    // Renovar sesión
    session_regenerate_id(true);

    // Guardar información del usuario
    $_SESSION['usuario'] = [

        'id_usuario' => (int)$datos['id_usuario'],

        'id_rol' => (int)$datos['id_rol'],

        'nombre_completo' => $datos['nombre_completo'],

        'usuario' => $datos['usuario'],

        'rol' => $datos['rol']

    ];

    unset($_SESSION['csrf_login']);

    // Redirigir al panel
    header('Location: ' . appUrl('dashboard.php'));
    exit;

} catch (Throwable $e) {

    error_log('Error de login: ' . $e->getMessage());

    header('Location: ' . appUrl('login.php?error=sistema'));
    exit;
}