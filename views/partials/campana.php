<?php /* Campana de avisos. Necesita $base (la define layout.php). */ ?>
<style>
    /* Campana de avisos: llamativa cuando hay algo pendiente */
    #pedidosBtn { background: transparent; color: #fff; border-color: rgba(255,255,255,.6); }
    #pedidosBtn:hover, #pedidosBtn:focus, #pedidosBtn.show { background: rgba(255,255,255,.18); color: #fff; border-color: #fff; }
    #campanaBtn { background: transparent; color: #fff; border-color: rgba(255,255,255,.6); }
    #campanaBtn:hover, #campanaBtn:focus, #campanaBtn.show { background: rgba(255,255,255,.18); color: #fff; border-color: #fff; }
    #campanaBtn .bi-bell-fill { display: inline-block; transform-origin: 50% 0; }

    @keyframes hc-campana-suena {
        0%, 80%, 100% { transform: rotate(0); }
        10% { transform: rotate(22deg); } 20% { transform: rotate(-20deg); } 30% { transform: rotate(16deg); }
        40% { transform: rotate(-14deg); } 50% { transform: rotate(9deg); } 60% { transform: rotate(-6deg); } 70% { transform: rotate(3deg); }
    }
    @keyframes hc-campana-brilla {
        0%   { box-shadow: 0 0 0 0 rgba(246,195,59,.85), 0 0 10px 2px rgba(246,195,59,.55); }
        70%  { box-shadow: 0 0 0 14px rgba(246,195,59,0), 0 0 16px 4px rgba(246,195,59,.25); }
        100% { box-shadow: 0 0 0 0 rgba(246,195,59,0), 0 0 10px 2px rgba(246,195,59,.55); }
    }
    @keyframes hc-campana-latido {
        0%, 100% { transform: translate(-50%, -50%) scale(1); }
        50% { transform: translate(-50%, -50%) scale(1.3); }
    }

    /* Una sola vez al entrar a la pantalla (no en bucle). Luego queda quieta; los urgentes conservan el borde dorado */
    #campanaBtn.hc-urgente { border-color: #f6c33b; color: #f6c33b; animation: hc-campana-brilla 1.8s 3; }
    #campanaBtn.hc-urgente .bi-bell-fill { animation: hc-campana-suena 1.5s 2; }
    #campanaBtn.hc-urgente #campanaN { animation: hc-campana-latido 1.2s 3; }
    #campanaBtn.hc-normal .bi-bell-fill { animation: hc-campana-suena 1.5s 1; }

    /* Etiqueta de fecha de los recordatorios: destaca y brilla una sola vez al abrir la campana */
    .hc-vence { display: inline-block; margin-top: 3px; padding: 2px 10px; border-radius: 999px; font-size: .78rem; font-weight: 800; letter-spacing: .02em; }
    .hc-vence.manana  { background: #f6c33b; color: #1c1c1c; }
    .hc-vence.hoy     { background: #fd7e14; color: #fff; }
    .hc-vence.vencido { background: #c92a4b; color: #fff; }
    #campana .dropdown-menu.show .hc-vence { animation: hc-vence-brilla 1.4s 2; }
    @keyframes hc-vence-brilla {
        0%   { box-shadow: 0 0 0 0 rgba(246,195,59,.9); }
        70%  { box-shadow: 0 0 0 9px rgba(246,195,59,0); }
        100% { box-shadow: 0 0 0 0 rgba(246,195,59,0); }
    }

    @media (prefers-reduced-motion: reduce) {
        .hc-vence { animation: none !important; }
        #campanaBtn, #campanaBtn .bi-bell-fill, #campanaBtn #campanaN { animation: none !important; }
    }
</style>
                <!-- Pedidos en curso: cuántos hay y en qué etapa van -->
                <div class="dropdown me-lg-2 mb-lg-0 mb-2 text-lg-start text-center" id="pedidosCaja">
                    <button class="btn btn-outline-light position-relative" type="button" id="pedidosBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Pedidos en curso">
                        <i class="bi bi-box-seam fs-5"></i>
                        <span id="pedidosN" class="position-absolute top-0 start-100 translate-middle badge rounded-pill d-none" style="background:#0d6efd">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-0 shadow" style="width:min(420px,92vw);max-height:70vh;overflow:auto" aria-labelledby="pedidosBtn">
                        <div class="px-3 py-2 fw-bold border-bottom" style="background:#f4f4f1"><i class="bi bi-box-seam me-2"></i>Pedidos en curso <span class="fw-normal text-muted small" id="pedidosSub"></span></div>
                        <div id="pedidosLista"><div class="p-3 text-muted small">Cargando…</div></div>
                        <a class="d-block text-center py-2 small fw-bold text-decoration-none border-top" style="background:#f4f4f1;color:#111" href="<?= $base ?>/pedidos">Ver el tablero de pedidos →</a>
                    </div>
                </div>
                <!-- Campana de avisos: pagos pendientes, entregas y filamento -->
                <div class="dropdown me-lg-3 mb-lg-0 mb-2 text-lg-start text-center" id="campana">
                    <button class="btn btn-outline-light position-relative" type="button" id="campanaBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Avisos y recordatorios">
                        <i class="bi bi-bell-fill fs-5"></i>
                        <span id="campanaN" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-0 shadow" style="width:min(400px,92vw);max-height:70vh;overflow:auto" aria-labelledby="campanaBtn">
                        <div class="px-3 py-2 fw-bold border-bottom" style="background:#f4f4f1"><i class="bi bi-bell me-2"></i>Avisos <span class="fw-normal text-muted small" id="campanaSub"></span></div>
                        <div id="campanaLista"><div class="p-3 text-muted small">Cargando…</div></div>
                    </div>
                </div>

    <script>
        // Campana de avisos (se actualiza sola al abrir la pantalla, al volver a la pestaña y después de guardar algo)
        (function () {
            var BASE = <?= json_encode($base ?? "") ?>;
            var btn = document.getElementById('campanaN'), lista = document.getElementById('campanaLista'), sub = document.getElementById('campanaSub');
            if (!btn) return;
            var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
            var Q = function (n) { return 'Q' + Number(n).toLocaleString('es-GT', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
            var color = { alta: '#d6336c', media: '#e0a800' };
            var origFetch = window.fetch.bind(window);

            var ETAPA_COLOR = {solicitado: '#6c757d', imprimiendo: '#0d6efd', impreso: '#0aa2c0', pintando: '#6f42c1', listo: '#2f9e44'};
            function pintarPedidos(l) {
                var n = document.getElementById('pedidosN'), box = document.getElementById('pedidosLista'), sub = document.getElementById('pedidosSub');
                n.textContent = l.length;
                n.classList.toggle('d-none', !l.length);
                sub.textContent = l.length ? '· ' + l.length + (l.length === 1 ? ' pedido' : ' pedidos') : '';
                box.innerHTML = l.length ? l.map(function (p) {
                    var col = ETAPA_COLOR[p.estado] || '#6c757d', barras = '';
                    for (var i = 1; i <= 5; i++) barras += '<span style="flex:1;height:6px;border-radius:3px;background:' + (i <= p.paso ? col : '#e4e1d8') + '"></span>';
                    return '<a class="d-block text-decoration-none text-dark px-3 py-2 border-bottom" href="' + BASE + '/pedidos" style="border-left:5px solid ' + col + '">'
                        + '<span class="d-flex justify-content-between align-items-start gap-2"><b>' + esc(p.nombre) + '</b>'
                        + '<span class="badge rounded-pill" style="background:' + col + '">' + esc(p.etapa) + '</span></span>'
                        + '<span class="d-flex justify-content-between small text-muted"><span>' + esc(p.cliente) + ' · ' + (p.dias === 0 ? 'de hoy' : 'hace ' + p.dias + (p.dias === 1 ? ' día' : ' días')) + '</span>'
                        + (p.saldo > 0.005 ? '<b class="text-danger">debe ' + Q(p.saldo) + '</b>' : '<b class="text-success">pagado</b>') + '</span>'
                        + '<span class="d-flex gap-1 mt-1">' + barras + '</span></a>';
                }).join('') : '<div class="p-4 text-center text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No tienes pedidos en curso.</div>';
            }

            var previos = -1;
            function pintar(r) {
                pintarPedidos(r.pedidos || []);
                var l = r.datos || [];
                btn.textContent = l.length;
                btn.classList.toggle('d-none', !l.length);
                btn.classList.toggle('bg-danger', r.urgentes > 0);
                btn.classList.toggle('bg-warning', !(r.urgentes > 0));
                var cb = document.getElementById('campanaBtn');
                // Si llegó un aviso nuevo mientras tienes la pantalla abierta, la campana vuelve a moverse una vez
                if (previos >= 0 && l.length > previos) { cb.classList.remove('hc-urgente', 'hc-normal'); void cb.offsetWidth; }
                previos = l.length;
                cb.classList.toggle('hc-urgente', r.urgentes > 0);
                cb.classList.toggle('hc-normal', l.length > 0 && !(r.urgentes > 0));
                btn.classList.toggle('text-dark', !(r.urgentes > 0));
                sub.textContent = l.length ? '· ' + l.length + (l.length === 1 ? ' pendiente' : ' pendientes') : '';
                lista.innerHTML = l.length ? l.map(function (a) {
                    return '<a class="d-flex gap-2 text-decoration-none text-dark px-3 py-2 border-bottom align-items-start" style="border-left:5px solid ' + color[a.nivel] + '" href="' + BASE + a.url + '">'
                        + '<i class="bi ' + esc(a.icono) + ' fs-5 mt-1" style="color:' + color[a.nivel] + '"></i>'
                        + '<span class="flex-grow-1"><b class="d-block">' + esc(a.titulo) + '</b><span class="small text-muted">' + esc(a.detalle) + '</span>'
                        + (a.etiqueta ? '<span class="d-block"><span class="hc-vence ' + esc(a.etiqueta.clase) + '"><i class="bi bi-alarm me-1"></i>' + esc(a.etiqueta.texto) + '</span></span>' : '') + '</span>'
                        + (a.monto ? '<b class="text-nowrap">' + Q(a.monto) + '</b>' : '') + '</a>';
                }).join('') : '<div class="p-4 text-center text-muted"><i class="bi bi-check-circle fs-2 d-block mb-2" style="color:#2f9e44"></i>Todo al día. No tienes nada pendiente.</div>';
            }

            var cargando = false;
            function cargar() {
                if (cargando) return;
                cargando = true;
                origFetch(BASE + '/API/alertas', {headers: {'Accept': 'application/json'}})
                    .then(function (x) { return x.json(); })
                    .then(function (r) { if (r && r.codigo === 1) pintar(r); })
                    .catch(function () {})
                    .then(function () { cargando = false; });
            }

            // Después de guardar algo (pago, cobro, compra, cambio de etapa...) se vuelve a revisar
            window.fetch = function (url, opts) {
                var p = origFetch(url, opts);
                if (opts && opts.method && String(opts.method).toUpperCase() !== 'GET' && String(url).indexOf('/API/alertas') < 0) {
                    p.then(function () { setTimeout(cargar, 400); }).catch(function () {});
                }
                return p;
            };
            document.addEventListener('visibilitychange', function () { if (!document.hidden) cargar(); });
            setInterval(cargar, 300000);
            cargar();
        })();
    </script>
