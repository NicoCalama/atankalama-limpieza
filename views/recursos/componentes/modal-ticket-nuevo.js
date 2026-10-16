/**
 * modal-ticket-nuevo.js — Lógica del modal reutilizable "Nuevo ticket".
 * Ver modal-ticket-nuevo.php para el markup y cómo se abre/consume.
 */

// Plazo para enviar un reporte. Con mala señal (pisos 800/900, datos prepago) el
// "Enviando..." podía girar minutos sin decir nada; pasado este plazo se corta y se
// ofrece reintentar. Holgado a propósito: 3 fotos comprimidas por 3G tardan ~20 s.
var TICKET_PLAZO_ENVIO_MS = 60000;
// Desde cuándo avisar que la señal está lenta (sin cortar todavía).
var TICKET_AVISO_LENTO_MS = 10000;

// Clave de idempotencia de un reporte: se genera al primer envío y se reusa en sus
// reintentos. Si la respuesta se perdió pero el ticket sí se creó, el servidor devuelve
// ese mismo ticket en vez de duplicarlo (TicketService::crear). El servidor acepta
// hasta 64 caracteres.
function nuevaClaveIdempotencia() {
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

// POST multipart con plazo. apiPostForm() no corta nunca, por eso el modal usa esta.
// Devuelve el JSON de la respuesta; lanza AbortError si se cumple el plazo, TypeError si
// no hubo conexión y SyntaxError si el servidor no respondió JSON. Con la sesión vencida
// (401) manda al login, igual que apiFetch().
async function enviarReporteConPlazo(url, formData, plazoMs) {
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var temporizador = ctrl ? setTimeout(function () { ctrl.abort(); }, plazoMs) : null;
    try {
        var resp = await fetch((window.BASE_PATH || '') + url, {
            method: 'POST',
            body: formData,
            signal: ctrl ? ctrl.signal : undefined,
        });
        if (resp.status === 401) {
            window.location.href = (window.BASE_PATH || '') + '/login';
            return { ok: false, error: { mensaje: 'Tu sesión se venció. Vuelve a entrar para enviar el reporte.' } };
        }
        return await resp.json();
    } finally {
        if (temporizador) clearTimeout(temporizador);
    }
}

function modalTicketNuevo(puedeAsignar, puedeEditarPrioridad) {
    return {
        abierto: false,
        enviando: false,
        error: null,
        // Ticket recién creado ({ id, fotosFallidas }). Mientras exista, el modal muestra la
        // confirmación en vez del formulario: antes, al reportar desde la pieza o el Inicio,
        // el modal se cerraba sin decir nada y el trabajador no sabía si había llegado.
        resultado: null,
        idempotencyKey: null,
        senalLenta: false,
        // Fotos que se están comprimiendo: mientras haya alguna no se puede enviar, o esa
        // foto quedaría fuera del reporte sin que nadie lo note.
        procesandoFotos: 0,
        _generacion: 0, // cambia en cada reset(): una compresión vieja no se mete en otro reporte
        puedeAsignar: puedeAsignar,
        puedeEditarPrioridad: puedeEditarPrioridad,
        hoteles: [],
        habitaciones: [],
        usuariosAsignables: [], // cargados on-demand, solo si puedeAsignar
        _datosCargados: false,
        busquedaHabitacion: '',
        abrirBuscador: false,
        habitacionSeleccionadaNumero: null,
        fotos: [], // [{ file, url }] — máx. 3, el server vuelve a validar igual
        form: {
            hotel_id: null,
            habitacion_id: null,
            tipoId: null,
            comentario: '', // v7.9: opcional, máx. 500 — mantención ve el detalle
            asignadoIds: [],
            prioridad: 'normal',
        },

        // Tipo de problema (v7.4, gerencia 10/10/2026: sin cuadros de texto ni dictado): lista «ticket_tipo»
        // de Ajustes → Checklists y listas. Se pide una vez por página.
        tipos: [],
        tiposCargando: false,
        tiposError: '',

        // detail: { habitacionId, habitacionNumero, hotelId, hotelCodigo } — todos opcionales.
        async abrir(detail) {
            this.reset();
            this.abierto = true;
            detail = detail || {};
            // La pieza y el hotel que manda la pantalla se aplican de inmediato, sin esperar
            // las listas: /api/habitaciones exige habitaciones.ver_todas, que un trabajador no
            // tiene, así que al reportar desde la pieza o el Inicio el formulario quedaba sin
            // hotel y "Crear ticket" nunca se habilitaba.
            if (detail.habitacionId) {
                this.form.habitacion_id = Number(detail.habitacionId);
                this.habitacionSeleccionadaNumero = detail.habitacionNumero || null;
            }
            if (detail.hotelId) {
                this.form.hotel_id = Number(detail.hotelId);
            }
            await this.asegurarDatos();
            if (this.form.habitacion_id !== null) {
                var idHab = this.form.habitacion_id;
                var hab = this.habitaciones.find(h => Number(h.id) === idHab);
                if (hab) this.seleccionarHabitacion(hab);
            }
            if (this.form.hotel_id === null && detail.hotelCodigo) {
                var h = this.hoteles.find(x => x.codigo === detail.hotelCodigo);
                if (h) this.form.hotel_id = Number(h.id);
            }
            if (this.form.hotel_id === null && this.hoteles.length === 0) {
                this.error = 'No pudimos cargar la lista de hoteles. Revisa tu señal y vuelve a abrir el formulario.';
            }
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            // Mientras envía no se cierra (ni con la X ni tocando afuera): el resultado
            // quedaría oculto y el trabajador no sabría si el reporte llegó.
            if (this.enviando) return;
            this.abierto = false;
            this.abrirBuscador = false;
            this.limpiarFotos();
        },


        reset() {
            this.form = { hotel_id: null, habitacion_id: null, tipoId: null, comentario: '', asignadoIds: [], prioridad: 'normal' };
            this.error = null;
            this.enviando = false;
            this.resultado = null;
            this.idempotencyKey = null; // reporte nuevo: clave nueva
            this.senalLenta = false;
            this.procesandoFotos = 0;
            this._generacion++;
            this.busquedaHabitacion = '';
            this.abrirBuscador = false;
            this.habitacionSeleccionadaNumero = null;
            this.limpiarFotos();
        },

        async onFotosSeleccionadas(event) {
            var espacio = 3 - this.fotos.length - this.procesandoFotos;
            var archivos = Array.from(event.target.files || []).slice(0, Math.max(0, espacio));
            event.target.value = ''; // permite volver a elegir el mismo archivo si se saca y se agrega de nuevo
            var generacion = this._generacion;
            this.procesandoFotos += archivos.length;
            // Comprimir antes de mostrar/subir — ver comprimirFotoParaSubir() en app.js.
            for (var i = 0; i < archivos.length; i++) {
                var comprimido = await comprimirFotoParaSubir(archivos[i], 1600, 0.8);
                if (generacion !== this._generacion) return; // se abrió otro reporte mientras tanto
                this.fotos.push({ file: comprimido, url: URL.createObjectURL(comprimido) });
                this.procesandoFotos = Math.max(0, this.procesandoFotos - 1);
            }
        },

        quitarFoto(idx) {
            URL.revokeObjectURL(this.fotos[idx].url);
            this.fotos.splice(idx, 1);
        },

        limpiarFotos() {
            this.fotos.forEach(function (f) { URL.revokeObjectURL(f.url); });
            this.fotos = [];
        },

        async cargarTipos() {
            if (this.tipos.length > 0 || this.tiposCargando) return;
            this.tiposCargando = true;
            this.tiposError = '';
            try {
                var r = await apiFetch('/api/listas/ticket_tipo');
                if (r && r.ok) {
                    this.tipos = r.data.opciones || [];
                } else {
                    this.tiposError = (r && r.error && r.error.mensaje) || 'No pudimos cargar los tipos de problema.';
                }
            } catch (e) {
                this.tiposError = 'No pudimos cargar los tipos de problema. Revisa tu señal y vuelve a abrir el formulario.';
            } finally {
                this.tiposCargando = false;
            }
        },

        async asegurarDatos() {
            this.cargarTipos();
            if (this._datosCargados) return;
            try {
                var rh = await apiFetch('/api/hoteles');
                if (rh && rh.ok) this.hoteles = rh.data.hoteles || [];
                var rhab = await apiFetch('/api/habitaciones');
                if (rhab && rhab.ok) this.habitaciones = rhab.data.habitaciones || [];
                if (this.puedeAsignar) {
                    var ru = await apiFetch('/api/tickets/usuarios-asignables');
                    if (ru && ru.ok) this.usuariosAsignables = ru.data.usuarios || [];
                }
                this._datosCargados = true;
            } catch (e) {
                // Sin datos no bloqueamos: user puede elegir hotel manualmente si se cargó
            }
        },

        habitacionesFiltradas() {
            var q = (this.busquedaHabitacion || '').trim().toLowerCase();
            if (!q) return this.habitaciones;
            return this.habitaciones.filter(function (h) {
                return (h.numero || '').toString().toLowerCase().includes(q);
            });
        },

        seleccionarHabitacion(hab) {
            this.form.habitacion_id = hab.id;
            this.form.hotel_id = hab.hotel_id;
            this.habitacionSeleccionadaNumero = hab.numero;
            this.abrirBuscador = false;
            this.busquedaHabitacion = '';
        },

        quitarHabitacion() {
            this.form.habitacion_id = null;
            this.habitacionSeleccionadaNumero = null;
        },

        nombreHotelCorto(codigo) {
            if (codigo === 'inn') return 'Inn';
            if (codigo === '1_sur') return 'Atankalama';
            return codigo || '';
        },

        // Grupos/selección: mismo criterio (agrupar por perfil, alfabético) y mismo patrón
        // de atajos de grupo completo que tickets.js usa para asignar un ticket ya creado —
        // funciones compartidas en app.js para no tener el algoritmo duplicado en dos archivos.
        gruposAsignables() {
            return agruparUsuariosPorPerfil(this.usuariosAsignables);
        },

        toggleResponsable(id) {
            id = Number(id);
            var idx = this.form.asignadoIds.indexOf(id);
            if (idx >= 0) {
                this.form.asignadoIds.splice(idx, 1);
            } else {
                this.form.asignadoIds.push(id);
            }
        },

        estaResponsableSeleccionado(id) {
            return this.form.asignadoIds.indexOf(Number(id)) >= 0;
        },

        asignarGrupo(nombreRol) {
            var ids = filtrarIdsPorRol(this.usuariosAsignables, nombreRol);
            if (ids.length === 0) {
                this.mostrarErrorGrupo(nombreRol);
                return;
            }

            var self = this;
            var todosEstan = ids.every(function (id) {
                return self.form.asignadoIds.indexOf(id) >= 0;
            });

            if (todosEstan) {
                this.form.asignadoIds = this.form.asignadoIds.filter(function (id) {
                    return ids.indexOf(id) === -1;
                });
            } else {
                var nuevoSet = new Set(this.form.asignadoIds.concat(ids));
                this.form.asignadoIds = Array.from(nuevoSet);
            }
        },

        estaGrupoSeleccionado(nombreRol) {
            var ids = filtrarIdsPorRol(this.usuariosAsignables, nombreRol);
            if (ids.length === 0) return false;
            var self = this;
            return ids.every(function (id) {
                return self.form.asignadoIds.indexOf(id) >= 0;
            });
        },

        limpiarResponsables() {
            this.form.asignadoIds = [];
        },

        mostrarErrorGrupo(nombreRol) {
            this.error = 'No hay usuarios activos para el grupo ' + nombreRol + '.';
        },

        formValido() {
            return this.form.hotel_id !== null && this.form.tipoId !== null;
        },

        textoBotonEnviar() {
            if (this.enviando) return 'Enviando...';
            if (this.procesandoFotos > 0) return 'Procesando foto...';
            // Ya hubo un intento que no se confirmó: el mismo reporte, con la misma clave.
            return this.idempotencyKey ? 'Reintentar' : 'Crear ticket';
        },

        async crear() {
            if (!this.formValido() || this.enviando || this.procesandoFotos > 0) return;
            this.enviando = true;
            this.error = null;
            this.senalLenta = false;
            if (!this.idempotencyKey) this.idempotencyKey = nuevaClaveIdempotencia();
            var self = this;
            var avisoLento = setTimeout(function () { self.senalLenta = true; }, TICKET_AVISO_LENTO_MS);
            try {
                // El servidor arma el título con el nombre del tipo elegido (v7.4); la descripción es el
                // comentario si lo escribieron (v7.9) o, si no, el mismo nombre del tipo.
                var datos = new FormData();
                datos.append('hotel_id', this.form.hotel_id);
                datos.append('tipo_id', this.form.tipoId);
                if (this.form.comentario.trim() !== '') datos.append('comentario', this.form.comentario.trim());
                datos.append('prioridad', this.puedeEditarPrioridad ? this.form.prioridad : 'normal');
                if (this.form.habitacion_id !== null) datos.append('habitacion_id', this.form.habitacion_id);
                if (this.puedeAsignar) {
                    this.form.asignadoIds.forEach(function (id) { datos.append('usuario_ids[]', id); });
                }
                this.fotos.forEach(function (f) { datos.append('fotos[]', f.file); });
                datos.append('idempotency_key', this.idempotencyKey);

                var r = await enviarReporteConPlazo('/api/tickets', datos, TICKET_PLAZO_ENVIO_MS);
                if (r && r.ok) {
                    // adjuntos_fallidos no aborta la creación (ver TicketsController::crear):
                    // la confirmación avisa cuántas fotos no subieron.
                    var fallidas = r.data.adjuntos_fallidos || [];
                    this.$dispatch('ticket-creado', Object.assign({}, r.data.ticket, {
                        _adjuntos_fallidos: fallidas,
                    }));
                    this.resultado = { id: r.data.ticket.id, fotosFallidas: fallidas.length };
                    this.idempotencyKey = null;
                    this.limpiarFotos();
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos crear el ticket.';
                }
            } catch (e) {
                // Plazo cumplido, sin conexión o respuesta que no es JSON: no sabemos si llegó.
                // Reintentar es seguro porque va con la misma clave de idempotencia.
                this.error = e && e.name === 'AbortError'
                    ? 'La señal está muy lenta y no alcanzamos a confirmar el envío. Toca «Reintentar»: si el reporte ya había llegado, no se va a duplicar.'
                    : 'No pudimos confirmar el envío. Revisa tu señal y toca «Reintentar»: si el reporte ya había llegado, no se va a duplicar.';
            } finally {
                clearTimeout(avisoLento);
                this.enviando = false;
                this.senalLenta = false;
            }
        }
    };
}
