# Inspección pre-entrega (v7; se armó como v6.18)

Recepción revisa una habitación **antes de entregársela al huésped** y registra si está en condiciones de
entregarse: **SÍ** o **NO**. Gerencia la llama «inspección visión cliente» (cómo la vería el cliente).
Pedido de gerencia del 04/10/2026; diseño final de Nicolás del 05/10/2026.

> **Nombres:** en pantalla es **«Inspección pre-entrega»**. En el código, la base y las rutas se llama
> `revision_entrega` (tablas `revisiones_entrega` / `motivos_revision_entrega`, permisos `revision_entrega.*`,
> `RevisionEntregaService`, `/api/revision-entrega`). No es la inspección de las supervisoras
> ([auditoria.md](auditoria.md)): nunca escribe `auditorias`, no tiene sus veredictos y no es inmutable.

## 1. Decisiones (Nicolás, 04–05/10/2026)

1. **Va dentro de Habitaciones, no en una pestaña aparte.** Cada tarjeta conserva su botón «N» de nochero y suma
   al pie un **botón grande «Inspección pre-entrega»** (del tamaño de la franja de estado). El botón abre una
   ventana: «¿La habitación está en condiciones para entregarse a un cliente?» con **SÍ** y **NO**. El NO abre una
   segunda ventana con el checklist de motivos, observaciones y foto.
2. **Recepción sin «Inicio»:** su pantalla principal es Habitaciones. Tampoco ve **Edificios y Mapeo** en Ajustes.
3. **No es obligatoria.** Lo ideal es revisar todas las piezas antes de entregarlas, pero la app no bloquea nada.
4. **Cualquier pieza, en cualquier estado.** El estado de aseo es contexto, nunca bloquea.
5. **Un NO lleva UN motivo** del catálogo (obligatorio) + observaciones opcionales + una foto opcional.
6. **Qué hace un NO lo decide un interruptor** en Ajustes → Inspección pre-entrega:
   - **Apagado (default):** solo queda registrado y avisa a las supervisoras. La pieza no cambia de estado y no se
     toca Cloudbeds. La supervisora decide con lo que ya existe (marcar sucia, reasignar).
   - **Prendido:** además, si la pieza está **aprobada** (`aprobada`, `aprobada_con_observacion`, `aprobada_automatica`),
     vuelve a `sucia` y se avisa `dirty` a Cloudbeds, igual que «Marcar sucia» a mano. Las que no están aprobadas no
     cambian. Si alguien la tenía asignada hoy (quien la limpió), vuelve a su cola y se le avisa. La re-limpieza no
     suma en los KPIs (decisión 10).
7. **Para el KPI de calidad de las supervisoras:** cada SÍ y cada NO guarda una foto del momento (ver §2). El KPI
   todavía no se calcula: se arma cuando gerencia defina la fórmula (pendiente en [kpis-sueldos.md](kpis-sueldos.md)).
8. **Catálogo de motivos:** lo manejan Supervisora y Admin (`revision_entrega.configurar`), no Recepción. Se siembran
   8 motivos iniciales. Se crean, renombran y activan/desactivan; **no se borran ni se reordenan** (orden alfabético).
9. **El botón de la tarjeta se reinicia cuando la pieza cambia de estado**, no a medianoche (05/10): el resultado
   («Aprobada» / «No aprobada») vale mientras la pieza siga igual; cuando se ensucia, empiezan a limpiarla o la
   aprueban, vuelve a «Inspección pre-entrega». Así un NO sin resolver no desaparece a las 00:00 y un SÍ no queda pegado
   a una pieza que se volvió a ensuciar. Ver «Revisión vigente» en §2.
10. **«Re-limpiar» (05/10):** sobre un NO vigente de una pieza aprobada, la supervisora toca «Re-limpiar» en la franja
    de la tarjeta (o en el detalle), elige una trabajadora con turno hoy y, por defecto, la deja **primera de su cola**.
    Sirve con el interruptor apagado y prendido (prendido, la pieza ya está sucia: solo la asigna). **La re-limpieza no
    suma ni resta en los KPIs** de aseo (piezas, créditos, tiempos, productividad, rechazos, Asignadas, bono) **ni en
    los de inspección** (cobertura, rechazo, aprobación a la primera, inspecciones por inspectora); a quien la limpió
    primero no se le toca nada. Los días trabajados sí cuentan. Ver §3b.
