<?php
require __DIR__ . '/config.php';
require __DIR__ . '/sacas.php';
exigir('admin');
if (($_POST['acao'] ?? '') === 'novo_link_tv' && csrf_ok()) { cfg_salvar('tv_chave', bin2hex(random_bytes(12))); redirecionar('admin.php'); }
if (!cfg('tv_chave')) cfg_salvar('tv_chave', bin2hex(random_bytes(12)));
$linkTv = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/tv.php?chave=' . cfg('tv_chave');
$linkTv = str_replace('//tv.php', '/tv.php', $linkTv);
$data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data'] ?? '') ? $_GET['data'] : date('Y-m-d');
topo('Painel', 'painel', true);
?>
<link rel="stylesheet" href="assets/sacas.css?v=3">
<div class="painel">
  <aside class="lateral">
    <form class="filtro-data">
      <label>Dia <input type="date" name="data" value="<?= e($data) ?>" onchange="this.form.submit()"></label>
    </form>
    <div class="resumo" id="resumo"></div>
    <div id="lista"><p class="dica">Carregando…</p></div>
    <p class="dica" id="atualizado"></p>
    <a class="btn largo" href="importar_entregas.php?data=<?= e($data) ?>">Carregar lista de entregas do dia</a>
    <a class="btn largo" href="quadrantes.php" style="margin-top:.4rem">Quadrantes</a>
    <details class="cartao tv-link">
      <summary>Tela da TV</summary>
      <p class="dica">Abra este link no navegador da TV. Ele mostra o mapa ao vivo sem precisar de login e não permite mudar nada.</p>
      <input readonly value="<?= e($linkTv) ?>" onclick="this.select()">
      <div class="acoes">
        <a class="btn pequeno primario" href="<?= e($linkTv) ?>" target="_blank">Abrir tela da TV</a>
        <form method="post" onsubmit="return confirm('O link antigo para de funcionar. Gerar outro?')"><?= csrf_field() ?><button class="btn pequeno" name="acao" value="novo_link_tv">Gerar novo link</button></form>
      </div>
    </details>
  </aside>
  <div id="mapa" class="mapa-painel"></div>
</div>

<script>
const DATA = <?= json_encode($data) ?>;
const mapa = L.map('mapa').setView([<?= MAPA_LAT ?>, <?= MAPA_LNG ?>], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(mapa);

const camadas = L.layerGroup().addTo(mapa);
let marcadorCd = null;
const hora = d => d ? d.substr(11, 5) : '';
function etapa(m) {
  if (!m.sacas) return '';
  if (m.saida_cd) return `Saiu do CD às ${hora(m.saida_cd)}`;
  if (m.chegada_cd) return `No CD desde ${hora(m.chegada_cd)} · caixas ${m.sacas_coletadas}/${m.sacas}`;
  return 'Ainda não chegou no CD';
}
const cores = ['#1F5FA8', '#7A3FA0', '#0E7C7B', '#B5501B', '#4E5D6C', '#A0306B'];
let focado = null, primeiraVez = true;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function corTexto(hex) {
  const h = hex.replace('#', ''); if (h.length !== 6) return '#fff';
  const [r, g, b] = [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16));
  return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#1B2B34' : '#fff';
}
const iniciais = n => n.split(' ').filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase();

function sinal(min) {
  if (min === null) return '<span class="sinal off">Sem localização ainda</span>';
  if (min <= 2) return '<span class="sinal on">Ao vivo</span>';
  if (min < 60) return `<span class="sinal atraso">Visto há ${min} min</span>`;
  return `<span class="sinal off">Visto há ${Math.floor(min / 60)} h</span>`;
}

