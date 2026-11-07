<?php
declare(strict_types=1);
// Painel da Dra. Fernanda Teo: saúde e números do site, do Instagram e dos anúncios da Meta. Spec: scripts/ESPECIFICACAO_PAINEL.md.
require __DIR__ . '/lib.php';
require __DIR__ . '/fontes.php';
if (!is_dir(DADOS)) mkdir(DADOS, 0700, true);
sessao();
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$acao = (string) ($_POST['acao'] ?? '');
$aba = isset(ABAS[$_GET['aba'] ?? '']) ? (string) $_GET['aba'] : 'resumo';
function voltar(string $q): void { header('Location: /painel/?' . ltrim($q, '?&'), true, 303); exit; }

// ---------- instalação (1ª vez): código de instalação + senha ----------
if ((string) cfg('senha_hash', '') === '') {
    $erro = '';
    if ($post && $acao === 'instalar') {
        $s = (string) ($_POST['senha'] ?? '');
        if (tentativas_bloqueado()) $erro = 'Muitas tentativas. Espere 15 minutos.';
        elseif (!hash_equals(CODIGO_INSTALACAO_SHA256, hash('sha256', trim((string) ($_POST['codigo'] ?? ''))))) { tentativa_falha(); $erro = 'Código de instalação errado.'; }
        elseif (mb_strlen($s) < 10) $erro = 'A senha precisa de pelo menos 10 caracteres.';
        elseif ($s !== (string) ($_POST['senha2'] ?? '')) $erro = 'As duas senhas não são iguais.';
        else {
            cfg_salvar(['senha_hash' => password_hash($s, PASSWORD_DEFAULT), 'segredo' => bin2hex(random_bytes(32)), 'cron_token' => bin2hex(random_bytes(24))]);
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            lembrete_criar();
            voltar('aba=ajustes&ok=instalado');
        }
    }
    pagina_topo('Instalar o painel');
    echo '<form method="post" class="card entrar">' . ($erro ? '<p class="erro">' . h($erro) . '</p>' : '')
        . '<p class="dica">Primeira vez: digite o código de instalação e crie a senha do painel.</p><input type="hidden" name="acao" value="instalar">'
        . '<label class="c" for="codigo">Código de instalação</label><input id="codigo" name="codigo" type="password" required autocomplete="off">'
        . '<label class="c" for="senha">Senha nova (10+ caracteres)</label><input id="senha" name="senha" type="password" required minlength="10" autocomplete="new-password">'
        . '<label class="c" for="senha2">Repita a senha</label><input id="senha2" name="senha2" type="password" required minlength="10" autocomplete="new-password">'
        . '<button>Instalar</button></form>';
    pagina_fim();
    exit;
}

// ---------- entrar ----------
if (!logado()) {
    $erro = '';
    if ($post && $acao === 'entrar') {
        if (tentativas_bloqueado()) $erro = 'Muitas tentativas. Espere 15 minutos.';
        elseif (password_verify((string) ($_POST['senha'] ?? ''), (string) cfg('senha_hash'))) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            if (!empty($_POST['lembrar'])) lembrete_criar();
            voltar((string) ($_SERVER['QUERY_STRING'] ?? ''));
        } else { tentativa_falha(); $erro = 'Senha errada.'; }
    }
    pagina_topo('Painel');
    echo '<form method="post" class="card entrar">' . ($erro ? '<p class="erro">' . h($erro) . '</p>' : '')
        . '<input type="hidden" name="acao" value="entrar"><label class="c" for="senha">Senha do painel</label>'
        . '<input id="senha" name="senha" type="password" required autofocus autocomplete="current-password">'
        . '<label class="c" style="display:flex;gap:8px;align-items:center;color:var(--ink)"><input type="checkbox" name="lembrar" value="1" checked style="width:20px;min-height:0;height:20px"> Lembrar neste aparelho (180 dias)</label>'
        . '<button>Entrar</button></form>';
    pagina_fim();
    exit;
}

