# Nocheros: la chapa Día / Noche / Día-Noche y la hora de la tarde

**Versión:** v7.23 — 10/11/2026 (reunión del 08/11/2026). El código citaba este documento desde la v6.x pero no
existía: acá queda lo que hace hoy.

Atankalama recibe huéspedes de turno (hotel minero). Una pieza puede necesitar un aseo en la mañana (lo normal), uno
en la tarde (el huésped duerme de día) o dos (dos huéspedes, uno de cada turno). Eso es la **chapa** de la pieza.

## 1. Las tres chapas

| Chapa | En la base | Qué significa | Qué hace la app |
|---|---|---|---|
| **Día** | `es_nochero = 0` | Hotel normal: un aseo en la mañana. Es lo de siempre, sin marca. | Nada especial. |
| **Noche** | `es_nochero = 1`, `nochero_modo = 'noche'` | Huésped de turno noche: un aseo, en la tarde. | Se ve la chapa. **No** entra al barrido: queda sucia por el «sucio» diario de Cloudbeds, como una pieza normal. Desde la v7.25 no se podrá asignar antes de la hora de la tarde. |
| **Día/Noche** | `es_nochero = 1`, `nochero_modo = 'dia_noche'` | Huéspedes de turno día y noche: dos aseos. | El barrido de la hora de la tarde la vuelve a sucia (una vez al día) si ya quedó aprobada, y le avisa `dirty` a Cloudbeds. Es lo que era «nochero» hasta la v7.22. |

Toda chapa Noche o Día/Noche tiene vigencia (`nochero_hasta`, último día, desde hoy en adelante, sin mínimo). Al
vencer vuelve a **Día**. Las marcas de antes de la v7.23 quedaron como Día/Noche (el valor por defecto de la columna).

## 2. Dónde se ve y quién la cambia

- **Habitaciones:** la chapa en cada tarjeta (antes, el botón «N»). Al tocarla se abre la ventana con las tres
  opciones; Noche y Día/Noche piden «hasta cuándo» (atajos de 4, 7 y 10 días). Elegir Día quita la marca. «Quitar
  todas» vuelve a Día todas las que se ven con los filtros. Se cambia con `habitaciones.marcar_nochero`
  (Supervisora, Recepción y Admin); hasta la v7.22 el botón salía a todo el que tenía `habitaciones.ver_todas`.
  Quien no puede cambiarla la ve igual, sin poder tocarla.
- **La chapa se ve siempre, también la de Día** (pedido de Nicolás, 10/11/2026): Día en celeste, Noche en índigo,
  Día/Noche en ámbar. Las áreas comunes no llevan chapa.
- **Asignaciones:** «D», «N» o «D/N» junto al número, en el pool y en las colas. Para un día futuro, una marca que
  vence antes cuenta como Día. Reemplaza el punto amarillo.
- **Inspección:** etiqueta «Día», «Noche» o «Día/Noche» (antes solo «Nochero»). Las piezas Noche y Día/Noche siguen
  saliendo primero en el orden por prioridad.
- **Reportes y el correo de inspecciones pendientes:** la columna «Nochero» sigue diciendo «Sí» para cualquier chapa
  puesta (sin cambios en la v7.23).
- El campo `chapa` («dia», «noche» o «dia_noche») viaja ya resuelto en `/api/habitaciones`, en la vista de
  Asignaciones (pool y colas), en la bandeja de Inspección y en `Habitacion::toArray()`
  (`HabitacionService::chapaDeFila()`).

## 3. La hora de la tarde

Antes era 16:00 fijo (`HabitacionService::HORA_BARRIDO_NOCHEROS`). Desde la v7.23 se cambia en **Ajustes → Hora de la
tarde** (`HoraTardeService`, clave `hora_tarde` en `alertas_config`; sin fila, 16:00). Va de 12:00 a 20:00 en
cuartos de hora. Solo la cambia el Admin (`habitaciones.configurar_hora_tarde`, decisión de Nicolás del 10/11/2026).

La usan:
- el barrido del cron (`scripts/sync-cloudbeds.php`, cada 10 min) desde esa hora;
- el barrido inmediato al marcar una pieza Día/Noche ya aprobada después de esa hora (v6.17);
- desde la v7.24 y la v7.25: el checklist premarcado y desde cuándo se pueden asignar las piezas Noche.

**No** la usan el Registro de días pasados (corte fijo a las 16:00 por la hora de inicio) ni Reportes (sus propios
cortes): así, mover la hora no cambia cómo se ve el pasado. El cierre de día de las 15:50 tampoco cambia.

## 4. El cron, cada pasada

1. **Marcas vencidas** (`nochero_hasta` antes de hoy) → vuelven a Día, a cualquier hora. Hasta la v7.22 esto pasaba
   recién a las 16:00 y una marca vencida seguía puesta toda la mañana.
2. **Desde la hora de la tarde:** barrido de las Día/Noche vigentes que ya quedaron aprobadas (`aprobada`,
   `aprobada_con_observacion`, `aprobada_automatica`) y que no se barrieron hoy (`nochero_ultima_reversion`). Una
   rechazada espera a que la rehagan y la aprueben (R4, v6.17). No se interrumpe una limpieza en curso ni una
   inspección pendiente.
3. **Desde las 08:00:** aviso de las marcas que vencen hoy a quien puede marcarlas, una vez por persona y día
   («Desde mañana vuelven a Día»).

## 5. API

- `PUT /api/habitaciones/{id}/nochero` `{hasta, modo?}` — `modo` = `noche` o `dia_noche` (sin modo, Día/Noche). 400
  `MODO_INVALIDO`, `FECHA_INVALIDA`, `NOCHERO_FECHA_PASADA`. Permiso `habitaciones.marcar_nochero`.
- `DELETE /api/habitaciones/{id}/nochero` — vuelve a Día. Mismo permiso.
- `GET` / `PUT /api/nocheros/hora-tarde` `{hora: 'HH:MM'}` — la hora de la tarde. 400 `HORA_INVALIDA`. Permiso
  `habitaciones.configurar_hora_tarde`.

## 6. Lo que viene

- **v7.24:** checklist premarcado en piezas con chapa, desde la hora de la tarde.
- **v7.25:** pestañas Día | Noche en Asignaciones; las Noche no se asignan antes de la hora.
- **v7.26:** dos tarjetas por pieza y día en las Día/Noche (una persona en cada ventana).
