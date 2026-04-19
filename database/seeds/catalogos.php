<?php

declare(strict_types=1);

return [
    'hoteles' => [
        ['codigo' => '1_sur', 'nombre' => 'Atankalama', 'cloudbeds_property_id' => null],
        ['codigo' => 'inn', 'nombre' => 'Atankalama INN', 'cloudbeds_property_id' => null],
    ],
    'turnos' => [
        ['nombre' => 'mañana', 'hora_inicio' => '08:00', 'hora_fin' => '16:00'],
        ['nombre' => 'tarde', 'hora_inicio' => '14:00', 'hora_fin' => '22:00'],
    ],
    'tipos_habitacion' => [
        ['nombre' => 'Singular', 'descripcion' => 'Habitación individual'],
        ['nombre' => 'Doble', 'descripcion' => 'Habitación doble estándar'],
        ['nombre' => 'Matrimonial', 'descripcion' => 'Habitación matrimonial'],
        ['nombre' => 'Suite', 'descripcion' => 'Suite'],
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
