# -*- coding: utf-8 -*-
"""Genera los 3 manuales de usuario (Trabajador, Supervisora, Recepción) en PDF.
Estilo limpio con reportlab. Español chileno. Sin emojis (reportlab no los dibuja)."""

import os
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import cm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.platypus import (
    BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, Table, TableStyle,
    ListFlowable, ListItem, HRFlowable
)

OUT_DIR = r"C:\Proyectos\atankalama-limpieza\manuales"
os.makedirs(OUT_DIR, exist_ok=True)

# --- Paleta (alineada con la app: azul #2563eb, gris oscuro #111827) ---
AZUL = colors.HexColor("#2563eb")
AZUL_OSC = colors.HexColor("#1e40af")
GRIS_TXT = colors.HexColor("#1f2937")
GRIS_SUAVE = colors.HexColor("#6b7280")
BLANCO = colors.white

CALLOUTS = {
    "tip":        (colors.HexColor("#eff6ff"), colors.HexColor("#2563eb"), "Tip"),
    "importante": (colors.HexColor("#fffbeb"), colors.HexColor("#d97706"), "Importante"),
    "ojo":        (colors.HexColor("#fef2f2"), colors.HexColor("#dc2626"), "Ojo"),
    "nota":       (colors.HexColor("#f3f4f6"), colors.HexColor("#6b7280"), "Nota"),
}

MARGEN = 1.7 * cm
PAGE_W, PAGE_H = A4
USABLE_W = PAGE_W - 2 * MARGEN

styles = getSampleStyleSheet()
S = {}
S["h1"] = ParagraphStyle("h1", parent=styles["Heading1"], fontName="Helvetica-Bold",
                         fontSize=15, textColor=AZUL, spaceBefore=16, spaceAfter=7, leading=19)
S["h2"] = ParagraphStyle("h2", parent=styles["Heading2"], fontName="Helvetica-Bold",
                         fontSize=11.5, textColor=GRIS_TXT, spaceBefore=10, spaceAfter=4, leading=15)
S["body"] = ParagraphStyle("body", parent=styles["Normal"], fontName="Helvetica",
                           fontSize=10.5, textColor=GRIS_TXT, leading=15.5, spaceAfter=5, alignment=TA_LEFT)
S["callout"] = ParagraphStyle("callout", parent=S["body"], fontSize=10, leading=14.5, spaceAfter=0)
S["li"] = ParagraphStyle("li", parent=S["body"], spaceAfter=3, leading=15)
S["intro"] = ParagraphStyle("intro", parent=S["body"], fontSize=11, textColor=GRIS_TXT, leading=16)
S["cover_kicker"] = ParagraphStyle("ck", fontName="Helvetica-Bold", fontSize=11, textColor=BLANCO, alignment=TA_LEFT, leading=13)
S["cover_title"] = ParagraphStyle("ct", fontName="Helvetica-Bold", fontSize=25, textColor=BLANCO, alignment=TA_LEFT, leading=28)
S["cover_sub"] = ParagraphStyle("cs", fontName="Helvetica", fontSize=10.5, textColor=colors.HexColor("#dbeafe"), alignment=TA_LEFT, leading=14)


def banner(kicker, title, sub):
    inner = [Paragraph(kicker, S["cover_kicker"]), Spacer(1, 4),
             Paragraph(title, S["cover_title"]), Spacer(1, 5),
             Paragraph(sub, S["cover_sub"])]
    t = Table([[inner]], colWidths=[USABLE_W])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), AZUL),
        ("LEFTPADDING", (0, 0), (-1, -1), 18), ("RIGHTPADDING", (0, 0), (-1, -1), 18),
        ("TOPPADDING", (0, 0), (-1, -1), 18), ("BOTTOMPADDING", (0, 0), (-1, -1), 18),
        ("ROUNDEDCORNERS", [6, 6, 6, 6]),
    ]))
    return t


def callout(kind, text):
    bg, bar, label = CALLOUTS[kind]
    p = Paragraph(f'<b>{label}.</b> {text}', S["callout"])
    t = Table([[p]], colWidths=[USABLE_W])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), bg),
        ("LINEBEFORE", (0, 0), (0, -1), 3, bar),
        ("LEFTPADDING", (0, 0), (-1, -1), 11), ("RIGHTPADDING", (0, 0), (-1, -1), 11),
        ("TOPPADDING", (0, 0), (-1, -1), 8), ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
    ]))
    return t


