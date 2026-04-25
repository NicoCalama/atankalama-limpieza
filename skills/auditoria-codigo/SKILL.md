# Auditoría de Código — Atankalama Limpieza

## Cuándo usar esta skill

Cuando el usuario invoca `/auditoria-codigo`. Ejecuta una auditoría completa del código del proyecto revisando las 7 categorías definidas abajo. Usa Grep y Read activamente sobre los archivos reales — nunca asumas el estado del código sin verificarlo.

## Alcance de la auditoría

Directorios y archivos a revisar:
- `src/` — todo el código PHP (Controllers, Services, Models, Core, Middleware)
- `views/` — todas las vistas PHP/HTML
- `database/seeds/permisos.php` — catálogo de permisos
- `src/Core/Kernel.php` — registro de rutas y middlewares

---

## Procedimiento de ejecución

Sigue estos pasos en orden. Cada sección indica exactamente qué buscar y con qué herramienta.

### Categoría 1 — Convenciones PHP

**1.1 `declare(strict_types=1)`**

Busca todos los archivos PHP en `src/` y verifica que cada uno tenga la declaración en la primera línea efectiva.

```
Grep: pattern="<\?php" en src/ — lista archivos PHP
Grep: pattern="declare\(strict_types=1\)" en src/ — lista los que SÍ lo tienen
```

Compara ambas listas. Archivos PHP sin `declare(strict_types=1)` son ❌.

**1.2 `final class`**

```
Grep: pattern="^class " en src/ — detecta clases sin final
Grep: pattern="^final class " en src/ — clases correctas
Grep: pattern="^abstract class " en src/ — clases abstractas (legítimas, no contar como error)
```

Clases no-abstractas sin `final` son ⚠️ (salvo herencia justificada con comentario).

**1.3 `readonly` en propiedades inyectadas por constructor**

```
Grep: pattern="private \$" en src/ — propiedades sin readonly ni tipado
Grep: pattern="private readonly" en src/ — propiedades correctas
Grep: pattern="public function __construct" en src/ — localiza constructores para revisión manual
```

Propiedades inyectadas por constructor sin `readonly` son ⚠️.

**1.4 Tipado completo**

```
Grep: pattern="public function .+\)" en src/ — funciones públicas
Grep: pattern="public function .+\): " en src/ — funciones con return type declarado
```

Funciones públicas sin return type son ❌. Revisa además parámetros sin tipo en las funciones encontradas.

**1.5 Nombres de dominio en español, técnicos en inglés**

Verifica que las clases de dominio usen nombres en español: `Habitacion`, `Asignacion`, `Trabajador`, `Auditoria`, `Checklist`, `Turno`, `Hotel`.

Verifica que las clases técnicas usen nombres en inglés: `Controller`, `Service`, `Repository`, `Middleware`, `Client`, `Request`, `Response`, `Exception`.

```
Grep: pattern="class [A-Z]" en src/ — lista todas las clases
```

Lee los nombres y detecta mezclas (clase de dominio en inglés, o sufijo técnico en español). Son ⚠️.

---

### Categoría 2 — RBAC: Regla de Oro

**2.1 Chequeo directo de rol (PROHIBIDO)**

```
Grep: pattern="->rol\s*===?" en src/
Grep: pattern="\$usuario->rol" en src/
Grep: pattern="rol\s*==\s*['\"]" en src/
Grep: pattern="getRole\(\)" en src/
```

Cualquier coincidencia es ❌ crítico. Reportar archivo, línea y fragmento exacto.

**2.2 Uso correcto de `tienePermiso()`**

```
Grep: pattern="tienePermiso\(" en src/ — verifica que se use
```

Si hay lógica de acceso condicional en Controllers o Services sin `tienePermiso()`, es ❌.

**2.3 Middleware `PermissionCheck` en rutas sensibles**

Lee `src/Core/Kernel.php` completo. Para cada ruta que involucre escritura (POST, PUT, PATCH, DELETE) o datos sensibles (GET de reportes, auditorías, usuarios), verifica que tenga `'middleware' => [..., 'permission:...']`.

Rutas de escritura sin middleware de permisos son ❌.

**2.4 Catálogo de permisos actualizado**

Lee `database/seeds/permisos.php`. Cruza los permisos registrados ahí contra los permisos que se usan en las llamadas a `tienePermiso()` en `src/`.

```
Grep: pattern="tienePermiso\('[^']+'\)" en src/ — extrae todos los códigos usados
```

Permisos usados en el código pero ausentes del catálogo son ❌.

---

### Categoría 3 — Seguridad Básica

**3.1 SQL injection — prepared statements**

```
Grep: pattern="query\s*\(\s*['\"].*\$" en src/ — concatenación SQL directa
Grep: pattern="\$db->query\s*\(" en src/
Grep: pattern="\"SELECT.*\." en src/
Grep: pattern="'SELECT.*\." en src/
```

