// Metro / Netzplan-Grafik fuer Projekt-Detailseiten.
//
// data-metro JSON beschreibt einen gerichteten Graphen:
//   { nodes: { id: { label, col, row } }, edges: [{ from, to, color }] }
// col/row sind Rasterkoordinaten (du legst das Layout selbst fest, kein
// Auto-Layout). Das SVG:
//   - eine Linie pro Kante (from -> to), Ecken rechtwinklig gefuehrt
//   - ein Kreis + Label pro Knoten
//   - erst wachsen die Kanten beim Scroll ins Bild (stroke-dashoffset),
//     dann laeuft dauerhaft ein Puls jede Kante entlang (Richtung = from->to)
//
// Spaltenbreiten sind NICHT fix: nach dem ersten Rendern werden die Labels
// gemessen und die Spalten so weit auseinandergezogen, dass benachbarte
// Labels sich nicht mehr ueberlappen. Danach werden Kanten/Knoten neu
// positioniert.
//
// rechtwinklige Verbindungen (H, dann V, dann H), keine Kurven,
// Labels immer mittig ueber dem Knoten (Start/Ende seitlich). Reicht das
// optisch nicht, kommt danach eine Graph-Layout-Lib.
//
// Hochkant auf dem Handy (VERTICAL_MQ) laeuft der Plan von oben nach unten:
// jede Station bekommt eine eigene Zeile (sortiert nach col, dann row), die
// urspruenglichen rows werden zu schmalen senkrechten Spuren, alle Labels
// stehen buendig rechts daneben. So passt der Plan ohne seitliches Scrollen
// in die Bildschirmbreite. Beim Drehen wird neu gebaut.

const NS = "http://www.w3.org/2000/svg";
const ROW_H = 110; // px pro Raster-Zeile
const MIN_COL_W = 170; // Mindest-Spaltenabstand
const COL_GAP = 26; // Mindestluecke zwischen zwei Labels benachbarter Spalten
const PAD_X = 24;
const PAD_Y = 52;
const R = 5; // Knoten-Radius
const LANE = 14; // Versatz paralleler Kanten, damit sie sich nicht decken
const DEFAULT_COLOR = "#1c8a7d";
const SLOT_H = 58; // vertikal: Mindesthoehe pro Station
const LANE_W = 34; // vertikal: Abstand der senkrechten Spuren

// Gleiche Query wie der Fullpage-Hero im CSS (Hochkant-Teil).
const VERTICAL_MQ = matchMedia("(max-width: 767px) and (orientation: portrait)");
// Handy quer: kaum Hoehe, daher dichtere Zeilen (reicht knapp fuer ein
// zweizeiliges Label ueber dem Knoten).
const LANDSCAPE_MQ = matchMedia("(orientation: landscape) and (max-height: 500px)");

const reduceMotion = matchMedia("(prefers-reduced-motion: reduce)").matches;

function el(name, attrs, parent) {
    const node = document.createElementNS(NS, name);
    for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
    if (parent) parent.appendChild(node);
    return node;
}

// rechtwinkliger Pfad von a nach b: halber Weg horizontal, dann vertikal,
// dann Rest horizontal. Gleiche Zeile => gerade Linie. laneShift verschiebt
// das vertikale Teilstueck seitlich, damit parallele Kanten sich nicht decken.
function orthPath(a, b, laneShift = 0, vertical = false) {
    if (vertical) {
        // gespiegelt: erst senkrecht, dann waagerecht, dann senkrecht
        if (a.x === b.x) return `M ${a.x} ${a.y} L ${b.x} ${b.y}`;
        const midY = a.y + (b.y - a.y) / 2 + laneShift;
        return `M ${a.x} ${a.y} L ${a.x} ${midY} L ${b.x} ${midY} L ${b.x} ${b.y}`;
    }
    if (a.y === b.y) return `M ${a.x} ${a.y} L ${b.x} ${b.y}`;
    const midX = a.x + (b.x - a.x) / 2 + laneShift;
    return `M ${a.x} ${a.y} L ${midX} ${a.y} L ${midX} ${b.y} L ${b.x} ${b.y}`;
}

