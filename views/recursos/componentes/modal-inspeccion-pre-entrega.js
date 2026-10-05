// Inspección pre-entrega (v6.18) — ventana que se abre desde el botón de la tarjeta en Habitaciones.
// Spec: docs/revision-entrega.md. Servido por PaginasController::servirRecurso (nada va al docroot).
//
// Paso 1: «¿La habitación está en condiciones para entregarse a un cliente?» SÍ / NO.
// Paso 2 (NO): el checklist de motivos (uno), observaciones y foto.

// Plazo de un envío y desde cuándo avisar que la señal está lenta (sin cortar todavía).
var INSPECCION_PLAZO_MS = 45000;
var INSPECCION_AVISO_LENTO_MS = 10000;
// Cuánto vale la clave de un envío que quedó sin respuesta, para reusarla si Recepción vuelve a
// mandar lo mismo después de cerrar y reabrir la ventana.
var INSPECCION_PENDIENTE_MS = 10 * 60 * 1000;

// Envíos sin respuesta (señal mala), por pieza: { firma, clave, t }. Si se vuelve a mandar LA MISMA
// respuesta (misma firma) en los próximos minutos, va con la misma clave y el servidor lo reconoce como
// reintento: no duplica la fila ni el aviso. Si cambia la respuesta, va con clave nueva. El servidor
// además ignora una clave vieja si ya hay una revisión más nueva de la pieza (reintentoVigente()).
var inspeccionPendientes = {};

// Misma implementación que la clave de los tickets (modal-ticket-nuevo.js), con otro nombre para no
// pisarse: los dos archivos se cargan en la misma página. El servidor acepta hasta 64 caracteres.
function claveInspeccionPreEntrega() {
    try {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    } catch (e) {
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }
}

// POST multipart con plazo. Devuelve { status, json } (json null si no vino JSON); lanza si no hubo
// respuesta (sin conexión o se cumplió el plazo). Con la sesión vencida (401) manda al login.
async function postInspeccionConPlazo(url, formData, plazoMs) {
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var temporizador = ctrl ? setTimeout(function () { ctrl.abort(); }, plazoMs) : null;
    try {
        var resp = await fetch((window.BASE_PATH || '') + url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            signal: ctrl ? ctrl.signal : undefined
        });
        if (resp.status === 401) {
            window.location.href = (window.BASE_PATH || '') + '/login';
            return null;
        }
        var json = null;
        try { json = await resp.json(); } catch (e) { json = null; }
        return { status: resp.status, json: json };
    } finally {
        if (temporizador) clearTimeout(temporizador);
    }
}

