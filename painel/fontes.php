<?php
declare(strict_types=1);
// De onde vêm os números do painel: o próprio site, o Google (pelo GitHub), o Instagram e os anúncios da Meta (lidos na hora, com cache).
// As funções *_resumo, *_afazeres e *_dados não têm efeitos (teste: scripts/test_painel.php). Spec: scripts/ESPECIFICACAO_PAINEL.md.

/** GET JSON: [código HTTP, JSON]. O token vai só no parâmetro, nunca no log. */
function http_json(string $url, array $p = []): array {
    $ch = curl_init($url . ($p ? '?' . http_build_query($p) : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
    $corpo = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($corpo, true)];
}

function texto_curto($v, int $max = 300): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '')), 0, $max); }

/** Junta itens [nivel, texto] e devolve o pior nível: erro > aviso > ok. */
function nivel(array $itens): string {
    $n = array_column($itens, 0);
    return in_array('erro', $n, true) ? 'erro' : (in_array('aviso', $n, true) ? 'aviso' : 'ok');
}
function ordenar_afazeres(array $l): array {
    usort($l, fn($a, $b) => ($a[0] === 'erro' ? 0 : 1) <=> ($b[0] === 'erro' ? 0 : 1));
    return $l;
}

// =============== SITE ===============

/** O site responde? Tempo da home e validade do cadeado, guardados 30 min. */
function site_saude(bool $forcar = false): array {
    $c = json_ler('site_saude');
    if (!$forcar && ($c['em'] ?? 0) > time() - 1800) return $c;
    $ch = curl_init(SITE . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'painel-fernandateo/1.0']);
    curl_exec($ch);
    $novo = ['em' => time(), 'codigo' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000), 'ssl_ate' => null];
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true]]);
    if ($s = @stream_socket_client('ssl://' . parse_url(SITE, PHP_URL_HOST) . ':443', $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx)) {
        $cert = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
        if ($cert) $novo['ssl_ate'] = (int) (openssl_x509_parse($cert)['validTo_time_t'] ?? 0) ?: null;
        fclose($s);
    }
    return json_atualizar('site_saude', fn() => $novo);
}

function site_velocidade_dados(array $j): ?array {
    $out = ['em' => time()];
    foreach (['celular', 'computador'] as $m) {
        $x = $j[$m] ?? null;
        if (!is_array($x) || !is_numeric($x['nota'] ?? null)) return null;
        $out[$m] = ['nota' => max(0, min(100, (int) $x['nota'])), 'lcp' => round((float) ($x['lcp'] ?? 0), 1), 'cls' => round((float) ($x['cls'] ?? 0), 3)];
    }
    return $out;
}

function site_deploy_dados(array $j): array {
    return ['em' => time(), 'commit' => texto_curto($j['commit'] ?? '', 12), 'mensagem' => texto_curto($j['mensagem'] ?? '', 160)];
}

// Canais do GA4 (sessionDefaultChannelGroup) em palavras da dona
const SITE_ORIGENS = ['Direct' => 'Direto (digitou o endereço ou link salvo)', 'Organic Search' => 'Busca no Google', 'Organic Social' => 'Redes sociais',
    'Referral' => 'Outros sites', 'Email' => 'E-mail', 'Paid Search' => 'Anúncio no Google', 'Paid Social' => 'Anúncio nas redes',
    'Display' => 'Anúncio em sites', 'Organic Video' => 'Vídeos', 'Unassigned' => 'Sem origem conhecida'];

// Motivos do Search Console (em inglês) em palavras da dona; motivo novo aparece como veio
const SITE_COBERTURA = ['Discovered - currently not indexed' => 'O Google já sabe dela, mas ainda não leu',
    'Crawled - currently not indexed' => 'O Google leu, mas ainda não pôs na busca',
    'Duplicate, Google chose different canonical than user' => 'O Google acha que repete outra página e mostra a outra',
    'Duplicate without user-selected canonical' => 'Parecida com outra página, sem dizer qual é a principal',
    'URL is unknown to Google' => 'O Google ainda não conhece', 'Page with redirect' => 'Endereço que redireciona',
    "Excluded by 'noindex' tag" => 'Marcada para não aparecer (noindex)', 'Blocked by robots.txt' => 'Bloqueada no robots.txt',
    'Not found (404)' => 'Não encontrada (404)', 'Soft 404' => 'O Google acha que a página está vazia'];

