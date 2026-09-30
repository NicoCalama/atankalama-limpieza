# Incidente del 23 de septiembre de 2026

**Reportado por:** Rodrigo Jaque, 16:45, a partir de lo que informó Yanet Valle.
**Síntoma:** "hoy no se actualizaron las habitaciones en el sistema de aseo. No se colocó
pendiente, lo está haciendo de manera manual".
**Versión en producción:** v6.10 (desplegada esa misma mañana).
**Cierre:** v6.11 para los defectos de código; el cron duplicado queda recomendado a jefatura.

---

## 1. Qué se encontró

Tres problemas distintos, que llegaron juntos y se confundían entre sí.

### 1.1 Un cron duplicado (lo más grave — NO es código)

En cPanel hay **dos entradas** para el mismo script:

```
55 23 * * *   app_core/scripts/aprobar-pendientes-cierre-dia.php
50 15 * * *   app_core/scripts/aprobar-pendientes-cierre-dia.php   ← no debería existir
```

El cierre de día aprueba solas las piezas que quedaron esperando inspección. Está pensado
para las 23:55, cuando ya nadie va a inspeccionar. La entrada de las **15:50** lo corre en
plena jornada.

Evidencia (`audit_log` del 23/09): **83 piezas** pasaron de `completada_pendiente_auditoria`
a `aprobada_automatica` por cron. Caso testigo, pieza 302 del Inn: Yanet la marcó limpia a
las 12:04 y a las **15:50** el sistema la aprobó sola, sin que nadie la revisara.

No lo detecta ninguna verificación: el runbook (`docs/deploy-cpanel.md` §8) documenta 4 cron
y este no es ninguno de ellos — lo agregó jefatura por fuera de git.

### 1.2 El sync borraba la marca de "esto lo aprobó la máquina"

`CloudbedsSyncService::sincronizar()` fuerza a `aprobada` cualquier pieza que Cloudbeds
reporte `clean` (decisión del 21/08/2026: Cloudbeds es la fuente madre del estado real). Su
lista de exclusión tenía `aprobada` y `aprobada_con_observacion`, pero **no
`aprobada_automatica`** — estado que se sumó en la v6.2 (15/09) y nunca se agregó ahí.

Resultado: diez minutos después de cada cierre de día, el sync convertía
`aprobada_automatica` → `aprobada`. **49 veces el 23/09.** Dos efectos:

1. **Se pierde la trazabilidad.** `aprobada_automatica` es la marca de que nadie inspeccionó
   esa pieza. Al convertirla en `aprobada`, los KPIs de cobertura de inspección y de
   aprobación a la primera (v6.4) la cuentan como inspeccionada. Los números de inspección
   del período están inflados.
2. **Le mueve el `updated_at`**, del que depende `conservarAprobacionDelDia()` desde la
   v6.10 para decidir si una pieza puede volver a la cola.

Caso testigo, pieza 303 del Inn: cierre de día a las 23:55 → `aprobada_automatica`; a las
**00:10** el sync la pasa a `aprobada`; queda fuera de la cola hasta que **Yanet la devuelve
a pendiente a mano a las 07:56**.

### 1.3 La regla de la v6.10 era demasiado ancha

`conservarAprobacionDelDia()` (v6.10) conserva la aprobación del día si la pieza está
ocupada. Dos agujeros:

- **`turnover`** —se va un huésped y entra otro el mismo día— llega con `roomOccupied: true`,
  y es justo cuando la pieza necesita aseo entremedio. Piezas 710 y 107, conservadas todo el
  día 23/09.
- El guard de afuera pregunta por `estaEnEstadoTerminal()`, que **también abarca
  `rechazada`**. A una pieza rechazada no la aprobó nadie: tiene que volver a la cola igual.

### 1.4 Un trabajador trabado sin explicación (caso César Viruez)

Con las 3 piezas activas de su cola ya en `aprobada`, `elegirHabitacionActual()` devuelve
`null` y `iniciarEjecucion()` respondía `NO_ES_TU_HABITACION_ACTUAL` — "empezá por tu
habitación actual", una habitación que no existe. La ficha mostraba el error y **no se
recargaba**, así que el botón "Comenzar limpieza" seguía ahí: el trabajador lo apretaba una y
otra vez. 19 piezas asignadas, 0 limpiadas.

## 2. Qué se arregló (v6.11)

| # | Cambio | Archivo |
|---|---|---|
| 1 | `aprobada_automatica` entra a la lista de exclusión: el sync ya no la re-aprueba y la marca sobrevive | `CloudbedsSyncService.php` |
| 2 | `conservarAprobacionDelDia()` solo aplica a aprobaciones (no a `rechazada`) y nunca conserva un `turnover` | `CloudbedsSyncService.php` |
| 3 | Sin ninguna pieza empezable → `SIN_HABITACION_PARA_EMPEZAR` con un mensaje que dice qué hacer | `ChecklistService.php` |
| 4 | La ficha se recarga cuando falla el inicio, en vez de dejar el botón invitando a reintentar | `habitacion-detalle.php` |

4 tests de regresión nuevos, verificados contra el código viejo (fallan los 4).
Suite 489/489, PHPStan limpio.

## 3. Lo que NO se arregló acá

- **El cron duplicado.** Es un cambio de configuración en cPanel, no de código. Recomendado
  a jefatura: borrar la entrada `50 15`.
- **Piezas que Cloudbeds da por limpias y no entran nunca a la cola.** Es la causa de fondo
  de las ~30 piezas que Yanet puso a mano. Corresponde a la pestaña de "habitaciones limpias"
  que pidió jefatura (Caso 2 de `Caso 01.docx`), pendiente de decidir.
