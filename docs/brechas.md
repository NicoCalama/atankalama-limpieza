# Runbook de respuesta a brechas de seguridad

**Aplicación:** Atankalama Limpieza
**Versión:** 1.0
**Vigencia:** 26 de abril de 2026
**Audiencia:** equipo técnico y administrativo de Atankalama Corp con acceso al sistema.

Este documento es un runbook operacional. Está pensado para ser seguido bajo presión, paso a paso, por cualquier persona del equipo que tenga acceso al servidor o a la base de datos. No es un texto legal: es una guía de acción.

Para el contexto legal y los compromisos con los titulares, ver `docs/privacidad.md`. La sección 9 de ese documento declara un objetivo de notificación de 72 horas desde la detección, que este runbook ayuda a cumplir.

---

## 1. Propósito y alcance

Este runbook se activa ante cualquier incidente de seguridad que afecte (o que se sospeche que afectó) la confidencialidad, integridad o disponibilidad de los datos personales tratados por la aplicación.

### 1.1. Qué califica como brecha

Aplica este runbook si ocurre o se sospecha alguno de los siguientes eventos:

| Tipo | Ejemplos concretos |
|------|--------------------|
| Acceso no autorizado | Login exitoso desde IP desconocida con credencial del admin; sesión activa de un trabajador que niega haber entrado. |
| Exfiltración | Descarga masiva de datos desde el VPS; archivo `database/atankalama.db` copiado fuera del servidor sin autorización. |
| Pérdida | Disco del VPS corrupto sin respaldo reciente; borrado accidental de tablas con datos personales. |
| Alteración no controlada | Modificación de filas en `usuarios`, `audit_log` o `rol_permisos` sin orden de servicio asociada. |
| Compromiso de credencial admin | Password admin filtrado, robado o usado por terceros. |
| Compromiso de claves API | Cloudbeds, Claude (Anthropic) o SMTP con sospecha de uso indebido. |
| Ataque exitoso | Inyección, XSS persistido, RCE, escalada de privilegios verificada. |
| Pérdida de dispositivo | Robo o pérdida del notebook del administrador con sesión iniciada. |

### 1.2. Qué NO activa este runbook

- Errores funcionales sin impacto en datos personales (ej.: un botón que no responde).
- Falsos positivos verificables (ej.: un trabajador olvidó su contraseña tres veces).
- Incidentes operativos sin componente de seguridad (ej.: caída de Cloudbeds por su lado).

Cuando dudes, activa el runbook. Es preferible cerrar un falso positivo que perder horas críticas.

---

## 2. Roles y responsabilidades

En un equipo pequeño una sola persona puede tomar varios roles. Lo importante es que cada función esté cubierta y que el coordinador sepa quién hace qué.

| Rol | Responsabilidad principal | Quién lo asume típicamente |
|-----|---------------------------|----------------------------|
| Detector | Identifica y reporta el incidente. Levanta la alarma. | Cualquier persona del equipo. |
| Coordinador del incidente | Dirige la respuesta, mantiene el timeline, decide cuándo escalar. | Administrador del sistema. |
| Soporte técnico | Aplica medidas de contención y remediación en el VPS y la BD. | Administrador del sistema o responsable técnico. |
| Comunicador | Redacta y envía las notificaciones a titulares y, si corresponde, a autoridades. | Administrador del sistema en coordinación con jefatura de Atankalama Corp. |
| Documentador | Toma notas en tiempo real, prepara el archivo de incidente. | Coordinador o quien designe. |

El coordinador es la única persona autorizada a declarar el incidente cerrado.

---

## 3. Detección — señales de alarma

### 3.1. Señales en la base de datos