def steps(items):
    lis = [ListItem(Paragraph(t, S["li"]), value=i + 1, leftIndent=6) for i, t in enumerate(items)]
    return ListFlowable(lis, bulletType="1", bulletFontName="Helvetica-Bold",
                        bulletColor=AZUL, leftIndent=16, bulletFontSize=10.5)


def bullets(items):
    lis = [ListItem(Paragraph(t, S["li"]), leftIndent=6) for t in items]
    return ListFlowable(lis, bulletType="bullet", bulletColor=AZUL, start="square",
                        leftIndent=14, bulletFontSize=6)


def h1(t): return Paragraph(t, S["h1"])
def h2(t): return Paragraph(t, S["h2"])
def p(t): return Paragraph(t, S["body"])
def intro(t): return Paragraph(t, S["intro"])
def sp(h=6): return Spacer(1, h)


def faq(pairs):
    out = []
    for q, a in pairs:
        out.append(Paragraph(q, ParagraphStyle("faqq", parent=S["body"], textColor=AZUL_OSC, spaceAfter=1, fontName="Helvetica-Bold")))
        out.append(Paragraph(a, ParagraphStyle("faqa", parent=S["body"], spaceAfter=8)))
    return out


def footer_maker(rol):
    def _f(canvas, doc):
        canvas.saveState()
        canvas.setStrokeColor(colors.HexColor("#e5e7eb"))
        canvas.setLineWidth(0.5)
        canvas.line(MARGEN, 1.35 * cm, PAGE_W - MARGEN, 1.35 * cm)
        canvas.setFont("Helvetica", 8)
        canvas.setFillColor(GRIS_SUAVE)
        canvas.drawString(MARGEN, 0.95 * cm, f"Atankalama Limpieza  ·  {rol}")
        canvas.drawRightString(PAGE_W - MARGEN, 0.95 * cm, f"Página {doc.page}")
        canvas.drawCentredString(PAGE_W / 2.0, 0.95 * cm, "atankalama.com/limpieza")
        canvas.restoreState()
    return _f


def build(filename, rol_footer, story):
    doc = BaseDocTemplate(os.path.join(OUT_DIR, filename), pagesize=A4,
                          leftMargin=MARGEN, rightMargin=MARGEN, topMargin=MARGEN, bottomMargin=1.7 * cm,
                          title=f"Manual {rol_footer} - Atankalama Limpieza", author="Atankalama Corp")
    frame = Frame(MARGEN, 1.7 * cm, USABLE_W, PAGE_H - MARGEN - 1.7 * cm, id="main")
    doc.addPageTemplates([PageTemplate(id="tpl", frames=[frame], onPage=footer_maker(rol_footer))])
    doc.build(story)
    print("OK:", filename)


# =========================================================================
#  SECCIÓN COMÚN — Cómo entrar
# =========================================================================
def seccion_ingreso():
    return [
        h1("1. Cómo entrar a la aplicación"),
        h2("Abrir la app"),
        steps([
            "En tu celular, abre el navegador (Chrome, Safari) y entra a: <b>atankalama.com/limpieza</b>",
            "Vas a ver la pantalla para iniciar sesión.",
        ]),
        sp(),
        callout("tip", "Instala la app en tu teléfono. La primera vez que entres, el navegador te va a ofrecer "
                       "<b>&ldquo;Instalar app&rdquo;</b> (o &ldquo;Agregar a pantalla de inicio&rdquo;). Acéptalo: te queda "
                       "un ícono como cualquier otra app y se abre más rápido. Funciona igual sin instalarla."),
        sp(),
        h2("Iniciar sesión"),
        steps([
            "Escribe tu <b>RUT</b> (por ejemplo 12.345.678-9). Puedes escribirlo con o sin puntos.",
            "Escribe la <b>contraseña temporal</b> que te dio tu jefatura.",
            "Aprieta <b>&ldquo;Iniciar sesión&rdquo;</b>.",
        ]),
        sp(),
        h2("Cambiar la contraseña (solo la primera vez)"),
        p("La primera vez, la app te va a pedir que cambies la contraseña temporal por una tuya:"),
        steps([
            "Escribe la contraseña temporal (la actual).",
            "Escribe tu contraseña nueva (mínimo 8 caracteres).",
            "Repítela para confirmar y aprieta <b>&ldquo;Cambiar&rdquo;</b>.",
        ]),
        callout("importante", "Guarda bien tu contraseña nueva. Si la olvidas, no te preocupes: avísale a tu "
                              "administrador y te genera una nueva contraseña temporal."),
    ]