function build(container) {
    // JSON kommt aus einem <script type="application/json"> (per
    // data-metro-src referenziert), nicht aus einem Attribut: geschweifte
    // Klammern im JSON waeren dort ein Fluid-Ausdruck.
    const src = container.dataset.metroSrc;
    const scriptEl = src && document.getElementById(src);
    if (!scriptEl) return;

    let data;
    try {
        data = JSON.parse(scriptEl.textContent);
    } catch {
        return;
    }
    if (!data || !data.nodes || !Array.isArray(data.edges)) return;

    const ids = Object.keys(data.nodes);
    if (!ids.length) return;

    const outCount = (id) => data.edges.filter((e) => e.from === id).length;
    const inCount = (id) => data.edges.filter((e) => e.to === id).length;
    const isEnd = (id) => outCount(id) === 0 && inCount(id) > 0;

    let maxCol = 0;
    let maxRow = 0;
    for (const id of ids) {
        maxCol = Math.max(maxCol, data.nodes[id].col || 0);
        maxRow = Math.max(maxRow, data.nodes[id].row || 0);
    }

    // Spalten-X: zunaechst gleichmaessig, spaeter anhand Labelbreiten geweitet
    const colX = [];
    for (let c = 0; c <= maxCol; c++) colX[c] = PAD_X + c * MIN_COL_W;

    const vertical = VERTICAL_MQ.matches;
    const lanes = [...new Set(ids.map((id) => data.nodes[id].row || 0))].sort((a, b) => a - b);
    const slotOf = new Map(
        [...ids]
            .sort(
                (a, b) =>
                    (data.nodes[a].col || 0) - (data.nodes[b].col || 0) ||
                    (data.nodes[a].row || 0) - (data.nodes[b].row || 0),
            )
            .map((id, i) => [data.nodes[id], i]),
    );
    let slotH = SLOT_H;
    const rowH = LANDSCAPE_MQ.matches ? 60 : ROW_H;
    const laneRight = PAD_X + (lanes.length - 1) * LANE_W;

    const pos = (n) =>
        vertical
            ? { x: PAD_X + lanes.indexOf(n.row || 0) * LANE_W, y: PAD_Y + slotOf.get(n) * slotH }
            : { x: colX[n.col || 0], y: PAD_Y + (n.row || 0) * rowH };

    const svg = el(
        "svg",
        {
            class: "tv-metro__svg",
            preserveAspectRatio: vertical ? "xMidYMid meet" : "xMinYMid meet",
            role: "img",
            "aria-label":
                "Netzplan des Projektaufbaus: " +
                data.edges
                    .map(
                        (e) =>
                            `${data.nodes[e.from]?.label || e.from} führt zu ${
                                data.nodes[e.to]?.label || e.to
                            }`,
                    )
                    .join("; "),
        },
        container,
    );

    // ── Kanten (Pfad-d wird nach dem Spalten-Fitting neu gesetzt) ───────────
    const laneSeen = {};
    const edgeEls = [];
    data.edges.forEach((e, i) => {
        const from = data.nodes[e.from];
        const to = data.nodes[e.to];
        if (!from || !to) return;

        let laneShift = 0;
        if ((from.row || 0) !== (to.row || 0)) {
            const key = `${from.col || 0}-${to.col || 0}`;
            const k = laneSeen[key] || 0;
            laneSeen[key] = k + 1;
            laneShift = Math.ceil(k / 2) * LANE * (k % 2 ? -1 : 1);
        }

        const color = e.color || DEFAULT_COLOR;
        const line = el(
            "path",
            {
                d: orthPath(pos(from), pos(to), laneShift, vertical),
                fill: "none",
                stroke: color,
                "stroke-width": 5,
                "stroke-linecap": "round",
                "stroke-linejoin": "round",
                class: "tv-metro__line",
            },
            svg,
        );
        edgeEls.push({ line, from, to, laneShift, color, i });
    });

    const isStart = (id) => inCount(id) === 0;
    const LINE_H = 20; // Zeilenhoehe fuer mehrzeilige Labels (im viewBox-Mass)

    // ── Knoten + Labels ────────────────────────────────────────────────────
    const nodeEls = ids.map((id, i) => {
        const n = data.nodes[id];
        const g = el("g", { class: "tv-metro__station", style: `--i:${i}` }, svg);
        const dot = el("circle", { r: R, class: "tv-metro__dot" }, g);

        // Start => Label links, Ende => rechts, sonst mittig darueber.
        const anchor = isEnd(id) ? "start" : isStart(id) ? "end" : "middle";

        // Label darf mit "\n" mehrzeilig sein (z.B. Tool-Liste am Anfang):
        // die Namen stehen dann UNTEREINANDER statt in einer langen Zeile.
        const lines = String(n.label || id).split("\n");
        const label = el("text", { class: "tv-metro__label" }, g);
        lines.forEach((ln, li) => {
            const tspan = el("tspan", {}, label);
            tspan.textContent = ln;
            if (li > 0) tspan.setAttribute("dy", LINE_H);
        });
        return { id, n, g, dot, label, anchor, lineCount: lines.length };
    });

    const place = () => {
        nodeEls.forEach(({ n, dot, label, anchor, lineCount }) => {
            const p = pos(n);
            dot.setAttribute("cx", p.x);
            dot.setAttribute("cy", p.y);
            // erste tspan-Zeile; bei mehrzeiligem Label so hoch setzen, dass der
            // Block vertikal um den Knoten zentriert liegt
            const blockOffset = ((lineCount - 1) * LINE_H) / 2;
            const setLines = (x, firstY, textAnchor) => {
                label.setAttribute("text-anchor", textAnchor);
                label.setAttribute("x", x);
                label.setAttribute("y", firstY);
                label.querySelectorAll("tspan").forEach((t, i) => {
                    if (i > 0) return;
                    t.removeAttribute("x");
                });
                // alle tspans an dieselbe x binden (sonst rutscht Zeile 2+ weg)
                label
                    .querySelectorAll("tspan")
                    .forEach((t) => t.setAttribute("x", x));
            };
            if (vertical) {
                // alle Labels in einer Spalte rechts neben den Spuren
                setLines(laneRight + R + 16, p.y + 5 - blockOffset, "start");
            } else if (anchor === "start") {
                setLines(p.x + R + 8, p.y + 4 - blockOffset, "start");
            } else if (anchor === "end") {
                setLines(p.x - R - 8, p.y + 4 - blockOffset, "end");
            } else {
                setLines(p.x, p.y - R - 12 - (lineCount - 1) * LINE_H, "middle");
            }
        });
        edgeEls.forEach(({ line, from, to, laneShift }) => {
            line.setAttribute("d", orthPath(pos(from), pos(to), laneShift, vertical));
        });
    };

    const fitViewBox = (startOverhang, endOverhang) => {
        const b = svg.getBBox();
        const m = 8;
        // Horizontal wird NICHT die tatsaechliche (durch unterschiedlich
        // lange Start-/End-Labels asymmetrische) Textbreite genutzt, sondern
        // derselbe Rand links wie rechts -- sonst wirkt die Grafik schiefer
        // als sie ist, weil z.B. "Kundenlogin" laenger ist als
        // "Agent-Extension". Der Rand ist so gross wie der laengere der
        // beiden Labelueberhaenge, damit kein Text abgeschnitten wird.
        const sideMargin = Math.max(startOverhang, endOverhang, 0) + m;
        const left = colX[0] - sideMargin;
        const right = colX[maxCol] + sideMargin;
        svg.setAttribute(
            "viewBox",
            `${left} ${b.y - m} ${right - left} ${b.height + m * 2}`,
        );
    };

    place();

    // Erst nach dem Layout: Labelbreiten messen, Spalten so weit auseinander-
    // ziehen, dass die mittigen Labels benachbarter Spalten sich nicht decken,
    // dann alles final positionieren, Kanten-Animation setzen und ERST DANN
    // die Puls-Kreise erzeugen (mit fertigem Pfad -> kein 0,0-Aufblitzen).
    requestAnimationFrame(() => {
        if (vertical) {
            // Zeilen strecken, bis der Plan das Seitenverhaeltnis der Karte
            // hat -- sonst bliebe unter dem Plan ein leerer Streifen.
            const b = svg.getBBox();
            const target = (b.width * container.clientHeight) / container.clientWidth;
            if (slotOf.size > 1 && target > b.height) {
                slotH += (target - b.height) / (slotOf.size - 1);
            }
            place();
            const fit = svg.getBBox();
            const m = 8;
            svg.setAttribute(
                "viewBox",
                `${fit.x - m} ${fit.y - m} ${fit.width + m * 2} ${fit.height + m * 2}`,
            );
        } else {
            const halfW = new Array(maxCol + 1).fill(0);
            const lineWidth = (label) => {
                // breiteste tspan-Zeile (getComputedTextLength summiert sonst alle)
                let max = 0;
                label.querySelectorAll("tspan").forEach((t) => {
                    const w = t.getComputedTextLength();
                    if (w > max) max = w;
                });
                return max;
            };
            nodeEls.forEach(({ n, label, anchor }) => {
                if (anchor === "start") return; // Endknoten ragen nur nach rechts
                const half = lineWidth(label) / 2;
                const c = n.col || 0;
                if (half > halfW[c]) halfW[c] = half;
            });

            for (let c = 1; c <= maxCol; c++) {
                const need = halfW[c - 1] + halfW[c] + COL_GAP;
                colX[c] = colX[c - 1] + Math.max(MIN_COL_W, need);
            }

            // Ueberhang der Start-/End-Labels ueber den jeweils aeussersten
            // Knoten hinaus (Start ragt nach links, Ende nach rechts) -- damit
            // fitViewBox links und rechts denselben Rand setzen kann, ohne
            // Text abzuschneiden.
            let startOverhang = 0;
            let endOverhang = 0;
            nodeEls.forEach(({ label, anchor }) => {
                if (anchor === "end") startOverhang = Math.max(startOverhang, lineWidth(label) + 8);
                if (anchor === "start") endOverhang = Math.max(endOverhang, lineWidth(label) + 8);
            });
            const sideMargin = Math.max(startOverhang, endOverhang, 0) + 8;

            // Spalten zusaetzlich so weit strecken, dass die Grafik die volle
            // Container-Breite ausnutzt (abzueglich des beidseitig gleichen
            // Randes) statt rechts Platz zu lassen. Nur die X-Koordinaten
            // werden gestreckt, Kreise bleiben also rund.
            if (maxCol > 0) {
                const targetWidth = container.clientWidth - sideMargin * 2;
                const naturalWidth = colX[maxCol] - colX[0];
                if (targetWidth > naturalWidth) {
                    const stretch = targetWidth / naturalWidth;
                    for (let c = 1; c <= maxCol; c++) {
                        colX[c] = colX[0] + (colX[c] - colX[0]) * stretch;
                    }
                }
            }

            place();
            fitViewBox(startOverhang, endOverhang);
        }

        if (!reduceMotion) {
            edgeEls.forEach(({ line, color, i }) => {
                const len = line.getTotalLength();
                line.style.strokeDasharray = len;
                line.style.strokeDashoffset = len;
                line.style.animationDelay = `${i * 0.12}s`;

                // Puls-Kreis + Motion jetzt erst anlegen: der Pfad steht,
                // der Kreis startet direkt auf der Linie statt bei (0,0).
                const begin = `${0.9 + i * 0.12}s`;
                const pulse = el(
                    "circle",
                    // ponytail: opacity 0 bis Animationsstart, sonst blitzt der
                    // Kreis vorher als statischer Punkt bei (0,0) auf.
                    // Radius kleiner als der Stations-Dot (R): identische
                    // Groesse liess beide Kreise beim Ueberlappen an der
                    // Station gegeneinander flackern (Antialiasing-Kanten).
                    { r: R - 1.5, fill: color, opacity: 0, class: "tv-metro__pulse" },
                    svg,
                );
                const runDur = 3.6;
                el(
                    "animateMotion",
                    {
                        dur: `${runDur}s`,
                        repeatCount: "indefinite",
                        begin,
                        path: line.getAttribute("d"),
                    },
                    pulse,
                );
                // Kurz aus-/einblenden statt hart auf 1 springen: der Puls
                // deckt beim exakten Durchqueren einer Station sonst den
                // weissen Dot ab und gibt ihn wieder frei -- das wirkte wie
                // Flackern. Ueber je ~6% der Laufzeit am Start und am Ende
                // faedelt er stattdessen unsichtbar ein/aus, an den
                // Stationen selbst ist der Puls also gar nicht sichtbar.
                el(
                    "animate",
                    {
                        attributeName: "opacity",
                        values: "0;1;1;0",
                        keyTimes: "0;0.06;0.94;1",
                        dur: `${runDur}s`,
                        repeatCount: "indefinite",
                        begin,
                        fill: "freeze",
                    },
                    pulse,
                );
            });
        }
    });

    if (!reduceMotion) {
        // Aufbau einmalig beim Sichtbarwerden starten; die SMIL-Pulse laufen
        // nur, solange die Grafik im Viewport ist – ausserhalb kosten 8 endlose
        // animateMotion sonst dauerhaft CPU (Ruckeln beim Scrollen).
        let started = false;
        container._tvIo?.disconnect();
        const io = (container._tvIo = new IntersectionObserver(
            (entries) => {
                const visible = entries[0].isIntersecting;
                if (visible && !started) {
                    started = true;
                    container.classList.add("tv-metro--play");
                }
                try {
                    if (visible) svg.unpauseAnimations();
                    else svg.pauseAnimations();
                } catch {
                    /* pauseAnimations not available everywhere */
                }
            },
            { threshold: 0.1 },
        ));
        io.observe(container);
    }
}

function init() {
    document.querySelectorAll(".tv-metro[data-metro-src]").forEach(build);
}

// Drehen: hochkant <-> quer braucht ein anderes Layout, nicht nur Skalierung.
function rebuild() {
    document.querySelectorAll(".tv-metro[data-metro-src]").forEach((container) => {
        container.replaceChildren();
        container.classList.remove("tv-metro--play");
        build(container);
    });
}
VERTICAL_MQ.addEventListener("change", rebuild);
LANDSCAPE_MQ.addEventListener("change", rebuild);

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
