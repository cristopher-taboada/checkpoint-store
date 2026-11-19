<?php

require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . appUrl('login.php'));
    exit;
}

$csrf = $_POST['csrf'] ?? '';
$token = $_SESSION['csrf_logout'] ?? '';

if (
    $token === '' ||
    $csrf === '' ||
    !hash_equals($token, $csrf)
) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

// Vaciar sesión
$_SESSION = [];

// Eliminar cookie de sesión
if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destruir sesión
session_destroy();

// Regresar al login
header('Location: ' . appUrl('login.php?logout=1'));
exit;