| Señal | Dónde mirar | Umbral aproximado |
|-------|-------------|-------------------|
| Login fallidos masivos contra una misma cuenta | Tabla `intentos_login` | Más de 10 intentos fallidos en 5 minutos sobre el mismo RUT. |
| Login fallidos desde una misma IP contra varias cuentas | Tabla `intentos_login` | Más de 20 intentos en 10 minutos desde la misma IP. |
| Errores anómalos en módulo `auth` | Tabla `logs_eventos` con `modulo='auth'` y `nivel IN ('WARNING','ERROR')` | Aumento súbito sobre la línea base. |
| Errores en integraciones externas | `logs_eventos` con `modulo IN ('cloudbeds','copilot','email')` | Picos de fallos 401/403 sugieren credencial rotada o comprometida. |
| Acciones inesperadas del admin | Tabla `audit_log` filtrando por usuarios con permisos altos | Cualquier acción sensible fuera del horario habitual de operación. |
| Sesiones desde IPs nuevas | Tabla `sesiones` | IP que no aparecía antes para ese usuario. |

### 3.2. Señales reportadas por personas

- Un trabajador avisa que ve asignaciones que no le corresponden.
- Una supervisora detecta cambios en la matriz de permisos que no autorizó.
- Llega un correo de "restablecer contraseña" que nadie pidió.
- Un usuario indica que su sesión se cerró sola en horario laboral sin razón.

### 3.3. Señales externas

- Aviso del proveedor del VPS (sospecha de compromiso, abuso saliente, IP en blacklist).
- Notificación de Cloudbeds o Anthropic sobre uso anómalo de la API.
- Aviso del servicio SMTP por envío masivo no esperado.

Cualquiera de estas señales obliga a abrir el runbook con un coordinador asignado en menos de 30 minutos.

---

## 4. Respuesta inmediata — primeras 4 horas

El objetivo de esta fase es **contener**, no investigar. Investigar viene después.

### 4.1. Checklist de contención

- [ ] Asignar coordinador del incidente y registrar hora de inicio.
- [ ] Abrir un archivo de bitácora en blanco (puede ser un `.md` local) e ir anotando cada acción con timestamp.
- [ ] Tomar snapshot de la base de datos antes de tocar nada.
- [ ] Decidir si la brecha está activa (atacante operando ahora) o pasiva (ya ocurrió).
- [ ] Si está activa: cerrar acceso externo deteniendo Caddy.
- [ ] Forzar relogin global: rotar `SESSION_SECRET` en `.env` y vaciar `sesiones`.
- [ ] Si se sospecha credencial admin comprometida: rotar password admin.
- [ ] Si se sospechan claves API comprometidas: rotarlas y actualizar `.env`.
- [ ] Reabrir Caddy una vez aplicada la contención mínima.

### 4.2. Snapshot forense de la base de datos

Antes de aplicar ningún cambio, copia la BD a un lugar seguro. Esto preserva evidencia.

```bash
# En el VPS, como usuario con acceso al directorio del proyecto
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p /var/backups/atankalama/incidentes
cp /ruta/al/proyecto/database/atankalama.db \
   /var/backups/atankalama/incidentes/atankalama-incidente-${TS}.db
sha256sum /var/backups/atankalama/incidentes/atankalama-incidente-${TS}.db \
   > /var/backups/atankalama/incidentes/atankalama-incidente-${TS}.sha256
```

Anota en la bitácora la ruta exacta del snapshot y su hash. Ese hash es la prueba de que el archivo no fue modificado después.

### 4.3. Cerrar acceso externo si la brecha está activa

```bash
# Detener Caddy temporalmente
sudo systemctl stop caddy
```

Reactiva solo cuando hayas aplicado las medidas de contención mínimas.

```bash
sudo systemctl start caddy
sudo systemctl status caddy
```

### 4.4. Forzar relogin global

Rotar el secreto de sesión invalida todas las cookies firmadas existentes y, complementariamente, vaciar la tabla `sesiones` cierra cualquier sesión opaca.

```bash
# 1) Editar .env y reemplazar SESSION_SECRET por un nuevo valor aleatorio (>= 64 chars).
#    Generar uno con:
php -r "echo bin2hex(random_bytes(48)).PHP_EOL;"
```

```sql
-- 2) Vaciar la tabla de sesiones desde sqlite3 sobre la BD en producción.
DELETE FROM sesiones;
```

Después de esto, todos los usuarios deberán volver a iniciar sesión. Es esperable.

### 4.5. Rotar password del admin

