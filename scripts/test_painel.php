<?php
declare(strict_types=1);
// Teste do painel (/painel/): php scripts/test_painel.php. Roda no deploy; dados numa pasta temporária, nada vai à internet.
define('DADOS', sys_get_temp_dir() . '/painel-teste-' . getmypid());
@mkdir(DADOS, 0700, true);
require __DIR__ . '/../painel/lib.php';
require __DIR__ . '/../painel/fontes.php';
$falhas = 0;
function ok(bool $c, string $o): void { global $falhas; if (!$c) { $falhas++; echo "FALHOU: $o\n"; } }

// período
$p = periodo_escolhido([], '2026-11-07');
ok($p['de'] === '2026-10-11' && $p['ate'] === '2026-11-07' && $p['chave'] === '28', 'padrão = 4 semanas com hoje');
ok(periodo_anterior($p) === ['de' => '2026-09-13', 'ate' => '2026-10-10'], 'anterior de 28 dias');
$p = periodo_escolhido(['periodo' => 'p', 'de' => '2026-11-30', 'ate' => '2026-11-01'], '2026-11-07');
ok($p['de'] === '2026-11-01' && $p['ate'] === '2026-11-07', 'personalizado: futuro vira hoje e datas invertidas trocam');
ok(periodo_anterior($p) === ['de' => '2026-10-25', 'ate' => '2026-10-31'], 'anterior de 7 dias');
ok(periodo_escolhido(['periodo' => '999'], '2026-11-07')['chave'] === '28', 'período inválido = 4 semanas');
ok(periodo_query(periodo_escolhido(['periodo' => '7'], '2026-11-07')) === '&periodo=7', 'período vai na URL das abas');

ok(variacao(150.0, 100.0) === 50 && variacao(5.0, 0.0) === null && variacao(null, 3.0) === null, 'variação');
ok(nivel([['aviso', 'a'], ['erro', 'b']]) === 'erro' && nivel([]) === 'ok', 'pior nível');

// site
$agora = strtotime('2026-11-07 12:00');
$saude = ['codigo' => 200, 'ms' => 300, 'ssl_ate' => $agora + 5 * 86400];
$vel = ['em' => $agora, 'celular' => ['nota' => 40], 'computador' => ['nota' => 90]];
$g = site_google_dados(['ga' => ['visitas' => 10, 'origens' => [['nome' => 'Organic Search', 'sessoes' => 3]]], 'sc' => ['total' => 5, 'indexadas' => 4, 'fora' => [['url' => 'https://x/a', 'estado' => 'Crawled - currently not indexed']]]]);
ok($g['ga']['origens'][0]['nome'] === 'Busca no Google' && $g['sc']['fora'][0]['estado'] === 'O Google leu, mas ainda não pôs na busca', 'Google em palavras da dona');
$g['em'] = $agora;
$af = site_afazeres($saude, $vel, $g, null, $agora);
ok($af[0][0] === 'erro' && str_contains($af[0][1], 'cadeado'), 'cadeado vencendo em 5 dias = erro, primeiro da lista');
ok((bool) array_filter($af, fn($i) => str_contains($i[1], 'lento')), 'nota < 50 no celular = aviso');
ok((bool) array_filter($af, fn($i) => str_contains($i[1], 'fora do Google')), 'página fora do Google = aviso');
ok(site_afazeres(['codigo' => 500, 'ssl_ate' => null], null, null, null, $agora)[0][1] === 'O site não respondeu (código 500).', 'site fora do ar');
$j = site_google_juntar(['ga' => ['visitas' => 9]], site_google_dados(['ga' => ['erro' => 'x'], 'sc' => ['total' => 1]]));
ok($j['ga'] === ['erro' => 'x', 'visitas' => 9], 'parte com erro guarda a última leitura boa');
ok(site_velocidade_dados(['celular' => ['nota' => 120], 'computador' => ['nota' => 5]])['celular']['nota'] === 100 && site_velocidade_dados(['celular' => []]) === null, 'velocidade validada');

// Instagram
$per = periodo_escolhido([], '2026-11-07');
$r = ig_resumo(['username' => 'f', 'followers_count' => 500], ['reach' => 70, 'views' => 300, 'total_interactions' => 20], ['reach' => 100],
    ['2026-10-01' => 9, '2026-10-20' => 2, '2026-11-01' => 3], [], $per);