# =========================================================================
#  MANUAL 1 — TRABAJADOR
# =========================================================================
def manual_trabajador():
    st = [banner("MANUAL DE USUARIO", "Trabajador de limpieza",
                 "Cómo usar la app para limpiar tus habitaciones, paso a paso."), sp(14),
          intro("Esta app te ayuda a saber, en todo momento, <b>qué habitación tienes que limpiar ahora</b>. Es "
                "simple: ves una sola habitación a la vez, la limpias siguiendo una lista, y cuando terminas aparece "
                "la siguiente sola. Nada más."), sp(4)]

    st += seccion_ingreso()

    st.append(h1("2. Tu pantalla de inicio"))
    st.append(p("Cuando entras, vas a ver:"))
    st.append(bullets([
        "Arriba: tu <b>saludo</b> y el <b>hotel</b> donde trabajas hoy.",
        "Una <b>barra de progreso</b> del día, que se va llenando a medida que terminas habitaciones.",
        "La <b>Habitación actual</b>: la única que tienes que hacer ahora, con un botón grande para empezar.",
    ]))
    st.append(callout("nota", "A propósito la app <b>no te muestra números</b> (cuántas te faltan, cuánto tardaste). "
                              "La idea es que trabajes tranquilo, sin presión, una habitación a la vez."))

    st.append(h1("3. Cómo limpiar una habitación"))
    st.append(steps([
        "En la <b>Habitación actual</b>, aprieta el botón azul <b>&ldquo;Comenzar limpieza&rdquo;</b>.",
        "Se abre la <b>lista de tareas</b> (el checklist) de esa habitación: limpiar baño, cambiar sábanas, toallas, etc.",
        "A medida que <b>terminas cada tarea</b>, tócala para marcarla con un tic.",
        "Cuando marcaste <b>todas</b> las tareas, se desbloquea el botón verde <b>&ldquo;Habitación terminada&rdquo;</b>.",
        "Aprieta <b>&ldquo;Habitación terminada&rdquo;</b>: la habitación pasa a revisión y aparece la siguiente sola.",
    ]))
    st.append(callout("tip", "Cada tarea que marcas <b>se guarda al instante</b>. Aunque se corte el internet o cierres "
                             "la app, cuando vuelvas a abrir la habitación vas a encontrar todo lo que ya habías marcado."))
    st.append(callout("importante", "Si el botón &ldquo;Habitación terminada&rdquo; se ve <b>gris</b>, es porque todavía "
                                    "falta marcar alguna tarea. Revisa la lista y marca las que falten."))

    st.append(h1("4. Si no puedes terminar una habitación ahora"))
    st.append(p("A veces una habitación no se puede terminar (el huésped no ha salido, te falta un insumo, "
                "necesita mantención). Para esos casos:"))
    st.append(steps([
        "Dentro de la habitación, aprieta <b>&ldquo;No puedo terminar esta ahora&rdquo;</b>.",
        "Elige el motivo: huésped no ha salido, falta un insumo, requiere mantención, u otro.",
        "La habitación pasa <b>al final de tu lista</b> (la vas a ver más tarde) y se le avisa a tu supervisora.",
    ]))
    st.append(callout("ojo", "Al saltar una habitación, el progreso que llevabas en esa habitación <b>se borra</b>. "
                             "Cuando la retomes más tarde, empiezas la lista de cero."))

    st.append(h1("5. Otras cosas útiles"))
    st.append(h2("Cuando terminas el día"))
    st.append(p("Cuando terminas todas tus habitaciones, la app te muestra <b>&ldquo;Día completado&rdquo;</b>. Eso es todo, buen trabajo."))
    st.append(h2("Si no tienes habitaciones asignadas"))
    st.append(p("Si todavía no te asignaron nada, vas a ver un botón <b>&ldquo;Avisar que estoy disponible&rdquo;</b>. "
                "Apriétalo y tu supervisora recibe el aviso de que puedes tomar más trabajo."))
    st.append(h2("Reportar algo roto o con problema (ticket)"))
    st.append(p("Si encuentras algo roto o que necesita arreglo (una lámpara quemada, una fuga), usa la sección "
                "<b>&ldquo;Tickets&rdquo;</b> de la barra de abajo para reportarlo: pones un título, una descripción y la prioridad."))
    st.append(h2("Menú de abajo y ajustes"))
    st.append(p("En la barra de abajo tienes: <b>Inicio</b>, <b>Habitaciones</b>, <b>Tickets</b> y <b>Ajustes</b>. "
                "Desde <b>Ajustes</b> puedes cambiar tu contraseña, activar el modo oscuro y cerrar sesión."))

    st.append(h1("6. Preguntas frecuentes"))
    st += faq([
        ("¿Se me cortó el internet mientras limpiaba, se pierde lo que marqué?",
         "No. La app guarda todo en tu teléfono y lo sincroniza sola cuando vuelve la conexión. Aunque cierres la app, tu progreso queda."),
        ("¿Por qué no veo cuántas habitaciones me faltan?",
         "Es a propósito. La app te muestra una habitación a la vez para que trabajes tranquilo, sin la presión de una lista larga."),
        ("El botón &ldquo;Habitación terminada&rdquo; está gris y no puedo apretarlo.",
         "Es porque falta marcar alguna tarea de la lista. Marca todas y el botón se pone verde."),
        ("Olvidé mi contraseña.",
         "Avísale a tu administrador. Él te genera una contraseña temporal nueva y la cambias al entrar."),
        ("¿Puedo usar la app en un computador?",
         "Sí, funciona en el navegador de cualquier computador o tablet, pero está pensada sobre todo para el celular."),
    ])
    build("Manual-Trabajador.pdf", "Manual del Trabajador", st)