Si hay sospecha de credencial admin comprometida:

```bash
php scripts/reset-admin-password.php
```

El script genera una contraseña temporal y la entrega por el canal seguro definido (no la pegues en chats compartidos).

### 4.6. Rotar claves API comprometidas

Edita `.env` y reemplaza, según corresponda:

| Variable | Acción adicional |
|----------|------------------|
| `CLOUDBEDS_CLIENT_ID` / `CLOUDBEDS_CLIENT_SECRET` / `CLOUDBEDS_REFRESH_TOKEN` | Revocar el cliente OAuth desde el panel de Cloudbeds y emitir uno nuevo. |
| `ANTHROPIC_API_KEY` | Revocar la key desde la consola de Anthropic y crear una nueva. |
| `SMTP_PASSWORD` | Cambiar la contraseña en el panel del proveedor SMTP. |

Reinicia los procesos PHP que correspondan para que tomen las nuevas variables. Verifica con un health check rápido que las integraciones siguen funcionando.

### 4.7. Decisión: ¿escalar o no?

Al cierre de esta fase, el coordinador decide:

- Si los datos personales de uno o más titulares pudieron quedar expuestos -> seguir a la sección 5 y preparar notificación.
- Si fue contenido sin exposición de datos personales -> registrar igualmente en `docs/incidentes/` por trazabilidad y saltar a la sección 7 (remediación).

---

## 5. Investigación — 4 a 24 horas

Con el sistema contenido, ahora reconstruye qué pasó.

### 5.1. Definir ventana de tiempo sospechosa

Acota desde cuándo hasta cuándo puede haber ocurrido el incidente. Sé generoso por el lado conservador: si no estás seguro, ensancha la ventana.

### 5.2. Revisar audit log y logs técnicos

Trabaja sobre el snapshot forense, no sobre la BD viva, para no mezclar acciones de la respuesta con acciones del atacante.

```sql
-- Acciones registradas en audit_log dentro de la ventana sospechosa.
SELECT id, fecha, usuario_id, accion, recurso_tipo, recurso_id, ip, detalle
FROM audit_log
WHERE fecha BETWEEN '2026-04-26 00:00:00' AND '2026-04-26 23:59:59'
ORDER BY fecha ASC;
```

```sql
-- Eventos técnicos relevantes en la misma ventana.
SELECT id, fecha, modulo, nivel, mensaje
FROM logs_eventos
WHERE fecha BETWEEN '2026-04-26 00:00:00' AND '2026-04-26 23:59:59'
  AND nivel IN ('WARNING','ERROR')
ORDER BY fecha ASC;
```

### 5.3. Identificar usuarios afectados

Cruza los hallazgos para determinar qué titulares quedaron expuestos y qué categorías de datos.

```sql
-- Sesiones activas durante la ventana, con IP y user-agent.
SELECT s.usuario_id, u.rut, u.nombre, s.ip, s.user_agent, s.creada_en, s.expira_en
FROM sesiones s
JOIN usuarios u ON u.id = s.usuario_id
WHERE s.creada_en BETWEEN '2026-04-26 00:00:00' AND '2026-04-26 23:59:59'
ORDER BY s.creada_en ASC;
```

```sql
-- Intentos de login fallidos en la ventana, agrupados por RUT e IP.
SELECT rut, ip, COUNT(*) AS intentos, MIN(fecha) AS primer, MAX(fecha) AS ultimo
FROM intentos_login
WHERE exito = 0
  AND fecha BETWEEN '2026-04-26 00:00:00' AND '2026-04-26 23:59:59'
GROUP BY rut, ip
ORDER BY intentos DESC;
```

Para cada titular afectado, anota qué categorías de la sección 2 del aviso de privacidad estuvieron expuestas (identificación, sesión, operativos, copilot, notificaciones, logs).

### 5.4. Determinar el vector

Trata de responder, con evidencia:

1. ¿Cómo entró el atacante? (credencial reutilizada, fuga de `.env`, vulnerabilidad explotada, ingeniería social, dispositivo perdido).
2. ¿Hasta dónde llegó? (solo lectura, escritura, escalada de privilegios, acceso al sistema operativo del VPS).
3. ¿Hay persistencia? (usuarios nuevos, claves SSH añadidas, tareas cron creadas, permisos modificados en `rol_permisos`).

