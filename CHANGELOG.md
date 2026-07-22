# Historial de versiones

Cada versión es un deploy a producción. Los deploys chicos suben el segundo
número (v1 → v1.1); un cambio grande sube el primero (v1.x → v2). Un cambio que
no sube código (por ejemplo, editar el `.env` de producción) no es una versión.

Este archivo es la **única fuente de verdad** del historial: de acá salen la
pantalla **Ajustes → Versiones** y el badge de versión del home del Admin. Si acá
dice v2, la app dice v2.

## Formato

Una fila por versión, la más nueva arriba:

```
| **v1.1** · 07/07/2026 | Cambio uno · Cambio dos |
```

A la izquierda la versión y su fecha de publicación en DD/MM/YYYY —o
`sin publicar` si ya está en `main` pero todavía no salió a producción—. A la
derecha los cambios enumerados, separados por ` · `. El parser
(`src/Helpers/Changelog.php`) espera exactamente ese formato y hay un test que
lo verifica contra este archivo, así que **respetá los asteriscos y los puntos
medios**.

## Versiones

| Versión | Qué cambió |
|---|---|
| **v2.4** · 21/07/2026 | Aviso automático a la supervisora cuando se agregan o quitan habitaciones en Cloudbeds, con opción de aceptar o rechazar el cambio |
| **v2.3** · 21/07/2026 | Corrección al editar un mismo checklist desde dos sesiones a la vez (antes daba un error genérico y el editor se trababa) · Corrección al heredar el trabajo previo en una re-limpieza cuando cambia el tipo de la habitación |
| **v2.2** · 20/07/2026 | Historial de versiones de cada checklist · Editar un checklist ya no cambia los reportes de días pasados |
| **v2.1** · 18/07/2026 | Corrección de la asignación de hotel al crear y editar usuarios (opción «Ambos» por defecto, misma lista en todas las pantallas) |
| **v2** · 18/07/2026 | Recuperación de clave por email · Botón de cerrar sesión en toda la app · Contador de habitaciones en la barra del trabajador · Desasignar habitaciones · Créditos por ítem en áreas comunes · Historial de limpiezas por habitación · Colores de las tarjetas editables · Historial de versiones de la app · Corrección de fechas en los reportes |
| **v1.1** · 07/07/2026 | Editor de checklists por tipo · Créditos por peso de cada ítem |
| **v1** · 07/07/2026 | Primera versión en producción · Checklist de limpieza por habitación · Asignación manual y automática · Auditoría con tres estados · Alertas predictivas para la supervisora · Reportes y créditos por trabajador · Tickets de mantención · Turnos · Áreas comunes · Varias limpiezas por día · Ocupación y cambio de sábanas desde Cloudbeds · Roles y permisos editables · App instalable con notificaciones push |