- **La alerta `aprobacion_deshecha` falla al guardarse** (`SQLSTATE[23000]`). Descartados:
  CHECK (no existe en esa tabla), clave foránea, y contador desfasado en las dos tablas
  involucradas. Sin explicación todavía; no rompe el sync, solo el aviso.
  **→ Explicado el 27/09: el CHECK sí existe. Ver §5.**

## 4. Lecciones

- **Una columna existente usada como señal nueva hereda a todos sus escritores.** La v6.10
  usó `habitaciones.updated_at` como "cuándo se aprobó". La verificación fue sobre el código
  de la app, pero el propio cron también escribe esa columna. (Y no solo el cron: la revisión
  de la v6.16 encontró más de diez escrituras más —notas, nocheros, estructura, inventario…—, así que la señal
  se cambió al `audit_log`. Ver §5.)
- **Un estado nuevo obliga a revisar todas las listas de estados.** `aprobada_automatica`
  entró en la v6.2 y quedó fuera de una exclusión escrita en agosto.
- **Los cron no están bajo control de versiones.** El runbook documenta 4; en producción hay
  más, agregados por FTP/cPanel. Nada los compara.

## 5. Seguimiento del 27/09 (v6.16)

**La v6.11 funciona.** En `audit_log`, las conversiones `aprobada_automatica → aprobada` por cron
eran 57, 97, 60 y 49 del 20 al 23/09, y desde el 24/09 no hay ninguna. Las que siguen saliendo de
`aprobada_automatica` son todas `→ sucia`, que es el ciclo normal.

**El cron de las 15:50 sigue activo.** Aprobaciones automáticas a la hora 15 de Chile: 45 el 24/09,
48 el 25/09 y 38 el 26/09. Sigue siendo decisión de jefatura.

**Por qué fallaba la alerta `aprobacion_deshecha`: el CHECK sí existe.** La base de producción se
creó el 07/07 con `build/limpieza-inicial.sql`, que declara
`tipo varchar(40) NOT NULL CHECK (tipo in (…7 tipos…))`. Faltan `aprobacion_deshecha` (v6.10) y
también `inventario_cambios_pendientes` (v2.4): **el aviso de inventario tampoco se pudo guardar
nunca**. Reproducido en MariaDB 10.6 y 10.11 con ese dump: `ERROR 4025 (23000): CONSTRAINT
limpieza_alertas_activas.tipo failed`.

Dos cosas taparon la causa:

1. **La verificación de la v2.4 dio un falso negativo.** Consultaba
   `information_schema.CHECK_CONSTRAINTS … WHERE CONSTRAINT_SCHEMA = DATABASE()`. Sin una base
   elegida (por ejemplo, desde la pestaña SQL del servidor en phpMyAdmin), `DATABASE()` es NULL y
   la consulta devuelve 0 filas aunque el CHECK exista. Reproducido. **Lección: en phpMyAdmin,
   nombrar el schema (`'cat6852_australia'`) en vez de `DATABASE()`.**
2. **El SQL de respaldo de ese runbook tampoco habría funcionado.** `DROP CONSTRAINT tipo` da
   `ERROR 1091`: MariaDB no deja borrar por nombre un CHECK declarado en la columna. Lo que sirve
   es `MODIFY COLUMN tipo … CHECK (…)`, que reemplaza la definición entera (§11.9 del runbook).

**Arreglar solo el CHECK habría sido peor.** El sync levantaba la alerta por *cualquier* pieza
terminal que Cloudbeds marcara sucia: también la aprobada ayer (así entra cada mañana el aseo del
día) y la rechazada, que no la aprobó nadie. Y nada la resolvía. En `logs_eventos` hay 29, 149, 133,
139 y 147 intentos por día del 23 al 27/09: esas alertas P1 habrían quedado colgadas en el Inicio de
la supervisora, una por pieza.

**Qué cambió en la v6.16:**

| # | Cambio | Archivo |
|---|---|---|
| 1 | La alerta solo se levanta si se deshace una aprobación **de hoy**. El «¿es de hoy?» se pregunta antes de revertir, porque revertir es un cambio de estado nuevo | `CloudbedsSyncService.php` |
| 2 | «¿Se aprobó hoy?» sale del último cambio de estado en `audit_log` y ya no de `habitaciones.updated_at`, que también mueven la nota de Recepción, marcar nochero o editar la estructura. Con eso, una aprobación de ayer pasaba por «de hoy»: la regla de la v6.10 la conservaba y la alerta mentía. Lo encontró la revisión de la v6.16 | `HabitacionService.php` |
| 3 | Se resuelve sola cuando la pieza vuelve a quedar aprobada. Todos los caminos que aprueban pasan por `cambiarEstado()`: inspección, «cliente no desea aseo», Cloudbeds y el cierre de día. «Dar por limpia» deja la pieza en inspección, así que se resuelve al inspeccionarla | `HabitacionService.php` |
| 4 | El verificador de esquema compara también las listas de los CHECK `columna IN (…)`: el health habría dado 503 el día del deploy de la v6.10 | `EsquemaService.php` |
| 5 | SQL que amplía el CHECK a los 9 tipos, probado en las dos versiones de MariaDB | runbook §11.9 |

6 tests de regresión de la alerta (los 6 fallan contra el código anterior) y 6 del verificador.

**Lección nueva:** un valor que se suma a una lista con CHECK (un tipo, un estado) es una migración,
aunque no se agregue ninguna columna. Antes nada lo verificaba; desde la v6.16 lo verifica el
health.
