<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Support;

/**
 * Catálogo de la Vista Guiada. Fuente ÚNICA de los textos de ayuda.
 * ---------------------------------------------------------------------------
 * Motor portado del kit "Vista Guiada v3.0" (sucesor de TourGuiado de Rodrigo
 * Jaque). Modelo: RECORRIDOS PLANOS. Una pantalla declara una lista de
 * recorridos POR TAREA; el botón «?» los muestra y el usuario elige. Tres
 * niveles y nada más:  pantalla -> recorrido -> paso.
 *
 * La CLAVE de cada entrada es el nombre lógico de la pantalla; TourResolver la
 * resuelve desde el PATH de la request (ver TourResolver::MAP), porque nuestro
 * router no usa nombres de ruta.
 *
 * REGLAS DURAS (las hace cumplir tests/Unit/VistaGuiada/ToursCatalogTest — no
 * las relajes):
 *   - Máximo 4 recorridos SIN precondición por pantalla (el selector muestra 4).
 *   - `titulo`: <= 28 caracteres, imperativo, y la PRIMERA PALABRA es única
 *     dentro de la pantalla (se elige leyendo una palabra, no cinco frases).
 *   - `pregunta`: la duda literal del usuario, <= 60 caracteres.
 *   - <= 5 pasos por recorrido; <= 2 frases y <= 220 caracteres por paso.
 *   - Español de Chile con TUTEO. Sin voseo. Sin '<'. Sin jerga de código.
 *   - `capacidad` (si se declara) debe ser un permiso real (permisos.php).
 *
 * VERACIDAD (la regla que salva el proyecto): ninguna frase se copia del manual
 * sin verificarla contra el código actual. Un tour que miente una vez se deja de
 * creer para siempre. Ver REDACCION en el kit.
 *
 * PRECONDICIONES: si un recorrido depende del estado, va en `requiere` (tokens
 * de bandera) y las banderas se calculan en PHP (data-vg-context), nunca del
 * DOM. Bandera ausente/desconocida => FALSA (fail-closed). En pantallas que
 * cargan su estado por fetch (como Asignaciones) preferimos recorridos
 * EXPLICATIVOS sin precondición, para no prometer un estado que PHP no conoce.
 *
 * ANCLAS: cada paso apunta con `sel` a un data-tour="..." que agregas a la vista,
 * sobre un contenedor ESTABLE (no dentro de un x-for, no una clase de Tailwind).
 */
final class Tours
{
    public const ESQUEMA = 1;
    public const MAX_DISPONIBLES = 4;

    /**
     * @return array<string, array{
     *     nombre?: string,
     *     capacidad?: string,
     *     banderas?: array<int, string>,
     *     recorridos: array<int, array<string, mixed>>
     * }>
     */
    public static function catalog(): array
    {
        return [

            // ════════════════════════════════════════════════════════════
            //  ASIGNACIONES (supervisora) — vista: views/asignaciones.php
            //  Ruta /asignaciones. Solo la ve quien puede asignar a mano
            //  (el endpoint que la alimenta exige asignaciones.asignar_manual).
            // ════════════════════════════════════════════════════════════
            'asignaciones' => [
                'nombre'    => 'Asignaciones',
                'capacidad' => 'asignaciones.asignar_manual',
                'recorridos' => [

                    // ── 1. El trabajo diario. Va primero SIEMPRE. ──
                    [
                        'id'       => 'repartir',
                        'v'        => 1,
                        'titulo'   => 'Repartir las piezas',
                        'pregunta' => '¿Cómo asigno las habitaciones de hoy?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="asig.sin-asignar"]',
                                'titulo' => 'Las piezas que faltan repartir',
                                'texto'  => 'Acá están las piezas sucias que todavía no tienen quién las limpie. Tócalas para elegirlas: se marcan en azul y abajo aparece «Asignar a…» para dárselas a alguien.',
                            ],
                            [
                                'sel'    => '[data-tour="asig.equipo"]',
                                'titulo' => 'Eliges a quién, según su carga',
                                'texto'  => 'En «Asignar a…» eliges al trabajador; te los ordena por menor carga. La pieza entra en su cola y la verá en su teléfono.',
                            ],
                        ],
                    ],

                    // ── 2. Las dos acciones que se confunden. ──
                    [
                        'id'       => 'mover',
                        'v'        => 1,
                        'titulo'   => 'Mover una pieza asignada',
                        'pregunta' => '¿Reasignar o sacarla de la cola?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="asig.equipo"]',
                                'titulo' => 'Cada tarjeta es un trabajador',
                                'texto'  => 'Ves su cola de piezas y su avance: cuántas lleva completadas y cuántas le quedan. Desde acá equilibras la carga si alguien va muy apretado.',
                            ],
                            [
                                'sel'    => '[data-tour="asig.equipo"]',
                                'titulo' => 'Reasignar no es sacar',
                                'texto'  => 'En cada pieza que todavía se puede mover hay dos acciones: «Reasignar» la pasa a otra persona; el botón rojo de quitar la deja sin asignar.',
                            ],
                        ],
                    ],

