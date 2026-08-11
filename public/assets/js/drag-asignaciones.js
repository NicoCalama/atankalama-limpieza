/**
 * drag-asignaciones.js — Motor de arrastrar-y-soltar para el tablero de Asignaciones.
 *
 * Se carga SOLO desde views/asignaciones.php (no en app.js, que va en todas las
 * páginas). Expone `window.dragAsignaciones`, un mixin de objeto plano que el
 * factory `asignacionesApp()` mezcla con spread → dentro de `iniciarDrag` el
 * `this` ya es el componente Alpine (accede a data, seleccionadas, _dropEnWorker…).
 *
 * Por qué pointer events y no HTML5 draggable: `draggable` no funciona en pantallas
 * táctiles (teléfono/tablet), y el pedido es que ande con el dedo. Un solo motor
 * pointerdown/move/up/cancel cubre mouse y dedo.
 *
 * Distinción de gesto:
 *  - Mouse: el arrastre arranca al superar UMBRAL_MOUSE px; menos que eso al soltar
 *    = click → toggle de selección (multi-arrastre desde el pool).
 *  - Táctil: long-press LONGPRESS_MS con el dedo quieto = arrastre; si el dedo se
 *    mueve más de SLOP_TOUCH antes = era scroll (se cancela y la lista scrollea,
 *    porque las tarjetas tienen touch-action:pan-y). Al entrar en arrastre, la clase
 *    `arrastrando` en <body> pone touch-action:none y frena el scroll por el resto
 *    del gesto.
 *
 * El fantasma que sigue al puntero lleva pointer-events:none — imprescindible para
 * que document.elementFromPoint() vea la zona de abajo y no el propio fantasma.
 *
 * Callbacks que el componente debe implementar (viven en el factory de la vista):
 *   this._dropEnWorker(ids, workerId, origen)  → asignar (pool) o reasignar (worker→worker)
 *   this._dropEnPool(ids, origen)              → desasignar (worker→pool)
 *   this._reordenarEnWorker(workerId, roomId, indice) → reordenar la cola
 */
