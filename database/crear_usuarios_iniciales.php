<?php

require_once __DIR__ . '/../config/conexion.php';

try {

    $conexion->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $conexion->beginTransaction();

    // REGISTRAR ROLES
    $roles = [

        'Administrador' => 'Acceso administrativo al sistema.',

        'Vendedor' => 'Acceso a operaciones de venta.'

    ];

    $buscarRol = $conexion->prepare(
        "SELECT id_rol FROM roles WHERE nombre = ? LIMIT 1"
    );

    $insertarRol = $conexion->prepare(
        "INSERT INTO roles (nombre, descripcion, estado)
         VALUES (?, ?, 1)"
    );

    $actualizarRol = $conexion->prepare(
        "UPDATE roles SET descripcion = ?, estado = 1
         WHERE id_rol = ?"
    );

    $ids = [];

    foreach ($roles as $nombre => $descripcion) {

        $buscarRol->execute([$nombre]);

        $id = $buscarRol->fetchColumn();

        if ($id) {

            $actualizarRol->execute([
                $descripcion,
                $id
            ]);

        } else {

            $insertarRol->execute([
                $nombre,
                $descripcion
            ]);

            $id = $conexion->lastInsertId();
        }

        $ids[$nombre] = (int)$id;
    }

    // CUENTAS DEL SISTEMA
    $cuentas = [

        [
            'usuario' => 'Admin@checkpoint',
            'nombre' => 'Administrador CheckPoint',
            'rol' => 'Administrador'
        ],

        [
            'usuario' => 'Vendedor@chekpoint',
            'nombre' => 'Vendedor CheckPoint',
            'rol' => 'Vendedor'
        ]

    ];

    $buscarUsuario = $conexion->prepare(
        "SELECT id_usuario FROM usuarios WHERE usuario = ? LIMIT 1"
    );

    $insertarUsuario = $conexion->prepare(
        "INSERT INTO usuarios
        (id_rol, nombre_completo, usuario, password_hash, estado)
        VALUES (?, ?, ?, ?, 1)"
    );

    $actualizarUsuario = $conexion->prepare(
        "UPDATE usuarios
         SET id_rol = ?,
             nombre_completo = ?,
             password_hash = ?,
             estado = 1
         WHERE id_usuario = ?"
    );

    foreach ($cuentas as $cuenta) {

        $hash = password_hash('1234', PASSWORD_DEFAULT);

        $idRol = $ids[$cuenta['rol']];

        $buscarUsuario->execute([
            $cuenta['usuario']
        ]);

        $idUsuario = $buscarUsuario->fetchColumn();

        if ($idUsuario) {

            $actualizarUsuario->execute([
                $idRol,
                $cuenta['nombre'],
                $hash,
                $idUsuario
            ]);

            $accion = 'Actualizada';

        } else {

            $insertarUsuario->execute([
                $idRol,
                $cuenta['nombre'],
                $cuenta['usuario'],
                $hash
            ]);

            $accion = 'Creada';
        }

        echo htmlspecialchars($accion . ': ' . $cuenta['usuario']) . '<br>';
    }

    $conexion->commit();

    echo '<h2>Usuarios configurados correctamente.</h2>';

} catch (Throwable $e) {

    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    error_log($e->getMessage());

    http_response_code(500);

    echo 'Error al configurar los usuarios. Revisa la conexión y las tablas.';
}