ok($r['novos'] === 5 && $r['novos_desde'] === '2026-10-20' && $r['alcance_antes'] === 100, 'seguidores novos só do período, com "desde"');
$ig = ['posts' => [['data' => date('c', $agora - 9 * 86400)]]] + $r;
$af = ig_afazeres($ig, $agora, $agora + 2 * 86400);
ok($af[0][0] === 'erro' && str_contains($af[0][1], 'token'), 'token vencendo em 2 dias = erro');
ok(count($af) === 3, 'último post há 9 dias e alcance caiu 30% = avisos');
ok(ig_afazeres(null, $agora, null)[0][1] === 'Falta o token do Instagram (Ajustes).', 'sem token');
// leitura com respostas de mentira (sem internet)
$falso = function (string $c, array $p) {
    if ($c === 'me') return [200, ['id' => '42', 'username' => 'fernandateoveterinaria', 'followers_count' => 497, 'media_count' => 128]];
    if ($c === '42/insights' && $p['metric'] === 'follower_count') return [200, ['data' => [['values' => [['value' => 2, 'end_time' => '2026-11-06T07:00:00+0000']]]]]];
    if ($c === '42/insights') return [200, ['data' => [['name' => 'reach', 'total_value' => ['value' => 80]]]]];
    if ($c === '42/media') return [200, ['data' => [['id' => 'm1', 'caption' => "Linha 1\nresto", 'media_type' => 'VIDEO', 'permalink' => 'https://www.instagram.com/reel/x/', 'timestamp' => '2026-11-05T10:00:00+0000']]]];
    if ($c === 'm1/insights') return [400, ['error' => ['message' => 'sem alcance']]];
    return [404, []];
};
$ig = instagram(periodo_escolhido([], date('Y-m-d')), true, $falso);
ok($ig['seguidores'] === 497 && $ig['alcance'] === 80 && $ig['posts'][0]['legenda'] === 'Linha 1' && $ig['posts'][0]['alcance'] === null && $ig['erro'] === null, 'Instagram lido e resumido');
$ig = instagram(periodo_escolhido([], date('Y-m-d')), true, fn() => [400, ['error' => ['message' => 'token vencido']]]);
ok($ig['seguidores'] === 497 && str_contains($ig['erro'], 'token vencido'), 'falha guarda a última leitura do mesmo período');

// anúncios
$info = ['account_status' => 1, 'spend_cap' => '10000', 'amount_spent' => '10000', 'insights' => ['data' => [['spend' => '50', 'actions' => [['action_type' => 'lead', 'value' => '5']]]]],
    'antes' => ['data' => [['spend' => '40', 'actions' => [['action_type' => 'lead', 'value' => '8']]]]]];
$camp = ['data' => [['name' => 'A', 'effective_status' => 'ACTIVE', 'daily_budget' => '2000'], ['name' => 'B', 'effective_status' => 'ACTIVE', 'stop_time' => '2026-01-01T00:00:00-0300'],
    ['name' => 'C', 'effective_status' => 'PAUSED']]];
$m = meta_resumo($info, $camp, ['data' => [['name' => 'X', 'effective_status' => 'DISAPPROVED']]], $agora);
ok($m['campanhas'][0]['situacao'] === 'LIMITE' && count($m['campanhas']) === 1, 'limite da conta trava a ligada; encerrada e pausada sem gasto somem');
ok($m['ativas'] === 0 && count($m['avisos']) === 2 && $m['campanhas'][0]['orcamento'] === ['dia', 20.0], 'avisos de limite e reprovado; orçamento em reais');
ok(meta_custo($m['total']) === 10.0 && meta_custo($m['antes']) === 5.0, 'custo por lead');
ok(meta_custo(['gasto' => 30.0, 'leads' => 0, 'conversas' => 3]) === 10.0, 'sem lead, custo por conversa');
$af = meta_afazeres($m + ['erro' => null]);
ok(nivel($af) === 'erro' && (bool) array_filter($af, fn($i) => str_contains($i[1], 'subiu 100%')), 'custo por resultado dobrou = aviso, avisos da conta = erro');
ok(meta_afazeres(null)[0][0] === 'aviso', 'sem token dos anúncios');
ok(meta_afazeres(['ativas' => 1], $agora - 55 * 86400, $agora)[0][1] === 'O token dos anúncios vence em 5 dias (60 dias desde que foi salvo): gere outro no Graph API Explorer e cole nos Ajustes.', 'aviso de token dos anúncios vencendo');
ok(meta_afazeres(['ativas' => 1], $agora - 10 * 86400, $agora) === [], 'token novo não avisa');
ok(meta_anuncios(periodo_escolhido([], '2026-11-07'), true, fn() => [400, ['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']]])['erro'] !== null, 'token errado vira aviso claro');

array_map('unlink', glob(DADOS . '/*'));
rmdir(DADOS);
echo $falhas ? "$falhas falha(s)\n" : "painel: tudo certo\n";
exit($falhas ? 1 : 0);