/** Visitas (GA4) e páginas no Google (Search Console) do scripts/google_painel.py; cada parte com números ou só 'erro'. */
function site_google_dados(array $j): array {
    $ga = is_array($j['ga'] ?? null) ? $j['ga'] : [];
    $sc = is_array($j['sc'] ?? null) ? $j['sc'] : [];
    $lista = fn($v, int $n) => array_values(array_filter(array_slice(is_array($v) ? $v : [], 0, $n), 'is_array'));
    return ['em' => time(),
        'ga' => !isset($ga['visitas']) ? ['erro' => texto_curto($ga['erro'] ?? 'sem dados', 200)] : ['visitas' => (int) $ga['visitas'],
            'visitas_antes' => (int) ($ga['visitas_antes'] ?? 0), 'usuarios' => (int) ($ga['usuarios'] ?? 0),
            'paginas' => array_map(fn($p) => ['caminho' => texto_curto($p['caminho'] ?? '', 200), 'titulo' => texto_curto($p['titulo'] ?? '', 120), 'vistas' => (int) ($p['vistas'] ?? 0)], $lista($ga['paginas'] ?? [], 5)),
            'origens' => array_map(fn($o) => ['nome' => SITE_ORIGENS[$o['nome'] ?? ''] ?? texto_curto($o['nome'] ?? '', 60), 'sessoes' => (int) ($o['sessoes'] ?? 0)], $lista($ga['origens'] ?? [], 8))],
        'sc' => !isset($sc['total']) ? ['erro' => texto_curto($sc['erro'] ?? 'sem dados', 200)] : ['cliques' => (int) ($sc['cliques'] ?? 0),
            'impressoes' => (int) ($sc['impressoes'] ?? 0), 'total' => (int) $sc['total'], 'indexadas' => (int) ($sc['indexadas'] ?? 0),
            'fora' => array_map(fn($f) => ['url' => texto_curto($f['url'] ?? '', 300), 'estado' => SITE_COBERTURA[str_replace(['‘', '’'], "'", (string) ($f['estado'] ?? ''))] ?? texto_curto($f['estado'] ?? '', 120)], $lista($sc['fora'] ?? [], 300))]];
}

/** Parte que falhou hoje mantém os números da última leitura boa, com o erro junto. */
function site_google_juntar(array $antes, array $novo): array {
    foreach (['ga', 'sc'] as $k) if (isset($novo[$k]['erro']) && is_array($antes[$k] ?? null)) $novo[$k] = ['erro' => $novo[$k]['erro']] + $antes[$k];
    return $novo;
}

/** O que tem que ser feito no site. Cada item = [erro|aviso, texto]. */
function site_afazeres(array $saude, ?array $vel, ?array $google, ?array $deploy, int $agora): array {
    $l = [];
    if ($saude && !($saude['codigo'] >= 200 && $saude['codigo'] < 400)) $l[] = ['erro', 'O site não respondeu (código ' . (int) $saude['codigo'] . ').'];
    if ($saude['ssl_ate'] ?? null) {
        $d = intdiv((int) $saude['ssl_ate'] - $agora, 86400);
        if ($d < 20) $l[] = [$d < 7 ? 'erro' : 'aviso', 'O cadeado (certificado de segurança) vence em ' . plural(max(0, $d), 'dia', 'dias') . '.'];
    }
    if ($vel === null) $l[] = ['aviso', 'A velocidade ainda não foi medida (workflow painel.yml, todo dia às 6h).'];
    elseif ($vel['celular']['nota'] < 50) $l[] = ['aviso', 'O site está lento no celular (nota ' . $vel['celular']['nota'] . ' de 100).'];
    elseif ($agora - (int) $vel['em'] > 3 * 86400) $l[] = ['aviso', 'A velocidade não é medida ' . ha((int) $vel['em'], $agora) . ' (workflow painel.yml).'];
    if ($google === null) $l[] = ['aviso', 'Os números do Google ainda não chegaram (workflow painel.yml, todo dia às 6h30).'];
    else {
        if (isset($google['sc']['erro'])) $l[] = ['aviso', 'Não consegui ler o Search Console (' . $google['sc']['erro'] . ').'];
        elseif ($n = count($google['sc']['fora'] ?? [])) $l[] = ['aviso', plural($n, 'página do site ainda fora', 'páginas do site ainda fora') . ' do Google.'];
        if (isset($google['ga']['erro'])) $l[] = ['aviso', 'Não consegui ler as visitas do Google Analytics (' . $google['ga']['erro'] . ').'];
        if ($agora - (int) ($google['em'] ?? 0) > 3 * 86400) $l[] = ['aviso', 'Os números do Google não chegam ' . ha((int) $google['em'], $agora) . ' (workflow painel.yml).'];
    }
    if ($deploy && $agora - (int) $deploy['em'] > 30 * 86400) $l[] = ['aviso', 'O site não é atualizado ' . ha((int) $deploy['em'], $agora) . ' (nenhum artigo novo?).'];
    return ordenar_afazeres($l);
}

