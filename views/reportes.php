<?php
/**
 * Vista de Reportes y KPIs.
 * Permiso requerido: reportes.ver
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="reportes()"
     x-init="cargar(); cargarMensual(); cargarAudit(); cargarAuditPendientes()"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto gap-3">
            <div class="flex items-center gap-3">
                <a href="<?= u('/home') ?>" class="md:hidden min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </a>
                <i data-lucide="bar-chart-3" class="w-6 h-6 text-gray-700 dark:text-gray-300 hidden md:block flex-shrink-0"></i>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reportes</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="subtitulo"></p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <button @click="exportar()" data-tour="rep.exportar"
                        :disabled="cargando || exportando"
                        class="min-h-[44px] flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800
                               text-white text-sm font-semibold rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed flex-shrink-0">
                    <i data-lucide="download" class="w-4 h-4 flex-shrink-0"></i>
                    <span class="hidden sm:inline" x-text="exportando ? 'Exportando...' : 'Exportar Excel'"></span>
                </button>
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto p-4 pb-24 md:pb-6 space-y-4">

        <!-- Filtros -->
        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4" data-tour="rep.filtros">

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
                <select x-model="hotel" @change="cargar(); cargarMensual(); cargarAudit()"
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
                    <span :class="cargando ? 'animate-spin' : ''" class="inline-flex">
                        <i data-lucide="rotate-cw" class="w-4 h-4 text-gray-600 dark:text-gray-400"></i>
                    </span>
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
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3" data-tour="rep.kpis">
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
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.tabla">
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

        <!-- ═══ Ficha de KPIs · Trabajador (N1 + N2 + N3) — mismo rango/hotel que los KPIs de arriba ═══ -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.ficha">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3 justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="clipboard-list" class="w-5 h-5 text-violet-600 dark:text-violet-400 flex-shrink-0"></i>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Ficha de KPIs · Trabajador</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Créditos y piezas en dos etapas, asignado vs. aprobado, y comparación con el equipo.</p>
                    </div>
                </div>
                <span class="text-xs text-gray-400 dark:text-gray-500" x-show="ficha" x-text="ficha ? (ficha.trabajadores.length + ' personas') : ''"></span>
            </header>

            <template x-if="fichaCargando && !ficha">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Calculando ficha...</div>
            </template>

            <template x-if="fichaError && !fichaCargando">
                <div class="flex flex-col items-center justify-center py-8 gap-3 text-center">
                    <i data-lucide="alert-circle" class="w-8 h-8 text-red-500"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400">No pudimos calcular la ficha, intenta de nuevo en un momento.</p>
                    <button @click="cargarFicha()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
                </div>
            </template>

            <template x-if="ficha && ficha.trabajadores.length === 0">
                <div class="p-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hay actividad registrada en este período.</p>
                </div>
            </template>

            <template x-if="ficha && ficha.trabajadores.length > 0">
                <div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-700/40">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap sticky left-0 bg-gray-50 dark:bg-gray-700/40">Trabajador</th>
                                    <template x-for="c in fichaCols" :key="c.clave">
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap" :title="c.ayuda" x-text="c.titulo"></th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <template x-for="t in ficha.trabajadores" :key="t.usuario_id">
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30" :class="t.datos_suficientes ? '' : 'opacity-70'">
                                        <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap sticky left-0 bg-white dark:bg-gray-800">
                                            <span x-text="primerNombre(t.nombre)"></span>
                                            <template x-if="!t.datos_suficientes">
                                                <span class="ml-1 text-[10px] uppercase tracking-wide text-gray-400 dark:text-gray-500" title="Menos piezas que el mínimo configurado: sin semáforo y fuera del promedio.">pocos datos</span>
                                            </template>
                                        </td>
                                        <template x-for="c in fichaCols" :key="c.clave">
                                            <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                                <span x-text="fmtCol(t, c)"></span>
                                                <template x-if="c.cmp">
                                                    <span class="inline-block w-2 h-2 rounded-full ml-1 align-middle" :class="dotSem(t, c.clave)" :title="cmpTitle(t, c.clave)"></span>
                                                </template>
                                            </td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="bg-gray-50 dark:bg-gray-700/40 border-t border-gray-200 dark:border-gray-700">
                                <tr>
                                    <td class="px-3 py-2 text-xs font-semibold text-gray-600 dark:text-gray-300 whitespace-nowrap sticky left-0 bg-gray-50 dark:bg-gray-700/40">Promedio equipo</td>
                                    <template x-for="c in fichaCols" :key="'p-' + c.clave">
                                        <td class="px-3 py-2 text-right text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap" x-text="fmtPromedio(c)"></td>
                                    </template>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="px-4 py-2 text-xs text-gray-400 dark:text-gray-500 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span><span class="inline-block w-2 h-2 rounded-full bg-emerald-500 align-middle"></span> en línea con el equipo</span>
                        <span><span class="inline-block w-2 h-2 rounded-full bg-amber-400 align-middle"></span> atención (≥ <span x-text="ficha.config.sigma_amarillo"></span>σ)</span>
                        <span><span class="inline-block w-2 h-2 rounded-full bg-red-500 align-middle"></span> fuera de rango (≥ <span x-text="ficha.config.sigma_rojo"></span>σ)</span>
                        <span><span class="inline-block w-2 h-2 rounded-full bg-blue-500 align-middle"></span> informativo</span>
                        <span>· mínimo <span x-text="ficha.config.min_datos"></span> piezas para comparar · pasa el mouse por un punto para ver la diferencia vs. el promedio</span>
                    </p>
                </div>
            </template>
        </section>

        <!-- ═══ Supervisora · Inspección (N1 + N2 + N3) ═══
             Privacidad jerárquica de tiempos (jefatura, 16/09): nadie ve sus propios tiempos, solo el nivel
             de arriba. Esta sección (tiempo por auditación de las supervisoras) se renderiza solo con
             reportes.ver_supervisoras; el backend tampoco la envía sin el permiso. La bandera gatea el tour. -->
        <div data-vg-context='{"ve_supervisoras": <?= $usuario->tienePermiso('reportes.ver_supervisoras') ? 'true' : 'false' ?>}' hidden></div>
        <?php if ($usuario->tienePermiso('reportes.ver_supervisoras')): ?>
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.supervisora">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3 justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="shield-check" class="w-5 h-5 text-indigo-600 dark:text-indigo-400 flex-shrink-0"></i>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Supervisora · Inspección</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Cobertura de la sección contra su meta y tendencia, y el trabajo de cada inspectora.</p>
                    </div>
                </div>
                <span class="text-xs text-gray-400 dark:text-gray-500" x-show="ficha"
                      x-text="ficha ? (ficha.supervisoras.seccion.auditadas_humanas + ' inspeccionadas de ' + ficha.supervisoras.seccion.completadas + ' limpiadas') : ''"></span>
            </header>

            <template x-if="fichaCargando && !ficha">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Calculando...</div>
            </template>

            <template x-if="fichaError && !fichaCargando">
                <div class="flex flex-col items-center justify-center py-8 gap-3 text-center">
                    <i data-lucide="alert-circle" class="w-8 h-8 text-red-500"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400">No pudimos calcular esta sección, intenta de nuevo en un momento.</p>
                    <button @click="cargarFicha()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
                </div>
            </template>

            <template x-if="ficha">
                <div class="p-4 space-y-4">
                    <!-- N2 · Sección vs metas + tendencia -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <template x-for="k in seccionCards()" :key="k.clave">
                            <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col gap-1">
                                <div class="flex items-start justify-between gap-1">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 leading-tight" x-text="k.titulo"></p>
                                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 mt-0.5" :class="dotEstado(k.d.estado)"></span>
                                </div>
                                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 leading-none">
                                    <span x-text="k.d.valor === null ? '—' : k.d.valor"></span><span class="text-sm font-normal text-gray-400 dark:text-gray-500 ml-1" x-show="k.d.valor !== null">%</span>
                                </p>
                                <p class="text-xs text-gray-400 dark:text-gray-500" x-text="'Meta: ' + k.metaTxt"></p>
                                <p class="text-xs" :class="tendenciaClase(k.d.tendencia)" x-text="tendenciaTxt(k.d)"></p>
                            </div>
                        </template>
                    </div>

                    <!-- N2 · Por turno: turno que tenía el trabajador ese día (calendario de Turnos). Ver la carga
                         real de cada turno y si el equipo de inspección va holgado o apretado en cada uno. -->
                    <template x-if="ficha.supervisoras.seccion.por_turno && ficha.supervisoras.seccion.por_turno.length > 0">
                        <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Turno que tenía el trabajador ese día en el calendario de Turnos. «Sin turno» = día sin turno cargado para esa persona.">Por turno</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Limpiadas</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Inspecc.</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Cobertura</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-red-700 dark:text-red-400 uppercase tracking-wider">Rechazo</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider whitespace-nowrap">Aprob. 1ª</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-700 dark:text-gray-300">
                                    <template x-for="t in ficha.supervisoras.seccion.por_turno" :key="t.turno">
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100 whitespace-nowrap" x-text="t.nombre"></td>
                                            <td class="px-3 py-2 text-right tabular-nums" x-text="t.completadas"></td>
                                            <td class="px-3 py-2 text-right tabular-nums" x-text="t.auditadas_humanas"></td>
                                            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">
                                                <span class="inline-block w-2 h-2 rounded-full mr-1 align-middle" :class="dotEstado(t.cobertura_estado)"></span><span x-text="fmtPct(t.cobertura_pct)"></span>
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums" x-text="fmtPct(t.rechazo_pct)"></td>
                                            <td class="px-3 py-2 text-right tabular-nums" x-text="fmtPct(t.aprobacion_pct)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-700/50 text-xs font-semibold text-gray-700 dark:text-gray-300">
                                    <tr>
                                        <td class="px-3 py-2">Total</td>
                                        <td class="px-3 py-2 text-right tabular-nums" x-text="ficha.supervisoras.seccion.completadas"></td>
                                        <td class="px-3 py-2 text-right tabular-nums" x-text="ficha.supervisoras.seccion.auditadas_humanas"></td>
                                        <td class="px-3 py-2 text-right tabular-nums" x-text="fmtPct(ficha.supervisoras.seccion.cobertura.valor)"></td>
                                        <td class="px-3 py-2 text-right tabular-nums" x-text="fmtPct(ficha.supervisoras.seccion.rechazo.valor)"></td>
                                        <td class="px-3 py-2 text-right tabular-nums" x-text="fmtPct(ficha.supervisoras.seccion.aprobacion_primera.valor)"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </template>

                    <!-- N1 + N3 · Por inspectora -->
                    <template x-if="ficha.supervisoras.inspectoras.length === 0">
                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">No hay inspecciones humanas registradas en este período.</p>
                    </template>
                    <template x-if="ficha.supervisoras.inspectoras.length > 0">
                        <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Inspectora</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Inspecc.</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Aprob.</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-amber-700 dark:text-amber-400 uppercase tracking-wider">C/obs.</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-red-700 dark:text-red-400 uppercase tracking-wider">Rechaz.</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Minutos promedio desde que abre la pieza hasta que da el veredicto. Solo inspecciones desde que se activó la medición.">T. por insp.</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Lo que ella inspeccionó sobre todo lo limpiado en la sección.">Aporte cobert.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <template x-for="i in ficha.supervisoras.inspectoras" :key="i.usuario_id">
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100 whitespace-nowrap" x-text="primerNombre(i.nombre)"></td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                                <span class="font-semibold text-gray-900 dark:text-gray-100" x-text="i.total"></span>
                                                <span class="block text-[11px] text-gray-400 dark:text-gray-500" x-text="deltaTxt(i.cmp.total, '')"></span>
                                            </td>
                                            <td class="px-3 py-2 text-right text-emerald-700 dark:text-emerald-400 font-semibold" x-text="i.aprobadas"></td>
                                            <td class="px-3 py-2 text-right text-amber-700 dark:text-amber-400 font-semibold" x-text="i.con_observacion"></td>
                                            <td class="px-3 py-2 text-right text-red-700 dark:text-red-400 font-semibold" x-text="i.rechazadas"></td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                                <span class="text-gray-700 dark:text-gray-300" x-text="i.tiempo_auditacion === null ? '—' : (i.tiempo_auditacion + ' min')"></span>
                                                <span class="block text-[11px] text-gray-400 dark:text-gray-500" x-text="i.tiempo_auditacion === null ? 'sin medición' : deltaTxt(i.cmp.tiempo_auditacion, ' min')"></span>
                                            </td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                                <span class="text-gray-700 dark:text-gray-300" x-text="fmtPct(i.aporte_cobertura_pct)"></span>
                                                <span class="block text-[11px] text-gray-400 dark:text-gray-500" x-text="deltaTxt(i.cmp.aporte_cobertura_pct, ' pts')"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-700/40 border-t border-gray-200 dark:border-gray-700">
                                    <tr class="text-xs text-gray-600 dark:text-gray-300">
                                        <td class="px-3 py-2 font-semibold">Promedio</td>
                                        <td class="px-3 py-2 text-right" x-text="fmtNull(ficha.supervisoras.comparativa.total.promedio)"></td>
                                        <td class="px-3 py-2" colspan="3"></td>
                                        <td class="px-3 py-2 text-right" x-text="ficha.supervisoras.comparativa.tiempo_auditacion.promedio === null ? '—' : (ficha.supervisoras.comparativa.tiempo_auditacion.promedio + ' min')"></td>
                                        <td class="px-3 py-2 text-right" x-text="fmtPct(ficha.supervisoras.comparativa.aporte_cobertura_pct.promedio)"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </template>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Las piezas aprobadas automáticamente al cierre del día no cuentan como inspeccionadas (bajan la cobertura). La cifra bajo cada valor es la diferencia con el promedio simple de las inspectoras.
                    </p>
                </div>
            </template>
        </section>
        <?php endif; ?>

        <!-- Resumen mensual por trabajador (independiente del filtro de arriba) -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.mensual">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3 justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="calendar" class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Resumen mensual por trabajador</h2>
                </div>
                <div class="flex items-center gap-2">
                    <input type="month" x-model="mensualMes" @change="cargarMensual()"
                           class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                    <button @click="exportarMensual()" :disabled="mensualCargando || mensualExportando"
                            class="min-h-[40px] flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                            aria-label="Exportar mes">
                        <i data-lucide="download" class="w-4 h-4 flex-shrink-0"></i>
                        <span class="hidden sm:inline" x-text="mensualExportando ? 'Exportando...' : 'Exportar'"></span>
                    </button>
                    <button @click="cargarMensual()" :disabled="mensualCargando"
                            class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                            aria-label="Refrescar">
                        <span :class="mensualCargando ? 'animate-spin' : ''" class="inline-flex">
                            <i data-lucide="rotate-cw" class="w-4 h-4 text-gray-600 dark:text-gray-400"></i>
                        </span>
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

        <!-- Resumen mensual de auditorías por auditor (Supervisora / Recepción) -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.auditorias">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3 justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="shield-check" class="w-5 h-5 text-indigo-600 dark:text-indigo-400 flex-shrink-0"></i>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Resumen mensual de inspecciones</h2>
                </div>
                <div class="flex items-center gap-2">
                    <input type="month" x-model="auditMes" @change="cargarAudit()"
                           class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                    <button @click="exportarAudit()" :disabled="auditCargando || auditExportando"
                            class="min-h-[40px] flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                            aria-label="Exportar mes">
                        <i data-lucide="download" class="w-4 h-4 flex-shrink-0"></i>
                        <span class="hidden sm:inline" x-text="auditExportando ? 'Exportando...' : 'Exportar'"></span>
                    </button>
                    <button @click="cargarAudit()" :disabled="auditCargando"
                            class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                            aria-label="Refrescar">
                        <span :class="auditCargando ? 'animate-spin' : ''" class="inline-flex">
                            <i data-lucide="rotate-cw" class="w-4 h-4 text-gray-600 dark:text-gray-400"></i>
                        </span>
                    </button>
                </div>
            </header>

            <template x-if="auditCargando && !auditData">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Cargando...</div>
            </template>

            <template x-if="auditData && auditData.length === 0">
                <div class="p-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hay inspecciones registradas en este mes.</p>
                </div>
            </template>

            <template x-if="auditData && auditData.length > 0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700/50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Inspector</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Total</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Aprobadas</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-amber-700 dark:text-amber-400 uppercase tracking-wider">Con observación</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-red-700 dark:text-red-400 uppercase tracking-wider">Rechazadas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <template x-for="a in auditData" :key="a.usuario_id">
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                    <td class="px-4 py-3 text-gray-900 dark:text-gray-100" x-text="a.nombre"></td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-gray-100" x-text="a.total"></td>
                                    <td class="px-4 py-3 text-right text-emerald-700 dark:text-emerald-400 font-semibold" x-text="a.aprobadas"></td>
                                    <td class="px-4 py-3 text-right text-amber-700 dark:text-amber-400 font-semibold" x-text="a.aprobadas_observacion"></td>
                                    <td class="px-4 py-3 text-right text-red-700 dark:text-red-400 font-semibold" x-text="a.rechazadas"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <!-- Auditorías pendientes al corte de las 23:50 (turno mañana/tarde) -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.auditorias_pendientes">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3 justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="alarm-clock" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0"></i>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Inspecciones pendientes al corte</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Sin inspeccionar a tiempo = sin inspección registrada antes de las <strong>23:50</strong> de la fecha elegida.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <input type="date" x-model="auditPendFecha" @change="cargarAuditPendientes()"
                           class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                    <button @click="exportarAuditPendientes()" :disabled="auditPendCargando || auditPendExportando"
                            class="min-h-[40px] flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                            aria-label="Exportar">
                        <i data-lucide="download" class="w-4 h-4 flex-shrink-0"></i>
                        <span class="hidden sm:inline" x-text="auditPendExportando ? 'Exportando...' : 'Exportar'"></span>
                    </button>
                    <button @click="cargarAuditPendientes()" :disabled="auditPendCargando"
                            class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                            aria-label="Refrescar">
                        <span :class="auditPendCargando ? 'animate-spin' : ''" class="inline-flex">
                            <i data-lucide="rotate-cw" class="w-4 h-4 text-gray-600 dark:text-gray-400"></i>
                        </span>
                    </button>
                </div>
            </header>

            <template x-if="auditPendCargando && !auditPendData">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Cargando...</div>
            </template>

            <template x-if="auditPendData">
                <div class="p-4 space-y-5">
                    <template x-for="turno in ['mañana', 'tarde']" :key="turno">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300" x-text="turnoLabel(turno)"></h3>
                                <span class="text-xs text-gray-500 dark:text-gray-400"
                                      x-text="auditPendData.turnos[turno].total + ' limpiadas · ' + auditPendData.turnos[turno].pendientes.length + ' sin inspeccionar a tiempo'"></span>
                            </div>

                            <template x-if="auditPendData.turnos[turno].pendientes.length === 0">
                                <p class="text-sm text-emerald-600 dark:text-emerald-400 flex items-center gap-2">
                                    <i data-lucide="check-circle-2" class="w-4 h-4"></i> Todo inspeccionado a tiempo.
                                </p>
                            </template>

                            <template x-if="auditPendData.turnos[turno].pendientes.length > 0">
                                <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50 dark:bg-gray-700/50">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Hotel</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Habitación</th>
                                                <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Nochero</th>
                                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Hora término</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <template x-for="p in auditPendData.turnos[turno].pendientes" :key="turno + '-' + p.habitacion_id + '-' + p.hora_termino">
                                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-300" x-text="hotelLabelCodigo(p.hotel_codigo)"></td>
                                                    <td class="px-3 py-2 font-semibold text-gray-900 dark:text-gray-100" x-text="p.numero"></td>
                                                    <td class="px-3 py-2 text-center" x-text="p.es_nochero ? 'Sí' : '—'"></td>
                                                    <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300" x-text="p.hora_termino"></td>
                                                    <td class="px-3 py-2">
                                                        <span :class="p.estado_auditoria === 'sin_auditar' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
                                                              x-text="p.estado_auditoria === 'sin_auditar' ? 'Sin inspeccionar' : 'Inspeccionada fuera de plazo'"></span>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </section>

    </main>
</div>

<script>
function reportes() {
    var hoy = window.hoyServidor();

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

        // Ficha de KPIs (docs/kpis-sueldos.md): Trabajador N1-N3 + Supervisora N1-N3, mismo rango/hotel.
        ficha:         null,
        fichaCargando: false,
        fichaSeq:      0,     // nº de la última carga pedida: una respuesta vieja que llega tarde no pisa a la nueva
        fichaError:    false, // la ficha se carga aparte de los KPIs clásicos: tiene su propio estado de error
        fichaCols: [
            { clave: 'creditos',         titulo: 'Créditos',     fmt: 'num',      cmp: true,  ayuda: 'Créditos aprobados (auditados + no auditados), incluidas áreas comunes. Solo ítems obligatorios; rechazadas fuera. Si uno mismo rehace su pieza rechazada recupera la mitad al segundo intento y nada desde el tercero.' },
            { clave: 'ab',               titulo: 'Audit. / no',  fmt: 'ab',       cmp: false, ayuda: 'Créditos auditados por una persona / no auditados (cuentan igual como aprobados).' },
            { clave: 'habitaciones',     titulo: 'Piezas',       fmt: 'num',      cmp: true,  ayuda: 'Piezas de huésped que quedaron bien, una vez por pieza por turno. Tras un rechazo la pieza sigue contando como rechazada para esa persona, la rehaga quien la rehaga.' },
            { clave: 'cobertura_pct',    titulo: 'Cobert.',      fmt: 'pct',      cmp: false, ayuda: 'Qué parte de sus piezas fue inspeccionada por una persona (señal de la supervisión, no del trabajador).' },
            { clave: 'tiempo_promedio',  titulo: 'T. prom.',     fmt: 'min',      cmp: true,  ayuda: 'Minutos promedio por pieza. Alerta hacia los dos lados: muy lento o sospechosamente rápido.' },
            { clave: 'esperado',         titulo: 'Asignadas',    fmt: 'esperado', cmp: false, ayuda: 'Lo asignado en el período: piezas · créditos del checklist vigente, una vez por pieza por turno. Pegajoso: no se quita al rechazar ni si la pieza pasa a otra persona. Una asignación retirada sin trabajo que nadie más tomó no cuenta.' },
            { clave: 'realizacion_pct',  titulo: 'Realiz.',      fmt: 'pct',      cmp: true,  ayuda: '(Aprobadas + Rechazadas) ÷ Asignadas — cuánto de lo asignado ejecutó.' },
            { clave: 'cumplimiento_pct', titulo: 'Cumplim.',     fmt: 'pct',      cmp: true,  ayuda: 'Aprobadas ÷ Asignadas — cuánto de lo asignado quedó bien.' },
            { clave: 'calidad_pct',      titulo: 'Calidad',      fmt: 'pct',      cmp: true,  ayuda: 'Aprobadas ÷ (Aprobadas + Rechazadas) — de lo que hizo, cuánto pasó.' },
            { clave: 'rechazo_pct',      titulo: 'Rechazo',      fmt: 'pct',      cmp: true,  ayuda: 'Rechazadas ÷ (Aprobadas + Rechazadas) — lo que hubo que rehacer.' },
            { clave: 'eficiencia_pct',   titulo: 'Eficiencia',   fmt: 'pct',      cmp: true,  ayuda: 'Créditos aprobados ÷ créditos asignados, solo piezas de huésped. Un rechazo baja este número: rehaciendo uno mismo se recupera la mitad al segundo intento y nada desde el tercero.' },
            { clave: 'creditos_por_hab', titulo: 'Créd./pieza',  fmt: 'num',      cmp: true,  ayuda: 'Créditos de piezas de huésped ÷ piezas: dificultad o mezcla del trabajo (informativo, sin rojo).' },
            { clave: 'ritmo',            titulo: 'Ritmo',        fmt: 'ritmo',    cmp: true,  ayuda: 'Créditos de piezas de huésped por hora trabajada en ellas. Alerta hacia los dos lados.' },
        ],

        subtitulo: 'Cargando...',

        // Resumen mensual (independiente)
        mensualMes:        window.hoyServidor().slice(0, 7), // YYYY-MM
        mensualData:       null,
        mensualCargando:   false,
        mensualExportando: false,

        // Resumen mensual de auditorías
        auditMes:        window.hoyServidor().slice(0, 7),
        auditData:       null,
        auditCargando:   false,
        auditExportando: false,

        // Auditorías pendientes al corte de las 23:50 (día puntual, no rango)
        auditPendFecha:      window.hoyServidor(),
        auditPendData:       null,
        auditPendCargando:   false,
        auditPendExportando: false,

        presets: [
            { valor: 'hoy',          label: 'Hoy' },
            { valor: 'semana',       label: 'Últimos 7 días' },
            { valor: 'mes',          label: 'Últimos 30 días' },
            { valor: 'personalizado', label: 'Personalizado' },
        ],

        setPreset(valor) {
            this.preset = valor;
            // Anclar "hoy" a la fecha de America/Santiago y hacer la aritmética de
            // días en UTC-mediodía para que restar días no cruce límites por zona/DST.
            var p = window.hoyServidor().split('-');
            var hoyDate = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2], 12));
            var fmt = function (d) {
                return d.getUTCFullYear() + '-' +
                    String(d.getUTCMonth() + 1).padStart(2, '0') + '-' +
                    String(d.getUTCDate()).padStart(2, '0');
            };
            if (valor === 'hoy') {
                this.desde = this.hasta = fmt(hoyDate);
            } else if (valor === 'semana') {
                var d = new Date(hoyDate); d.setUTCDate(d.getUTCDate() - 6);
                this.desde = fmt(d); this.hasta = fmt(hoyDate);
            } else if (valor === 'mes') {
                var d = new Date(hoyDate); d.setUTCDate(d.getUTCDate() - 29);
                this.desde = fmt(d); this.hasta = fmt(hoyDate);
            }
            if (valor !== 'personalizado') this.cargar();
        },

        async cargar() {
            this.cargando = true;
            this.error    = false;
            this.cargarFicha(); // en paralelo, con los mismos filtros; no bloquea los KPIs clásicos
            try {
                var params = new URLSearchParams({
                    desde:      this.desde,
                    hasta:      this.hasta,
                    hotel:      this.hotel,
                });
                if (this.usuarioId) params.set('usuario_id', this.usuarioId);

                var resp = await fetch(u('/api/reportes/kpis?' + params.toString()));
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
                var resp = await fetch(u('/api/reportes/resumen-mensual?' + params.toString()));
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

        hotelLabelCodigo(codigo) {
            return { '1_sur': 'Atankalama', inn: 'Atankalama INN' }[codigo] || codigo;
        },

        // El nombre del turno solo no dice cuándo empieza/termina — la hora de corte
        // (18:00) es la regla de negocio que separa mañana de tarde, hay que mostrarla.
        turnoLabel(turno) {
            return turno === 'mañana'
                ? 'Turno mañana (antes de las 18:00)'
                : 'Turno tarde (18:00 en adelante)';
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

                var resp = await fetch(u('/api/reportes/exportar?' + params.toString()));
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

        async cargarAudit() {
            var partes = (this.auditMes || '').split('-');
            if (partes.length !== 2) return;
            var anio = parseInt(partes[0], 10);
            var mes  = parseInt(partes[1], 10);
            if (!anio || !mes) return;
            this.auditCargando = true;
            try {
                var params = new URLSearchParams({ anio: anio, mes: mes, hotel: this.hotel });
                var resp = await fetch(u('/api/reportes/resumen-mensual-auditores?' + params.toString()));
                var json = await resp.json();
                if (json.ok) {
                    this.auditData = json.data.auditores || [];
                    this.$nextTick(() => lucide.createIcons());
                } else {
                    this.auditData = [];
                }
            } catch (e) {
                this.auditData = [];
            } finally {
                this.auditCargando = false;
            }
        },

        async exportarAudit() {
            if (this.auditExportando) return;
            var partes = (this.auditMes || '').split('-');
            if (partes.length !== 2) return;
            var anio = parseInt(partes[0], 10);
            var mes  = parseInt(partes[1], 10);
            if (!anio || !mes) return;
            this.auditExportando = true;
            try {
                var params = new URLSearchParams({ anio: anio, mes: mes, hotel: this.hotel });
                var resp = await fetch(u('/api/reportes/exportar-mensual-auditores?' + params.toString()));
                if (!resp.ok) { this.auditExportando = false; return; }

                var blob = await resp.blob();
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = 'reporte_inspecciones_' + this.auditMes + '.csv';
                a.click();
                URL.revokeObjectURL(url);
            } catch (e) { /* silencioso */ } finally {
                this.auditExportando = false;
            }
        },

        async exportarMensual() {
            if (this.mensualExportando) return;
            var partes = (this.mensualMes || '').split('-');
            if (partes.length !== 2) return;
            var anio = parseInt(partes[0], 10);
            var mes  = parseInt(partes[1], 10);
            if (!anio || !mes) return;
            this.mensualExportando = true;
            try {
                var params = new URLSearchParams({ anio: anio, mes: mes, hotel: this.hotel });
                var resp = await fetch(u('/api/reportes/exportar-mensual?' + params.toString()));
                if (!resp.ok) { this.mensualExportando = false; return; }

                var blob = await resp.blob();
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = 'reporte_mensual_' + this.mensualMes + '.csv';
                a.click();
                URL.revokeObjectURL(url);
            } catch (e) { /* silencioso */ } finally {
                this.mensualExportando = false;
            }
        },

        async cargarAuditPendientes() {
            if (!this.auditPendFecha) return;
            this.auditPendCargando = true;
            try {
                var params = new URLSearchParams({ fecha: this.auditPendFecha, hotel: this.hotel });
                var resp = await fetch(u('/api/reportes/auditorias-pendientes?' + params.toString()));
                var json = await resp.json();
                if (json.ok) {
                    this.auditPendData = json.data;
                    this.$nextTick(() => lucide.createIcons());
                } else {
                    this.auditPendData = null;
                }
            } catch (e) {
                this.auditPendData = null;
            } finally {
                this.auditPendCargando = false;
            }
        },

        async exportarAuditPendientes() {
            if (this.auditPendExportando || !this.auditPendFecha) return;
            this.auditPendExportando = true;
            try {
                var params = new URLSearchParams({ fecha: this.auditPendFecha, hotel: this.hotel });
                var resp = await fetch(u('/api/reportes/exportar-auditorias-pendientes?' + params.toString()));
                if (!resp.ok) { this.auditPendExportando = false; return; }

                var blob = await resp.blob();
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = 'reporte_inspecciones_pendientes_' + this.auditPendFecha + '.csv';
                a.click();
                URL.revokeObjectURL(url);
            } catch (e) { /* silencioso */ } finally {
                this.auditPendExportando = false;
            }
        },

        // ── Ficha de KPIs ──────────────────────────────────────────────────────
        async cargarFicha() {
            // La ficha es la llamada más pesada de la pantalla: si el usuario cambia de período antes de
            // que responda, gana la ÚLTIMA pedida, no la última en llegar (mismo período que los KPIs).
            var seq = ++this.fichaSeq;
            this.fichaCargando = true;
            this.fichaError = false;
            try {
                var params = new URLSearchParams({ desde: this.desde, hasta: this.hasta, hotel: this.hotel });
                var resp = await fetch(u('/api/reportes/ficha?' + params.toString()));
                var json = await resp.json();
                if (seq !== this.fichaSeq) return;
                this.ficha = json.ok ? json.data : null;
                this.fichaError = !json.ok;
            } catch (e) {
                if (seq === this.fichaSeq) { this.ficha = null; this.fichaError = true; }
            } finally {
                if (seq === this.fichaSeq) {
                    this.fichaCargando = false;
                    this.$nextTick(() => lucide.createIcons());
                }
            }
        },

        fmtNull(v) { return (v === null || v === undefined) ? '—' : v; },
        fmtPct(v)  { return (v === null || v === undefined) ? '—' : (v + ' %'); },

        fmtCol(t, c) {
            var v = t[c.clave];
            switch (c.fmt) {
                case 'pct':      return this.fmtPct(v);
                case 'min':      return v === null ? '—' : (v + ' min');
                case 'ritmo':    return v === null ? '—' : (v + ' cr/h');
                case 'ab':       return t.creditos_auditados + ' / ' + t.creditos_no_auditados;
                case 'esperado': return t.esperado_hab + ' · ' + t.esperado_creditos + ' cr';
                default:         return this.fmtNull(v);
            }
        },

        fmtPromedio(c) {
            if (!this.ficha || !c.cmp) return '';
            var s = this.ficha.comparativa[c.clave];
            if (!s || s.promedio === null) return '—';
            var sufijo = c.fmt === 'pct' ? ' %' : (c.fmt === 'min' ? ' min' : (c.fmt === 'ritmo' ? ' cr/h' : ''));
            return s.promedio + sufijo + (s.sigma !== null ? ' ±' + s.sigma : '');
        },

        // Semáforo del Nivel 3 (comparación con el grupo): color del punto junto a cada valor.
        dotSem(t, kpi) {
            var c = t.cmp && t.cmp[kpi];
            if (!c) return 'bg-gray-300 dark:bg-gray-600';
            return {
                ok:          'bg-emerald-500',
                alerta:      'bg-amber-400',
                critico:     'bg-red-500',
                informativo: 'bg-blue-500',
            }[c.estado] || 'bg-gray-300 dark:bg-gray-600';
        },

        cmpTitle(t, kpi) {
            var c = t.cmp && t.cmp[kpi];
            if (!c || c.estado === 'sin_datos') return 'Pocos datos para comparar con el equipo.';
            var signo = c.delta > 0 ? '+' : '';
            return 'Diferencia vs. promedio del equipo: ' + signo + c.delta + ' (' + c.z + 'σ)';
        },

        dotEstado(estado) {
            return {
                ok: 'bg-emerald-500', alerta: 'bg-amber-400', critico: 'bg-red-500', informativo: 'bg-blue-500',
            }[estado] || 'bg-gray-300 dark:bg-gray-600';
        },

        seccionCards() {
            if (!this.ficha) return [];
            var s = this.ficha.supervisoras.seccion;
            return [
                { clave: 'cobertura',          titulo: 'Cobertura de inspección',     d: s.cobertura,          metaTxt: '≥ ' + s.cobertura.meta + ' %' },
                { clave: 'rechazo',            titulo: 'Rechazo de la sección',       d: s.rechazo,            metaTxt: '≤ ' + s.rechazo.meta + ' %' },
                { clave: 'aprobacion_primera', titulo: 'Aprobación a la 1ª (sección)', d: s.aprobacion_primera, metaTxt: '≥ ' + s.aprobacion_primera.meta + ' %' },
            ];
        },

        tendenciaTxt(d) {
            if (!d || d.tendencia === 'sin_datos') return 'Sin período anterior para comparar';
            var flecha = { mejora: '▲', empeora: '▼', igual: '=' }[d.tendencia] || '';
            var signo = d.delta > 0 ? '+' : '';
            return flecha + ' ' + signo + d.delta + ' pts vs. período anterior (' + d.anterior + ' %)';
        },

        tendenciaClase(t) {
            return { mejora: 'text-emerald-600 dark:text-emerald-400', empeora: 'text-red-600 dark:text-red-400' }[t]
                || 'text-gray-400 dark:text-gray-500';
        },

        deltaTxt(delta, sufijo) {
            if (delta === null || delta === undefined) return '';
            var signo = delta > 0 ? '+' : '';
            return signo + delta + (sufijo || '') + ' vs. prom.';
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
