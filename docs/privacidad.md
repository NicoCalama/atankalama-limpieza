# Aviso de Privacidad y Política de Tratamiento de Datos Personales

**Aplicación:** Atankalama Limpieza (sistema interno de gestión de housekeeping)
**Versión:** 1.0
**Fecha de vigencia:** 25 de abril de 2026
**Audiencia:** trabajadores y trabajadoras de Atankalama Corp con cuenta en la aplicación.

Este documento explica, en lenguaje claro, qué datos personales tratamos cuando usas la aplicación, para qué los usamos, con quién se comparten y qué derechos tienes sobre ellos. Está pensado para que cualquier persona del equipo lo lea y lo entienda, sin necesidad de conocimientos técnicos ni legales.

Si después de leerlo te queda alguna duda, puedes pedir aclaraciones al administrador del sistema (ver sección 10).

---

## 1. Identificación del responsable

| Dato | Detalle |
|------|---------|
| Responsable del tratamiento | Atankalama Corp |
| Domicilio | Calama, Región de Antofagasta, Chile |
| Ámbito de uso | Aplicación interna para los hoteles Atankalama 1 Sur y Atankalama Inn |
| Contacto interno | Administrador del sistema (ver sección 10) |

La aplicación es de uso estrictamente interno: solo acceden trabajadores con cuenta creada por el administrador. No existen cuentas para huéspedes ni para personas externas a la organización.

---

## 2. Datos que se recopilan y para qué

A continuación se listan los datos personales que la aplicación procesa, agrupados por categoría, con su finalidad concreta. Toda esta información proviene del esquema real de la base de datos del sistema.

### 2.1. Datos de identificación y cuenta

| Dato | Origen | Finalidad |
|------|--------|-----------|
| RUT | Lo ingresa el administrador al crear la cuenta | Identificador único de inicio de sesión |
| Nombre | Lo ingresa el administrador | Mostrar tu nombre en pantallas, asignaciones y notificaciones |
| Correo electrónico (opcional) | Lo ingresa el administrador | Enviar la contraseña temporal al crearse la cuenta o al resetearla |
| Hash de contraseña | Lo genera el sistema con `bcrypt` | Verificar tu identidad sin guardar la contraseña en claro |
| Hotel por defecto | Lo configura el administrador | Mostrar primero el hotel donde sueles trabajar |
| Tema preferido (claro/oscuro/auto) | Lo eliges tú | Personalizar tu interfaz |

### 2.2. Datos de sesión y conexión

| Dato | Tabla técnica | Finalidad |
|------|---------------|-----------|
| Token de sesión | `sesiones` | Mantener tu sesión activa de forma segura |
| Dirección IP | `sesiones`, `audit_log` | Detectar accesos sospechosos y dejar trazabilidad de acciones sensibles |
| User-Agent del navegador | `sesiones` | Detectar inconsistencias de dispositivo y depurar problemas técnicos |

### 2.3. Datos operativos del trabajo

| Dato | Tabla técnica | Finalidad |
|------|---------------|-----------|
| Asignaciones de habitaciones | `asignaciones` | Saber qué habitaciones te corresponden cada día |
| Turno y fecha | `usuarios_turnos` | Organizar la jornada y los relevos |
| Items marcados/desmarcados del checklist | `ejecuciones_items` | Guardar tu avance tap-a-tap, incluso si pierdes conexión |
| Inicio y fin de cada limpieza (ocultos al trabajador) | `ejecuciones_checklist` | Calcular tu tiempo promedio personal y alimentar las alertas predictivas (ver sección 4) |
| Auditorías recibidas | `auditorias` | Dejar registro del veredicto (aprobada / aprobada con observación / rechazada) |
| Tickets de mantenimiento que levantas | `tickets` | Que el equipo correspondiente reciba y resuelva el ticket |

### 2.4. Datos del copilot conversacional (asistente IA)

| Dato | Tabla técnica | Finalidad |
|------|---------------|-----------|
| Conversaciones e historial | `copilot_conversaciones` | Que puedas retomar conversaciones previas |
| Mensajes (texto que escribes y respuestas del asistente) | `copilot_mensajes` | Funcionamiento del asistente y trazabilidad |
| Conteo de tokens consumidos | `copilot_mensajes` | Control de costos y monitoreo de uso |