# =========================================================================
#  MANUAL 2 — SUPERVISORA
# =========================================================================
def manual_supervisora():
    st = [banner("MANUAL DE USUARIO", "Supervisora",
                 "Monitorear al equipo, asignar habitaciones, auditar y resolver alertas."), sp(14),
          intro("Como supervisora, la app te da una <b>vista completa</b> del día: qué necesita tu atención ahora, "
                "cómo va cada trabajador, y las habitaciones para auditar. Desde acá asignas, reasignas y controlas la calidad."), sp(4)]

    st += seccion_ingreso()

    st.append(h1("2. Tu pantalla de inicio"))
    st.append(p("Tu inicio está ordenado por prioridad: lo urgente arriba, el estado del equipo abajo."))
    st.append(h2("Alertas (lo urgente)"))
    st.append(p("Arriba de todo ves las <b>alertas</b> que necesitan tu acción. Cada alerta trae la información y "
                "hasta 2 botones para resolverla. Los tipos más comunes:"))
    st.append(bullets([
        "<b>Trabajador en riesgo</b>: alguien no va a alcanzar a terminar su turno.",
        "<b>Habitación rechazada</b>: una auditoría salió mal y hay que reasignar.",
        "<b>Habitación saltada</b>: un trabajador no pudo terminar una habitación.",
        "<b>Trabajador disponible</b>: alguien quedó sin carga y puede ayudar.",
        "<b>Ticket nuevo</b> de mantención y <b>fallo de sincronización</b> con Cloudbeds.",
    ]))
    st.append(callout("nota", "Las alertas <b>no tienen botón de descartar</b>: quedan hasta que las resuelves o hasta "
                              "que la situación se soluciona sola. Así no se te pasa ninguna."))
    st.append(h2("Estado del equipo"))
    st.append(p("Debajo ves a cada trabajador con su avance, agrupados por <b>en riesgo</b>, <b>en tiempo</b> y "
                "<b>disponible</b>. Cada uno trae botones <b>&ldquo;Ver carga&rdquo;</b> y <b>&ldquo;Reasignar&rdquo;</b>. "
                "Arriba puedes cambiar el <b>hotel</b> (Inn, 1 Sur o ambos)."))

    st.append(h1("3. Asignar habitaciones"))
    st.append(h2("Una por una (manual)"))
    st.append(steps([
        "Entra a <b>Asignaciones</b>.",
        "Toca una habitación sin asignar (aparecen en rojo).",
        "Elige el <b>trabajador</b> de la lista y aprieta <b>&ldquo;Asignar&rdquo;</b>.",
    ]))
    st.append(h2("Todas de una (automático)"))
    st.append(p("Con el botón <b>&ldquo;Auto-asignar&rdquo;</b> la app reparte las habitaciones pendientes entre los "
                "trabajadores activos de forma pareja. También puedes <b>reordenar</b> la cola de un trabajador para "
                "definir en qué orden hace sus habitaciones."))

    st.append(h1("4. Auditar habitaciones (los 3 resultados)"))
    st.append(p("Cuando un trabajador marca una habitación como terminada, te llega para auditar. Entra a la sección "
                "<b>&ldquo;Auditoría&rdquo;</b>, elige el hotel y toca la habitación. Vas a ver el checklist que hizo el "
                "trabajador. Después de revisar la habitación en persona, eliges uno de estos 3 resultados:"))
    st.append(steps([
        "<b>Aprobar</b>: quedó impecable. La habitación se marca como limpia (y se avisa a Cloudbeds).",
        "<b>Aprobar con observación</b>: había algo menor, lo resolviste en el momento pero dejas constancia. "
        "Desmarcas los ítems que estaban mal y la habitación queda igual como limpia.",
        "<b>Rechazar</b>: necesita volver a limpiarse. Eliges el motivo; la habitación vuelve a &ldquo;sucia&rdquo; y "
        "se genera una alerta para reasignarla.",
    ]))
    st.append(callout("importante", "Una vez que auditas una habitación, <b>no se puede volver a auditar</b>. Queda "
                                    "marcada como &ldquo;Auditada&rdquo; (solo lectura) para mantener el historial limpio. "
                                    "Si tocas una ya auditada, ves el detalle de quién la auditó y cuándo."))

    st.append(h1("5. Habitaciones rechazadas y tickets"))
    st.append(p("Cuando una habitación se rechaza, aparece como alerta. Toca <b>&ldquo;Reasignar&rdquo;</b> para "
                "mandársela a otro trabajador (o al mismo), o <b>&ldquo;Resolver ahora&rdquo;</b> si la arreglas tú misma. "
                "En la sección <b>Tickets</b> ves todos los reportes de mantención y puedes crear los tuyos."))

    st.append(h1("6. Reportes"))
    st.append(p("En <b>Reportes</b> tienes dos resúmenes mensuales, que puedes filtrar por mes y hotel y "
                "<b>descargar en Excel/CSV</b>:"))
    st.append(bullets([
        "<b>Resumen por trabajador</b>: cuántas habitaciones hizo cada uno en el mes, con sus créditos.",
        "<b>Resumen de auditorías</b>: cuántas auditaste, separadas por aprobadas, con observación y rechazadas.",
    ]))

    st.append(h1("7. Preguntas frecuentes"))
    st += faq([
        ("¿Qué diferencia hay entre &ldquo;Aprobar&rdquo; y &ldquo;Aprobar con observación&rdquo;?",
         "Las dos dejan la habitación como limpia. La observación sirve para dejar constancia de algo menor que resolviste, "
         "y queda registrado para los reportes. El trabajador no lo ve como un rechazo."),
        ("Me equivoqué al auditar una habitación, ¿puedo cambiarla?",
         "No. Una vez auditada queda fija para mantener el historial confiable. Si fue un error, coordina la corrección con tu administrador."),
        ("¿El trabajador ve las alertas que yo recibo?",
         "No. Las alertas (por ejemplo &ldquo;trabajador en riesgo&rdquo;) son solo para ti. El trabajador nunca las ve."),
        ("¿Qué pasa si falla la sincronización con Cloudbeds?",
         "Te llega una alerta. Puedes reintentar desde ahí. Si no se recupera, avísale a tu administrador."),
    ])
    build("Manual-Supervisora.pdf", "Manual de la Supervisora", st)


