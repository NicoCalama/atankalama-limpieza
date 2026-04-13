# CLAUDE.md — Instrucciones para Claude Code

Este documento contiene las reglas y convenciones para trabajar en el proyecto **Atankalama Aplicación Limpieza**. Léelo completo antes de codificar cualquier cosa.

## Sobre el proyecto

Aplicación web mobile-first en PHP 8.2 + SQLite para gestionar limpieza hotelera en las dos propiedades de Atankalama Corp (Calama, Chile). Reemplaza Flexkeeping. Integra con Cloudbeds API y con Claude API (copilot conversacional). Ver `plan.md` para el contexto completo.

## Documentación obligatoria a consultar

Antes de tocar cualquier módulo, lee:

1. `plan.md` — esqueleto general del proyecto (v3.0)
2. `docs/<modulo>.md` — especificación detallada del módulo en el que vas a trabajar
3. `docs/database-schema.sql` — estructura de la base de datos
4. `docs/api-endpoints.md` — todos los endpoints REST
5. Las skills en `skills/` según corresponda (cloudbeds-api, php-conventions, ui-components)

## Modo de trabajo

Este proyecto opera en **autonomía híbrida**:

- **Autonomía total** para módulos backend mecánicos: schema, modelos, CRUD, RBAC dinámico, integración Cloudbeds, middleware, alertas predictivas, tests. Codifica módulos completos, commitea, sigue.
- **Supervisión por módulo** para UI: Login, Home (4 versiones por rol), checklist, copilot IA, ajustes (matriz RBAC), auditoría, alertas predictivas. Propone archivos, espera aprobación antes de aceptar.

Cuando estés en modo autonomía total y debas tomar una decisión que no esté especificada en `docs/`, sigue los **Defaults razonables** (más abajo) y deja un comentario `// DECISIÓN AUTÓNOMA: <descripción>` en el código para revisión posterior.

## Stack obligatorio

- **PHP 8.2** — usa features modernos: typed properties, readonly, enums, match expressions, named arguments
- **SQLite** vía PDO — nada de ORMs externos en el MVP
- **Tailwind CSS via CDN** — sin build step, sin npm para el frontend
- **Alpine.js via CDN** — para interactividad puntual (checkboxes, modales, FAB, modo día/noche)
- **PHP nativo como motor de plantillas** — sin Blade ni Twig en el MVP
- **Lucide icons via CDN** — iconografía minimalista
- **Google Fonts (Inter) via CDN** — tipografía
- **Composer** para dependencias PHP (mínimas: solo lo esencial)

## Frontend — decisiones clave

- **NO uses** frameworks frontend pesados (React, Vue, jQuery)
- **NO uses** build tools (Webpack, Vite, PostCSS)
- **NO compiles Tailwind** — viene por CDN, todas las clases están disponibles
- **SÍ puedes** usar Alpine.js directivas (`x-data`, `x-show`, `x-on`, etc.) libremente
- **Mobile-first siempre:** escribe los estilos base para 375px y agrega `sm:`, `md:`, `lg:` para escalar
- **Botones mínimo 44px de alto:** `min-h-[44px]` o equivalente
- **Bottom tab bar en móvil**, sidebar en desktop (`md:` y arriba)
- **FAB del copilot IA** siempre visible — `fixed bottom-20 right-4` o similar
- **Modo día/noche** con clase `dark:` de Tailwind, persistido en localStorage

## Arquitectura clave — RBAC Dinámico

Este proyecto usa **Role-Based Access Control dinámico**, no hardcodeado. Reglas:

1. **NUNCA** chequees roles directamente en el código:
   ```php
   // ❌ NUNCA
   if ($usuario->rol === 'admin') { ... }
   ```

2. **SIEMPRE** chequea permisos específicos:
   ```php
   // ✅ SIEMPRE
   if ($usuario->tienePermiso('habitaciones.asignar')) { ... }
   ```

3. El catálogo de permisos vive en `database/seeds/permisos.php`. Cada vez que agregues una feature nueva, agrega los permisos que necesita a ese archivo.

4. La asignación de permisos a roles se hace vía la tabla `rol_permisos` y se puede editar desde la UI (Ajustes → Roles y Permisos) sin tocar código.

5. El middleware `PermissionCheck` debe aplicarse en cada endpoint que requiera permisos. Nunca confíes solo en chequeos del frontend.

Ver `docs/roles-permisos.md` para el detalle completo.

## Arquitectura clave — Auditoría con 3 estados

La auditoría NO es binaria (aprobado/rechazado). Tiene **3 estados**:

