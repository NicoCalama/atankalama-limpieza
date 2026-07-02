# Ocupación y cambio de sábanas (Gaps A y B)

**Versión:** 1.0 — 2026-07-02
**Estado:** Implementado y verificado (2026-07-02) — suite 247+/247+, sync real + UI verificados, revisión independiente sin bloqueantes. Pendiente prod: correr `scripts/migrate-add-ocupacion-sabanas.php` (incluye backfill del ítem de sábanas).

Documenta dos features del relevamiento de Rodrigo que salen del **mismo** endpoint de Cloudbeds:

- **A — Ocupación:** saber por pieza si el huésped **llega hoy / sigue / se va hoy** (no solo `clean/dirty`).
- **B — Cambio de sábanas cada N días:** avisar cuándo toca cambiar sábanas a un huésped que **sigue** (stayover), aunque no se vaya. Rodrigo: *"cada 4 días (configurable) avisa que deben cambiarse las sábanas aunque la persona continúe"*.

---

## 1. Hallazgo clave (verificado en la API v1.1 real, 02/07/2026)

**Ambos features salen del endpoint que YA llamamos** (`getHousekeepingStatus`) — hoy tiramos los
campos ricos. Cada fila trae (ver `docs/cloudbeds.md` §3.1):

- **`frontdeskStatus`** ∈ `check-in` / `check-out` / `stayover` / `turnover` / `unused` → **Gap A**.
- **`arrivalDate`** (entrada del huésped actual) → *noches de estadía = hoy − arrivalDate* → **Gap B**.
- `roomOccupied`, `departureDate`, flags.

**No hace falta llamar a `getReservations`.** Las "reglas de cada hotel" (cadencia de sábanas) no se
exponen por la API: Cloudbeds da el estado, la **regla de N días la implementamos nosotros** (configurable).

---

## 2. Parte A — Ocupación

### 2.1 Datos
Columnas nuevas en `habitaciones` (nullable, pobladas por el sync; son contexto de Cloudbeds, no
cambian nuestro `estado` de limpieza):

- `cb_frontdesk_status` TEXT — `check-in`/`check-out`/`stayover`/`turnover`/`unused`
- `cb_ocupada` INTEGER (0/1)
- `cb_arrival_date` TEXT (`YYYY-MM-DD`)
- `cb_departure_date` TEXT (`YYYY-MM-DD`)
- `cb_ocupacion_sync_at` TEXT (cuándo se refrescó)

(Prefijo `cb_` = dato de Cloudbeds. Alternativa considerada: tabla 1:1 aparte; se elige columnas por
simplicidad — es 1:1 y evita JOINs en los listados.) + migración portable idempotente.

### 2.2 Sync
`CloudbedsSyncService::sincronizar` ya baja `getHousekeepingStatus`. Se extiende para, por cada pieza
matcheada, **también** guardar los 5 campos de arriba. La lógica actual `clean/dirty → estado` queda
intacta. Los espacios (áreas comunes, sin `cloudbeds_room_id`) no se tocan.

### 2.3 UI
Badge de ocupación por pieza (**"Se va hoy" / "Sigue" / "Llega hoy" / "Día/noche"**) en el listado de
habitaciones, para que el coordinador priorice (checkouts y turnovers primero). `unused` (libre) no
lleva badge para reducir ruido visual. Es una dimensión **separada** de nuestro `estado` de limpieza
(sucia/limpia…): se muestran las dos. Los datos también viajan en la cola del trabajador (badge
"Sábanas"); extender los badges de ocupación a la vista de Asignaciones queda como mejora futura.

### 2.4 Dependencia
La ocupación es tan fresca como el sync (hoy **2×/día**). Para que sea útil en el día dinámico conviene
subir la cadencia → es el **Gap C** (separado, pero este feature lo hace más valioso).

---

## 3. Parte B — Cambio de sábanas cada N días

### 3.1 Regla (MVP: cadencia desde la llegada)
Para una pieza en `stayover`:

```
noches       = hoy − cb_arrival_date
toca_sabanas = (noches > 0) AND (noches % N == 0)      -- avisa los días N, 2N, 3N…
```