11. **El NO le cuenta a la supervisora que aprobó la pieza (05/10):** su bono de calidad se verá afectado por lo que
    Recepción aprueba y rechaza. Desde ya, Reportes muestra la columna **«Recepción»** (aprobadas en verde / no aprobadas
    en rojo) en «Supervisora · Inspección», en el «Resumen mensual de inspecciones» y en la pestaña «Supervisores» del
    Excel mensual. **Fórmula de gerencia (10/10):** al lado va su **«Calidad»** = (aprobadas − no aprobadas) ÷ las que
    Recepción revisó × 100, con mínimo 0 % (§3b; KPI S2.4 de [kpis-sueldos.md](kpis-sueldos.md)).

## 2. Modelo de datos

Tablas `motivos_revision_entrega` (catálogo, `nombre` UNIQUE, `activo`) y `revisiones_entrega` (una fila por cada
SÍ/NO, solo-append):

| Columna | Qué guarda |
|---|---|
| `habitacion_id`, `usuario_id` | la pieza y quién revisó |
| `resultado` | `si` / `no` |
| `motivo_id`, `comentario`, `foto_ruta` | solo con `no` (observaciones ≤300; foto en `uploads/revision-entrega/`) |
| `paso_a_sucia` | 1 si ese NO devolvió la pieza a sucia (interruptor prendido) |
| `estado_pieza` | estado de la pieza al revisar |
| `ejecucion_id` | la última limpieza de la pieza a ese momento (o NULL) |
| `auditoria_id` | la inspección de esa limpieza, si hubo (de ahí sale quién la aprobó) |
| `idempotency_key` | UUID del cliente (UNIQUE): un reintento no duplica |
| `relimpieza_asignacion_id`, `relimpieza_pedida_por`, `relimpieza_pedida_at` | la asignación que creó «Re-limpiar», quién y cuándo |
| `relimpieza_ejecucion_id` | la limpieza que rehízo la pieza: fuera de los KPIs (se vincula al empezarla) |

`estado_pieza`, `ejecucion_id` y `auditoria_id` son la **foto para el KPI de supervisoras**: qué limpieza y qué
inspección se estaba evaluando. Después ese vínculo no se puede reconstruir con certeza (la pieza se vuelve a limpiar
e inspeccionar). Se guarda tal cual; la regla de qué cuenta para el KPI (p. ej. excluir lo aprobado por el sistema,
o una aprobación de hace días) se aplica al calcularlo.

FKs `ON DELETE RESTRICT` (pieza, usuario, motivo) y `SET NULL` (ejecución, inspección, asignación y limpieza de la
re-limpieza, quien la pidió). DDL en
[database-schema.sql](database-schema.sql) / [database-schema.mariadb.sql](database-schema.mariadb.sql), bloque 10.

- **Revisión vigente** (`revisionesVigentes()`, la que pinta el botón de la tarjeta) = la de `MAX(id)` de la pieza,
  **mientras la pieza no cambie de estado** (decisión de Nicolás, 05/10/2026: se reinicia como el estado de la pieza,
  no a medianoche). Dos condiciones:
  1. La pieza sigue en el estado en que quedó al revisarla: `estado_pieza`, o `sucia` si ese NO la devolvió a sucia o
     la supervisora la mandó a re-limpiar. Desde la v6.17 todo cambio de estado de la app pasa por
     `HabitacionService::cambiarEstado` y deja su fila en el `audit_log` (R6: antes la (re)asignación y la desasignación
     de `AsignacionService` la ponían `sucia` con un UPDATE directo); esta condición queda para lo que no deja rastro,
     como un cambio hecho a mano en la base.
  2. Su último `habitacion.cambiar_estado` del `audit_log` no es posterior a la revisión, **o al pedido de re-limpieza**
     si lo hubo (`relimpieza_pedida_at`). Cubre las idas y vueltas (aprobada → sucia → … → aprobada). El paso a sucia
     que provoca el mismo «Re-limpiar» (la reasignación) queda antes del pedido, porque se anota después de reasignar:
     así un NO mandado a re-limpiar se sigue viendo hasta que empiezan a limpiar la pieza. Busca solo el último cambio
     de cada pieza (índice `entidad, entidad_id`, de atrás hacia adelante): no recorre todo el historial, que nunca se
     borra.

  El cambio a sucia que provoca el mismo NO (interruptor prendido) se hace **antes** de guardar la revisión y en la
  misma transacción, así que no la reinicia; si la revisión no se puede guardar, la pieza tampoco cambia. `created_at`
  de la revisión y `relimpieza_pedida_at` salen del reloj de la base, el mismo que fecha el `audit_log`.