// ---------- ações (POST) ----------
$per = periodo_escolhido($_GET, date('Y-m-d'));
$q = 'aba=' . $aba . periodo_query($per);
if ($post) {
    if (!csrf_ok((string) ($_POST['csrf'] ?? ''))) voltar($q . '&ok=expirou');
    if ($acao === 'sair') {
        $_SESSION = [];
        session_destroy();
        lembrete_apagar();
        voltar('');
    }
    if ($acao === 'atualizar') {
        if (in_array($aba, ['resumo', 'site'], true)) site_saude(true);
        if (in_array($aba, ['resumo', 'instagram'], true)) instagram($per, true);
        if (in_array($aba, ['resumo', 'anuncios'], true)) meta_anuncios($per, true);
        voltar($q);
    }
    if ($acao === 'ajustes') {
        $novo = ['meta_conta' => preg_replace('/\D/', '', (string) ($_POST['meta_conta'] ?? ''))];
        if (($t = trim((string) ($_POST['ig_token'] ?? ''))) !== '') $novo += ['ig_token' => $t, 'ig_token_em' => 0, 'ig_token_ate' => null];
        if (($t = trim((string) ($_POST['meta_token'] ?? ''))) !== '') $novo['meta_token'] = $t;
        cfg_salvar($novo);
        @unlink(DADOS . '/instagram.json'); @unlink(DADOS . '/anuncios.json');  // lê de novo com o token novo
        voltar('aba=ajustes&ok=salvo');
    }
    if ($acao === 'chave') { cfg_salvar(['cron_token' => bin2hex(random_bytes(24))]); voltar('aba=ajustes&ok=chave'); }
    if ($acao === 'senha') {
        $s = (string) ($_POST['senha'] ?? '');
        if (!password_verify((string) ($_POST['atual'] ?? ''), (string) cfg('senha_hash'))) voltar('aba=ajustes&ok=senha_atual');
        if (mb_strlen($s) < 10 || $s !== (string) ($_POST['senha2'] ?? '')) voltar('aba=ajustes&ok=senha_nova');
        cfg_salvar(['senha_hash' => password_hash($s, PASSWORD_DEFAULT), 'segredo' => bin2hex(random_bytes(32))]);  // segredo novo: os outros aparelhos saem
        lembrete_criar();
        voltar('aba=ajustes&ok=senha');
    }
    voltar($q);
}

// ---------- telas ----------
$agora = time();
$rot = $per['chave'] === 'p' ? $per['rotulo'] : mb_strtolower($per['rotulo']);
/** ▲▼ com a % de variação; $menorMelhor = subir é ruim (custo). */
function seta(?int $v, bool $menorMelhor = false): string {
    if ($v === null) return '';
    if ($v === 0) return '<span>= igual</span>';
    $bom = ($v > 0) !== $menorMelhor;
    return '<span class="' . ($bom ? 'up' : 'dn') . '">' . ($v > 0 ? '▲ ' : '▼ ') . abs($v) . '%</span>';
}
function kpi(string $rotulo, string $valor, string $sub = '', string $titulo = ''): string {
    return '<div class="kpi"' . ($titulo ? ' title="' . h($titulo) . '"' : '') . '><div class="l">' . h($rotulo) . '</div><div class="v">' . $valor . '</div><div class="s">' . $sub . '</div></div>';
}
function lista_afazeres(array $itens, bool $comArea = false): string {
    if (!$itens) return '<p class="dica">Nada pendente. ✓</p>';
    $h = '';
    foreach ($itens as $i) $h .= '<div class="row"><span class="tag t-' . $i[0] . '">' . ($i[0] === 'erro' ? 'problema' : 'atenção') . '</span><span class="x">'
        . ($comArea ? '<strong>' . h($i[2]) . ':</strong> ' : '') . h($i[1]) . '</span></div>';
    return $h;
}
function botao_atualizar(string $texto): string {
    return '<form method="post" data-espera="Atualizando…" class="dica">' . $texto . ' ' . csrf_campo() . '<input type="hidden" name="acao" value="atualizar"><button class="sec">↻ Atualizar agora</button></form>';
}
$nomeNivel = ['ok' => 'tudo certo', 'aviso' => 'atenção', 'erro' => 'problema'];
$tituloAba = ['resumo' => 'Painel', 'site' => 'Site', 'instagram' => 'Instagram', 'anuncios' => 'Anúncios', 'ajustes' => 'Ajustes'][$aba];
pagina_topo($tituloAba, $aba, periodo_query($per));
$ok = (string) ($_GET['ok'] ?? '');
$okTxt = ['instalado' => 'Painel instalado. Agora cole os tokens abaixo.', 'salvo' => 'Ajustes salvos.', 'chave' => 'Chave nova gerada: troque também o secret PAINEL_TOKEN no GitHub.', 'senha' => 'Senha trocada. Os outros aparelhos vão pedir a senha de novo.'];
$erroTxt = ['expirou' => 'A sessão expirou e nada foi feito. Tente de novo.', 'senha_atual' => 'A senha atual não confere.', 'senha_nova' => 'A senha nova precisa de 10+ caracteres e as duas precisam ser iguais.'];
if (isset($okTxt[$ok])) echo '<p class="ok-msg">' . h($okTxt[$ok]) . '</p>';
if (isset($erroTxt[$ok])) echo '<p class="erro">' . h($erroTxt[$ok]) . '</p>';

