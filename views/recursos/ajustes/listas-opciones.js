/**
 * Ajustes → Checklists y listas → pestaña «Listas» (v7.3). La vista es views/ajustes/listas-opciones.php,
 * que vive dentro de un <template x-if>: por eso el componente se define acá y se carga afuera (un <script>
 * insertado por x-if no se ejecuta).
 */
function listasOpcionesApp() {
    // Qué es cada lista y quién la ve (el orden de las tarjetas). Las de Recepción (mensaje a la mucama)
    // y de tickets se suman en la v7.4.
    var LISTAS = {
        rechazo:      { id: 'rechazo',      icono: 'x-circle',         titulo: 'Rechazar una habitación',         quien: 'la supervisora', quienCorto: 'la supervisora', cuando: 'al rechazar en la inspección, además de marcar los ítems fallidos', ejemplo: 'Baño sucio' },
        reasignar:    { id: 'reasignar',    icono: 'arrow-left-right', titulo: 'Cambiar de persona',              quien: 'la supervisora', quienCorto: 'la supervisora', cuando: 'al reasignar una habitación, también al arrastrarla en el tablero', ejemplo: 'Sobrecarga de trabajo' },
        saltar:       { id: 'saltar',       icono: 'skip-forward',     titulo: 'No puedo limpiarla ahora',        quien: 'la trabajadora', quienCorto: 'la trabajadora', cuando: 'cuando no puede entrar o terminar una habitación de su cola', ejemplo: 'Falta un insumo' },
        no_recepcion: { id: 'no_recepcion', icono: 'concierge-bell',   titulo: 'NO de la inspección pre-entrega', quien: 'Recepción',      quienCorto: 'Recepción',      cuando: 'al marcar NO antes de entregar la pieza', ejemplo: 'Mal olor' },
    };

    return {
        resumen: null,      // { lista: {activas, total} }
        cargando: false,
        error: '',
        grupos: [
            { titulo: 'Supervisora', listas: [LISTAS.rechazo, LISTAS.reasignar] },
            { titulo: 'Trabajadora', listas: [LISTAS.saltar] },
            { titulo: 'Recepción',   listas: [LISTAS.no_recepcion] },
        ],
        editor: {
            abierto: false, lista: null, opciones: [], cargando: false, error: '', guardando: false,
            editandoId: null, editandoNombre: '', nuevoNombre: '', seq: 0,
        },
        toast: { visible: false, mensaje: '', tipo: 'ok' },
        toastTimer: null,

        async cargar() {
            this.cargando = true;
            this.error = '';
            try {
                var r = await apiFetch('/api/listas');
                if (!r || !r.ok) {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos cargar las listas, intenta de nuevo en un momento.';
                    return;
                }
                var resumen = {};
                (r.data.listas || []).forEach(function (l) { resumen[l.lista] = l; });
                this.resumen = resumen;
            } catch (e) {
                this.error = 'No pudimos cargar las listas, intenta de nuevo en un momento.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        conteo(id) {
            var l = this.resumen && this.resumen[id];
            if (!l) return '';
            var activas = l.activas + (l.activas === 1 ? ' opción activa' : ' opciones activas');
            var inactivas = l.total - l.activas;
            return inactivas > 0 ? activas + ' · ' + inactivas + ' desactivada' + (inactivas === 1 ? '' : 's') : activas;
        },

        abrir(lista) {
            this.editor.abierto = true;
            this.editor.lista = lista;
            this.editor.opciones = [];
            this.editor.editandoId = null;
            this.editor.nuevoNombre = '';
            this.cargarOpciones();
        },

        cerrar() {
            if (this.editor.guardando) return;
            this.editor.abierto = false;
            this.editor.editandoId = null;
            this.cargar(); // los conteos de las tarjetas
        },

        async cargarOpciones() {
            if (!this.editor.lista) return;
            var seq = ++this.editor.seq;
            this.editor.cargando = true;
            this.editor.error = '';
            try {
                var r = await apiFetch('/api/listas/' + this.editor.lista.id + '?todos=1');
                if (seq !== this.editor.seq) return;
                if (r && r.ok) {
                    this.editor.opciones = r.data.opciones || [];
                } else {
                    this.editor.error = (r && r.error && r.error.mensaje) || 'No pudimos cargar las opciones.';
                }
            } catch (e) {
                if (seq === this.editor.seq) this.editor.error = 'No pudimos cargar las opciones, intenta de nuevo en un momento.';
            } finally {
                if (seq === this.editor.seq) {
                    this.editor.cargando = false;
                    this.$nextTick(function () { lucide.createIcons(); });
                }
            }
        },

        mostrarToast(mensaje, tipo) {
            var self = this;
            this.toast = { visible: true, mensaje: mensaje, tipo: tipo || 'ok' };
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(function () { self.toast.visible = false; }, 2500);
        },

        errorDe(r, porDefecto) {
            return (r && r.error && r.error.mensaje) || porDefecto;
        },

        async agregar() {
            var nombre = this.editor.nuevoNombre.trim();
            if (nombre === '' || this.editor.guardando) return;
            this.editor.guardando = true;
            try {
                var r = await apiPost('/api/listas/' + this.editor.lista.id, { nombre: nombre });
                if (r && r.ok) {
                    this.editor.nuevoNombre = '';
                    await this.cargarOpciones();
                    this.mostrarToast('Opción agregada');
                } else {
                    this.mostrarToast(this.errorDe(r, 'No pudimos agregar la opción.'), 'error');
                }
            } catch (e) {
                this.mostrarToast('No pudimos agregar la opción, intenta de nuevo en un momento.', 'error');
            } finally {
                this.editor.guardando = false;
            }
        },

        editar(o) {
            this.editor.editandoId = o.id;
            this.editor.editandoNombre = o.nombre;
            this.$nextTick(function () { lucide.createIcons(); });
        },

        async guardarNombre(o) {
            var nombre = this.editor.editandoNombre.trim();
            if (nombre === '' || nombre === o.nombre) {
                this.editor.editandoId = null;
                return;
            }
            try {
                var r = await apiPut('/api/listas/' + this.editor.lista.id + '/' + o.id, { nombre: nombre });
                if (r && r.ok) {
                    o.nombre = nombre;
                    this.editor.editandoId = null;
                    this.mostrarToast('Guardado');
                } else {
                    this.mostrarToast(this.errorDe(r, 'No pudimos guardar tu cambio.'), 'error');
                }
            } catch (e) {
                this.mostrarToast('No pudimos guardar tu cambio, intenta de nuevo en un momento.', 'error');
            }
        },

        // Optimista: el interruptor cambia al toque y vuelve atrás si el servidor no acepta
        // (por ejemplo, la última opción activa de la lista).
        async alternarActivo(o) {
            var nuevo = !o.activo;
            o.activo = nuevo;
            try {
                var r = await apiPut('/api/listas/' + this.editor.lista.id + '/' + o.id, { activo: nuevo });
                if (!r || !r.ok) throw new Error(this.errorDe(r, ''));
                this.mostrarToast(nuevo ? 'Opción activada' : 'Opción desactivada');
            } catch (e) {
                o.activo = !nuevo;
                this.mostrarToast(e.message || 'No pudimos guardar tu cambio, intenta de nuevo en un momento.', 'error');
            }
        },

        async mover(i, delta) {
            var j = i + delta;
            var ops = this.editor.opciones;
            if (j < 0 || j >= ops.length || this.editor.guardando) return;
            var nuevo = ops.slice();
            var tmp = nuevo[i]; nuevo[i] = nuevo[j]; nuevo[j] = tmp;
            var anterior = ops;
            this.editor.opciones = nuevo;
            this.editor.guardando = true;
            try {
                var r = await apiPut('/api/listas/' + this.editor.lista.id + '/orden', { ids: nuevo.map(function (o) { return o.id; }) });
                if (!r || !r.ok) throw new Error(this.errorDe(r, ''));
            } catch (e) {
                this.editor.opciones = anterior;
                this.mostrarToast(e.message || 'No pudimos guardar el orden, intenta de nuevo en un momento.', 'error');
                await this.cargarOpciones();
            } finally {
                this.editor.guardando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },
    };
}