Importante: los mensajes son texto libre. Si escribes información sensible (por ejemplo, datos de un huésped o detalles personales tuyos), esa información queda guardada y, además, se envía al proveedor del modelo de IA. Lee con detención la sección 5.

### 2.5. Notificaciones y mensajería interna

| Dato | Tabla técnica | Finalidad |
|------|---------------|-----------|
| Bandeja de notificaciones (asignaciones, rechazos, riesgos, etc.) | `notificaciones` | Avisarte de eventos relevantes dentro de la app |
| Suscripciones a notificaciones push del navegador (claves criptográficas `p256dh` y `auth`) | `push_subscriptions` | Enviarte avisos al dispositivo cuando la app no está abierta |
| Aviso de disponibilidad diaria | `notificaciones_disponibilidad` | Que la supervisora sepa si quedaste con holgura para apoyar |

Las claves de las suscripciones push son técnicas: solo permiten al servidor enviar mensajes a tu navegador, no contienen información de identidad.

### 2.6. Registro de auditoría y logs técnicos

| Dato | Tabla técnica | Finalidad |
|------|---------------|-----------|
| Acciones de negocio realizadas (quién aprobó, rechazó, creó usuarios, etc.) | `audit_log` | Trazabilidad y rendición de cuentas |
| Eventos técnicos (errores, advertencias, sincronizaciones) | `logs_eventos` | Diagnóstico y mantenimiento del sistema |
| Bitácora de alertas | `bitacora_alertas` | Registro histórico de alertas levantadas y cómo se resolvieron |

En estos logs **nunca** se guardan tokens, contraseñas, claves API ni headers de autorización.

---

## 3. Base legal del tratamiento

El tratamiento de tus datos personales se sustenta, principalmente, en las siguientes bases:

- **Ejecución de la relación laboral** y cumplimiento del Código del Trabajo: la aplicación es una herramienta provista por el empleador para coordinar tu labor diaria. El uso del sistema es parte de tus tareas.
- **Interés legítimo del empleador** en organizar la operación, asegurar la calidad del servicio y mantener trazabilidad de las acciones realizadas.
- **Cumplimiento legal**: Ley 19.628 sobre protección de la vida privada y Ley 21.096 que eleva la protección de datos personales a rango constitucional.
- **Consentimiento explícito** para acciones específicas: por ejemplo, las notificaciones push del navegador requieren que tú autorices a tu dispositivo a recibirlas. Puedes revocarlo en cualquier momento desde los ajustes del navegador.
- **Criterios de la Dirección del Trabajo** (entre otros, Ord. 4541/319) sobre uso de medios tecnológicos para evaluar el rendimiento, que se aplican expresamente a la sección 4.

---

## 4. Tracking de tiempos de limpieza (información sensible)

Esta sección merece transparencia especial, porque es lo más sensible que la aplicación hace con tus datos.

**Qué medimos:** cada vez que entras a una habitación y empiezas a marcar items del checklist, el sistema registra el momento exacto en que comenzaste y el momento exacto en que marcaste "habitación terminada". La diferencia es el tiempo que tomó esa limpieza.

**Por qué es oculto:** estos tiempos **no aparecen en tu pantalla**. Decidimos esconderlos porque no queremos que sientas que estás compitiendo contra un cronómetro mientras trabajas. La calidad importa más que el segundo a segundo.

**Para qué se usa:**

- Calcular tu **tiempo promedio personal** (no el de otra persona) para que las alertas predictivas sean realistas para ti.
- Alimentar las **alertas predictivas** que avisan a la supervisora cuando un turno corre riesgo de no terminar a tiempo. La alerta llega solo a la supervisora, nunca a ti.
- Generar **KPIs y reportes agregados** que apoyan la toma de decisiones operativas.

**Quién puede ver estos tiempos:**

- Personas con permisos de **Supervisora** o **Admin** (definidos en la matriz de roles y permisos editable desde Ajustes).
- Tú no los ves dentro de la aplicación.

**Qué NO se hace con estos datos:**

- **No se usan para amonestar, sancionar ni evaluar de forma individual** sin un proceso documentado, conversado contigo, y respetando el Código del Trabajo y los procedimientos internos.
- **No se publican** ni se comparten con terceros ajenos a la operación.
- **No se cruzan** con datos de tu vida fuera del trabajo.

Si alguna vez sospechas que estos datos se están usando con fines distintos a los descritos aquí, puedes ejercer tu derecho de oposición (sección 7) y pedir aclaraciones al administrador.