- **Interruptor** = fila `revision_entrega_no_ensucia` de `alertas_config` (`'1'` prendido; sin fila = apagado). Solo
  lo escribe `PUT /api/revision-entrega/config`: `PUT /api/alertas/config` acepta únicamente sus claves (lista blanca).
- **Foto** = `public/uploads/revision-entrega/AAAA/MM/{hex16}.webp` (mismo `ImagenAdjuntoService` que los tickets),
  servida por `GET /uploads/…` (público con nombre impredecible, igual que los tickets). No va en los backups ni en
  los ZIP delta: ver [deploy-cpanel.md](deploy-cpanel.md) §10 y el gotcha de §11.

## 3. Reglas del backend (`RevisionEntregaService`)

- **Idempotencia:** la ventana manda un UUID por envío y lo reusa al reintentar. Una clave vale como **reintento solo
  si es la misma pieza, la misma respuesta y sigue siendo la última revisión de esa pieza** (`reintentoVigente()`):
  así una clave que quedó pegada de un envío viejo nunca se «traga» una revisión posterior. Si no es reintento, la
  revisión se guarda como nueva (sin clave). La carrera del doble toque la frena el índice UNIQUE.
- **Aviso:** notificación + push (`PushService::notificar`, tipo `revision_entrega_no`, `requireInteraction`) a los
  usuarios activos con `alertas.recibir_predictivas`, sin el usuario del cron ni quien registró el NO. Sin dedupe. El
  push solo llega a quien está de turno; la campanita la ven todos. No usa `alertas_activas` (su CHECK está cerrado en
  prod). Si el aviso falla, la revisión ya quedó: se registra el error y Recepción no ve nada raro.
  - Título: «Hab. 305 (INN): pre-entrega no aprobada».
  - Cuerpo: «Baño sucio: «la tina quedó con pelos». Revisó Carla a las 14:32. Hay foto.» y, al final, según el caso:
    «La pieza volvió a sucia y a la cola de Ana; puedes cambiarla con «Re-limpiar».» (interruptor, la tenía alguien),
    «La pieza volvió a sucia y quedó sin asignar: asígnala con «Re-limpiar».» (interruptor, nadie la tenía) o «Si hay que
    rehacerla, usa «Re-limpiar» en la pieza.» (interruptor apagado, la pieza estaba aprobada).
  - Con el interruptor, a quien la tenía asignada hoy: «Hab. 305 de vuelta en tu cola» (campanita + push si está de
    turno), con el motivo.
- **Observaciones:** los saltos de línea se normalizan antes de medir (el multipart los manda como CRLF).
- **Foto:** nunca hace fallar el NO. Si no se pudo guardar (muy pesada, formato, sin GD: `ImagenAdjuntoService`
  lanza `ImagenException('SIN_GD')`), el NO queda igual y la respuesta trae `foto_fallida`. Si el cuerpo supera
  `post_max_size`, PHP vacía el request: el controller responde 413 `CUERPO_MUY_GRANDE` y la ventana pide quitar la
  foto y reintentar con la misma clave.
- **Interruptor prendido:** `HabitacionService::cambiarEstado(…, 'sucia')` en la misma transacción que guarda la revisión
  (antes del INSERT) y, ya confirmada, `CloudbedsSyncService::escribirEstadoDirty` best-effort (si falla, levanta la
  alerta P0 de siempre).
- **Protección:** la lista y el detalle de Habitaciones leen la revisión dentro de un `try`: si la tabla faltara (SQL
  de prod no corrido), esas pantallas igual cargan.
