
<?php

// ============================================
// CHECKPOINT STORE - PÁGINA PRINCIPAL
// ============================================

// Cargar catálogo de videojuegos
$juegos = require __DIR__ . '/datos/juegos.php';

// Verificar que los datos sean correctos
if (!is_array($juegos)) {
    $juegos = [];
}

// Preparar datos para JavaScript de forma segura
$juegosJson = json_encode(
    $juegos,
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);

if ($juegosJson === false) {
    $juegosJson = '[]';
}

// Videojuego destacado
$juegoDestacado = $juegos[0] ?? [
    'titulo' => 'CheckPoint Store'
];

$tituloDestacado = htmlspecialchars(
    (string) $juegoDestacado['titulo'],
    ENT_QUOTES,
    'UTF-8'
);

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description"
          content="CheckPoint Store - Catálogo de videojuegos, consolas y accesorios.">

    <meta name="theme-color" content="#12062b">

    <title>CheckPoint Store | Catálogo Gamer</title>

    <!-- Estilos principales -->
    <link rel="stylesheet" href="assets/css/inicio.css">

</head>

<body class="inicio">

    <!-- =====================================
         BARRA DE NAVEGACIÓN
    ====================================== -->

    <header class="barra">

        <span class="marca">
            🎮 CHECKPOINT STORE
        </span>

        <!-- FILTROS DE VIDEOJUEGOS -->

        <nav class="filtros" aria-label="Filtrar videojuegos">

            <button type="button"
                    class="activo"
                    data-f="">
                Todos
            </button>

            <button type="button"
                    data-f="playstation">
                PlayStation
            </button>

            <button type="button"
                    data-f="xbox">
                Xbox
            </button>

            <button type="button"
                    data-f="switch">
                Nintendo
            </button>

            <button type="button"
                    data-f="pc">
                PC
            </button>

        </nav>

        <!-- ACCESO AL LOGIN ORIGINAL -->

        <div class="login-wrap">

            <a href="login.php"
               class="btn-login"
               style="display:inline-block;text-decoration:none;">

                👤 Iniciar sesión

            </a>

        </div>

    </header>


    <!-- =====================================
         CONTENIDO PRINCIPAL
    ====================================== -->

    <main class="tienda">

        <!-- =================================
             CATÁLOGO Y CARRUSEL 3D
        ================================== -->

        <section class="banner"
                 aria-labelledby="tituloCatalogo">

            <h2 id="tituloCatalogo">
                CATÁLOGO DESTACADO
            </h2>

            <!-- ESCENARIO DEL CARRUSEL -->

            <div class="escena" id="escena">

                <div class="anillo" id="anillo">

                    <!-- JavaScript generará aquí
                         las tarjetas de videojuegos -->

                </div>

                <!-- BOTÓN ANTERIOR -->

                <button type="button"
                        class="flecha izq"
                        aria-label="Videojuego anterior">

                    ‹

                </button>

                <!-- BOTÓN SIGUIENTE -->

                <button type="button"
                        class="flecha der"
                        aria-label="Videojuego siguiente">

                    ›

                </button>

                <!-- MENSAJE CUANDO NO HAY RESULTADOS -->

                <p class="vacio"
                   role="status">

                    No encontramos videojuegos
                    con esa plataforma.

                </p>

            </div>


            <!-- INFORMACIÓN DEL VIDEOJUEGO SELECCIONADO -->

            <div class="info">

                <h3 id="tituloJuego">
                    Selecciona un videojuego
                </h3>

                <span class="precio"
                      id="precioJuego">

                </span>

                <button type="button"
                        class="btn-detalles">

                    Ver detalles

                </button>

            </div>

        </section>


        <!-- =================================
             PANEL PROMOCIONAL
        ================================== -->

        <aside class="promo">

            <p class="mini">
                EXPERIENCIA GAMER
            </p>

            <div class="gran-vi">
                VI
            </div>

            <p class="promo-txt">
                <?= $tituloDestacado ?>
            </p>

            <p>
                Descubre nuestro catálogo
                de videojuegos y encuentra
                tu próxima aventura.
            </p>

        </aside>

    </main>


    <!-- =====================================
         PIE DE PÁGINA
    ====================================== -->

    <footer class="pie">

        © <?= date('Y') ?> CheckPoint Store.

        Todos los derechos reservados.

        <br>

        Proyecto académico - Tecnología Web I

    </footer>


    <!-- =====================================
         VENTANA DE DETALLES
    ====================================== -->

    <div class="modal"
         id="modalDetalles"
         aria-hidden="true">

        <div class="modal-caja"
             role="dialog"
             aria-modal="true"
             aria-labelledby="mTitulo">

            <!-- BOTÓN CERRAR -->

            <button type="button"
                    class="cerrar"
                    id="cerrarModal"
                    aria-label="Cerrar ventana">

                ✕

            </button>


            <!-- TÍTULO DEL VIDEOJUEGO -->

            <h2 id="mTitulo">
                Videojuego
            </h2>


            <!-- DESCRIPCIÓN -->

            <p class="desc"
               id="mDescripcion">

            </p>


            <!-- PRECIO -->

            <div class="fila">

                <span class="etq">
                    Precio
                </span>

                <strong id="mPrecio">

                </strong>

            </div>


            <!-- PLATAFORMAS -->

            <div class="fila">

                <span class="etq">
                    Plataformas
                </span>

                <div class="chips"
                     id="mPlataformas">

                </div>

            </div>


            <!-- REQUISITOS -->

            <h3>
                Información técnica
            </h3>

            <table id="mReq">

            </table>


            <!-- TRÁILER -->

            <div class="trailer"
                 id="trailer"
                 hidden>

            </div>

        </div>

    </div>


    <!-- =====================================
         DATOS PARA JAVASCRIPT
    ====================================== -->

    <script>

        window.CHECKPOINT_JUEGOS =
            <?= $juegosJson ?>;

        window.CHECKPOINT_IMAGE_PATH =
            'assets/img/juegos/';

    </script>


    <!-- JAVASCRIPT PRINCIPAL -->

    <script src="assets/js/inicio.js" defer></script>

</body>

</html>