Si no logras determinar el vector con certeza razonable, márcalo como **vector no confirmado** en la documentación. No inventes una causa.

---

## 6. Notificación — antes del objetivo de 72 horas

El aviso de privacidad declara un **objetivo recomendado** de 72 horas desde la detección para notificar a los titulares afectados. Este runbook se diseña para cumplirlo.

### 6.1. A los titulares afectados

Canal principal: correo electrónico al email registrado en el sistema (cuando exista). Para titulares sin email, usar aviso dentro de la app y comunicación directa por jefatura.

La notificación debe incluir, sin lenguaje legalista:

1. Qué pasó, en una frase.
2. Cuándo se detectó.
3. Qué categorías de datos personales pudieron verse afectadas (las del aviso de privacidad).
4. Qué medidas tomó la empresa.
5. Qué debe hacer el titular ahora (típicamente: cambiar contraseña, revisar accesos).
6. A quién dirigirse para preguntas (administrador del sistema).

### 6.2. Plantilla de email a titulares

```
Asunto: Aviso importante sobre tu cuenta en la aplicación de limpieza

Hola [nombre]:

Te escribimos para informarte que el día [fecha] detectamos un incidente
de seguridad que pudo haber afectado tu cuenta en la aplicación de
limpieza de Atankalama.

Qué pasó:
[describir en lenguaje simple, una o dos frases. Ejemplo: "Detectamos
accesos no autorizados a la aplicación durante un período de tiempo."]

Qué datos pudieron verse afectados:
[listar las categorías concretas, por ejemplo:
- Tu RUT y nombre.
- Tu correo electrónico registrado.
- Tus asignaciones recientes y avances de checklist.]

Qué hicimos al detectarlo:
- Cerramos todas las sesiones activas y obligamos a iniciar sesión nuevamente.
- Cambiamos las claves internas de la aplicación.
- Revisamos los registros del sistema para entender el alcance.
- Aplicamos las correcciones necesarias para que no vuelva a ocurrir.

Qué te pedimos hacer:
- La próxima vez que ingreses, cambia tu contraseña aunque el sistema
  no te lo pida.
- Si recibiste algún correo sospechoso a nombre de la aplicación o
  notas algo extraño en tu cuenta, avísanos.

Si tienes dudas o quieres más detalles, responde este correo o conversa
directamente con el administrador del sistema.

Lamentamos las molestias y te agradecemos la confianza.

Equipo Atankalama Limpieza
```

Adapta la plantilla a cada incidente. No envíes la versión genérica sin completar los corchetes.

### 6.3. Registro interno de la notificación

Por cada envío, deja constancia en el archivo de incidente con:

- Fecha y hora de envío.
- Lista de destinatarios (RUT y email enmascarado, ej.: `n***@gmail.com`).
- Versión del texto enviado (puedes guardar el texto completo).
- Canal de envío.
- Persona que envió.

### 6.4. A autoridades

Si la magnitud del incidente lo amerita, jefatura de Atankalama Corp evalúa, con apoyo legal si corresponde, si se notifica a las autoridades chilenas competentes según la legislación vigente al momento del incidente. Este runbook no define umbrales legales específicos; deja la decisión documentada y razonada en el archivo del incidente.

---

## 7. Remediación

Con el incidente notificado, ahora cierras el vector.

### 7.1. Checklist de remediación

- [ ] Aplicar parche o configuración que cierra el vector identificado.
- [ ] Si el vector fue una vulnerabilidad de código: agregar test de regresión que falle si la vulnerabilidad reaparece.
- [ ] Si el vector fue config: actualizar `docs/deploy-vps.md` para reflejar la configuración correcta.
- [ ] Verificar que no quedaron usuarios, claves SSH, cron jobs ni permisos creados por el atacante.
- [ ] Re-correr la suite de tests completa.
- [ ] Hacer un escaneo manual de `audit_log` desde el inicio de la ventana hasta el momento actual para confirmar que no hay actividad sospechosa nueva.