- **N configurable por hotel** (`hoteles.sabanas_cada_n_dias`, default **4**, por Rodrigo).
- Es literal a "cada N días avisa". **No rastrea el cambio real** (arrival-based): no toca la
  obligatoriedad del checklist ni, por lo tanto, el conteo de créditos.
- Solo aplica a `stayover`. En check-in/check-out/turnover el aseo ya trae sábanas frescas.

**Trade-off aceptado (MVP):** si el aviso de un día se pasa por alto, no reaparece hasta el siguiente
múltiplo de N. Un modelo más preciso (rastrear el último cambio real + ítem de sábanas opcional que se
marca solo cuando toca) es una **mejora futura** — pero ese cambio vuelve opcional un ítem hoy
obligatorio, lo que ripplearía en el KPI de créditos (conteo de obligatorios); por eso queda fuera del MVP.

### 3.2 Datos
- Config **`hoteles.sabanas_cada_n_dias`** (default `4`) — editable por hotel desde Ajustes.
- **`items_checklist.es_cambio_sabanas`** (0/1) — solo para **etiquetar** en la UI cuál es el ítem de
  sábanas (no se usa para rastrear el cambio en el MVP).

### 3.3 Surfacing
- Badge **"Cambio de sábanas / Sábanas hoy"** en la pieza (coordinador, listado de habitaciones) y en
  la cola/home del trabajador cuando `toca_sabanas`.
- En el checklist del trabajador, el ítem de sábanas lleva una etiqueta "Sábanas" (informativa).

---

## 4. Decisiones de producto (resueltas en el MVP)

- **A — dónde guardar la ocupación:** columnas `cb_*` en `habitaciones` (1:1, sin JOINs). ✅
- **B — alcance de N:** **por hotel** (`hoteles.sabanas_cada_n_dias`, default 4). ✅
- **B — cómo se calcula:** **cadencia desde la llegada** (`noches % N == 0`), arrival-based, sin
  rastrear el cambio real → no toca la obligatoriedad del checklist ni el KPI de créditos (ver §3.1). ✅
- **B — cómo se avisa:** **badge** en habitaciones + cola/home del trabajador (sin alerta nueva). ✅
- **Pendiente de decisión futura:** modelo preciso de sábanas (rastrear último cambio + ítem opcional),
  que ripplearía en créditos; y si conviene una alerta P2 a la supervisora.

---

## 5. Fuera de alcance (MVP)

- Leer `getReservations` (no hace falta: todo sale de `getHousekeepingStatus`).
- Escribir el housekeeper/asignación a Cloudbeds.
- Subir la cadencia del sync (**Gap C**, feature aparte — pero recomendado junto con esto).
- Disparar automáticamente la 2ª limpieza en `turnover` (**Gap F automático**, feature aparte).

---

## 6. Plan de fases

- **Fase 1 — Datos:** columnas `cb_*` en `habitaciones`, `es_cambio_sabanas` en `items_checklist`,
  `sabanas_cada_n_dias` en `hoteles`; ambos schemas + migración portable.
- **Fase 2 — Sync (A):** `CloudbedsSyncService` guarda la ocupación por pieza; test con respuesta mockeada.
- **Fase 3 — Regla de sábanas (B):** `SabanasService` calcula `toca_sabanas`/`noches_estadia` (cadencia).
- **Fase 4 — UI:** badges de ocupación + "sábanas hoy" en habitaciones y en la cola/home del trabajador;
  etiqueta "Sábanas" en el ítem del checklist.
- **Fase 5 — Tests + verificación:** unidad de la regla (sin arrival, N exacto, no-stayover) + sync que
  guarda ocupación; verificación por UI con datos reales.

---

## 7. Referencias cruzadas

- [cloudbeds.md](cloudbeds.md) §3.1 — campos de `getHousekeepingStatus` (verificados)
- [limpiezas-multiples-dia.md](limpiezas-multiples-dia.md) — Gap F (turnover como disparador automático)
- [habitaciones.md](habitaciones.md) — estados de limpieza (dimensión separada de la ocupación)
- [database-schema.sql](database-schema.sql) / [database-schema.mariadb.sql](database-schema.mariadb.sql)
