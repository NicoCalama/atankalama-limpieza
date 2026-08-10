/* Vista Guiada v3.0 — Motor de ayuda guiada por TAREA para stack moderno.
 * ---------------------------------------------------------------------------
 * Sucesor portable de "TourGuiado v2.0" (Rodrigo Jaque). Escrito para apps
 * Laravel + Livewire + Tailwind con Content-Security-Policy ESTRICTA
 * (script-src 'self' — sin 'unsafe-inline'). Cero dependencias, cero CDN.
 *
 * Qué cambia respecto del motor original (los 4 bloqueos verificados):
 *   1. Sin Bootstrap. El descubrimiento es un punto pulsante en el botón "?".
 *   2. Posiciona con getBoundingClientRect() + overlays position:fixed. NO usa
 *      window.scrollY (acá el scroll es del contenedor <main>, no de la
 *      ventana). Aguanta scroll anidado y horizontal.
 *   3. Nunca guarda una referencia a un elemento: re-consulta el selector en
 *      cada repintado, con verificación de nulo y reintentos escalonados. Un
 *      refresco de Livewire a media guía no deja la app inservible.
 *   4. CSP-safe: pinta SIEMPRE con textContent + createElement + addEventListener.
 *      Cero HTML crudo, cero atributos de evento inline, cero eval. Los textos viajan en un
 *      atributo data-* (no en <script>), así no rompen el canario de seguridad.
 *
 * Modelo de datos: RECORRIDOS PLANOS. Una pantalla NO tiene "un tour": tiene
 * una lista de recorridos por tarea. El botón "?" abre un selector y el
 * usuario elige la tarea que responde SU pregunta. Tres niveles y nada más:
 *   pantalla -> recorrido -> paso.
 *
 * El motor NO decide qué mostrar: lo decide el SERVIDOR.
 *   - El catálogo filtrado por rol viaja en  #vg-root[data-vg-payload] (JSON).
 *   - Las precondiciones (banderas booleanas) viajan en [data-vg-context] (JSON),
 *     calculadas en PHP. Bandera ausente/desconocida => FALSA (fail-closed).
 *
 * API pública (por si la necesitas):
 *   VistaGuiada.boot();               // idempotente; se auto-invoca
 *   VistaGuiada.abrir();              // abre el selector
 *   VistaGuiada.iniciar(idRecorrido); // arranca un recorrido por código
 *   VistaGuiada.cerrar();             // cierra lo que esté abierto
 */
