/**
 * modal-ticket-nuevo.js — Lógica del modal reutilizable "Nuevo ticket".
 * Ver modal-ticket-nuevo.php para el markup y cómo se abre/consume.
 */
function modalTicketNuevo(puedeAsignar, puedeEditarPrioridad) {
    return {
        abierto: false,
        enviando: false,
        error: null,
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
            descripcion: '',
            asignadoIds: [],
            prioridad: 'normal',
        },

        // Dictado por voz de la descripción (Web Speech API) — mismo patrón que
        // auditoria-detalle.php (comentario de observación/rechazo).
        grabando: false,
        reconocimiento: null,
        dictadoReinicios: 0,

        get soportaDictado() {
            return !!(window.SpeechRecognition || window.webkitSpeechRecognition);
        },

        async abrir(detail) {
            this.reset();
            this.abierto = true;
            await this.asegurarDatos();
            if (detail && detail.habitacionId) {
                var hab = this.habitaciones.find(h => h.id === detail.habitacionId);
                if (hab) this.seleccionarHabitacion(hab);
                else this.form.habitacion_id = detail.habitacionId;
            }
            if (detail && detail.hotelCodigo) {
                var h = this.hoteles.find(x => x.codigo === detail.hotelCodigo);
                if (h) this.form.hotel_id = h.id;
            }
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            this.detenerDictado();
            this.abierto = false;
            this.abrirBuscador = false;
            this.limpiarFotos();
        },

        // Dicta la descripción por voz. El texto reconocido se agrega al que ya haya
        // (no lo reemplaza), para poder mezclar teclado y voz. Ver auditoria-detalle.php,
        // mismo patrón (reinicio automático en Safari/iOS, tope de seguridad, permisos).
        toggleDictado() {
            if (this.grabando) {
                this.detenerDictado();
                return;
            }
            if (!this.soportaDictado) return;

            var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            var reco = new SpeechRecognition();
            var self = this;
            reco.lang = 'es-CL';
            reco.continuous = true;
            reco.interimResults = false;

            reco.onresult = function (event) {
                var textoNuevo = '';
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    if (event.results[i].isFinal) {
                        textoNuevo += event.results[i][0].transcript;
                    }
                }
                textoNuevo = textoNuevo.trim();
                if (textoNuevo === '') return;
                self.dictadoReinicios = 0; // hubo voz real: el contador de seguridad se reinicia
                var actual = self.form.descripcion.trim();
                self.form.descripcion = (actual === '' ? textoNuevo : actual + ' ' + textoNuevo).slice(0, 500);
            };

            reco.onerror = function (event) {
                // 'no-speech' (pausa) y 'aborted' (nuestro propio stop) son normales:
                // no cortan el dictado, los maneja onend.
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    self.grabando = false;
                    self.error = 'Permiso de micrófono denegado.';
                } else if (event.error !== 'no-speech' && event.error !== 'aborted') {
                    self.grabando = false;
                    self.error = 'No pudimos usar el micrófono.';
                }
            };

            reco.onend = function () {
                // Safari/iOS ignora continuous y corta al primer silencio. Mientras no se
                // toque "detener", reanudamos para poder hacer pausas y seguir hablando.
                if (!self.grabando) return;
                if (self.dictadoReinicios >= 30) { self.grabando = false; return; } // tope: micrófono en silencio
                self.dictadoReinicios++;
                setTimeout(function () {
                    if (!self.grabando) return;
                    try { reco.start(); } catch (e) { self.grabando = false; }
                }, 250);
            };

            this.reconocimiento = reco;
            this.dictadoReinicios = 0;
            this.grabando = true;
            reco.start();
        },

        detenerDictado() {
            this.grabando = false;
            this.dictadoReinicios = 0;
            if (this.reconocimiento) {
                try { this.reconocimiento.stop(); } catch (e) {}
            }
        },

        reset() {
            this.form = { hotel_id: null, habitacion_id: null, descripcion: '', asignadoIds: [], prioridad: 'normal' };
            this.error = null;
            this.enviando = false;
            this.busquedaHabitacion = '';
            this.abrirBuscador = false;
            this.habitacionSeleccionadaNumero = null;
            this.limpiarFotos();
        },

        async onFotosSeleccionadas(event) {
            var espacio = 3 - this.fotos.length;
            var archivos = Array.from(event.target.files || []).slice(0, espacio);
            event.target.value = ''; // permite volver a elegir el mismo archivo si se saca y se agrega de nuevo
            // Comprimir antes de mostrar/subir — ver comprimirFotoParaSubir() en app.js.
            for (var i = 0; i < archivos.length; i++) {
                var comprimido = await comprimirFotoParaSubir(archivos[i], 1600, 0.8);
                this.fotos.push({ file: comprimido, url: URL.createObjectURL(comprimido) });
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

        async asegurarDatos() {
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
            return this.form.hotel_id !== null
                && (this.form.descripcion || '').trim().length > 0;
        },

        async crear() {
            if (!this.formValido() || this.enviando) return;
            this.enviando = true;
            this.error = null;
            try {
                var descripcion = (this.form.descripcion || '').trim();
                // Título auto-generado desde la descripción (primeros 80 chars, sin saltos de línea)
                var titulo = descripcion.replace(/\s+/g, ' ').slice(0, 80);

                var datos = new FormData();
                datos.append('hotel_id', this.form.hotel_id);
                datos.append('titulo', titulo);
                datos.append('descripcion', descripcion);
                datos.append('prioridad', this.puedeEditarPrioridad ? this.form.prioridad : 'normal');
                if (this.form.habitacion_id !== null) datos.append('habitacion_id', this.form.habitacion_id);
                if (this.puedeAsignar) {
                    this.form.asignadoIds.forEach(function (id) { datos.append('usuario_ids[]', id); });
                }
                this.fotos.forEach(function (f) { datos.append('fotos[]', f.file); });

                var r = await apiPostForm('/api/tickets', datos);
                if (r && r.ok) {
                    // adjuntos_fallidos no aborta la creación (ver TicketsController::crear) — se
                    // relaya en el detail del evento para que la página lo muestre en el toast.
                    var detalle = Object.assign({}, r.data.ticket, {
                        _adjuntos_fallidos: r.data.adjuntos_fallidos || [],
                    });
                    this.$dispatch('ticket-creado', detalle);
                    this.cerrar();
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos crear el ticket.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.enviando = false;
            }
        }
    };
}