---

## 5. Transferencias a terceros y transferencia internacional

La aplicación se comunica con tres servicios externos. Te detallamos cada uno con qué información sale y dónde queda.

### 5.1. Cloudbeds (sistema de gestión hotelera — PMS)

- **Qué se transfiere:** únicamente el estado de cada habitación (limpia, sucia, en inspección) y el identificador interno de la habitación en Cloudbeds.
- **Qué NO se transfiere:** ningún dato de empleados. Cloudbeds nunca recibe tu RUT, tu nombre, tus tiempos ni tus checklists.
- **Para qué:** mantener sincronizado el estado de las habitaciones entre la app y el PMS del hotel.

### 5.2. Anthropic Inc. (Claude API, Estados Unidos) — transferencia internacional

Esta es la transferencia más relevante y queremos ser muy claros.

- **Cuándo ocurre:** solo cuando usas el copilot conversacional (el botón flotante del asistente IA).
- **Qué se transfiere:** el contenido completo de tus mensajes de la conversación, junto con el contexto que la aplicación arma para responderte (por ejemplo, datos operativos pertinentes a tu pregunta). Estos mensajes viajan a los servidores de Anthropic Inc., con sede en Estados Unidos, a través del endpoint `api.anthropic.com`.
- **Qué implica:** se trata de una **transferencia internacional de datos personales** hacia un país que tiene un régimen de protección distinto al chileno. La conexión es cifrada (HTTPS), pero el contenido del mensaje queda en infraestructura del proveedor durante el procesamiento.
- **Recomendación importante para ti:** **no incluyas en los mensajes del copilot información personal sensible de huéspedes** (nombres, números de habitación con detalles de salud, datos de pago, situaciones íntimas) ni datos personales tuyos que no sean necesarios para la consulta. Pregunta lo que necesites para tu trabajo de forma genérica.
- **Almacenamiento local:** además de enviarse a Anthropic, las conversaciones se guardan en la base de datos del sistema (tablas `copilot_conversaciones` y `copilot_mensajes`).
- **Quién puede ver tu historial dentro de la organización:** tú mismo y los usuarios con el permiso `copilot.ver_historial_todos` (típicamente, el administrador). La supervisora estándar **no** ve tus conversaciones del copilot a menos que se le otorgue ese permiso explícitamente desde Ajustes.

> Nota: Atankalama Corp se relaciona con Anthropic en condiciones de servicio estándar de la API. Si se firma un acuerdo de tratamiento de datos (DPA) específico, esta política se actualizará para reflejarlo.

### 5.3. Servidor de correo (SMTP)

- **Cuándo ocurre:** cuando se crea tu cuenta o se resetea tu contraseña, y solo si tienes correo configurado.
- **Qué se transfiere:** tu nombre, tu correo y la contraseña temporal de un solo uso.
- **Cómo viaja:** por conexión cifrada al servidor SMTP (STARTTLS o SMTPS, según configuración).
- **Recomendación:** cambia la contraseña temporal apenas inicies sesión por primera vez. El sistema te lo va a pedir.

---

## 6. Plazo de conservación

| Categoría de datos | Plazo |
|--------------------|-------|
| Datos de cuenta (RUT, nombre, email, hash de contraseña) | Mientras dure tu relación laboral con Atankalama Corp. Al egresar, la cuenta se desactiva. La eliminación definitiva se realiza tras los plazos de conservación que exija la ley laboral aplicable. |
| Sesiones | Hasta su expiración o cierre de sesión. Las expiradas se eliminan periódicamente. |
| Contraseñas temporales (`contrasenas_temporales`) | Solo se guarda traza del evento (no la contraseña en claro). Se purga periódicamente. |
| Ejecuciones de checklist y auditorías | Mientras sean útiles para reportes operativos y cumplimiento legal. |
| Logs técnicos (`logs_eventos`) | Se rotan periódicamente; los más antiguos se descartan. |
| Audit log de negocio (`audit_log`) | Se conserva por períodos más largos que los logs técnicos, para mantener trazabilidad de acciones sensibles. |
| Conversaciones del copilot | Mientras sean útiles para el usuario o para auditoría interna. El usuario puede pedir su eliminación (ver sección 7). |
| Suscripciones push | Hasta que el navegador o el usuario las revoque. |