// =============== INSTAGRAM ===============

const IG_API = 'https://graph.instagram.com/v24.0/';

/** Instagram do período: perfil, alcance/visualizações/interações (e os do período anterior), seguidores novos e os 6 últimos posts.
 *  Guardado 1 h por período em _dados/instagram.json; trocar de período lê de novo. null = sem token nos Ajustes. */
function instagram(array $per, bool $forcar = false, ?callable $get = null): ?array {
    $token = (string) cfg('ig_token', '');
    if ($token === '' && $get === null) return null;
    $c = json_ler('instagram');
    $mesmo = ($c['de'] ?? '') === $per['de'] && ($c['ate'] ?? '') === $per['ate'];
    if (!$forcar && $mesmo && ($c['em'] ?? 0) > time() - 3600) return $c;
    if ($get === null) { ig_renovar_token($token); $token = (string) cfg('ig_token', ''); }
    $get ??= fn($caminho, $p) => http_json(IG_API . $caminho, $p + ['access_token' => $token]);
    $falha = function ($r, string $o) use ($per, $mesmo) {
        $msg = (string) ($r['error']['message'] ?? 'o Instagram não respondeu');
        return json_atualizar('instagram', fn($d) => ['erro' => "$o: " . mb_substr($msg, 0, 200), 'em' => time(), 'de' => $per['de'], 'ate' => $per['ate']] + ($mesmo ? $d : []));
    };
    [$hp, $perfil] = $get('me', ['fields' => 'id,username,followers_count,media_count']);
    if ($hp !== 200 || empty($perfil['id'])) return $falha($perfil, 'perfil');
    $id = (string) $perfil['id'];
    $total = function (string $de, string $ate) use ($get, $id) {
        $ini = strtotime("$de 00:00"); $fim = min(strtotime("$ate 00:00 +1 day"), time());
        [$h, $r] = $get("$id/insights", ['metric' => 'reach,views,total_interactions', 'period' => 'day', 'metric_type' => 'total_value', 'since' => $ini, 'until' => $fim]);
        return $h === 200 ? array_column(array_map(fn($m) => [$m['name'] ?? '', (int) ($m['total_value']['value'] ?? 0)], (array) ($r['data'] ?? [])), 1, 0) : null;
    };
    $ant = periodo_anterior($per);
    $agora = $total($per['de'], $per['ate']);
    $antes = $total($ant['de'], $ant['ate']);
    // seguidores novos por dia: o Instagram só dá os 30 últimos; guardo cada dia para períodos maiores
    [, $fol] = $get("$id/insights", ['metric' => 'follower_count', 'period' => 'day', 'since' => strtotime('today -29 days'), 'until' => time()]);
    $dias = [];
    foreach ((array) ($fol['data'][0]['values'] ?? []) as $x) if (isset($x['end_time'], $x['value']))
        $dias[date('Y-m-d', strtotime((string) $x['end_time']) - 86400)] = (int) $x['value'];  // end_time = fim do dia
    $hist = json_atualizar('ig_seguidores', fn($d) => array_slice(array_replace($d, $dias), -800, null, true));
    [, $media] = $get("$id/media", ['fields' => 'caption,media_type,timestamp,like_count,comments_count,permalink', 'limit' => 6]);
    $posts = [];
    foreach ((array) ($media['data'] ?? []) as $m) {
        if (!is_array($m)) continue;
        [$hr, $r] = $get(($m['id'] ?? '0') . '/insights', ['metric' => 'reach']);  // falha em story ou post antigo: fica sem alcance
        $posts[] = ['tipo' => (string) ($m['media_type'] ?? ''), 'legenda' => texto_curto(strtok((string) ($m['caption'] ?? ''), "\n"), 90), 'data' => (string) ($m['timestamp'] ?? ''),
            'curtidas' => (int) ($m['like_count'] ?? 0), 'comentarios' => (int) ($m['comments_count'] ?? 0),
            'link' => preg_match('~^https://www\.instagram\.com/~', (string) ($m['permalink'] ?? '')) ? (string) $m['permalink'] : '',
            'alcance' => $hr === 200 ? (int) ($r['data'][0]['values'][0]['value'] ?? 0) : null];
    }
    $novo = ig_resumo($perfil, $agora, $antes, $hist, $posts, $per) + ['em' => time(), 'erro' => $agora === null ? 'o Instagram não mandou o alcance deste período' : null, 'de' => $per['de'], 'ate' => $per['ate']];
    return json_atualizar('instagram', fn() => $novo);
}

