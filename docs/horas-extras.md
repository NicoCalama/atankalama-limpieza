# Horas extras — total del mes por persona de Aseo (v7.17)

**Origen:** pedido de gerencia del 06/11/2026. Las decisiones de Nicolás son del 07/11/2026 (las extras las carga el
Admin como total del mes) y del 08/11/2026 (cuadrícula del año y plazo hasta el 6 del mes siguiente).

Una persona con muchas horas extras tenía las piezas por día «al cielo», porque el corte del bono está pensado para un
día de 7,5 h. Desde la v7.18 «KPIs por hora» las extras se suman a sus horas en Aseo y el bono se mide por hora. Esta
ventana es donde el Admin las carga.

La v7.17 solo guardaba el dato; el bono las usa desde la v7.18 (§5).

---

## 1. Quién y dónde

- **Permiso `horas_extras.registrar`.** Por defecto lo tiene el Admin: la migración se lo da a todo rol que hoy edita
  el corte del bono (`reportes.editar_corte`). La supervisora no lo tiene; ella anota las horas en otras áreas en
  [Cambio Tarea](cambio-tarea.md).
- **Botón «Horas extras»** en Reportes → Mensual, al lado de «Corte hab./día». Solo se dibuja con el permiso.
- Abre una ventana: en el celular ocupa toda la pantalla y en el computador sale centrada.

## 2. La cuadrícula

- **Filas = personas, columnas = los 12 meses del año.** Arriba van las flechas para cambiar de año, desde 2026 hasta
  el año en curso.
- **Una casilla por persona y mes**, con el total de horas extras de ese mes:
  - de media en media hora, hasta 100 h (acepta coma: «7,5»);
  - vacía o 0 = sin extras (se borra la fila);
  - **se guarda sola** al salir de la casilla (`PUT`): borde verde si se guardó y rojo si no, con el motivo abajo. El
    aviso de error queda hasta que se corrige esa misma casilla.
- **Solo se escriben los meses que siguen abiertos** (columna azul con «hasta 06/12»); los demás muestran el número o
  «—», en solo lectura.
- **Buscador por nombre**, igual al de Cambio Tarea: sin tildes ni mayúsculas, cada palabra tiene que estar en el
  nombre.
- En el celular la cuadrícula se desliza de lado con el nombre fijo, y se abre mostrando el mes abierto.

### Quién sale

El personal de Aseo que entra al bono:
- con `habitaciones.ver_asignadas_propias` y sin `asignaciones.excluir_auto` ni `kpis.excluido` (fuera quedan
  Supervisora, Recepción, Apoyo y Admin);
- activo, o inactivo con piezas asignadas en un mes que sigue abierto (quien se fue a fin de mes igual recibe sus
  extras hasta el 6), con la etiqueta «Inactiva»;
- quien ya tiene extras cargadas en ese año sale siempre, aunque después le hayan cambiado el rol, para que nada quede
  escondido.

**Sin filtro de hotel:** las extras son de la persona, no de un hotel.

## 3. El plazo

Decisión de Nicolás del 08/11/2026: «se tiene hasta el 6 de cada mes para rellenar la celda del mes».

- **Cada mes se escribe desde su día 1 hasta el día 6 del mes siguiente inclusive** (hora de Chile, `APP_TIMEZONE`).
  Ejemplo: noviembre se llena durante noviembre y se corrige hasta el 6 de diciembre; desde el 7 de diciembre queda en
  solo lectura **para todos**.
- Del 1 al 6 de cada mes hay dos columnas abiertas: la del mes anterior y la del mes en curso.
- Un mes que todavía no empieza tampoco se escribe.
- **El servidor lo exige:** fuera de plazo responde **409 `FUERA_DE_PLAZO`** («Las horas extras de noviembre de 2026 se
  podían cargar hasta el 6 de diciembre de 2026.» o «Ese mes todavía no empieza.»). Si la ventana quedó abierta
  mientras se cerraba el plazo, avisa y se recarga.
- **Desde noviembre de 2026.** Los meses anteriores responden 409 `ANTES_DE_INICIO` y quedan como se calcularon.
- Si hay que corregir un mes cerrado, se hace a mano en la base y queda fuera de la app (no hay botón para saltarse el
  plazo).

## 4. Datos

- **Tabla `horas_extras`** (`limpieza_horas_extras` en producción): `usuario_id`, `mes` ('YYYY-MM'), `minutos` (de 30
  en 30, hasta 6000), `registrado_por`, `created_at` y `updated_at`.
  - Una fila por persona y mes: `UNIQUE (usuario_id, mes)`. Sin fila = sin extras.
  - Las horas van en minutos enteros para que las cuentas den exactas en SQLite y en MariaDB.
- **Registro de auditoría:** cada cambio queda en `audit_log` (`horas_extras.guardar`) con el mes y los minutos de
  antes y de después.

## 5. Cómo se usan desde la v7.18 «KPIs por hora»

- **Horas en Aseo del mes** = horas de turno de los días trabajados + **extras del mes** − horas en otras áreas
  ([cambio-tarea.md](cambio-tarea.md) §6). Detalle en [kpis-sueldos.md](kpis-sueldos.md), «KPIs por hora».
- **Bono y Productividad se miden por hora**, con el corte escrito directo por hora.
- **Período que no es el mes completo** (decisión de Nicolás, 08/11/2026): las extras se cargan como total del mes,
  así que **solo suman si el período cubre el mes entero**. En los KPIs del 1 al 15 no suman y el indicador dice «sin
  extras del mes». El resumen mensual y el Excel siempre son del mes completo.
- **Con un hotel filtrado** van enteras a la fila de la persona: son suyas y no de un hotel.

## 6. API

| Método | Endpoint | Permiso | Qué hace |
|---|---|---|---|
| GET | `/api/reportes/horas-extras?anio=` | `horas_extras.registrar` | La cuadrícula del año (por defecto el año en curso): `personas` con sus `meses` (`'YYYY-MM' → horas`), `meses_editables`, `mes_inicio`, `dia_plazo` y `hoy` |
| PUT | `/api/reportes/horas-extras/{usuario_id}` | `horas_extras.registrar` | Body `{mes: 'YYYY-MM', horas: número \| null}`. `null`, vacío o 0 lo borra. Responde las horas como quedaron |

Errores: `FUERA_DE_PLAZO` y `ANTES_DE_INICIO` (409), `ANIO_INVALIDO`, `MES_INVALIDO`, `HORAS_INVALIDAS`,
`NO_ES_DE_ASEO` y `PARAMETROS_INVALIDOS` (400), `PERSONA_NO_ENCONTRADA` (404), `SIN_PERMISO` (403).
