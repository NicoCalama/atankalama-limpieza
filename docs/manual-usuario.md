# Manual de Usuario — Atankalama Aplicación Limpieza

**Versión 1.0** | Aplicación web para gestión de limpieza hotelera

---

## Tabla de Contenidos

1. [Introducción](#introducción)
2. [Cómo acceder](#cómo-acceder)
3. [Home del Trabajador](#home-del-trabajador)
4. [Home de la Supervisora](#home-de-la-supervisora)
5. [Home de Recepción](#home-de-recepción)
6. [Home del Admin](#home-del-admin)
7. [Preguntas Frecuentes](#preguntas-frecuentes)

---

## Introducción

**Atankalama Limpieza** es una aplicación diseñada para gestionar la limpieza de habitaciones en los hoteles Atankalama (1 Sur e Inn) de forma eficiente y colaborativa.

Cada miembro del equipo tiene un perfil diferente con funciones específicas:

- **Trabajador**: realiza la limpieza de habitaciones
- **Supervisora**: asigna habitaciones, audita calidad, ve alertas de desempeño
- **Recepción**: ve estado de habitaciones, puede levanttar tickets de mantenimiento
- **Admin**: configura la app, gestiona usuarios, permisos y reportes

---

## Cómo acceder

### Primer acceso

1. Abre el navegador en tu teléfono o computadora
2. Ve a la URL que tu admin te proporcionó
3. Verás la **pantalla de Login** con dos campos:
   - **RUT**: tu número de identificación (ej. 12345678-K)
   - **Contraseña**: contraseña temporal que te envió el admin
4. Haz clic en **"Iniciar sesión"**

### Cambio de contraseña (primer login)

La primera vez que entres, la app te obligará a cambiar tu contraseña temporal:

1. Verás una pantalla de **"Cambiar contraseña"**
2. Ingresa tu contraseña actual (la temporal)
3. Ingresa tu nueva contraseña (mínimo 8 caracteres)
4. Confirma la nueva contraseña
5. Haz clic en **"Cambiar"**

A partir de ahora, usarás esa nueva contraseña para entrar.

### Cerrar sesión

En cualquier pantalla, en la esquina superior derecha verás tu nombre. Haz clic → **"Cerrar sesión"**.

---

## Home del Trabajador

El Home del Trabajador es tu panel principal para hacer limpieza diaria.

### Pantalla Principal

Al entrar, ves:

- **Encabezado**: tu nombre, hora y estado (Ej. "En turno" o "Fuera de turno")
- **Barra de progreso**: muestra cuántas habitaciones completaste hoy vs cuántas hay en tu cola
- **Tu cola de habitaciones**: lista de habitaciones que te asignaron hoy, ordenadas por orden de limpieza

Cada habitación en la cola muestra:
- **Número de habitación** (Ej. "Hab 201")
- **Tipo** (individual, doble, suite)
- **Estado actual**: 
  - 🔴 Rojo = Sucia (pendiente de limpiar)
  - 🟡 Amarillo = En progreso (ya empezaste)
  - 🟢 Verde = Completada (terminada, esperando auditoría)

### Iniciar Limpieza

1. Toca la habitación que vas a limpiar
2. Se abre la pantalla de **checklist**
3. Verás la lista de cosas que debes revisar/hacer:
   - Limpiar baño
   - Cambiar sábanas
   - Barrer piso
   - Pasar trapo
   - Verificar toallas limpias
   - etc.

4. **A medida que terminas cada tarea**, toca el checkbox para marcar como hecho
5. **La app guarda cada marca automáticamente** (aunque se cierre la app, los cambios se guardan)

### Completar Habitación

Una vez que hayas marcado **todas las tareas** del checklist:

1. El botón **"Habitación terminada"** se desbloquea (se pone verde)
2. Haz clic en él
3. La app registra automáticamente la **hora de termino**
4. La habitación se envía a **auditoría** (la supervisora o recepcionista la verá para inspeccionar)

### Ver Detalles de una Habitación

Si toques una habitación que ya completaste, verás:
- El checklist completo (ya marcado)
- Hora en que terminaste
- Estado actual (ej. "Aprobada" o "En auditoría")

### Panel Lateral (Móvil)

En móviles, hay un **menú hamburguesa** (≡) en la esquina superior izquierda. Desde ahí puedes:
- Ver tus notificaciones
- Acceder a Copilot IA (preguntas)
- Cambiar tema día/noche
- Cambiar contraseña
- Cerrar sesión

---

## Home de la Supervisora

La Supervisora es responsable de asignar habitaciones, auditar calidad y ver alertas de desempeño del equipo.

### Pantalla Principal

Al entrar, ves:

- **Resumen del día**: 
  - Habitaciones limpias ✓
  - Habitaciones en progreso 🟡
  - Habitaciones sin asignar 🔴
  - Habitaciones rechazadas por auditoría ⛔

- **Alertas** (en la esquina superior derecha, icono 🔔):
  - Trabajador en riesgo de no terminar su turno
  - Habitación rechazada (necesita reasignación)
  - Sincronización con Cloudbeds fallida
  - etc.

- **Trabajadores del turno**: lista de trabajadores activos hoy con su progreso

### Asignar Habitaciones

**Opción 1: Manual (una por una)**

1. Ve a la sección **"Asignaciones"** (tab en el fondo o menú lateral)
2. Verás habitaciones sin asignar de color rojo
3. Toca una habitación → se abre un formulario
4. Selecciona el **trabajador** de la lista
5. Haz clic en **"Asignar"**
6. La habitación desaparece de la lista sin asignar y aparece en la cola del trabajador

**Opción 2: Automática (round-robin)**

1. En la pantalla de **Asignaciones**, hay un botón **"Auto-asignar"**
2. Selecciona el hotel (1 Sur, Inn, o Ambos)
3. Haz clic → la app reparte automáticamente todas las habitaciones pendientes entre los trabajadores activos

### Reasignar Habitaciones Rechazadas

Si una habitación fue **rechazada por auditoría** (ej. limpieza incompleta):

1. Aparecerá con icono ⛔ rojo en la lista
2. Toca la habitación → verás un botón **"Reasignar"**
3. Selecciona un trabajador diferente (puede ser el mismo, pero se recomienda otro)
4. La habitación vuelve a estado "Sucia" y se añade a la cola del trabajador

### Auditar Habitaciones

Una vez que un trabajador marca una habitación como "Terminada", tú la ve en la sección **"Auditoría"**:

1. Ve a **Auditoría** (tab en el fondo)
2. Verás habitaciones esperando tu inspección
3. Toca una habitación → se abre en modo inspección (puedes ver el checklist, fotos si las hay, etc.)
4. Inspecciona físicamente la habitación en el hotel
5. En la app, elige:
   - ✅ **Aprobada**: todo está bien, la habitación se marca como limpia en Cloudbeds
   - ✅ **Aprobada con observación**: todo está bien pero dejas una nota (ej. "cortinas ajustes menores"). La habitación se aprueba pero queda constancia de la observación.
   - ❌ **Rechazada**: hay que relimpiar. Aparecerá en tu lista de reasignaciones.

6. Opcionalmente agrega un comentario (ej. "Espejo con manchas")
7. Haz clic en tu opción

**Importante**: Una vez auditada, **NO se puede cambiar el veredicto**. El sistema lo protege para mantener un historial limpio.

### Ver Alertas

En la esquina superior derecha, el icono 🔔 muestra:

- **Alertas rojas (P1)**: trabajador en riesgo de no terminar, habitación rechazada, sincronización fallida
- **Alertas naranjas (P2)**: trabajador disponible, ticket nuevo de mantenimiento
- Toca para ver detalles y acciones recomendadas

### Reportes Mensuales

En la sección **"Reportes"**:

1. **Resumen mensual por trabajador**: ve cuántas habitaciones limpió cada trabajador en el mes + créditos/bonificaciones
   - Selecciona el mes y hotel
   - Puedes **descargar en CSV** (botón "Exportar")

2. **Resumen de auditorías**: ve cuántas auditadas hizo cada supervisora/recepcionista, desglosadas por veredicto (aprobadas, con observación, rechazadas)
   - Selecciona el mes y hotel
   - Puedes **descargar en CSV** (botón "Exportar")

---

## Home de Recepción

Recepción ve el estado de las habitaciones y puede levantar tickets de mantenimiento.

### Pantalla Principal

Al entrar, ves:

- **Estado de los hoteles**:
  - Habitaciones limpias ✅
  - Habitaciones en limpieza 🟡
  - Habitaciones sucias 🔴
  - Habitaciones con problemas ⛔

- **Listado completo de habitaciones** con filtros (por estado, hotel, tipo)

### Ver Detalles de una Habitación

Toca cualquier habitación para ver:
- Estado actual (sucia, en progreso, completada, aprobada, etc.)
- Quién la está limpiando (si aplica)
- Último checklist realizado
- Historial de auditoría (quién la auditó, cuándo, veredicto)

### Levantar un Ticket de Mantenimiento

Si detectas un problema en una habitación (ej. lámpara rota, fuga de agua):

1. Toca la habitación
2. Busca el botón **"Levantar ticket"** o **"Reportar problema"**
3. Llena el formulario:
   - **Título**: "Lámpara de noche rota" o similar
   - **Descripción**: detalles del problema
   - **Prioridad**: Normal, Urgente, Baja
4. Haz clic en **"Enviar ticket"**
5. El Admin recibirá una notificación y asignará a mantenimiento

### Auditar Habitaciones

**Si tienes permisos de auditoría** (igual que la Supervisora):

- Ve a **Auditoría**
- Inspecciona y aprueba/rechaza habitaciones completadas
- (Ver sección de Supervisora → "Auditar Habitaciones" para más detalles)

---

## Home del Admin

El Admin es el gerente de la aplicación. Configura todo, crea usuarios y ve reportes.

### Pantalla Principal

Dashboard con:
- Total de usuarios activos
- Total de habitaciones en los hoteles
- Habitaciones completadas hoy
- Alertas técnicas (ej. sincronización con Cloudbeds)

### Gestión de Usuarios

En **Ajustes** → **Usuarios**:

1. **Ver lista**: todos los usuarios, filtrado por rol (Trabajador, Supervisora, Recepción, Admin)

2. **Crear usuario**:
   - Llena el formulario: nombre, RUT, email (opcional), rol
   - La app genera una **contraseña temporal** automáticamente
   - El usuario debe cambiarla en su primer login
   - Haz clic en **"Crear"**

3. **Editar usuario**:
   - Toca un usuario
   - Cambia su nombre, rol, estado (activo/inactivo)
   - Haz clic en **"Guardar"**

4. **Resetear contraseña**:
   - Toca un usuario
   - Botón **"Resetear contraseña"**
   - La app genera una nueva contraseña temporal
   - El usuario la cambiará en su próximo login

5. **Desactivar usuario**:
   - Toca un usuario
   - Marca como **"Inactivo"**
   - El usuario no puede entrar a la app

### Matriz RBAC (Roles y Permisos)

En **Ajustes** → **Roles y Permisos**:

Verás una tabla con todos los roles (filas) y permisos (columnas). Puedes:

- ✅ Marcar un permiso para asignarlo a un rol
- ❌ Desmarcar para quitarlo

Ejemplos de permisos:
- `auditoria.aprobar`: puede auditar habitaciones
- `habitaciones.asignar_manual`: puede asignar habitaciones
- `reportes.ver`: puede ver reportes
- `alertas.recibir_predictivas`: recibe alertas de riesgo
- `usuarios.crear`: puede crear nuevos usuarios

**Por defecto** cada rol tiene permisos base. Puedes ajustarlos según necesites.

### Configuración de Turnos

En **Ajustes** → **Turnos**:

1. Define los horarios de turno (ej. "Mañana: 08:00-16:00", "Tarde: 14:00-22:00")
2. Asigna trabajadores a turnos (ej. "Juan: Mañana los lunes a viernes")
3. Guarda cambios

### Configuración de Checklists

En **Ajustes** → **Checklists templates**:

1. Define qué items debe verificar cada tipo de habitación
2. Puedes tener templates diferentes para habitaciones simples, dobles, suites
3. Los trabajadores verán estos items en su pantalla de limpieza

### Configuración Cloudbeds

En **Ajustes** → **Cloudbeds**:

1. **Credenciales**: guarda las API keys de ambos hoteles (1 Sur e Inn)
2. **Schedule de sincronización**: a qué hora se sincroniza automáticamente (ej. 09:00 y 21:00)
3. **Sincronizar ahora**: botón para forzar una sincronización manual

**¿Qué hace?** La app sincroniza el estado de habitaciones (sucia/limpia) con Cloudbeds, tu sistema de gestión hotelera.

### Configuración de Alertas

En **Ajustes** → **Alertas**:

1. **Margen de seguridad**: cuántos minutos de anticipación el sistema debe avisar si un trabajador va a no terminar (default 15 min)
2. **Umbrales de historiales**: cuántas limpiezas previas necesita un trabajador para calcular su promedio

### Logs y Auditoría

En **Ajustes** → **Logs**:

1. Ver todos los eventos del sistema (INFOs, WARNINGs, ERRORs)
2. Filtrar por:
   - Nivel (Info, Advertencia, Error)
   - Módulo (auth, habitaciones, auditoría, etc.)
   - Fecha
3. Útil para debugging o investigar incidentes

### Reportes

En **Reportes**:

1. **KPIs (Key Performance Indicators)**:
   - Tiempo promedio de limpieza por trabajador
   - Tasa de rechazo de auditoría
   - Habitaciones por turno
   - Filtrar por fecha, hotel, trabajador
   - Descargar en CSV

2. **Resumen mensual por trabajador**: ver productividad y créditos del mes

3. **Resumen de auditorías**: ver desempeño de supervisoras/recepción

---

## Preguntas Frecuentes

### **P: ¿Qué pasa si se va la internet mientras limpio?**

**R:** No hay problema. La app guarda tus cambios en el teléfono (cache local). Cuando vuelve la internet, se sincroniza automáticamente. Incluso si cierras la app, cuando la abras de nuevo verás tu progreso.

### **P: ¿Puedo cambiar el orden de mis habitaciones?**

**R:** Sí. En tu cola, puedes **arrastrar y soltar** cada habitación para reordenarla. El sistema guarda tu orden.

### **P: ¿Qué significa "Aprobada con observación"?**

**R:** La habitación está limpia, pero el auditor dejó una nota (ej. "espejo con manchita menor"). Aparece como aprobada en Cloudbeds, pero tú ves la observación para mejorar próximas veces.

### **P: ¿Si rechazan mi habitación, pierdo dinero?**

**R:** Depende de tu contrato. El sistema solo registra el rechazo. Tu supervisor o jefe dirá cómo se maneja (si hay descuento, bonificación por re-limpieza, etc.).

### **P: ¿Puedo ver mis históricos de limpiezas pasadas?**

**R:** (Según rol)
- **Trabajador**: solo ves el día actual
- **Supervisora/Recepción**: ves los reportes de meses anteriores en Reportes
- **Admin**: acceso total a todo historial

### **P: ¿Qué hago si olvido mi contraseña?**

**R:** Avísale a tu Admin. Él puede resetearla desde la app. Recibirás una contraseña temporal nueva.

### **P: ¿La app me da tips de limpieza?**

**R:** Sí. Hay un icono de **Copilot** (✨ chispa) en la pantalla. Puedes hacer preguntas:
- "¿Cómo limpiar manchas de café?"
- "¿Qué hacer si no tengo producto de limpieza?"
- "¿Cuánto tiempo debe tomar una habitación doble?"

El Copilot te dará respuestas personalizadas.

### **P: ¿Puedo usar la app en computadora?**

**R:** Sí, es **mobile-first** (optimizada para teléfono), pero también funciona en desktop/tablet. Solo abre la URL en tu navegador.

### **P: ¿Cómo cambio mi contraseña?**

**R:** En cualquier pantalla, toca tu nombre (esquina superior derecha) → **Cambiar contraseña** → ingresa la vieja y la nueva → Guardar.

### **P: ¿Qué es la "Sincronización con Cloudbeds"?**

**R:** Cloudbeds es el sistema que usan los hoteles para reservaciones y estado de habitaciones. Cuando completamos una habitación, la app le avisa a Cloudbeds "esta habitación está limpia" para que los recepcionistas sepan que ya puede alojarse un nuevo huésped.

### **P: ¿Por qué el botón "Habitación terminada" está gris?**

**R:** Porque aún hay items del checklist sin marcar. Marca todos los items antes de terminar la habitación.

### **P: ¿Recibo notificaciones?**

**R:** Sí, si habilitas notificaciones en tu teléfono:
- **Trabajador**: cuando te asignan una nueva habitación
- **Supervisora**: cuando hay una alerta (trabajador en riesgo, rechazo, etc.)
- **Recepción**: cuando hay un ticket nuevo
- **Admin**: notificaciones técnicas

### **P: ¿Cómo activo el tema oscuro?**

**R:** Toca tu nombre (esquina superior derecha) → **Tema** → elige "Oscuro" o "Automático" (sigue tu teléfono).

---

## Contacto y Soporte

Si tienes problemas o preguntas no cubiertas aquí:

1. **Admin del sistema**: habla con tu administrador local
2. **Tickets de soporte**: usa el botón "Reportar problema" en la app (Recepción/Admin pueden crear tickets)

---

**Última actualización**: Abril 2026  
**Versión de la app**: 1.0  
**Desarrollador**: Claude Code + Atankalama Corp