if (in_array($aba, ['resumo', 'site'], true)) {
    $saude = site_saude();
    $vel = json_ler('velocidade') ?: null;
    $google = json_ler('google') ?: null;
    $deploy = json_ler('deploy') ?: null;
    $siteAf = site_afazeres($saude, $vel, $google, $deploy, $agora);
    $noAr = $saude['codigo'] >= 200 && $saude['codigo'] < 400;
    $ga = $google['ga'] ?? []; $sc = $google['sc'] ?? [];
}
if (in_array($aba, ['resumo', 'instagram'], true)) {
    $ig = instagram($per);
    $igAf = ig_afazeres($ig, $agora, cfg('ig_token_ate') ? (int) cfg('ig_token_ate') : null);
}
if (in_array($aba, ['resumo', 'anuncios'], true)) {
    $an = meta_anuncios($per);
    $anAf = meta_afazeres($an);
}

if ($aba === 'resumo'):
    $t = $an['total'] ?? null; $custo = $t ? meta_custo($t) : null;
    $todos = [];
    foreach (['Site' => $siteAf, 'Instagram' => $igAf, 'Anúncios' => $anAf] as $area => $l) foreach ($l as $i) $todos[] = [$i[0], $i[1], $area];
    $todos = ordenar_afazeres($todos);
    echo periodo_form($per, 'resumo'); ?>
<div class="saude">
  <a href="?aba=site" class="<?= nivel($siteAf) ?>"><div class="t">Site <span class="tag t-<?= nivel($siteAf) ?>"><?= $nomeNivel[nivel($siteAf)] ?></span></div>
    <div class="v"><?= $noAr ? 'No ar' : 'Fora do ar' ?></div>
    <div class="sub"><?= $vel ? 'velocidade ' . $vel['celular']['nota'] . ' no celular' : 'velocidade ainda não medida' ?><?= isset($ga['visitas']) ? ' · ' . mil($ga['visitas']) . ' visitas em 28 dias ' . seta(variacao($ga['visitas'], $ga['visitas_antes'])) : '' ?></div></a>
  <a href="?aba=instagram<?= periodo_query($per) ?>" class="<?= nivel($igAf) ?>"><div class="t">Instagram <span class="tag t-<?= nivel($igAf) ?>"><?= $nomeNivel[nivel($igAf)] ?></span></div>
    <div class="v"><?= isset($ig['seguidores']) ? mil($ig['seguidores']) . ' <small class="sub">seguidores</small>' : '—' ?></div>
    <div class="sub"><?= isset($ig['seguidores']) ? ($ig['novos'] !== null ? '+' . mil($ig['novos']) . ' novos · ' : '') . 'alcance ' . ($ig['alcance'] !== null ? mil($ig['alcance']) . ' ' . seta(variacao($ig['alcance'], $ig['alcance_antes'])) : '—') : 'sem leitura' ?></div></a>
  <a href="?aba=anuncios<?= periodo_query($per) ?>" class="<?= nivel($anAf) ?>"><div class="t">Anúncios <span class="tag t-<?= nivel($anAf) ?>"><?= $nomeNivel[nivel($anAf)] ?></span></div>
    <div class="v"><?= $t ? h(brl($t['gasto'])) . ' <small class="sub">gastos</small>' : '—' ?></div>
    <div class="sub"><?= $t ? plural($t['leads'] ?: $t['conversas'], $t['leads'] ? 'lead' : 'conversa', $t['leads'] ? 'leads' : 'conversas') . ($custo !== null ? ' · ' . h(brl($custo)) . ' cada' : '') . ' · ' . plural((int) $an['ativas'], 'campanha no ar', 'campanhas no ar') : 'sem leitura' ?></div></a>