/** Respostas do Instagram -> o que a tela mostra. Sem efeitos. */
function ig_resumo(array $perfil, ?array $agora, ?array $antes, array $hist, array $posts, array $per): array {
    $noPer = array_filter($hist, fn($d) => $d >= $per['de'] && $d <= $per['ate'], ARRAY_FILTER_USE_KEY);
    ksort($noPer);
    $pega = fn(?array $t, string $k) => $t === null ? null : (int) ($t[$k] ?? 0);
    return ['usuario' => (string) ($perfil['username'] ?? ''), 'seguidores' => (int) ($perfil['followers_count'] ?? 0), 'posts_total' => (int) ($perfil['media_count'] ?? 0),
        'alcance' => $pega($agora, 'reach'), 'visualizacoes' => $pega($agora, 'views'), 'interacoes' => $pega($agora, 'total_interactions'),
        'alcance_antes' => $pega($antes, 'reach'), 'visualizacoes_antes' => $pega($antes, 'views'), 'interacoes_antes' => $pega($antes, 'total_interactions'),
        'novos' => $noPer ? array_sum($noPer) : null, 'novos_desde' => $noPer && array_key_first($noPer) > $per['de'] ? array_key_first($noPer) : null,
        'posts' => $posts];
}

/** Token do login do Instagram vale 60 dias: renovo 1x por semana (a Meta recusa nas primeiras 24 h; aí só tenta de novo depois). */
function ig_renovar_token(string $token): void {
    if (time() - (int) cfg('ig_token_em', 0) < 7 * 86400) return;
    [$h, $r] = http_json('https://graph.instagram.com/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => $token]);
    if ($h === 200 && !empty($r['access_token'])) cfg_salvar(['ig_token' => (string) $r['access_token'], 'ig_token_em' => time(), 'ig_token_ate' => time() + (int) ($r['expires_in'] ?? 0)]);
    else cfg_salvar(['ig_token_em' => time() - 6 * 86400]);  // tenta de novo amanhã
}

function ig_afazeres(?array $ig, int $agora, ?int $tokenAte): array {
    if ($ig === null) return [['aviso', 'Falta o token do Instagram (Ajustes).']];
    $l = [];
    if (!empty($ig['erro'])) $l[] = [isset($ig['seguidores']) ? 'aviso' : 'erro', 'Não consegui ler o Instagram: ' . $ig['erro'] . '.'];
    if ($tokenAte && ($d = intdiv($tokenAte - $agora, 86400)) < 10) $l[] = [$d < 3 ? 'erro' : 'aviso', 'O token do Instagram vence em ' . plural(max(0, $d), 'dia', 'dias') . ': gere outro e cole nos Ajustes.'];
    if (($p = $ig['posts'][0]['data'] ?? '') !== '' && ($d = intdiv($agora - (int) strtotime($p), 86400)) >= 7) $l[] = ['aviso', 'O último post no Instagram foi há ' . plural($d, 'dia', 'dias') . '.'];
    if (($v = variacao($ig['alcance'] ?? null, $ig['alcance_antes'] ?? null)) !== null && $v <= -30) $l[] = ['aviso', 'O alcance do Instagram caiu ' . abs($v) . '% em relação ao período anterior.'];
    return ordenar_afazeres($l);
}

// =============== ANÚNCIOS (META) ===============

const META_API = 'https://graph.facebook.com/v24.0/';
const META_CONTA_PADRAO = '10151653454237128';  // conta de anúncios da Dra. Fernanda (docs/anuncios no HD)
const META_LEAD = ['lead', 'onsite_conversion.lead_grouped', 'leadgen.other'];  // a Meta chama o lead de formas diferentes conforme a campanha
const META_CONVERSA = ['onsite_conversion.messaging_conversation_started_7d'];

/** Primeiro tipo de $tipos que aparece em actions; 0 se nenhum. */
function meta_acao($acoes, array $tipos): float {
    $v = array_column(is_array($acoes) ? array_filter($acoes, 'is_array') : [], 'value', 'action_type');
    foreach ($tipos as $t) if (isset($v[$t])) return (float) $v[$t];
    return 0.0;
}

function meta_conta(): string { return preg_replace('/\D/', '', (string) (cfg('meta_conta', '') ?: META_CONTA_PADRAO)); }

/** Conta, campanhas e totais do período (e do anterior), guardados 30 min em _dados/anuncios.json. null = sem token nos Ajustes. */
function meta_anuncios(array $per, bool $forcar = false, ?callable $get = null): ?array {
    $token = (string) cfg('meta_token', '');
    if ($token === '' && $get === null) return null;
    $conta = meta_conta();
    $c = json_ler('anuncios');
    $mesmo = ($c['conta'] ?? '') === $conta && ($c['de'] ?? '') === $per['de'] && ($c['ate'] ?? '') === $per['ate'];
    if (!$forcar && $mesmo && ($c['em'] ?? 0) > time() - 1800) return $c;
    $get ??= fn($caminho, $p) => http_json(META_API . $caminho, $p + ['access_token' => $token]);
    $campos = '{spend,reach,impressions,clicks,actions}';
    $ins = 'insights.time_range(' . json_encode(['since' => $per['de'], 'until' => $per['ate']]) . ')' . $campos;
    $ant = periodo_anterior($per);
    [$h1, $info] = $get("act_$conta", ['fields' => 'name,account_status,currency,spend_cap,amount_spent,' . $ins]);
    [, $antes] = $get("act_$conta/insights", ['fields' => trim($campos, '{}'), 'time_range' => json_encode(['since' => $ant['de'], 'until' => $ant['ate']])]);
    $info['antes'] = $antes;
    [$h2, $camp] = $get("act_$conta/campaigns", ['fields' => 'name,effective_status,objective,daily_budget,lifetime_budget,stop_time,' . $ins, 'limit' => 50]);
    [, $ads] = $get("act_$conta/ads", ['fields' => 'name,effective_status,campaign{name}', 'limit' => 20,
        'filtering' => json_encode([['field' => 'effective_status', 'operator' => 'IN', 'value' => ['DISAPPROVED', 'WITH_ISSUES']]])]);
    if ($h1 !== 200 || $h2 !== 200) {
        $msg = (string) (($h1 !== 200 ? $info : $camp)['error']['message'] ?? '');
        $erro = str_contains($msg, 'permission') || str_contains($msg, 'ads_read') || in_array(190, [(int) ($info['error']['code'] ?? 0), (int) ($camp['error']['code'] ?? 0)], true)
            ? 'a Meta não deixou este token ler os anúncios (precisa ser um token do Facebook com a permissão ads_read nesta conta)' : 'a Meta não respondeu (HTTP ' . $h1 . '/' . $h2 . ')';
        if ($msg !== '') $erro .= '. Resposta da Meta: "' . mb_substr($msg, 0, 200) . '"';
        return json_atualizar('anuncios', fn($d) => ['erro' => $erro, 'em' => time(), 'conta' => $conta, 'de' => $per['de'], 'ate' => $per['ate']] + ($mesmo ? $d : []));
    }
    $novo = meta_resumo((array) $info, (array) $camp, (array) $ads, time()) + ['em' => time(), 'erro' => null, 'conta' => $conta, 'de' => $per['de'], 'ate' => $per['ate']];
    return json_atualizar('anuncios', fn() => $novo);
}

/** Respostas da Meta -> totais (do período e do anterior), campanhas ligadas ou que gastaram (ligadas primeiro) e avisos. Sem efeitos. */
function meta_resumo(array $info, array $camp, array $ads, int $agora): array {
    $num = function ($i) {
        $x = is_array($i['data'][0] ?? null) ? $i['data'][0] : [];
        return ['gasto' => (float) ($x['spend'] ?? 0), 'alcance' => (int) ($x['reach'] ?? 0), 'impressoes' => (int) ($x['impressions'] ?? 0),
            'cliques' => (int) ($x['clicks'] ?? 0), 'leads' => (int) meta_acao($x['actions'] ?? [], META_LEAD), 'conversas' => (int) meta_acao($x['actions'] ?? [], META_CONVERSA)];
    };
    // a chave "ligada" não basta: limite de gastos da conta atingido ou data de fim passada param tudo sem mudar o effective_status
    $cap = (float) ($info['spend_cap'] ?? 0) / 100; $gastoConta = (float) ($info['amount_spent'] ?? 0) / 100;  // a Meta manda em centavos
    $noLimite = $cap > 0 && $gastoConta >= $cap;
    $campanhas = [];
    foreach ($camp['data'] ?? [] as $c) {
        if (!is_array($c)) continue;
        $n = $num($c['insights'] ?? []);
        $st = (string) ($c['effective_status'] ?? '');
        if ($st === 'ACTIVE' && ($fim = strtotime((string) ($c['stop_time'] ?? ''))) && $fim <= $agora) $st = 'ENCERRADA';
        elseif ($st === 'ACTIVE' && $noLimite) $st = 'LIMITE';
        if (!in_array($st, ['ACTIVE', 'LIMITE'], true) && $n['gasto'] <= 0) continue;
        $orc = isset($c['daily_budget']) ? ['dia', (float) $c['daily_budget'] / 100] : (isset($c['lifetime_budget']) ? ['total', (float) $c['lifetime_budget'] / 100] : null);
        $campanhas[] = ['nome' => (string) ($c['name'] ?? ''), 'situacao' => $st, 'orcamento' => $orc] + $n;
    }
    usort($campanhas, fn($a, $b) => [$b['situacao'] === 'ACTIVE', $b['gasto']] <=> [$a['situacao'] === 'ACTIVE', $a['gasto']]);
    $avisos = [];
    if ($noLimite) $avisos[] = 'A conta atingiu o limite de gastos de ' . brl($cap) . ': nenhuma campanha está rodando. Aumente ou tire o limite no Gerenciador de Anúncios (Cobrança → Limite de gastos da conta)';
    $st = (int) ($info['account_status'] ?? 1);
    if ($st !== 1) $avisos[] = [2 => 'A conta de anúncios está desativada pela Meta', 3 => 'A conta de anúncios tem pagamento pendente: os anúncios param até pagar',
        7 => 'A conta de anúncios está em análise de risco', 9 => 'A conta de anúncios está no período de carência de pagamento', 101 => 'A conta de anúncios está fechada'][$st] ?? "A conta de anúncios não está ativa (situação $st)";
    foreach ($ads['data'] ?? [] as $a) if (is_array($a))
        $avisos[] = 'Anúncio ' . (($a['effective_status'] ?? '') === 'DISAPPROVED' ? 'reprovado' : 'com problema') . ': ' . ($a['name'] ?? '?') . (isset($a['campaign']['name']) ? ' (' . $a['campaign']['name'] . ')' : '');
    return ['nome' => (string) ($info['name'] ?? ''), 'total' => $num($info['insights'] ?? []), 'antes' => $num($info['antes'] ?? []),
        'ativas' => count(array_filter($campanhas, fn($c) => $c['situacao'] === 'ACTIVE')), 'campanhas' => $campanhas, 'avisos' => $avisos];
}

/** Custo por resultado: lead, ou conversa no WhatsApp quando a campanha é de mensagem. null = sem resultado. */
function meta_custo(array $t): ?float {
    $r = $t['leads'] ?: $t['conversas'];
    return $r ? $t['gasto'] / $r : null;
}

function meta_afazeres(?array $an): array {
    if ($an === null) return [['aviso', 'Falta o token dos anúncios da Meta (Ajustes).']];
    $l = [];
    if (!empty($an['erro'])) $l[] = [isset($an['total']) ? 'aviso' : 'erro', 'Não consegui ler os anúncios: ' . $an['erro'] . '.'];
    foreach ($an['avisos'] ?? [] as $a) $l[] = ['erro', $a . '.'];
    if (isset($an['ativas']) && !$an['ativas'] && !($an['avisos'] ?? [])) $l[] = ['aviso', 'Nenhuma campanha no ar agora.'];
    if (isset($an['total']) && ($v = variacao(meta_custo($an['total']), meta_custo($an['antes']))) !== null && $v >= 30)
        $l[] = ['aviso', 'O custo por resultado dos anúncios subiu ' . $v . '% em relação ao período anterior.'];
    return ordenar_afazeres($l);
}