async function carregar() {
  let dados;
  try {
    const r = await fetch('api.php?acao=painel&data=' + DATA);
    if (r.status === 401) { location.href = 'index.php'; return; }
    dados = await r.json();
  } catch (e) { document.getElementById('atualizado').textContent = 'Sem conexão — tentando de novo…'; return; }

  camadas.clearLayers();
  if (dados.cd && !marcadorCd) marcadorCd = L.marker(dados.cd, { zIndexOffset: 2000, icon: L.divIcon({ className: '', html: '<div class="mapa-cd">CD</div>', iconSize: [34, 24], iconAnchor: [17, 12] }) }).addTo(mapa).bindPopup('Centro de distribuição');
  const limites = [];
  let tEnt = 0, tFaltam = 0, tPac = 0, tPacEnt = 0;
  const lista = document.getElementById('lista');
  lista.innerHTML = '';

  dados.motoboys.forEach((m, i) => {
    const cor = m.cor || cores[i % cores.length];
    const txt = corTexto(cor);
    const total = m.paradas.length;
    const ent = m.paradas.filter(p => p.status === 'entregue').length;
    const falha = m.paradas.filter(p => p.status === 'falhou').length;
    const faltam = total - ent - falha;
    const pac = m.paradas.reduce((s, p) => s + +p.pacotes, 0);
    const pacEnt = m.paradas.filter(p => p.status === 'entregue').reduce((s, p) => s + +p.pacotes, 0);
    const prox = m.paradas.find(p => p.status === 'pendente');
    tEnt += ent; tFaltam += faltam; tPac += pac; tPacEnt += pacEnt;

    // paradas
    m.paradas.forEach(p => {
      if (!p.lat) return;
      const ll = [+p.lat, +p.lng]; limites.push(ll);
      L.marker(ll, { icon: L.divIcon({ className: '', html: `<div class="pino ${p.status}" style="--cor:${cor}">${p.numero}</div>`, iconSize: [26, 26], iconAnchor: [13, 13] }) })
        .addTo(camadas)
        .bindPopup(`<b>${esc(m.nome)} · parada ${p.numero}</b><br>${esc(p.endereco)}, ${esc(p.numero_casa)}<br>${p.pacotes} pacote(s) — ${p.status === 'pendente' ? 'pendente' : (p.status === 'entregue' ? 'entregue' : 'não entregue')}${p.finalizado_em ? ' às ' + p.finalizado_em.substr(11, 5) : ''}`);
    });

    // trajeto e posição
    if (m.trajeto.length > 1) L.polyline(m.trajeto, { color: cor, weight: 3, opacity: .5 }).addTo(camadas);
    let marcador = null;
    if (m.lat) {
      const ll = [+m.lat, +m.lng]; limites.push(ll);
      marcador = L.marker(ll, { zIndexOffset: 1000, icon: L.divIcon({ className: '', html: `<div class="moto ${m.minutos_sem_sinal !== null && m.minutos_sem_sinal <= 2 ? 'vivo' : ''}" style="--cor:${cor};color:${txt};border-color:#1B2B34">🛵 ${esc(iniciais(m.nome))}</div>`, iconSize: [70, 30], iconAnchor: [35, 15] }) })
        .addTo(camadas).bindPopup(`<b>${esc(m.nome)}</b> ${esc(m.placa || '')}<br>${ent} de ${total} entregas · faltam ${faltam}`);
    }

    // cartão lateral
    const pct = total ? Math.round((ent + falha) / total * 100) : 0;
    const c = document.createElement('button');
    c.type = 'button'; c.className = 'cartao-moto' + (focado === m.id ? ' focado' : ''); c.style.setProperty('--cor', cor);
    c.innerHTML = `
      <div class="topo-moto"><strong>${esc(m.nome)}</strong>${sinal(m.minutos_sem_sinal)}</div>
      <div class="contagem"><span><b>${ent}</b> entregues</span><span><b>${faltam}</b> faltam</span>${falha ? `<span><b>${falha}</b> sem sucesso</span>` : ''}</div>
      <div class="progresso"><span style="width:${pct}%"></span></div>
      ${m.sacas ? `<small>${etapa(m)}</small>` : ''}
      <small>${pacEnt} de ${pac} pacotes${prox ? ` · próxima: parada ${prox.numero}, ${esc(prox.endereco)}` : (total ? ' · rota concluída' : '')}</small>`;
    c.onclick = () => {
      focado = m.id;
      if (marcador) { mapa.setView(marcador.getLatLng(), 16); marcador.openPopup(); }
      document.querySelectorAll('.cartao-moto').forEach(x => x.classList.remove('focado')); c.classList.add('focado');
    };
    lista.appendChild(c);
  });

  if (!dados.motoboys.length) lista.innerHTML = '<p class="vazio">Nenhuma rota neste dia. <a href="rotas.php?data=' + DATA + '">Criar rota</a></p>';
  document.getElementById('resumo').innerHTML = `
    <div><b>${tEnt}</b><span>entregas feitas</span></div>
    <div><b>${tFaltam}</b><span>faltam</span></div>
    <div><b>${tPacEnt}/${tPac}</b><span>pacotes</span></div>`;
  document.getElementById('atualizado').textContent = 'Atualizado às ' + dados.atualizado + ' · atualiza sozinho a cada 15 s';
  if (primeiraVez && limites.length) { mapa.fitBounds(limites, { padding: [40, 40], maxZoom: 15 }); primeiraVez = false; }
}
carregar();
setInterval(carregar, 15000);
</script>
<?php rodape();