1. **`aprobado`** — todo bien, habitación queda "Clean" en Cloudbeds
2. **`aprobado_con_observacion`** — auditor encontró algo menor, lo resolvió, pero deja constancia. Habitación queda "Clean" en Cloudbeds. Se guardan qué items específicos del checklist fueron desmarcados por el auditor. Afecta KPIs a nivel de ítem, no de habitación. El trabajador NO ve esto como rechazo en su historial.
3. **`rechazado`** — habitación necesita re-limpieza. Vuelve a "Dirty" en Cloudbeds. Supervisora es notificada y decide a quién reasignar.

Ver `docs/auditoria.md` para el flujo completo.

**Inmutabilidad post-auditoría (NO NEGOCIABLE):**

Una vez una habitación recibe veredicto de auditoría (cualquiera de los 3 estados), **NO puede ser re-auditada**. En la UI:
- Aparece en las listas de auditoría como solo lectura
- Visualmente diferenciada: opacidad reducida, badge "Auditada"
- Sin botones de acción (no muestra los 3 botones)
- Tap → muestra detalle histórico (auditor, fecha, comentario, ítems desmarcados si aplica)

Backend: el endpoint `POST /api/auditoria/{habitacion_id}` debe rechazar con error 409 (Conflict) si la habitación ya tiene un registro en `auditorias` para esa ejecución.

## Arquitectura clave — Persistencia del checklist

El progreso del trabajador en un checklist se guarda **a cada tap**, no al final:

- Cada vez que el trabajador marca o desmarca un item del checklist, dispara un PUT/POST inmediato al backend
- Si no hay internet, el cambio queda en una cola local (localStorage/IndexedDB) y se sincroniza cuando vuelve la conexión
- Si la app se cierra, al reabrir la habitación aparece como "Continuar" con todos los checks previamente marcados
- El botón "Habitación terminada" se desbloquea solo cuando todos los items están marcados

Ver `docs/checklist.md` para el detalle.

## Arquitectura clave — Tracking de tiempo oculto

Cada habitación registra `timestamp_inicio` y `timestamp_fin` en `ejecuciones_checklist`. El trabajador **NUNCA** debe ver estos valores en su pantalla. Son exclusivamente para:
- Cálculo del tiempo promedio personal del trabajador (input de alertas predictivas)
- Reportes, KPIs y analítica (versión básica en MVP, completa en Fase 2)

## Arquitectura clave — Alertas predictivas

El sistema calcula en tiempo real si cada trabajador va a alcanzar a terminar su turno. Ver `docs/alertas-predictivas.md` para el algoritmo y umbrales. Reglas:

- La alerta es **solo visible para supervisoras con permiso `alertas.recibir_predictivas`**
- El trabajador **NUNCA** ve la alerta ni sabe de su existencia
- El umbral de margen de seguridad (default 15 min) es configurable desde Ajustes por roles con permiso `alertas.configurar_umbrales`

**Tipos de alertas y prioridades (definidos en `docs/home-supervisora.md`):**

El sistema maneja 6 tipos de alertas con prioridades 0-3:

- P0: `cloudbeds_sync_failed`
- P1: `trabajador_en_riesgo`, `habitacion_rechazada`, `fin_turno_pendientes`
- P2: `trabajador_disponible`, `ticket_nuevo`
- P3: (reservado para casos futuros)

Cada tipo de alerta tiene:
- Un título claro y accionable
- Una descripción con datos concretos
- Máximo 2 botones de acción
- NO tiene botón "descartar" (las alertas persisten hasta resolverse o hasta que la condición desaparezca)

Las acciones sobre alertas se registran en la tabla `bitacora_alertas`.

## Convenciones de código PHP

- **Namespace raíz:** `Atankalama\Limpieza`
- **PSR-4 autoloading** vía Composer
- **PSR-12** para estilo de código
- **Strict types** en cada archivo: `declare(strict_types=1);`
- **Tipado estricto** en parámetros y retornos siempre
- **`final class`** por defecto, salvo herencia justificada
- **`readonly`** en propiedades inyectadas por constructor
- **Nombres en español** para conceptos del dominio (`Habitacion`, `Asignacion`, `Trabajador`, `Auditoria`) y en inglés para conceptos técnicos (`Controller`, `Service`, `Repository`, `Middleware`)
- **Comentarios y mensajes de error en español** — la app es 100% español chileno
- **Prepared statements con PDO** siempre — nunca concatenación SQL
- **Errores con excepciones**, no con `return false`

### Respuestas JSON estandarizadas

Éxito:
```json
{ "ok": true, "data": { ... } }
```

Error:
```json
{ "ok": false, "error": { "codigo": "CODIGO_ERROR", "mensaje": "Descripción amigable" } }
```

Ver la skill `php-conventions` para más detalle.

## Reglas de seguridad (NO NEGOCIABLES)