### 7.2. Documentar el incidente

Crear el archivo `docs/incidentes/YYYY-MM-DD-titulo-corto.md` con la siguiente plantilla:

```markdown
# Incidente YYYY-MM-DD: <título corto>

## Resumen
Una o dos frases.

## Línea de tiempo
- HH:MM — Detección. Quién detectó, cómo.
- HH:MM — Coordinador asignado.
- HH:MM — Snapshot forense tomado en <ruta> con hash <sha256>.
- HH:MM — Contención aplicada (qué se hizo).
- HH:MM — Investigación cerrada con conclusión X.
- HH:MM — Notificación enviada a N titulares.
- HH:MM — Remediación aplicada.
- HH:MM — Incidente declarado cerrado por <coordinador>.

## Alcance
- Titulares afectados: <número y descripción>.
- Categorías de datos expuestas: <listado>.
- Sistemas afectados: <listado>.

## Vector
Descripción concreta. Si no se confirmó, decirlo así.

## Acciones de contención
Listado.

## Acciones de remediación
Listado, con commits o cambios de configuración referenciados.

## Notificaciones
- A titulares: <fecha, canal, plantilla usada>.
- A autoridades: <decisión y justificación>.

## Lecciones aprendidas
Listado.

## Acciones correctivas
| Acción | Responsable | Plazo | Estado |
|--------|-------------|-------|--------|
| ... | ... | ... | abierta/cerrada |
```

---

## 8. Post-mortem

Dentro de los 7 días siguientes al cierre del incidente, el coordinador convoca a un post-mortem.

### 8.1. Agenda sugerida (60 minutos)

1. Lectura del archivo de incidente (10 min).
2. Discusión de la línea de tiempo: qué se hizo bien, qué se hizo tarde, qué faltó (20 min).
3. Identificación de causas raíz, no solo del vector inmediato (15 min).
4. Definición de acciones correctivas con responsable y plazo (10 min).
5. Cierre y firma del coordinador (5 min).

### 8.2. Reglas

- **No buscar culpables.** El objetivo es mejorar el sistema, no señalar personas.
- **Toda acción correctiva debe tener responsable y fecha.** Sin esto, no es una acción, es un buen deseo.
- **El archivo de incidente queda como registro permanente** en `docs/incidentes/`. No se borra ni se reescribe.

---

## 9. Comandos útiles

Snippets copiables. Adapta fechas y RUTs a cada incidente.

### 9.1. Login fallidos por usuario en últimas 24 horas

```sql
SELECT rut, COUNT(*) AS intentos_fallidos, MAX(fecha) AS ultimo_intento
FROM intentos_login
WHERE exito = 0
  AND fecha >= datetime('now','-1 day')
GROUP BY rut
HAVING COUNT(*) >= 5
ORDER BY intentos_fallidos DESC;
```

### 9.2. Login fallidos por IP en últimas 24 horas

```sql
SELECT ip, COUNT(*) AS intentos_fallidos, COUNT(DISTINCT rut) AS ruts_distintos
FROM intentos_login
WHERE exito = 0
  AND fecha >= datetime('now','-1 day')
GROUP BY ip
ORDER BY intentos_fallidos DESC;
```

### 9.3. Acciones del admin en el último período

```sql
SELECT a.fecha, a.accion, a.recurso_tipo, a.recurso_id, a.ip, a.detalle
FROM audit_log a
JOIN usuarios u ON u.id = a.usuario_id
WHERE u.rol_id IN (SELECT id FROM roles WHERE codigo = 'admin')
  AND a.fecha >= datetime('now','-2 days')
ORDER BY a.fecha DESC;
```

### 9.4. Listar usuarios con sesión activa

```sql
SELECT u.rut, u.nombre, s.ip, s.user_agent, s.creada_en, s.expira_en
FROM sesiones s
JOIN usuarios u ON u.id = s.usuario_id
WHERE s.expira_en > datetime('now')
ORDER BY s.creada_en DESC;
```

### 9.5. Forzar logout global

```sql
DELETE FROM sesiones;
```

