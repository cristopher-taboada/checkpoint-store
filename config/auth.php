<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Obtener la ruta principal del proyecto
function appBasePath(): string {

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
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

// Generar rutas del proyecto
function appUrl(string $ruta = ''): string {
    return appBasePath() . '/' . ltrim($ruta, '/');
}

// Verificar inicio de sesión
function requireLogin(): void {

    if (empty($_SESSION['usuario']['id_usuario'])) {
        header('Location: ' . appUrl('login.php'));
        exit;
    }
}

// Verificar permisos
function requireRole(string ...$rolesPermitidos): void {

    requireLogin();

    $rolActual = strtolower(trim(
        $_SESSION['usuario']['rol'] ?? ''
    ));

    $roles = array_map('strtolower', $rolesPermitidos);

    if (!in_array($rolActual, $roles, true)) {

        http_response_code(403);

        exit('No tienes permisos para acceder a este módulo.');
    }
}