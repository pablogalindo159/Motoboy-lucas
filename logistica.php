<?php
// Lista de entregas do dia, localização no mapa, divisão em caixas e roteiro saindo do CD.
// Carregado pelo sacas.php.

// Região de busca (Curitiba e região metropolitana): oeste, norte, leste, sul
const REGIAO_BUSCA = [-49.50, -25.25, -48.95, -25.75];

function garantir_schema_v2(): void {
    $flag = __DIR__ . '/.schema_v2';
    if (file_exists($flag)) return;
    $col = fn($t, $c) => (bool)db()->query("SHOW COLUMNS FROM $t LIKE '$c'")->fetch();
    if (!$col('paradas', 'entrega'))     db()->exec("ALTER TABLE paradas ADD COLUMN entrega INT NULL AFTER numero, ADD INDEX (entrega)");
    if (!$col('paradas', 'geo_tentado')) db()->exec("ALTER TABLE paradas ADD COLUMN geo_tentado TINYINT(1) NOT NULL DEFAULT 0");
    if (!$col('sacas', 'entregas'))      db()->exec("ALTER TABLE sacas ADD COLUMN entregas TEXT NULL, ADD COLUMN compartilhada TINYINT(1) NOT NULL DEFAULT 0");
    if (!$col('rotas', 'chegada_cd'))    db()->exec("ALTER TABLE rotas ADD COLUMN chegada_cd DATETIME NULL, ADD COLUMN saida_cd DATETIME NULL");
    db()->exec("CREATE TABLE IF NOT EXISTS configuracoes (chave VARCHAR(50) PRIMARY KEY, valor TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS geocache (
        chave VARCHAR(255) PRIMARY KEY, lat DECIMAL(10,7) NULL, lng DECIMAL(10,7) NULL,
        fonte VARCHAR(20) NULL, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @touch($flag);
}
garantir_schema_v2();

// ---------- configurações ----------
function &cfg_cache(): array {
    static $cache = null;
    if ($cache === null) $cache = db()->query("SELECT chave, valor FROM configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $cache;
}
function cfg(string $chave, $padrao = null) { return cfg_cache()[$chave] ?? $padrao; }
function cfg_salvar(string $chave, ?string $valor): void {
    db()->prepare("REPLACE INTO configuracoes (chave, valor) VALUES (?, ?)")->execute([$chave, $valor]);
    $c = &cfg_cache(); $c[$chave] = $valor;
}
function cd_posicao(): ?array {
    $lat = cfg('cd_lat'); $lng = cfg('cd_lng');
    return ($lat && $lng) ? [(float)$lat, (float)$lng] : null;
}

// ---------- lista de entregas (.txt) ----------
// Aceita o formato do app: número / endereço / "N unidades" em linhas separadas.
// Também aceita uma linha por entrega: número;endereço;pacotes
function ler_lista_entregas(string $texto): array {
    $texto = str_replace(["\r", "\xEF\xBB\xBF"], '', $texto);
    $l = array_values(array_filter(array_map('trim', explode("\n", $texto)), fn($x) => $x !== ''));
    $itens = []; $ignoradas = [];
    for ($i = 0, $n = count($l); $i < $n;) {
        if (ctype_digit($l[$i]) && isset($l[$i + 2]) && preg_match('/^(\d+)\s*unidade/i', $l[$i + 2], $m)) {
            $itens[] = ['entrega' => (int)$l[$i], 'endereco_completo' => $l[$i + 1], 'pacotes' => max(1, (int)$m[1])];
            $i += 3;
        } elseif (preg_match('/^(\d+)\s*[;\t]\s*(.+?)\s*[;\t]\s*(\d+)/', $l[$i], $m)) {
            $itens[] = ['entrega' => (int)$m[1], 'endereco_completo' => $m[2], 'pacotes' => max(1, (int)$m[3])];
            $i++;
        } else {
            $ignoradas[] = $l[$i]; $i++;
        }
    }
    foreach ($itens as &$it) {
        // "Rua Luiz França, 1390" -> rua + número
        $pos = strrpos($it['endereco_completo'], ',');
        $it['rua'] = trim($pos === false ? $it['endereco_completo'] : substr($it['endereco_completo'], 0, $pos));
        $it['numero_casa'] = trim($pos === false ? '' : substr($it['endereco_completo'], $pos + 1));
        $it['caixa'] = intdiv($it['entrega'], 10) * 10;
    }
    unset($it);
    return ['itens' => $itens, 'ignoradas' => $ignoradas];
}

// ---------- localização (com cache: endereço repetido não consulta de novo) ----------
function geo_chave(string $rua, string $num): string { return mb_substr(sem_acento(trim("$rua, $num")), 0, 250); }

function geo_cache(string $rua, string $num): ?array {
    $s = db()->prepare("SELECT lat, lng FROM geocache WHERE chave = ?");
    $s->execute([geo_chave($rua, $num)]);
    $r = $s->fetch();
    if (!$r) return null;                       // nunca consultado
    return $r['lat'] ? [(float)$r['lat'], (float)$r['lng']] : [null, null]; // consultado, sem resultado
}

function http_json(string $url, array $headers = []) {
    $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => true]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r ? json_decode($r, true) : null;
}

// Consulta de verdade (Google se tiver chave, senão OpenStreetMap). Retorna [lat, lng, fonte, consultou_nominatim]
function geo_consultar(string $rua, string $num): array {
    [$o, $n, $l, $s] = regiao_busca();
    $chave = trim((string)cfg('google_key', ''));
    if ($chave !== '') {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => "$rua, $num, Paraná, Brasil", 'region' => 'br',
            'components' => 'country:BR|administrative_area:PR', 'bounds' => "$s,$o|$n,$l", 'key' => $chave,
        ]);
        $j = http_json($url);
        foreach ($j['results'] ?? [] as $res) {
            $tipos = $res['types'] ?? [];
            if (!array_intersect($tipos, ['street_address', 'premise', 'subpremise', 'route', 'establishment'])) continue;
            $p = $res['geometry']['location'];
            if ($p['lng'] >= $o && $p['lng'] <= $l && $p['lat'] <= $n && $p['lat'] >= $s) return [$p['lat'], $p['lng'], 'google', false];
        }
        return [null, null, 'google', false];
    }
    $ua = ['User-Agent: RotasMotoboy/1.0 (' . EMAIL_CONTATO . ')'];
    $base = ['format' => 'json', 'limit' => 1, 'countrycodes' => 'br', 'viewbox' => "$o,$n,$l,$s", 'bounded' => 1];
    $j = http_json('https://nominatim.openstreetmap.org/search?' . http_build_query($base + ['street' => trim("$num $rua"), 'state' => 'Paraná']), $ua);
    if (!empty($j[0]['lat'])) return [(float)$j[0]['lat'], (float)$j[0]['lon'], 'osm', true];
    usleep(1100000);
    $j = http_json('https://nominatim.openstreetmap.org/search?' . http_build_query($base + ['street' => $rua, 'state' => 'Paraná']), $ua);
    if (!empty($j[0]['lat'])) return [(float)$j[0]['lat'], (float)$j[0]['lon'], 'osm-rua', true];
    return [null, null, 'osm', true];
}