- **Nunca:** escribe `auditorias`, `ejecuciones_checklist`, `alertas_activas`. `ReportesService` lee esta tabla solo
  para dejar fuera las re-limpiezas (§3b) y para la columna «Recepción».

## 3b. Re-limpieza (`pedirRelimpieza()`, `vincularRelimpieza()`)

- **Pedido:** `POST /api/revision-entrega/{id}/relimpiar {trabajador_id, prioridad}` (ruta con
  `asignaciones.asignar_manual`; la prioridad exige además `asignaciones.reordenar_cola_trabajador`). Solo para la
  revisión vigente de la pieza, un NO sobre una pieza aprobada (`relimpiable`). Usa `AsignacionService::reasignar`
  (hereda la franja; pasa la pieza a sucia y avisa `dirty` a Cloudbeds si estaba aprobada; a la trabajadora le llega
  «Nueva habitación asignada» con «Es una re-limpieza: Recepción no la aprobó para entregar (motivo).») y, con
  prioridad, `subirAlInicioDeCola` (la pieza queda primera; si está a mitad de otra, termina esa primero). Se puede
  volver a pedir para cambiar de trabajadora mientras no hayan empezado.
- **Vínculo:** al **empezar** cualquier limpieza (`ChecklistService::iniciarEjecucion`), si la pieza tiene una
  re-limpieza pedida **ese mismo día** (botón o interruptor prendido) y sin limpieza todavía, esa limpieza queda en
  `relimpieza_ejecucion_id`. Un pedido de otro día no se vincula: esa es la limpieza normal del día. Si el vínculo
  falla, la trabajadora igual empieza (queda en el log).
- **KPIs:** `ReportesService::sinRelimpieza()` deja fuera las limpiezas vinculadas de la ficha (créditos, piezas,
  rechazos, observaciones del bono, tiempos, Asignadas, vueltas), productividad, desmarcados, «Habitaciones
  limpiadas», la sección de supervisoras, inspecciones por inspectora, el resumen mensual de inspecciones y
  «Pendientes al corte». La asignación que creó «Re-limpiar» no es una pieza asignada; el día sí cuenta como trabajado.
- **Columna «Recepción»** (`recepcionPorSupervisora()`; regla de conteo aprobada por Nicolás el 05/10): por cada inspección **aprobada por una persona** (no el
  cierre automático) que Recepción revisó en el período: no aprobada si recibió al menos un NO, aprobada si solo SÍ.
  Ventana = fecha de la revisión de Recepción (un mes cerrado no cambia). Quien solo tiene resultados de Recepción en
  el período (aprobó antes y no inspeccionó) aparece igual, fuera del promedio.
- **Columna «Calidad»** (`calidadRecepcionPct()`, fórmula de gerencia y decisiones de Nicolás del 10/10): (aprobadas −
  no aprobadas) ÷ (aprobadas + no aprobadas) × 100 con las mismas piezas de la columna «Recepción» (lo que ella aprobó
  y Recepción no revisó no entra), **mínimo 0 %**, un decimal; sin revisiones en el período, «—». En el Excel
  («Recepción: calidad %») el TOTAL de cada hotel sale de los SÍ y NO sumados.

## 4. Pantallas

- **Habitaciones** (pantalla principal de Recepción): botón «Inspección pre-entrega» al pie de cada tarjeta para
  quien tiene `revision_entrega.registrar`. El botón muestra la revisión vigente (`revision_vigente` en
  `GET /api/habitaciones`): «Aprobada · 14:32» (verde) o «No aprobada · Baño sucio» (rojo), con la fecha si es de otro
  día («Aprobada · 04/10 18:10»); se puede volver a revisar. **Cuando la pieza cambia de estado** (se ensucia, empiezan a
  limpiarla, la aprueban), el botón vuelve a «Inspección pre-entrega». Quien ve todas las piezas sin poder inspeccionar
  (Supervisora) ve ese resultado como franja; si es un NO sobre una pieza aprobada y puede asignar, la franja es el
  botón «Re-limpiar» («No aprobada · Baño sucio · Re-limpiar», «… · En cola de Ana» o «Re-limpieza: Berta») y la
  tarjeta no se ve tenue. Para quien inspecciona, las piezas aprobadas no se ven semitransparentes (son las que más
  revisa).