(function () {
    'use strict';

    // ── Constantes de la UI ──────────────────────────────────────────────
    var MAX_DISPONIBLES = 4;                 // techo de filas en el selector
    var REINTENTOS_MS   = [0, 150, 400, 1000]; // escalonado para anclas tardías

    // ── Estado (todo se reconstruye en cada arranque) ────────────────────
    var payload = null;   // { esquema, pantalla, u, recorridos:[...] }
    var recorrido = null; // el recorrido en curso
    var pasos = [];       // pasos efectivos del recorrido en curso
    var idx = 0;
    var activo = false;
    var esperandoDialogo = false; // true mientras se espera que aparezca un modal
    var refuerzo = null;  // id de setInterval de reposición de respaldo

    // Nodos que el motor monta en <body> (fuera de todo componente Livewire).
    var fab = null, punto = null, panel = null;
    var velo = null;      // { top, right, bottom, left, ring } — 4 paneles + anillo
    var caja = null;      // la burbuja del paso
    var franja = null;    // instrucción "abre el diálogo y la ayuda sigue sola"
    var focoPrevio = null;

    // ── localStorage seguro (modo privado no revienta) ───────────────────
    var ls = {
        get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* no-op */ } }
    };

    // Clave de "visto" por recorrido, versionada y por usuario (tablet compartida).
    function claveVisto(r) {
        return 'vg_seen_' + payload.pantalla + '_' + r.id + '_v' + (r.v || 1) + '_u' + (payload.u || 0);
    }
    function estaVisto(r) { return ls.get(claveVisto(r)) === '1'; }
    function marcarVisto(r) { ls.set(claveVisto(r), '1'); }

    // ── Banderas: se leen del DOM (las calculó PHP), fail-closed ─────────
    function leerBanderas() {
        var flags = {};
        var nodos = document.querySelectorAll('[data-vg-context]');
        for (var i = 0; i < nodos.length; i++) {
            var raw = nodos[i].getAttribute('data-vg-context');
            if (!raw) continue;
            try {
                var obj = JSON.parse(raw);
                for (var k in obj) { if (Object.prototype.hasOwnProperty.call(obj, k)) flags[k] = !!obj[k]; }
            } catch (e) { /* JSON malo => se ignora, las banderas quedan falsas */ }
        }
        return flags;
    }

    // Evalúa una lista de requisitos ("flag" debe ser true, "!flag" debe ser false).
    // Devuelve el PRIMER token que falla (para mostrar su motivo), o null si pasa.
    function primerRequisitoRoto(requiere, flags) {
        if (!requiere || !requiere.length) return null;
        for (var i = 0; i < requiere.length; i++) {
            var token = requiere[i];
            var negado = token.charAt(0) === '!';
            var nombre = negado ? token.slice(1) : token;
            var valor = flags[nombre] === true; // ausente => false (fail-closed)
            var ok = negado ? !valor : valor;
            if (!ok) return token;
        }
        return null;
    }

    function recorridoDisponible(r, flags) {
        return primerRequisitoRoto(r.requiere, flags) === null;
    }

    function motivoDe(r, flags) {
        var roto = primerRequisitoRoto(r.requiere, flags);
        if (roto === null) return null;
        if (r.motivos) {
            // El motivo puede estar keyed por el token del requisito ('tiene_cierre')
            // O por el estado que bloquea, que es su negación ('!tiene_cierre').
            // Aceptamos ambas convenciones para que el catálogo no dependa de un
            // detalle sutil de signo.
            if (r.motivos[roto]) return r.motivos[roto];
            var neg = roto.charAt(0) === '!' ? roto.slice(1) : '!' + roto;
            if (r.motivos[neg]) return r.motivos[neg];
        }
        return 'Este recorrido no está disponible ahora mismo.';
    }

    // ── Pintado CSP-safe ──────────────────────────────────────────────────
    // Único formato permitido: «…» -> <strong>. Se arma con nodos, sin HTML crudo.
    function pintarTexto(destino, texto) {
        var partes = String(texto).split(/(«[^»]*»)/);
        for (var i = 0; i < partes.length; i++) {
            var p = partes[i];
            if (!p) continue;
            if (p.charAt(0) === '«' && p.charAt(p.length - 1) === '»') {
                var strong = document.createElement('strong');
                strong.textContent = p.slice(1, -1);
                destino.appendChild(strong);
            } else {
                destino.appendChild(document.createTextNode(p));
            }
        }
    }

    function el(tag, clase, texto) {
        var n = document.createElement(tag);
        if (clase) n.className = clase;
        if (texto != null) n.textContent = texto;
        return n;
    }

    // ── Botón flotante "?" + punto pulsante ──────────────────────────────
    function montarFab() {
        fab = el('button', 'vg-fab');
        fab.type = 'button';
        fab.textContent = '?';
        fab.setAttribute('aria-label', 'Ayuda de esta pantalla');
        fab.setAttribute('aria-haspopup', 'menu');
        fab.dataset.vgBound = '1';
        punto = el('span', 'vg-fab-punto');
        punto.setAttribute('aria-hidden', 'true');
        punto.hidden = true;
        fab.appendChild(punto);
        fab.addEventListener('click', onFab);
        document.body.appendChild(fab);
    }

    function actualizarPunto() {
        if (!punto || !payload) return;
        var flags = leerBanderas();
        var hayNuevo = false;
        for (var i = 0; i < payload.recorridos.length; i++) {
            var r = payload.recorridos[i];
            if (recorridoDisponible(r, flags) && !estaVisto(r)) { hayNuevo = true; break; }
        }
        punto.hidden = !hayNuevo;
    }

    function onFab() {
        if (panel) { cerrarPanel(); return; }
        if (activo) { cerrar(); return; }
        var flags = leerBanderas();
        var disponibles = payload.recorridos.filter(function (r) { return recorridoDisponible(r, flags); });
        // Una pantalla con UN solo recorrido disponible arranca directo, sin selector.
        if (disponibles.length === 1 && payload.recorridos.length === 1) {
            iniciar(disponibles[0].id);
        } else {
            abrirPanel();
        }
    }

    // ── Selector de recorridos (la "hoja" anclada sobre el botón) ────────
    function abrirPanel() {
        cerrarPanel();
        var flags = leerBanderas();
        var disponibles = [], bloqueados = [];
        payload.recorridos.forEach(function (r) {
            (recorridoDisponible(r, flags) ? disponibles : bloqueados).push(r);
        });

        panel = el('div', 'vg-panel');
        panel.setAttribute('role', 'menu');
        panel.setAttribute('aria-label', 'Recorridos de ayuda');

        var cab = el('div', 'vg-panel-cab');
        cab.appendChild(el('span', 'vg-panel-titulo', '¿Con qué te ayudo?'));
        var cerrar = el('button', 'vg-panel-x');
        cerrar.type = 'button';
        cerrar.setAttribute('aria-label', 'Cerrar');
        cerrar.textContent = '×';
        cerrar.addEventListener('click', cerrarPanel);
        cab.appendChild(cerrar);
        panel.appendChild(cab);

        if (payload.nombrePantalla) {
            panel.appendChild(el('div', 'vg-panel-sub', payload.nombrePantalla));
        }

        // El orden NUNCA cambia por estado de visto: memoria muscular.
        disponibles.slice(0, MAX_DISPONIBLES).forEach(function (r) {
            panel.appendChild(filaRecorrido(r, false, null));
        });

        // Los que se pasan del techo también se pliegan (raro, pero honesto).
        var extra = disponibles.slice(MAX_DISPONIBLES);
        var plegables = bloqueados.concat(extra.map(function (r) { return r; }));
        if (plegables.length) {
            panel.appendChild(plegado(plegables, flags, extra));
        }

        document.body.appendChild(panel);
        document.addEventListener('keydown', onTeclaPanel, true);
        setTimeout(function () { document.addEventListener('click', onClickFuera, true); }, 0);
        var primera = panel.querySelector('.vg-fila');
        if (primera) primera.focus();
    }

    function filaRecorrido(r, bloqueado, motivo) {
        var fila = el('button', 'vg-fila' + (bloqueado ? ' vg-fila-bloq' : ''));
        fila.type = 'button';
        fila.setAttribute('role', 'menuitem');

        var marca = el('span', 'vg-fila-marca');
        if (!bloqueado) marca.textContent = estaVisto(r) ? '✓' : '●'; // ✓ / ●
        marca.setAttribute('aria-hidden', 'true');
        fila.appendChild(marca);

        var cuerpo = el('span', 'vg-fila-cuerpo');
        cuerpo.appendChild(el('span', 'vg-fila-titulo', r.titulo));
        if (bloqueado && motivo) {
            cuerpo.appendChild(el('span', 'vg-fila-motivo', motivo));
        } else {
            cuerpo.appendChild(el('span', 'vg-fila-pregunta', r.pregunta || ''));
        }
        fila.appendChild(cuerpo);

        if (!bloqueado) {
            fila.appendChild(el('span', 'vg-fila-flecha', '›')); // ›
            fila.addEventListener('click', function () { cerrarPanel(); iniciar(r.id); });
        } else {
            fila.disabled = true;
        }
        return fila;
    }

    function plegado(lista, flags, extraDisponibles) {
        var cont = el('div', 'vg-plegado');
        var toggle = el('button', 'vg-plegado-toggle');
        toggle.type = 'button';
        toggle.textContent = 'Otras ' + lista.length + ' ' +
            (lista.length === 1 ? 'ayuda' : 'ayudas') + ' (no disponibles ahora) ▾';
        var cuerpo = el('div', 'vg-plegado-cuerpo');
        cuerpo.hidden = true;
        lista.forEach(function (r) {
            var esExtra = extraDisponibles.indexOf(r) !== -1;
            var motivo = esExtra ? 'Cabe en la lista de arriba una vez que cierres otra.' : motivoDe(r, flags);
            cuerpo.appendChild(filaRecorrido(r, true, motivo));
        });
        toggle.addEventListener('click', function () {
            cuerpo.hidden = !cuerpo.hidden;
        });
        cont.appendChild(toggle);
        cont.appendChild(cuerpo);
        return cont;
    }

    function onTeclaPanel(e) { if (e.key === 'Escape') cerrarPanel(); }
    function onClickFuera(e) {
        if (panel && !panel.contains(e.target) && e.target !== fab) cerrarPanel();
    }
    function cerrarPanel() {
        if (!panel) return;
        document.removeEventListener('keydown', onTeclaPanel, true);
        document.removeEventListener('click', onClickFuera, true);
        panel.remove();
        panel = null;
        if (fab) fab.focus();
    }

    // ── Ejecución de un recorrido ────────────────────────────────────────
    function iniciar(idRecorrido) {
        cerrarPanel();
        if (activo) cerrar();
        if (!payload) return;
        recorrido = null;
        for (var i = 0; i < payload.recorridos.length; i++) {
            if (payload.recorridos[i].id === idRecorrido) { recorrido = payload.recorridos[i]; break; }
        }
        if (!recorrido) return;

        var flags = leerBanderas();
        // Si el recorrido quedó bloqueado, abre el panel y muestra el motivo
        // en vez de arrancar para morirse en el paso 1.
        if (!recorridoDisponible(recorrido, flags)) { abrirPanel(); return; }

        // Filtra los pasos por su propio requisito (banderas). Los pasos de
        // diálogo se conservan: su ancla aparece cuando el usuario abre el modal.
        pasos = (recorrido.pasos || []).filter(function (p) {
            return primerRequisitoRoto(p.requiere, flags) === null;
        });
        if (!pasos.length) return;

        focoPrevio = document.activeElement;
        activo = true;
        idx = 0;
        document.body.classList.add('vg-activo');
        montarVelo();
        montarCaja();
        window.addEventListener('resize', reposicionar);
        window.addEventListener('scroll', reposicionar, true); // capture: agarra el scroll de <main>
        document.addEventListener('keydown', onTeclaPaso, true);
        refuerzo = setInterval(reposicionar, 400); // respaldo por si algo mueve el layout sin evento
        mostrarPaso();
    }

    function cerrar() {
        if (panel) cerrarPanel();
        if (!activo) return;
        activo = false;
        esperandoDialogo = false;
        document.body.classList.remove('vg-activo');
        window.removeEventListener('resize', reposicionar);
        window.removeEventListener('scroll', reposicionar, true);
        document.removeEventListener('keydown', onTeclaPaso, true);
        if (refuerzo) { clearInterval(refuerzo); refuerzo = null; }
        limpiarObservador();   // el observador de diálogo pudo quedar armado esperando un modal
        desmontarVelo();
        if (caja) { caja.remove(); caja = null; }
        if (franja) { franja.remove(); franja = null; }
        if (focoPrevio && focoPrevio.focus) { try { focoPrevio.focus(); } catch (e) {} }
        actualizarPunto();
    }

    function esEditable(nodo) {
        if (!nodo) return false;
        var tag = nodo.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || nodo.isContentEditable === true;
    }

    function onTeclaPaso(e) {
        if (e.key === 'Escape') { cerrar(); e.stopPropagation(); return; }
        // Las flechas navegan el recorrido — PERO si el foco está en un campo
        // editable (p.ej. el input del modal que el propio paso te pidió llenar),
        // se dejan pasar para mover el cursor, no para saltar de paso.
        if (e.key === 'ArrowRight') {
            if (esEditable(e.target)) return;
            e.preventDefault(); e.stopPropagation(); avanzar();
        } else if (e.key === 'ArrowLeft') {
            if (esEditable(e.target)) return;
            e.preventDefault(); e.stopPropagation(); retroceder();
        }
    }

    function avanzar() {
        if (idx >= pasos.length - 1) { finalizar(); return; }
        idx++;
        mostrarPaso();
    }
    function retroceder() {
        if (idx <= 0) return;
        idx--;
        mostrarPaso();
    }
    function finalizar() {
        if (recorrido) marcarVisto(recorrido);
        cerrar();
    }

    function mostrarPaso() {
        var paso = pasos[idx];
        esperandoDialogo = false;
        if (franja) { franja.remove(); franja = null; }

        if (paso.modo === 'dialogo') {
            // Mini-tour dentro de un modal: no lo abrimos nosotros (la ayuda
            // mira, no toca). Si el modal YA está abierto (p.ej. dos pasos de
            // diálogo seguidos), enganchamos directo; si no, mostramos la
            // franja y esperamos a que el ancla aparezca (por el hook de
            // Livewire o el observador de respaldo).
            if (document.querySelector(paso.sel)) {
                mostrarVelo();
                dibujarCaja(paso, false);
                reposicionar();
                return;
            }
            if (caja) caja.style.display = 'none'; // esconde la burbuja anterior mientras se espera
            mostrarFranja(paso);
            esperandoDialogo = true;
            ocultarVelo();
            armarEsperaDialogo(paso);
            return;
        }
        anclarEnPaso(paso, 0);
    }

    function anclarEnPaso(paso, intento) {
        var elx = document.querySelector(paso.sel);
        if (elx) {
            mostrarVelo();
            try { elx.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (e) {}
            dibujarCaja(paso, false);
            setTimeout(reposicionar, 260); // deja terminar el scroll suave antes de medir
            reposicionar();
            return;
        }
        if (intento < REINTENTOS_MS.length - 1) {
            setTimeout(function () {
                if (activo && pasos[idx] === paso) anclarEnPaso(paso, intento + 1);
            }, REINTENTOS_MS[intento + 1]);
            return;
        }
        // Se rindió: ancla ausente. En vez de trabarse, ofrece salida limpia.
        ocultarVelo();
        dibujarCaja(paso, true);
    }

    // ── Diálogos: esperar a que aparezca el ancla del modal ──────────────
    var observadorDialogo = null;
    function armarEsperaDialogo(paso) {
        limpiarObservador();
        var listo = function () {
            if (!activo || !esperandoDialogo || pasos[idx] !== paso) return false;
            var elx = document.querySelector(paso.sel);
            if (!elx) return false;
            limpiarObservador();
            esperandoDialogo = false;
            if (franja) { franja.remove(); franja = null; }
            mostrarVelo();
            dibujarCaja(paso, false);
            reposicionar();
            return true;
        };
        if (listo()) return;
        // Observador de respaldo (sirve aunque no haya Livewire). El hook
        // 'morphed' llama a esto también, pero el observador cubre modales
        // que no vengan de un refresco de componente.
        observadorDialogo = new MutationObserver(function () { listo(); });
        observadorDialogo.observe(document.body, { childList: true, subtree: true });
    }
    function limpiarObservador() {
        if (observadorDialogo) { observadorDialogo.disconnect(); observadorDialogo = null; }
    }

    function mostrarFranja(paso) {
        franja = el('div', 'vg-franja');
        franja.setAttribute('role', 'status');
        var t = el('div', 'vg-franja-texto');
        pintarTexto(t, paso.abrir || 'Abre el cuadro y la ayuda sigue sola.');
        franja.appendChild(t);
        var salir = el('button', 'vg-franja-salir', 'Salir');
        salir.type = 'button';
        salir.addEventListener('click', cerrar);
        franja.appendChild(salir);
        document.body.appendChild(franja);
    }

    // ── Velo de 4 paneles + anillo (deja tocable el elemento explicado) ──
    function montarVelo() {
        velo = {
            top: el('div', 'vg-velo vg-velo-top'),
            right: el('div', 'vg-velo vg-velo-right'),
            bottom: el('div', 'vg-velo vg-velo-bottom'),
            left: el('div', 'vg-velo vg-velo-left'),
            ring: el('div', 'vg-anillo')
        };
        for (var k in velo) { if (velo.hasOwnProperty(k)) document.body.appendChild(velo[k]); }
        ocultarVelo();
    }
    function desmontarVelo() {
        if (!velo) return;
        for (var k in velo) { if (velo.hasOwnProperty(k) && velo[k]) velo[k].remove(); }
        velo = null;
    }
    function mostrarVelo() { if (velo) for (var k in velo) if (velo.hasOwnProperty(k)) velo[k].style.display = 'block'; }
    function ocultarVelo() { if (velo) for (var k in velo) if (velo.hasOwnProperty(k)) velo[k].style.display = 'none'; }

    function colocarVelo(r) {
        if (!velo) return;
        var m = 6; // margen alrededor del elemento
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;
        // Si el ancla salió del viewport (el usuario hizo scroll), NO pintes un
        // velo negro a pantalla completa sin hueco: apágalo y deja sólo la
        // burbuja operable. Se re-enciende cuando el elemento vuelve a la vista.
        var visible = r.bottom > 0 && r.right > 0 && r.top < vh && r.left < vw && r.width > 0 && r.height > 0;
        if (!visible) { ocultarVelo(); return; }
        mostrarVelo();
        var top = Math.min(vh, Math.max(0, r.top - m));
        var left = Math.min(vw, Math.max(0, r.left - m));
        var right = Math.max(0, Math.min(vw, r.right + m));
        var bottom = Math.max(0, Math.min(vh, r.bottom + m));
        // Paneles fijos que rodean el hueco (el hueco queda tocable).
        set(velo.top, 0, 0, vw, top);
        set(velo.bottom, 0, bottom, vw, vh - bottom);
        set(velo.left, 0, top, left, bottom - top);
        set(velo.right, right, top, vw - right, bottom - top);
        // Anillo sobre el borde del hueco (no lo dibujes si quedó degenerado).
        velo.ring.style.left = left + 'px';
        velo.ring.style.top = top + 'px';
        velo.ring.style.width = Math.max(0, right - left) + 'px';
        velo.ring.style.height = Math.max(0, bottom - top) + 'px';
    }
    function set(node, x, y, w, h) {
        node.style.left = x + 'px'; node.style.top = y + 'px';
        node.style.width = Math.max(0, w) + 'px'; node.style.height = Math.max(0, h) + 'px';
    }

    // ── La burbuja del paso ──────────────────────────────────────────────
    function montarCaja() {
        caja = el('div', 'vg-caja');
        caja.setAttribute('role', 'dialog');
        // OJO: nada de aria-labelledby aquí. El nombre accesible se fija en
        // dibujarCaja con aria-label "Paso X de Y: <título>" (aria-labelledby
        // GANARÍA sobre aria-label y el lector nunca anunciaría el progreso).
        // Sin trampa de foco a propósito (requisito 8 del diseño): así puedes
        // llegar con Tab al elemento que te están explicando.
        caja.tabIndex = -1;
        document.body.appendChild(caja);
    }

    function dibujarCaja(paso, anclaAusente) {
        // Reconstruye la burbuja entera (CSP-safe, sin HTML crudo).
        while (caja.firstChild) caja.removeChild(caja.firstChild);

        var x = el('button', 'vg-caja-x');
        x.type = 'button';
        x.setAttribute('aria-label', 'Salir');
        x.textContent = '×';
        x.addEventListener('click', cerrar);
        caja.appendChild(x);

        var titulo = el('div', 'vg-caja-titulo');
        titulo.id = 'vg-caja-titulo';
        if (anclaAusente) {
            titulo.textContent = 'Este paso apunta a algo que ahora no está en pantalla';
        } else {
            titulo.textContent = paso.titulo || '';
        }
        caja.appendChild(titulo);

        var texto = el('div', 'vg-caja-texto');
        if (anclaAusente) {
            texto.textContent = 'Puedes saltarlo o salir de la ayuda.';
        } else {
            pintarTexto(texto, paso.texto || '');
        }
        caja.appendChild(texto);

        var pie = el('div', 'vg-caja-pie');
        pie.appendChild(el('span', 'vg-caja-contador', (idx + 1) + ' de ' + pasos.length));
        var acciones = el('span', 'vg-caja-acciones');

        if (anclaAusente) {
            var saltar = el('button', 'vg-btn vg-btn-2', 'Saltar');
            saltar.type = 'button';
            saltar.addEventListener('click', avanzar);
            var salir = el('button', 'vg-btn', 'Salir');
            salir.type = 'button';
            salir.addEventListener('click', cerrar);
            acciones.appendChild(saltar);
            acciones.appendChild(salir);
        } else {
            if (idx > 0) {
                var prev = el('button', 'vg-btn vg-btn-2', 'Anterior');
                prev.type = 'button';
                prev.addEventListener('click', retroceder);
                acciones.appendChild(prev);
            }
            var ultimo = idx === pasos.length - 1;
            var next = el('button', 'vg-btn', ultimo ? 'Entendido' : 'Siguiente');
            next.type = 'button';
            next.addEventListener('click', avanzar);
            acciones.appendChild(next);
        }
        pie.appendChild(acciones);
        caja.appendChild(pie);

        caja.style.display = 'block';
        // Nombre accesible = progreso + título, en aria-label (sin aria-labelledby
        // que lo taparía). El foco va a la burbuja en cada paso, así el lector
        // anuncia "Paso X de Y: <título>".
        var nombre = 'Paso ' + (idx + 1) + ' de ' + pasos.length + ': ' +
            (anclaAusente ? 'este paso no está en pantalla' : (paso.titulo || ''));
        caja.setAttribute('aria-label', nombre);
        try { caja.focus(); } catch (e) {}
    }

    // ── Reposición: viewport-relative, se re-consulta el selector cada vez ─
    function reposicionar() {
        if (!activo || !pasos.length) return;
        var paso = pasos[idx];
        if (!paso || paso.modo === 'dialogo' && esperandoDialogo) return;
        var elx = document.querySelector(paso.sel);
        if (!elx) { return; } // no rompas: el próximo tick o el hook lo reintenta
        var r = elx.getBoundingClientRect();
        colocarVelo(r);
        colocarCaja(r);
    }

    function colocarCaja(r) {
        if (!caja || caja.style.display === 'none') return;
        var m = 12;
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;
        var cw = caja.offsetWidth || 320;
        var ch = caja.offsetHeight || 150;

        // En pantallas chicas: fija abajo, a lo ancho (lo remata el CSS).
        // El umbral debe calzar EXACTO con el @media del CSS (max-width:575.98px).
        if (vw <= 575.98) { caja.style.left = ''; caja.style.top = ''; return; }

        var top, left;
        if (r.bottom + m + ch < vh - 8) {
            top = r.bottom + m;                 // debajo del elemento
        } else if (r.top - m - ch > 8) {
            top = r.top - m - ch;               // encima si no cabe abajo
        } else {
            top = Math.max(8, vh - ch - 8);     // pegada abajo del viewport
        }
        left = r.left;
        if (left + cw > vw - 8) left = Math.max(8, vw - cw - 8);
        caja.style.top = top + 'px';
        caja.style.left = left + 'px';
    }

    // ── Enganche a Livewire (opcional) + arranque idempotente ────────────
    function boot() {
        var root = document.getElementById('vg-root');
        if (!root || !root.getAttribute('data-vg-payload')) {
            // No hay ayuda para esta pantalla: no montes nada.
            var viejo = document.querySelector('.vg-fab');
            if (viejo) viejo.remove();
            payload = null;
            return;
        }
        try {
            payload = JSON.parse(root.getAttribute('data-vg-payload'));
        } catch (e) { payload = null; return; }
        if (!payload || !payload.recorridos || !payload.recorridos.length) { payload = null; return; }

        // SIEMPRE re-montamos el botón. Con wire:navigate (atrás/adelante) el
        // nodo se restaura desde la caché de Livewire CON su atributo pero SIN
        // el listener de click (los handlers no sobreviven a serializar HTML).
        // No confíes en un atributo serializable para saber si el handler vive.
        var existente = document.querySelector('.vg-fab');
        if (existente) existente.remove();
        montarFab();
        actualizarPunto();
    }

    function onMorphed() {
        // El DOM se refrescó (Livewire), NO navegó. Dos cosas:
        if (activo) reposicionar();                 // 1. re-mide el resaltado
        if (esperandoDialogo && pasos[idx]) {       // 2. ¿apareció el modal?
            var elx = document.querySelector(pasos[idx].sel);
            if (elx) mostrarPaso();
        }
        // NO reconstruimos el panel abierto: el catálogo no cambia en un morph,
        // y rehacerlo cada 30 s (KDS) colapsaría el bloque "Otras N ayudas" y
        // robaría el foco mientras el usuario lee un motivo.
        actualizarPunto();
    }

    // Registro de Livewire DENTRO de livewire:init (hay páginas sin Livewire).
    document.addEventListener('livewire:init', function () {
        if (typeof Livewire !== 'undefined' && Livewire.hook) {
            Livewire.hook('morphed', onMorphed);
        }
    });

    // Al NAVEGAR con wire:navigate: desmonta el recorrido ANTES del morph (si no,
    // queda un tour zombie con su intervalo y listeners vivos en la pág. nueva)…
    document.addEventListener('livewire:navigating', cerrar);
    // …y al aterrizar, limpia de nuevo (belt-and-suspenders) y re-monta el botón
    // (con wire:navigate el nodo puede volver de caché sin su listener).
    document.addEventListener('livewire:navigated', function () { cerrar(); boot(); });
    // DOMContentLoaded es la carga fresca: sólo montar, nada que desmontar.
    document.addEventListener('DOMContentLoaded', boot);

    // ── API pública ──────────────────────────────────────────────────────
    window.VistaGuiada = {
        boot: boot,
        abrir: abrirPanel,
        iniciar: iniciar,
        cerrar: cerrar
    };
})();
