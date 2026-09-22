/**
 * tickets.js — Lógica modular para la vista de Tickets de mantenimiento.
 * Spec: docs/tickets.md
 * Atankalama Limpieza
 */

function ticketsApp() {
    return {
        tickets: [],
        total: 0,
        cargando: false,
        sinConexion: !navigator.onLine,
        estado: localStorage.getItem('tickets_estado') || 'abierto',
        hotel: localStorage.getItem('tickets_hotel') || 'ambos',
        alcance: localStorage.getItem('tickets_alcance'),
        _intervalId: null,

        toast: { visible: false, tipo: 'exito', mensaje: '' },

        detalle: {
            abierto: false,
            ticket: null,
            enviando: false,
            mostrarCierre: false,
            mostrarAsignar: false,
            asignarUsuarioIds: [],
            mostrarPrioridad: false,
            prioridadNueva: null,
            comentarios: [],
            comentarioNuevo: '',
            avisarSupervisora: false,
            enviandoComentario: false,
        },
        cierreFotos: [], // [{ file, url }] — fotos opcionales al cerrar, máx. 3
        usuariosAsignables: [], // cargados on-demand la primera vez que se abre el panel de asignar

        estadosFiltro: [
            { valor: '', etiqueta: 'Todos' },
            { valor: 'abierto', etiqueta: 'Abiertos' },
            { valor: 'en_progreso', etiqueta: 'En progreso' },
            { valor: 'cerrado', etiqueta: 'Cerrados' }
        ],

        hotelesFiltro: [
            { valor: 'ambos', etiqueta: 'Ambos' },
            { valor: '1_sur', etiqueta: 'Atankalama' },
            { valor: 'inn', etiqueta: 'Atankalama INN' }
        ],

        alcanceFiltroPropio: [
            { valor: 'mios', etiqueta: 'Asignados a mí' },
            { valor: 'sin_asignar', etiqueta: 'Sin asignar' }
        ],
        alcanceFiltroGestion: [
            { valor: 'sin_asignar', etiqueta: 'Sin asignar' },
            { valor: 'mios', etiqueta: 'Asignados a mí' },
            { valor: 'todos', etiqueta: 'Todos' }
        ],
        get alcanceFiltro() {
            return this.puedeVerTodos ? this.alcanceFiltroGestion : this.alcanceFiltroPropio;
        },

        get puedeCrear() {
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.crear'));
        },
        get puedeVerTodos() {
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.ver_todos'));
        },
        get puedeGestionar() {
            return this.puedeVerTodos;
        },
        get puedeEditarPrioridad() {
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.editar_prioridad'));
        },
        get puedeResolverAsignado() {
            var yo = Alpine.store('auth') && Alpine.store('auth').usuario;
            if (!yo || !this.detalle.ticket) return false;
            if (this.detalle.ticket.responsables && this.detalle.ticket.responsables.length > 0) {
                return this.detalle.ticket.responsables.some(function (r) {
                    return Number(r.id) === Number(yo.id);
                });
            }
            return Number(this.detalle.ticket.asignado_a) === Number(yo.id);
        },
        get puedeTomar() {
            if (!this.detalle.ticket || this.detalle.ticket.estado !== 'abierto') return false;
            // Solo tickets sin dueño, también para quien gestiona: tomar() manda [yo] y reemplazaría
            // en silencio a todo el equipo asignado. Para sumarse a un equipo: «Asignar responsable».
            var sinDuenio = (!this.detalle.ticket.responsables || this.detalle.ticket.responsables.length === 0) && !this.detalle.ticket.asignado_a;
            if (!sinDuenio) return false;
            if (this.puedeGestionar) return true;
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.ver_propios'));
        },
        get responsablesOcultos() {
            // Responsables actuales que no aparecen en la lista de personas asignables (inactivos,
            // o de un perfil que quien edita no puede designar). Siguen en la selección: el backend
            // los conserva (y saca a los inactivos). Acá solo se avisa que existen.
            if (!this.detalle.ticket || this.usuariosAsignables.length === 0) return [];
            var visibles = this.usuariosAsignables.map(function (u) { return Number(u.id); });
            var seleccion = this.detalle.asignarUsuarioIds;
            return (this.detalle.ticket.responsables || []).filter(function (r) {
                return seleccion.indexOf(Number(r.id)) >= 0 && visibles.indexOf(Number(r.id)) < 0;
            });
        },

        async cargar() {
            this.cargando = true;
            var auth = Alpine.store('auth');
            if (auth && !auth.cargado) {
                await auth.cargar();
            }
            var alcancesValidos = this.alcanceFiltro.map(function (a) { return a.valor; });
            if (alcancesValidos.indexOf(this.alcance) === -1) {
                this.alcance = this.puedeVerTodos ? 'sin_asignar' : 'mios';
                localStorage.setItem('tickets_alcance', this.alcance);
            }
            try {
                var params = [];
                if (this.estado) params.push('estado=' + encodeURIComponent(this.estado));
                if (this.puedeVerTodos && this.hotel && this.hotel !== 'ambos') params.push('hotel=' + encodeURIComponent(this.hotel));
                params.push('alcance=' + encodeURIComponent(this.alcance));
                var url = '/api/tickets' + (params.length ? '?' + params.join('&') : '');
                var r = await apiFetch(url);
                if (r && r.ok) {
                    this.tickets = r.data.tickets || [];
                    this.total = r.data.total || 0;
                }
            } catch (e) {
                // Silencioso
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        iniciarRefresco() {
            var self = this;
            this._intervalId = setInterval(function () { self.cargar(); }, 60000);
            window.addEventListener('online', function () { self.sinConexion = false; self.cargar(); });
            window.addEventListener('offline', function () { self.sinConexion = true; });
        },

        alVolverVisible() {
            if (!document.hidden) this.cargar();
        },

        setEstado(valor) {
            this.estado = valor;
            localStorage.setItem('tickets_estado', valor);
            this.cargar();
        },

        setHotel(valor) {
            this.hotel = valor;
            localStorage.setItem('tickets_hotel', valor);
            this.cargar();
        },

        setAlcance(valor) {
            this.alcance = valor;
            localStorage.setItem('tickets_alcance', valor);
            this.cargar();
        },

        onTicketCreado(ticket) {
            var fallidas = (ticket && ticket._adjuntos_fallidos) || [];
            if (fallidas.length > 0) {
                this.mostrarToast('error', 'Ticket creado, pero ' + fallidas.length + ' foto(s) no se pudieron subir.');
            } else {
                this.mostrarToast('exito', 'Ticket creado. Gracias por reportar.');
            }
            this.cargar();
        },

        async abrirDesdeUrl() {
            var id = parseInt(new URLSearchParams(window.location.search).get('ticket'), 10);
            if (!id) return;
            var enLista = this.tickets.find(function (t) { return t.id === id; });
            if (enLista) {
                this.abrirDetalle(enLista);
                return;
            }
            try {
                var r = await apiFetch('/api/tickets/' + id);
                if (r && r.ok) this.abrirDetalle(r.data.ticket);
            } catch (e) {
                // Deep-link no crítico
            }
        },

        async abrirDetalle(t) {
            this.detalle = {
                abierto: true,
                ticket: t,
                enviando: false,
                mostrarCierre: false,
                mostrarAsignar: false,
                asignarUsuarioIds: [],
                mostrarPrioridad: false,
                prioridadNueva: null,
                comentarios: [],
                comentarioNuevo: '',
                avisarSupervisora: false,
                enviandoComentario: false,
            };
            this.$nextTick(function () { lucide.createIcons(); });

            try {
                var r = await apiFetch('/api/tickets/' + t.id);
                if (r && r.ok && this.detalle.ticket && this.detalle.ticket.id === t.id) {
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, {
                        adjuntos: r.data.adjuntos,
                        responsables: r.data.ticket.responsables || this.detalle.ticket.responsables || []
                    });
                }
            } catch (e) {
                // Sin adjuntos
            }
            try {
                var rc = await apiFetch('/api/tickets/' + t.id + '/comentarios');
                if (rc && rc.ok && this.detalle.ticket && this.detalle.ticket.id === t.id) {
                    this.detalle.comentarios = rc.data.comentarios || [];
                }
            } catch (e) {
                // Sin comentarios
            }
        },

        cerrarDetalle() {
            this.limpiarCierreFotos();
            this.detalle = {
                abierto: false,
                ticket: null,
                enviando: false,
                mostrarCierre: false,
                mostrarAsignar: false,
                asignarUsuarioIds: [],
                mostrarPrioridad: false,
                prioridadNueva: null,
                comentarios: [],
                comentarioNuevo: '',
                avisarSupervisora: false,
                enviandoComentario: false,
            };
        },

        async comentar() {
            if (!this.detalle.ticket || !this.detalle.comentarioNuevo || this.detalle.enviandoComentario) return;
            this.detalle.enviandoComentario = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/comentarios', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        comentario: this.detalle.comentarioNuevo,
                        avisar_supervisora: this.detalle.avisarSupervisora,
                    })
                });
                if (r && r.ok) {
                    this.detalle.comentarios = r.data.comentarios || [];
                    this.detalle.comentarioNuevo = '';
                    this.detalle.avisarSupervisora = false;
                    this.mostrarToast('exito', 'Comentario agregado.');
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos agregar el comentario.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviandoComentario = false;
            }
        },

        async abrirAsignar() {
            var asignadosActuales = [];
            if (this.detalle.ticket && this.detalle.ticket.responsables && this.detalle.ticket.responsables.length > 0) {
                asignadosActuales = this.detalle.ticket.responsables.map(function (r) { return Number(r.id); });
            } else if (this.detalle.ticket && this.detalle.ticket.asignado_a) {
                asignadosActuales = [Number(this.detalle.ticket.asignado_a)];
            }
            this.detalle.asignarUsuarioIds = asignadosActuales;
            this.detalle.mostrarAsignar = true;

            if (this.usuariosAsignables.length === 0) {
                try {
                    var r = await apiFetch('/api/tickets/usuarios-asignables');
                    if (r && r.ok) this.usuariosAsignables = r.data.usuarios || [];
                } catch (e) {
                    this.mostrarToast('error', 'No pudimos cargar la lista de personas.');
                }
            }
        },

        gruposAsignables() {
            return agruparUsuariosPorPerfil(this.usuariosAsignables);
        },

        toggleResponsable(id) {
            id = Number(id);
            var idx = this.detalle.asignarUsuarioIds.indexOf(id);
            if (idx >= 0) {
                this.detalle.asignarUsuarioIds.splice(idx, 1);
            } else {
                this.detalle.asignarUsuarioIds.push(id);
            }
        },

        estaResponsableSeleccionado(id) {
            return this.detalle.asignarUsuarioIds.indexOf(Number(id)) >= 0;
        },

        asignarGrupo(nombreRol) {
            var ids = filtrarIdsPorRol(this.usuariosAsignables, nombreRol);

            if (ids.length === 0) {
                this.mostrarToast('error', 'No hay usuarios activos para el grupo ' + nombreRol + '.');
                return;
            }

            var self = this;
            var todosEstan = ids.every(function (id) {
                return self.detalle.asignarUsuarioIds.indexOf(id) >= 0;
            });

            if (todosEstan) {
                this.detalle.asignarUsuarioIds = this.detalle.asignarUsuarioIds.filter(function (id) {
                    return ids.indexOf(id) === -1;
                });
            } else {
                var nuevoSet = new Set(this.detalle.asignarUsuarioIds.concat(ids));
                this.detalle.asignarUsuarioIds = Array.from(nuevoSet);
            }
        },

        estaGrupoSeleccionado(nombreRol) {
            var ids = filtrarIdsPorRol(this.usuariosAsignables, nombreRol);

            if (ids.length === 0) return false;
            var self = this;
            return ids.every(function (id) {
                return self.detalle.asignarUsuarioIds.indexOf(id) >= 0;
            });
        },

        limpiarResponsables() {
            this.detalle.asignarUsuarioIds = [];
        },

        async asignarResponsables() {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            if (this.detalle.asignarUsuarioIds.length === 0) {
                this.mostrarToast('error', 'Selecciona al menos un responsable.');
                return;
            }

            this.detalle.enviando = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/asignar', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ usuario_ids: this.detalle.asignarUsuarioIds })
                });
                if (r && r.ok) {
                    var respNuevos = r.data.ticket.responsables || [];
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, r.data.ticket, {
                        responsables: respNuevos,
                        asignado_a_nombre: respNuevos.length > 0 ? this.formatearResponsables(respNuevos) : null
                    });
                    this.detalle.mostrarAsignar = false;
                    this.mostrarToast('exito', 'Responsables actualizados.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos asignar responsables.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        abrirPrioridad() {
            this.detalle.prioridadNueva = this.detalle.ticket.prioridad;
            this.detalle.mostrarPrioridad = true;
        },

        async guardarPrioridad() {
            if (!this.detalle.ticket || !this.detalle.prioridadNueva || this.detalle.enviando) return;
            this.detalle.enviando = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/prioridad', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ prioridad: this.detalle.prioridadNueva })
                });
                if (r && r.ok) {
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, r.data.ticket);
                    this.detalle.mostrarPrioridad = false;
                    this.mostrarToast('exito', 'Prioridad actualizada.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos cambiar la prioridad.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        urlAdjunto(ruta) {
            return (window.BASE_PATH || '') + '/uploads/' + ruta;
        },

        async onCierreFotoSeleccionada(event) {
            var espacio = 3 - this.cierreFotos.length;
            var archivos = Array.from(event.target.files || []).slice(0, espacio);
            event.target.value = '';
            for (var i = 0; i < archivos.length; i++) {
                var comprimido = await comprimirFotoParaSubir(archivos[i], 1600, 0.8);
                this.cierreFotos.push({ file: comprimido, url: URL.createObjectURL(comprimido) });
            }
        },

        quitarCierreFoto(idx) {
            URL.revokeObjectURL(this.cierreFotos[idx].url);
            this.cierreFotos.splice(idx, 1);
        },

        limpiarCierreFotos() {
            this.cierreFotos.forEach(function (f) { URL.revokeObjectURL(f.url); });
            this.cierreFotos = [];
        },

        cancelarCierre() {
            this.detalle.mostrarCierre = false;
            this.limpiarCierreFotos();
        },

        async confirmarCierre() {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            this.detalle.enviando = true;
            try {
                var datos = new FormData();
                this.cierreFotos.forEach(function (f) { datos.append('fotos[]', f.file); });
                var r = await apiPostForm('/api/tickets/' + this.detalle.ticket.id + '/cerrar', datos);
                if (r && r.ok) {
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, r.data.ticket, { adjuntos: r.data.adjuntos });
                    this.detalle.mostrarCierre = false;
                    this.limpiarCierreFotos();
                    var fallidas = r.data.adjuntos_fallidos || [];
                    this.mostrarToast(
                        fallidas.length > 0 ? 'error' : 'exito',
                        fallidas.length > 0 ? 'Ticket cerrado, pero ' + fallidas.length + ' foto(s) no se pudieron subir.' : 'Ticket cerrado.'
                    );
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos cerrar el ticket.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        async tomar() {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            var usuarioId = Alpine.store('auth') && Alpine.store('auth').usuario && Alpine.store('auth').usuario.id;
            if (!usuarioId) return;
            this.detalle.enviando = true;
            try {
                var rA = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/asignar', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ usuario_ids: [usuarioId] })
                });
                if (!rA || !rA.ok) {
                    this.mostrarToast('error', (rA && rA.error && rA.error.mensaje) || 'No pudimos tomar el ticket.');
                    return;
                }
                // cambiarEstado() tiene su propio guard de "enviando": hay que liberarlo antes de
                // encadenarlo, si no sale sin hacer nada y el ticket tomado se queda en 'abierto'.
                this.detalle.enviando = false;
                await this.cambiarEstado('en_progreso');
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        async cambiarEstado(nuevo) {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            this.detalle.enviando = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/estado', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ estado: nuevo })
                });
                if (r && r.ok) {
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, r.data.ticket);
                    this.mostrarToast('exito', 'Ticket actualizado.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos actualizar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        },

        // --- Helpers visuales ---

        etiquetaAlcanceActual() {
            var op = this.alcanceFiltro.find(function (a) { return a.valor === this.alcance; }, this);
            return op ? op.etiqueta : '';
        },

        nombreHotelCorto(codigo) {
            if (codigo === 'inn') return 'Atankalama INN';
            if (codigo === '1_sur') return 'Atankalama';
            return codigo || '';
        },

        etiquetaPrioridad(p) {
            if (p === 'urgente') return 'Urgente';
            if (p === 'alta') return 'Alta';
            if (p === 'normal') return 'Normal';
            return 'Baja';
        },

        etiquetaEstado(e) {
            if (e === 'abierto') return 'Abierto';
            if (e === 'en_progreso') return 'En progreso';
            if (e === 'resuelto') return 'Resuelto';
            return 'Cerrado';
        },

        claseBordePrioridad(p) {
            if (p === 'urgente') return 'border-l-4 border-l-red-600';
            if (p === 'alta') return 'border-l-4 border-l-amber-500';
            if (p === 'normal') return 'border-l-4 border-l-blue-500';
            return '';
        },

        claseBadgePrioridad(p) {
            if (p === 'urgente') return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
            if (p === 'alta') return 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300';
            if (p === 'normal') return 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300';
            return 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300';
        },

        claseBadgeEstado(e) {
            if (e === 'abierto') return 'bg-rose-100 dark:bg-rose-900/30 text-rose-800 dark:text-rose-300';
            if (e === 'en_progreso') return 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300';
            if (e === 'resuelto') return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
            return 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400';
        },

        formatearResponsables(responsables) {
            if (!responsables || !responsables.length) return '';
            if (responsables.length === 1) return responsables[0].nombre;
            if (responsables.length === 2) return responsables[0].nombre + ', ' + responsables[1].nombre;
            return responsables[0].nombre + ' (+' + (responsables.length - 1) + ')';
        },

        estaTerminado(t) {
            return !!t && (t.estado === 'resuelto' || t.estado === 'cerrado');
        },

        esperaMinutos(t) {
            if (!t || !t.created_at) return null;
            var inicio = new Date(t.created_at).getTime();
            var fin = this.estaTerminado(t)
                ? new Date(t.resuelto_at || t.updated_at || t.created_at).getTime()
                : Date.now();
            if (isNaN(inicio) || isNaN(fin)) return null;
            return Math.max(0, Math.floor((fin - inicio) / 60000));
        },

        esperaTexto(t) {
            var min = this.esperaMinutos(t);
            if (min === null) return '';
            if (min < 60) return min + ' min';
            var hrs = Math.floor(min / 60);
            if (hrs < 24) return hrs + ' h';
            var dias = Math.floor(hrs / 24);
            var resto = hrs % 24;
            return dias + ' d' + (resto > 0 ? ' ' + resto + ' h' : '');
        },

        nivelEspera(t) {
            if (this.estaTerminado(t)) return 'verde';
            var min = this.esperaMinutos(t);
            if (min === null) return 'azul';
            var hrs = min / 60;
            if (hrs < 12) return 'azul';
            if (hrs < 24) return 'amarillo';
            if (hrs < 36) return 'naranjo';
            return 'rojo';
        },

        claseEsperaChip(t) {
            var n = this.nivelEspera(t);
            if (n === 'verde')    return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
            if (n === 'amarillo') return 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300';
            if (n === 'naranjo')  return 'bg-orange-100 dark:bg-orange-900/30 text-orange-800 dark:text-orange-300';
            if (n === 'rojo')     return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
            return 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300';
        },

        claseEsperaPunto(t) {
            var n = this.nivelEspera(t);
            if (n === 'verde')    return 'bg-green-500';
            if (n === 'amarillo') return 'bg-yellow-500';
            if (n === 'naranjo')  return 'bg-orange-500';
            if (n === 'rojo')     return 'bg-red-600';
            return 'bg-blue-500';
        },

        tituloEspera(t) {
            return this.estaTerminado(t)
                ? 'Tiempo total hasta resolverse'
                : 'Tiempo en espera desde que se reportó';
        },

        fechaCorta(iso) {
            try {
                var d = new Date(iso);
                var dd = String(d.getDate()).padStart(2, '0');
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                var yyyy = d.getFullYear();
                var hh = String(d.getHours()).padStart(2, '0');
                var mi = String(d.getMinutes()).padStart(2, '0');
                return dd + '/' + mm + '/' + yyyy + ' ' + hh + ':' + mi;
            } catch (e) { return iso; }
        },

        fechaRelativa(iso) {
            try {
                var d = new Date(iso);
                var diffMs = Date.now() - d.getTime();
                var diffMin = Math.floor(diffMs / 60000);
                if (diffMin < 1) return 'ahora';
                if (diffMin < 60) return 'hace ' + diffMin + ' min';
                var diffHr = Math.floor(diffMin / 60);
                if (diffHr < 24) return 'hace ' + diffHr + ' h';
                var diffD = Math.floor(diffHr / 24);
                if (diffD < 7) return 'hace ' + diffD + ' d';
                return this.fechaCorta(iso).slice(0, 10);
            } catch (e) { return ''; }
        }
    };
}

window.ticketsApp = ticketsApp;
if (window.Alpine) {
    window.Alpine.data('ticketsApp', ticketsApp);
} else {
    document.addEventListener('alpine:init', function () {
        window.Alpine.data('ticketsApp', ticketsApp);
    });
}
