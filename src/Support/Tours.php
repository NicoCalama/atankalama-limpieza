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
                        'v'        => 2,
                        'titulo'   => 'Repartir las piezas',
                        'pregunta' => '¿Cómo asigno las habitaciones de hoy?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="asig.sin-asignar"]',
                                'titulo' => 'Las piezas que faltan repartir',
                                'texto'  => 'Acá están las piezas sucias que todavía no tienen quién las limpie. Tócalas para elegir varias y arrástralas hasta un trabajador, o arrastra una sola directo.',
                            ],
                            [
                                'sel'    => '[data-tour="asig.equipo"]',
                                'titulo' => 'Suéltala sobre la persona',
                                'texto'  => 'Al soltarla sobre un trabajador, la pieza entra en su cola y la verá en su teléfono. En cada tarjeta ves su avance y su carga del día.',
                            ],
                        ],
                    ],

                    // ── 2. Las tres formas de mover una pieza ya asignada. ──
                    [
                        'id'       => 'mover',
                        'v'        => 2,
                        'titulo'   => 'Mover una pieza asignada',
                        'pregunta' => '¿Reasignar, quitar o reordenar una pieza?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="asig.equipo"]',
                                'titulo' => 'Arrastra de una persona a otra',
                                'texto'  => 'Cada tarjeta es un trabajador con su cola. Arrastra una pieza de una persona a otra para reasignarla.',
                            ],
                            [
                                'sel'    => '[data-tour="asig.equipo"]',
                                'titulo' => 'Para quitarla del todo',
                                'texto'  => 'Arrástrala de vuelta al panel de la derecha, o toca la X roja de la pieza: queda sin asignar.',
                            ],
                            [
                                'sel'    => '[data-tour="asig.equipo"]',
                                'titulo' => 'Ordenar dentro de la persona',
                                'texto'  => 'Dentro de un mismo trabajador, arrastra sus piezas hacia arriba o abajo para cambiar el orden en que las limpia.',
                            ],
                        ],
                    ],

                    // ── 3. El atajo para repartir todo. Solo con permiso. ──
                    [
                        'id'        => 'auto',
                        'v'         => 2,
                        'titulo'    => 'Auto-asignar todo',
                        'pregunta'  => '¿Puedo repartir todo de una vez?',
                        'capacidad' => 'asignaciones.auto_asignar',
                        'requiere'  => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="asig.auto"]',
                                'titulo' => 'Reparte parejo, tú ajustas',
                                'texto'  => 'El botón de auto-asignar reparte todas las piezas sucias entre el equipo con turno, en partes parejas. Después arrastra a mano lo que quieras ajustar.',
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
            //  HABITACIONES · LISTA — vista: views/habitaciones.php
            //  Ruta /habitaciones (fija). Role-aware: con habitaciones.ver_todas
            //  muestra la grilla completa + filtros (gateados por PHP). Gate de
            //  pantalla por ver_todas (la vista de panorama de la supervisora).
            // ════════════════════════════════════════════════════════════
            'habitaciones' => [
                'nombre'    => 'Habitaciones',
                'capacidad' => 'habitaciones.ver_todas',
                'recorridos' => [

                    // ── 1. Leer el panorama. ──
                    [
                        'id'       => 'panorama',
                        'v'        => 1,
                        'titulo'   => 'Ver el panorama',
                        'pregunta' => '¿Qué me dice cada tarjeta?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="hb.grid"]',
                                'titulo' => 'Una tarjeta por pieza',
                                'texto'  => 'Cada tarjeta es una pieza con su estado (pendiente, en progreso, por auditar, lista…) y su hotel. Tócala para entrar a su detalle.',
                            ],
                            [
                                'sel'    => '[data-tour="hb.grid"]',
                                'titulo' => 'Los avisos de la tarjeta',
                                'texto'  => 'Algunas traen avisos: «Llega hoy», «Se va hoy», «Día/noche» o «Sábanas hoy», según la ocupación que llega de Cloudbeds.',
                            ],
                        ],
                    ],

                    // ── 2. Filtrar la lista. ──
                    [
                        'id'       => 'filtrar',
                        'v'        => 1,
                        'titulo'   => 'Filtrar la lista',
                        'pregunta' => '¿Cómo veo solo las sucias?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="hb.filtros"]',
                                'titulo' => 'Por hotel y por estado',
                                'texto'  => 'Filtra por hotel y por estado (sucias, por auditar, rechazadas…). Lo que elijas se recuerda para la próxima vez.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES · TURNOS — vista: views/ajustes-turnos.php
            //  Ruta /ajustes/turnos (fija). Dos pestañas: «Catálogo» (los turnos)
            //  y «Asignación» (calendario semanal). Como las pestañas son x-show
            //  (ambas en el DOM), el recorrido de asignación ancla a las PESTAÑAS
            //  (siempre visibles), no al calendario oculto en la otra pestaña.
            // ════════════════════════════════════════════════════════════
            'ajustes.turnos' => [
                'nombre'    => 'Turnos',
                'capacidad' => 'turnos.ver',
                'recorridos' => [

                    // ── 1. El catálogo de turnos. ──
                    [
                        'id'       => 'catalogo',
                        'v'        => 1,
                        'titulo'   => 'Definir los turnos',
                        'pregunta' => '¿Cómo defino un turno?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="tur.catalogo"]',
                                'titulo' => 'Los turnos y su horario',
                                'texto'  => 'En «Catálogo» ves los turnos con su horario (mañana, tarde…). Con «Nuevo turno» creas uno y el lápiz lo edita.',
                            ],
                        ],
                    ],

                    // ── 2. Asignar la semana. Solo con permiso. ──
                    [
                        'id'        => 'asignar',
                        'v'         => 1,
                        'titulo'    => 'Armar la semana',
                        'pregunta'  => '¿Cómo pongo quién trabaja?',
                        'capacidad' => 'turnos.asignar_a_usuario',
                        'requiere'  => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="tur.tabs"]',
                                'titulo' => 'El calendario de la semana',
                                'texto'  => 'Cambia a la pestaña «Asignación»: es un calendario con cada trabajador y cada día. Tocas una celda para ponerle un turno.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  HOME TRABAJADOR — vista: views/home-trabajador.php
            //  Ruta /home (home.php lo incluye para el encargado de limpieza;
            //  TourResolver::resolveHome() lo mapea a 'home.trabajador' —es el
            //  fallback: sin ajustes/supervisora/recepción). Filosofía «una
            //  habitación a la vez»: ve SOLO su pieza actual, nunca la lista.
            // ════════════════════════════════════════════════════════════
            'home.trabajador' => [
                'nombre'    => 'Inicio',
                'recorridos' => [

                    // ── 1. El trabajo: su pieza actual. Va primero. ──
                    [
                        'id'       => 'empezar',
                        'v'        => 1,
                        'titulo'   => 'Empezar a limpiar',
                        'pregunta' => '¿Por dónde empiezo?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="htr.actual"]',
                                'titulo' => 'Tu pieza de ahora',
                                'texto'  => 'Acá está la pieza que te toca ahora, de a una. Toca «Comenzar limpieza» (o «Continuar») para abrir su checklist.',
                            ],
                        ],
                    ],

                    // ── 2. Avisar que estás libre (cuando no hay piezas). ──
                    [
                        'id'       => 'avisar',
                        'v'        => 1,
                        'titulo'   => 'Avisar que estás libre',
                        'pregunta' => '¿Y si no tengo piezas hoy?',
                        'requiere' => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="htr.disponible"]',
                                'titulo' => 'Que sepan que puedes más',
                                'texto'  => 'Si no tienes piezas asignadas, toca «Avisar que estoy disponible» y tu supervisora sabrá que puede darte más.',
                            ],
                        ],
                    ],

                    // ── 3. Reportar un problema. Solo si puede crear tickets. ──
                    [
                        'id'        => 'reportar',
                        'v'         => 1,
                        'titulo'    => 'Reportar un problema',
                        'pregunta'  => '¿Cómo aviso una avería?',
                        'capacidad' => 'tickets.crear',
                        'requiere'  => [],
                        'pasos' => [
                            [
                                'sel'    => '[data-tour="htr.reportar"]',
                                'titulo' => 'Algo roto o que falta',
                                'texto'  => 'Si algo está roto o necesita mantención, toca «Reportar un problema». Se crea un ticket para que lo revisen.',
                            ],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  HABITACIONES · TRABAJADOR — vista: views/habitaciones.php (rama
            //  sin ver_todas). /habitaciones lo resuelve por rol. Reusa el ancla
            //  hb.grid (los filtros hb.filtros son solo de la supervisora).
            // ════════════════════════════════════════════════════════════
            'habitaciones.trabajador' => [
                'nombre'    => 'Mis habitaciones',
                'capacidad' => 'habitaciones.ver_asignadas_propias',
                'recorridos' => [
                    [
                        'id' => 'abrir', 'v' => 1,
                        'titulo' => 'Abrir una pieza',
                        'pregunta' => '¿Cómo entro a limpiar una pieza?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hb.grid"]', 'titulo' => 'Tus piezas de hoy',
                             'texto' => 'Acá aparecen solo las piezas que te toca limpiar hoy: cada tarjeta muestra su número y su tipo. Tócala para abrir la pieza y empezar a limpiarla.'],
                        ],
                    ],
                    [
                        'id' => 'leer', 'v' => 1,
                        'titulo' => 'Leer las etiquetas',
                        'pregunta' => '¿Qué significan los colores y etiquetas?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hb.grid"]', 'titulo' => 'En qué va cada pieza',
                             'texto' => 'La etiqueta de estado dice cómo va: «Pendiente» si está sucia, «En progreso» si empezaste, «Por auditar» al terminarla, y «Aprobada» o «Rechazada» según la revisión.'],
                            ['sel' => '[data-tour="hb.grid"]', 'titulo' => 'Huésped y sábanas',
                             'texto' => 'Otras etiquetas avisan del huésped: «Llega hoy», «Se va hoy», «Día/noche» o «Sigue». Si ves «Sábanas hoy», a esa pieza le toca cambio de sábanas.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  HOME RECEPCIÓN — vista: views/home-recepcion.php. /home por rol
            //  (resolveHome → 'home.recepcion'). Bandeja de auditoría pendiente.
            // ════════════════════════════════════════════════════════════
            'home.recepcion' => [
                'nombre' => 'Panel de recepción',
                'recorridos' => [
                    [
                        'id' => 'auditar', 'v' => 1,
                        'titulo' => 'Auditar las piezas',
                        'pregunta' => '¿Cómo audito una habitación?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hrec.pendientes"]', 'titulo' => 'Piezas por auditar',
                             'texto' => 'Acá aparecen las piezas que esperan tu auditoría; toca una y se abre su auditoría para revisar el trabajo. Cuando no queda ninguna, ves «No hay habitaciones».'],
                        ],
                    ],
                    [
                        'id' => 'hotel', 'v' => 1,
                        'titulo' => 'Filtrar por hotel',
                        'pregunta' => '¿Cómo veo un solo hotel?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hrec.hotel"]', 'titulo' => 'Elegir el hotel',
                             'texto' => 'Con este menú eliges «Ambos hoteles», «Atankalama» o «Atankalama INN». La lista se recarga solo con ese hotel y tu preferencia queda guardada.'],
                        ],
                    ],
                    [
                        'id' => 'novedades', 'v' => 1,
                        'titulo' => 'Revisar novedades',
                        'pregunta' => '¿Cómo me entero de lo nuevo?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hrec.notif"]', 'titulo' => 'Tus avisos',
                             'texto' => 'La campana muestra un globo azul con la cantidad de avisos sin leer. Al tocarla se abren tus notificaciones para ver qué pasó.'],
                            ['sel' => '[data-tour="hrec.refrescar"]', 'titulo' => 'Traer lo último',
                             'texto' => 'Con este botón vuelves a pedir la lista al momento. Igual la pantalla se actualiza sola cada pocos minutos y al volver a ella.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  HOME ADMIN — vista: views/home-admin.php. /home por rol
            //  (resolveHome → 'home.admin'). Dashboard con pestañas (x-show;
            //  en desktop se ven todas). Recortado a lo esencial.
            // ════════════════════════════════════════════════════════════
            'home.admin' => [
                'nombre' => 'Panel del administrador',
                'recorridos' => [
                    [
                        'id' => 'alertas', 'v' => 1,
                        'titulo' => 'Revisar las alertas',
                        'pregunta' => '¿Qué alertas hay?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hadm.alertas"]', 'titulo' => 'Las alertas críticas',
                             'texto' => 'Acá se juntan las alertas críticas, como una sincronización de Cloudbeds caída o una pieza rechazada. Cada tarjeta trae hasta dos botones para resolverla.'],
                        ],
                    ],
                    [
                        'id' => 'operativas', 'v' => 1,
                        'titulo' => 'Ver las operativas',
                        'pregunta' => '¿Cómo van las operativas de hoy?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hadm.contadores"]', 'titulo' => 'Los cuatro contadores',
                             'texto' => 'Estos cuatro contadores resumen el día: habitaciones limpias, auditorías, trabajadores en turno y tickets abiertos. Se recalculan según el hotel que elijas.'],
                            ['sel' => '[data-tour="hadm.kpis"]', 'titulo' => 'Los tres indicadores',
                             'texto' => 'Estas barras muestran el tiempo promedio por pieza, la tasa de rechazo y la eficiencia del equipo. Cada una se pinta verde, ámbar o roja según su meta.'],
                        ],
                    ],
                    [
                        'id' => 'sistema', 'v' => 1,
                        'titulo' => 'Vigilar la salud técnica',
                        'pregunta' => '¿Está todo bien con el sistema?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hadm.sistema"]', 'titulo' => 'La salud del sistema',
                             'texto' => 'Esta zona reúne el estado de Cloudbeds, los errores del día, el uso de la base de datos, quién está conectado y la versión de la app.'],
                        ],
                    ],
                    [
                        'id' => 'hotel', 'v' => 1,
                        'titulo' => 'Cambiar de hotel',
                        'pregunta' => '¿Cómo filtro por hotel?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="hadm.hotel"]', 'titulo' => 'Filtrar por hotel',
                             'texto' => 'Con este menú eliges «Ambos hoteles», «Atankalama» o «Atankalama Inn». Los contadores y los indicadores se recalculan solo para ese hotel.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  USUARIOS — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'usuarios' => [
                'nombre' => 'Usuarios',
                'capacidad' => 'usuarios.ver',
                'recorridos' => [
                    [
                        'id' => 'crear-usuario', 'v' => 1,
                        'titulo' => 'Crear una cuenta',
                        'pregunta' => '¿Cómo agrego a alguien nuevo al equipo?',
                        'capacidad' => 'usuarios.crear',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="usr.nuevo"]', 'titulo' => 'Dar de alta a una persona',
                             'texto' => 'Con «Nuevo usuario» se abre un formulario para cargar RUT, nombre y al menos un rol. Al crear, la app genera una contraseña temporal que anotas y le entregas; la deberá cambiar al entrar.'],
                        ],
                    ],
                    [
                        'id' => 'buscar-usuario', 'v' => 1,
                        'titulo' => 'Buscar a una persona',
                        'pregunta' => '¿Cómo encuentro a una persona en la lista?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="usr.buscar"]', 'titulo' => 'Buscar por nombre o RUT',
                             'texto' => 'Escribe un nombre o un RUT y la lista se va filtrando sola mientras escribes. Quedan solo las personas que coinciden.'],
                            ['sel' => '[data-tour="usr.filtro-rol"]', 'titulo' => 'Acotar por rol',
                             'texto' => 'Estos botones acotan por rol: «Trabajadores», «Supervisoras», «Recepción» o «Admins». Toca uno y la lista muestra solo ese grupo.'],
                            ['sel' => '[data-tour="usr.filtro-estado"]', 'titulo' => 'Activos o inactivos',
                             'texto' => 'Con «Activos» ves a quién puede entrar a la app y con «Inactivos» a quién está deshabilitado. «Todos» los muestra juntos.'],
                        ],
                    ],
                    [
                        'id' => 'editar-usuario', 'v' => 1,
                        'titulo' => 'Editar datos y roles',
                        'pregunta' => '¿Cómo cambio los datos o el rol de alguien?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="usr.lista"]', 'titulo' => 'Abrir la ficha de una persona',
                             'texto' => 'Cada tarjeta es una persona con su nombre, RUT y roles. Al tocarla se abre su ficha, donde puedes cambiar nombre, email y hotel, y agregar o quitar roles según tus permisos.'],
                        ],
                    ],
                    [
                        'id' => 'resetear-desactivar', 'v' => 1,
                        'titulo' => 'Resetear clave o desactivar',
                        'pregunta' => '¿Cómo reseteo la clave o desactivo a alguien?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="usr.lista"]', 'titulo' => 'Acciones desde la ficha',
                             'texto' => 'Al abrir la ficha de una persona puedes generar una nueva contraseña temporal si la olvidó, o desactivarla para que no entre a la app. Ambas acciones dependen de tus permisos.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  REPORTES — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'reportes' => [
                'nombre' => 'Reportes y KPIs',
                'capacidad' => 'reportes.ver',
                'recorridos' => [
                    [
                        'id' => 'filtrar', 'v' => 1,
                        'titulo' => 'Filtrar los indicadores',
                        'pregunta' => '¿Cómo veo los KPIs del período?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="rep.filtros"]', 'titulo' => 'Elige período y hotel',
                             'texto' => 'Con «Hoy», «Últimos 7 días» o «Últimos 30 días» eliges cuánto mirar; «Personalizado» abre un rango «Desde»–«Hasta». Los selectores de hotel y trabajadora afinan los datos, que se recalculan al elegir.'],
                            ['sel' => '[data-tour="rep.kpis"]', 'titulo' => 'Los KPIs del equipo',
                             'texto' => 'Cada tarjeta resume un indicador del período: tiempo promedio, tasa de rechazo, eficiencia, créditos y más. El puntito de color avisa si va bien (verde), en alerta (amarillo) o crítico (rojo).'],
                            ['sel' => '[data-tour="rep.tabla"]', 'titulo' => 'Detalle por trabajadora',
                             'texto' => 'La tabla desglosa a cada persona: tiempo, rechazo, eficiencia, créditos, aprobación a la 1ª y productividad. Al tocar una fila, los indicadores de arriba se filtran solo a esa trabajadora.'],
                        ],
                    ],
                    [
                        'id' => 'descargar', 'v' => 1,
                        'titulo' => 'Descargar los reportes',
                        'pregunta' => '¿Cómo exporto los datos a una planilla?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="rep.exportar"]', 'titulo' => 'Bajar los KPIs del período',
                             'texto' => 'El botón «Exportar Excel» genera un archivo con los indicadores del período y los filtros elegidos, listo para abrir en Excel.'],
                            ['sel' => '[data-tour="rep.mensual"]', 'titulo' => 'Bajar el mes por trabajador',
                             'texto' => 'Dentro de «Resumen mensual por trabajador», «Exportar» descarga la planilla del mes elegido con las habitaciones y créditos de cada persona.'],
                            ['sel' => '[data-tour="rep.auditorias"]', 'titulo' => 'Bajar el mes de auditorías',
                             'texto' => 'En «Resumen mensual de auditorías», «Exportar» descarga la planilla del mes con el total y el desglose por auditor.'],
                        ],
                    ],
                    [
                        'id' => 'mensuales', 'v' => 1,
                        'titulo' => 'Consultar los resúmenes',
                        'pregunta' => '¿Cómo veo los totales de cada mes?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="rep.mensual"]', 'titulo' => 'Resumen mensual del equipo',
                             'texto' => 'Elige un mes arriba y la tabla muestra, por trabajador, cuántas habitaciones limpió y sus créditos frente al máximo posible.'],
                            ['sel' => '[data-tour="rep.auditorias"]', 'titulo' => 'Auditorías del mes',
                             'texto' => 'Aquí ves, por auditor, cuántas habitaciones revisó y cómo quedaron: aprobadas, con observación o rechazadas.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES.RBAC — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'ajustes.rbac' => [
                'nombre' => 'Roles y permisos',
                'capacidad' => 'permisos.asignar_a_rol',
                'recorridos' => [
                    [
                        'id' => 'editar-matriz', 'v' => 1,
                        'titulo' => 'Cambiar los permisos',
                        'pregunta' => '¿Cómo doy o quito permisos a un rol?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="rbac.matriz"]', 'titulo' => 'La matriz de roles y permisos',
                             'texto' => 'Cada fila es un permiso y cada columna un rol. Toca la casilla del cruce para darle o quitarle ese permiso al rol; la que cambies queda con un borde ámbar hasta que guardes.'],
                            ['sel' => '[data-tour="rbac.guardar"]', 'titulo' => 'Guardar o descartar',
                             'texto' => 'Al cambiar algo aparece esta franja con los cambios sin guardar. «Guardar cambios» los aplica y «Descartar» los deshace; si te quitas «permisos.asignar_a_rol» te avisa que perderás el acceso.'],
                        ],
                    ],
                    [
                        'id' => 'crear-rol', 'v' => 1,
                        'titulo' => 'Crear un rol',
                        'pregunta' => '¿Cómo creo un rol nuevo?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="rbac.nuevo-rol"]', 'titulo' => 'Crear un rol nuevo',
                             'texto' => 'El botón «Nuevo rol» abre un formulario para crear un rol. Cuando lo guardas aparece como una columna más en la matriz, lista para asignarle permisos.'],
                        ],
                    ],
                    [
                        'id' => 'editar-borrar-rol', 'v' => 1,
                        'titulo' => 'Editar o borrar roles',
                        'pregunta' => '¿Cómo edito o elimino un rol?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="rbac.cabecera"]', 'titulo' => 'Editar un rol',
                             'texto' => 'En la cabecera de cada columna, el lápiz abre el rol para cambiar su nombre o descripción. En los roles de sistema (etiqueta «sys») el lápiz solo deja editar la descripción.'],
                            ['sel' => '[data-tour="rbac.cabecera"]', 'titulo' => 'Eliminar un rol',
                             'texto' => 'El tacho rojo borra el rol, previa confirmación. Solo aparece en los roles que no son de sistema, y si el rol tiene usuarios asignados la operación falla.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES.CHECKLISTS — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'ajustes.checklists' => [
                'nombre' => 'Editor de checklists',
                'capacidad' => 'checklists.editar',
                'recorridos' => [
                    [
                        'id' => 'editar', 'v' => 1,
                        'titulo' => 'Editar el checklist',
                        'pregunta' => '¿Cómo cambio los ítems y créditos de un tipo?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="chk.tarjetas"]', 'titulo' => 'Cada tipo, su checklist',
                             'texto' => 'Cada tarjeta es un tipo de habitación con sus ítems y créditos. Al tocar «Editar checklist» se abre el editor para agregar, quitar y reordenar ítems, y ponerles créditos.'],
                            ['sel' => '[data-tour="chk.intro"]', 'titulo' => 'Qué ítems dan créditos',
                             'texto' => 'Solo los ítems marcados «Obligatorio» suman créditos cuando el trabajador los completa. Los opcionales no dan créditos, así que el total premia lo que de verdad importa.'],
                        ],
                    ],
                    [
                        'id' => 'por-hotel', 'v' => 1,
                        'titulo' => 'Separar por hotel',
                        'pregunta' => '¿Cómo le doy su propio checklist a cada hotel?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="chk.por-hotel"]', 'titulo' => 'Un checklist por hotel',
                             'texto' => 'Al activar «Separar checklists por hotel», cada hotel puede tener su propia versión de un tipo; si no la tiene, usa la compartida. Aparece un selector «Compartido» y un botón por hotel para elegir cuál editas.'],
                            ['sel' => '[data-tour="chk.tarjetas"]', 'titulo' => 'Cambios solo de ese hotel',
                             'texto' => 'Con un hotel elegido, la tarjeta dice «Personalizar» cuando ese tipo todavía usa el compartido. Al guardar ahí, se crea o actualiza solo la versión de ese hotel; la compartida y la del otro hotel no cambian.'],
                        ],
                    ],
                    [
                        'id' => 'ver-versiones', 'v' => 1,
                        'titulo' => 'Ver versiones pasadas',
                        'pregunta' => '¿Dónde veo las versiones pasadas de un checklist?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="chk.tarjetas"]', 'titulo' => 'Abrir el historial',
                             'texto' => 'El botón «Historial» de cada tarjeta abre las versiones anteriores del checklist, de solo lectura. No se pueden editar porque los reportes de días ya cerrados se calculan con ellas.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES.ALERTAS — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'ajustes.alertas' => [
                'nombre' => 'Alertas',
                'capacidad' => 'alertas.configurar_umbrales',
                'recorridos' => [
                    [
                        'id' => 'ajustar', 'v' => 1,
                        'titulo' => 'Ajustar los umbrales',
                        'pregunta' => '¿Cómo cambio los umbrales de las alertas?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="alt.explicacion"]', 'titulo' => 'Qué controlan estos valores',
                             'texto' => 'Estos minutos definen cuándo saltan las alertas predictivas, como la de «trabajador en riesgo». Si los subes o bajas, cambias qué tan pronto avisa el sistema.'],
                            ['sel' => '[data-tour="alt.campos"]', 'titulo' => 'Los cuatro valores',
                             'texto' => 'Cada valor es un tiempo en minutos y trae su «default» al lado. Si cambias uno, el campo se resalta en amarillo para que veas qué tocaste antes de guardar.'],
                            ['sel' => '[data-tour="alt.cambios"]', 'titulo' => 'Cambios sin guardar',
                             'texto' => 'Cuando editas algo aparece este aviso con el número de «cambios sin guardar». Es solo un recordatorio: todavía no se aplicó nada.'],
                            ['sel' => '[data-tour="alt.acciones"]', 'titulo' => 'Guardar o descartar',
                             'texto' => 'Con «Guardar» quedan aplicados los nuevos valores y el sistema recalcula las alertas al toque. Con «Descartar» vuelves a los valores que tenías al abrir.'],
                        ],
                    ],
                    [
                        'id' => 'recalcular', 'v' => 1,
                        'titulo' => 'Recalcular las alertas',
                        'pregunta' => '¿Cómo fuerzo el recálculo de las alertas?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="alt.recalcular"]', 'titulo' => 'Recalcular al instante',
                             'texto' => 'El botón «Recalcular ahora» vuelve a evaluar todas las alertas en el momento, sin esperar el intervalo automático. Úsalo para ver el efecto de un cambio recién guardado.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES.IMPORTAR_TURNOS — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'ajustes.importar_turnos' => [
                'nombre' => 'Importar turnos desde Breik',
                'capacidad' => 'turnos.importar',
                'recorridos' => [
                    [
                        'id' => 'subir', 'v' => 1,
                        'titulo' => 'Subir el CSV de Breik',
                        'pregunta' => '¿Cómo cargo el archivo de turnos?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="imp.instrucciones"]', 'titulo' => 'De dónde sale el archivo',
                             'texto' => 'Acá están los pasos para sacar el CSV desde Breik: entra a «Reportes», elige «Turnos planificados», marca el rango de fechas y usa «Exportar → CSV». Ese archivo es el que subes aquí.'],
                            ['sel' => '[data-tour="imp.dropzone"]', 'titulo' => 'Carga el CSV aquí',
                             'texto' => 'Arrastra el archivo a este recuadro o tócalo para elegirlo desde tu equipo. Acepta el CSV de Breik de hasta 5 MB; cuando queda cargado, ves su nombre en azul.'],
                            ['sel' => '[data-tour="imp.analizar"]', 'titulo' => 'Analiza el archivo',
                             'texto' => 'Con «Analizar archivo» el sistema lee el CSV y arma una vista previa, sin guardar nada todavía. Recién ahí pasas a revisar qué personas y turnos trae.'],
                        ],
                    ],
                    [
                        'id' => 'revisar', 'v' => 1,
                        'titulo' => 'Revisar la previsualización',
                        'pregunta' => '¿Qué se va a importar exactamente?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="imp.resumen"]', 'titulo' => 'El resumen de un vistazo',
                             'texto' => 'Estas tarjetas te muestran cuántos «Turnos nuevos» entrarían, cuántas «Personas» se reconocieron y el rango de fechas «Desde» y «Hasta» que cubre el archivo.'],
                            ['sel' => '[data-tour="imp.no-encontrados"]', 'titulo' => 'Personas que se omiten',
                             'texto' => 'Si alguien del archivo no está registrado en el sistema, aparece en este bloque «Personas no encontradas» y sus turnos no se importan. Créales antes su usuario si quieres incluirlos.'],
                        ],
                    ],
                    [
                        'id' => 'confirmar', 'v' => 1,
                        'titulo' => 'Confirmar la importación',
                        'pregunta' => '¿Cómo confirmo y evito duplicados?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="imp.duplicados"]', 'titulo' => 'Turnos que ya existen',
                             'texto' => 'Cuando algunos turnos ya están cargados para esas fechas, eliges qué hacer: «Saltar» los deja como están o «Reemplazar» los sobrescribe con los del archivo.'],
                            ['sel' => '[data-tour="imp.confirmar"]', 'titulo' => 'Confirma y termina',
                             'texto' => 'Con «Confirmar importación» se guardan los turnos nuevos y llegas a la pantalla de resultado, que te dice cuántos «turnos importados» quedaron y cuántos duplicados se omitieron.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'ajustes' => [
                'nombre' => 'Ajustes',
                'recorridos' => [
                    [
                        'id' => 'navegar', 'v' => 1,
                        'titulo' => 'Recorrer los ajustes',
                        'pregunta' => '¿Qué puedo configurar acá?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="aj.secciones"]', 'titulo' => 'Las secciones de la app',
                             'texto' => 'Cada tarjeta te lleva a una sección para configurar la app, como «Mi cuenta», «Checklists» o «Turnos». Solo aparecen las que tus permisos permiten, así que puedes ver más o menos opciones que otra persona.'],
                            ['sel' => '[data-tour="aj.contador"]', 'titulo' => 'Cuántas tienes disponibles',
                             'texto' => 'Arriba dice cuántas «secciones disponibles» tienes según tu rol. Si te falta una que necesitas, pídele a un administrador que te dé el permiso.'],
                        ],
                    ],
                    [
                        'id' => 'activar', 'v' => 1,
                        'titulo' => 'Activar los avisos',
                        'pregunta' => '¿Cómo recibo alertas con la app cerrada?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="aj.push"]', 'titulo' => 'Notificaciones en el celular',
                             'texto' => 'Al activar el interruptor, este dispositivo recibe alertas aunque la app esté cerrada y queda como «Activadas en este dispositivo». Si el navegador las bloqueó, verás «Bloqueado» y hay que habilitarlas ahí.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES.COLORES — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'ajustes.colores' => [
                'nombre' => 'Colores',
                'capacidad' => 'apariencia.editar',
                'recorridos' => [
                    [
                        'id' => 'estados', 'v' => 1,
                        'titulo' => 'Pintar los estados',
                        'pregunta' => '¿Cómo cambio el color de cada estado?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="col.estados"]', 'titulo' => 'Un color por estado',
                             'texto' => 'Cada estado de la pieza tiene su color. Toca el cuadrito de color de la fila y elige uno nuevo; la muestra de al lado te anticipa cómo se verá esa etiqueta en las tarjetas.'],
                            ['sel' => '[data-tour="col.guardar"]', 'titulo' => 'Aplica para todo el equipo',
                             'texto' => 'Al tocar «Guardar colores» el cambio queda aplicado para todo el equipo al instante. La app deriva sola las versiones para modo claro y oscuro, no tienes que hacer nada más.'],
                        ],
                    ],
                    [
                        'id' => 'hoteles', 'v' => 1,
                        'titulo' => 'Marcar cada hotel',
                        'pregunta' => '¿Cómo distingo las piezas de cada hotel?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="col.hoteles"]', 'titulo' => 'El acento de cada hotel',
                             'texto' => 'Cada hotel tiene un color de acento que pinta el borde de sus tarjetas. Elige el color y la mini-tarjeta de muestra te enseña cómo quedan el borde y el tinte resultantes.'],
                            ['sel' => '[data-tour="col.guardar"]', 'titulo' => 'Guarda para que se vea',
                             'texto' => 'El acento nuevo se aplica en toda la app recién cuando tocas «Guardar colores». Antes de eso solo lo ves en la muestra de esta pantalla.'],
                        ],
                    ],
                    [
                        'id' => 'restaurar', 'v' => 1,
                        'titulo' => 'Restaurar la paleta',
                        'pregunta' => '¿Cómo vuelvo a los colores originales?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="col.restaurar"]', 'titulo' => 'Volver a los originales',
                             'texto' => '«Restaurar originales» recarga la paleta inicial de la app en el editor. Ojo: todavía no se aplica, tienes que tocar «Guardar colores» para que el equipo la vea.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES.MI_CUENTA — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'ajustes.mi_cuenta' => [
                'nombre' => 'Mi cuenta',
                'recorridos' => [
                    [
                        'id' => 'editar', 'v' => 1,
                        'titulo' => 'Editar tus datos',
                        'pregunta' => '¿Cómo cambio mi nombre o email?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="cta.datos"]', 'titulo' => 'Tus datos personales',
                             'texto' => 'Tu «RUT» queda fijo, pero puedes cambiar tu nombre, email y hotel por defecto. Al tocar «Guardar cambios» se guardan y la pantalla se actualiza; si los campos aparecen bloqueados es que no tienes permiso para editarlos.'],
                        ],
                    ],
                    [
                        'id' => 'tema', 'v' => 1,
                        'titulo' => 'Elegir el tema',
                        'pregunta' => '¿Cómo pongo la app en modo oscuro?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="cta.tema"]', 'titulo' => 'Claro, oscuro o automático',
                             'texto' => 'Eliges entre «Automático», «Claro» u «Oscuro». El cambio se aplica al instante y queda recordado en este dispositivo; «Automático» sigue lo que use tu teléfono o computador.'],
                        ],
                    ],
                    [
                        'id' => 'seguridad', 'v' => 1,
                        'titulo' => 'Cerrar sesión o clave',
                        'pregunta' => '¿Cómo cambio mi clave o cierro sesión?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="cta.seguridad"]', 'titulo' => 'Cambiar tu contraseña',
                             'texto' => 'Al tocar «Cambiar contraseña» se abre una ventana para poner una clave nueva. Así mantienes tu cuenta protegida.'],
                            ['sel' => '[data-tour="cta.sesion"]', 'titulo' => 'Cerrar tu sesión',
                             'texto' => 'Al tocar «Cerrar sesión» te pedimos confirmar y luego te lleva de vuelta al inicio de sesión. Úsalo cuando termines, sobre todo en un equipo compartido.'],
                        ],
                    ],
                ],
            ],

            // ════════════════════════════════════════════════════════════
            //  AJUSTES.VERSIONES — generada del borrador (workflow), revisada.
            // ════════════════════════════════════════════════════════════
            'ajustes.versiones' => [
                'nombre' => 'Versiones',
                'recorridos' => [
                    [
                        'id' => 'entender-historial', 'v' => 1,
                        'titulo' => 'Leer el historial',
                        'pregunta' => '¿Qué muestra el historial de versiones?',
                        'requiere' => [],
                        'pasos' => [
                            ['sel' => '[data-tour="ver.encabezado"]', 'titulo' => 'En qué versión estás',
                             'texto' => 'Acá arriba te decimos qué versión de la app estás usando ahora mismo. Es solo informativo: esta pantalla no cambia nada, únicamente muestra los cambios que ha tenido la app.'],
                            ['sel' => '[data-tour="ver.lista"]', 'titulo' => 'Cada versión y sus cambios',
                             'texto' => 'Cada fila es una versión: a la izquierda su número y su fecha, a la derecha lo que se cambió en ella. La versión que estás usando aparece en azul con «En uso»; las que todavía no salen dicen «Sin publicar».'],
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
