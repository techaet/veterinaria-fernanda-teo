<?php
declare(strict_types=1);
// Base do painel da Dra. Fernanda Teo (/painel/). Dados, senha e tokens ficam SÓ no servidor, em _dados/ (fora do Git, negado no .htaccess).
// Modelo: as abas Site, Instagram e Anúncios do /admin/ da Pousada Campeche Lofts. Spec: scripts/ESPECIFICACAO_PAINEL.md.

defined('DADOS') || define('DADOS', __DIR__ . '/_dados');  // o teste troca por uma pasta temporária
const SITE = 'https://www.fernandateo.vet.br';
// sha256 do código de instalação (o mesmo do painel da pousada; o código só o Leonardo conhece)
const CODIGO_INSTALACAO_SHA256 = 'f80ec50046bef84a15717bb1905f9bccc653d6fea1ada606fce81d33bf311dfc';
const LEMBRAR_COOKIE = 'painel_ft';
const LEMBRAR_DIAS = 180;

if (PHP_SAPI !== 'cli') {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
}
date_default_timezone_set('America/Sao_Paulo');

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function mil($n): string { return number_format((float) $n, 0, ',', '.'); }
function brl(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function plural(int $n, string $um, string $varios): string { return mil($n) . ' ' . ($n === 1 ? $um : $varios); }

/** Quanto tempo faz, em palavras curtas ("há 3 h", "há 2 dias"). */
function ha(int $ts, int $agora): string {
    $s = max(0, $agora - $ts);
    if ($s < 3600) return 'há ' . max(1, intdiv($s, 60)) . ' min';
    if ($s < 86400) return 'há ' . intdiv($s, 3600) . ' h';
    $d = intdiv($s, 86400);
    return 'há ' . $d . ' dia' . ($d === 1 ? '' : 's');
}

/** Variação em % de $antes para $agora; null quando não dá para comparar. */
function variacao(?float $agora, ?float $antes): ?int {
    return $agora === null || !$antes ? null : (int) round(($agora - $antes) / $antes * 100);
}

// ---------- armazenamento em JSON com trava ----------
function json_ler(string $nome): array {
    $f = DADOS . "/$nome.json";
    return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
}

/** Lê, aplica $fn (recebe o array, devolve o novo) e grava, tudo sob trava. Devolve o array novo. */
function json_atualizar(string $nome, callable $fn): array {
    $fp = fopen(DADOS . "/$nome.json", 'c+');
    flock($fp, LOCK_EX);
    $d = $fn(json_decode((string) stream_get_contents($fp), true) ?: []);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod(DADOS . "/$nome.json", 0600);
    return $d;
}

/** Configuração. Tokens e chaves saem sem espaços nem caracteres invisíveis (vêm grudados ao colar de páginas da Meta e do Google). */
function cfg(string $k, $padrao = null) {
    $v = json_ler('config')[$k] ?? $padrao;
    return is_string($v) && preg_match('/(_token|_chave|_conta)$/', $k) ? preg_replace('/[\p{Z}\p{C}]+/u', '', $v) : $v;
}
function cfg_salvar(array $novo): void { json_atualizar('config', fn($c) => array_merge($c, $novo)); }

// ---------- sessão, "lembrar neste aparelho", CSRF, tentativas ----------
function https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }

function sessao(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('painel_ft_sessao');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/painel/', 'secure' => https(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (empty($_SESSION['ok']) && lembrete_ok((string) ($_COOKIE[LEMBRAR_COOKIE] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
    }
}

/** Cookie "validade.assinatura": a assinatura usa o segredo da config, sorteado de novo ao trocar a senha (desliga todos os aparelhos). */
function lembrete_ok(string $c): bool {
    [$ate, $ass] = explode('.', $c, 2) + ['', ''];
    $seg = (string) cfg('segredo', '');
    return $seg !== '' && ctype_digit($ate) && (int) $ate > time() && hash_equals(hash_hmac('sha256', $ate, $seg), $ass);
}
function lembrete_criar(): void {
    $ate = time() + LEMBRAR_DIAS * 86400;
    setcookie(LEMBRAR_COOKIE, $ate . '.' . hash_hmac('sha256', (string) $ate, (string) cfg('segredo')),
        ['expires' => $ate, 'path' => '/painel/', 'secure' => https(), 'httponly' => true, 'samesite' => 'Strict']);
}
function lembrete_apagar(): void { setcookie(LEMBRAR_COOKIE, '', ['expires' => time() - 3600, 'path' => '/painel/']); }