function geo_localizar(string $rua, string $num, bool &$consultou = false): array {
    $c = geo_cache($rua, $num);
    if ($c !== null) { $consultou = false; return $c; }
    [$lat, $lng, $fonte, $consultou] = geo_consultar($rua, $num);
    db()->prepare("REPLACE INTO geocache (chave, lat, lng, fonte) VALUES (?,?,?,?)")->execute([geo_chave($rua, $num), $lat, $lng, $fonte]);
    return [$lat, $lng];
}

// ---------- caixas a partir das paradas ----------
// Refaz as sacas da rota pelas entregas dela (caixa = entrega arredondada para a dezena),
// mantendo o que já foi marcado como coletado e avisando quando a caixa é dividida com outro motoboy.
function recalcular_sacas_rota(int $rotaId): void {
    $s = db()->prepare("SELECT entrega, pacotes FROM paradas WHERE rota_id = ? AND entrega IS NOT NULL ORDER BY entrega");
    $s->execute([$rotaId]);
    $caixas = [];
    foreach ($s->fetchAll() as $p) {
        $c = intdiv((int)$p['entrega'], 10) * 10;
        $caixas[$c]['qtd'] = ($caixas[$c]['qtd'] ?? 0) + (int)$p['pacotes'];
        $caixas[$c]['entregas'][] = (int)$p['entrega'];
    }
    if (!$caixas) return;

    $s = db()->prepare("SELECT caixa, coletada, coletada_em FROM sacas WHERE rota_id = ?");
    $s->execute([$rotaId]);
    $antes = [];
    foreach ($s->fetchAll() as $x) $antes[(int)$x['caixa']] = $x;

    // entregas de cada caixa em outras rotas do mesmo dia
    $s = db()->prepare("SELECT FLOOR(p.entrega/10)*10 caixa, COUNT(*) n FROM paradas p JOIN rotas r ON r.id = p.rota_id
                        WHERE r.data = (SELECT data FROM rotas WHERE id = ?) AND r.id <> ? AND p.entrega IS NOT NULL GROUP BY caixa");
    $s->execute([$rotaId, $rotaId]);
    $outras = array_column($s->fetchAll(), 'n', 'caixa');

    db()->prepare("DELETE FROM sacas WHERE rota_id = ?")->execute([$rotaId]);
    $ins = db()->prepare("INSERT INTO sacas (rota_id, caixa, quantidade, entregas, compartilhada, coletada, coletada_em) VALUES (?,?,?,?,?,?,?)");
    ksort($caixas);
    foreach ($caixas as $c => $x) {
        $ins->execute([$rotaId, $c, $x['qtd'], implode(',', $x['entregas']), !empty($outras[$c]) ? 1 : 0,
                       $antes[$c]['coletada'] ?? 0, $antes[$c]['coletada_em'] ?? null]);
    }
}

// ---------- roteiro ----------
function distancia_m(array $a, array $b): float {
    $r = M_PI / 180;
    $dLat = ($b[0] - $a[0]) * $r; $dLng = ($b[1] - $a[1]) * $r;
    $h = sin($dLat / 2) ** 2 + cos($a[0] * $r) * cos($b[0] * $r) * sin($dLng / 2) ** 2;
    return 12742000 * asin(min(1, sqrt($h)));
}

// Ordena as paradas pendentes saindo do CD: vizinho mais próximo + melhoria 2-opt.
// Paradas sem localização entram logo depois da entrega de número mais próximo.
function otimizar_rota(int $rotaId): array {
    $s = db()->prepare("SELECT id, entrega, numero, lat, lng, status FROM paradas WHERE rota_id = ? ORDER BY numero, entrega, id");
    $s->execute([$rotaId]);
    $todas = $s->fetchAll();
    $feitas = array_values(array_filter($todas, fn($p) => $p['status'] !== 'pendente'));
    $pend = array_values(array_filter($todas, fn($p) => $p['status'] === 'pendente'));
    $com = array_values(array_filter($pend, fn($p) => $p['lat'] !== null));
    $sem = array_values(array_filter($pend, fn($p) => $p['lat'] === null));

    $ordem = [];
    if ($com) {
        $pt = fn($p) => [(float)$p['lat'], (float)$p['lng']];
        $inicio = cd_posicao() ?? $pt($com[0]);
        // vizinho mais próximo
        $resto = $com; $atual = $inicio;
        while ($resto) {
            $melhor = null; $dm = INF;
            foreach ($resto as $k => $p) { $d = distancia_m($atual, $pt($p)); if ($d < $dm) { $dm = $d; $melhor = $k; } }
            $ordem[] = $resto[$melhor]; $atual = $pt($resto[$melhor]); unset($resto[$melhor]);
        }
        // 2-opt (caminho aberto, começo fixo no CD)
        $pts = array_merge([$inicio], array_map($pt, $ordem));
        $n = count($pts);
        $D = [];
        for ($i = 0; $i < $n; $i++) for ($j = $i; $j < $n; $j++) $D[$i][$j] = $D[$j][$i] = distancia_m($pts[$i], $pts[$j]);
        $idx = range(0, $n - 1);
        for ($passo = 0, $melhorou = true; $melhorou && $passo < 60; $passo++) {
            $melhorou = false;
            for ($i = 1; $i < $n - 1; $i++) {
                for ($k = $i + 1; $k < $n; $k++) {
                    $a = $idx[$i - 1]; $b = $idx[$i]; $c = $idx[$k]; $d = $k + 1 < $n ? $idx[$k + 1] : null;
                    $delta = $D[$a][$c] - $D[$a][$b] + ($d !== null ? $D[$b][$d] - $D[$c][$d] : 0);
                    if ($delta < -0.5) {
                        array_splice($idx, $i, $k - $i + 1, array_reverse(array_slice($idx, $i, $k - $i + 1)));
                        $melhorou = true;
                    }
                }
            }
        }
        $ordem = array_map(fn($i) => $ordem[$i - 1], array_slice($idx, 1));
    }
    // encaixa as sem localização
    foreach ($sem as $p) {
        if (!$ordem || $p['entrega'] === null) { $ordem[] = $p; continue; }
        $pos = 0; $dm = INF;
        foreach ($ordem as $k => $o) {
            if ($o['entrega'] === null) continue;
            $d = abs($o['entrega'] - $p['entrega']);
            if ($d < $dm) { $dm = $d; $pos = $k; }
        }
        array_splice($ordem, $pos + 1, 0, [$p]);
    }
    $up = db()->prepare("UPDATE paradas SET numero = ? WHERE id = ?");
    $num = count($feitas);
    foreach ($ordem as $p) $up->execute([++$num, $p['id']]);

    // distância estimada em linha reta (só para referência)
    $km = 0; $ant = cd_posicao();
    foreach ($ordem as $p) if ($p['lat'] !== null) { $q = [(float)$p['lat'], (float)$p['lng']]; if ($ant) $km += distancia_m($ant, $q); $ant = $q; }
    return ['paradas' => count($ordem), 'sem_local' => count($sem), 'km' => round($km / 1000, 1)];
}

// =====================================================================
// Distribuição por quadrante (só com a lista .txt, sem planilha de cores)
// =====================================================================
function garantir_schema_v3(): void {
    $flag = __DIR__ . '/.schema_v3';
    if (file_exists($flag)) return;
    db()->exec("CREATE TABLE IF NOT EXISTS entregas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data DATE NOT NULL,
        entrega INT NOT NULL,
        rua VARCHAR(200) NOT NULL,
        numero_casa VARCHAR(40) NULL,
        pacotes INT NOT NULL DEFAULT 1,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        geo_tentado TINYINT(1) NOT NULL DEFAULT 0,
        INDEX (data, entrega)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS quadrantes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(60) NOT NULL,
        cor VARCHAR(7) NOT NULL DEFAULT '#8CF20A',
        pontos MEDIUMTEXT NOT NULL,
        motoboy_id INT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @touch($flag);
}
garantir_schema_v3();

const PALETA = ['#8CF20A', '#00B0F0', '#FF0066', '#FFC000', '#9B59FF', '#00C49A', '#FF6A00', '#1F5FA8',
                '#D60093', '#7A5C00', '#00FFFF', '#B8352A', '#5E8C00', '#FF99CC', '#2F5597', '#BFBFBF'];

function quadrantes_ativos(): array {
    $q = db()->query("SELECT * FROM quadrantes WHERE ativo = 1 ORDER BY nome")->fetchAll();
    foreach ($q as &$x) $x['pontos'] = json_decode($x['pontos'], true) ?: [];
    return $q;
}

// Região de busca de endereços: em volta das zonas (com ~3 km de folga); sem zonas, a região metropolitana.
function regiao_busca(): array {
    static $r = null;
    if ($r !== null) return $r;
    $lat = []; $lng = [];
    foreach (db()->query("SELECT pontos FROM quadrantes WHERE ativo = 1")->fetchAll(PDO::FETCH_COLUMN) as $j)
        foreach (json_decode($j, true) ?: [] as $p) { $lat[] = $p[0]; $lng[] = $p[1]; }
    if (!$lat) return $r = REGIAO_BUSCA;
    $m = 0.03;
    return $r = [min($lng) - $m, max($lat) + $m, max($lng) + $m, min($lat) - $m];
}

// Zonas fixas (Mercado Livre) que vêm com o sistema
function carregar_quadrantes_fixos(bool $substituir = false): int {
    $zonas = json_decode((string)@file_get_contents(__DIR__ . '/quadrantes_fixos.json'), true) ?: [];
    if (!$zonas) return 0;
    $motoPorNome = [];
    if ($substituir) {
        foreach (db()->query("SELECT nome, motoboy_id FROM quadrantes WHERE motoboy_id IS NOT NULL ORDER BY id")->fetchAll() as $x) $motoPorNome[$x['nome']] ??= $x['motoboy_id'];
        db()->exec("DELETE FROM quadrantes");
    }
    $ins = db()->prepare("INSERT INTO quadrantes (nome, cor, pontos, motoboy_id) VALUES (?,?,?,?)");
    foreach ($zonas as $z) $ins->execute([$z['nome'], $z['cor'], json_encode($z['pontos']), $motoPorNome[$z['nome']] ?? null]);
    return count($zonas);
}

function garantir_schema_v4(): void {
    $flag = __DIR__ . '/.schema_v4';
    if (file_exists($flag)) return;
    if (!(int)db()->query("SELECT COUNT(*) FROM quadrantes")->fetchColumn()) carregar_quadrantes_fixos();
    @touch($flag);
}
garantir_schema_v4();

// distância em metros de um ponto até a borda do polígono
function distancia_borda(array $p, array $poli): float {
    $k = cos(deg2rad($p[0])) * 111320; $kl = 110540;
    $px = $p[1] * $k; $py = $p[0] * $kl; $min = INF; $n = count($poli);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $ax = $poli[$j][1] * $k; $ay = $poli[$j][0] * $kl; $bx = $poli[$i][1] * $k; $by = $poli[$i][0] * $kl;
        $dx = $bx - $ax; $dy = $by - $ay; $l2 = $dx * $dx + $dy * $dy;
        $t = $l2 ? max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $l2)) : 0;
        $min = min($min, hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy)));
    }
    return $min;
}

