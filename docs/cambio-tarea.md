# Cambio Tarea — horas de Aseo en otra área (v7.16)

**Origen:** pedido de gerencia del 06/11/2026; plan aprobado por Nicolás el 07/11/2026.

En noviembre hubo mucha demanda y bastante gente de Aseo fue a apoyar a otras áreas (Recepción, Lavandería…).
Esos días hizo menos piezas, le bajaron las piezas por día y con eso el bono, que es justo lo que no tiene que pasar.
«Cambio Tarea» es la pantalla donde la supervisora anota, **el mismo día**, cuántas horas estuvo cada persona de Aseo en
otra área.

La v7.16 **solo guarda el dato**: el bono todavía no cambia. El cálculo nuevo llega en la v7.17 «KPIs por hora» (§6).

---

## 1. Quién y dónde

- **Permiso `cambio_tarea.registrar`.** Por defecto lo tienen la Supervisora y el Admin: la migración se lo da a todo
  rol con `asignaciones.asignar_manual`.
- **Ruta `/cambio-tarea`.** Se entra por tres lugares:
  - en el computador, desde el menú lateral, entre «Asignaciones» y «Áreas comunes»;
  - en el celular, desde la tarjeta «Cambio Tarea» del Inicio de la supervisora, porque la barra de abajo ya no tiene
    espacio;
  - desde el botón con el ícono de flechas cruzadas en el encabezado de Asignaciones.

## 2. Quién sale en la lista

El mismo personal de la pestaña «Aseo» de Asignaciones (`PersonalAsignableService`, grupo `aseo`):

- **quién sale:** sin la marca `asignaciones.excluir_auto`, con turno ese día o con piezas asignadas ese día;
- **quién no sale:** quien tiene `kpis.excluido`, porque no entra al bono (Apoyo y otras áreas);
- **filtro de hotel:** igual que Asignaciones, por `hotel_default`. Quien está en «ambos» sale siempre;
- **sin que se esconda nada:** quien ya tiene horas anotadas ese día también sale, aunque después le hayan borrado el
  turno.

Cada fila muestra solo el nombre, a la derecha lo anotado en otras áreas («Sin novedades» si nada) y el aviso «Sin
turno» si corresponde; el turno y las piezas no se muestran (decisión de Nicolás, 08/11/2026). La ficha se abre al
tocar la fila.

La API igual devuelve el turno (con sus minutos netos), las piezas hechas de las asignadas y las áreas comunes hechas;
la pantalla hoy no los muestra.

El resumen de arriba dice solo cuántas personas de Aseo hay ese día.

Arriba de los botones de hotel hay un **buscador por nombre** (Nicolás, 08/11/2026). Filtra la lista mientras se
escribe, sin importar tildes ni mayúsculas, y cada palabra escrita tiene que estar en el nombre: «ana roj» encuentra a
Ana Rojas y «munoz» a Muñoz. Si nadie coincide, lo dice. Funciona solo en el navegador, sin llamar a la API.

## 3. La ficha

- **Una fila por área:** el área, que sale de la lista «Áreas de Cambio Tarea» de Ajustes → Checklists y listas, y las
  horas, de media en media hora entre 0,5 y 12 h.
  - El botón «+ Otra área» agrega una fila y el basurero la quita.
  - Una persona puede tener hasta 6 áreas por día, sin repetir, y en total no más de 16 h.
- **Se guarda solo** a cada cambio (`PUT /api/cambio-tarea/{usuario_id}`). Se manda el juego completo de la persona y lo
  que no viene se borra. La ficha muestra «Guardando...», «✓ Guardado» o «No se guardó».
- **Sin cuentas de horas en Aseo:** la ficha no muestra cuántas horas le quedan en Aseo (decisión de Nicolás,
  08/11/2026). La supervisora solo anota las horas en otras áreas; las extras las carga el Admin en otro lugar (v7.17).
  - La cuenta la hace la v7.17: el turno de jornada completa descuenta 30 min de colación no pagada, así que 08–16
    son 7,5 h (`HorasTurno`); la jornada parcial cuenta el turno entero.