function modalInspeccionPreEntrega() {
    return {
        abierto: false,
        paso: 'pregunta', // 'pregunta' | 'no'
        hab: null,

        motivos: [],
        noEnsucia: false,
        formularioListo: false,
        cargandoFormulario: false,
        errorFormulario: '',

        motivoId: null,
        comentario: '',
        foto: null,
        fotoPreview: null,
        procesandoFoto: false,
        // Sube con cada apertura/cierre: una foto que termina de comprimirse después de cambiar de
        // pieza se descarta en vez de colarse en el NO de otra.
        gen: 0,

        enviando: null, // 'si' | 'no' | null
        lento: false,
        error: '',
        reintentar: false,
        claves: {}, // firma -> clave, solo mientras la ventana está abierta

        toast: { visible: false, mensaje: '', tipo: 'ok' },
        toastTimer: null,

        abrir(detalle) {
            if (!detalle || !detalle.habitacion || this.enviando) return;
            this.gen++;
            this.hab = detalle.habitacion;
            this.paso = 'pregunta';
            this.motivoId = null;
            this.comentario = '';
            this.quitarFoto();
            this.procesandoFoto = false;
            this.lento = false;
            this.error = '';
            this.reintentar = false;
            this.claves = {};
            this.abierto = true;
            this.cargarFormulario();
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            if (!this.abierto || this.enviando) return;
            this.abierto = false;
            this.gen++;
            this.quitarFoto();
            this.procesandoFoto = false;
        },

        async cargarFormulario() {
            if (this.cargandoFormulario) return;
            this.cargandoFormulario = true;
            this.errorFormulario = '';
            try {
                var r = await apiFetch('/api/revision-entrega/formulario');
                if (r && r.ok) {
                    this.motivos = r.data.motivos || [];
                    this.noEnsucia = !!r.data.no_ensucia;
                    this.formularioListo = true;
                } else {
                    this.errorFormulario = (r && r.error && r.error.mensaje) || 'No pudimos cargar los motivos.';
                }
            } catch (e) {
                this.errorFormulario = 'No pudimos cargar los motivos. Revisa la señal.';
            } finally {
                this.cargandoFormulario = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        irANo() {
            if (this.enviando) return;
            this.paso = 'no';
            this.error = '';
            this.reintentar = false;
            if (!this.formularioListo) this.cargarFormulario();
            this.$nextTick(function () { lucide.createIcons(); });
        },

        volver() {
            if (this.enviando) return;
            this.paso = 'pregunta';
            this.error = '';
            this.reintentar = false;
        },

        firma(resultado) {
            return resultado === 'si' ? 'si' : 'no:' + this.motivoId;
        },

        claveDe(firma) {
            if (this.claves[firma]) return this.claves[firma];
            var p = inspeccionPendientes[this.hab.id];
            if (p && p.firma === firma && Date.now() - p.t < INSPECCION_PENDIENTE_MS) {
                this.claves[firma] = p.clave;
                return p.clave;
            }
            this.claves[firma] = claveInspeccionPreEntrega();
            return this.claves[firma];
        },

        async enviar(resultado) {
            if (this.enviando || !this.hab) return;
            if (resultado === 'no' && (this.motivoId === null || this.procesandoFoto)) return;

            var hab = this.hab;
            var firma = this.firma(resultado);
            var clave = this.claveDe(firma);
            var fd = new FormData();
            fd.append('habitacion_id', String(hab.id));
            fd.append('resultado', resultado);
            fd.append('idempotency_key', clave);
            if (resultado === 'no') {
                fd.append('motivo_id', String(this.motivoId));
                fd.append('comentario', this.comentario || '');
                if (this.foto) fd.append('foto', this.foto, this.foto.name || 'foto.jpg');
            }

            this.enviando = resultado;
            this.error = '';
            this.reintentar = false;
            this.lento = false;
            var self = this;
            var avisoLento = setTimeout(function () { self.lento = true; }, INSPECCION_AVISO_LENTO_MS);
            try {
                var r = await postInspeccionConPlazo('/api/revision-entrega', fd, INSPECCION_PLAZO_MS);
                if (r === null) return; // sesión vencida: ya va al login
                if (r.json && r.json.ok) {
                    delete inspeccionPendientes[hab.id];
                    window.dispatchEvent(new CustomEvent('inspeccion-pre-entrega-registrada', {
                        detail: { habitacion_id: hab.id, revision: r.json.data.revision }
                    }));
                    this.enviando = null;
                    this.abierto = false;
                    this.gen++;
                    this.quitarFoto();
                    if (r.json.data.foto_fallida) {
                        this.mostrarToast('El aviso llegó, pero la foto no se pudo subir: ' + r.json.data.foto_fallida, 'aviso', 5000);
                    } else if (resultado === 'si') {
                        this.mostrarToast('Habitación ' + hab.numero + ' aprobada ✓', 'ok', 2500);
                    } else {
                        this.mostrarToast('Aviso enviado a las supervisoras', 'ok', 2500);
                    }
                    return;
                }
                if (r.json && r.json.error && r.status < 500) {
                    // Respuesta clara del servidor (validación, permiso, foto muy pesada): no se guardó nada.
                    this.error = r.json.error.mensaje || 'No pudimos guardar la inspección, intenta de nuevo.';
                    this.reintentar = r.json.error.codigo === 'CUERPO_MUY_GRANDE';
                    return;
                }
                if (r.status === 413) {
                    // El hosting cortó el envío antes de llegar a la app: no se guardó.
                    this.error = 'La foto es muy pesada para el servidor. Quítala y vuelve a enviar.';
                    this.reintentar = true;
                    return;
                }
                throw new Error('respuesta incierta');
            } catch (e) {
                // Sin respuesta clara (señal, plazo, error del servidor): no sabemos si se guardó. La clave
                // queda guardada: si se reintenta lo mismo, el servidor no duplica.
                inspeccionPendientes[hab.id] = { firma: firma, clave: clave, t: Date.now() };
                this.error = resultado === 'si'
                    ? 'No pudimos confirmar si se guardó. Revisa la señal y vuelve a tocar SÍ: si ya había llegado, no se duplica.'
                    : 'No pudimos confirmar si se guardó. Revisa la señal y toca Reintentar: si ya había llegado, no se duplica.';
                this.reintentar = true;
            } finally {
                clearTimeout(avisoLento);
                this.lento = false;
                if (this.enviando === resultado) this.enviando = null;
            }
        },

        quitarFoto() {
            if (this.fotoPreview) {
                try { URL.revokeObjectURL(this.fotoPreview); } catch (e) { /* nada */ }
            }
            this.foto = null;
            this.fotoPreview = null;
        },

        async onFoto(evento) {
            var archivo = evento.target.files && evento.target.files[0];
            evento.target.value = '';
            if (!archivo) return;
            var gen = this.gen;
            this.procesandoFoto = true;
            try {
                var comprimida = await comprimirFotoParaSubir(archivo, 1600, 0.8);
                if (gen !== this.gen) return; // se cerró o se cambió de pieza mientras comprimía
                this.quitarFoto();
                this.foto = comprimida;
                this.fotoPreview = URL.createObjectURL(comprimida);
            } finally {
                if (gen === this.gen) this.procesandoFoto = false;
            }
        },

        hotelNombre(codigo) {
            return codigo === 'inn' ? 'Atankalama INN' : 'Atankalama';
        },

        // La inspección vigente (la pieza no cambió de estado desde entonces): «Ya se revisó: aprobada ·
        // 14:32 · Carla» si fue hoy, o con la fecha («· 04/10 18:10 ·») si es de otro día.
        textoRevisionVigente() {
            var r = this.hab && this.hab.revision_vigente;
            if (!r) return '';
            var nombre = String(r.usuario_nombre || '').trim().split(/\s+/)[0] || '';
            var que = r.resultado === 'si' ? 'aprobada' : 'no aprobada (' + (r.motivo_nombre || '') + ')';
            var p = String(r.fecha_local || '').split('-');
            var cuando = r.fecha_local === window.hoyServidor()
                ? r.hora_local
                : (p.length === 3 ? p[2] + '/' + p[1] : r.fecha_local) + ' ' + r.hora_local;
            return 'Ya se revisó: ' + que + ' · ' + cuando + (nombre ? ' · ' + nombre : '') + '. Puedes revisarla de nuevo.';
        },

        mostrarToast(mensaje, tipo, ms) {
            var self = this;
            this.toast = { visible: true, mensaje: mensaje, tipo: tipo || 'ok' };
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(function () { self.toast.visible = false; }, ms || 2500);
        }
    };
}