Cualquier query con interpolación de variables sin `prepare()` es ❌ crítico.

**3.2 XSS — `htmlspecialchars()` en output HTML**

```
Grep: pattern="echo \$" en views/ — variables echadas directamente
Grep: pattern="<\?= \$" en views/ — shorthand sin escapar
Grep: pattern="htmlspecialchars" en views/ — las que sí escapan
```

Variables de usuario impresas sin `htmlspecialchars()` en vistas son ❌.

**3.3 Credenciales hardcodeadas**

```
Grep: pattern="(password|secret|api_key|token)\s*=\s*['\"][^'\"\$]" en src/ (case insensitive)
Grep: pattern="Bearer [A-Za-z0-9_\-]{20,}" en src/
Grep: pattern="sk-[A-Za-z0-9]{20,}" en src/
```

Cualquier coincidencia es ❌ crítico y debe bloquearse antes de commit.

**3.4 Sanitización de logs**

```
Grep: pattern="LogSanitizer" en src/ — verifica que exista y se use
Grep: pattern="->info\(|->warning\(|->error\(" en src/ — llamadas de log
```

Lee los contextos encontrados. Si algún log incluye `password`, `token`, `Authorization` o `api_key` directamente (sin pasar por `LogSanitizer`), es ❌.

---

### Categoría 4 — Formato de Respuestas JSON

**4.1 Estructura de respuestas de éxito**

```
Grep: pattern="json\(" en src/Controllers/ — respuestas JSON
Grep: pattern="'ok'\s*=>\s*true" en src/ — respuestas de éxito correctas
```

Respuestas JSON que no usen `{ "ok": true, "data": ... }` son ❌.

**4.2 Estructura de respuestas de error**

```
Grep: pattern="'ok'\s*=>\s*false" en src/ — respuestas de error correctas
Grep: pattern="'error'\s*=>\s*\[" en src/
```

Errores que retornen `{ "error": "string" }` plano o sin `codigo` y `mensaje` son ❌.

**4.3 HTTP status codes**

```
Grep: pattern="http_response_code\(" en src/
Grep: pattern="->status\(" en src/
```

Verifica que se usen los códigos correctos:
- 201 para POST de creación exitosa
- 400 para validación fallida
- 401 para no autenticado
- 403 para sin permisos
- 404 para recurso no encontrado
- 409 para conflicto (ej: habitación ya auditada)
- 500 para errores internos

Endpoints que siempre retornan 200 incluso en error son ❌.

---

### Categoría 5 — Arquitectura

**5.1 Separación Controller/Service**

```
Grep: pattern="prepare\(|query\(" en src/Controllers/ — SQL en Controllers
Grep: pattern="echo |print " en src/Services/ — output en Services
```

SQL en Controllers es ❌. Output directo en Services es ❌.

**5.2 Lógica de negocio en Controllers**

Lee los Controllers encontrados. Un Controller debe hacer como máximo: validar el request, llamar al Service, retornar la Response. Si contiene cálculos, reglas de negocio, o transformaciones complejas de datos, es ⚠️.

**5.3 Un Controller por módulo**

```
Grep: pattern="class.*Controller" en src/Controllers/ — lista Controllers
```

Verifica que no haya un único Controller monolítico manejando múltiples módulos sin relación. Es ⚠️ si existe.

**5.4 Manejo de excepciones**

```
Grep: pattern="return false" en src/Services/ — antipatrón
Grep: pattern="return null" en src/Services/ — posiblemente problemático
Grep: pattern="throw new " en src/ — uso correcto de excepciones
```

Services que retornan `false` o `null` para señalizar errores sin excepción son ⚠️.

---

### Categoría 6 — Frontend

**6.1 Mobile-first**

```
Grep: pattern="lg:|xl:" en views/ — breakpoints grandes
Grep: pattern="md:" en views/ — breakpoints medianos
```

Verifica que las clases base (sin prefijo) sean para móvil y que los breakpoints escalen hacia arriba. Si hay bloques de layout que solo usan clases desktop sin base móvil, es ⚠️.

**6.2 Tamaño mínimo de botones**

```
Grep: pattern="<button" en views/ — todos los botones
Grep: pattern="min-h-\[44px\]|min-h-\[48px\]|py-3|py-4" en views/ — altura suficiente
```

Botones sin `min-h-[44px]` ni padding equivalente son ⚠️.

**6.3 Colores hardcodeados fuera de Tailwind**

```
Grep: pattern="style=\".*color" en views/ — colores inline
Grep: pattern="style=\".*background" en views/ — fondos inline
```

Colores hardcodeados en `style=` (excepto anchos dinámicos de barras de progreso) son ⚠️.

