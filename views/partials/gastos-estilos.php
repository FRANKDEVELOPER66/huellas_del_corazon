<style>
    .caja-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: .7rem; }
    .caja-box { border-radius: 12px; padding: .7rem .95rem; border-left: 5px solid #888; background: #f4f4f1; }
    .caja-box span { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .4px; color: #555; }
    .caja-box b { display: block; font-size: 1.7rem; line-height: 1.2; }
    .caja-box small { color: #666; font-size: .76rem; }
    .caja-box.k { background: color-mix(in srgb, var(--c) 9%, #fff); border-color: var(--c); }
    .caja-box.k b { color: var(--c); font-size: 1.9rem; }
    .caja-box.k span { color: var(--c); font-weight: 600; }
    .caja-box.k span i { font-size: 1.05rem; margin-right: .4rem; }
    .caja-box.azul { background: #e8f0fb; border-color: #1d5fa8; } .caja-box.azul b { color: #1d5fa8; }
    .caja-box.ambar { background: #fff4e0; border-color: #b26a00; } .caja-box.ambar b { color: #b26a00; }
    .caja-box.verde { background: #e8f6ee; border-color: #117a43; } .caja-box.verde b { color: #117a43; font-size: 2rem; }
    .caja-box.rojo { background: #fdecea; border-color: #b42318; } .caja-box.rojo b { color: #b42318; font-size: 2rem; }
    .est { display: inline-block; padding: .12rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
    .est-ok { background: #e8f6ee; color: #117a43; }
    .est-bajo { background: #fff4e0; color: #b26a00; }
    .est-sin { background: #fdecea; color: #b42318; }
    .txt-ok { color: #117a43; } .txt-bajo { color: #b26a00; } .txt-sin { color: #b42318; }
    .pieza-card { border: 1px solid var(--pc); border-left: 6px solid var(--pc); border-radius: 12px; margin-bottom: .75rem; background: #fff; overflow: hidden; }
    .pieza-head { background: color-mix(in srgb, var(--pc) 12%, #fff); padding: .6rem .9rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .pieza-head small { display: block; font-size: .68rem; letter-spacing: .5px; color: #666; }
    .pieza-head b { font-size: 1.2rem; color: var(--pc); text-transform: uppercase; }
    .pieza-linea { display: flex; gap: .75rem; align-items: center; padding: .55rem .9rem; border-top: 1px solid #eee; flex-wrap: wrap; }
    .cpt { padding: .15rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 700; min-width: 96px; text-align: center; }
    .cpt-pintura { background: #efe7fb; color: #5b2fb0; }
    .cpt-envio { background: #e3effd; color: #1d5fa8; }
    .cpt-otros { background: #fff1d6; color: #9a5b00; }
    .pieza-falta { color: #b26a00; font-size: 1.1rem; min-width: 90px; text-align: right; }
</style>