function logado(): bool { sessao(); return !empty($_SESSION['ok']); }

/** No aparelho lembrado o token sai do cookie e não muda: formulário aberto há horas continua valendo depois que a sessão expira. */
function csrf_token(): string {
    sessao();
    $c = (string) ($_COOKIE[LEMBRAR_COOKIE] ?? '');
    return $_SESSION['csrf'] ??= lembrete_ok($c) ? hash_hmac('sha256', 'csrf', $c) : bin2hex(random_bytes(16));
}
function csrf_campo(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_ok(string $v): bool {
    $c = (string) ($_COOKIE[LEMBRAR_COOKIE] ?? '');
    return $v !== '' && (hash_equals(csrf_token(), $v) || (lembrete_ok($c) && hash_equals(hash_hmac('sha256', 'csrf', $c), $v)));
}

/** 5 falhas em 15 min por IP bloqueiam novas tentativas. */
function tentativas_bloqueado(): bool {
    $t = json_ler('tentativas')[$_SERVER['REMOTE_ADDR'] ?? '?'] ?? [];
    return count(array_filter($t, fn($x) => $x > time() - 900)) >= 5;
}
function tentativa_falha(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    json_atualizar('tentativas', function ($d) use ($ip) {
        foreach ($d as $k => $v) if (!($d[$k] = array_values(array_filter($v, fn($x) => $x > time() - 900)))) unset($d[$k]);
        $d[$ip][] = time();
        return $d;
    });
    usleep(500000);
}

// ---------- período ----------
const PERIODOS = [7 => '1 semana', 14 => '2 semanas', 28 => '4 semanas', 56 => '8 semanas', 84 => '12 semanas'];  // dias com hoje

/** Período escolhido ($q = $_GET: periodo = dias de PERIODOS ou 'p' com de/ate AAAA-MM-DD). Inválido = 4 semanas; datas futuras viram hoje. */
function periodo_escolhido(array $q, string $hoje): array {
    $dias = (int) ($q['periodo'] ?? 28);
    $data = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v = (string) ($q[$k] ?? '')) && strtotime($v) ? min($v, $hoje) : '';
    if (($q['periodo'] ?? '') === 'p' && ($de = $data('de')) !== '' && ($ate = $data('ate')) !== '') {
        if ($de > $ate) [$de, $ate] = [$ate, $de];
        $dm = fn($d) => date('d/m' . (substr($d, 0, 4) === substr($hoje, 0, 4) ? '' : '/Y'), strtotime($d));
        return ['chave' => 'p', 'de' => $de, 'ate' => $ate, 'rotulo' => $de === $ate ? $dm($de) : $dm($de) . ' a ' . $dm($ate)];
    }
    if (!isset(PERIODOS[$dias])) $dias = 28;
    return ['chave' => (string) $dias, 'de' => date('Y-m-d', strtotime("$hoje -" . ($dias - 1) . ' days')), 'ate' => $hoje, 'rotulo' => PERIODOS[$dias]];
}

/** O período de mesmo tamanho logo antes (para o ▲▼). */
function periodo_anterior(array $per): array {
    $n = (int) round((strtotime($per['ate']) - strtotime($per['de'])) / 86400) + 1;
    return ['de' => date('Y-m-d', strtotime($per['de'] . " -$n days")), 'ate' => date('Y-m-d', strtotime($per['de'] . ' -1 day'))];
}

/** Parte da URL que leva o período para outra aba. */
function periodo_query(array $per): string {
    return $per['chave'] === 'p' ? '&periodo=p&de=' . $per['de'] . '&ate=' . $per['ate'] : ($per['chave'] === '28' ? '' : '&periodo=' . $per['chave']);
}

