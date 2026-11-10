/**
 * camara-fotos.js — Cámara propia para sacar varias fotos seguidas (v7.21, reunión del 08/11/2026).
 *
 * Con <input type="file" capture> el teléfono abre su cámara, entrega UNA foto y vuelve a la app:
 * para tres fotos había que abrirla tres veces. Esta cámara usa getUserMedia, queda abierta y saca
 * hasta `max` fotos; «Listo», la X o llegar al máximo las devuelven todas juntas.
 *
 * Uso desde cualquier componente Alpine (el markup se incluye con include_once de
 * views/componentes/camara-fotos.php):
 *   if (camaraFotosDisponible()) { var archivos = await abrirCamaraFotos({ max: 3 }); }
 * Devuelve un arreglo de File JPEG ya comprimidos (lado mayor 1600 px, calidad 0,8: lo mismo que
 * comprimirFotoParaSubir() de app.js), vacío si no sacó ninguna. Sin permiso o sin cámara, la
 * pantalla ofrece la cámara del teléfono (el método de antes) y devuelve esas fotos igual.
 */

var CAMARA_FOTOS_MAX_LADO = 1600;
var CAMARA_FOTOS_CALIDAD = 0.8;

// Fuera del estado de Alpine a propósito: el stream y la promesa pendiente no tienen que pasar
// por el proxy reactivo. Hay una sola cámara por página (include_once).
var camaraFotosStream = null;
var camaraFotosResolver = null;

// getUserMedia solo existe en contexto seguro (https o localhost) y en navegadores que lo traen.
function camaraFotosDisponible() {
    return !!(window.isSecureContext && navigator.mediaDevices
        && typeof navigator.mediaDevices.getUserMedia === 'function');
}

function abrirCamaraFotos(opciones) {
    return new Promise(function (resolve) {
        var detalle = { max: (opciones && opciones.max) || 3, resolver: resolve, atendida: false };
        window.dispatchEvent(new CustomEvent('abrir-camara-fotos', { detail: detalle }));
        // Sin el markup en la página nadie atiende el evento: devolver nada en vez de colgarse.
        if (!detalle.atendida) resolve([]);
    });
}