- **Sin turno:** quien trabajó sin turno cargado sale con la etiqueta «Sin turno».
  - Si quien mira tiene `turnos.asignar_a_usuario`, en la ficha elige qué turno hizo y toca «Cargar turno». Usa el
    mismo `POST /api/usuarios/{id}/turno` de Ajustes → Turnos.
  - Si no tiene el permiso, la ficha le dice que se lo pida al Admin.
- **Las horas pueden pasar del turno.** Si alguien se quedó después de hora apoyando en otra área, también se anota. En
  la v7.17 el Admin suma esas horas como extras del mes y se compensan.

## 4. Solo el mismo día

Decisión de Nicolás del 07/11/2026: «que las personas se vuelvan menos procrastinadoras».

- **El servidor lo exige:** guardar una fecha que no es hoy (hora de Chile, `APP_TIMEZONE`) responde **409
  `SOLO_MISMO_DIA`**. Si la pantalla quedó abierta desde ayer, avisa y se recarga en el día de hoy.
- **Ver otros días está programado pero apagado.** Con `CAMBIO_TAREA_NAVEGAR_DIAS=true` en el `.env` aparecen flechas
  para ver días pasados, **solo lectura**. Nunca se ven días futuros. Apagado, `GET /api/cambio-tarea` con otra fecha
  responde 400 `SOLO_HOY`.
- **Del 1 al 7 de noviembre no se cargan.** Esos días se pierden, por decisión de Nicolás.

## 5. Datos

- **Tabla `horas_otras_areas`** (`limpieza_horas_otras_areas` en producción). Lleva `usuario_id`, `fecha`, `area_id`
  (FK a `listas_opciones`) y `minutos` (de 30 en 30, hasta 720), más `registrado_por`, `created_at` y `updated_at`.
  - Hay una fila por persona, día y área: `UNIQUE (usuario_id, fecha, area_id)`.
  - Las horas van en minutos enteros para que las cuentas den exactas en SQLite y en MariaDB.
- **Lista nueva `area_apoyo`** en `listas_opciones`. La semilla trae Recepción, Lavandería, Mantención, Cocina y Bodega.
  - «Áreas comunes» no va: es trabajo de Aseo y no se descuenta (Nicolás, 07/11/2026).
  - Una opción desactivada sale de la lista para elegir, pero sigue valiendo donde ya estaba anotada.
- **Registro de auditoría:** cada cambio queda en `audit_log` (`cambio_tarea.guardar`) con las horas de antes y de
  después por área.
- **Las horas extras no se anotan aquí.** Las carga el Admin como total del mes, en la v7.17.

## 6. Lo que viene en la v7.17 «KPIs por hora» (aprobado, sin construir)

- **Horas en Aseo del mes** = horas de turno de los días trabajados + extras del Admin − horas en otras áreas.
  - Cuentan los días con piezas, con áreas comunes o con horas anotadas.
  - En jornada completa, el turno descuenta la colación.
  - Si alguien trabajó sin turno, se usa el promedio de sus turnos del mes.
- **Bono y Productividad pasan de hab./día a hab./hora.** El corte se escribe directo por hora y la base es la misma
  para jornada completa y parcial.
- **Las extras valen como horas normales.** Quedan con la fecha en que se agregan y suman al período que la contiene,
  sin prorrateo.

Detalle en la memoria del proyecto y en [kpis-sueldos.md](kpis-sueldos.md).

## 7. API

| Método | Endpoint | Permiso | Qué hace |
|---|---|---|---|
| GET | `/api/cambio-tarea?hotel=&fecha=` | `cambio_tarea.registrar` | Lista el personal de Aseo del día con su turno, sus piezas y sus horas en otras áreas, más las áreas activas y los turnos (si puede cargarlos). Sin el interruptor, solo hoy |
| PUT | `/api/cambio-tarea/{usuario_id}` | `cambio_tarea.registrar` | Body `{fecha, areas: [{area_id, minutos}]}`, con el juego completo de ese día. Responde 409 si la fecha no es hoy |

Errores: `SOLO_MISMO_DIA` (409), `SOLO_HOY` (400), `NO_ES_DE_ASEO`, `AREA_INVALIDA`, `AREA_REPETIDA`, `AREA_REQUERIDA`,
`HORAS_INVALIDAS` y `DEMASIADAS_AREAS` (400), `PERSONA_NO_ENCONTRADA` (404).
