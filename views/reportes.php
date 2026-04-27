<?php
/**
 * Vista de Reportes y KPIs.
 * Permiso requerido: reportes.ver
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="reportes()"
     x-init="cargar(); cargarMensual()"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto gap-3">
            <div class="flex items-center gap-3">
                <a href="/home" class="md:hidden min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </a>
                <i data-lucide="bar-chart-3" class="w-6 h-6 text-gray-700 dark:text-gray-300 hidden md:block flex-shrink-0"></i>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reportes</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="subtitulo"></p>
                </div>
            </div>
            <button @click="exportar()"
                    :disabled="cargando || exportando"
                    class="min-h-[44px] flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800
                           text-white text-sm font-semibold rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed flex-shrink-0">
                <i data-lucide="download" class="w-4 h-4 flex-shrink-0"></i>
                <span class="hidden sm:inline" x-text="exportando ? 'Exportando...' : 'Exportar Excel'"></span>
            </button>
        </div>
    </header>

    <main class="max-w-5xl mx-auto p-4 pb-24 md:pb-6 space-y-4">

        <!-- Filtros -->
        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4">

            <!-- Presets de período -->
            <div class="flex flex-wrap gap-2 mb-3">
                <template x-for="p in presets" :key="p.valor">
                    <button @click="setPreset(p.valor)"
                            :class="preset === p.valor
                                ? 'bg-blue-600 text-white'
                                : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600'"
                            class="min-h-[36px] px-3 py-1.5 text-sm font-medium rounded-lg transition">
                        <span x-text="p.label"></span>
                    </button>
                </template>
            </div>

            <!-- Rango personalizado -->
            <div x-show="preset === 'personalizado'" x-cloak class="flex gap-2 mb-3">
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Desde</label>
                    <input type="date" x-model="desde" @change="cargar()"
                           class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100">
                </div>
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Hasta</label>
                    <input type="date" x-model="hasta" @change="cargar()"
                           class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100">
                </div>
            </div>

            <!-- Hotel + Trabajadora -->
            <div class="flex flex-wrap gap-2">
                <select x-model="hotel" @change="cargar(); cargarMensual()"
                        class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                    <option value="ambos">Ambos hoteles</option>
                    <option value="1_sur">Atankalama</option>
                    <option value="inn">Atankalama INN</option>
                </select>

                <select x-model="usuarioId" @change="cargar()"
                        class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200 flex-1 min-w-[180px]">
                    <option value="">Todas las trabajadoras</option>
                    <template x-for="t in trabajadoras" :key="t.usuario_id">
                        <option :value="t.usuario_id" x-text="t.nombre"></option>
                    </template>
                </select>

                <!-- Refrescar -->
                <button @click="cargar()" :disabled="cargando"
                        class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700
                               hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                        aria-label="Refrescar">
                    <i data-lucide="rotate-cw" class="w-4 h-4 text-gray-600 dark:text-gray-400"
                       :class="cargando ? 'animate-spin' : ''"></i>
                </button>
            </div>
        </div>

        <!-- Estado cargando (primera carga) -->
        <template x-if="cargando && !data">
            <div class="flex flex-col items-center justify-center py-16 gap-3">
                <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="text-sm text-gray-500 dark:text-gray-400">Calculando KPIs...</p>
            </div>
        </template>

        <!-- Error -->
        <template x-if="error && !data">
            <div class="flex flex-col items-center justify-center py-16 gap-3 text-center">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500"></i>
                <p class="text-gray-600 dark:text-gray-400">No pudimos cargar los reportes.</p>
                <button @click="cargar()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">
                    Reintentar
                </button>
            </div>
        </template>

        <!-- Contenido principal -->
        <template x-if="data">
            <div class="space-y-4">

                <!-- Grid de KPIs -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <template x-for="kpi in tarjetasKpi()" :key="kpi.clave">
                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col gap-2">
                            <!-- Título + indicador estado -->
                            <div class="flex items-start justify-between gap-1">
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 leading-tight" x-text="kpi.titulo"></p>
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 mt-0.5"
                                      :class="{
                                          'bg-emerald-500': kpi.estado === 'ok',
                                          'bg-amber-400':   kpi.estado === 'alerta',
                                          'bg-red-500':     kpi.estado === 'critico',
                                          'bg-blue-500':    kpi.estado === 'informativo',
                                          'bg-gray-300 dark:bg-gray-600': kpi.estado === 'sin_datos',
                                      }"></span>
                            </div>

                            <!-- Valor principal -->
                            <template x-if="kpi.valor !== null">
                                <div>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 leading-none">
                                        <span x-text="kpi.valor"></span><!--
                                     --><span class="text-sm font-normal text-gray-400 dark:text-gray-500 ml-1" x-text="kpi.unidad"></span>
                                    </p>

                                    <!-- Barra de progreso (solo si tiene meta) -->
                                    <template x-if="kpi.meta !== null">
                                        <div class="mt-2">
                                            <div class="w-full h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                                <div class="h-full rounded-full transition-all duration-700"
                                                     :class="{
                                                         'bg-emerald-500': kpi.estado === 'ok',
                                                         'bg-amber-400':   kpi.estado === 'alerta',
                                                         'bg-red-500':     kpi.estado === 'critico',
                                                     }"
                                                     :style="'width:' + anchoBarra(kpi) + '%'"></div>
                                            </div>
                                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1"
                                               x-text="'Meta: ' + kpi.meta + ' ' + kpi.unidad"></p>
                                        </div>
                                    </template>

                                    <!-- Contexto -->
                                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1" x-text="kpi.contexto"></p>
                                </div>
                            </template>

                            <!-- Sin datos -->
                            <template x-if="kpi.valor === null">
                                <div>
                                    <p class="text-xl font-medium text-gray-300 dark:text-gray-600">—</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Sin datos para el período</p>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Tabla por trabajadora (solo si no hay filtro por persona) -->
                <template x-if="data.por_trabajadora && data.por_trabajadora.length > 0 && !usuarioId">
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Detalle por trabajadora</h2>
                            <span class="text-xs text-gray-400 dark:text-gray-500" x-text="data.por_trabajadora.length + ' trabajadoras'"></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-700/40">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Trabajadora</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">T. Prom.</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Rechazo</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Eficiencia</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Créditos</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Aprob. 1ª</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Productiv.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <template x-for="t in data.por_trabajadora" :key="t.usuario_id">
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 cursor-pointer"
                                            @click="filtrarPorTrabajadora(t.usuario_id)">
                                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap"
                                                x-text="primerNombre(t.nombre)"></td>
                                            <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400"
                                                x-text="fmtKpi(t.kpis.tiempo_promedio)"></td>
                                            <td class="px-4 py-3 text-right font-medium"
                                                :class="claseKpi(t.kpis.tasa_rechazo)"
                                                x-text="fmtKpi(t.kpis.tasa_rechazo)"></td>
                                            <td class="px-4 py-3 text-right font-medium"
                                                :class="claseKpi(t.kpis.eficiencia)"
                                                x-text="fmtKpi(t.kpis.eficiencia)"></td>
                                            <td class="px-4 py-3 text-right font-medium"
                                                :class="claseKpi(t.kpis.creditos)"
                                                x-text="fmtKpi(t.kpis.creditos)"></td>
                                            <td class="px-4 py-3 text-right font-medium"
                                                :class="claseKpi(t.kpis.aprobacion_primera)"
                                                x-text="fmtKpi(t.kpis.aprobacion_primera)"></td>
                                            <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400"
                                                x-text="fmtKpi(t.kpis.productividad)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <p class="px-4 py-2 text-xs text-gray-400 dark:text-gray-500">
                            Tap en una fila para ver el detalle individual.
                        </p>
                    </div>
                </template>

                <!-- Sin datos en el período -->
                <template x-if="data.por_trabajadora && data.por_trabajadora.length === 0 && !usuarioId">
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center">
                        <i data-lucide="inbox" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3"></i>
                        <p class="text-sm text-gray-500 dark:text-gray-400">No hay actividad registrada en este período.</p>
                    </div>
                </template>

            </div>
        </template>

        <!-- Resumen mensual por trabajador (independiente del filtro de arriba) -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3 justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="calendar" class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Resumen mensual por trabajador</h2>
                </div>
                <div class="flex items-center gap-2">
                    <input type="month" x-model="mensualMes" @change="cargarMensual()"
                           class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                    <button @click="cargarMensual()" :disabled="mensualCargando"
                            class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                            aria-label="Refrescar">
                        <i data-lucide="rotate-cw" class="w-4 h-4 text-gray-600 dark:text-gray-400"
                           :class="mensualCargando ? 'animate-spin' : ''"></i>
                    </button>
                </div>
            </header>

            <template x-if="mensualCargando && !mensualData">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Cargando...</div>
            </template>

            <template x-if="mensualData && mensualData.length === 0">
                <div class="p-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hay actividad registrada en este mes.</p>
                </div>
            </template>

            <template x-if="mensualData && mensualData.length > 0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700/50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Trabajador</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Habitaciones</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Créditos</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <template x-for="t in mensualData" :key="t.usuario_id">
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                    <td class="px-4 py-3 text-gray-900 dark:text-gray-100" x-text="t.nombre"></td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-gray-100" x-text="t.habitaciones"></td>
                                    <td class="px-4 py-3 text-right">
                                        <span class="font-semibold text-gray-900 dark:text-gray-100" x-text="t.creditos"></span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="' / ' + t.creditos_maximos"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

    </main>
</div>

<script>
function reportes() {
    var hoy = new Date().toISOString().split('T')[0];

    return {
        data:         null,
        cargando:     false,
        error:        false,
        exportando:   false,

        preset:    'hoy',
        desde:     hoy,
        hasta:     hoy,
        hotel:     'ambos',
        usuarioId: '',

        trabajadoras: [],

        subtitulo: 'Cargando...',

        // Resumen mensual (independiente)
        mensualMes:      new Date().toISOString().slice(0, 7), // YYYY-MM
        mensualData:     null,
        mensualCargando: false,

        presets: [
            { valor: 'hoy',          label: 'Hoy' },
            { valor: 'semana',       label: 'Últimos 7 días' },
            { valor: 'mes',          label: 'Últimos 30 días' },
            { valor: 'personalizado', label: 'Personalizado' },
        ],

        setPreset(valor) {
            this.preset = valor;
            var hoyDate = new Date();
            var fmt = d => d.toISOString().split('T')[0];
            if (valor === 'hoy') {
                this.desde = this.hasta = fmt(hoyDate);
            } else if (valor === 'semana') {
                var d = new Date(hoyDate); d.setDate(d.getDate() - 6);
                this.desde = fmt(d); this.hasta = fmt(hoyDate);
            } else if (valor === 'mes') {
                var d = new Date(hoyDate); d.setDate(d.getDate() - 29);
                this.desde = fmt(d); this.hasta = fmt(hoyDate);
            }
            if (valor !== 'personalizado') this.cargar();
        },

        async cargar() {
            this.cargando = true;
            this.error    = false;
            try {
                var params = new URLSearchParams({
                    desde:      this.desde,
                    hasta:      this.hasta,
                    hotel:      this.hotel,
                });
                if (this.usuarioId) params.set('usuario_id', this.usuarioId);

                var resp = await fetch('/api/reportes/kpis?' + params.toString());
                var json = await resp.json();

                if (json.ok) {
                    this.data         = json.data;
                    this.trabajadoras = json.data.trabajadoras || [];
                    this.subtitulo    = this.calcSubtitulo();
                    this.$nextTick(() => lucide.createIcons());
                } else {
                    this.error = true;
                }
            } catch (e) {
                this.error = true;
            } finally {
                this.cargando = false;
            }
        },

        async cargarMensual() {
            // mensualMes viene como 'YYYY-MM'
            var partes = (this.mensualMes || '').split('-');
            if (partes.length !== 2) return;
            var anio = parseInt(partes[0], 10);
            var mes  = parseInt(partes[1], 10);
            if (!anio || !mes) return;
            this.mensualCargando = true;
            try {
                var params = new URLSearchParams({ anio: anio, mes: mes, hotel: this.hotel });
                var resp = await fetch('/api/reportes/resumen-mensual?' + params.toString());
                var json = await resp.json();
                if (json.ok) {
                    this.mensualData = json.data.trabajadores || [];
                    this.$nextTick(() => lucide.createIcons());
                } else {
                    this.mensualData = [];
                }
            } catch (e) {
                this.mensualData = [];
            } finally {
                this.mensualCargando = false;
            }
        },

        calcSubtitulo() {
            if (!this.data) return '';
            var hotelLabel = { ambos: 'Ambos hoteles', '1_sur': 'Atankalama', inn: 'Atankalama INN' }[this.hotel] || 'Ambos hoteles';
            if (this.desde === this.hasta) return hotelLabel + ' · ' + this.fmtFecha(this.desde);
            return hotelLabel + ' · ' + this.fmtFecha(this.desde) + ' — ' + this.fmtFecha(this.hasta);
        },

        fmtFecha(iso) {
            if (!iso) return '';
            var p = iso.split('-');
            return p[2] + '/' + p[1] + '/' + p[0];
        },

        async exportar() {
            if (this.exportando) return;
            this.exportando = true;
            try {
                var params = new URLSearchParams({
                    desde:  this.desde,
                    hasta:  this.hasta,
                    hotel:  this.hotel,
                });
                if (this.usuarioId) params.set('usuario_id', this.usuarioId);

                var resp = await fetch('/api/reportes/exportar?' + params.toString());
                if (!resp.ok) { this.exportando = false; return; }

                var blob = await resp.blob();
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = 'reporte_kpis_' + this.desde + '_' + this.hasta + '.csv';
                a.click();
                URL.revokeObjectURL(url);
            } catch (e) { /* silencioso */ } finally {
                this.exportando = false;
            }
        },

        filtrarPorTrabajadora(uid) {
            this.usuarioId = uid;
            this.cargar();
        },

        alVolverVisible() {
            if (!document.hidden && this.data) this.cargar();
        },

        tarjetasKpi() {
            if (!this.data) return [];
            var defs = [
                { clave: 'tiempo_promedio',    titulo: 'Tiempo prom. limpieza' },
                { clave: 'tasa_rechazo',       titulo: 'Tasa de rechazo' },
                { clave: 'eficiencia',         titulo: 'Eficiencia del equipo' },
                { clave: 'creditos',           titulo: 'Créditos obtenidos' },
                { clave: 'aprobacion_primera', titulo: 'Aprobación a la 1ª' },
                { clave: 'productividad',      titulo: 'Productividad prom.' },
                { clave: 'tasa_desmarcados',   titulo: 'Ítems desmarcados' },
            ];
            return defs.map(d => Object.assign({ clave: d.clave, titulo: d.titulo }, this.data.kpis[d.clave] || {}));
        },

        anchoBarra(kpi) {
            if (kpi.valor === null || kpi.meta === null) return 0;
            // Para KPIs donde menor es mejor (rechazo, tiempo, desmarcados): invertir
            var menorMejor = ['tasa_rechazo', 'tiempo_promedio', 'tasa_desmarcados'].includes(kpi.clave);
            if (menorMejor) {
                // 100% = exactamente en meta, 0% = doble de meta
                var pct = Math.max(0, Math.min(100, (1 - (kpi.valor - kpi.meta) / kpi.meta) * 100));
                return Math.round(pct);
            }
            // Para KPIs donde mayor es mejor (eficiencia, créditos, aprobación)
            return Math.round(Math.min(100, (kpi.valor / kpi.meta) * 100));
        },

        fmtKpi(k) {
            if (!k || k.valor === null) return '—';
            return k.valor + ' ' + (k.unidad || '');
        },

        claseKpi(k) {
            if (!k || k.valor === null) return 'text-gray-400 dark:text-gray-500';
            return {
                ok:          'text-emerald-600 dark:text-emerald-400',
                alerta:      'text-amber-600 dark:text-amber-400',
                critico:     'text-red-600 dark:text-red-400',
                informativo: 'text-blue-600 dark:text-blue-400',
            }[k.estado] || 'text-gray-600 dark:text-gray-400';
        },

        primerNombre(nombre) {
            return nombre ? nombre.split(' ')[0] : nombre;
        },
    };
}
</script>
