<?php
declare(strict_types=1);
// Porta dos robôs do GitHub (só POST com JSON, header X-Painel-Key = cron_token dos Ajustes = secret PAINEL_TOKEN):
//   ?acao=velocidade  painel.yml, 1x por dia: notas do Lighthouse
//   ?acao=google      painel.yml, 1x por dia: visitas (GA4) e páginas no Google (Search Console)
//   ?acao=deploy      fim do deploy.yml: commit e mensagem
require __DIR__ . '/lib.php';
require __DIR__ . '/fontes.php';
header('Content-Type: application/json; charset=utf-8');
$nega = function (): void { http_response_code(404); exit('{"ok":false}'); };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_dir(DADOS)) $nega();
$chave = (string) cfg('cron_token', '');
if ($chave === '' || !hash_equals($chave, (string) ($_SERVER['HTTP_X_PAINEL_KEY'] ?? ''))) $nega();
$acao = (string) ($_GET['acao'] ?? '');
$j = json_decode((string) file_get_contents('php://input', false, null, 0, 262144), true);
if (!is_array($j)) { http_response_code(400); exit('{"ok":false,"erro":"JSON inválido"}'); }
$d = match ($acao) { 'velocidade' => site_velocidade_dados($j), 'google' => site_google_dados($j), 'deploy' => site_deploy_dados($j), default => false };
if ($d === false) $nega();
if ($d === null) { http_response_code(400); exit('{"ok":false,"erro":"faltam as notas"}'); }
json_atualizar($acao, fn($antes) => $acao === 'google' ? site_google_juntar($antes, $d) : $d);
echo '{"ok":true}';