Combinado con rotación de `SESSION_SECRET` en `.env` (sección 4.4).

### 9.6. Buscar cambios sospechosos en `rol_permisos`

```sql
SELECT a.fecha, a.usuario_id, a.accion, a.detalle
FROM audit_log a
WHERE a.recurso_tipo = 'rol_permisos'
  AND a.fecha >= datetime('now','-7 days')
ORDER BY a.fecha DESC;
```

### 9.7. Desactivar una cuenta sospechosa de inmediato

```sql
UPDATE usuarios
SET activo = 0
WHERE rut = '<rut>';
```

Inmediatamente después, eliminar sus sesiones:

```sql
DELETE FROM sesiones
WHERE usuario_id = (SELECT id FROM usuarios WHERE rut = '<rut>');
```

### 9.8. Generar nuevo `SESSION_SECRET`

```bash
php -r "echo bin2hex(random_bytes(48)).PHP_EOL;"
```

### 9.9. Rotar password del admin

```bash
php scripts/reset-admin-password.php
```

### 9.10. Detener y reanudar Caddy

```bash
sudo systemctl stop caddy
sudo systemctl start caddy
sudo systemctl status caddy
```

---

## 10. Anexos

### 10.1. Diagrama de flujo del runbook

```
[1] Detección
       |
       v
[2] Asignar coordinador  ----  abrir bitácora
       |
       v
[3] Snapshot forense de la BD
       |
       v
[4] ¿Brecha activa?
       |          \
      sí           no
       |            \
       v             v
[4a] Detener Caddy   [5] Contención sin downtime
       |             |
       +-------------+
                |
                v
[6] Rotar SESSION_SECRET + DELETE FROM sesiones
                |
                v
[7] ¿Credencial admin comprometida?
       |          \
      sí           no
       |            \
       v             v
[7a] reset-admin   [8] Rotar API keys si aplica
       |             |
       +-------------+
                |
                v
[9] Reabrir Caddy si estuvo detenido
                |
                v
[10] Investigación: audit_log, logs_eventos, sesiones
                |
                v
[11] ¿Datos personales expuestos?
       |          \
      sí           no
       |            \
       v             v
[11a] Notificar    [12] Documentar y cerrar
       |             |
       v             |
[12] Documentar    <-+
                |
                v
[13] Remediación + tests
                |
                v
[14] Post-mortem dentro de 7 días
                |
                v
[15] Cierre formal por coordinador
```

### 10.2. Contactos críticos

Mantén esta tabla actualizada en un lugar de acceso restringido (no en este repositorio en claro). Aquí solo van los rótulos.

| Contacto | Rol en un incidente | Cómo se llega |
|----------|---------------------|---------------|
| Administrador del sistema | Coordinador por defecto. Acceso a `.env`, BD, scripts. | Canal interno de jefatura. |
| Soporte del proveedor de VPS | Bloqueo de IPs, snapshots de disco, evidencia de tráfico. | Panel del proveedor / mesa de ayuda. |
| Soporte de Cloudbeds | Revocar OAuth, auditar accesos a la API del PMS. | Canal de soporte de Cloudbeds. |
| Soporte de Anthropic | Revocar API keys, revisar uso anómalo del copilot. | Consola de Anthropic / soporte. |
| Proveedor SMTP | Bloquear cuentas, revisar envíos, regenerar credenciales. | Panel del proveedor SMTP. |
| Asesoría legal de Atankalama Corp | Decisión sobre notificación a autoridades chilenas. | Vía jefatura. |

### 10.3. Referencias internas

- `docs/privacidad.md` — aviso de privacidad y compromiso de notificación de 72 horas.
- `docs/database-schema.sql` — esquema de tablas mencionadas (`sesiones`, `intentos_login`, `audit_log`, `logs_eventos`, `usuarios`, `rol_permisos`).
- `docs/retencion.md` — políticas de retención que afectan la disponibilidad de logs forenses.
- `docs/deploy-vps.md` — configuración de Caddy y procesos PHP en el VPS.
- `scripts/reset-admin-password.php` — utilitario de rotación de password admin.
