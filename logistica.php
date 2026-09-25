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
function cfg(string $chave, $padrao = null) {
    static $cache = null;
    if ($cache === null) $cache = db()->query("SELECT chave, valor FROM configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $cache[$chave] ?? $padrao;
}
function cfg_salvar(string $chave, ?string $valor): void {
    db()->prepare("REPLACE INTO configuracoes (chave, valor) VALUES (?, ?)")->execute([$chave, $valor]);
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
    [$o, $n, $l, $s] = REGIAO_BUSCA;
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
