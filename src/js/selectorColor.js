// Selector de filamento: UN solo desplegable de "color + material" con búsqueda.
//  · Escribes "ros" y salen todos los rosados (PLA, PLA+, SILK, PETG…) con su gramos y precio por kg
//  · Los colores que usaste hace poco salen arriba antes de escribir
//  · Teclado: escribir, ↑ ↓, Enter para elegir, Esc para cerrar
//  · "Igual que la fila de arriba" para repetir el mismo carrete
// El panel vive en <body> (fuera de las ventanas de SweetAlert), así Esc y Enter solo afectan al panel.

// Puntito de color (el mismo del inventario). Lo más específico primero.
const PUNTOS = [['verde azulado', '#2a9d8f'], ['verde olivo', '#6b7d2c'], ['verde militar', '#4b5320'], ['rojo lava', '#c0392b'], ['ladrillo', '#a0432e'], ['marrón', '#6f4e37'], ['marron', '#6f4e37'], ['beige militar', '#ddd0b3'], ['pink', '#f48fb1'], ['yellow', '#f4c20d'], ['celeste', '#5bc0eb'], ['dorado', '#d4af37'], ['plateado', '#b8bcc2'], ['negro', '#1f1f1f'], ['blanco', '#f4f4f4'],
    ['gris', '#8a8f98'], ['rojo', '#d62828'], ['azul', '#1d4ed8'], ['verde', '#2e9e4f'], ['amarillo', '#f4c20d'], ['naranja', '#f07b1c'], ['rosado', '#e895b0'], ['rosa', '#f48fb1'],
    ['morado', '#7048e8'], ['café', '#7b4a2d'], ['cafe', '#7b4a2d'], ['beige', '#e1cfae'], ['transparente', '#dfe7ee']];

