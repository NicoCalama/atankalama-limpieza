// «Re-limpiar» (v7) — la supervisora manda a re-limpiar una pieza aprobada que Recepción no aprobó
// para entregar. Spec: docs/revision-entrega.md. Servido por PaginasController::servirRecurso.
//
// Lista las trabajadoras con turno hoy en el hotel de la pieza (la misma vista de Asignaciones) y
// asigna con POST /api/revision-entrega/{id}/relimpiar { trabajador_id, prioridad }.

function modalRelimpiarEntrega(puedePriorizar) {
    return {
        abierto: false,
        hab: null,
        revision: null,
        trabajadoras: [],
        cargando: false,
        errorCarga: null,
        elegida: null,
        puedePriorizar: !!puedePriorizar,
        // DEFAULT APLICADO (aprobado por el usuario, 05/10/2026): con prioridad por defecto, hay un huésped esperando.
        prioridad: !!puedePriorizar,
        enviando: false,
        error: null,
        toast: '',
        seq: 0,

        abrir(detalle) {
            if (!detalle || !detalle.revision || !detalle.habitacion) return;
            this.hab = detalle.habitacion;
            this.revision = detalle.revision;
            this.elegida = null;
            this.prioridad = this.puedePriorizar;
            this.error = null;
            this.enviando = false;
            this.abierto = true;
            this.cargarTrabajadoras();
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            if (this.enviando) return;
            this.abierto = false;
        },

        async cargarTrabajadoras() {
            var seq = ++this.seq;
            this.cargando = true;
            this.errorCarga = null;
            this.trabajadoras = [];
            try {
                // Con los dos hoteles para que la carga de cada una sea la total (con un hotel, la vista
                // corta su cola a ese hotel); quiénes aparecen se filtra acá con la misma regla de la vista:
                // su hotel por defecto es el de la pieza o «ambos».
                var hotel = this.hab.hotel_codigo || 'ambos';
                var r = await apiFetch('/api/asignaciones/vista?hotel=ambos&fecha=' + encodeURIComponent(window.hoyServidor()));
                if (seq !== this.seq) return;
                if (!r || !r.ok) {
                    this.errorCarga = (r && r.error && r.error.mensaje) || 'No pudimos cargar a las trabajadoras.';
                    return;
                }
                this.trabajadoras = (r.data.trabajadores || []).filter(function (t) {
                    var suyo = t.usuario.hotel_default;
                    return hotel === 'ambos' || suyo === hotel || suyo === 'ambos';
                }).map(function (t) {
                    var p = t.progreso || {};
                    return {
                        id: t.usuario.id,
                        nombre: t.usuario.nombre,
                        porHacer: (p.pendientes || 0) + (p.en_progreso || 0) + (p.rechazadas || 0),
                        enCurso: (p.en_progreso || 0) > 0
                    };
                });
            } catch (e) {
                if (seq === this.seq) this.errorCarga = 'No pudimos conectar con el servidor.';
            } finally {
                if (seq === this.seq) this.cargando = false;
            }
        },

        async enviar() {
            if (this.elegida === null || this.enviando) return;
            this.enviando = true;
            this.error = null;
            try {
                var r = await apiPost('/api/revision-entrega/' + this.revision.id + '/relimpiar', {
                    trabajador_id: this.elegida,
                    prioridad: this.puedePriorizar && this.prioridad
                });
                if (!r || !r.ok) {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos asignar la re-limpieza, intenta de nuevo en un momento.';
                    return;
                }
                var elegida = this.elegida;
                var t = this.trabajadoras.find(function (x) { return x.id === elegida; });
                var nombre = t ? t.nombre : '';
                window.dispatchEvent(new CustomEvent('relimpieza-pedida', {
                    detail: { habitacion_id: this.hab.id, revision: r.data.revision, trabajador_nombre: nombre }
                }));
                this.enviando = false;
                this.abierto = false;
                this.mostrarToast('Re-limpieza de la ' + this.hab.numero + ' asignada a ' + (nombre.split(' ')[0] || 'la trabajadora') + ' ✓');
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor. Revisa la señal e intenta de nuevo.';
            } finally {
                this.enviando = false;
            }
        },

        textoMotivo() {
            if (!this.revision) return '';
            var quien = String(this.revision.usuario_nombre || '').trim().split(/\s+/)[0] || '';
            return (this.revision.motivo_nombre || '') + (quien ? ' · revisó ' + quien : '');
        },

        textoCarga(t) {
            if (t.porHacer === 0) return 'Sin piezas pendientes';
            return t.porHacer + (t.porHacer === 1 ? ' pieza por hacer' : ' piezas por hacer') + (t.enCurso ? ' · limpiando una ahora' : '');
        },

        mostrarToast(mensaje) {
            var self = this;
            this.toast = mensaje;
            setTimeout(function () { if (self.toast === mensaje) self.toast = ''; }, 3500);
        }
    };
}