Los plazos exactos de retención y rotación se definen en la configuración operativa del sistema y pueden ajustarse para cumplir con requerimientos legales o de seguridad.

---

## 7. Tus derechos

La Ley 19.628 (artículo 12) te reconoce, como titular de los datos, los siguientes derechos:

- **Acceso:** saber qué datos personales tuyos tiene la empresa y cómo los está tratando.
- **Rectificación:** pedir que se corrijan datos erróneos o desactualizados (por ejemplo, un nombre mal escrito, un correo cambiado).
- **Cancelación (eliminación):** pedir que se eliminen datos que ya no son necesarios o que se trataron sin base legal.
- **Oposición:** oponerte a un tratamiento específico cuando consideres que no se justifica.

**Cómo ejercer estos derechos:**

1. Envía una solicitud por escrito al administrador del sistema (ver sección 10), identificándote y explicando claramente qué derecho quieres ejercer.
2. La empresa responderá en un plazo razonable, normalmente dentro de 15 días hábiles.
3. Si tu solicitud no puede atenderse (por ejemplo, porque hay obligación legal de conservar el dato), se te explicará el motivo.

No se cobra por ejercer estos derechos.

---

## 8. Medidas de seguridad

La aplicación implementa, entre otras, las siguientes medidas técnicas:

- **Conexión HTTPS forzada** en todo el sitio.
- **Contraseñas hasheadas con bcrypt** (`PASSWORD_BCRYPT`); nunca se guardan en claro.
- **Cookies de sesión** con flags `HttpOnly`, `Secure` y `SameSite=Strict` para reducir el riesgo de robo de sesión.
- **Tokens de sesión opacos** generados con generadores criptográficos seguros.
- **Control de acceso basado en roles y permisos (RBAC) dinámico**, validado en el backend antes de cada acción sensible. No basta con que el frontend permita algo: el servidor lo verifica.
- **Sanitización de entradas** del usuario (prevención de XSS y de inyección SQL mediante consultas preparadas con PDO).
- **Variables de entorno (`.env`)** para credenciales y secretos; nunca se versionan en el repositorio.
- **Hook pre-commit con gitleaks** para impedir que se filtren secretos accidentalmente.
- **Logs sanitizados:** los registros técnicos no contienen tokens, claves API ni encabezados de autorización.
- **Trazabilidad** de acciones sensibles en `audit_log`, incluyendo IP y origen.

Ninguna medida es infalible. Si detectas algo raro (un acceso que no reconoces, un cambio que no hiciste), avisa de inmediato al administrador.

---

## 9. Procedimiento ante brechas de seguridad

Si la empresa detecta o recibe aviso de una brecha que pudiera afectar tus datos personales, el procedimiento es:

1. **Detección y contención:** el administrador del sistema, apoyado por el responsable técnico, contiene el incidente lo antes posible (revocar sesiones, rotar credenciales, aislar el sistema afectado si corresponde).
2. **Evaluación:** se evalúa qué datos quedaron expuestos, de cuántas personas y por cuánto tiempo.
3. **Notificación interna:** dentro de un plazo razonable (objetivo de 72 horas desde la detección, sujeto a la complejidad técnica), se notifica a las personas afectadas mediante el medio de contacto disponible (correo o aviso dentro de la app).
4. **Notificación a autoridades:** si la magnitud lo amerita, se notifica a las autoridades chilenas competentes según la legislación vigente al momento del incidente.
5. **Análisis posterior:** se documenta el incidente en `audit_log` y se aplican mejoras para evitar su repetición.

---

## 10. Contacto y vigencia

| Concepto | Detalle |
|----------|---------|
| Responsable | Atankalama Corp |
| Domicilio | Calama, Chile |
| Canal de contacto | Administrador del sistema (consultar internamente con la jefatura quién ocupa este rol vigente) |
| Vigencia de esta versión | desde 25 de abril de 2026 |
| Versión del documento | 1.0 |

Esta política puede actualizarse para reflejar cambios en la aplicación, en los proveedores externos o en la normativa chilena aplicable. Cuando ocurra un cambio relevante, se avisará dentro de la aplicación y, si tienes correo registrado, también por ese medio.

Si tienes dudas sobre cualquier punto de este documento, pregunta al administrador antes de aceptarlo. Esta política se entiende leída y conocida cuando inicies sesión por primera vez después de su publicación.