export const puntoColor = (color, px = 14) => {
    const c = String(color || '').toLowerCase();
    const hit = PUNTOS.find(([k]) => c.includes(k));
    return `<span style="display:inline-block;flex:none;width:${px}px;height:${px}px;border-radius:50%;vertical-align:${px > 16 ? -6 : -2}px;margin-right:8px;border:1px solid #0004;background:${hit ? hit[1] : '#cfd4da'}"></span>`;
};

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const norm = (s) => String(s ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
const gr = (n) => Number(n || 0).toLocaleString('es-GT', { maximumFractionDigits: 0 }) + ' g';
const Q0 = (n) => 'Q' + Number(n || 0).toLocaleString('es-GT', { maximumFractionDigits: 0 });
// Con `id` (un carrete concreto) la clave es el id; si no, color + material
const clave = (i) => (i.id != null ? `#${i.id}` : `${i.material_id}|${i.color}`);
const POCO = 100; // gramos: por debajo se marca en rojo

// ── Recientes (solo comodidad de este navegador) ────────────────────────────
const LLAVE = 'hc_colores_recientes';
const leerRecientes = () => {
    try { return JSON.parse(localStorage.getItem(LLAVE) || '[]'); } catch { return []; }
};
const guardarReciente = (k) => {
    try { localStorage.setItem(LLAVE, JSON.stringify([k, ...leerRecientes().filter((x) => x !== k)].slice(0, 6))); } catch { /* sin almacenamiento */ }
};

// ── Estilos (una sola vez) ──────────────────────────────────────────────────
function estilos() {
    if (document.getElementById('scEstilos')) return;
    const st = document.createElement('style');
    st.id = 'scEstilos';
    st.textContent = `
    .sc { position: relative; min-width: 0; }
    .sc-btn { width: 100%; text-align: left; display: flex; align-items: center; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; cursor: pointer; }
    .sc-btn .sc-txt { overflow: hidden; text-overflow: ellipsis; }
    .sc-mat { color: #6c757d; font-size: .85em; margin-left: .35rem; }
    .sc-vacio { color: #6c757d; }
    .sc-panel { position: fixed; z-index: 3000; background: #fff; border: 1px solid #cfd4da; border-radius: 12px; box-shadow: 0 12px 34px rgba(0,0,0,.25); padding: .5rem; display: flex; flex-direction: column; }
    .sc-q { width: 100%; padding: .45rem .7rem; border: 2px solid #c99a14; border-radius: 8px; outline: none; font-size: 1rem; margin-bottom: .4rem; }
    .sc-lista { overflow-y: auto; flex: 1; }
    .sc-sec { font-size: .72rem; letter-spacing: .05em; text-transform: uppercase; color: #8a8f98; padding: .35rem .5rem .1rem; font-weight: 700; }
    .sc-it { display: flex; align-items: center; gap: .1rem; padding: .4rem .5rem; border-radius: 8px; cursor: pointer; }
    .sc-it:hover, .sc-it.act { background: #fff4d6; }
    .sc-it.sel { box-shadow: inset 3px 0 0 #c99a14; }
    .sc-it .sc-main { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sc-it .sc-der { text-align: right; font-size: .82rem; color: #6c757d; white-space: nowrap; padding-left: .6rem; }
    .sc-it.poco .sc-der { color: #b42318; font-weight: 700; }
    .sc-it.agotado { opacity: .45; cursor: not-allowed; }
    .sc-igual { display: block; width: 100%; text-align: left; background: #eef4ff; border: 1px solid #b9cdf5; border-radius: 8px; padding: .4rem .6rem; margin-bottom: .4rem; font-size: .9rem; }
    .sc-igual:hover { background: #dfeaff; }
    .sc-nada { padding: .8rem .6rem; color: #6c757d; font-size: .9rem; }
    `;
    document.head.appendChild(st);
}

/**
 * items: [{ material_id, material, color, gramos, cotiza_a, id?, etiqueta?, der? }]
 *   id: si cada item es un carrete concreto · etiqueta: texto extra junto al material · der: segunda línea a la derecha
 * valor: { material_id, color } | null
 * anterior: () => { material_id, color } | null   (para "igual que la fila de arriba")
 * alCambiar: ({ material_id, color } | null) => void
 */
export function selectorColor({ items = [], valor = null, anterior = null, alCambiar = () => {}, vacio = 'Elige color…' } = {}) {
    estilos();
    let lista = items;
    let sel = null;
    let panel = null;
    let quitarEscucha = null;

    const el = document.createElement('div');
    el.className = 'sc';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'form-select sc-btn';
    el.appendChild(btn);

    const buscar = (m, c) => lista.find((i) => String(i.material_id) === String(m) && norm(i.color) === norm(c));
    const deSel = (s) => (s ? (s.id != null ? lista.find((i) => String(i.id) === String(s.id)) : buscar(s.material_id, s.color)) : null);
    const comoSel = (it) => (it ? { material_id: String(it.material_id), color: it.color, ...(it.id != null ? { id: it.id } : {}) } : null);

    const pintarBtn = () => {
        const it = deSel(sel);
        btn.innerHTML = it
            ? `${puntoColor(it.color, 16)}<span class="sc-txt"><b>${esc(it.color)}</b><span class="sc-mat">${esc(it.material)}${it.etiqueta ? ' · ' + esc(it.etiqueta) : ''}${it.id != null ? ' · quedan ' + gr(it.gramos) : ''}</span></span>`
            : `<span class="sc-vacio">${esc(lista.length ? vacio : 'Sin carretes: regístralos en Gastos > Inventario')}</span>`;
    };

    const poner = (it, avisar = true) => {
        sel = comoSel(it);
        pintarBtn();
        if (it) guardarReciente(clave(it));
        if (avisar) alCambiar(sel);
    };

    // ── Panel ──────────────────────────────────────────────────────────────
    const cerrar = (devolverFoco = false) => {
        if (!panel) return;
        panel.remove();
        panel = null;
        if (quitarEscucha) { quitarEscucha(); quitarEscucha = null; }
        if (devolverFoco) btn.focus();
    };

    const abrir = () => {
        if (panel) return;

        panel = document.createElement('div');
        panel.className = 'sc-panel';
        panel.innerHTML = '<input type="text" class="sc-q" placeholder="Escribe un color o material… (ej. ros petg)" autocomplete="off"><div class="sc-lista"></div>';
        document.body.appendChild(panel);
        const q = panel.querySelector('.sc-q');
        const cont = panel.querySelector('.sc-lista');
        let visibles = []; // items elegibles en orden
        let activo = -1;

        const ubicar = () => {
            if (!panel) return;
            const r = btn.getBoundingClientRect();
            const ancho = Math.max(r.width, 420);
            const izq = Math.min(Math.max(8, r.left), window.innerWidth - ancho - 8);
            const abajo = window.innerHeight - r.bottom - 12;
            const arriba = r.top - 12;
            const alto = Math.min(420, Math.max(abajo, arriba));
            panel.style.width = `${ancho}px`;
            panel.style.left = `${izq}px`;
            panel.style.maxHeight = `${alto}px`;
            if (abajo >= 240 || abajo >= arriba) panel.style.top = `${r.bottom + 4}px`;
            else panel.style.top = `${Math.max(8, r.top - 4 - Math.min(panel.offsetHeight, alto))}px`;
        };

        const fila = (it, extra = '') => {
            const agotado = Number(it.gramos) <= 0;
            const poco = !agotado && Number(it.gramos) < POCO;
            const k = clave(it);
            const esSel = sel && clave(comoSel(deSel(sel)) || {}) === k;
            return `<div class="sc-it ${poco ? 'poco' : ''} ${agotado ? 'agotado' : ''} ${esSel ? 'sel' : ''}" data-k="${esc(k)}" ${agotado ? 'data-off="1"' : ''}>
                <div class="sc-main">${puntoColor(it.color, 20)}<b>${esc(it.color)}</b><span class="sc-mat">${esc(it.material)}${it.etiqueta ? ' · ' + esc(it.etiqueta) : ''}</span>${extra}</div>
                <div class="sc-der">${poco ? '<i class="bi bi-exclamation-triangle-fill"></i> ' : ''}quedan ${gr(it.gramos)}<br>${it.der ? esc(it.der) : `cotiza a ${Q0(it.cotiza_a)}/kg`}</div></div>`
        };

        const pintarLista = () => {
            const palabras = norm(q.value).split(/\s+/).filter(Boolean);
            const ordenados = [...lista].sort((a, b) => norm(a.color).localeCompare(norm(b.color)) || norm(a.material).localeCompare(norm(b.material)));
            let html = '';
            let orden = [];
            const prev = anterior ? anterior() : null;
            const itPrev = prev && buscar(prev.material_id, prev.color);

            if (!palabras.length) {
                if (itPrev) {
                    html += `<button type="button" class="sc-igual" data-igual="1"><i class="bi bi-arrow-up-circle-fill"></i> Igual que la fila de arriba: ${puntoColor(itPrev.color, 14)}<b>${esc(itPrev.color)}</b> <span class="sc-mat">${esc(itPrev.material)}</span></button>`;
                }
                const rec = leerRecientes().map((k) => lista.find((i) => clave(i) === k)).filter(Boolean).slice(0, 4);
                if (rec.length) {
                    html += '<div class="sc-sec">Recientes</div>' + rec.map((i) => fila(i)).join('');
                    orden = orden.concat(rec);
                }
                html += `<div class="sc-sec">${rec.length ? 'Todos' : 'Colores'} (${ordenados.length})</div>` + ordenados.map((i) => fila(i)).join('');
                orden = orden.concat(ordenados);
            } else {
                const hallados = ordenados.filter((i) => {
                    const texto = norm(`${i.color} ${i.material}`);
                    return palabras.every((p) => texto.includes(p));
                });
                html += hallados.length
                    ? hallados.map((i) => fila(i)).join('')
                    : `<div class="sc-nada">No hay carretes con “${esc(q.value)}”. Si lo compraste, regístralo en Gastos &gt; Inventario.</div>`;
                orden = hallados;
            }
            cont.innerHTML = html;
            visibles = orden.filter((i) => Number(i.gramos) > 0);
            activo = -1;
            marcar(visibles.length && palabras.length ? 0 : -1);
        };

        const marcar = (i) => {
            activo = i;
            cont.querySelectorAll('.sc-it.act').forEach((x) => x.classList.remove('act'));
            if (i < 0 || !visibles[i]) return;
            // el primer elemento que coincide con la clave (en recientes y en la lista puede repetirse)
            const nodos = [...cont.querySelectorAll('.sc-it:not([data-off])')];
            const nodo = nodos[i] && nodos[i].dataset.k === clave(visibles[i]) ? nodos[i] : nodos.find((n) => n.dataset.k === clave(visibles[i]));
            if (nodo) {
                nodo.classList.add('act');
                nodo.scrollIntoView({ block: 'nearest' });
            }
        };

        const elegir = (it) => {
            if (!it) return;
            cerrar(true);
            poner(it);
        };

        q.addEventListener('input', pintarLista);
        q.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (visibles.length) marcar((activo + 1) % visibles.length); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (visibles.length) marcar((activo - 1 + visibles.length) % visibles.length); }
            else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); elegir(visibles[activo >= 0 ? activo : 0]); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cerrar(true); }
            else if (e.key === 'Tab') { cerrar(true); e.preventDefault(); }
        });
        cont.addEventListener('mousedown', (e) => e.preventDefault()); // no pierde el foco del buscador
        cont.addEventListener('click', (e) => {
            if (e.target.closest('[data-igual]')) {
                const p = anterior && anterior();
                const it = p && buscar(p.material_id, p.color);
                return elegir(it);
            }
            const nodo = e.target.closest('.sc-it');
            if (!nodo || nodo.dataset.off) return;
            elegir(lista.find((i) => clave(i) === nodo.dataset.k));
        });

        // Se cierra al tocar fuera o al mover la pantalla
        const fuera = (e) => { if (panel && !panel.contains(e.target) && !btn.contains(e.target)) cerrar(); };
        const mover = (e) => { if (panel && !panel.contains(e.target)) ubicar(); };
        document.addEventListener('mousedown', fuera, true);
        window.addEventListener('scroll', mover, true);
        window.addEventListener('resize', ubicar);
        quitarEscucha = () => {
            document.removeEventListener('mousedown', fuera, true);
            window.removeEventListener('scroll', mover, true);
            window.removeEventListener('resize', ubicar);
        };

        pintarLista();
        ubicar();
        q.focus();
    };

    btn.addEventListener('click', () => (panel ? cerrar(true) : abrir()));
    btn.addEventListener('keydown', (e) => {
        if (['ArrowDown', 'ArrowUp'].includes(e.key)) { e.preventDefault(); abrir(); }
    });

    // Al empezar a escribir con el botón enfocado, se abre y arranca la búsqueda
    btn.addEventListener('keypress', (e) => {
        if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && e.key !== ' ') {
            e.preventDefault();
            abrir();
            const q = panel && panel.querySelector('.sc-q');
            if (q) { q.value = e.key; q.dispatchEvent(new Event('input')); }
        }
    });

    // El valor inicial solo cuenta si ese carrete existe
    if (valor) sel = comoSel(deSel(valor) || (valor.id == null ? buscar(valor.material_id, valor.color) : null));
    pintarBtn();

    return {
        el,
        get: () => sel,
        set: (material_id, color) => { const it = buscar(material_id, color); sel = comoSel(it); pintarBtn(); return Boolean(it); },
        setItems: (nuevos) => { lista = nuevos; if (sel && !deSel(sel)) sel = null; pintarBtn(); },
        // Otros materiales en los que existe ese mismo color
        tambienEn: (color, materialId) => lista.filter((i) => norm(i.color) === norm(color) && String(i.material_id) !== String(materialId)).map((i) => i.material),
        cerrar,
    };
}
