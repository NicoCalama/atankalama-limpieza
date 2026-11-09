<?php
/**
 * Vista de Reportes y KPIs.
 * Permiso requerido: reportes.ver
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="reportes()"
     x-init="iniciar()"
     @hashchange.window="leerPestanaDeUrl()"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header + pestañas (v7.2: la pantalla creció a 9 bloques; se ordena en 4 pestañas dedicadas) -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 pt-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <a href="<?= u('/home') ?>" class="md:hidden min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </a>
                <i data-lucide="bar-chart-3" class="w-6 h-6 text-gray-700 dark:text-gray-300 hidden md:block flex-shrink-0"></i>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reportes</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="subtituloTxt()"></p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <button @click="abrirExportar()" data-tour="rep.exportar"
                        :disabled="exportando"
                        class="min-h-[44px] flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800
                               text-white text-sm font-semibold rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed flex-shrink-0">
                    <i data-lucide="download" class="w-4 h-4 flex-shrink-0"></i>
                    <span class="hidden sm:inline" x-text="exportando ? 'Exportando...' : 'Exportar Excel'"></span>
                </button>
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
        </div>

        <!-- Pestañas: en el teléfono, 4 columnas con el ícono arriba (caben las 4 en 375 px, sin desplazar);
             desde md, en fila. La elegida queda en la URL (#mensual) para recargar sin perderla. -->
        <nav class="max-w-5xl mx-auto mt-2 -mb-px grid grid-cols-4 md:flex md:gap-1" role="tablist" aria-label="Secciones de Reportes" data-tour="rep.pestanas">
            <template x-for="p in pestanas" :key="p.id">
                <button type="button" role="tab" @click="cambiarPestana(p.id)"
                        :aria-selected="pestana === p.id ? 'true' : 'false'"
                        :class="pestana === p.id
                            ? 'border-blue-600 text-blue-700 dark:text-blue-300'
                            : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                        class="min-h-[44px] flex flex-col md:flex-row items-center justify-center gap-0.5 md:gap-1.5 px-1 md:px-3 py-1 border-b-2 text-[11px] md:text-sm font-medium whitespace-nowrap transition">
                    <i :data-lucide="p.icono" class="w-4 h-4 flex-shrink-0"></i>
                    <span x-text="p.label"></span>
                </button>
            </template>
        </nav>
    </header>

    <main class="max-w-5xl mx-auto p-4 pb-24 md:pb-6 space-y-4">

        <!-- Filtros: cambian según la pestaña. Período (Aseo / Supervisoras / Recepción) o mes (Mensual);
             el hotel es común a todas; la trabajadora solo filtra Aseo. -->
        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4" data-tour="rep.filtros">

            <template x-if="pestana !== 'mensual'">
                <div>
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
                            <input type="date" x-model="desde" @change="alCambiarPeriodo()"
                                   class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100">
                        </div>
                        <div class="flex-1">
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Hasta</label>
                            <input type="date" x-model="hasta" @change="alCambiarPeriodo()"
                                   class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100">
                        </div>
                    </div>
                </div>
            </template>

            <!-- Mes de la pestaña Mensual: uno solo para los dos resúmenes -->
            <template x-if="pestana === 'mensual'">
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <label for="rep-mes" class="text-xs font-medium text-gray-600 dark:text-gray-300">Mes</label>
                    <input id="rep-mes" type="month" x-model="mes" @change="alCambiarMes()"
                           class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                </div>
            </template>

            <!-- Hotel + Trabajadora -->
            <div class="flex flex-wrap gap-2">
                <select x-model="hotel" @change="alCambiarHotel()" aria-label="Hotel"
                        class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                    <option value="ambos">Ambos hoteles</option>
                    <option value="1_sur">Atankalama</option>
                    <option value="inn">Atankalama INN</option>
                </select>

                <select x-show="pestana === 'trabajadores'" x-model="usuarioId" @change="alCambiarTrabajadora()" aria-label="Trabajadora"
                        class="min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200 flex-1 min-w-[180px]">
                    <option value="">Todas las trabajadoras</option>
                    <template x-for="t in trabajadoras" :key="t.usuario_id">
                        <option :value="t.usuario_id" x-text="t.nombre"></option>
                    </template>
                </select>

                <!-- Refrescar: vuelve a pedir lo de la pestaña abierta -->
                <button @click="refrescar()" :disabled="cargandoPestana()"
                        class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700
                               hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                        aria-label="Refrescar">
                    <span :class="cargandoPestana() ? 'animate-spin' : ''" class="inline-flex">
                        <i data-lucide="rotate-cw" class="w-4 h-4 text-gray-600 dark:text-gray-400"></i>
                    </span>
                </button>
            </div>
        </div>

        <!-- Aviso de una descarga que falló: flotante, para que se vea esté donde esté la pantalla -->
        <template x-if="avisoExport">
            <div role="alert" class="fixed z-50 bottom-24 left-4 right-4 md:bottom-6 md:left-auto md:right-6 md:max-w-md shadow-lg flex flex-wrap items-center gap-3 bg-amber-50 dark:bg-amber-900 border border-amber-200 dark:border-amber-700 rounded-xl px-4 py-3">
                <i data-lucide="download" class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0"></i>
                <p class="text-sm text-amber-800 dark:text-amber-200 flex-1 min-w-[200px]" x-text="avisoExport"></p>
                <button @click="avisoExport = ''" class="min-h-[44px] px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium">Cerrar</button>
            </div>
        </template>

        <!-- Ventana «Exportar»: un solo botón para todas las descargas (pedido de Nicolás, 04/10/2026) -->
        <div x-show="exportarAbierto" x-cloak
             @keydown.escape.window="exportarAbierto && cerrarExportar()"
             @click.self="cerrarExportar()"
             role="dialog" aria-modal="true" aria-labelledby="exportar-titulo"
             class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50">
            <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">
                <h3 id="exportar-titulo" class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">¿Qué quieres exportar?</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4" x-text="'Hotel: ' + hotelLabel()"></p>

                <div class="space-y-2 mb-5">
                    <!-- Resumen mensual: Excel con tres pestañas -->
                    <label class="flex gap-3 p-3 rounded-lg border cursor-pointer transition"
                           :class="exportarTipo === 'mensual' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700'">
                        <input type="radio" name="exportar-tipo" value="mensual" x-model="exportarTipo" class="mt-1 w-4 h-4 flex-shrink-0">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Resumen mensual</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Excel con tres pestañas: «Aseo» (del hotel elegido), «Supervisores» y «Recepción» (cada hotel por separado y el total).</p>
                            <input type="month" x-show="exportarTipo === 'mensual'" x-model="exportarMes" aria-label="Mes del resumen"
                                   class="mt-2 min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                        </div>
                    </label>

                    <!-- KPIs del período de arriba -->
                    <label class="flex gap-3 p-3 rounded-lg border cursor-pointer transition"
                           :class="exportarTipo === 'kpis' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700'">
                        <input type="radio" name="exportar-tipo" value="kpis" x-model="exportarTipo" class="mt-1 w-4 h-4 flex-shrink-0">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">KPIs del período</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="'Los indicadores con los filtros de arriba: ' + periodoExportar() + '.'"></p>
                        </div>
                    </label>

                    <!-- Inspecciones pendientes al corte de un día -->
                    <label class="flex gap-3 p-3 rounded-lg border cursor-pointer transition"
                           :class="exportarTipo === 'pendientes' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700'">
                        <input type="radio" name="exportar-tipo" value="pendientes" x-model="exportarTipo" class="mt-1 w-4 h-4 flex-shrink-0">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Inspecciones pendientes del día</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Las piezas que quedaron sin inspeccionar antes de las 23:50, por turno.</p>
                            <input type="date" x-show="exportarTipo === 'pendientes'" x-model="exportarFecha" aria-label="Día de las inspecciones pendientes"
                                   class="mt-2 min-h-[40px] px-3 py-2 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">
                        </div>
                    </label>
                </div>

                <div class="flex gap-2 justify-end">
                    <button @click="cerrarExportar()" :disabled="exportando"
                            class="min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 disabled:opacity-50">
                        Cancelar
                    </button>
                    <button @click="confirmarExportar()" :disabled="exportando || !exportarListo()"
                            class="min-h-[44px] flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white disabled:opacity-50 disabled:cursor-not-allowed">
                        <i data-lucide="download" class="w-4 h-4 flex-shrink-0"></i>
                        <span x-text="exportando ? 'Descargando...' : 'Descargar'"></span>
                    </button>
                </div>
            </div>
        </div>

        <?php if ($usuario->tienePermiso('horas_extras.registrar')): ?>
        <!-- Ventana «Horas extras» (v7.17; Nicolás, 08/11/2026): total del mes por persona de Aseo en una
             cuadrícula del año. Cada mes se escribe hasta el 6 del mes siguiente; lo demás, solo lectura. -->
        <div x-show="extrasAbierto" x-cloak
             @keydown.escape.window="extrasAbierto && cerrarExtras()"
             @click.self="cerrarExtras()"
             role="dialog" aria-modal="true" aria-labelledby="extras-titulo"
             class="fixed inset-0 z-50 flex items-stretch md:items-center justify-center md:p-4 bg-black/50">
            <div class="bg-white dark:bg-gray-800 w-full md:max-w-5xl h-full md:h-auto md:max-h-[90vh] md:rounded-xl shadow-xl flex flex-col min-h-0">
                <!-- Encabezado: título, año y cerrar -->
                <div class="px-4 pt-4 pb-3 border-b border-gray-200 dark:border-gray-700 space-y-3">
                    <div class="flex items-center gap-2">
                        <i data-lucide="clock-plus" class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                        <h3 id="extras-titulo" class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex-1 min-w-0">Horas extras</h3>
                        <div class="flex items-center">
                            <button type="button" @click="cambiarAnioExtras(-1)" :disabled="extrasCargando || !extrasAnio || extrasAnio <= extrasAnioMin()"
                                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30" aria-label="Año anterior">
                                <i data-lucide="chevron-left" class="w-5 h-5"></i>
                            </button>
                            <span class="w-12 text-center text-sm font-semibold text-gray-900 dark:text-gray-100 tabular-nums" x-text="extrasAnio || ''"></span>
                            <button type="button" @click="cambiarAnioExtras(1)" :disabled="extrasCargando || !extrasAnio || extrasAnio >= extrasAnioMax"
                                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30" aria-label="Año siguiente">
                                <i data-lucide="chevron-right" class="w-5 h-5"></i>
                            </button>
                        </div>
                        <button type="button" @click="cerrarExtras()"
                                class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        El total de horas extras del mes de cada persona de Aseo. Cada mes se llena hasta el 6 del mes siguiente y se guarda solo al salir de la casilla.
                    </p>
                    <div class="relative">
                        <label class="sr-only" for="extras-buscar">Buscar persona</label>
                        <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                        <input id="extras-buscar" x-model="extrasBusqueda" type="search" autocomplete="off"
                               @input="$nextTick(function () { lucide.createIcons(); })"
                               placeholder="Buscar por nombre..."
                               class="w-full pl-9 pr-3 py-2 min-h-[44px] border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-lg text-sm">
                    </div>
                </div>

                <!-- Cuerpo -->
                <div class="flex-1 min-h-0 overflow-auto" x-ref="extrasScroll">
                    <template x-if="extrasCargando && !extrasData">
                        <div class="flex flex-col items-center justify-center py-16 gap-3">
                            <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Cargando...</p>
                        </div>
                    </template>

                    <template x-if="extrasError">
                        <div class="p-8 text-center">
                            <i data-lucide="alert-circle" class="w-10 h-10 text-red-500 mx-auto mb-3"></i>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3" x-text="extrasError"></p>
                            <button type="button" @click="cargarExtras(extrasAnio)" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
                        </div>
                    </template>

                    <template x-if="!extrasError && extrasData && extrasData.personas.length === 0">
                        <div class="p-8 text-center">
                            <i data-lucide="users" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">No hay personal de Aseo para este año.</p>
                        </div>
                    </template>

                    <template x-if="!extrasError && extrasData && extrasData.personas.length > 0">
                        <div>
                            <table class="min-w-full text-sm border-separate border-spacing-0">
                                <thead>
                                    <tr>
                                        <th class="sticky top-0 left-0 z-30 bg-gray-50 dark:bg-gray-700 px-4 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 min-w-[9rem]">Persona</th>
                                        <template x-for="m in extrasMeses()" :key="m">
                                            <th class="sticky top-0 z-20 px-1.5 py-2 text-center text-xs font-semibold uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 whitespace-nowrap"
                                                :class="mesEditable(m) ? 'bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300' : 'bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400'"
                                                :data-abierto="mesEditable(m) ? '1' : null">
                                                <span x-text="mesCorto(m)"></span>
                                                <template x-if="mesEditable(m)">
                                                    <span class="block text-[10px] font-normal normal-case tracking-normal" x-text="plazoMes(m)"></span>
                                                </template>
                                            </th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="p in extrasVisibles()" :key="p.id">
                                        <tr>
                                            <td class="sticky left-0 z-10 bg-white dark:bg-gray-800 px-4 py-1.5 border-b border-gray-100 dark:border-gray-700 text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                                <span x-text="p.nombre"></span>
                                                <template x-if="!p.activo">
                                                    <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" title="Ya no está activa en la app.">Inactiva</span>
                                                </template>
                                            </td>
                                            <template x-for="m in extrasMeses()" :key="p.id + '|' + m">
                                                <td class="px-1.5 py-1.5 border-b border-gray-100 dark:border-gray-700 text-center"
                                                    :class="mesEditable(m) ? 'bg-blue-50/60 dark:bg-blue-900/20' : ''">
                                                    <template x-if="mesEditable(m)">
                                                        <input type="number" inputmode="decimal" step="0.5" min="0" max="100"
                                                               :value="p.meses[m] != null ? p.meses[m] : ''"
                                                               @change="guardarExtra(p, m, $event.target)"
                                                               :aria-label="'Horas extras de ' + p.nombre + ' en ' + mesLargo(m)"
                                                               class="w-16 min-h-[44px] px-2 py-1 text-sm text-right rounded-lg border bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 tabular-nums"
                                                               :class="claseCeldaExtra(p.id + '|' + m)">
                                                    </template>
                                                    <template x-if="!mesEditable(m)">
                                                        <span class="tabular-nums" :class="p.meses[m] != null ? 'text-gray-900 dark:text-gray-100' : 'text-gray-300 dark:text-gray-600'"
                                                              x-text="fmtHoras(p.meses[m])"></span>
                                                    </template>
                                                </td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p x-show="extrasVisibles().length === 0" class="px-4 py-6 text-sm text-center text-gray-500 dark:text-gray-400">
                                Nadie de Aseo se llama así. Revisa el nombre.
                            </p>
                        </div>
                    </template>
                </div>

                <!-- Pie: aviso de error y cerrar -->
                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3">
                    <p class="flex-1 min-w-[12rem] text-xs"
                       :class="extrasAviso ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'"
                       x-text="extrasAviso || 'Se suman a las horas en Aseo del mes, con las que se calcula el bono.'"></p>
                    <button type="button" @click="cerrarExtras()"
                            class="min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════════ Pestaña TRABAJADORES ═══════════════ -->
        <template x-if="pestana === 'trabajadores'">
        <div class="space-y-4">

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
                <p class="text-gray-600 dark:text-gray-400" x-text="error"></p>
                <button @click="cargarKpis()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">
                    Reintentar
                </button>
            </div>
        </template>
        <!-- Error con datos anteriores en pantalla: no se deja pasar por el período nuevo -->
        <template x-if="error && data">
            <div class="flex flex-wrap items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3">
                <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0"></i>
                <p class="text-sm text-red-700 dark:text-red-300 flex-1 min-w-[200px]" x-text="error + ' Los números de abajo son de la consulta anterior.'"></p>
                <button @click="cargarKpis()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
            </div>
        </template>

        <!-- KPIs del equipo + detalle por trabajadora -->
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
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap" :title="AYUDA_DIAS">Días trab.</th>
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
                                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                                <span x-text="primerNombre(t.nombre)"></span>
                                                <template x-if="t.jornada">
                                                    <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" :title="jornadaTitulo(t.jornada)" x-text="jornadaCorta(t.jornada)"></span>
                                                </template>
                                            </td>
                                            <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400"
                                                x-text="t.dias_trabajados"></td>
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
                                            <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400 whitespace-nowrap"
                                                :title="t.kpis.productividad ? t.kpis.productividad.contexto : ''"
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
                                            <template x-if="t.jornada">
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" :title="jornadaTitulo(t.jornada)" x-text="jornadaCorta(t.jornada)"></span>
                                            </template>
                                            <template x-if="!t.datos_suficientes">
                                                <span class="ml-1 text-[10px] uppercase tracking-wide text-gray-400 dark:text-gray-500" title="Menos piezas trabajadas (aprobadas + rechazadas) que el mínimo configurado: sin semáforo y fuera del promedio.">pocos datos</span>
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
                        <span>· mínimo <span x-text="ficha.config.min_datos"></span> piezas trabajadas (aprobadas + rechazadas) para comparar · pasa el mouse por un punto para ver la diferencia vs. el promedio</span>
                    </p>
                </div>
            </template>
        </section>

        </div>
        </template>
        <!-- ═══════════════ fin pestaña TRABAJADORES ═══════════════ -->

        <!-- Privacidad jerárquica (jefatura, 16/09): «Supervisora · Inspección» se renderiza solo con
             reportes.ver_supervisoras; el backend tampoco la envía sin el permiso. La bandera gatea el tour.
             (Desde la v7.10 ya no trae el tiempo por auditación.) -->
        <!-- carga_extras (v7.17): el botón «Horas extras» de la pestaña Mensual sale solo con horas_extras.registrar. -->
        <div data-vg-context='{"ve_supervisoras": <?= $usuario->tienePermiso('reportes.ver_supervisoras') ? 'true' : 'false' ?>, "carga_extras": <?= $usuario->tienePermiso('horas_extras.registrar') ? 'true' : 'false' ?>}' hidden></div>

        <!-- ═══════════════ Pestaña SUPERVISORAS ═══════════════ -->
        <template x-if="pestana === 'supervisoras'">
        <div class="space-y-4">

        <!-- ═══ Supervisora · Inspección (N1 + N2 + N3) ═══ -->
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
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Lo que ella inspeccionó sobre todo lo limpiado en la sección.">Aporte cobert.</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="De las piezas que aprobó, las que Recepción aprobó (verde) y no aprobó (rojo) para entregar en la inspección pre-entrega, revisadas mientras seguían aprobadas y según la fecha de la revisión de Recepción. Una pieza cuenta una vez: si recibió algún NO, cuenta como no aprobada.">Recepción</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Calidad según Recepción: (aprobadas − no aprobadas) ÷ las que Recepción revisó × 100, con las mismas piezas de la columna «Recepción». Cada NO pesa el doble (18 SÍ y 2 NO = 80 %). Nunca baja de 0 %. Sin revisiones de Recepción en el período: —.">Calidad</th>
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
                                                <span class="text-gray-700 dark:text-gray-300" x-text="fmtPct(i.aporte_cobertura_pct)"></span>
                                                <span class="block text-[11px] text-gray-400 dark:text-gray-500" x-text="deltaTxt(i.cmp.aporte_cobertura_pct, ' pts')"></span>
                                            </td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                <span class="font-semibold text-emerald-700 dark:text-emerald-400" x-text="i.recepcion_aprobadas"></span><span class="text-gray-400 dark:text-gray-500"> / </span><span class="font-semibold text-red-700 dark:text-red-400" x-text="i.recepcion_rechazadas"></span>
                                            </td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap tabular-nums text-gray-700 dark:text-gray-300" x-text="fmtPct(i.recepcion_calidad_pct)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-700/40 border-t border-gray-200 dark:border-gray-700">
                                    <tr class="text-xs text-gray-600 dark:text-gray-300">
                                        <td class="px-3 py-2 font-semibold">Promedio</td>
                                        <td class="px-3 py-2 text-right" x-text="fmtNull(ficha.supervisoras.comparativa.total.promedio)"></td>
                                        <td class="px-3 py-2" colspan="3"></td>
                                        <td class="px-3 py-2 text-right" x-text="fmtPct(ficha.supervisoras.comparativa.aporte_cobertura_pct.promedio)"></td>
                                        <td class="px-3 py-2"></td>
                                        <td class="px-3 py-2"></td>
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

            <template x-if="auditPendError">
                <div class="p-8 text-center">
                    <i data-lucide="alert-circle" class="w-10 h-10 text-red-500 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3" x-text="auditPendError"></p>
                    <button @click="cargarAuditPendientes()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
                </div>
            </template>

            <template x-if="!auditPendError && auditPendData">
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
                                                        <span :class="p.estado_auditoria === 'auditada_tarde' ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'"
                                                              x-text="estadoPendiente(p)"></span>
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

        </div>
        </template>
        <!-- ═══════════════ fin pestaña SUPERVISORAS ═══════════════ -->

        <!-- ═══════════════ Pestaña MENSUAL ═══════════════ -->
        <template x-if="pestana === 'mensual'">
        <div class="space-y-4">

        <!-- Resumen mensual por trabajador (mes y hotel de los filtros de la pestaña) -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.mensual">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 min-w-0">
                    <div class="flex items-center gap-2 min-w-0">
                        <i data-lucide="calendar" class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Resumen mensual por trabajador</h2>
                    </div>
                    <!-- Corte del bono de aseo (único dato manual; se guarda por mes). Por hora desde noviembre de 2026 (v7.18). -->
                    <div class="flex items-center gap-2" :title="mensualPorHora ? 'Corte de habitaciones por hora para el bono de aseo de RRHH, el mismo para jornada completa y parcial. Depende de la ocupación: se guarda para el mes elegido.' : 'Corte de habitaciones por día con jornada completa para el bono de aseo de RRHH. Jornada parcial = la mitad. Depende de la ocupación: se guarda para el mes elegido.'">
                        <label for="corte-hab-dia" class="text-xs font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap" x-text="mensualPorHora ? 'Corte hab./hora' : 'Corte hab./día'"></label>
                        <input id="corte-hab-dia" type="number" inputmode="decimal"
                               :step="mensualPorHora ? '0.01' : '0.5'" :min="mensualPorHora ? '0.5' : '1'" :max="mensualPorHora ? '6' : '100'"
                               x-model="corteInput" @change="guardarCorte()"
                               :disabled="!puedeEditarCorte || guardandoCorte || mensualCargando"
                               class="w-20 min-h-[40px] px-2 py-1 text-sm text-right bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100 disabled:opacity-60 disabled:cursor-not-allowed">
                        <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap" x-text="corteNota()"></span>
                    </div>
                    <?php if ($usuario->tienePermiso('horas_extras.registrar')): ?>
                    <!-- Horas extras del mes por persona (v7.17): cuadrícula del año, solo administración -->
                    <button type="button" @click="abrirExtras()" data-tour="rep.horas_extras"
                            class="min-h-[44px] flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                        <i data-lucide="clock-plus" class="w-4 h-4 flex-shrink-0"></i>
                        <span>Horas extras</span>
                    </button>
                    <?php endif; ?>
                    <template x-if="corteError">
                        <span class="text-xs text-red-600 dark:text-red-400" x-text="corteError"></span>
                    </template>
                </div>
            </header>

            <template x-if="mensualCargando && !mensualData">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Cargando...</div>
            </template>

            <template x-if="mensualError">
                <div class="p-8 text-center">
                    <i data-lucide="alert-circle" class="w-10 h-10 text-red-500 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3" x-text="mensualError"></p>
                    <button @click="cargarMensual()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
                </div>
            </template>

            <template x-if="!mensualError && mensualData && mensualData.length === 0">
                <div class="p-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hay actividad registrada en este mes.</p>
                </div>
            </template>

            <template x-if="!mensualError && mensualData && mensualData.length > 0">
                <div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Trabajador</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" :title="AYUDA_DIAS">Días trab.</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Todas las habitaciones que limpió, hayan quedado bien o no: las que quedaron bien + las rechazadas. Informativo: el bono se calcula solo con las que quedaron bien.">Hab. limpiadas</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Habitaciones que quedaron bien (aprobadas o sin inspeccionar), una por limpieza, y en rojo las rechazadas. Las que quedaron bien son las «hab. hechas» del bono de aseo.">Desglose hab.</th>
                                    <th x-show="mensualPorHora" class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Horas en Aseo del mes: turnos de los días trabajados + horas extras − horas en otras áreas. Pasa el mouse por el número de cada persona para ver el detalle.">Horas</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" :title="mensualPorHora ? 'Hab. hechas ÷ horas en Aseo.' : 'Hab. hechas ÷ días trabajados.'" x-text="mensualPorHora ? 'Act./hora' : 'Act./día'"></th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Casillas del checklist que la supervisora le desmarcó al inspeccionar (en aprobadas con observación y en rechazadas).">Observ.</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Observaciones ÷ hab. hechas (tope 100 %).">% observ.</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" :title="mensualPorHora ? 'Act./hora ÷ corte del mes, con tope 100 %.' : 'Act./día ÷ base de su jornada (corte; parcial = la mitad), con tope 100 %.'">Eficacia</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="(1 − % observadas) × eficacia.">% logro</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Hasta 70 % de logro: 0,427 × logro. Sobre 70 %: 2,333 × logro − 1,333.">Factor</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="% logro × factor de peso (el «% calidad» de la planilla de RRHH).">KPIs Calidad</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" :title="mensualPorHora ? 'Habitaciones sobre el corte en el mes: hab. hechas − corte × horas en Aseo.' : 'Habitaciones sobre la base de su jornada en el mes: (act./día − base) × días trabajados.'">Extras</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap">Créditos</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Créditos de piezas ÷ créditos asignados.">Eficiencia</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <template x-for="t in mensualData" :key="t.usuario_id">
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                        <td class="px-4 py-3 text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                            <span x-text="t.nombre"></span>
                                            <template x-if="t.jornada">
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400" :title="jornadaTitulo(t.jornada)" x-text="jornadaCorta(t.jornada)"></span>
                                            </template>
                                            <template x-if="!t.jornada">
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300" title="Sin jornada definida: asígnala en Usuarios para calcular eficacia, logro, KPIs calidad y extras.">Sin jornada</span>
                                            </template>
                                            <template x-if="mensualPorHora && avisoHoras(t.horas_aseo)">
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300" :title="avisoHoras(t.horas_aseo).titulo" x-text="avisoHoras(t.horas_aseo).texto"></span>
                                            </template>
                                        </td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="t.dias_trabajados"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="t.limpiadas"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums">
                                            <span class="font-semibold text-gray-900 dark:text-gray-100" x-text="t.habitaciones"></span>
                                            <template x-if="t.rechazadas > 0">
                                                <span class="text-xs text-red-600 dark:text-red-400" x-text="' + ' + t.rechazadas + ' rech.'"></span>
                                            </template>
                                        </td>
                                        <td x-show="mensualPorHora" class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" :title="detalleHoras(t.horas_aseo)" x-text="fmtNull(t.horas_aseo ? t.horas_aseo.aseo : null)"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="fmtNull(mensualPorHora ? t.bono.act_hora : t.bono.act_dia)"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="t.observaciones"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="fmtPct(t.bono.observadas_pct)"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="fmtPct(t.bono.eficacia_pct)"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="fmtPct(t.bono.logro_pct)"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="fmtNull(t.bono.factor_peso)"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums font-semibold text-gray-900 dark:text-gray-100" x-text="fmtPct(t.bono.resultado_pct)"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="fmtNull(t.bono.extras)"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums font-semibold text-gray-900 dark:text-gray-100" x-text="t.creditos"></td>
                                        <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300 whitespace-nowrap tabular-nums" x-text="fmtPct(t.eficiencia_pct)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p x-show="!mensualPorHora" class="px-4 py-2 text-xs text-gray-400 dark:text-gray-500">
                        Mismos números que la ficha del mes: días trabajados (días con al menos una pieza asignada), habitaciones que quedaron bien (una por limpieza), créditos con la regla de re-limpieza (100 % · 50 % · 0 %) y eficiencia = créditos de piezas ÷ créditos asignados.
                        De «Act./día» a «Extras», las fórmulas de la planilla de bonos de aseo de RRHH con el corte del mes (jornada parcial = la mitad). Pasa el mouse por cada título para ver su fórmula.
                    </p>
                    <p x-show="mensualPorHora" class="px-4 py-2 text-xs text-gray-400 dark:text-gray-500">
                        Mismos números que la ficha del mes: días trabajados (con alguna limpieza empezada, de pieza o área común, u horas anotadas en Cambio Tarea), habitaciones que quedaron bien (una por limpieza), créditos con la regla de re-limpieza (100 % · 50 % · 0 %) y eficiencia = créditos de piezas ÷ créditos asignados.
                        Horas = turnos de los días trabajados + horas extras del mes − horas en otras áreas; las extras se pueden cargar hasta el día 6 del mes siguiente.
                        De «Act./hora» a «Extras», las fórmulas de la planilla de bonos de aseo de RRHH con el corte del mes, el mismo para jornada completa y parcial. Pasa el mouse por cada título para ver su fórmula.
                    </p>
                </div>
            </template>
        </section>

        <!-- Resumen mensual de auditorías por auditor (Supervisora / Recepción) -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.auditorias">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="shield-check" class="w-5 h-5 text-indigo-600 dark:text-indigo-400 flex-shrink-0"></i>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Resumen mensual de inspecciones</h2>
                </div>
            </header>

            <template x-if="auditCargando && !auditData">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Cargando...</div>
            </template>

            <template x-if="auditError">
                <div class="p-8 text-center">
                    <i data-lucide="alert-circle" class="w-10 h-10 text-red-500 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3" x-text="auditError"></p>
                    <button @click="cargarAudit()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
                </div>
            </template>

            <template x-if="!auditError && auditData && auditData.length === 0">
                <div class="p-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No hay inspecciones registradas en este mes.</p>
                </div>
            </template>

            <template x-if="!auditError && auditData && auditData.length > 0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700/50">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Inspector</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Total</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">Aprobadas</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-amber-700 dark:text-amber-400 uppercase tracking-wider">Con observación</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-red-700 dark:text-red-400 uppercase tracking-wider">Rechazadas</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Piezas que marcó como «No se pudo inspeccionar» (por ejemplo, ya había un huésped). No son inspecciones: no suman en el Total ni en Aprobadas y quedan como las que aprueba el sistema.">Sin inspección</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider" title="Casillas del checklist que desmarcó al inspeccionar (en aprobadas con observación y en rechazadas). Es lo mismo que «Observ.» de los trabajadores, contado desde quien inspecciona.">Observaciones</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="De las piezas que aprobó, las que Recepción aprobó (verde) y no aprobó (rojo) para entregar en la inspección pre-entrega, revisadas mientras seguían aprobadas y según la fecha de la revisión de Recepción. Una pieza cuenta una vez: si recibió algún NO, cuenta como no aprobada.">Recepción</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Calidad según Recepción: (aprobadas − no aprobadas) ÷ las que Recepción revisó × 100, con las mismas piezas de la columna «Recepción». Cada NO pesa el doble (18 SÍ y 2 NO = 80 %). Nunca baja de 0 %. Sin revisiones de Recepción en el período: —.">Calidad</th>
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
                                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 font-semibold" x-text="a.sin_inspeccion ?? 0"></td>
                                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 font-semibold" x-text="a.observaciones"></td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                        <span class="font-semibold text-emerald-700 dark:text-emerald-400" x-text="a.recepcion_aprobadas"></span><span class="text-gray-400 dark:text-gray-500"> / </span><span class="font-semibold text-red-700 dark:text-red-400" x-text="a.recepcion_rechazadas"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums text-gray-700 dark:text-gray-300" x-text="fmtPct(a.recepcion_calidad_pct)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <!-- Inspección pre-entrega del mes (pedido de Nicolás, 10/10/2026): solo el total y cuántas hizo cada
             recepcionista, sin SÍ/NO (el detalle está en la pestaña Recepción). Una por limpieza, igual que la
             hoja «Recepción» del Excel. -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.recepcion_mensual">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="concierge-bell" class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Inspección pre-entrega del mes</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Cuántas hizo Recepción, una por limpieza. El SÍ / NO está en la pestaña Recepción.</p>
                    </div>
                </div>
            </header>

            <template x-if="recMesCargando && !recMes">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Cargando...</div>
            </template>

            <template x-if="recMesError">
                <div class="p-8 text-center">
                    <i data-lucide="alert-circle" class="w-10 h-10 text-red-500 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3" x-text="recMesError"></p>
                    <button @click="cargarRecepcionMensual()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
                </div>
            </template>

            <template x-if="!recMesError && recMes">
                <div class="p-4 space-y-4">
                    <div class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-4">
                        <p class="text-xs font-medium text-blue-700 dark:text-blue-300">Total del mes</p>
                        <p class="text-3xl font-bold text-blue-900 dark:text-blue-100 tabular-nums" x-text="recMes.total"></p>
                    </div>
                    <template x-if="recMes.por_recepcionista.length === 0">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Recepción no registró inspecciones pre-entrega en este mes.</p>
                    </template>
                    <template x-if="recMes.por_recepcionista.length > 0">
                        <ul class="divide-y divide-gray-100 dark:divide-gray-700 border border-gray-200 dark:border-gray-700 rounded-lg">
                            <template x-for="p in recMes.por_recepcionista" :key="p.usuario_id">
                                <li class="flex items-center justify-between gap-3 px-4 py-3">
                                    <span class="text-sm text-gray-900 dark:text-gray-100" x-text="p.nombre"></span>
                                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100 tabular-nums" x-text="p.inspecciones"></span>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>
            </template>
        </section>

        </div>
        </template>
        <!-- ═══════════════ fin pestaña MENSUAL ═══════════════ -->

        <!-- ═══════════════ Pestaña RECEPCIÓN ═══════════════ -->
        <template x-if="pestana === 'recepcion'">
        <div class="space-y-4">

        <!-- Inspección pre-entrega de Recepción (v7, docs/revision-entrega.md). No cambia los KPIs de aseo;
             alimenta la columna «Calidad» de las supervisoras y, desde la v7.1, la producción por
             recepcionista. Mismo período y hotel que arriba; ignora la trabajadora. -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden" data-tour="rep.revision_entrega">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3 justify-between">
                <div class="flex items-center gap-2 min-w-0">
                    <i data-lucide="clipboard-check" class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Inspección pre-entrega (Recepción)</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            SÍ / NO que registró Recepción antes de entregar cada pieza, en el período y hotel elegidos. No cambia los indicadores de las trabajadoras.
                        </p>
                    </div>
                </div>
            </header>

            <template x-if="revEntregaCargando && !revEntrega">
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Cargando...</div>
            </template>

            <template x-if="revEntregaError">
                <div class="p-8 text-center">
                    <i data-lucide="alert-circle" class="w-10 h-10 text-red-500 mx-auto mb-3"></i>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3" x-text="revEntregaError"></p>
                    <button @click="cargarRevisionEntrega()" class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Reintentar</button>
                </div>
            </template>

            <template x-if="!revEntregaError && revEntrega">
                <div class="p-4 space-y-5">
                    <!-- Tarjetas -->
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Revisadas</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="revEntrega.resumen.total"></p>
                        </div>
                        <div class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 p-3">
                            <p class="text-xs text-emerald-700 dark:text-emerald-300">Aprobadas (SÍ)</p>
                            <p class="text-2xl font-bold text-emerald-800 dark:text-emerald-200" x-text="revEntrega.resumen.si"></p>
                        </div>
                        <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3">
                            <p class="text-xs text-red-700 dark:text-red-300">No aprobadas (NO)</p>
                            <p class="text-2xl font-bold text-red-800 dark:text-red-200" x-text="revEntrega.resumen.no"></p>
                            <p class="text-xs text-red-700 dark:text-red-300" x-show="revEntrega.resumen.pct_no !== null"
                               x-text="revEntrega.resumen.pct_no + ' % de las revisadas'"></p>
                            <p class="text-xs text-red-700 dark:text-red-300" x-show="revEntrega.resumen.a_sucia > 0"
                               x-text="revEntrega.resumen.a_sucia + ' volvieron a sucia'"></p>
                        </div>
                    </div>

                    <!-- Producción por recepcionista (v7.1): una por limpieza; dentro de una limpieza, el NO manda. -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Por recepcionista</h3>
                        <template x-if="revEntrega.por_recepcionista.length === 0">
                            <p class="text-sm text-gray-500 dark:text-gray-400">Nadie registró inspecciones en el período.</p>
                        </template>
                        <template x-if="revEntrega.por_recepcionista.length > 0">
                            <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Recepcionista</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap" title="Una por limpieza: revisar otra vez la misma habitación sin que se haya vuelto a limpiar no suma; después de una re-limpieza, sí.">Inspecc.</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">SÍ</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold text-red-700 dark:text-red-400 uppercase tracking-wider" title="Si en la misma limpieza hubo un SÍ y un NO, cuenta como NO.">NO</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap">% NO</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <template x-for="p in revEntrega.por_recepcionista" :key="p.usuario_id">
                                            <tr>
                                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100 whitespace-nowrap" x-text="p.nombre"></td>
                                                <td class="px-3 py-2 text-right font-semibold text-gray-900 dark:text-gray-100 tabular-nums" x-text="p.inspecciones"></td>
                                                <td class="px-3 py-2 text-right font-semibold text-emerald-700 dark:text-emerald-400 tabular-nums" x-text="p.si"></td>
                                                <td class="px-3 py-2 text-right font-semibold text-red-700 dark:text-red-400 tabular-nums" x-text="p.no"></td>
                                                <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300 tabular-nums" x-text="fmtPct(p.pct_no)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>

                    <!-- NO por motivo -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">NO por motivo</h3>
                        <template x-if="revEntrega.por_motivo.length === 0">
                            <p class="text-sm text-gray-500 dark:text-gray-400">Sin NO en el período.</p>
                        </template>
                        <template x-if="revEntrega.por_motivo.length > 0">
                            <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Motivo</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Cantidad</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">% de los NO</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <template x-for="m in revEntrega.por_motivo" :key="m.motivo_id">
                                            <tr>
                                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                                    <span x-text="m.nombre"></span>
                                                    <span x-show="!m.activo" class="text-xs text-gray-500 dark:text-gray-400">(inactivo)</span>
                                                </td>
                                                <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300" x-text="m.cantidad"></td>
                                                <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300" x-text="m.pct !== null ? m.pct + ' %' : '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>

                    <!-- Historial -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Historial</h3>
                        <template x-if="revEntrega.historial.length === 0">
                            <p class="text-sm text-gray-500 dark:text-gray-400">Recepción no registró revisiones en este período.</p>
                        </template>
                        <template x-if="revEntrega.historial.length > 0">
                            <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Fecha</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Hotel</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Pieza</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Resultado</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Motivo</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Comentario</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Revisó</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider">Foto</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <template x-for="r in revEntrega.historial" :key="r.id">
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                                <td class="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300" x-text="fechaDiaMes(r.fecha_local) + ' ' + r.hora_local"></td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300" x-text="hotelLabelCodigo(r.hotel_codigo)"></td>
                                                <td class="px-3 py-2 font-semibold">
                                                    <a :href="u('/habitaciones/' + r.habitacion_id)" class="text-blue-600 dark:text-blue-400 hover:underline" x-text="r.numero"></a>
                                                </td>
                                                <td class="px-3 py-2 whitespace-nowrap">
                                                    <span :class="r.resultado === 'si' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'"
                                                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                                          x-text="r.resultado === 'si' ? 'Aprobada' : 'No aprobada'"></span>
                                                    <span x-show="r.paso_a_sucia" class="text-xs text-red-700 dark:text-red-300">→ sucia</span>
                                                </td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300" x-text="r.motivo_nombre || '—'"></td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300 max-w-xs truncate" :title="r.comentario || ''" x-text="r.comentario || '—'"></td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300" x-text="r.usuario_nombre"></td>
                                                <td class="px-3 py-2">
                                                    <template x-if="r.foto_url">
                                                        <a :href="r.foto_url" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 hover:underline">Ver</a>
                                                    </template>
                                                    <template x-if="!r.foto_url"><span class="text-gray-400">—</span></template>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <p x-show="revEntrega.truncado" class="mt-2 text-xs text-gray-500 dark:text-gray-400">Mostrando las últimas 500 revisiones del período.</p>
                    </div>
                </div>
            </template>
        </section>

        </div>
        </template>
        <!-- ═══════════════ fin pestaña RECEPCIÓN ═══════════════ -->

    </main>
</div>

<script>
function reportes() {
    var hoy = window.hoyServidor();
    var AYUDA_DIAS_TRABAJADOS = 'Desde noviembre de 2026: días en que empezó al menos una limpieza, de una pieza o de un área común, o tuvo horas anotadas en Cambio Tarea. Si tenía piezas asignadas y no empezó ninguna, ese día no cuenta. '
        + 'Hasta octubre: días en que tuvo al menos una pieza asignada, aunque no la empezara, salvo que todo lo asignado se retirara sin que lo trabajara.';

    // v7.2 — qué carga necesita cada pestaña. Las cargas son perezosas: se piden al abrir la pestaña y,
    // si cambia un filtro, solo se recarga la abierta (las otras quedan «vencidas» hasta que se abran).
    var VE_SUPERVISORAS = <?= $usuario->tienePermiso('reportes.ver_supervisoras') ? 'true' : 'false' ?>;
    var CARGAS_POR_PESTANA = {
        trabajadores: ['kpis', 'ficha'],
        supervisoras: VE_SUPERVISORAS ? ['ficha', 'pend'] : ['pend'], // la ficha trae la sección de supervisoras
        recepcion:    ['rev'],
        mensual:      ['mensual', 'audit', 'recmes'],
    };
    var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    return {
        AYUDA_DIAS: AYUDA_DIAS_TRABAJADOS,

        // DEFAULT APLICADO (aprobado por el usuario, 10/10/2026): la pestaña va en la URL (#mensual) y
        // Trabajadores es la de entrada; un solo mes para los dos resúmenes mensuales.
        pestana:  'trabajadores',
        pestanas: [
            { id: 'trabajadores', label: 'Aseo', icono: 'users' }, // «Trabajadores» hasta la v7.14; el id no cambia (va en la URL)
            { id: 'supervisoras', label: 'Supervisoras', icono: 'shield-check' },
            { id: 'recepcion',    label: 'Recepción',    icono: 'concierge-bell' },
            { id: 'mensual',      label: 'Mensual',      icono: 'calendar' },
        ],
        // true = ya se pidió con los filtros actuales; false = hay que pedirla al abrir su pestaña.
        vigente: { kpis: false, ficha: false, pend: false, rev: false, mensual: false, audit: false, recmes: false },

        data:         null,
        cargando:     false,
        error:        '',   // mensaje del último error de los KPIs ('' = sin error)
        exportando:   false,
        // Ventana «Exportar» (único botón de descarga de la pantalla)
        exportarAbierto: false,
        exportarTipo:    'mensual', // 'mensual' | 'kpis' | 'pendientes'
        exportarMes:     '',        // YYYY-MM; al abrir, el mes que se está viendo en el resumen mensual
        exportarFecha:   '',        // YYYY-MM-DD; al abrir, el día de «Inspecciones pendientes al corte»
        kpisSeq:      0,    // igual que fichaSeq: gana la última consulta pedida, no la última en llegar
        avisoExport:  '',   // aviso visible cuando una descarga falla

        preset:    'hoy',
        desde:     hoy,
        hasta:     hoy,
        hotel:     'ambos',
        usuarioId: '',
        usuarioNombre: '', // nombre de la trabajadora filtrada: se conserva aunque no tenga actividad en el período nuevo

        trabajadoras: [],

        // Textos de «Inspecciones pendientes al corte» (los mismos del CSV y del correo diario).
        estadosPendiente: <?= json_encode(\Atankalama\Limpieza\Services\ReportesService::ESTADOS_PENDIENTE, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,

        // Ficha de KPIs (docs/kpis-sueldos.md): Trabajador N1-N3 + Supervisora N1-N3, mismo rango/hotel.
        ficha:         null,
        fichaCargando: false,
        fichaSeq:      0,     // nº de la última carga pedida: una respuesta vieja que llega tarde no pisa a la nueva
        fichaError:    false, // la ficha se carga aparte de los KPIs clásicos: tiene su propio estado de error
        fichaCols: [
            { clave: 'dias_trabajados',  titulo: 'Días trab.',   fmt: 'num',      cmp: false, ayuda: AYUDA_DIAS_TRABAJADOS },
            { clave: 'creditos',         titulo: 'Créditos',     fmt: 'num',      cmp: true,  ayuda: 'Créditos aprobados (auditados + no auditados), incluidas áreas comunes. Solo ítems obligatorios; rechazadas fuera. Si uno mismo rehace su pieza rechazada recupera la mitad al segundo intento y nada desde el tercero.' },
            { clave: 'ab',               titulo: 'Audit. / no',  fmt: 'ab',       cmp: false, ayuda: 'Créditos auditados por una persona / no auditados (cuentan igual como aprobados).' },
            { clave: 'habitaciones',     titulo: 'Piezas',       fmt: 'num',      cmp: true,  ayuda: 'Piezas de huésped que quedaron bien, una por limpieza (el nochero de la tarde y el turnover cuentan otra). Tras un rechazo la pieza sigue contando como rechazada para esa persona, la rehaga quien la rehaga.' },
            { clave: 'cobertura_pct',    titulo: 'Cobert.',      fmt: 'pct',      cmp: false, ayuda: 'Qué parte de sus piezas fue inspeccionada por una persona (señal de la supervisión, no del trabajador).' },
            { clave: 'esperado',         titulo: 'Asignadas',    fmt: 'esperado', cmp: false, ayuda: 'Lo asignado en el período: piezas · créditos del checklist vigente, una por limpieza pedida (el nochero de la tarde y el turnover suman otra). Pegajoso: no se quita al rechazar. Una asignación retirada sin trabajo no cuenta, y desde el 01/10/2026 tampoco la que pasó a otra persona sin que la empezaras.' },
            { clave: 'realizacion_pct',  titulo: 'Realiz.',      fmt: 'pct',      cmp: true,  ayuda: '(Aprobadas + Rechazadas) ÷ Asignadas — cuánto de lo asignado ejecutó.' },
            { clave: 'cumplimiento_pct', titulo: 'Cumplim.',     fmt: 'pct',      cmp: true,  ayuda: 'Aprobadas ÷ Asignadas — cuánto de lo asignado quedó bien.' },
            { clave: 'calidad_pct',      titulo: 'Calidad',      fmt: 'pct',      cmp: true,  ayuda: 'Aprobadas ÷ (Aprobadas + Rechazadas) — de lo que hizo, cuánto pasó.' },
            { clave: 'rechazo_pct',      titulo: 'Rechazo',      fmt: 'pct',      cmp: true,  ayuda: 'Rechazadas ÷ (Aprobadas + Rechazadas) — lo que hubo que rehacer.' },
            { clave: 'eficiencia_pct',   titulo: 'Eficiencia',   fmt: 'pct',      cmp: true,  ayuda: 'Créditos aprobados ÷ créditos asignados, solo piezas de huésped. Un rechazo baja este número: rehaciendo uno mismo se recupera la mitad al segundo intento y nada desde el tercero.' },
            { clave: 'creditos_por_hab', titulo: 'Créd./pieza',  fmt: 'num',      cmp: true,  ayuda: 'Créditos de piezas de huésped ÷ piezas: dificultad o mezcla del trabajo (informativo, sin rojo).' },        ],

        // Mes de la pestaña Mensual (YYYY-MM): lo comparten los dos resúmenes y es el que propone el Excel.
        mes:               window.hoyServidor().slice(0, 7),

        // Resumen mensual por trabajador
        mensualData:       null,
        mensualCargando:   false,
        mensualError:      '',
        mensualSeq:        0,
        // Corte de habitaciones diarias del bono de aseo (RRHH): único dato manual, guardado por mes.
        corte:             null,   // { valor, mes_origen, propio } del mes mostrado
        corteInput:        '',
        mensualPorHora:    false, // lo decide la API según el mes (v7.18), no el mes elegido en pantalla
        guardandoCorte:    false,
        corteError:        '',
        puedeEditarCorte:  <?= $usuario->tienePermiso('reportes.editar_corte') ? 'true' : 'false' ?>,

        // Horas extras (v7.17): total del mes por persona de Aseo, en una cuadrícula del año. La ventana existe
        // solo con horas_extras.registrar; el servidor exige el plazo (hasta el 6 del mes siguiente).
        extrasAbierto:   false,
        extrasAnio:      null,
        extrasAnioMax:   null,
        extrasData:      null,   // { anio, hoy, mes_inicio, dia_plazo, meses_editables, personas }
        extrasCargando:  false,
        extrasError:     '',
        extrasBusqueda:  '',
        extrasAviso:     '',
        extrasAvisoClave: '',    // casilla del último error: el aviso se va cuando esa casilla se guarda bien
        extrasEstado:    {},     // 'usuarioId|YYYY-MM' → 'guardando' | 'guardado' | 'error'
        extrasSeq:       0,

        // Inspección pre-entrega del mes (pestaña Mensual): total y cuántas hizo cada recepcionista.
        recMes:          null,
        recMesCargando:  false,
        recMesError:     '',
        recMesSeq:       0,

        // Resumen mensual de auditorías
        auditData:       null,
        auditCargando:   false,
        auditError:      '',
        auditSeq:        0,

        // Auditorías pendientes al corte de las 23:50 (día puntual, no rango)
        auditPendFecha:      window.hoyServidor(),
        auditPendData:       null,
        auditPendCargando:   false,
        auditPendError:      '',
        auditPendSeq:        0,

        // Inspección pre-entrega de Recepción (v7): mismo período y hotel que los KPIs.
        revEntrega:          null,
        revEntregaCargando:  false,
        revEntregaError:     '',
        revEntregaSeq:       0,

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
            if (valor !== 'personalizado') this.alCambiarPeriodo();
        },

        // ── Pestañas y carga perezosa (v7.2) ───────────────────────────────────
        iniciar() {
            this.leerPestanaDeUrl(false);
            this.asegurar();
            this.$nextTick(() => lucide.createIcons());
        },

        leerPestanaDeUrl(cargar = true) {
            var id = (window.location.hash || '').replace('#', '');
            if (!CARGAS_POR_PESTANA[id] || id === this.pestana) return;
            this.pestana = id;
            if (cargar) {
                this.asegurar();
                this.$nextTick(() => lucide.createIcons());
            }
        },

        // replaceState y no location.hash: cambiar de pestaña no llena el historial (atrás sale de Reportes).
        cambiarPestana(id) {
            if (!CARGAS_POR_PESTANA[id]) return;
            this.pestana = id;
            try { history.replaceState(null, '', '#' + id); } catch (e) { /* sin historial: igual cambia */ }
            this.asegurar();
            this.$nextTick(() => lucide.createIcons());
        },

        invalidar(claves) {
            claves.forEach(k => { this.vigente[k] = false; });
        },

        // Pide lo que la pestaña abierta necesita y todavía no está vigente con los filtros actuales.
        asegurar() {
            var cargadores = {
                kpis: () => this.cargarKpis(), ficha: () => this.cargarFicha(), pend: () => this.cargarAuditPendientes(),
                rev: () => this.cargarRevisionEntrega(), mensual: () => this.cargarMensual(), audit: () => this.cargarAudit(),
                recmes: () => this.cargarRecepcionMensual(),
            };
            (CARGAS_POR_PESTANA[this.pestana] || []).forEach(k => {
                if (this.vigente[k]) return;
                this.vigente[k] = true;
                cargadores[k]();
            });
        },

        refrescar() {
            this.invalidar(CARGAS_POR_PESTANA[this.pestana] || []);
            this.asegurar();
        },

        cargandoPestana() {
            var enCurso = {
                kpis: this.cargando, ficha: this.fichaCargando, pend: this.auditPendCargando,
                rev: this.revEntregaCargando, mensual: this.mensualCargando, audit: this.auditCargando,
                recmes: this.recMesCargando,
            };
            return (CARGAS_POR_PESTANA[this.pestana] || []).some(k => enCurso[k]);
        },

        alCambiarPeriodo() {
            this.invalidar(['kpis', 'ficha', 'rev']);
            this.asegurar();
        },

        alCambiarHotel() {
            this.invalidar(['kpis', 'ficha', 'pend', 'rev', 'mensual', 'audit', 'recmes']);
            this.asegurar();
        },

        alCambiarTrabajadora() {
            this.invalidar(['kpis']); // la trabajadora solo filtra las tarjetas de KPI
            this.asegurar();
        },

        alCambiarMes() {
            this.invalidar(['mensual', 'audit', 'recmes']);
            this.asegurar();
        },

        subtituloTxt() {
            var hotel = this.hotelLabel();
            if (this.pestana === 'mensual') {
                var p = (this.mes || '').split('-');
                return p.length === 2 ? hotel + ' · ' + (MESES[parseInt(p[1], 10) - 1] || p[1]) + ' ' + p[0] : hotel;
            }
            // Con una trabajadora filtrada, el subtítulo dice de quién son las tarjetas.
            var quien = (this.pestana === 'trabajadores' && this.usuarioId) ? ' · ' + (this.usuarioNombre || 'Trabajadora elegida') : '';
            var rango = this.desde === this.hasta
                ? this.fmtFecha(this.desde)
                : this.fmtFecha(this.desde) + ' — ' + this.fmtFecha(this.hasta);
            return hotel + quien + ' · ' + rango;
        },

        // Mensaje amable para una respuesta fallida. Un error NUNCA se muestra como «sin actividad»:
        // quien revisa el mes para sueldos creería que nadie trabajó.
        mensajeError(resp, json) {
            if (resp && resp.status === 401) return 'Tu sesión expiró. Vuelve a iniciar sesión para ver los reportes.';
            if (json && json.error && json.error.mensaje) return json.error.mensaje;
            return 'No pudimos cargar esta información, intenta de nuevo en un momento.';
        },

        async cargarKpis() {
            var seq = ++this.kpisSeq;
            this.cargando = true;
            this.error    = '';
            if (this.usuarioId) {
                var elegida = this.trabajadoras.find(t => String(t.usuario_id) === String(this.usuarioId));
                if (elegida) this.usuarioNombre = elegida.nombre;
            }
            var resp = null;
            try {
                var params = new URLSearchParams({
                    desde:      this.desde,
                    hasta:      this.hasta,
                    hotel:      this.hotel,
                });
                if (this.usuarioId) params.set('usuario_id', this.usuarioId);

                resp = await fetch(u('/api/reportes/kpis?' + params.toString()));
                var json = await resp.json();
                if (seq !== this.kpisSeq) return;

                if (json.ok) {
                    var lista = json.data.trabajadoras || [];
                    // Si la trabajadora elegida no tuvo actividad en este período, el selector la sigue
                    // mostrando: si no, diría «Todas» con el filtro todavía puesto.
                    if (this.usuarioId && !lista.some(t => String(t.usuario_id) === String(this.usuarioId))) {
                        lista = lista.concat([{ usuario_id: Number(this.usuarioId), nombre: this.usuarioNombre || 'Trabajadora elegida' }]);
                    }
                    this.data         = json.data;
                    this.trabajadoras = lista;
                } else {
                    this.error = this.mensajeError(resp, json);
                }
            } catch (e) {
                if (seq === this.kpisSeq) this.error = this.mensajeError(resp, null);
            } finally {
                if (seq === this.kpisSeq) {
                    this.cargando = false;
                    this.$nextTick(() => lucide.createIcons());
                }
            }
        },

        async cargarMensual() {
            // mes viene como 'YYYY-MM'
            var partes = (this.mes || '').split('-');
            if (partes.length !== 2) return;
            var anio = parseInt(partes[0], 10);
            var mes  = parseInt(partes[1], 10);
            if (!anio || !mes) return;
            var seq = ++this.mensualSeq;
            this.mensualCargando = true;
            this.mensualError = '';
            var resp = null;
            try {
                var params = new URLSearchParams({ anio: anio, mes: mes, hotel: this.hotel });
                resp = await fetch(u('/api/reportes/resumen-mensual?' + params.toString()));
                var json = await resp.json();
                if (seq !== this.mensualSeq) return;
                if (json.ok) {
                    this.mensualData = json.data.trabajadores || [];
                    this.corte = json.data.corte || null;
                    this.mensualPorHora = json.data.por_hora === true;
                    this.corteInput = this.corte ? String(this.corte.valor) : '';
                    this.corteError = '';
                } else {
                    this.mensualData = null;
                    this.mensualError = this.mensajeError(resp, json);
                }
            } catch (e) {
                if (seq === this.mensualSeq) { this.mensualData = null; this.mensualError = this.mensajeError(resp, null); }
            } finally {
                if (seq === this.mensualSeq) {
                    this.mensualCargando = false;
                    this.$nextTick(() => lucide.createIcons());
                }
            }
        },

        async guardarCorte() {
            var partes = (this.mes || '').split('-');
            var valor = parseFloat(String(this.corteInput).replace(',', '.'));
            if (partes.length !== 2 || isNaN(valor)) {
                this.corteError = this.mensualPorHora ? 'Escribe un número de habitaciones por hora, por ejemplo 2,4.' : 'Escribe un número de habitaciones por día.';
                return;
            }
            this.guardandoCorte = true;
            this.corteError = '';
            try {
                var r = await apiPut('/api/reportes/corte-hab-dia', {
                    anio: parseInt(partes[0], 10), mes: parseInt(partes[1], 10), valor: valor,
                });
                if (r && r.ok) {
                    await this.cargarMensual();
                } else {
                    this.corteError = (r && r.error && r.error.mensaje) || 'No pudimos guardar el corte, intenta de nuevo.';
                    this.corteInput = this.corte ? String(this.corte.valor) : '';
                }
            } catch (e) {
                this.corteError = 'No pudimos guardar el corte, intenta de nuevo.';
                this.corteInput = this.corte ? String(this.corte.valor) : '';
            } finally {
                this.guardandoCorte = false;
            }
        },

        corteNota() {
            if (!this.corte) return '';
            if (this.mensualPorHora) {
                if (this.corte.propio) return '';
                if (this.corte.mes_origen) {
                    var m = this.corte.mes_origen.split('-');
                    return 'igual que ' + m[1] + '/' + m[0];
                }
                return 'calculado del corte por día';
            }
            var parcial = 'parcial ' + (this.corte.valor / 2);
            if (this.corte.propio) return parcial;
            if (this.corte.mes_origen) {
                var p = this.corte.mes_origen.split('-');
                return parcial + ' · igual que ' + p[1] + '/' + p[0];
            }
            return parcial + ' · valor por defecto';
        },

        // v7.18 — aviso sobre las horas en Aseo (la falta de jornada ya tiene su propia etiqueta).
        avisoHoras(h) {
            if (!h || h.estado === 'sin_jornada') return null;
            if (h.estado === 'sin_horas') return { texto: 'Sin horas', titulo: 'Sus horas en otras áreas igualan o superan sus turnos más sus extras: no le quedan horas en Aseo.' };
            if (h.dias_estimados > 0) {
                return {
                    texto: h.dias_estimados + (h.dias_estimados === 1 ? ' día sin turno' : ' días sin turno'),
                    titulo: 'Trabajó sin turno cargado: cada uno de esos días vale la jornada estándar (7,5 h completa, 4 h parcial). Si le cargas el turno en Turnos, el número se corrige solo.',
                };
            }
            return null;
        },

        detalleHoras(h) {
            if (!h) return '';
            var texto = 'Turnos ' + h.turno + ' h + extras ' + h.extras + ' h − otras áreas ' + h.otras_areas + ' h.';
            if (h.dias_estimados > 0) {
                texto += ' ' + h.dias_estimados + (h.dias_estimados === 1 ? ' día sin turno cargado, con' : ' días sin turno cargado, con') + ' la jornada estándar.';
            }
            return texto;
        },

        // ── Horas extras (v7.17) ──
        abrirExtras() {
            this.extrasAbierto = true;
            this.extrasBusqueda = '';
            this.extrasAviso = '';
            this.extrasEstado = {};
            this.cargarExtras(this.extrasAnio);
        },

        cerrarExtras() {
            this.extrasAbierto = false;
        },

        async cargarExtras(anio) {
            var seq = ++this.extrasSeq;
            this.extrasCargando = true;
            this.extrasError = '';
            try {
                var r = await apiFetch('/api/reportes/horas-extras' + (anio ? '?anio=' + encodeURIComponent(anio) : ''));
                if (seq !== this.extrasSeq) return;
                if (r && r.ok) {
                    // Sin extras, PHP manda «meses» como lista vacía: se pasa a objeto para poder escribirle meses.
                    r.data.personas.forEach(function (p) { if (Array.isArray(p.meses)) p.meses = {}; });
                    this.extrasData = r.data;
                    this.extrasAnio = r.data.anio;
                    this.extrasAnioMax = parseInt(String(r.data.hoy).slice(0, 4), 10);
                    var self = this;
                    this.$nextTick(function () { lucide.createIcons(); self.centrarMesAbierto(0); });
                } else {
                    this.extrasData = null;
                    this.extrasError = (r && r.error && r.error.mensaje) || 'No pudimos cargar las horas extras, intenta de nuevo.';
                }
            } catch (e) {
                if (seq === this.extrasSeq) {
                    this.extrasData = null;
                    this.extrasError = 'No pudimos cargar las horas extras, intenta de nuevo.';
                }
            } finally {
                if (seq === this.extrasSeq) this.extrasCargando = false;
            }
        },

        // En el celular la cuadrícula se desliza de lado: se abre mostrando el mes que sigue abierto. La tabla
        // vive en un x-if que se arma después del nextTick y la ventana se muestra en el cuadro siguiente:
        // mientras no esté a la vista, se reintenta un rato.
        centrarMesAbierto(intento) {
            var self = this;
            var caja = this.$refs.extrasScroll;
            var th = caja ? caja.querySelector('th[data-abierto]') : null;
            var nombre = caja ? caja.querySelector('thead th') : null;
            if (!caja || !th || !nombre || caja.clientWidth === 0) {
                if (intento < 15 && this.extrasAbierto) setTimeout(function () { self.centrarMesAbierto(intento + 1); }, 80);
                return;
            }
            caja.scrollLeft = Math.max(th.offsetLeft - nombre.offsetWidth - 16, 0);
        },

        extrasAnioMin() {
            return this.extrasData ? parseInt(String(this.extrasData.mes_inicio).slice(0, 4), 10) : null;
        },

        cambiarAnioExtras(delta) {
            var nuevo = (this.extrasAnio || 0) + delta;
            if (nuevo < this.extrasAnioMin() || nuevo > this.extrasAnioMax) return;
            this.extrasAviso = '';
            this.extrasEstado = {};
            this.cargarExtras(nuevo);
        },

        extrasMeses() {
            var meses = [];
            if (!this.extrasData) return meses;
            for (var i = 1; i <= 12; i++) meses.push(this.extrasData.anio + '-' + (i < 10 ? '0' : '') + i);
            return meses;
        },

        mesEditable(mes) {
            return !!this.extrasData && this.extrasData.meses_editables.indexOf(mes) !== -1;
        },

        mesCorto(mes) {
            return ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][parseInt(mes.slice(5, 7), 10) - 1];
        },

        mesLargo(mes) {
            return ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][parseInt(mes.slice(5, 7), 10) - 1]
                + ' de ' + mes.slice(0, 4);
        },

        // «hasta 06/12»: último día para escribir ese mes (el día del plazo del mes siguiente).
        plazoMes(mes) {
            var anio = parseInt(mes.slice(0, 4), 10);
            var m = parseInt(mes.slice(5, 7), 10) + 1;
            if (m > 12) { m = 1; anio++; }
            var dia = this.extrasData ? this.extrasData.dia_plazo : 6;
            return 'hasta ' + (dia < 10 ? '0' : '') + dia + '/' + (m < 10 ? '0' : '') + m;
        },

        normalizarNombre(texto) {
            return String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
        },

        // Mismo buscador que Cambio Tarea: sin tildes ni mayúsculas, cada palabra tiene que estar en el nombre.
        extrasVisibles() {
            if (!this.extrasData) return [];
            var palabras = this.normalizarNombre(this.extrasBusqueda).split(/\s+/).filter(Boolean);
            if (palabras.length === 0) return this.extrasData.personas;
            var self = this;
            return this.extrasData.personas.filter(function (p) {
                var nombre = self.normalizarNombre(p.nombre);
                return palabras.every(function (w) { return nombre.indexOf(w) !== -1; });
            });
        },

        fmtHoras(horas) {
            return horas == null ? '—' : String(horas).replace('.', ',');
        },

        claseCeldaExtra(clave) {
            switch (this.extrasEstado[clave]) {
                case 'guardando': return 'border-blue-400 opacity-70';
                case 'guardado':  return 'border-green-500 ring-1 ring-green-500';
                case 'error':     return 'border-red-500 ring-1 ring-red-500';
                default:          return 'border-gray-300 dark:border-gray-600';
            }
        },

        async guardarExtra(p, mes, input) {
            var clave = p.id + '|' + mes;
            var anterior = p.meses[mes] != null ? p.meses[mes] : null;
            var texto = String(input.value).trim().replace(',', '.');
            var self = this;
            var fallo = function (mensaje) {
                self.extrasEstado[clave] = 'error';
                input.value = anterior === null ? '' : anterior;
                self.extrasAviso = p.nombre + ' (' + self.mesLargo(mes) + '): ' + mensaje;
                self.extrasAvisoClave = clave;
            };
            this.extrasEstado[clave] = 'guardando';
            try {
                var r = await apiPut('/api/reportes/horas-extras/' + p.id, { mes: mes, horas: texto === '' ? null : texto });
                if (r && r.ok) {
                    if (r.data.horas === null) delete p.meses[mes]; else p.meses[mes] = r.data.horas;
                    input.value = r.data.horas === null ? '' : r.data.horas;
                    // El aviso de error se va cuando se corrige ESA casilla, no al guardar otra.
                    if (this.extrasAvisoClave === clave) { this.extrasAviso = ''; this.extrasAvisoClave = ''; }
                    this.extrasEstado[clave] = 'guardado';
                    setTimeout(function () { if (self.extrasEstado[clave] === 'guardado') delete self.extrasEstado[clave]; }, 2000);
                } else {
                    fallo((r && r.error && r.error.mensaje) || 'No pudimos guardar, intenta de nuevo.');
                    // El plazo se cerró con la ventana abierta: se recarga para mostrar el mes en solo lectura.
                    if (r && r.error && r.error.codigo === 'FUERA_DE_PLAZO') this.cargarExtras(this.extrasAnio);
                }
            } catch (e) {
                fallo('No pudimos guardar, intenta de nuevo en un momento.');
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

        // Igual que ReportesService::textoEstadoPendiente(): «Sin inspección» (v7.11) lleva su motivo.
        estadoPendiente(p) {
            var texto = this.estadosPendiente[p.estado_auditoria] || p.estado_auditoria;
            return p.motivo ? texto + ' (' + p.motivo + ')' : texto;
        },

        // Descarga un archivo (CSV o Excel); si el servidor no lo entrega, lo avisa en vez de no hacer nada.
        async descargar(ruta, params, nombre) {
            this.avisoExport = '';
            var resp = null;
            try {
                resp = await fetch(u(ruta + '?' + params.toString()));
                if (!resp.ok) {
                    var json = null;
                    try { json = await resp.json(); } catch (e2) { /* no era JSON */ }
                    this.avisoExport = 'No se pudo descargar «' + nombre + '». ' + this.mensajeError(resp, json);
                    return;
                }
                var blob = await resp.blob();
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = nombre;
                a.click();
                URL.revokeObjectURL(url);
            } catch (e) {
                this.avisoExport = 'No se pudo descargar «' + nombre + '». ' + this.mensajeError(resp, null);
            } finally {
                this.$nextTick(() => lucide.createIcons());
            }
        },

        fmtFecha(iso) {
            if (!iso) return '';
            var p = iso.split('-');
            return p[2] + '/' + p[1] + '/' + p[0];
        },

        abrirExportar() {
            this.exportarMes   = this.mes;
            this.exportarFecha = this.auditPendFecha;
            this.avisoExport   = '';
            this.exportarAbierto = true;
            this.$nextTick(() => lucide.createIcons());
        },

        cerrarExportar() {
            if (!this.exportando) this.exportarAbierto = false;
        },

        hotelLabel() {
            return { ambos: 'Ambos hoteles', '1_sur': 'Atankalama', inn: 'Atankalama INN' }[this.hotel] || 'Ambos hoteles';
        },

        periodoExportar() {
            var rango = this.desde === this.hasta
                ? this.fmtFecha(this.desde)
                : this.fmtFecha(this.desde) + ' — ' + this.fmtFecha(this.hasta);
            return rango + (this.usuarioId ? ' · ' + (this.usuarioNombre || 'Trabajadora elegida') : '');
        },

        exportarListo() {
            if (this.exportarTipo === 'mensual')    return /^\d{4}-\d{2}$/.test(this.exportarMes || '');
            if (this.exportarTipo === 'pendientes') return /^\d{4}-\d{2}-\d{2}$/.test(this.exportarFecha || '');
            return true;
        },

        // Una sola descarga por vez. Si falla, la ventana se cierra igual y el aviso flotante dice por qué.
        async confirmarExportar() {
            if (this.exportando || !this.exportarListo()) return;
            this.exportando = true;
            try {
                if (this.exportarTipo === 'mensual') {
                    var partes = this.exportarMes.split('-');
                    var pm = new URLSearchParams({ anio: parseInt(partes[0], 10), mes: parseInt(partes[1], 10), hotel: this.hotel });
                    await this.descargar('/api/reportes/exportar-mensual', pm, 'reporte_mensual_' + this.exportarMes + '.xlsx');
                } else if (this.exportarTipo === 'pendientes') {
                    var pp = new URLSearchParams({ fecha: this.exportarFecha, hotel: this.hotel });
                    await this.descargar('/api/reportes/exportar-auditorias-pendientes', pp, 'reporte_inspecciones_pendientes_' + this.exportarFecha + '.csv');
                } else {
                    var pk = new URLSearchParams({ desde: this.desde, hasta: this.hasta, hotel: this.hotel });
                    if (this.usuarioId) pk.set('usuario_id', this.usuarioId);
                    await this.descargar('/api/reportes/exportar', pk, 'reporte_kpis_' + this.desde + '_' + this.hasta + '.csv');
                }
            } finally {
                this.exportando = false;
                this.exportarAbierto = false;
            }
        },

        async cargarAudit() {
            var partes = (this.mes || '').split('-');
            if (partes.length !== 2) return;
            var anio = parseInt(partes[0], 10);
            var mes  = parseInt(partes[1], 10);
            if (!anio || !mes) return;
            var seq = ++this.auditSeq;
            this.auditCargando = true;
            this.auditError = '';
            var resp = null;
            try {
                var params = new URLSearchParams({ anio: anio, mes: mes, hotel: this.hotel });
                resp = await fetch(u('/api/reportes/resumen-mensual-auditores?' + params.toString()));
                var json = await resp.json();
                if (seq !== this.auditSeq) return;
                if (json.ok) {
                    this.auditData = json.data.auditores || [];
                } else {
                    this.auditData = null;
                    this.auditError = this.mensajeError(resp, json);
                }
            } catch (e) {
                if (seq === this.auditSeq) { this.auditData = null; this.auditError = this.mensajeError(resp, null); }
            } finally {
                if (seq === this.auditSeq) {
                    this.auditCargando = false;
                    this.$nextTick(() => lucide.createIcons());
                }
            }
        },

        async cargarRecepcionMensual() {
            var partes = (this.mes || '').split('-');
            if (partes.length !== 2) return;
            var anio = parseInt(partes[0], 10);
            var mes  = parseInt(partes[1], 10);
            if (!anio || !mes) return;
            var seq = ++this.recMesSeq;
            this.recMesCargando = true;
            this.recMesError = '';
            var resp = null;
            try {
                var params = new URLSearchParams({ anio: anio, mes: mes, hotel: this.hotel });
                resp = await fetch(u('/api/reportes/recepcion-mensual?' + params.toString()));
                var json = await resp.json();
                if (seq !== this.recMesSeq) return;
                if (json.ok) {
                    this.recMes = { total: json.data.total, por_recepcionista: json.data.por_recepcionista || [] };
                } else {
                    this.recMes = null;
                    this.recMesError = this.mensajeError(resp, json);
                }
            } catch (e) {
                if (seq === this.recMesSeq) { this.recMes = null; this.recMesError = this.mensajeError(resp, null); }
            } finally {
                if (seq === this.recMesSeq) {
                    this.recMesCargando = false;
                    this.$nextTick(() => lucide.createIcons());
                }
            }
        },

        async cargarAuditPendientes() {
            if (!this.auditPendFecha) return;
            var seq = ++this.auditPendSeq;
            this.auditPendCargando = true;
            this.auditPendError = '';
            var resp = null;
            try {
                var params = new URLSearchParams({ fecha: this.auditPendFecha, hotel: this.hotel });
                resp = await fetch(u('/api/reportes/auditorias-pendientes?' + params.toString()));
                var json = await resp.json();
                if (seq !== this.auditPendSeq) return;
                if (json.ok) {
                    this.auditPendData = json.data;
                } else {
                    this.auditPendData = null;
                    this.auditPendError = this.mensajeError(resp, json);
                }
            } catch (e) {
                if (seq === this.auditPendSeq) { this.auditPendData = null; this.auditPendError = this.mensajeError(resp, null); }
            } finally {
                if (seq === this.auditPendSeq) {
                    this.auditPendCargando = false;
                    this.$nextTick(() => lucide.createIcons());
                }
            }
        },

        async cargarRevisionEntrega() {
            var seq = ++this.revEntregaSeq;
            this.revEntregaCargando = true;
            this.revEntregaError = '';
            var resp = null;
            try {
                var params = new URLSearchParams({ desde: this.desde, hasta: this.hasta, hotel: this.hotel });
                resp = await fetch(u('/api/reportes/revision-entrega?' + params.toString()));
                var json = await resp.json();
                if (seq !== this.revEntregaSeq) return;
                if (json.ok) {
                    this.revEntrega = json.data;
                } else {
                    this.revEntrega = null;
                    this.revEntregaError = this.mensajeError(resp, json);
                }
            } catch (e) {
                if (seq === this.revEntregaSeq) { this.revEntrega = null; this.revEntregaError = this.mensajeError(resp, null); }
            } finally {
                if (seq === this.revEntregaSeq) {
                    this.revEntregaCargando = false;
                    this.$nextTick(() => lucide.createIcons());
                }
            }
        },

        // 'YYYY-MM-DD' → 'DD/MM' sin pasar por Date() (fecha local ya calculada en el servidor).
        fechaDiaMes(iso) {
            var p = String(iso || '').split('-');
            return p.length === 3 ? p[2] + '/' + p[1] : (iso || '');
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
                case 'ab':       return t.creditos_auditados + ' / ' + t.creditos_no_auditados;
                case 'esperado': return t.esperado_hab + ' · ' + t.esperado_creditos + ' cr';
                default:         return this.fmtNull(v);
            }
        },

        fmtPromedio(c) {
            if (!this.ficha || !c.cmp) return '';
            var s = this.ficha.comparativa[c.clave];
            if (!s || s.promedio === null) return '—';
            var sufijo = c.fmt === 'pct' ? ' %' : '';
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
            this.alCambiarTrabajadora(); // cargarKpis() toma el nombre de la lista actual antes de pedir el período
        },

        // Al volver a la pestaña del navegador se refresca solo lo que se está mirando; lo demás queda
        // vencido y se pide al abrir su pestaña (antes se recargaba todo lo del período).
        alVolverVisible() {
            // Al abrir la página el navegador también avisa «visible»: si la carga inicial sigue en curso, no se duplica.
            if (document.hidden || this.cargandoPestana()) return;
            this.invalidar(['kpis', 'ficha', 'rev', 'pend']);
            this.asegurar();
        },

        tarjetasKpi() {
            if (!this.data) return [];
            var defs = [
                { clave: 'tasa_rechazo',       titulo: 'Tasa de rechazo' },
                { clave: 'eficiencia',         titulo: 'Eficiencia del equipo' },
                { clave: 'creditos',           titulo: 'Créditos obtenidos' },
                { clave: 'aprobacion_primera', titulo: 'Aprobación a la 1ª' },
                { clave: 'productividad',      titulo: 'Productividad prom.' },
                { clave: 'tasa_desmarcados',   titulo: 'Ítems desmarcados' },
                { clave: 'limpiadas',          titulo: 'Habitaciones limpiadas' },
            ];
            return defs.map(d => Object.assign({ clave: d.clave, titulo: d.titulo }, this.data.kpis[d.clave] || {}));
        },

        anchoBarra(kpi) {
            if (kpi.valor === null || kpi.meta === null) return 0;
            // Para KPIs donde menor es mejor (rechazo, desmarcados): invertir
            var menorMejor = ['tasa_rechazo', 'tasa_desmarcados'].includes(kpi.clave);
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

        jornadaCorta(j) {
            return j === 'parcial' ? 'Parcial' : (j === 'completa' ? 'Completa' : '');
        },

        jornadaTitulo(j) {
            return j === 'parcial' ? 'Jornada: tiempo parcial' : (j === 'completa' ? 'Jornada: tiempo completo' : '');
        },

        primerNombre(nombre) {
            return nombre ? nombre.split(' ')[0] : nombre;
        },
    };
}
</script>
