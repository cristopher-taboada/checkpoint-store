
<?php

// ==========================================
// 1. CONFIGURACIÓN DE SESIÓN
// ==========================================

require_once __DIR__ . '/../config/auth.php';

// ==========================================
// 2. VALIDAR MÉTODO HTTP
// ==========================================

$loginUrl = appUrl('login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: ' . $loginUrl);
    exit;
}

// ==========================================
// 3. VALIDAR TOKEN CSRF
// ==========================================

$csrf = $_POST['csrf'] ?? null;
$token = $_SESSION['csrf_logout'] ?? null;

if (
    !is_string($csrf) ||
    !is_string($token) ||
    $token === '' ||
    !hash_equals($token, $csrf)
) {

    http_response_code(403);

    exit('Solicitud no válida.');
}

// ==========================================
// 4. ELIMINAR DATOS DE SESIÓN
// ==========================================

$_SESSION = [];

// ==========================================
// 5. ELIMINAR COOKIE DE SESIÓN
// ==========================================

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

// ==========================================
// 6. DESTRUIR SESIÓN
// ==========================================

session_destroy();

// ==========================================
// 7. REDIRECCIONAR AL LOGIN
// ==========================================

header('Location: ' . $loginUrl . '?logout=1');

exit;