</div>
<h2>O que fazer</h2><div class="card" style="padding:4px 16px"><?= lista_afazeres($todos, true) ?></div>
<p class="dica">Período: <?= h($rot) ?> com hoje, comparado com o mesmo tanto de dias logo antes. As visitas do site são sempre dos últimos 28 dias (o Google manda 1x por dia).</p>
<?= botao_atualizar('O Instagram atualiza a cada 1 h e os anúncios a cada 30 min.') ?>

<?php elseif ($aba === 'site'): ?>
<div class="kpis">
  <?= kpi('No ar', $noAr ? 'Sim' : 'Não', $noAr ? number_format($saude['ms'] / 1000, 1, ',', '') . ' s para abrir' . ($saude['ssl_ate'] ? ' · cadeado até ' . date('d/m/y', (int) $saude['ssl_ate']) : '') : 'código ' . (int) $saude['codigo']) ?>
  <?= kpi('Velocidade', $vel ? $vel['celular']['nota'] . ' / ' . $vel['computador']['nota'] : '—', $vel ? 'celular / computador · ' . ha((int) $vel['em'], $agora) : 'medida todo dia às 6h', 'Nota do Lighthouse (o motor do PageSpeed do Google), de 0 a 100. 90+ é ótimo, abaixo de 50 é lento.') ?>
  <?= kpi('Visitas (28 dias)', isset($ga['visitas']) ? mil($ga['visitas']) : '—', isset($ga['visitas']) ? seta(variacao($ga['visitas'], $ga['visitas_antes'])) . ' · ' . plural($ga['usuarios'], 'pessoa', 'pessoas') : 'Google Analytics') ?>
  <?= kpi('No Google', isset($sc['total']) ? mil($sc['indexadas']) . ' de ' . mil($sc['total']) : '—', isset($sc['total']) ? mil($sc['cliques']) . ' cliques · ' . mil($sc['impressoes']) . ' vezes na busca' : 'páginas indexadas', 'Páginas do sitemap que o Google mostra na busca; cliques e vezes que o site apareceu na busca em 28 dias (o Search Console atrasa 3 dias).') ?>
  <?= kpi('Último deploy', $deploy ? ha((int) $deploy['em'], $agora) : '—', $deploy ? h($deploy['mensagem']) : 'chega no próximo deploy') ?>
</div>
<div class="cols">
  <div>
    <h2>O que tem que ser feito</h2><div class="card" style="padding:4px 16px"><?= lista_afazeres($siteAf) ?></div>
    <?php if (!empty($sc['fora'])): ?>
    <h2>Fora do Google</h2><div class="card" style="padding:0 16px"><details><summary><?= plural(count($sc['fora']), 'página do sitemap ainda não aparece', 'páginas do sitemap ainda não aparecem') ?> na busca</summary>
    <?php foreach ($sc['fora'] as $f) echo '<div class="row"><div class="x"><a href="' . h($f['url']) . '" target="_blank" rel="noopener">' . h(preg_replace('~^https?://[^/]+~', '', $f['url']) ?: '/') . '</a><div class="sub">' . h($f['estado']) . '</div></div></div>'; ?>
    </details></div><p class="dica">Página nova leva de dias a semanas para entrar.</p>
    <?php endif; ?>
  </div>
  <div>
    <?php if (!empty($ga['paginas'])): $max = max(array_column($ga['paginas'], 'vistas')) ?: 1; ?>
    <h2>Páginas mais vistas (28 dias)</h2><div class="card" style="padding:4px 16px">
    <?php foreach ($ga['paginas'] as $p) echo '<div class="row"><div class="x">' . h($p['titulo'] ?: $p['caminho']) . '<div class="barra"><i style="width:' . round($p['vistas'] / $max * 100) . '%"></i></div></div><span class="n">' . mil($p['vistas']) . '</span></div>'; ?>
    </div><?php endif; ?>
    <?php if (!empty($ga['origens'])): $tot = array_sum(array_column($ga['origens'], 'sessoes')) ?: 1; ?>
    <h2>De onde vêm as visitas</h2><div class="card" style="padding:4px 16px">
    <?php foreach ($ga['origens'] as $o) echo '<div class="row"><div class="x">' . h($o['nome']) . '<div class="barra"><i style="width:' . round($o['sessoes'] / $tot * 100) . '%"></i></div></div><span class="n">' . round($o['sessoes'] / $tot * 100) . '% · ' . mil($o['sessoes']) . '</span></div>'; ?>
    </div><?php endif; ?>
  </div>