function periodo_form(array $per, string $aba): string {
    $hoje = date('Y-m-d');
    $op = '';
    foreach (PERIODOS + ['p' => 'Escolher datas'] as $k => $t) $op .= '<option value="' . $k . '"' . ($per['chave'] === (string) $k ? ' selected' : '') . '>' . $t . '</option>';
    return '<form method="get" class="per"><input type="hidden" name="aba" value="' . h($aba) . '"><label for="per">Período</label>'
        . '<select id="per" name="periodo" onchange="var p=this.value===\'p\';this.form.querySelector(\'.datas\').hidden=!p;if(!p)this.form.submit()">' . $op . '</select>'
        . '<span class="datas"' . ($per['chave'] === 'p' ? '' : ' hidden') . '><input type="date" name="de" aria-label="De" value="' . h($per['de']) . '" max="' . $hoje . '"> a '
        . '<input type="date" name="ate" aria-label="Até" value="' . h($per['ate']) . '" max="' . $hoje . '"> <button>Ver</button></span>'
        . '<span class="vs">comparado com o mesmo tanto de dias logo antes</span></form>';
}

// ---------- layout ----------
const ABAS = ['resumo' => 'Resumo', 'site' => 'Site', 'instagram' => 'Instagram', 'anuncios' => 'Anúncios', 'ajustes' => 'Ajustes'];

function pagina_topo(string $titulo, ?string $aba = null, string $query = ''): void {
    echo '<!DOCTYPE html><html lang="pt-BR" translate="no"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#eaf3ee"><link rel="icon" href="/favicon-32x32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">'
        . '<title>' . h($titulo) . ' · Painel Dra. Fernanda Teo</title><style>' . PAINEL_CSS . '</style></head><body>'
        . '<header class="topo"><div class="lin"><div><div class="marca">Dra. Fernanda Teo</div><h1>' . h($titulo) . '</h1></div>';
    if ($aba !== null) {
        echo '<form method="post" class="sair">' . csrf_campo() . '<input type="hidden" name="acao" value="sair"><button class="link">Sair</button></form></div><nav class="abas" aria-label="Seções">';
        foreach (ABAS as $k => $t) echo '<a href="?aba=' . $k . ($k === 'ajustes' ? '' : $query) . '"' . ($k === $aba ? ' aria-current="page"' : '') . '>' . $t . '</a>';
        echo '</nav>';
    } else echo '</div>';
    echo '</header><main>';
}

function pagina_fim(): void {
    // botão "Mostrar" nos campos de senha; "Atualizando…" nos botões que esperam a Meta ou o Instagram
    echo '</main><script>document.querySelectorAll("input[type=password]").forEach(function(i){var b=document.createElement("button");b.type="button";b.className="mostrar";b.textContent="Mostrar";'
        . 'b.onclick=function(){var v=i.type==="password";i.type=v?"text":"password";b.textContent=v?"Ocultar":"Mostrar"};i.insertAdjacentElement("afterend",b)});'
        . 'document.querySelectorAll("form[data-espera]").forEach(function(f){f.addEventListener("submit",function(){var b=f.querySelector("button");b.disabled=true;b.textContent=f.dataset.espera})});</script></body></html>';
}

