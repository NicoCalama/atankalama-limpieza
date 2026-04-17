<?php
/**
 * FAB (Floating Action Button) del copilot IA.
 * Visible en todas las pantallas si el usuario tiene permiso copilot.usar_nivel_1_consultas.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="{ abierto: false }" class="fixed bottom-20 right-4 md:bottom-6 md:right-6 z-50">
    <!-- Botón FAB -->
    <button @click="abierto = !abierto"
            class="w-14 h-14 rounded-full bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600
                   shadow-lg hover:shadow-xl transition-all duration-200
                   flex items-center justify-center text-white
                   focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
            aria-label="Abrir copilot IA">
        <i data-lucide="sparkles" class="w-6 h-6" x-show="!abierto"></i>
        <i data-lucide="x" class="w-6 h-6" x-show="abierto" x-cloak></i>
    </button>

    <!-- Panel del copilot (placeholder — se implementa completo en item 57) -->
    <div x-show="abierto"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         x-cloak
         class="absolute bottom-16 right-0 w-80 md:w-96 max-h-[70vh]
                bg-white dark:bg-gray-800 rounded-2xl shadow-2xl
                border border-gray-200 dark:border-gray-700
                flex flex-col overflow-hidden">

        <!-- Header del panel -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-2">
                <i data-lucide="sparkles" class="w-5 h-5 text-blue-600 dark:text-blue-400"></i>
                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">Copilot IA</span>
            </div>
            <button @click="abierto = false" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <!-- Área de mensajes -->
        <div class="flex-1 overflow-y-auto p-4 min-h-[200px]" id="copilot-mensajes">
            <div class="text-center text-sm text-gray-500 dark:text-gray-400 py-8">
                <i data-lucide="message-circle" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                <p>Hola, ¿en qué te puedo ayudar?</p>
            </div>
        </div>

        <!-- Input -->
        <div class="border-t border-gray-200 dark:border-gray-700 p-3">
            <form x-data="copilotInput()" @submit.prevent="enviar()" class="flex items-center gap-2">
                <input type="text"
                       x-model="mensaje"
                       placeholder="Escribe tu pregunta..."
                       class="flex-1 min-h-[44px] px-3 py-2 text-sm bg-gray-100 dark:bg-gray-700
                              border-0 rounded-lg text-gray-900 dark:text-gray-100
                              placeholder-gray-500 dark:placeholder-gray-400
                              focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit"
                        :disabled="mensaje.trim() === '' || enviando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center
                               rounded-lg bg-blue-600 hover:bg-blue-700 text-white
                               disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                    <i data-lucide="send" class="w-4 h-4"></i>
                </button>
            </form>
        </div>
    </div>
</div>
