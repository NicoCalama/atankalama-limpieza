---
name: cierre-sesion
description: Cierre de sesion — documenta lo hecho, commitea, pushea y entrega un informe de despedida.
disable-model-invocation: true
---

# Cierre de sesion

Ejecuta esta cadena de acciones EN ORDEN. Al invocar el comando, el usuario ya autorizo el commit y el push, asi que NO vuelvas a pedir permiso para esos pasos (salvo que detectes algo destructivo, ambiguo o fuera de alcance: ahi frena y pregunta).

Antes de empezar, detecta donde corres: `echo "$CLAUDE_CODE_REMOTE"`. Si dice `true` estas en una **sesion en la nube**: la memoria automatica no persiste (es local de cada maquina; lo que escribas ahi no lo ve ninguna otra sesion) y el hook de gitleaks no existe (vive en `.git/hooks`, que no se clona). Los pasos 1, 2 y 3 tienen una variante para ese caso.

## 0. Reconocer el trabajo de la sesion
Antes de tocar nada, repasa que se hizo en esta sesion: cambios de codigo, decisiones, bugs encontrados/arreglados, pruebas corridas, y pendientes que surgieron. Corre `git status` y `git diff --stat` para ver que quedo sin commitear y `git log --oneline` para ver los commits ya hechos en la sesion. Eso alimenta todos los pasos siguientes.

## 1. Documentar
- **En local:** actualiza la **memoria del proyecto** (el checkpoint en la carpeta de memoria; si no existe, crealo) con: estado al cierre, ultimo avance, bugs encontrados/arreglados, decisiones tomadas y los pendientes para retomar. Convierte fechas relativas a absolutas. Actualiza tambien la linea de indice en `MEMORY.md`.
- **En la nube:** NO documentes en la memoria (se pierde). Escribi lo mismo que iria al checkpoint en el repo, en `docs/handoff-AAAA-MM-DD.md` (fecha de hoy; si ya existe uno de hoy, actualizalo): estado al cierre, ultimo avance, bugs encontrados/arreglados, decisiones tomadas y pendientes para retomar, en orden de prioridad. Es lo que va a leer la proxima sesion, local o en la nube, con `/session-start`.
- Si la sesion toco un modulo o flujo con documentacion en `docs/`, actualiza el doc correspondiente (estado, resultados de pruebas, hallazgos).
- No dupliques: si ya existe un checkpoint de hoy, actualizalo en vez de crear otro.

## 2. Commit
- Revisa el diff y stagea los cambios de la sesion (incluida la documentacion commiteable del paso 1; en local la memoria del proyecto vive fuera del repo y no se commitea; en la nube el handoff de `docs/` SI se commitea).
- Genera el commit siguiendo las convenciones de `CLAUDE.md`: mensaje en español, formato `<tipo>: <descripcion corta>`, cuerpo opcional explicando el que y el porque, terminando con la linea `Co-Authored-By` que pida el harness. Usa varios commits si hay bloques logicos distintos.
- Si gitleaks bloquea, NO uses `--no-verify`: revisa que lo activo y resuelvelo.
- **En la nube gitleaks no corre.** Antes de commitear, revisa vos el diff (`git diff --cached`) buscando credenciales, tokens, API keys, passwords o contenido de un `.env`. Si encontras algo, NO commitees: saca el secreto y avisale al usuario.
- Si no hay nada que commitear, dilo y segui.

## 3. Push
- Pushea la rama actual a `origin` (`git push`, o `git push -u origin <rama>` si aun no trackea).
- Si estas en la rama principal (`main`/`master`), avisa antes de pushear.
- **En la nube** trabajas en una rama `claude/...`: el push deja el trabajo ahi y `main` no se mueve hasta que alguien la mergee. Decilo en el informe.
- Confirma el resultado: el rango de commits subidos y que el arbol quedo limpio y sincronizado con `origin`.

## 4. Despedida + informe
Cierra con un informe estructurado y una despedida breve:

## Informe de cierre — [fecha]
- **Que se hizo**: [resumen de avances/cambios]
- **Bugs encontrados / arreglados**: [lista o ninguno]
- **Commits**: [hash corto + mensaje de cada commit de esta sesion]
- **Push**: [rango subido a origin / estado de sincronizacion]
- **Rama / donde quedo el trabajo**: [rama actual; si es una sesion en la nube, recordar que hay que mergearla a `main` y si hay o no PR abierto]
- **Pruebas / verificacion**: [que se corrio y resultado]
- **Pendiente al retomar**: [lo que sigue, en orden de prioridad]
- **Notas de entorno**: [contenedores corriendo, servicios, credenciales de prueba, etc. si aplica]

Termina con una despedida corta.