</div>
<?= botao_atualizar('O site é conferido a cada 30 min; velocidade e Google chegam 1x por dia.') ?>

<?php elseif ($aba === 'instagram'):
    echo periodo_form($per, 'instagram');
    if ($ig === null): ?>
  <div class="card"><p class="dica">Cole o token do Instagram em <a href="?aba=ajustes">Ajustes</a> para ver seguidores, alcance e posts.</p></div>
<?php else:
    if (!empty($ig['erro'])) echo '<p class="erro">' . h($ig['erro']) . (isset($ig['seguidores']) ? '. Mostrando a última leitura.' : '.') . '</p>';
    $eng = !empty($ig['alcance']) ? round($ig['interacoes'] / $ig['alcance'] * 100, 1) : null;
    $engAntes = !empty($ig['alcance_antes']) ? round($ig['interacoes_antes'] / $ig['alcance_antes'] * 100, 1) : null; ?>
<div class="kpis">
  <?= kpi('Seguidores', isset($ig['seguidores']) ? mil($ig['seguidores']) : '—', $ig['novos'] ?? null ? '+' . mil($ig['novos']) . ' novos ' . ($ig['novos_desde'] ? 'desde ' . date('d/m', strtotime($ig['novos_desde'])) : 'em ' . h($rot)) : '@' . h($ig['usuario'] ?? '')) ?>
  <?= kpi('Alcance', isset($ig['alcance']) ? mil($ig['alcance']) : '—', seta(variacao($ig['alcance'] ?? null, $ig['alcance_antes'] ?? null)) . ' contas diferentes', 'Contas diferentes que viram algo seu no período, igual ao app do Instagram (inclui anúncios).') ?>
  <?= kpi('Visualizações', isset($ig['visualizacoes']) ? mil($ig['visualizacoes']) : '—', seta(variacao($ig['visualizacoes'] ?? null, $ig['visualizacoes_antes'] ?? null)) . ' vezes na tela') ?>
  <?= kpi('Interações', isset($ig['interacoes']) ? mil($ig['interacoes']) : '—', seta(variacao($ig['interacoes'] ?? null, $ig['interacoes_antes'] ?? null)) . ' curtidas, comentários, salvos…') ?>
  <?= kpi('Engajamento', $eng !== null ? number_format($eng, 1, ',', '') . '%' : '—', $engAntes !== null ? 'antes: ' . number_format($engAntes, 1, ',', '') . '%' : 'interações ÷ alcance', 'Interações divididas pelo alcance do período. Mostra se quem vê o conteúdo reage a ele.') ?>
</div>
<h2>Últimos posts</h2><div class="card" style="padding:4px 16px">
<?php $maxA = max(array_map(fn($p) => (int) $p['alcance'], $ig['posts'] ?? []) ?: [1]) ?: 1;
foreach ($ig['posts'] ?? [] as $p) echo '<div class="row"><div class="x">' . ($p['link'] ? '<a href="' . h($p['link']) . '" target="_blank" rel="noopener">' . h($p['legenda'] ?: 'sem legenda') . '</a>' : h($p['legenda']))
    . '<div class="sub">' . h(['VIDEO' => 'Reel', 'CAROUSEL_ALBUM' => 'Carrossel', 'IMAGE' => 'Foto'][$p['tipo']] ?? $p['tipo']) . ' · ' . ($p['data'] ? date('d/m', strtotime($p['data'])) : '—') . ' · ' . $p['curtidas'] . ' curtidas · ' . $p['comentarios'] . ' comentários</div>'
    . ($p['alcance'] !== null ? '<div class="barra"><i style="width:' . round($p['alcance'] / $maxA * 100) . '%"></i></div>' : '') . '</div><span class="n">' . ($p['alcance'] !== null ? mil($p['alcance']) . '<br>alcance' : '') . '</span></div>';