- **Ventana «Re-limpiar»** (`views/componentes/modal-relimpiar-entrega.php`, en el layout con
  `asignaciones.asignar_manual`): motivo y observaciones, trabajadoras con turno hoy en el hotel de la pieza con su
  carga total, «Que sea la siguiente de su cola» (marcada por defecto) y el aviso de que no suma en los KPIs.
- **Ventana** (`views/componentes/modal-inspeccion-pre-entrega.php`, en el layout solo con `revision_entrega.registrar`):
  paso 1 SÍ / NO; paso 2 (NO) motivos en botones grandes, observaciones y «Tomar foto». Plazo de 45 s por envío con
  aviso de «señal lenta» a los 10 s; sin respuesta, guarda la clave y ofrece reintentar sin duplicar.
- **Menú:** Recepción ve Habitaciones · Inspección · Tickets · Ajustes. «Inicio» se oculta a quien no tiene Inicio
  propio (`Support\PantallaInicio`, por permisos: inspecciona sin administrar ni supervisar); `/home` lo lleva a
  Habitaciones en el servidor.
- **Ajustes → Inspección pre-entrega** (`revision_entrega.configurar`): el interruptor y el catálogo de motivos.
- **Ajustes → Edificios y Mapeo:** desde la v7 exige `habitaciones.gestionar_edificios` (antes alcanzaba con
  `habitaciones.ver_todas`, así que Recepción la veía y podía crear y borrar edificios).
- **Detalle de la pieza:** tarjeta verde o roja con la última revisión (con `vigente`). Solo con
  `habitaciones.ver_todas`. Un NO vigente sobre una pieza aprobada trae «Re-limpiar» (o «Cambiar quién la re-limpia»)
  para quien puede asignar. Desde la v7 el detalle dice «Asignada hoy a …» en vez de «pendiente de asignación»
  cuando la pieza está en la cola de otra persona.
- **Reportes → Inspección pre-entrega (Recepción):** Revisadas / Aprobadas (SÍ) / No aprobadas (NO), NO por motivo e
  historial (máx. 500 filas). Mismo período y hotel que los KPIs; no cambia los indicadores.
- **Reportes → Supervisora · Inspección** y **Resumen mensual de inspecciones** (y la pestaña «Supervisores» del
  Excel): columna «Recepción», aprobadas en verde / no aprobadas en rojo (§3b).
- **Vista guiada:** recorrido «Inspeccionar la pieza» en Habitaciones (solo para quien inspecciona, bandera
  `inspecciona_entrega`), la pantalla `ajustes.revision_entrega` y un paso en Reportes → «Inspecciones y entregas».

## 5. Permisos

`revision_entrega.registrar` (Recepción), `revision_entrega.configurar` (Supervisora) y
`habitaciones.gestionar_edificios` (Supervisora; en prod, todo rol que tenía `ver_todas` menos Recepción). Admin
tiene los tres. «Re-limpiar» no tiene permiso propio: es una asignación (`asignaciones.asignar_manual`, y
`asignaciones.reordenar_cola_trabajador` para la prioridad). Ver [roles-permisos.md](roles-permisos.md). Endpoints en
[api-endpoints.md](api-endpoints.md) §19.

## 6. Pendientes y límites conocidos

- **`sw.js` cachea `GET /uploads/*`** en la caché de páginas (network-first). Para fotos con nombre inmutable no hace
  daño, pero ocupa espacio en el celular.
- **Límite de subida de prod** (`post_max_size` / `upload_max_filesize` en MultiPHP INI) no está documentado. La ventana
  comprime la foto a 1600 px / calidad 0,8 antes de subirla (≈300–600 KB, igual que los tickets).
- **El PHP local no tiene `gd`:** la foto real solo se prueba en producción, con una test room.
- **Interruptor prendido y sync:** si el `dirty` a Cloudbeds falla, el próximo sync puede devolver la pieza a aprobada,
  exactamente como con «Marcar sucia» a mano. La guarda de la carrera lectura/cambio del sync llega con la v6.17.
- **Renombrar un motivo** cambia el texto en todo el historial (se guarda por id).
