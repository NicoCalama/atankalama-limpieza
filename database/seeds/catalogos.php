<?php

declare(strict_types=1);

return [
    'hoteles' => [
        // property_id de Cloudbeds (identificador público, no es secreto). La API key
        // de cada propiedad vive en .env (CLOUDBEDS_API_KEY_PRINCIPAL / _INN).
        ['codigo' => '1_sur', 'nombre' => 'Atankalama', 'cloudbeds_property_id' => '209760'],
        ['codigo' => 'inn', 'nombre' => 'Atankalama INN', 'cloudbeds_property_id' => '209761'],
    ],
    'turnos' => [
        ['nombre' => 'mañana', 'hora_inicio' => '08:00', 'hora_fin' => '16:00'],
        ['nombre' => 'tarde', 'hora_inicio' => '14:00', 'hora_fin' => '22:00'],
    ],
    // Tipos de habitación: los REALES los crea el import de inventario on-the-fly, uno por cada
    // roomTypeName de Cloudbeds (ver InventarioImportService), con su checklist default. Acá solo
    // se siembra el tipo técnico de áreas comunes; una instalación fresca queda sin tipos de huésped
    // hasta correr el import. Ver docs/checklist.md.
    'tipos_habitacion' => [
        // Tipo técnico para áreas comunes (piscina, pasillos, patio, bodega…). No viene de Cloudbeds;
        // rellena el FK NOT NULL de las filas-espacio. Su checklist es propio de cada espacio, no de
        // este tipo (por eso seed.php NO le crea template estándar). Ver docs/areas-comunes.md
        ['nombre' => 'Área común', 'descripcion' => 'Espacio que no es habitación de huésped (piscina, pasillo, patio…)'],
    ],
    'alertas_config' => [
        ['clave' => 'margen_seguridad_minutos', 'valor' => '15', 'descripcion' => 'Margen del algoritmo predictivo'],
        ['clave' => 'fin_turno_anticipo_minutos', 'valor' => '30', 'descripcion' => 'Anticipo para alerta fin_turno_pendientes'],
        ['clave' => 'recalculo_intervalo_minutos', 'valor' => '15', 'descripcion' => 'Frecuencia del cron de recálculo'],
        ['clave' => 'tiempo_fallback_nueva_habitacion', 'valor' => '30', 'descripcion' => 'Fallback cuando no hay histórico'],
    ],
    'cloudbeds_config' => [
        // El cron tickea seguido (p. ej. cada 10 min) y el script se auto-regula con este intervalo:
        // la cadencia se cambia desde la app sin tocar crontab. Ver docs/cloudbeds.md §4.1.
        ['clave' => 'sync_intervalo_minutos', 'valor' => '30', 'descripcion' => 'Cadencia del sync automático (minutos)'],
        ['clave' => 'reintentos_max', 'valor' => '3', 'descripcion' => 'Número de reintentos'],
        ['clave' => 'timeout_segundos', 'valor' => '10', 'descripcion' => 'Timeout por request'],
        // Toggle Ajustes → Checklists: separar los checklists de tipo por hotel (override por propiedad).
        ['clave' => 'tipos_checklist_por_hotel', 'valor' => '0', 'descripcion' => 'Separar los checklists de tipo por hotel (override por propiedad)'],
    ],
];