                    // ── 3. El atajo para repartir todo. Solo con permiso. ──
                    [
                        'id'        => 'auto',
                        'v'         => 1,
                        'titulo'    => 'Auto-asignar todo',
                        'pregunta'  => '¿Puedo repartir todo de una vez?',
                        'capacidad' => 'asignaciones.auto_asignar',
                        'requiere'  => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="asig.auto"]',
                                'titulo' => 'Reparte parejo, tú ajustas',
                                'texto'  => '«Auto-asignar» reparte todas las piezas sucias entre el equipo con turno, en partes parejas. Después puedes reasignar a mano lo que quieras.',
                            ],
                        ],
                    ],

                    // ── 4. Filtrar la vista. ──
                    [
                        'id'       => 'hotel',
                        'v'        => 1,
                        'titulo'   => 'Cambiar de hotel',
                        'pregunta' => '¿Cómo veo un solo hotel?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="asig.hotel"]',
                                'titulo' => 'Filtra por hotel',
                                'texto'  => 'Acá eliges ver Atankalama, el Inn, o los dos juntos. Lo que elijas se recuerda para la próxima vez que entres.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  HOME SUPERVISORA — vista: views/home-supervisora.php
            //  Ruta /home (home.php incluye este dashboard cuando el usuario
            //  tiene alertas.recibir_predictivas + asignaciones.asignar_manual
            //  y NO es admin). TourResolver::resolveHome() espeja ese branching.
            // ════════════════════════════════════════════════════════════
            'home.supervisora' => [
                'nombre'    => 'Inicio · Supervisora',
                'recorridos' => [

                    // ── 1. Leer el día de un vistazo. Va primero. ──
                    [
                        'id'       => 'tablero',
                        'v'        => 1,
                        'titulo'   => 'Leer el tablero',
                        'pregunta' => '¿Cómo veo cómo va el día?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="home.progreso"]',
                                'titulo' => 'El día, en una barra',
                                'texto'  => 'La barra resume cuántas piezas van completadas, en progreso, rechazadas y pendientes, sobre el total del turno.',
                            ],
                            [
                                'sel'    => '[data-tour="home.equipo"]',
                                'titulo' => 'Cómo va cada trabajador',
                                'texto'  => 'Cada tarjeta muestra su avance y una etiqueta: «En tiempo», «En riesgo» (va lento y podría no alcanzar) o «Disponible» (ya terminó).',
                            ],
                        ],
                    ],

                    // ── 2. Atender lo urgente. Solo con permiso de alertas. ──
                    [
                        'id'        => 'alertas',
                        'v'         => 1,
                        'titulo'    => 'Atender las alertas',
                        'pregunta'  => '¿Qué son las alertas de arriba?',
                        'capacidad' => 'alertas.recibir_predictivas',
                        'requiere'  => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="home.alertas"]',
                                'titulo' => 'Lo que necesita tu atención ahora',
                                'texto'  => 'Acá salen las cosas urgentes: un trabajador en riesgo, una pieza rechazada, un ticket nuevo. Se ordenan por prioridad.',
                            ],
                            [
                                'sel'    => '[data-tour="home.alertas"]',
                                'titulo' => 'No se descartan solas',
                                'texto'  => 'Cada alerta trae hasta dos botones para resolverla en el momento. Se van cuando resuelves lo que las causó, no antes.',
                            ],
                        ],
                    ],

                    // ── 3. Mover carga sin cambiar de pantalla. Solo si asigna. ──
                    [
                        'id'        => 'mover',
                        'v'         => 1,
                        'titulo'    => 'Mover carga sin salir',
                        'pregunta'  => '¿Reasignar sin ir a Asignaciones?',
                        'capacidad' => 'asignaciones.asignar_manual',
                        'requiere'  => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="home.equipo"]',
                                'titulo' => 'Ver carga y reasignar',
                                'texto'  => 'En cada trabajador tienes «Ver carga» para mirar sus piezas y «Reasignar» para pasarle una a alguien con menos trabajo.',
                            ],
                        ],
                    ],

                    // ── 4. Filtrar la vista. ──
                    [
                        'id'       => 'hotel',
                        'v'        => 1,
                        'titulo'   => 'Cambiar de hotel',
                        'pregunta' => '¿Cómo veo un solo hotel?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="home.hotel"]',
                                'titulo' => 'Filtra por hotel',
                                'texto'  => 'Acá eliges Atankalama, el Inn, o los dos juntos. El tablero, el equipo y las alertas se ajustan al hotel que elijas.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  PLANTILLA — copia esto para una pantalla nueva (y bórrala si
            //  no la usas; no la publiques con textos de relleno).
            // ════════════════════════════════════════════════════════════
            // 'mi.pantalla' => [
            //     'nombre'    => 'Nombre visible',
            //     'capacidad' => 'mi.permiso',   // o quítalo si la ve cualquiera
            //     'recorridos' => [
            //         [
            //             'id'       => 'hacer-lo-principal',
            //             'v'        => 1,
            //             'titulo'   => 'Hacer lo principal',       // <= 28
            //             'pregunta' => '¿Cómo hago lo principal?',  // <= 60
            //             'requiere' => [],
            //             'pasos' => [
            //                 ['sel' => '[data-tour="mi.bloque"]', 'titulo' => 'Empieza acá',
            //                  'texto' => 'Explica el RESULTADO, no el control. «Al guardar» queda disponible.'],
            //             ],
            //         ],
            //     ],
            // ],
        ];
    }
}