// ponto [lat,lng] dentro do polígono [[lat,lng],...]
function dentro_poligono(array $p, array $poli): bool {
    $dentro = false; $n = count($poli);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$yi, $xi] = $poli[$i]; [$yj, $xj] = $poli[$j];
        if ((($yi > $p[0]) !== ($yj > $p[0])) && ($p[1] < ($xj - $xi) * ($p[0] - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $dentro = !$dentro;
    }
    return $dentro;
}

// Entregas sem localização vão para o grupo da entrega de número mais próximo
// (a numeração da lista já segue a região).
function completar_sem_local(array $entregas, array &$grupoDe): void {
    $com = array_filter($entregas, fn($e) => isset($grupoDe[$e['id']]));
    if (!$com) return;
    foreach ($entregas as $e) {
        if (isset($grupoDe[$e['id']])) continue;
        $melhor = null; $dm = PHP_INT_MAX;
        foreach ($com as $c) { $d = abs($c['entrega'] - $e['entrega']); if ($d < $dm) { $dm = $d; $melhor = $c['id']; } }
        $grupoDe[$e['id']] = $grupoDe[$melhor];
    }
}

function entregas_do_dia(string $data): array {
    $s = db()->prepare("SELECT * FROM entregas WHERE data = ? ORDER BY entrega");
    $s->execute([$data]);
    return $s->fetchAll();
}

/** Grupos pelos quadrantes desenhados. Retorna [grupos, avisos]. */
function grupos_por_quadrante(string $data): array {
    $quads = quadrantes_ativos();
    $entregas = entregas_do_dia($data);
    $grupoDe = []; $fora = 0;
    foreach ($entregas as $e) {
        if ($e['lat'] === null) continue;
        $p = [(float)$e['lat'], (float)$e['lng']];
        $achou = null; $fundo = -1;
        // dentro de mais de uma zona (sobreposição): fica na zona onde está mais "para dentro"
        foreach ($quads as $q) {
            if (count($q['pontos']) < 3 || !dentro_poligono($p, $q['pontos'])) continue;
            $d = distancia_borda($p, $q['pontos']);
            if ($d > $fundo) { $fundo = $d; $achou = $q['id']; }
        }
        if ($achou === null && $quads) { // fora de todas (fresta entre zonas): vai para a borda mais perto
            $fora++; $dm = INF;
            foreach ($quads as $q) { if (count($q['pontos']) < 3) continue; $d = distancia_borda($p, $q['pontos']); if ($d < $dm) { $dm = $d; $achou = $q['id']; } }
        }
        if ($achou !== null) $grupoDe[$e['id']] = 'q' . $achou;
    }
    completar_sem_local($entregas, $grupoDe);
    $grupos = [];
    foreach ($quads as $q) $grupos['q' . $q['id']] = ['chave' => 'q' . $q['id'], 'nome' => $q['nome'], 'cor' => $q['cor'], 'motoboy_id' => $q['motoboy_id'], 'entregas' => []];
    foreach ($entregas as $e) if (isset($grupoDe[$e['id']])) $grupos[$grupoDe[$e['id']]]['entregas'][] = $e;
    return [array_values(array_filter($grupos, fn($g) => $g['entregas'])), ['fora' => $fora]];
}

/** Divisão automática em setores (fatias de pizza saindo do CD), com pacotes equilibrados. */
function grupos_por_setor(string $data, array $motoboyIds): array {
    $n = max(1, count($motoboyIds));
    $entregas = entregas_do_dia($data);
    $com = array_values(array_filter($entregas, fn($e) => $e['lat'] !== null));
    if (!$com) return [[], []];
    $centro = cd_posicao();
    if (!$centro) $centro = [array_sum(array_column($com, 'lat')) / count($com), array_sum(array_column($com, 'lng')) / count($com)];
    $k = cos(deg2rad($centro[0]));
    foreach ($com as &$e) $e['ang'] = atan2((float)$e['lat'] - $centro[0], ((float)$e['lng'] - $centro[1]) * $k);
    unset($e);
    usort($com, fn($a, $b) => $a['ang'] <=> $b['ang']);
    // começa depois do maior "buraco" para não cortar um bairro no meio
    $m = count($com); $gap = -1; $ini = 0;
    for ($i = 0; $i < $m; $i++) {
        $g = ($i + 1 < $m ? $com[$i + 1]['ang'] : $com[0]['ang'] + 2 * M_PI) - $com[$i]['ang'];
        if ($g > $gap) { $gap = $g; $ini = ($i + 1) % $m; }
    }
    $com = array_merge(array_slice($com, $ini), array_slice($com, 0, $ini));
    $total = array_sum(array_column($entregas, 'pacotes')); $alvo = $total / $n; $acc = 0; $grupoDe = [];
    foreach ($com as $e) { $grupoDe[$e['id']] = 's' . min($n - 1, (int)floor(($acc + $e['pacotes'] / 2) / $alvo)); $acc += $e['pacotes']; }
    completar_sem_local($entregas, $grupoDe);
    $grupos = [];
    for ($i = 0; $i < $n; $i++) $grupos['s' . $i] = ['chave' => 's' . $i, 'nome' => 'Setor ' . ($i + 1), 'cor' => PALETA[$i % count(PALETA)], 'motoboy_id' => $motoboyIds[$i] ?? null, 'entregas' => []];
    foreach ($entregas as $e) if (isset($grupoDe[$e['id']])) $grupos[$grupoDe[$e['id']]]['entregas'][] = $e;
    return [array_values($grupos), []];
}

/** Cria as rotas do dia a partir dos grupos (substitui as rotas desse dia). */
function criar_rotas_do_dia(string $data, array $grupos): array {
    $porMoto = [];
    foreach ($grupos as $g) {
        if (empty($g['motoboy_id'])) continue;
        $m = &$porMoto[(int)$g['motoboy_id']];
        $m['nomes'][] = $g['nome']; $m['cor'] ??= $g['cor'];
        $m['entregas'] = array_merge($m['entregas'] ?? [], $g['entregas']);
        unset($m);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM rotas WHERE data = ?")->execute([$data]);
        $insR = $pdo->prepare("INSERT INTO rotas (motoboy_id, data, descricao, cor) VALUES (?,?,?,?)");
        $insP = $pdo->prepare("INSERT INTO paradas (rota_id, numero, entrega, endereco, numero_casa, cidade, pacotes, lat, lng, geo_tentado) VALUES (?,?,?,?,?,'',?,?,?,1)");
        $rotas = [];
        foreach ($porMoto as $mid => $m) {
            $insR->execute([$mid, $data, implode(' + ', $m['nomes']), $m['cor']]);
            $rid = (int)$pdo->lastInsertId(); $rotas[] = $rid;
            usort($m['entregas'], fn($a, $b) => $a['entrega'] <=> $b['entrega']);
            $n = 0;
            foreach ($m['entregas'] as $e) $insP->execute([$rid, ++$n, $e['entrega'], $e['rua'], $e['numero_casa'], $e['pacotes'], $e['lat'], $e['lng']]);
        }
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
    // a sequência de entrega é a do número da lista (.txt): 1, 2, 3...
    foreach ($rotas as $rid) recalcular_sacas_rota($rid);
    return $rotas;
}