(function () {
    'use strict';

    var UMBRAL_MOUSE = 5;    // px: el mouse pasa de click a arrastre
    var SLOP_TOUCH   = 10;   // px de tolerancia antes del long-press (si se pasa = scroll)
    var LONGPRESS_MS = 200;  // ms de dedo quieto para iniciar arrastre táctil

    // Gesto en curso (module-level: hay UN solo arrastre a la vez, y así el estado
    // transitorio de alta frecuencia no pasa por la reactividad de Alpine).
    var G = null;

    // Marca de cuándo terminó el último gesto táctil. Los navegadores táctiles
    // sintetizan eventos de mouse ~300ms después de un toque; sin esta guarda, ese
    // "click fantasma" dispararía un segundo iniciar() y toggle-aría la selección dos veces.
    var ultimoTouchFin = 0;

    // ─── Inicio del gesto (pointerdown sobre una tarjeta [data-drag-room]) ──────
    function iniciar(componente, e) {
        // Solo botón principal del mouse.
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        // Ignorar el mouse sintético que sigue a un gesto táctil (click fantasma).
        if (e.pointerType === 'mouse' && (performance.now() - ultimoTouchFin) < 700) return;
        // Ignorar si ya hay un gesto vivo.
        if (G) return;

        var card = e.currentTarget;
        if (!card || card.getAttribute('data-drag-room') === null) return;

        var roomId  = parseInt(card.dataset.roomId, 10);
        var origen  = card.dataset.roomOrigin || 'pool';           // 'pool' | 'worker'
        var workerId = card.dataset.workerId ? parseInt(card.dataset.workerId, 10) : null;
        if (!roomId) return;

        var rect = card.getBoundingClientRect();

        // Conjunto a mover. Multi-arrastre SOLO desde el pool: si la tarjeta está
        // seleccionada y hay varias, se mueven todas las seleccionadas.
        var ids;
        var sel = componente.seleccionadas || [];
        if (origen === 'pool' && sel.indexOf(roomId) !== -1 && sel.length > 1) {
            ids = sel.slice();
        } else {
            ids = [roomId];
        }

        G = {
            componente: componente,
            card: card,
            pointerId: e.pointerId,
            tipo: e.pointerType,
            startX: e.clientX,
            startY: e.clientY,
            grabDX: e.clientX - rect.left,
            grabDY: e.clientY - rect.top,
            anchoCard: rect.width,
            roomId: roomId,
            origen: origen,
            workerId: workerId,
            ids: ids,
            multi: ids.length > 1,
            arrastrando: false,
            longPressTimer: null,
            ghost: null,
            onMove: null, onUp: null, onCancel: null, onKey: null
        };

        try { card.setPointerCapture(e.pointerId); } catch (err) {}

        G.onMove   = function (ev) { mover(ev); };
        G.onUp     = function (ev) { soltar(ev); };
        G.onCancel = function ()   { limpiar(); };
        G.onKey    = function (ev) { if (ev.key === 'Escape') limpiar(); };

        card.addEventListener('pointermove', G.onMove);
        card.addEventListener('pointerup', G.onUp);
        card.addEventListener('pointercancel', G.onCancel);
        document.addEventListener('keydown', G.onKey);

        // Táctil: esperar el long-press con el dedo quieto.
        if (G.tipo !== 'mouse') {
            G.longPressTimer = setTimeout(function () {
                G.longPressTimer = null;
                if (G) entrarEnArrastre();
            }, LONGPRESS_MS);
        }
    }

    // ─── Movimiento (pointermove) ──────────────────────────────────────────────
    function mover(e) {
        if (!G) return;
        var dx = Math.abs(e.clientX - G.startX);
        var dy = Math.abs(e.clientY - G.startY);

        if (!G.arrastrando) {
            if (G.tipo === 'mouse') {
                // Mouse: arrancar arrastre al superar el umbral.
                if (dx > UMBRAL_MOUSE || dy > UMBRAL_MOUSE) entrarEnArrastre();
            } else {
                // Táctil: si se mueve antes del long-press, era scroll → abortar.
                if ((dx > SLOP_TOUCH || dy > SLOP_TOUCH) && G.longPressTimer) {
                    limpiar();
                }
            }
            if (!G.arrastrando) return;
        }

        e.preventDefault();
        posicionarFantasma(e.clientX, e.clientY);
        resaltarZona(e.clientX, e.clientY);
    }

    // ─── Soltar (pointerup) ────────────────────────────────────────────────────
    function soltar(e) {
        if (!G) return;

        // Si nunca entró en arrastre: fue un tap/click → toggle de selección (pool).
        if (!G.arrastrando) {
            var comp = G.componente, rid = G.roomId, org = G.origen;
            limpiar();
            if (org === 'pool' && comp && typeof comp.toggleSeleccion === 'function') {
                comp.toggleSeleccion(rid);
            }
            return;
        }

        var destino = zonaBajoPunto(e.clientX, e.clientY);
        var comp2 = G.componente;
        var ids = G.ids.slice();
        var origen = G.origen;
        var roomId = G.roomId;
        var workerOrigen = G.workerId;

        limpiar();
        if (!destino) return;

        if (destino.tipo === 'worker') {
            if (origen === 'pool') {
                comp2._dropEnWorker(ids, destino.workerId, 'pool');
            } else if (origen === 'worker' && destino.workerId === workerOrigen) {
                comp2._reordenarEnWorker(workerOrigen, roomId, destino.indice);
            } else if (origen === 'worker') {
                comp2._dropEnWorker([roomId], destino.workerId, 'worker');
            }
        } else if (destino.tipo === 'pool') {
            if (origen === 'worker') {
                comp2._dropEnPool([roomId], 'worker');
            }
            // pool → pool: nada.
        }
    }

    // ─── Entrar en modo arrastre (crea el fantasma, frena el scroll) ────────────
    function entrarEnArrastre() {
        if (!G || G.arrastrando) return;
        G.arrastrando = true;
        if (G.longPressTimer) { clearTimeout(G.longPressTimer); G.longPressTimer = null; }
        document.body.classList.add('arrastrando');
        crearFantasma();
        posicionarFantasma(G.startX, G.startY);
    }

    // ─── Fantasma ──────────────────────────────────────────────────────────────
    function crearFantasma() {
        var ghost = G.card.cloneNode(true);
        ghost.removeAttribute('data-drag-room');
        ghost.classList.add('vg-arrastre-fantasma');
        ghost.style.width = G.anchoCard + 'px';
        if (G.multi) {
            var badge = document.createElement('span');
            badge.className = 'vg-arrastre-contador';
            badge.textContent = G.ids.length;
            ghost.appendChild(badge);
        }
        document.body.appendChild(ghost);
        G.ghost = ghost;
    }

    function posicionarFantasma(x, y) {
        if (!G || !G.ghost) return;
        G.ghost.style.left = (x - G.grabDX) + 'px';
        G.ghost.style.top  = (y - G.grabDY) + 'px';
    }

    // ─── Resaltado de la zona candidata durante el movimiento ──────────────────
    function resaltarZona(x, y) {
        quitarResaltados();
        var destino = zonaBajoPunto(x, y);
        if (!destino || !destino.zonaEl) return;

        // No resaltar el origen inútil (soltar en el pool viniendo del pool no hace nada).
        if (destino.tipo === 'pool' && G.origen === 'pool') return;

        destino.zonaEl.classList.add('drop-activa');

        // Reordenar dentro del mismo trabajador: marcar la línea de inserción.
        if (destino.tipo === 'worker' && G.origen === 'worker' && destino.workerId === G.workerId) {
            marcarInsercion(destino.zonaEl, destino.indice);
        }
    }

    function quitarResaltados() {
        var i, activos = document.querySelectorAll('.drop-activa');
        for (i = 0; i < activos.length; i++) activos[i].classList.remove('drop-activa');
        var slots = document.querySelectorAll('.slot-insert-antes, .slot-insert-fin');
        for (i = 0; i < slots.length; i++) {
            slots[i].classList.remove('slot-insert-antes');
            slots[i].classList.remove('slot-insert-fin');
        }
    }

    function marcarInsercion(zonaEl, indice) {
        var cola = zonaEl.querySelector('[data-cola]');
        if (!cola) return;
        var slots = cola.querySelectorAll('[data-room-slot]');
        if (!slots.length) return;
        if (indice >= slots.length) {
            slots[slots.length - 1].classList.add('slot-insert-fin');
        } else {
            slots[indice].classList.add('slot-insert-antes');
        }
    }

    // ─── ¿Qué hay bajo el puntero? ─────────────────────────────────────────────
    // El fantasma tiene pointer-events:none → elementFromPoint devuelve lo de abajo.
    function zonaBajoPunto(x, y) {
        var el = document.elementFromPoint(x, y);
        if (!el) return null;
        var zona = el.closest ? el.closest('[data-drop]') : null;
        if (!zona) return null;

        var tipo = zona.dataset.drop; // 'worker' | 'pool'
        if (tipo === 'worker') {
            var workerId = parseInt(zona.dataset.workerId, 10);
            var indice = calcularIndiceInsercion(zona, y);
            return { tipo: 'worker', workerId: workerId, indice: indice, zonaEl: zona };
        }
        if (tipo === 'pool') {
            return { tipo: 'pool', zonaEl: zona };
        }
        return null;
    }

    // Índice de inserción en la cola de un trabajador según la posición vertical.
    function calcularIndiceInsercion(zonaEl, y) {
        var cola = zonaEl.querySelector('[data-cola]');
        if (!cola) return 0;
        var slots = cola.querySelectorAll('[data-room-slot]');
        for (var i = 0; i < slots.length; i++) {
            var r = slots[i].getBoundingClientRect();
            if (y < r.top + r.height / 2) return i;
        }
        return slots.length;
    }

    // ─── Limpieza del gesto ────────────────────────────────────────────────────
    function limpiar() {
        if (!G) return;
        if (G.tipo !== 'mouse') ultimoTouchFin = performance.now(); // arma la guarda anti click-fantasma
        if (G.longPressTimer) { clearTimeout(G.longPressTimer); G.longPressTimer = null; }
        if (G.ghost && G.ghost.parentNode) G.ghost.parentNode.removeChild(G.ghost);
        quitarResaltados();
        document.body.classList.remove('arrastrando');
        try { if (G.pointerId != null) G.card.releasePointerCapture(G.pointerId); } catch (err) {}
        G.card.removeEventListener('pointermove', G.onMove);
        G.card.removeEventListener('pointerup', G.onUp);
        G.card.removeEventListener('pointercancel', G.onCancel);
        document.removeEventListener('keydown', G.onKey);
        G = null;
    }

    // ─── Mixin expuesto ────────────────────────────────────────────────────────
    window.dragAsignaciones = {
        // Enlazado con @pointerdown en cada tarjeta arrastrable del tablero.
        iniciarDrag: function (e) { iniciar(this, e); }
    };
})();
