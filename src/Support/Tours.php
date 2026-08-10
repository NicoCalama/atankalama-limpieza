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
            //  CHECKLIST DEL TRABAJADOR — vista: views/habitacion-detalle.php
            //  Ruta /habitaciones/{id} (TourResolver la matchea por patrón).
            //  La pantalla cambia de estado: sucia → «Comenzar limpieza»;
            //  en_progreso → checklist + «Habitación terminada» + «No puedo
            //  terminar». Los recorridos son EXPLICATIVOS: si el ancla de un
            //  paso no está en el estado actual, el motor ofrece salir (honesto).
            // ════════════════════════════════════════════════════════════
            'habitacion.detalle' => [
                'nombre'    => 'Limpiar una habitación',
                'capacidad' => 'habitaciones.marcar_completada',
                'recorridos' => [

                    // ── 1. Arrancar (estado sucia). ──
                    [
                        'id'       => 'comenzar',
                        'v'        => 1,
                        'titulo'   => 'Comenzar la limpieza',
                        'pregunta' => '¿Cómo empiezo a limpiar?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="hab.comenzar"]',
                                'titulo' => 'Abre la lista de tareas',
                                'texto'  => 'Con «Comenzar limpieza» se abre la lista de tareas de esta pieza. Recién ahí puedes empezar a marcar.',
                            ],
                        ],
                    ],

                    // ── 2. El trabajo diario: marcar y terminar. ──
                    [
                        'id'       => 'marcar',
                        'v'        => 1,
                        'titulo'   => 'Marcar la limpieza',
                        'pregunta' => '¿Cómo voy marcando lo que limpio?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="hab.checklist"]',
                                'titulo' => 'Se guarda sola',
                                'texto'  => 'Marca cada tarea a medida que la haces; la que marcas se tacha. Se guarda sola al toque, aunque se corte el internet.',
                            ],
                            [
                                'sel'    => '[data-tour="hab.terminar"]',
                                'titulo' => 'Cuándo se habilita terminar',
                                'texto'  => '«Habitación terminada» se prende solo cuando marcaste todas las tareas obligatorias. Las que dicen «Opcional» no hacen falta.',
                            ],
                        ],
                    ],

                    // ── 3. La válvula de escape. ──
                    [
                        'id'       => 'saltar',
                        'v'        => 1,
                        'titulo'   => 'No puedo terminar',
                        'pregunta' => '¿Y si no la puedo terminar?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="hab.saltar"]',
                                'titulo' => 'Avisa y sigue con otra',
                                'texto'  => 'Si algo lo impide (el huésped no salió, falta un insumo), toca acá y elige un motivo; se avisa a tu supervisora. Ojo: lo que marcaste se pierde y al retomarla empiezas de cero.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AUDITORÍA · BANDEJA — vista: views/auditoria-bandeja.php
            //  Ruta /auditoria (fija). Es un lanzador: lista las piezas «Por
            //  auditar» y entra a cada una a dar el veredicto (eso vive en el
            //  detalle /auditoria/{id}, otra pantalla). Por eso solo 2 tareas.
            // ════════════════════════════════════════════════════════════
            'auditoria.bandeja' => [
                'nombre'    => 'Auditoría',
                'capacidad' => 'auditoria.ver_bandeja',
                'recorridos' => [

                    // ── 1. El trabajo: revisar lo terminado. ──
                    [
                        'id'       => 'auditar',
                        'v'        => 1,
                        'titulo'   => 'Auditar una habitación',
                        'pregunta' => '¿Cómo reviso lo que se limpió?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="aud.lista"]',
                                'titulo' => 'Lo que espera tu revisión',
                                'texto'  => 'Cada tarjeta es una pieza que un trabajador dio por terminada y espera tu revisión. Tócala para abrir su checklist y dar tu veredicto.',
                            ],
                        ],
                    ],

                    // ── 2. Filtrar la vista. ──
                    [
                        'id'       => 'hotel',
                        'v'        => 1,
                        'titulo'   => 'Filtrar por hotel',
                        'pregunta' => '¿Cómo veo un solo hotel?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="aud.hotel"]',
                                'titulo' => 'Un hotel o los dos',
                                'texto'  => 'Con estos botones ves solo un hotel o los dos juntos. Lo que elijas se recuerda para la próxima vez que entres.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AUDITORÍA · DETALLE — vista: views/auditoria-detalle.php
            //  Ruta /auditoria/{id} (TourResolver por patrón). Pendiente:
            //  3 veredictos (aprobar / con observación / rechazar). Ya auditada:
            //  read-only (inmutable). Recorridos explicativos; los botones solo
            //  existen en el estado pendiente → fallback honesto si no están.
            // ════════════════════════════════════════════════════════════
            'auditoria.detalle' => [
                'nombre'    => 'Auditar una habitación',
                'capacidad' => 'auditoria.ver_bandeja',
                'recorridos' => [

                    // ── 1. El corazón de la pantalla: los 3 veredictos. ──
                    [
                        'id'       => 'veredicto',
                        'v'        => 1,
                        'titulo'   => 'Dar el veredicto',
                        'pregunta' => '¿Aprobar, con obs. o rechazar?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="aud2.acciones"]',
                                'titulo' => 'Tres caminos, no dos',
                                'texto'  => 'Tres veredictos: «Aprobar» (todo bien), «Aprobar con observación» (algo menor, queda anotado) y «Rechazar» (a re-limpiar). El trabajador no ve la observación como un rechazo.',
                            ],
                            [
                                'sel'    => '[data-tour="aud2.acciones"]',
                                'titulo' => 'Con observación no es rechazar',
                                'texto'  => '«Con observación» deja la pieza limpia igual, con una nota; «Rechazar» la manda de vuelta a sucia para re-limpiarla.',
                            ],
                            [
                                'sel'    => '[data-tour="aud2.acciones"]',
                                'titulo' => 'No hay vuelta atrás',
                                'texto'  => 'Cuando das un veredicto, la pieza queda auditada y no se puede volver a auditar. Revísala bien antes de confirmar.',
                            ],
                        ],
                    ],

                    // ── 2. El checklist ejecutado (ver + desmarcar). ──
                    [
                        'id'       => 'checklist',
                        'v'        => 1,
                        'titulo'   => 'Revisar lo hecho',
                        'pregunta' => '¿Cómo marco lo que quedó mal?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="aud2.checklist"]',
                                'titulo' => 'Lo que marcó el trabajador',
                                'texto'  => 'Acá ves lo que el trabajador marcó. En «con observación» o «rechazo», desmarca los ítems que quedaron mal antes de confirmar.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  ÁREAS COMUNES — vista: views/espacios.php
            //  Ruta /espacios (fija). Espacios que no son piezas de huésped
            //  (piscina, pasillos…), servicio on-demand, SIN auditoría (se
            //  auto-cierran al completar). Ver docs/areas-comunes.md
            // ════════════════════════════════════════════════════════════
            'espacios' => [
                'nombre'    => 'Áreas comunes',
                'capacidad' => 'espacios.ver',
                'recorridos' => [

                    // ── 1. El trabajo diario: pedir una limpieza. ──
                    [
                        'id'       => 'pedir',
                        'v'        => 1,
                        'titulo'   => 'Pedir una limpieza',
                        'pregunta' => '¿Cómo mando a limpiar un área?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="esp.lista"]',
                                'titulo' => 'Cada tarjeta es un área',
                                'texto'  => 'Cada tarjeta es un área común (piscina, pasillos…). Con «Pedir limpieza» eliges a un trabajador y se la asignas para hoy.',
                            ],
                            [
                                'sel'    => '[data-tour="esp.lista"]',
                                'titulo' => 'No pasan por auditoría',
                                'texto'  => 'El badge te dice si está lista, pendiente o en limpieza. Las áreas no se auditan: se cierran solas cuando el trabajador termina.',
                            ],
                        ],
                    ],

                    // ── 2. Armar un área y su checklist. Solo con permiso. ──
                    [
                        'id'        => 'crear',
                        'v'         => 1,
                        'titulo'    => 'Crear o editar un área',
                        'pregunta'  => '¿Cómo armo un área nueva?',
                        'capacidad' => 'espacios.crear_editar',
                        'requiere'  => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="esp.nueva"]',
                                'titulo' => 'Un espacio y su checklist',
                                'texto'  => 'Con «Nueva área» creas un espacio y su checklist (qué hacer al limpiarlo). Cada tarjeta también se puede editar o archivar.',
                            ],
                        ],
                    ],

                    // ── 3. Filtrar la vista. ──
                    [
                        'id'       => 'hotel',
                        'v'        => 1,
                        'titulo'   => 'Cambiar de hotel',
                        'pregunta' => '¿Cómo veo un solo hotel?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="esp.hotel"]',
                                'titulo' => 'Un hotel o los dos',
                                'texto'  => 'Acá eliges Atankalama, el Inn, o los dos juntos. Lo que elijas se recuerda para la próxima vez.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  TICKETS — vista: views/tickets.php
            //  Ruta /tickets (fija). Tickets de mantención. Gate por
            //  tickets.ver_todos (la vista de gestión de la supervisora); el
            //  trabajador que solo reporta usa el modal desde otras pantallas.
            // ════════════════════════════════════════════════════════════
            'tickets' => [
                'nombre'    => 'Tickets',
                'capacidad' => 'tickets.ver_todos',
                'recorridos' => [

                    // ── 1. Reportar. ──
                    [
                        'id'        => 'reportar',
                        'v'         => 1,
                        'titulo'    => 'Reportar un problema',
                        'pregunta'  => '¿Cómo aviso una avería?',
                        'capacidad' => 'tickets.crear',
                        'requiere'  => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="tk.nuevo"]',
                                'titulo' => 'Deja constancia de la avería',
                                'texto'  => 'Con «Nuevo» reportas un problema de mantención (una avería, algo roto). Queda registrado para que alguien lo resuelva.',
                            ],
                        ],
                    ],

                    // ── 2. Gestionar el ciclo del ticket. ──
                    [
                        'id'       => 'gestionar',
                        'v'        => 1,
                        'titulo'   => 'Gestionar un ticket',
                        'pregunta' => '¿Cómo lo tomo y lo cierro?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="tk.lista"]',
                                'titulo' => 'Del reporte al cierre',
                                'texto'  => 'Toca un ticket para ver su detalle y las acciones: «Tomar», «Marcar resuelto», «Cerrar» y «Reabrir». Así lo mueves hasta resolverlo.',
                            ],
                        ],
                    ],

                    // ── 3. Filtrar la lista. ──
                    [
                        'id'       => 'filtrar',
                        'v'        => 1,
                        'titulo'   => 'Filtrar los tickets',
                        'pregunta' => '¿Cómo veo solo los abiertos?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="tk.filtros"]',
                                'titulo' => 'Por estado y por hotel',
                                'texto'  => 'Filtra por estado (abiertos, en progreso, resueltos, cerrados) y por hotel. Lo que elijas se recuerda para la próxima vez.',
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
