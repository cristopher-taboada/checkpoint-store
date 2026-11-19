
document.addEventListener('DOMContentLoaded', () => {

    // ==========================================
    // 1. CONFIGURACIÓN INICIAL
    // ==========================================

    const juegos = Array.isArray(window.CHECKPOINT_JUEGOS)
        ? window.CHECKPOINT_JUEGOS
        : [];

    const rutaImagenes = window.CHECKPOINT_IMAGE_PATH
        || 'assets/img/juegos/';

    const anillo = document.querySelector('.anillo');
    const escena = document.querySelector('.escena');
    const banner = document.querySelector('.banner');

    const botonAnterior = document.querySelector('.flecha.izq');
    const botonSiguiente = document.querySelector('.flecha.der');

    const botonesFiltro = document.querySelectorAll('.filtros [data-f]');

    const mensajeVacio = document.querySelector('.vacio');

    const informacion = document.querySelector('.info');
    const tituloJuego = document.getElementById('tituloJuego');
    const precioJuego = document.getElementById('precioJuego');

    const botonDetalles = document.querySelector('.btn-detalles');

    // Elementos de la ventana de detalles

    const modal = document.getElementById('modalDetalles');
    const cerrarModal = document.getElementById('cerrarModal');

    const modalTitulo = document.getElementById('mTitulo');
    const modalDescripcion = document.getElementById('mDescripcion');
    const modalPrecio = document.getElementById('mPrecio');

    const modalPlataformas = document.getElementById('mPlataformas');
    const modalRequisitos = document.getElementById('mReq');

    const trailer = document.getElementById('trailer');

    // ==========================================
    // 2. VARIABLES DEL CARRUSEL
    // ==========================================

    let juegosFiltrados = [...juegos];
    let indiceActual = 0;
    let filtroActual = '';

    let tarjetas = [];
    let angulo = 0;
    let radio = 0;

    // ==========================================
    // 3. FUNCIONES AUXILIARES
    // ==========================================

    function normalizar(texto) {
        return String(texto || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    }

    function crearTexto(elemento, texto) {
        if (elemento) {
            elemento.textContent = texto ?? '';
        }
    }

    function obtenerImagen(nombre) {
        return rutaImagenes + encodeURIComponent(nombre || '');
    }

    // ==========================================
    // 4. CREAR TARJETAS DEL CARRUSEL
    // ==========================================

    function crearTarjeta(juego, indice) {

        const tarjeta = document.createElement('div');
        tarjeta.className = 'portada';
        tarjeta.dataset.indice = indice;

        const ancho = Math.min(
            250,
            Math.max(160, (escena.clientWidth || 800) * 0.28)
        );

        const alto = ancho * 1.38;

        tarjeta.style.setProperty('--w', ancho + 'px');
        tarjeta.style.setProperty('--h', alto + 'px');

        const cara = document.createElement('div');
        cara.className = 'cara';

        const imagen = document.createElement('img');
        imagen.src = obtenerImagen(juego.imagen);
        imagen.alt = juego.titulo || 'Videojuego';
        imagen.loading = 'lazy';

        const falta = document.createElement('div');
        falta.className = 'falta';
        falta.textContent = juego.titulo || 'Videojuego';
        falta.hidden = true;

        imagen.addEventListener('error', () => {
            imagen.hidden = true;
            falta.hidden = false;
        });

        imagen.addEventListener('load', () => {
            imagen.hidden = false;
            falta.hidden = true;
        });

        cara.append(imagen, falta);

        // Reflejo de la portada

        const espejo = document.createElement('div');
        espejo.className = 'espejo';

        const imagenEspejo = document.createElement('img');
        imagenEspejo.src = obtenerImagen(juego.imagen);
        imagenEspejo.alt = '';

        espejo.appendChild(imagenEspejo);

        tarjeta.append(cara, espejo);

        tarjeta.addEventListener('click', () => {
            indiceActual = indice;
            actualizarCarrusel();
            mostrarDetalles(juego);
        });

        return tarjeta;
    }

    // ==========================================
    // 5. CALCULAR LA POSICIÓN 3D
    // ==========================================

    function posicionarTarjetas() {

        const cantidad = tarjetas.length;

        if (cantidad === 0) return;

        const ancho = Math.min(
            250,
            Math.max(160, (escena.clientWidth || 800) * 0.28)
        );

        const alto = ancho * 1.38;

        angulo = cantidad > 1 ? 360 / cantidad : 0;

        radio = cantidad > 1
            ? Math.max(
                ancho * 1.15,
                (ancho / 2) / Math.tan(Math.PI / cantidad)
            )
            : 0;

        tarjetas.forEach((tarjeta, indice) => {

            tarjeta.style.setProperty('--w', ancho + 'px');
            tarjeta.style.setProperty('--h', alto + 'px');

            tarjeta.style.transform =
                `rotateY(${indice * angulo}deg) translateZ(${radio}px)`;

        });

        actualizarCarrusel();
    }

    // ==========================================
    // 6. ACTUALIZAR CARRUSEL
    // ==========================================

    function actualizarCarrusel() {

        if (!anillo || tarjetas.length === 0) return;

        const cantidad = tarjetas.length;

        indiceActual = Math.max(
            0,
            Math.min(indiceActual, cantidad - 1)
        );

        anillo.style.transform =
            `rotateY(${-indiceActual * angulo}deg)`;

        tarjetas.forEach((tarjeta, indice) => {

            tarjeta.dataset.frente =
                indice === indiceActual ? '1' : '0';

            tarjeta.style.setProperty(
                '--b',
                indice === indiceActual ? '1' : '0.65'
            );

        });

        actualizarInformacion();
    }

    // ==========================================
    // 7. MOSTRAR INFORMACIÓN DEL JUEGO
    // ==========================================

    function actualizarInformacion() {

        const juego = juegosFiltrados[indiceActual];

        if (!juego) return;

        crearTexto(tituloJuego, juego.titulo);
        crearTexto(precioJuego, juego.precio);

        if (informacion) {
            informacion.classList.remove('oculto');
        }

        if (banner) {
            banner.style.boxShadow =
                '0 0 30px rgba(255,46,147,0.12)';
        }
    }

    // ==========================================
    // 8. AVANZAR Y RETROCEDER
    // ==========================================

    function siguiente() {

        if (juegosFiltrados.length <= 1) return;

        indiceActual =
            (indiceActual + 1) % juegosFiltrados.length;

        actualizarCarrusel();
    }

    function anterior() {

        if (juegosFiltrados.length <= 1) return;

        indiceActual =
            (indiceActual - 1 + juegosFiltrados.length)
            % juegosFiltrados.length;

        actualizarCarrusel();
    }

    botonSiguiente?.addEventListener('click', siguiente);
    botonAnterior?.addEventListener('click', anterior);

    // ==========================================
    // 9. FILTRAR VIDEOJUEGOS
    // ==========================================

    function coincideFiltro(juego, filtro) {

        if (!filtro) return true;

        const plataformas = (juego.plataformas || [])
            .map(normalizar);

        switch (filtro) {

            case 'playstation':
                return plataformas.some(p => p.includes('playstation'));

            case 'xbox':
                return plataformas.some(p => p.includes('xbox'));

            case 'switch':
                return plataformas.some(p => p.includes('switch'));

            case 'pc':
                return plataformas.some(p =>
                    p === 'pc' || p.includes('windows')
                );

            default:
                return true;
        }
    }

    function aplicarFiltro(filtro) {

        filtroActual = filtro;

        juegosFiltrados = juegos.filter(juego =>
            coincideFiltro(juego, filtro)
        );

        indiceActual = 0;

        botonesFiltro.forEach(boton => {
            boton.classList.toggle(
                'activo',
                boton.dataset.f === filtro
            );
        });

        construirCarrusel();
    }

    botonesFiltro.forEach(boton => {

        boton.addEventListener('click', () => {
            aplicarFiltro(boton.dataset.f || '');
        });

    });

    // ==========================================
    // 10. CONSTRUIR EL CARRUSEL COMPLETO
    // ==========================================

    function construirCarrusel() {

        if (!anillo || !escena) return;

        anillo.replaceChildren();

        tarjetas = [];

        if (juegosFiltrados.length === 0) {

            mensajeVacio?.classList.add('visible');
            informacion?.classList.add('oculto');

            if (botonDetalles) {
                botonDetalles.disabled = true;
            }

            if (botonAnterior) botonAnterior.disabled = true;
            if (botonSiguiente) botonSiguiente.disabled = true;

            return;
        }

        mensajeVacio?.classList.remove('visible');
        informacion?.classList.remove('oculto');

        if (botonDetalles) {
            botonDetalles.disabled = false;
        }

        if (botonAnterior) botonAnterior.disabled =
            juegosFiltrados.length <= 1;

        if (botonSiguiente) botonSiguiente.disabled =
            juegosFiltrados.length <= 1;

        juegosFiltrados.forEach((juego, indice) => {

            const tarjeta = crearTarjeta(juego, indice);

            tarjetas.push(tarjeta);
            anillo.appendChild(tarjeta);

        });

        posicionarTarjetas();
    }

    // ==========================================
    // 11. ABRIR VENTANA DE DETALLES
    // ==========================================

    function mostrarDetalles(juego) {

        if (!modal || !juego) return;

        crearTexto(modalTitulo, juego.titulo);
        crearTexto(modalDescripcion, juego.descripcion);
        crearTexto(modalPrecio, juego.precio);

        // Plataformas

        if (modalPlataformas) {

            modalPlataformas.replaceChildren();

            (juego.plataformas || []).forEach(plataforma => {

                const chip = document.createElement('span');
                chip.textContent = plataforma;

                modalPlataformas.appendChild(chip);

            });
        }

        // Requisitos técnicos

        if (modalRequisitos) {

            modalRequisitos.replaceChildren();

            const requisitos = juego.requisitos || {};

            Object.entries(requisitos).forEach(([clave, valor]) => {

                const fila = document.createElement('tr');

                const nombre = document.createElement('td');
                nombre.textContent = clave;

                const detalle = document.createElement('td');
                detalle.textContent = valor;

                fila.append(nombre, detalle);
                modalRequisitos.appendChild(fila);

            });
        }

        // Tráiler opcional

        if (trailer) {

            trailer.replaceChildren();

            const video = obtenerVideoId(juego.youtube);

            if (video) {

                const iframe = document.createElement('iframe');

                iframe.src =
                    'https://www.youtube-nocookie.com/embed/' + video;

                iframe.title = 'Tráiler de ' + juego.titulo;
                iframe.allowFullscreen = true;
                iframe.loading = 'lazy';

                trailer.appendChild(iframe);
                trailer.hidden = false;

            } else {
                trailer.hidden = true;
            }
        }

        modal.classList.add('abierto');
        modal.setAttribute('aria-hidden', 'false');

        document.body.style.overflow = 'hidden';
    }

    // ==========================================
    // 12. IDENTIFICAR VIDEO DE YOUTUBE
    // ==========================================

    function obtenerVideoId(url) {

        if (!url) return null;

        const coincidencia = String(url).match(
            /(?:youtube\.com\/(?:watch\?.*v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
        );

        return coincidencia ? coincidencia[1] : null;
    }

    // ==========================================
    // 13. CERRAR VENTANA DE DETALLES
    // ==========================================

    function cerrarVentana() {

        if (!modal) return;

        modal.classList.remove('abierto');
        modal.setAttribute('aria-hidden', 'true');

        document.body.style.overflow = '';
    }

    cerrarModal?.addEventListener('click', cerrarVentana);

    modal?.addEventListener('click', evento => {

        if (evento.target === modal) {
            cerrarVentana();
        }

    });

    document.addEventListener('keydown', evento => {

        if (evento.key === 'Escape') {
            cerrarVentana();
        }

        if (
            evento.target.matches('input, textarea, select') ||
            modal?.classList.contains('abierto')
        ) {
            return;
        }

        if (evento.key === 'ArrowRight') siguiente();
        if (evento.key === 'ArrowLeft') anterior();

    });

    // ==========================================
    // 14. BOTÓN DE VER DETALLES
    // ==========================================

    botonDetalles?.addEventListener('click', () => {

        const juego = juegosFiltrados[indiceActual];

        if (juego) {
            mostrarDetalles(juego);
        }

    });

    // ==========================================
    // 15. INICIAR EL CATÁLOGO
    // ==========================================

    aplicarFiltro('');

    // Recalcular el carrusel cuando cambia el tamaño
    // de la ventana.

    window.addEventListener('resize', () => {

        if (tarjetas.length > 0) {
            posicionarTarjetas();
        }

    });

});