# =========================================================================
#  MANUAL 3 — RECEPCIÓN
# =========================================================================
def manual_recepcion():
    st = [banner("MANUAL DE USUARIO", "Recepción",
                 "Auditar (revisar) las habitaciones que el equipo de limpieza terminó."), sp(14),
          intro("En recepción usas esta app para una tarea puntual: <b>auditar</b> las habitaciones que los "
                "trabajadores marcaron como terminadas. La abres cuando tienes un momento, revisas lo pendiente, y listo.")]
    st.append(callout("nota", "Para consultar el estado general de habitaciones, disponibilidad, check-in y check-out "
                              "sigues usando <b>Cloudbeds</b> como siempre. Esta app es <b>solo para auditar</b>, no lo reemplaza."))
    st.append(sp(2))

    st += seccion_ingreso()

    st.append(h1("2. Tu pantalla de inicio"))
    st.append(p("Tu inicio es una <b>bandeja de auditoría</b>: una grilla con las habitaciones que están esperando "
                "tu revisión. Cada cuadrito es una habitación terminada por un trabajador."))
    st.append(bullets([
        "Arriba puedes elegir el <b>hotel</b>: ATAN, INN o ambos (si eliges ambos, cada habitación muestra su hotel adelante, ej. ATAN-302).",
        "El botón de <b>refrescar</b> (arriba a la derecha) actualiza la lista con las nuevas habitaciones terminadas.",
        "Si no hay nada para auditar, ves el mensaje <b>&ldquo;No hay habitaciones pendientes de auditar&rdquo;</b>.",
    ]))

    st.append(h1("3. Cómo auditar una habitación"))
    st.append(steps([
        "Toca el cuadrito de la habitación que quieres revisar.",
        "Se abre la auditoría con el <b>checklist</b> que completó el trabajador.",
        "Revisa la habitación en persona.",
        "Elige uno de los <b>3 resultados</b> (ver abajo).",
    ]))
    st.append(h2("Los 3 resultados"))
    st.append(bullets([
        "<b>Aprobar</b>: quedó impecable. La habitación se marca como limpia (y se avisa a Cloudbeds).",
        "<b>Aprobar con observación</b>: había algo menor, lo resolviste y dejas constancia. Desmarcas los ítems que "
        "estaban mal; la habitación igual queda como limpia.",
        "<b>Rechazar</b>: necesita volver a limpiarse. Eliges el motivo y se le avisa a la supervisora para reasignarla.",
    ]))
    st.append(callout("importante", "Cuando terminas de auditar, la habitación <b>desaparece de tu bandeja</b> y no se "
                                    "puede volver a auditar. Queda marcada como &ldquo;Auditada&rdquo;. Si la tocas de nuevo, "
                                    "solo ves el detalle histórico (quién la auditó, cuándo y el resultado)."))

    st.append(h1("4. Otras cosas útiles"))
    st.append(bullets([
        "La app <b>recuerda el hotel</b> que elegiste la última vez.",
        "La lista se actualiza sola cada pocos minutos; igual puedes apretar refrescar cuando quieras.",
        "Desde <b>Ajustes</b> cambias tu contraseña, activas el modo oscuro y cierras sesión.",
    ]))

    st.append(h1("5. Preguntas frecuentes"))
    st += faq([
        ("¿Puedo ver el estado de todas las habitaciones del hotel acá?",
         "Para eso sigues usando Cloudbeds. Esta app te muestra solo las habitaciones que están esperando auditoría."),
        ("¿Qué diferencia hay entre &ldquo;Aprobar&rdquo; y &ldquo;Aprobar con observación&rdquo;?",
         "Las dos dejan la habitación como limpia. La observación sirve para dejar constancia de algo menor que resolviste "
         "en el momento; queda registrado sin frenar la habitación."),
        ("Me equivoqué al auditar, ¿puedo deshacerlo?",
         "No. Una vez auditada queda fija para mantener el historial confiable. Si fue un error, coordina con tu administrador."),
        ("¿Cada cuánto reviso la app?",
         "Cuando tengas un momento libre. No necesitas estar pendiente: la abres, auditas lo que haya, y sigues con lo tuyo."),
    ])
    build("Manual-Recepcion.pdf", "Manual de Recepción", st)


if __name__ == "__main__":
    manual_trabajador()
    manual_supervisora()
    manual_recepcion()
    print("\nManuales generados en:", OUT_DIR)