function camaraFotos() {
    return {
        abierta: false,
        estado: 'cerrada', // 'abriendo' | 'lista' | 'error'
        error: '',
        puedeReintentar: false,
        aviso: '',
        max: 3,
        fotos: [], // [{ file, url }] sacadas en esta apertura
        capturando: false,
        procesando: 0, // fotos de la cámara del teléfono que se están comprimiendo
        destello: false,
        _intento: 0, // cambia al cerrar o al reabrir: un getUserMedia viejo no se queda con la cámara

        abrir(detalle) {
            detalle = detalle || {};
            detalle.atendida = true;
            if (this.abierta) {
                if (detalle.resolver) detalle.resolver([]);
                return;
            }
            this.max = Math.max(1, Math.min(3, Number(detalle.max) || 3));
            camaraFotosResolver = detalle.resolver || null;
            this.fotos = [];
            this.procesando = 0;
            this.aviso = '';
            this.abierta = true;
            this.iniciar();
        },

        async iniciar() {
            this.detener();
            var intento = ++this._intento;
            this.estado = 'abriendo';
            this.error = '';
            this.aviso = '';
            if (!camaraFotosDisponible()) {
                this.mostrarError(null);
                return;
            }
            try {
                var stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: {
                        facingMode: { ideal: 'environment' }, // la de atrás
                        width: { ideal: 1920 },
                        height: { ideal: 1080 },
                    },
                });
                if (intento !== this._intento || !this.abierta) {
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    return;
                }
                camaraFotosStream = stream;
                var video = this.$refs.video;
                video.srcObject = stream;
                try { await video.play(); } catch (e) { /* va muda y con autoplay: igual se ve */ }
                if (intento !== this._intento) return;
                this.estado = 'lista';
            } catch (e) {
                if (intento !== this._intento) return;
                this.mostrarError(e);
            }
        },

        mostrarError(e) {
            var nombre = e && e.name ? e.name : '';
            this.puedeReintentar = true;
            if (!e) {
                this.error = 'Este navegador no deja abrir la cámara aquí. Usa la cámara del teléfono: saca una foto a la vez.';
                this.puedeReintentar = false;
            } else if (nombre === 'NotAllowedError' || nombre === 'SecurityError' || nombre === 'PermissionDeniedError') {
                this.error = 'La app no tiene permiso para usar la cámara. Puedes darlo en los ajustes del navegador o usar la cámara del teléfono (una foto a la vez).';
            } else if (nombre === 'NotFoundError' || nombre === 'DevicesNotFoundError' || nombre === 'OverconstrainedError') {
                this.error = 'No encontramos una cámara en este equipo. Puedes elegir una foto guardada.';
                this.puedeReintentar = false;
            } else if (nombre === 'NotReadableError' || nombre === 'TrackStartError' || nombre === 'AbortError') {
                this.error = 'Otra app está usando la cámara. Ciérrala y toca «Reintentar».';
            } else {
                this.error = 'No pudimos abrir la cámara. Toca «Reintentar» o usa la cámara del teléfono.';
            }
            this.estado = 'error';
        },

        async capturar() {
            if (this.estado !== 'lista' || this.capturando || this.fotos.length >= this.max) return;
            var video = this.$refs.video;
            var ancho = video.videoWidth;
            var alto = video.videoHeight;
            if (!ancho || !alto) return; // todavía no llega el primer cuadro
            this.capturando = true;
            this.aviso = '';
            this.destello = true;
            var self = this;
            setTimeout(function () { self.destello = false; }, 150);
            try {
                var escala = Math.min(1, CAMARA_FOTOS_MAX_LADO / Math.max(ancho, alto));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(ancho * escala) || 1;
                canvas.height = Math.round(alto * escala) || 1;
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                var blob = await new Promise(function (resolve) {
                    canvas.toBlob(resolve, 'image/jpeg', CAMARA_FOTOS_CALIDAD);
                });
                if (!blob) throw new Error('sin imagen');
                if (!this.abierta) return;
                var archivo = new File([blob], 'foto-' + Date.now() + '.jpg', { type: 'image/jpeg' });
                this.fotos.push({ file: archivo, url: URL.createObjectURL(archivo) });
            } catch (e) {
                this.aviso = 'No pudimos sacar la foto. Intenta de nuevo.';
            } finally {
                this.capturando = false;
            }
            if (this.fotos.length >= this.max) this.terminar();
        },

        // La cámara del teléfono (el método de antes), cuando esta no se pudo abrir.
        async onFotoDelTelefono(event) {
            var espacio = this.max - this.fotos.length - this.procesando;
            var archivos = Array.from(event.target.files || []).slice(0, Math.max(0, espacio));
            event.target.value = '';
            var intento = this._intento;
            this.procesando += archivos.length;
            for (var i = 0; i < archivos.length; i++) {
                var comprimido = await comprimirFotoParaSubir(archivos[i], CAMARA_FOTOS_MAX_LADO, CAMARA_FOTOS_CALIDAD);
                if (intento !== this._intento || !this.abierta) return;
                this.fotos.push({ file: comprimido, url: URL.createObjectURL(comprimido) });
                this.procesando = Math.max(0, this.procesando - 1);
            }
            if (this.fotos.length >= this.max) this.terminar();
        },

        quitar(idx) {
            URL.revokeObjectURL(this.fotos[idx].url);
            this.fotos.splice(idx, 1);
        },

        // «Listo», la X o el máximo: las fotos sacadas se entregan, nunca se pierden. Para sacar
        // una que salió mal está la × de su miniatura, aquí o en el formulario.
        terminar() {
            if (!this.abierta || this.procesando > 0) return;
            var archivos = this.fotos.map(function (f) { return f.file; });
            this._intento++;
            this.detener();
            this.fotos.forEach(function (f) { URL.revokeObjectURL(f.url); });
            this.fotos = [];
            this.abierta = false;
            this.estado = 'cerrada';
            var resolver = camaraFotosResolver;
            camaraFotosResolver = null;
            if (resolver) resolver(archivos);
        },

        detener() {
            if (camaraFotosStream) {
                camaraFotosStream.getTracks().forEach(function (t) { t.stop(); });
                camaraFotosStream = null;
            }
            if (this.$refs.video) this.$refs.video.srcObject = null;
        },

        // Al salir de la app el teléfono corta la cámara (iPhone) o la deja prendida gastando
        // batería (Android): se suelta al ocultarse y se vuelve a abrir al volver.
        alCambiarVisibilidad() {
            if (!this.abierta || this.estado === 'error') return;
            if (document.hidden) {
                this._intento++;
                this.detener();
                this.estado = 'abriendo';
            } else {
                this.iniciar();
            }
        },

        textoContador() {
            return this.fotos.length + ' de ' + this.max + (this.max === 1 ? ' foto' : ' fotos');
        },
    };
}
