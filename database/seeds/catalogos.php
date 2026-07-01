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
    // Tipos de LIMPIEZA (no de venta): se mapean desde Cloudbeds por maxGuests en el
    // import de inventario (ver InventarioImportService). El protocolo de limpieza no
    // cambia por sub-tipo comercial, así que colapsamos los ~16 roomTypeName de Cloudbeds
    // a este set chico. Cada tipo obtiene su checklist template default en seed.php.
    //   maxGuests=1 -> Singular | =2 -> Doble/Matrimonial | =3-4 -> Suite/Familiar
    // (El inventario real actual no tiene piezas de 1 huésped: 'Singular' queda disponible
    //  para el futuro pero hoy no se le asigna ninguna habitación.)
    'tipos_habitacion' => [
        ['nombre' => 'Singular', 'descripcion' => 'Individual — 1 huésped'],
        ['nombre' => 'Doble/Matrimonial', 'descripcion' => 'Doble o matrimonial — 2 huéspedes'],
        ['nombre' => 'Suite/Familiar', 'descripcion' => 'Suite, triple o familiar — 3 a 4 huéspedes'],
    ],
    'alertas_config' => [
        ['clave' => 'margen_seguridad_minutos', 'valor' => '15', 'descripcion' => 'Margen del algoritmo predictivo'],
        ['clave' => 'fin_turno_anticipo_minutos', 'valor' => '30', 'descripcion' => 'Anticipo para alerta fin_turno_pendientes'],
        ['clave' => 'recalculo_intervalo_minutos', 'valor' => '15', 'descripcion' => 'Frecuencia del cron de recálculo'],
        ['clave' => 'tiempo_fallback_nueva_habitacion', 'valor' => '30', 'descripcion' => 'Fallback cuando no hay histórico'],
    ],
    'cloudbeds_config' => [
        ['clave' => 'sync_schedule_morning', 'valor' => '07:00', 'descripcion' => 'Hora del cron matutino'],
        ['clave' => 'sync_schedule_afternoon', 'valor' => '15:00', 'descripcion' => 'Hora del cron tarde'],
        ['clave' => 'reintentos_max', 'valor' => '3', 'descripcion' => 'Número de reintentos'],
        ['clave' => 'timeout_segundos', 'valor' => '10', 'descripcion' => 'Timeout por request'],
    ],
];