**6.4 Componentes Alpine con manejo de errores y estado de carga**

```
Grep: pattern="x-data=" en views/ — localiza componentes Alpine
```

Para cada componente Alpine que haga requests al backend (busca `fetch(` o `axios(` dentro de `x-data`), verifica que tenga:
- Variable de estado de carga (ej: `loading: false`)
- Manejo de error con `try/catch` o `.catch()`
- Mensaje de error visible al usuario (no solo `console.error`)

Componentes Alpine con fetch sin manejo de error son ❌.

---

### Categoría 7 — Deuda Técnica

**7.1 Decisiones autónomas pendientes de revisión**

```
Grep: pattern="DECISIÓN AUTÓNOMA" en src/ — decisiones pendientes de revisar
Grep: pattern="DECISIÓN AUTÓNOMA" en views/ — en vistas
```

Lista todas las encontradas con archivo y línea. No son errores, son ⚠️ para revisión.

**7.2 TODOs y FIXMEs**

```
Grep: pattern="TODO:|FIXME:|HACK:|XXX:" en src/ (case insensitive)
Grep: pattern="TODO:|FIXME:|HACK:|XXX:" en views/ (case insensitive)
```

Lista todos con archivo, línea y texto. Son ⚠️.

**7.3 Código comentado**

```
Grep: pattern="^(\s*)\/\/.*;\s*$" en src/ — líneas comentadas con código PHP
Grep: pattern="\/\*.*\*\/" en src/ — bloques comentados
```

Código comentado (no comentarios explicativos) de más de 5 líneas consecutivas es ⚠️.

**7.4 Imports no usados**

```
Grep: pattern="^use " en src/ — todos los use statements
```

Para cada clase importada con `use`, verifica con Grep si el nombre de la clase aparece en el resto del archivo. Imports cuyo nombre no aparece en el archivo son ⚠️.

---

## Formato de salida del reporte

Presenta el reporte en este formato exacto:

```
## Reporte de Auditoría de Código — Atankalama Limpieza
Fecha: <fecha actual>

---

### 1. Convenciones PHP

✅ <lista de lo que está bien>
⚠️  <advertencias — no crítico pero mejorable>
❌ <problemas que deben corregirse>

Puntuación: X/10

---

### 2. RBAC — Regla de Oro

✅ ...
⚠️  ...
❌ ...

Puntuación: X/10

---

### 3. Seguridad Básica

✅ ...
⚠️  ...
❌ ...

Puntuación: X/10

---

### 4. Formato de Respuestas JSON

✅ ...
⚠️  ...
❌ ...

Puntuación: X/10

---

### 5. Arquitectura

✅ ...
⚠️  ...
❌ ...

Puntuación: X/10

---

### 6. Frontend

✅ ...
⚠️  ...
❌ ...

Puntuación: X/10

---

### 7. Deuda Técnica

✅ ...
⚠️  ...
❌ ...

Puntuación: X/10

---

## Resumen

| Categoría             | Puntuación |
|-----------------------|------------|
| 1. Convenciones PHP   | X/10       |
| 2. RBAC               | X/10       |
| 3. Seguridad          | X/10       |
| 4. JSON               | X/10       |
| 5. Arquitectura       | X/10       |
| 6. Frontend           | X/10       |
| 7. Deuda técnica      | X/10       |
| **TOTAL**             | **X/70**   |

### Problemas críticos (❌) — resolver antes del próximo deploy

<lista numerada de todos los ❌ encontrados con archivo:línea>

### Advertencias (⚠️) — resolver en próxima iteración

<lista numerada de todas las ⚠️ encontradas>
```

## Criterio de puntuación por categoría

| Puntuación | Significado |
|------------|-------------|
| 10/10      | Sin hallazgos negativos |
| 8–9/10     | Solo advertencias menores (⚠️), sin errores (❌) |
| 6–7/10     | 1–2 errores (❌) no críticos o varias advertencias |
| 4–5/10     | 3–5 errores o 1–2 errores críticos |
| 0–3/10     | Múltiples errores críticos o falla de seguridad |

## Reglas de la auditoría

- **Nunca asumas** el estado del código sin haberlo verificado con Grep o Read
- Si un directorio no existe (`src/`, `views/`, etc.), indícalo como "módulo no implementado aún" — no es un error, es contexto
- Reporta los hallazgos con **archivo y número de línea** cuando sea posible
- Si un problema aparece en múltiples archivos, agrúpalos bajo un solo ❌ con la lista de afectados
- Si no encuentras ningún hallazgo negativo en una categoría, escribe: "Sin hallazgos — todo en orden"
- La auditoría es descriptiva, no prescriptiva: reporta, no corrijas (a menos que el usuario lo pida explícitamente después de ver el reporte)
