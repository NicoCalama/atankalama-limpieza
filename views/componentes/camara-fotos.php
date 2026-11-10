<?php
/**
 * Cámara propia para sacar varias fotos seguidas (v7.21, reunión del 08/11/2026).
 * Lógica y uso en views/recursos/componentes/camara-fotos.js: abrirCamaraFotos({ max }) devuelve
 * las fotos ya comprimidas.
 *
 * Se incluye SIEMPRE con include_once desde cada pantalla que la usa (modal de ticket nuevo y
 * detalle de ticket): si quedara dos veces en la página, las dos cámaras se abrirían a la vez.
 */
$camaraFotosJsV = @filemtime(__DIR__ . '/../recursos/componentes/camara-fotos.js') ?: '1';
?>
<script src="<?= u('/views/recursos/componentes/camara-fotos.js') ?>?v=<?= $camaraFotosJsV ?>"></script>

<div x-data="camaraFotos()"
     @abrir-camara-fotos.window="abrir($event.detail)"
     @visibilitychange.document="alCambiarVisibilidad()"
     @keydown.escape.window="abierta && terminar()">
    <div x-show="abierta" x-cloak
         class="fixed inset-0 z-[100] bg-black text-white flex flex-col select-none"
         role="dialog" aria-modal="true" aria-label="Cámara para sacar fotos">

        <!-- Arriba: cerrar (entrega lo sacado) y contador -->
        <div class="flex items-center justify-between gap-3 px-3 pb-2" style="padding-top: max(0.75rem, env(safe-area-inset-top));">
            <button type="button" @click="terminar()" :disabled="procesando > 0"
                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-full bg-white/15 hover:bg-white/25 disabled:opacity-40"
                    aria-label="Cerrar la cámara">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            <p class="text-sm font-semibold" x-text="textoContador()" aria-live="polite"></p>
            <span class="w-11" aria-hidden="true"></span>
        </div>

        <!-- Visor -->
        <div class="relative flex-1 min-h-0 overflow-hidden">
            <video x-ref="video" playsinline muted autoplay
                   x-show="estado !== 'error'"
                   class="absolute inset-0 w-full h-full object-contain"></video>
            <div x-show="destello" class="absolute inset-0 bg-white/70 pointer-events-none"></div>

            <div x-show="estado === 'abriendo'" class="absolute inset-0 flex flex-col items-center justify-center gap-3">
                <svg class="animate-spin h-8 w-8 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="text-sm">Abriendo la cámara...</p>
            </div>

            <!-- Sin cámara o sin permiso: el método de antes, una foto por toque -->
            <div x-show="estado === 'error'" class="absolute inset-0 flex flex-col items-center justify-center gap-4 p-6 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-white/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
                <p class="text-base max-w-sm" x-text="error"></p>
                <div class="flex flex-wrap items-center justify-center gap-2">
                    <button type="button" x-show="puedeReintentar" @click="iniciar()"
                            class="min-h-[44px] px-4 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-sm font-medium">
                        Reintentar
                    </button>
                    <label class="min-h-[44px] px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-sm font-medium inline-flex items-center gap-2 cursor-pointer"
                           :class="fotos.length + procesando >= max ? 'opacity-40 pointer-events-none' : ''">
                        <span>Usar la cámara del teléfono</span>
                        <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoDelTelefono($event)">
                    </label>
                </div>
                <p x-show="procesando > 0" class="text-sm text-white/80">Procesando foto...</p>
            </div>

            <p x-show="aviso" x-cloak class="absolute bottom-3 left-3 right-3 text-center text-sm bg-black/70 rounded-lg px-3 py-2" x-text="aviso"></p>
        </div>

        <!-- Abajo: las fotos sacadas, el disparador y Listo -->
        <div class="px-4 pt-3 space-y-3" style="padding-bottom: max(1rem, env(safe-area-inset-bottom));">
            <div class="flex justify-center gap-3 min-h-[56px]">
                <template x-for="(foto, idx) in fotos" :key="foto.url">
                    <div class="relative w-14 h-14">
                        <img :src="foto.url" alt="Foto sacada" class="w-14 h-14 object-cover rounded-lg border-2 border-white/80">
                        <button type="button" @click="quitar(idx)"
                                class="absolute -top-2 -right-2 w-6 h-6 flex items-center justify-center rounded-full bg-red-600 text-white text-sm leading-none"
                                aria-label="Quitar esta foto">×</button>
                    </div>
                </template>
            </div>
            <div class="grid grid-cols-3 items-center">
                <span></span>
                <div class="flex justify-center">
                    <button type="button" @click="capturar()"
                            :disabled="estado !== 'lista' || capturando || fotos.length >= max"
                            class="w-[72px] h-[72px] rounded-full border-4 border-white flex items-center justify-center disabled:opacity-40 active:scale-95 transition"
                            aria-label="Sacar foto">
                        <span class="w-[56px] h-[56px] rounded-full bg-white"></span>
                    </button>
                </div>
                <div class="flex justify-end">
                    <button type="button" @click="terminar()" :disabled="procesando > 0"
                            class="min-h-[44px] px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-40"
                            :class="fotos.length > 0 ? 'bg-blue-600 hover:bg-blue-700' : 'bg-white/15 hover:bg-white/25'"
                            x-text="fotos.length > 0 ? 'Listo (' + fotos.length + ')' : 'Cerrar'"></button>
                </div>
            </div>
        </div>
    </div>
</div>
