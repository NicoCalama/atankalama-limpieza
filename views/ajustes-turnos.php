<?php
/**
 * Ajustes → Turnos. Dos pestañas: Catálogo + Asignación semanal.
 * Spec: docs/ajustes.md §2.2 + docs/turnos.md
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

$puedeEditarCatalogo = $usuario->tienePermiso('turnos.crear_editar');
$puedeAsignar = $usuario->tienePermiso('turnos.asignar_a_usuario');
?>

<div x-data="turnosApp()" x-init="inicializar()"
     @turno-guardado.window="cargarCatalogo()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between gap-3 max-w-6xl mx-auto">
            <div class="flex items-center gap-3 min-w-0">
                <a href="/ajustes" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </a>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Turnos</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="tab === 'catalogo' ? 'Catálogo de turnos' : 'Asignación semanal'"></p>
                </div>
            </div>
            <?php if ($puedeEditarCatalogo): ?>
                <button type="button" x-show="tab === 'catalogo'"
                        @click="window.dispatchEvent(new CustomEvent('abrir-modal-turno-editor', {detail:{turno:null}}))"
                        class="min-h-[44px] inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span class="hidden sm:inline">Nuevo turno</span>
                </button>
            <?php endif; ?>
        </div>
        <!-- Tabs -->
        <div class="max-w-6xl mx-auto mt-3 flex gap-1 border-b border-gray-200 dark:border-gray-700 -mb-3">
            <button type="button" @click="cambiarTab('catalogo')"
                    :class="tab === 'catalogo'
                        ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                    class="min-h-[44px] px-4 py-2 text-sm font-medium border-b-2 transition">
                Catálogo
            </button>
            <?php if ($puedeAsignar): ?>
            <button type="button" @click="cambiarTab('asignacion')"
                    :class="tab === 'asignacion'
                        ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                    class="min-h-[44px] px-4 py-2 text-sm font-medium border-b-2 transition">
                Asignación
            </button>
            <?php endif; ?>
        </div>
    </header>

    <main class="max-w-6xl mx-auto p-4 pb-24 md:pb-6">

        <!-- ===================== TAB CATÁLOGO ===================== -->
        <section x-show="tab === 'catalogo'" x-cloak>
            <div x-show="cargandoCatalogo" x-cloak class="flex items-center justify-center py-10">
                <svg class="animate-spin h-6 w-6 text-blue-600" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                    <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
                </svg>
            </div>

            <div x-show="!cargandoCatalogo && catalogo.length === 0" x-cloak
                 class="text-center py-10">
                <i data-lucide="calendar-clock" class="w-10 h-10 text-gray-400 mx-auto mb-2"></i>
                <p class="text-sm text-gray-500 dark:text-gray-400">No hay turnos configurados.</p>
            </div>

            <div x-show="!cargandoCatalogo && catalogo.length > 0" x-cloak class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-for="t in catalogo" :key="t.id">
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="t.nombre"></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    <span x-text="t.hora_inicio"></span> – <span x-text="t.hora_fin"></span>
                                </p>
                            </div>
                            <?php if ($puedeEditarCatalogo): ?>
                                <button type="button"
                                        @click="window.dispatchEvent(new CustomEvent('abrir-modal-turno-editor', {detail:{turno:t}}))"
                                        class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                                        aria-label="Editar">
                                    <i data-lucide="pencil" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="mt-3 flex items-center gap-2">
                            <span :class="t.activo ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'
                                                   : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-600'"
                                  class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border"
                                  x-text="t.activo ? 'Activo' : 'Inactivo'"></span>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <!-- ===================== TAB ASIGNACIÓN ===================== -->
        <?php if ($puedeAsignar): ?>
        <section x-show="tab === 'asignacion'" x-cloak>

            <!-- Navegación semana -->
            <div class="flex items-center justify-between gap-2 mb-3">
                <button type="button" @click="cambiarSemana(-1)"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-700"
                        aria-label="Semana anterior">
                    <i data-lucide="chevron-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </button>
                <div class="text-center">
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="rangoSemanaTexto()"></p>
                    <button type="button" @click="irASemanaActual()" x-show="!esSemanaActual()" x-cloak
                            class="text-xs text-blue-600 dark:text-blue-400 hover:underline mt-0.5">
                        Ir a semana actual
                    </button>
                </div>
                <button type="button" @click="cambiarSemana(1)"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-700"
                        aria-label="Semana siguiente">
                    <i data-lucide="chevron-right" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </button>
            </div>

            <!-- Loading -->
            <div x-show="cargandoAsignacion" x-cloak class="flex items-center justify-center py-10">
                <svg class="animate-spin h-6 w-6 text-blue-600" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                    <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
                </svg>
            </div>

            <!-- Calendario semanal -->
            <div x-show="!cargandoAsignacion" x-cloak class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th class="sticky left-0 z-10 bg-gray-50 dark:bg-gray-900 px-3 py-2 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 min-w-[180px] border-r border-gray-200 dark:border-gray-700">Trabajador</th>
                                <template x-for="(d, idx) in dias" :key="idx">
                                    <th class="px-2 py-2 text-center text-xs font-semibold border-l border-gray-200 dark:border-gray-700 min-w-[90px]"
                                        :class="esHoy(d) ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300'">
                                        <div x-text="nombreDiaCorto(d)"></div>
                                        <div class="text-[10px] font-normal opacity-70" x-text="fechaCorta(d)"></div>
                                    </th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="u in usuariosAsignacion" :key="u.id">
                                <tr class="border-t border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900/30">
                                    <td class="sticky left-0 bg-white dark:bg-gray-800 px-3 py-2 border-r border-gray-200 dark:border-gray-700">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="u.nombre"></p>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 truncate" x-text="Array.isArray(u.roles) ? u.roles.join(', ') : (u.roles || '')"></p>
                                    </td>
                                    <template x-for="(d, idx) in dias" :key="idx">
                                        <td class="border-l border-gray-200 dark:border-gray-700 p-1 align-middle">
                                            <button type="button" @click="abrirEditorCelda(u, d)"
                                                    :class="asignacion(u.id, d)
                                                        ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-blue-300 dark:border-blue-700'
                                                        : 'bg-white dark:bg-gray-900 text-gray-400 dark:text-gray-500 border-dashed border-gray-300 dark:border-gray-600 hover:border-blue-400'"
                                                    class="w-full min-h-[44px] rounded-md border text-xs font-medium transition px-1">
                                                <template x-if="asignacion(u.id, d)">
                                                    <div>
                                                        <div class="font-semibold" x-text="asignacion(u.id, d).turno_nombre"></div>
                                                        <div class="text-[10px] opacity-70">
                                                            <span x-text="asignacion(u.id, d).hora_inicio"></span>–<span x-text="asignacion(u.id, d).hora_fin"></span>
                                                        </div>
                                                    </div>
                                                </template>
                                                <template x-if="!asignacion(u.id, d)">
                                                    <span>—</span>
                                                </template>
                                            </button>
                                        </td>
                                    </template>
                                </tr>
                            </template>
                            <tr x-show="usuariosAsignacion.length === 0" x-cloak>
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No hay trabajadores activos.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Popover de edición celda -->
            <div x-show="popoverAbierto" x-cloak
                 @click.away="cerrarPopover()"
                 @keydown.escape.window="cerrarPopover()"
                 class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4"
                 x-transition.opacity>
                <div class="w-full sm:max-w-sm bg-white dark:bg-gray-800 rounded-t-2xl sm:rounded-xl shadow-xl overflow-hidden"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="translate-y-full sm:translate-y-0 sm:opacity-0"
                     x-transition:enter-end="translate-y-0 sm:opacity-100">
                    <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="celdaEdicion.usuarioNombre"></p>
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 mt-0.5" x-text="celdaEdicion.fechaTexto"></p>
                    </div>
                    <div class="p-4 space-y-2">
                        <button type="button" @click="asignarCelda(null)" :disabled="popoverGuardando"
                                class="w-full min-h-[48px] flex items-center justify-between px-4 py-2 rounded-lg bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 text-sm transition">
                            <span class="text-gray-700 dark:text-gray-300">Sin turno</span>
                            <i data-lucide="circle-off" class="w-4 h-4 text-gray-400"></i>
                        </button>
                        <template x-for="t in catalogoActivos" :key="t.id">
                            <button type="button" @click="asignarCelda(t.id)" :disabled="popoverGuardando"
                                    :class="celdaEdicion.turnoIdActual === t.id
                                        ? 'bg-blue-50 dark:bg-blue-900/30 border-blue-400 ring-2 ring-blue-400'
                                        : 'bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                    class="w-full min-h-[48px] flex items-center justify-between px-4 py-2 rounded-lg border disabled:opacity-50 text-sm transition">
                                <span class="text-gray-900 dark:text-gray-100 font-medium" x-text="t.nombre"></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="t.hora_inicio"></span>–<span x-text="t.hora_fin"></span>
                                </span>
                            </button>
                        </template>
                    </div>
                    <div class="flex items-center justify-end p-3 border-t border-gray-200 dark:border-gray-700">
                        <button type="button" @click="cerrarPopover()"
                                class="min-h-[44px] px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                            Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Toast -->
        <div x-show="toast.visible" x-cloak x-transition
             :class="toast.tipo === 'error' ? 'bg-rose-600' : 'bg-emerald-600'"
             class="fixed bottom-20 left-1/2 -translate-x-1/2 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-50"
             x-text="toast.mensaje"></div>
    </main>
</div>

<script>
function turnosApp() {
    return {
        tab: localStorage.getItem('ajustes_turnos_tab') || 'catalogo',
        puedeAsignar: <?= $puedeAsignar ? 'true' : 'false' ?>,
        cargandoCatalogo: false,
        catalogo: [],
        cargandoAsignacion: false,
        usuariosAsignacion: [],
        asignacionesPorDia: {},
        lunesSemana: null,
        popoverAbierto: false,
        popoverGuardando: false,
        celdaEdicion: { usuarioId: null, usuarioNombre: '', fecha: '', fechaTexto: '', turnoIdActual: null },
        toast: { visible: false, mensaje: '', tipo: 'ok' },

        inicializar() {
            this.lunesSemana = this.calcularLunes(new Date());
            this.cargarCatalogo();
            if (this.tab === 'asignacion' && this.puedeAsignar) {
                this.cargarAsignacion();
            }
        },

        cambiarTab(t) {
            this.tab = t;
            localStorage.setItem('ajustes_turnos_tab', t);
            if (t === 'asignacion' && this.usuariosAsignacion.length === 0) {
                this.cargarAsignacion();
            }
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        async cargarCatalogo() {
            this.cargandoCatalogo = true;
            try {
                const res = await apiFetch('/api/turnos?todos=1');
                if (!res.ok) { this.mostrarToast(res.error?.mensaje || 'Error.', 'error'); return; }
                this.catalogo = res.data?.turnos || [];
            } catch (e) {
                this.mostrarToast('Error de red.', 'error');
            } finally {
                this.cargandoCatalogo = false;
                this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
            }
        },

        get catalogoActivos() {
            return this.catalogo.filter(t => t.activo);
        },

        get dias() {
            if (!this.lunesSemana) return [];
            const out = [];
            for (let i = 0; i < 7; i++) {
                const d = new Date(this.lunesSemana);
                d.setDate(d.getDate() + i);
                out.push(this.ymd(d));
            }
            return out;
        },

        async cargarAsignacion() {
            if (!this.puedeAsignar) return;
            this.cargandoAsignacion = true;
            try {
                const promesas = [apiFetch('/api/usuarios')];
                if (this.catalogo.length === 0) promesas.push(apiFetch('/api/turnos?todos=1'));
                for (const d of this.dias) {
                    promesas.push(apiFetch('/api/turnos/dia?fecha=' + encodeURIComponent(d)));
                }
                const resultados = await Promise.all(promesas);
                const resUsers = resultados.shift();
                if (!resUsers.ok) { this.mostrarToast('No se pudo cargar usuarios.', 'error'); return; }
                const lista = resUsers.data?.usuarios || [];
                this.usuariosAsignacion = lista.filter(u => u.activo).sort((a, b) => a.nombre.localeCompare(b.nombre));
                if (this.catalogo.length === 0) {
                    const resCat = resultados.shift();
                    if (resCat.ok) this.catalogo = resCat.data?.turnos || [];
                }
                this.asignacionesPorDia = {};
                for (let i = 0; i < this.dias.length; i++) {
                    const d = this.dias[i];
                    const r = resultados[i];
                    this.asignacionesPorDia[d] = r && r.ok ? (r.data?.turnos || []) : [];
                }
            } catch (e) {
                this.mostrarToast('Error de red.', 'error');
            } finally {
                this.cargandoAsignacion = false;
                this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
            }
        },

        asignacion(usuarioId, fecha) {
            const lista = this.asignacionesPorDia[fecha] || [];
            return lista.find(a => a.usuario_id === usuarioId) || null;
        },

        abrirEditorCelda(u, fecha) {
            const existente = this.asignacion(u.id, fecha);
            this.celdaEdicion = {
                usuarioId: u.id,
                usuarioNombre: u.nombre,
                fecha,
                fechaTexto: this.fechaLarga(fecha),
                turnoIdActual: existente ? existente.turno_id : null,
            };
            this.popoverAbierto = true;
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        cerrarPopover() {
            this.popoverAbierto = false;
        },

        async asignarCelda(turnoId) {
            if (this.popoverGuardando) return;
            this.popoverGuardando = true;
            const { usuarioId, fecha, turnoIdActual } = this.celdaEdicion;
            try {
                let res;
                if (turnoId === null) {
                    if (turnoIdActual === null) { this.cerrarPopover(); return; }
                    res = await apiFetch('/api/usuarios/' + usuarioId + '/turno?fecha=' + encodeURIComponent(fecha), { method: 'DELETE' });
                } else {
                    res = await apiPost('/api/usuarios/' + usuarioId + '/turno', { turno_id: turnoId, fecha });
                }
                if (!res.ok) {
                    this.mostrarToast(res.error?.mensaje || 'No se pudo guardar.', 'error');
                    return;
                }
                const resDia = await apiFetch('/api/turnos/dia?fecha=' + encodeURIComponent(fecha));
                if (resDia.ok) this.asignacionesPorDia[fecha] = resDia.data?.turnos || [];
                this.cerrarPopover();
                this.mostrarToast(turnoId === null ? 'Asignación eliminada.' : 'Turno asignado.');
            } catch (e) {
                this.mostrarToast('Error de red.', 'error');
            } finally {
                this.popoverGuardando = false;
            }
        },

        cambiarSemana(delta) {
            const d = new Date(this.lunesSemana);
            d.setDate(d.getDate() + delta * 7);
            this.lunesSemana = d;
            this.cargarAsignacion();
        },

        irASemanaActual() {
            this.lunesSemana = this.calcularLunes(new Date());
            this.cargarAsignacion();
        },

        esSemanaActual() {
            const hoyLunes = this.calcularLunes(new Date());
            return this.ymd(hoyLunes) === this.ymd(this.lunesSemana);
        },

        calcularLunes(d) {
            const copia = new Date(d);
            const dia = copia.getDay(); // 0=Dom, 1=Lun...
            const diff = dia === 0 ? -6 : 1 - dia;
            copia.setDate(copia.getDate() + diff);
            copia.setHours(0, 0, 0, 0);
            return copia;
        },

        ymd(d) {
            if (typeof d === 'string') return d;
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const da = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${da}`;
        },

        nombreDiaCorto(fecha) {
            const d = new Date(fecha + 'T12:00:00');
            return ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'][d.getDay()];
        },

        fechaCorta(fecha) {
            const [, m, da] = fecha.split('-');
            return `${da}/${m}`;
        },

        fechaLarga(fecha) {
            const d = new Date(fecha + 'T12:00:00');
            const dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
            const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            return `${dias[d.getDay()]} ${d.getDate()} de ${meses[d.getMonth()]}`;
        },

        rangoSemanaTexto() {
            if (!this.lunesSemana) return '';
            const fin = new Date(this.lunesSemana);
            fin.setDate(fin.getDate() + 6);
            const fIni = this.lunesSemana;
            const mismoMes = fIni.getMonth() === fin.getMonth();
            const meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
            if (mismoMes) {
                return `${fIni.getDate()} – ${fin.getDate()} ${meses[fIni.getMonth()]} ${fin.getFullYear()}`;
            }
            return `${fIni.getDate()} ${meses[fIni.getMonth()]} – ${fin.getDate()} ${meses[fin.getMonth()]} ${fin.getFullYear()}`;
        },

        esHoy(fecha) {
            return fecha === this.ymd(new Date());
        },

        mostrarToast(mensaje, tipo = 'ok') {
            this.toast = { visible: true, mensaje, tipo };
            setTimeout(() => { this.toast.visible = false; }, 2500);
        },
    };
}
</script>
