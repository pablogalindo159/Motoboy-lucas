// Peças comuns dos mapas: quadrantes e bolinhas numeradas das entregas.
window.NP = window.NP || {};
(() => {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const txtSobre = hex => { const h = String(hex || '').replace('#', ''); if (h.length !== 6) return '#fff';
    const [r, g, b] = [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16)); return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#111' : '#fff'; };

  // Zoom do bairro para cima: número dentro da bolinha. Mais afastado: bolinha pequena, sem número.
  NP.ZOOM_NUMERO = 14;
  NP.prepararMapa = mapa => {
    const aplicar = () => mapa.getContainer().classList.toggle('np-longe', mapa.getZoom() < NP.ZOOM_NUMERO);
    mapa.on('zoomend', aplicar); aplicar();
  };

  // Quadrantes: contorno na cor da zona e o nome no meio. Ficam por baixo das entregas.
  NP.quadrantes = (mapa, quads, opcoes = {}) => {
    const g = L.layerGroup();
    if (!mapa.getPane('quadrantes')) { mapa.createPane('quadrantes'); mapa.getPane('quadrantes').style.zIndex = 350; }
    (quads || []).forEach(q => {
      if (!q.pontos || q.pontos.length < 3) return;
      const p = L.polygon(q.pontos, { pane: 'quadrantes', color: q.cor, weight: opcoes.escuro ? 2 : 1.8, opacity: .9, fillColor: q.cor,
                                      fillOpacity: opcoes.escuro ? .07 : .06, dashArray: '6 4', interactive: false }).addTo(g);
      const motos = (q.motoboys || []).filter(Boolean);
      L.tooltip({ permanent: true, direction: 'center', className: 'np-rotulo-quad' + (opcoes.escuro ? ' escuro' : ''), interactive: false })
        .setContent(esc(q.nome) + (motos.length ? `<span class="np-quad-moto">🛵 ${motos.map(esc).join(', ')}</span>` : ''))
        .setLatLng(p.getBounds().getCenter()).addTo(g);
    });
    return g.addTo(mapa);
  };

  // Bolinha de entrega com o número dentro.
  // status: pendente (cor do motoboy) · entregue (verde) · falhou (vermelho). destaque = próxima entrega.
  NP.pino = (latlng, o) => {
    const cor = o.status === 'entregue' ? '#1E7F47' : o.status === 'falhou' ? '#B8352A' : (o.cor || '#8CF20A');
    const cls = 'np-pino' + (o.destaque ? ' destaque' : '') + (o.status && o.status !== 'pendente' ? ' feito' : '') + (o.extra ? ' ' + o.extra : '');
    const m = L.marker(latlng, {
      icon: L.divIcon({ className: '', iconSize: [0, 0],
        html: `<div class="${cls}" style="--c:${cor};--t:${txtSobre(cor)}">${esc(o.num ?? '')}</div>` }),
      draggable: !!o.arrastar, keyboard: false, zIndexOffset: o.destaque ? 500 : 0,
    });
    if (o.titulo) m.bindTooltip(o.titulo, { direction: 'top', offset: [0, -10] });
    return m;
  };
})();