1. **Nunca hardcodear credenciales.** Todas las API keys, passwords, secrets vienen de `.env` vía `getenv()` o `$_ENV`.
2. **Nunca commitear `.env`.** Solo `.env.example` con placeholders.
3. **Nunca pegar valores reales** de API keys en ejemplos de código, comentarios, ni documentación.
4. **Contraseñas siempre hasheadas** con `password_hash($pwd, PASSWORD_BCRYPT)`.
5. **Validación de permisos en backend** con el middleware `PermissionCheck` antes de cada acción sensible. Nunca confíes solo en el frontend.
6. **El copilot IA valida permisos del rol** antes de ejecutar cualquier tool.
7. **Toda escritura a Cloudbeds queda en logs** con timestamp, payload y respuesta.
8. **Sanitiza todo input del usuario** (XSS vía `htmlspecialchars()`, SQL injection vía PDO prepared statements).
9. **Antes de cada commit**, gitleaks está configurado como hook pre-commit. Si te bloquea, NO uses `--no-verify` — revisa qué activó la alerta.
10. **Nunca loggear tokens, API keys, passwords ni headers Authorization**, ni siquiera en errores.

## Defaults razonables (cuando algo no está especificado)

- **Mensajes de error al usuario:** tono amable, en español, sin jerga técnica. Ejemplo: "No pudimos guardar tu cambio, intenta de nuevo en un momento."
- **Estados de carga:** spinner azul centrado con texto "Cargando..." debajo
- **Estado vacío:** ilustración o icono grande + texto explicativo + acción primaria si aplica
- **Confirmaciones:** para acciones destructivas (borrar, rechazar), modal con dos botones — primario rojo y secundario gris "Cancelar"
- **Timeouts de red:** 10 segundos para Cloudbeds, 30 segundos para Claude API
- **Reintentos:** 3 reintentos con backoff exponencial (1s, 2s, 4s) para llamadas externas
- **Logs:** `INFO` para acciones normales, `WARNING` para validaciones fallidas, `ERROR` para fallos de integración
- **Fechas:** formato chileno DD/MM/YYYY en UI, ISO 8601 en BD y JSON
- **Hora:** zona horaria `America/Santiago`
- **Idioma:** español chileno. Textos cortos, claros, sin formalismos excesivos pero respetuosos.

Cuando uses un default razonable, deja un comentario:
```php
// DECISIÓN AUTÓNOMA: usé spinner azul porque docs/checklist.md no especifica estado de carga
```

## Comandos útiles del proyecto

```powershell
# Servidor local de desarrollo
php -S localhost:8000 -t public/

# Crear/recrear la base de datos desde migraciones + seeds
php scripts/init-db.php

# Cargar datos de demo (para mostrar el MVP)
php scripts/seed-demo-data.php

# Sincronizar manualmente con Cloudbeds (normalmente es cron)
php scripts/sync-cloudbeds.php

# Rescate de emergencia: resetear password del admin
php scripts/reset-admin-password.php

# Correr tests
./vendor/bin/phpunit tests/
```

## Convención de commits

Mensajes en español, descriptivos, en presente. Formato:

```
<tipo>: <descripción corta>

<descripción larga opcional>
```

Tipos: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `style`

Ejemplos:
- `feat: implementar sistema RBAC dinámico con catálogo de permisos`
- `feat: autenticación con RUT y middleware de permisos`
- `feat: endpoint POST /api/habitaciones/{id}/completar con escritura a Cloudbeds`
- `fix: corregir validación del dígito verificador del RUT`
- `docs: documentar flujo de auditoría con 3 estados`

Un commit por módulo o sub-módulo lógico terminado. NO commitees código a medias.

## Testing

Para los módulos en autonomía total, escribe tests unitarios mínimos:
- Validación de RUT (incluye casos con K)
- Hash y verificación de contraseñas
- Generación de contraseñas temporales (sin caracteres ambiguos)
- Lógica de asignación round-robin
- Algoritmo de alertas predictivas (con casos edge: trabajador nuevo, sin histórico, margen exacto)
- Sistema de permisos dinámicos (`tienePermiso()`)
- Cliente Cloudbeds (con respuestas mockeadas)

Los módulos UI (con supervisión) no requieren tests automatizados en el MVP.

## Cuando termines un módulo

1. Asegúrate de que el código corre sin errores (`php -S localhost:8000 -t public/`)
2. Si es módulo backend: corre los tests
3. Haz commit con mensaje descriptivo
4. Si encontraste decisiones autónomas significativas, menciónalas en el cuerpo del commit
5. Continúa con el siguiente módulo del orden definido en `claude-code-setup.md` sección 10

## Cuando algo no funciona

- Si una API externa falla (Cloudbeds, Claude), NO inventes el comportamiento — registra el error en logs y propaga un error claro al usuario
- Si una decisión es ambigua, prefiere preguntar a Nicolás antes que asumir
- Si encuentras un bug en código previo (incluso si lo escribiste tú), arréglalo y menciónalo en el commit