// Glassmorphism (07/11/2026, pedido do Leonardo): fundo com manchas de cor fixas e cartões de vidro fosco por cima.
// Sem suporte a backdrop-filter ou com "reduzir transparência" ligado, os cartões ficam quase opacos (o texto continua legível).
const PAINEL_CSS = ':root{--verde:#2E7D32;--verde-esc:#1B5E20;--ink:#14231c;--muted:#4c5f58;--line:rgba(20,50,35,.10);'
    . '--vidro:rgba(255,255,255,.52);--vidro-forte:rgba(255,255,255,.72);--borda:rgba(255,255,255,.75);--sombra:0 8px 32px rgba(27,94,32,.12);--campo:rgba(255,255,255,.65);'
    . '--fundo:#eaf3ee;--m1:#9be7a8;--m2:#7fd6cf;--m3:#b9d7ff;--m4:#ffe0b2;'
    . '--ok:#1f6b25;--ok-bg:rgba(102,187,106,.22);--aviso:#7a4b00;--aviso-bg:rgba(255,183,77,.28);--erro:#a1221b;--erro-bg:rgba(239,83,80,.18);--trilha:rgba(20,50,35,.08)}'
    . '@media(prefers-color-scheme:dark){:root{--verde:#8be29a;--verde-esc:#c8f5d0;--ink:#e8f1ec;--muted:#a6b8b0;--line:rgba(255,255,255,.08);'
    . '--vidro:rgba(20,34,30,.50);--vidro-forte:rgba(20,34,30,.78);--borda:rgba(255,255,255,.10);--sombra:0 8px 32px rgba(0,0,0,.35);--campo:rgba(255,255,255,.06);'
    . '--fundo:#0b1411;--m1:#1f6b3a;--m2:#145a5a;--m3:#243a6b;--m4:#5a3d14;'
    . '--ok:#8be29a;--ok-bg:rgba(139,226,154,.14);--aviso:#ffc069;--aviso-bg:rgba(255,183,77,.15);--erro:#ff9a92;--erro-bg:rgba(239,83,80,.16);--trilha:rgba(255,255,255,.08)}}'
    . '*{box-sizing:border-box}html{background:var(--fundo)}'
    . 'body{margin:0;min-height:100vh;font:16px/1.5 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);-webkit-text-size-adjust:100%;background:'
    . 'radial-gradient(38rem 38rem at 8% 6%,var(--m1),transparent 70%),radial-gradient(34rem 34rem at 92% 14%,var(--m3),transparent 70%),'
    . 'radial-gradient(40rem 40rem at 78% 88%,var(--m2),transparent 70%),radial-gradient(30rem 30rem at 18% 92%,var(--m4),transparent 70%),var(--fundo);background-attachment:fixed}'
    . '.vidro,header.topo,.card,.kpi,.saude a{background:var(--vidro);border:1px solid var(--borda);box-shadow:var(--sombra);-webkit-backdrop-filter:blur(18px) saturate(160%);backdrop-filter:blur(18px) saturate(160%)}'
    . '@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){header.topo,.card,.kpi,.saude a{background:var(--vidro-forte)}}'
    . '@media(prefers-reduced-transparency:reduce){header.topo,.card,.kpi,.saude a{background:var(--vidro-forte);-webkit-backdrop-filter:none;backdrop-filter:none}}'
    . 'a{color:var(--verde)}'
    . 'header.topo{position:sticky;top:0;z-index:5;margin:12px 12px 0;padding:12px 16px 10px;padding-top:max(12px,env(safe-area-inset-top));border-radius:20px}'
    . '.topo .lin{max-width:1180px;margin:0 auto;display:flex;justify-content:space-between;align-items:flex-end;gap:12px;padding-bottom:10px}'
    . '.marca{font-size:13px;color:var(--muted)}h1{font:600 22px/1.2 Poppins,Inter,system-ui,sans-serif;margin:2px 0 0;color:var(--verde-esc)}'
    . '.abas{max-width:1180px;margin:0 auto;display:flex;gap:6px;overflow-x:auto;scrollbar-width:none}.abas::-webkit-scrollbar{display:none}'
    . '.abas a{flex:none;padding:7px 15px;border-radius:999px;color:var(--ink);text-decoration:none;font-weight:500;font-size:15px;border:1px solid transparent}'
    . '.abas a:hover{background:var(--vidro-forte);border-color:var(--borda)}.abas a[aria-current]{background:var(--verde);color:#fff;box-shadow:0 4px 14px rgba(46,125,50,.35)}'
    . '@media(prefers-color-scheme:dark){.abas a[aria-current]{color:#0b1411}}'
    . 'button.link{background:none;border:0;box-shadow:none;color:var(--muted);text-decoration:underline;min-height:0;margin:0;padding:4px;font:inherit;cursor:pointer}.sair{margin:0}'
    . 'main{max-width:1180px;margin:0 auto;padding:20px 16px 48px}h2{font-size:16px;font-weight:600;margin:24px 0 10px}'
    . '.card{border-radius:20px;padding:16px;margin-bottom:12px}'
    . '.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:6px}'
    . '.kpi{border-radius:18px;padding:14px 16px}.kpi .l{font-size:13px;color:var(--muted)}'
    . '.kpi .v{font:600 26px/1.2 Poppins,Inter,system-ui,sans-serif;font-variant-numeric:tabular-nums;margin:4px 0 2px}.kpi .s{font-size:13px;color:var(--muted)}'
    . '.up{color:var(--ok);font-weight:600}.dn{color:var(--erro);font-weight:600}'
    . '.cols{display:grid;gap:0 20px}@media(min-width:900px){.cols{grid-template-columns:minmax(0,7fr) minmax(0,5fr)}.cols.meio{grid-template-columns:1fr 1fr}}'
    . '.row{display:flex;gap:10px;align-items:baseline;padding:10px 0;border-bottom:1px solid var(--line)}.row:last-child{border:0}'
    . '.row .x{flex:1;min-width:0;overflow-wrap:anywhere}.sub{font-size:13px;color:var(--muted)}.n{font-variant-numeric:tabular-nums;white-space:nowrap;font-size:14px;color:var(--muted);text-align:right}'
    . '.tag{flex:none;display:inline-block;padding:1px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}'
    . '.t-ok{background:var(--ok-bg);color:var(--ok)}.t-aviso{background:var(--aviso-bg);color:var(--aviso)}.t-erro{background:var(--erro-bg);color:var(--erro)}.t-cinza{background:var(--trilha);color:var(--muted)}'
    . '.parada{opacity:.6}.dica{font-size:14px;color:var(--muted)}'
    . '.saude{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-bottom:8px}'
    . '.saude a{position:relative;overflow:hidden;display:block;text-decoration:none;color:inherit;border-radius:20px;padding:16px 18px;transition:transform .15s,box-shadow .15s}'
    . '.saude a::before{content:"";position:absolute;inset:0 auto 0 0;width:5px;background:var(--muted)}'  // a faixa de cor diz o estado; a etiqueta diz em palavras
    . '.saude a:hover{transform:translateY(-2px);box-shadow:0 14px 40px rgba(27,94,32,.18)}.saude a.ok::before{background:#43a047}.saude a.aviso::before{background:#f9a825}.saude a.erro::before{background:#e53935}'
    . '@media(prefers-reduced-motion:reduce){.saude a{transition:none}.saude a:hover{transform:none}}'
    . '.saude .t{display:flex;justify-content:space-between;align-items:center;gap:8px;font-weight:600}.saude .v{font:600 24px/1.2 Poppins,Inter,system-ui,sans-serif;margin:8px 0 2px}'
    . '.barra{height:8px;border-radius:4px;background:var(--trilha);overflow:hidden;margin-top:5px}.barra i{display:block;height:100%;border-radius:4px;background:linear-gradient(90deg,#43a047,#26a69a)}'
    . '.per{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px}.per label{font-size:14px;color:var(--muted)}.per .vs{font-size:13px;color:var(--muted)}'
    . '.per select,.per input{width:auto}.per .datas{display:flex;flex-wrap:wrap;gap:8px;align-items:center}[hidden]{display:none!important}'
    . 'label.c{display:block;font-size:14px;font-weight:500;color:var(--muted);margin:14px 0 6px}'
    . 'input,select{min-height:44px;padding:9px 12px;border:1px solid var(--borda);border-radius:12px;font:inherit;color:inherit;background:var(--campo);width:100%;-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}'
    . 'select option{color:#14231c;background:#fff}input:focus,select:focus{outline:2px solid var(--verde);outline-offset:0}'
    . 'button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;margin-top:14px;padding:9px 18px;border:0;border-radius:12px;background:var(--verde);color:#fff;font:600 15px/1.2 inherit;font-family:inherit;cursor:pointer;box-shadow:0 4px 14px rgba(46,125,50,.3)}'
    . '@media(prefers-color-scheme:dark){button{color:#0b1411}}button:disabled{opacity:.6}.per button{margin:0}'
    . 'button.sec{background:var(--vidro-forte);color:var(--verde);border:1px solid var(--borda);box-shadow:none}button.mostrar{margin:6px 0 0;min-height:32px;padding:4px 12px;font-size:13px;background:none;color:var(--verde);border:1px solid var(--borda);box-shadow:none}'
    . '.ok-msg{background:var(--ok-bg);color:var(--ok);padding:12px 16px;border-radius:14px;margin-bottom:14px;border:1px solid var(--borda)}.erro{background:var(--erro-bg);color:var(--erro);padding:12px 16px;border-radius:14px;margin-bottom:14px;border:1px solid var(--borda)}'
    . '.entrar{max-width:420px;margin:8vh auto}code{font-size:13px;background:var(--trilha);padding:1px 6px;border-radius:6px;overflow-wrap:anywhere}'
    . 'details summary{cursor:pointer;padding:8px 0}';