if (empty($ig['posts'])) echo '<p class="dica">Nenhum post lido.</p>'; ?>
</div>
<?= botao_atualizar('Lido do Instagram ' . (isset($ig['em']) ? date('d/m H:i', (int) $ig['em']) : '') . '; atualiza a cada 1 h.') ?>
<?php endif; ?>

<?php elseif ($aba === 'anuncios'):
    echo periodo_form($per, 'anuncios');
    if ($an === null): ?>
  <div class="card"><p class="dica">Cole o token dos anúncios da Meta em <a href="?aba=ajustes">Ajustes</a> para ver gasto, leads e campanhas.</p></div>
<?php else:
    if (!empty($an['erro'])) echo '<p class="erro">Não consegui ler os anúncios: ' . h($an['erro']) . '.' . (isset($an['total']) ? ' Mostrando a última leitura.' : '') . '</p>';
    foreach ($an['avisos'] ?? [] as $a) echo '<p class="erro">⚠ ' . h($a) . '.</p>';
    $t = $an['total'] ?? null; $a0 = $an['antes'] ?? null;
    $res = fn($x) => $x['leads'] ?: $x['conversas'];
    $situacao = ['ACTIVE' => ['no ar', 't-ok'], 'LIMITE' => ['parada: limite da conta', 't-aviso'], 'ENCERRADA' => ['encerrada', 't-cinza'], 'PAUSED' => ['pausada', 't-cinza'], 'CAMPAIGN_PAUSED' => ['pausada', 't-cinza'],
        'WITH_ISSUES' => ['com problema', 't-erro'], 'IN_PROCESS' => ['em análise', 't-aviso'], 'PENDING_REVIEW' => ['em análise', 't-aviso'], 'DISAPPROVED' => ['reprovada', 't-erro'], 'ARCHIVED' => ['arquivada', 't-cinza']];
    if ($t): ?>
<div class="kpis">
  <?= kpi('Gasto', h(brl($t['gasto'])), seta(variacao($t['gasto'], $a0['gasto'] ?? null)) . ' · ' . plural((int) $an['ativas'], 'campanha no ar', 'campanhas no ar')) ?>
  <?= kpi($t['leads'] || !$t['conversas'] ? 'Leads' : 'Conversas no WhatsApp', mil($res($t)), seta(variacao($res($t), $a0 ? $res($a0) : null)) . ($t['leads'] && $t['conversas'] ? ' · ' . plural($t['conversas'], 'conversa', 'conversas') : ''), 'Quem preencheu o formulário do anúncio (ou começou conversa no WhatsApp), contado pela Meta.') ?>
  <?= kpi('Custo por resultado', meta_custo($t) !== null ? h(brl(meta_custo($t))) : '—', seta(variacao(meta_custo($t), $a0 ? meta_custo($a0) : null), true) . ' quanto menor, melhor') ?>
  <?= kpi('Alcance', mil($t['alcance']), mil($t['impressoes']) . ' vezes na tela', 'Contas diferentes que viram os anúncios.') ?>
  <?= kpi('Cliques', mil($t['cliques']), $t['impressoes'] ? number_format($t['cliques'] / $t['impressoes'] * 100, 2, ',', '') . '% de quem viu clicou' : '') ?>
</div>
<?php endif; ?>
<h2>Campanhas</h2><div class="card" style="padding:4px 16px">
<?php if (empty($an['campanhas'])) echo '<p class="dica">Nenhuma campanha no ar nem com gasto neste período.</p>';
foreach ($an['campanhas'] ?? [] as $c) {
    [$st, $tag] = $situacao[$c['situacao']] ?? [mb_strtolower($c['situacao']), 't-cinza'];
    $orc = $c['orcamento'] ? brl($c['orcamento'][1]) . ($c['orcamento'][0] === 'dia' ? ' por dia' : ' no total') . ' · ' : '';
    $cc = meta_custo($c);
    echo '<div class="row' . ($c['situacao'] === 'ACTIVE' ? '' : ' parada') . '"><span class="tag ' . $tag . '">' . h($st) . '</span><div class="x">' . h($c['nome'])
        . '<div class="sub">' . h($orc) . 'gasto ' . h(brl($c['gasto'])) . ' · ' . plural($c['cliques'], 'clique', 'cliques') . '</div></div>'
        . '<span class="n">' . plural($res($c), $c['leads'] || !$c['conversas'] ? 'lead' : 'conversa', $c['leads'] || !$c['conversas'] ? 'leads' : 'conversas') . ($cc !== null ? '<br>' . h(brl($cc)) . ' cada' : '') . '</span></div>';
} ?>
</div>
<?= botao_atualizar('Lido da Meta ' . (isset($an['em']) ? date('d/m H:i', (int) $an['em']) : '') . '; atualiza a cada 30 min.') ?>
<?php endif; ?>

<?php else: // ajustes
    $fim = fn(string $k) => ($v = (string) cfg($k, '')) !== '' ? 'configurado (termina em …' . h(substr($v, -4)) . ')' : 'não configurado'; ?>
<div class="cols meio">
<div>
<h2>Tokens</h2>
<form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="ajustes">
  <label class="c" for="ig_token">Token do Instagram · <?= $fim('ig_token') ?><?= cfg('ig_token_ate') ? ', vale até ' . date('d/m/Y', (int) cfg('ig_token_ate')) : '' ?></label>
  <input id="ig_token" name="ig_token" type="password" autocomplete="off" placeholder="IGAA… (vazio = manter o atual)">
  <p class="dica">Token de login do Instagram (o mesmo tipo que o motor dos carrosséis usa). O painel renova sozinho toda semana.</p>
  <label class="c" for="meta_conta">Conta de anúncios da Meta</label>
  <input id="meta_conta" name="meta_conta" inputmode="numeric" value="<?= h(cfg('meta_conta', '') ?: META_CONTA_PADRAO) ?>">
  <label class="c" for="meta_token">Token dos anúncios · <?= $fim('meta_token') ?></label>
  <input id="meta_token" name="meta_token" type="password" autocomplete="off" placeholder="EAA… (vazio = manter o atual)">
  <p class="dica">Token do Facebook (de preferência de usuário do sistema do Gerenciador de Negócios) com a permissão <code>ads_read</code> nesta conta. O token do Instagram não serve aqui.</p>
  <button>Salvar</button></form>
</div>
<div>
<h2>Chave dos robôs do GitHub</h2>
<div class="card"><p class="dica">Os workflows <code>painel.yml</code> (velocidade e Google) e <code>deploy.yml</code> mandam os números com esta chave. Ela precisa estar igual no GitHub: repositório → Settings → Secrets and variables → Actions → <code>PAINEL_TOKEN</code>.</p>
  <label class="c" for="cron">Chave</label><input id="cron" type="password" readonly value="<?= h((string) cfg('cron_token', '')) ?>">
  <form method="post" onsubmit="return confirm('Gerar uma chave nova? A antiga para de valer e o GitHub precisa da nova.')"><?= csrf_campo() ?><input type="hidden" name="acao" value="chave"><button class="sec">Gerar chave nova</button></form></div>
<h2>Senha do painel</h2>
<form method="post" class="card"><?= csrf_campo() ?><input type="hidden" name="acao" value="senha">
  <label class="c" for="atual">Senha atual</label><input id="atual" name="atual" type="password" required autocomplete="current-password">
  <label class="c" for="s1">Senha nova (10+ caracteres)</label><input id="s1" name="senha" type="password" required minlength="10" autocomplete="new-password">
  <label class="c" for="s2">Repita a senha nova</label><input id="s2" name="senha2" type="password" required minlength="10" autocomplete="new-password">
  <button>Trocar senha</button><p class="dica">Trocar a senha desconecta os outros aparelhos.</p></form>
</div>
</div>
<?php endif;
pagina_fim();
