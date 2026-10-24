// Robô do podcast: para cada artigo do blog sem episódio, gera no NotebookLM (Gemini Notebook) um
// "Resumo em Áudio" (Análise detalhada, duração Padrão, no idioma do artigo), baixa quando fica pronto e publica
// em /podcast/ (adicionar_podcast.py + commit + push na main → deploy). Vale para cada site de SITES.
// Uso: node robo.mjs            (o launchd roda a cada 3 h; ver LEIA-ME.md)
//      node robo.mjs --so-conferir   (não começa episódios novos)
import { chromium } from 'playwright';
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const BASE = path.join(os.homedir(), 'Library/Application Support/aet-podcast');
const PERFIL = path.join(BASE, 'chrome');           // Chrome só do robô, logado em tech.aetsolidez
const TESTE = process.env.PODCAST_REPO;             // teste: usa esse checkout como está, sem pull nem push
const SO = process.env.PODCAST_SITE || (TESTE ? 'fernanda-teo' : ''); // só um site (nome de SITES)
const ARTIGO = process.env.PODCAST_ARTIGO; // teste: só esse artigo
// Um site entra no robô quando o repo dele tem scripts/adicionar_podcast.py na main.
// fonte: texto que aparece na lista de fontes do NotebookLM quando o artigo carrega (está no <title>).
// linguas: um episódio por idioma para cada artigo (id "<slug>/<lingua>"); sem linguas, um só, no idioma do artigo (id "<slug>").
// foco: instrução aos apresentadores, por idioma (campo "Em quais aspectos os apresentadores de IA devem se concentrar").
const SITES = [
  { nome: 'fernanda-teo', git: 'https://github.com/techaet/veterinaria-fernanda-teo.git', pasta: 'site', estado: 'estado.json',
    url: 'https://www.fernandateo.vet.br', fonte: 'Dra. Fernanda Teo', verificar: 'scripts/verificar.py' },
  { nome: 'pousada-campeche-lofts', git: 'https://github.com/techaet/pousada-campeche-lofts.git', pasta: 'site-pousada-campeche-lofts',
    estado: 'estado-pousada-campeche-lofts.json', url: 'https://www.campechelofts.floripa.br', fonte: 'Campeche Lofts', verificar: 'scripts/check_site.py',
    linguas: ['pt', 'es'],
    // só instrução positiva: o que se diz para "não falar" de algo (pousada, preço) os apresentadores falam em voz alta
    foco: {
      pt: 'Conversa em português do Brasil para viajantes brasileiros que planejam conhecer o Campeche e Florianópolis: o que fazer, como se organizar e o que conferir antes de ir.',
      es: 'Conversación en español rioplatense, con el voseo de Argentina en toda la charla (vos tenés, vos podés, mirá, fijate, andá), para viajeros argentinos que planean conocer Campeche y Florianópolis: qué hacer, cómo organizarse y qué chequear antes de ir.',
    } },
].filter(s => !SO || s.nome === SO)
  .map(s => ({ ...s, repo: TESTE || path.join(BASE, s.pasta), estado: path.join(BASE, s.estado) }));
const IDIOMAS = { pt: 'português (Brasil)', es: 'español (Latinoamérica)' }; // o NotebookLM não tem espanhol da Argentina: o voseo vem do foco
const BAIXADOS = path.join(BASE, 'baixados');
const LOG = path.join(os.homedir(), 'Library/Logs/aet-podcast.log');
const PORTA = 9333;
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const NB = 'https://notebook.google.com/';
const LOTE = 5;              // episódios novos por rodada (somando os sites: o limite do NotebookLM é da conta)
const ESPERA_1 = 5 * 60e3;   // 1ª conferência
const ESPERA_N = 3 * 60e3;   // conferências seguintes
const MAX_RODADA = 45 * 60e3; // o que não ficar pronto fica para a próxima rodada
const MAX_TENTATIVAS = 2;

const soConferir = process.argv.includes('--so-conferir');
const dorme = ms => new Promise(r => setTimeout(r, ms));
const log = msg => { const l = `${new Date().toLocaleString('sv-SE').slice(0, 16)} ${msg}`; console.log(l); fs.appendFileSync(LOG, l + '\n'); };
const avisar = msg => { log('AVISO ' + msg); try { execFileSync('osascript', ['-e', `display notification ${JSON.stringify(msg)} with title "Robô do podcast"`]); } catch {} };
const git = (s, ...a) => execFileSync('git', ['-C', s.repo, ...a], { encoding: 'utf8' }).trim();
const lerEstado = s => { try { return JSON.parse(fs.readFileSync(s.estado, 'utf8')); } catch { return {}; } };
const gravarEstado = s => fs.writeFileSync(s.estado, JSON.stringify(s.est, null, 2));

// ---------- site ----------
function atualizarRepo(s) {
  if (TESTE) return;
  if (!fs.existsSync(s.repo)) execFileSync('git', ['clone', '-q', s.git, s.repo]);
  git(s, 'checkout', '-q', 'main');
  git(s, 'pull', '-q', '--ff-only');
}

function pendentes(s, estado) {
  const cfg = JSON.parse(fs.readFileSync(path.join(s.repo, 'scripts/site.json'), 'utf8'));
  const feitos = new Set((cfg.podcasts || []).map(e => s.linguas ? `${e.artigo}/${e.lingua}` : e.artigo));
  return fs.readdirSync(path.join(s.repo, 'blog'))
    .filter(a => fs.existsSync(path.join(s.repo, 'blog', a, 'index.html')) && (!ARTIGO || a === ARTIGO))
    .flatMap(a => s.linguas ? s.linguas.map(l => `${a}/${l}`) : [a])
    .filter(id => !feitos.has(id))
    .filter(id => !estado[id] || (estado[id].status === 'erro' && (estado[id].tentativas || 0) < MAX_TENTATIVAS));
}

// código do idioma do episódio: o do id ou, sem ele, o <html lang> do artigo
const lingua = (s, id) => id.split('/')[1]
  || (/<html lang="es/.test(fs.readFileSync(path.join(s.repo, 'blog', id, 'index.html'), 'utf8')) ? 'es' : 'pt');

function publicar(s, id, arquivo, titulo) { // id do episódio: "<slug>" ou "<slug>/<lingua>"
  if (!TESTE) git(s, 'pull', '-q', '--ff-only'); // a main pode ter mudado durante a rodada
  execFileSync('python3', [path.join(s.repo, 'scripts/adicionar_podcast.py'), id, arquivo, titulo], { stdio: 'inherit' });
  // trecho do episódio para o player (transcreve com whisper.cpp; se falhar, fica o aviso padrão)
  try { execFileSync('python3', [path.join(s.repo, 'scripts/trecho_podcast.py'), id], { stdio: 'inherit', timeout: 30 * 60e3 }); }
  catch (e) { log(`sem trecho para ${id}: ${e.message.split('\n')[0]}`); }
  execFileSync('python3', [path.join(s.repo, s.verificar)], { stdio: 'inherit' });
  git(s, 'add', '-A');
  git(s, '-c', 'user.name=robo-podcast', '-c', 'user.email=tech.aetsolidez@gmail.com', 'commit', '-q', '-m', `Podcast: ${titulo} (${id})`);
  if (!TESTE) git(s, 'push', '-q', 'origin', 'main'); // na hora: deixa a main livre para outras mudanças
}

// ---------- Chrome / NotebookLM ----------
async function conectar() {
  const ligar = () => chromium.connectOverCDP(`http://127.0.0.1:${PORTA}`);
  try { return await ligar(); } catch {}
  // Chrome aberto sem nenhuma janela: o Playwright só conecta depois que houver uma aba
  try { await fetch(`http://127.0.0.1:${PORTA}/json/new?${NB}`, { method: 'PUT' }); await dorme(3000); return await ligar(); } catch {}
  // Chrome comum (sem as marcas de automação do Playwright, que o login do Google recusa)
  spawn(CHROME, [`--user-data-dir=${PERFIL}`, `--remote-debugging-port=${PORTA}`, '--no-first-run', '--no-default-browser-check', NB],
    { detached: true, stdio: 'ignore' }).unref();
  for (let i = 0; i < 30; i++) { await dorme(1000); try { return await ligar(); } catch {} }
  throw new Error('Chrome do robô não abriu');
}

async function fecharAvisos(p) {
  for (const nome of ['Fechar caixa de diálogo', 'Dispensar banner'])
    await p.getByLabel(nome, { exact: true }).first().click({ timeout: 1500 }).catch(() => {});
}

async function abrir(p, url) {
  await p.goto(url, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(6000);
  if (!p.url().startsWith(NB)) throw new Error('NotebookLM deslogado: ' + p.url());
  await fecharAvisos(p);
}

async function gerar(p, s, id) {
  const slug = id.split('/')[0], cod = lingua(s, id);
  await abrir(p, NB);
  await p.getByRole('button', { name: 'Novo notebook' }).click();
  await p.waitForURL(/\/notebook\//, { timeout: 30000 });
  // desde 24/10/2026 o notebook novo não abre mais sozinho a janela de fontes
  if (!(await p.locator('button', { hasText: 'Sites' }).first().isVisible())) await p.getByRole('button', { name: 'Adicionar fonte', exact: true }).click();
  await p.locator('button', { hasText: 'Sites' }).first().click();
  await p.getByLabel('Insira os URLs').fill(`${s.url}/blog/${slug}/`);
  await p.getByRole('button', { name: 'Inserir', exact: true }).click();
  // fonte carregada: o título do artigo aparece na lista de fontes
  await p.getByText(s.fonte, { exact: false }).first().waitFor({ timeout: 90000 });
  await p.waitForTimeout(3000);
  await fecharAvisos(p);
  await p.locator('[aria-label="Resumo em Áudio"]').first().click();
  const d = p.getByRole('dialog');
  await d.getByText('Análise detalhada', { exact: true }).click();
  await d.getByRole('radio', { name: 'Padrão' }).click();
  const nome = IDIOMAS[cod], caixa = d.getByRole('combobox').first();
  if (!(await caixa.innerText()).includes(nome)) {
    await caixa.click();
    await p.getByRole('option', { name: nome, exact: true }).click();
  }
  const escolhido = (await caixa.innerText()).trim();
  if (!escolhido.includes(nome)) throw new Error(`idioma do áudio é "${escolhido}", esperado ${nome}`);
  if (s.foco?.[cod]) await d.getByLabel('Em quais aspectos os apresentadores de IA devem se concentrar', { exact: false }).fill(s.foco[cod]);
  const gerarJa = d.getByRole('button', { name: 'Gere agora mesmo' });
  // no limite de uso o botão some (fica só "Gerar mais tarde") ou vem desativado
  if (!(await gerarJa.isVisible({ timeout: 10000 }).catch(() => false)) || !(await gerarJa.isEnabled())) {
    await d.getByLabel('Fechar caixa de diálogo').click().catch(() => {});
    return 'limite';
  }
  await gerarJa.click();
  await p.getByText('Gerando Resumo em Áudio', { exact: false }).first().waitFor({ timeout: 30000 });
  return p.url().split('?')[0];
}

// 'gerando' | 'erro' | { titulo, arquivo }
async function conferir(b, p, id, url) {
  await abrir(p, url);
  const item = p.locator('artifact-library-item').filter({ has: p.locator('[aria-description="Resumo em Áudio"]') }).first();
  if (!(await item.count())) return (await p.getByText('Gerando Resumo em Áudio').count()) ? 'gerando' : 'erro';
  const texto = (await item.innerText()).replace(/\s+/g, ' ');
  if (/Gerando/i.test(texto)) return 'gerando';
  if (!/\d+:\d\d/.test(texto)) return 'erro'; // pronto mostra a duração (31:50 · Deep Dive)
  const titulo = (await item.locator('.artifact-title').innerText()).trim();
  const pasta = path.join(BAIXADOS, id);
  fs.rmSync(pasta, { recursive: true, force: true }); fs.mkdirSync(pasta, { recursive: true });
  const cdp = await b.newBrowserCDPSession();
  await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: pasta, eventsEnabled: true });
  const fim = new Promise((ok, falha) => {
    cdp.on('Browser.downloadProgress', e => { if (e.state === 'completed') ok(e.filePath); if (e.state === 'canceled') falha(new Error('download cancelado')); });
    setTimeout(() => falha(new Error('download não terminou em 5 min')), 5 * 60e3).unref();
  });
  await item.getByRole('button', { name: 'Mais' }).click();
  await p.getByRole('menuitem', { name: 'Baixar' }).click();
  const arquivo = await fim;
  for (const extra of b.contexts()[0].pages()) if (extra !== p && !extra.url().startsWith('chrome')) await extra.close().catch(() => {});
  return { titulo, arquivo };
}

// ---------- rodada ----------
async function main() {
  fs.mkdirSync(BAIXADOS, { recursive: true });
  if (!fs.existsSync('/Volumes/Extreme SSD/AET')) return log('HD não montado: nada a fazer');
  const sites = [];
  for (const s of SITES) {
    try { atualizarRepo(s); } catch (e) { log(`${s.nome}: não consegui atualizar o repo: ${e.message.split('\n')[0]}`); continue; }
    if (!fs.existsSync(path.join(s.repo, 'scripts/adicionar_podcast.py'))) continue; // site ainda sem podcast
    s.est = lerEstado(s);
    sites.push(s);
  }
  if (!sites.length) return log('nenhum site com podcast');
  const b = await conectar();
  const ctx = b.contexts()[0];
  const p = await ctx.newPage();
  let novos = 0;
  try {
    // 1. começa os novos
    if (!soConferir) {
      lote: for (const s of sites) {
        for (const id of pendentes(s, s.est)) {
          if (novos >= LOTE) break lote;
          const est = s.est;
          try {
            const r = await gerar(p, s, id);
            if (r === 'limite') { log('limite de uso do NotebookLM: paro de começar novos nesta rodada'); break lote; }
            est[id] = { status: 'gerando', notebook: r, pedido: new Date().toISOString(), tentativas: (est[id]?.tentativas || 0) + 1 };
            gravarEstado(s); novos++; log(`gerando ${id} → ${r}`);
          } catch (e) {
            if (/deslogado/.test(e.message)) throw e;
            est[id] = { status: 'erro', erro: e.message.split('\n')[0], tentativas: (est[id]?.tentativas || 0) + 1 };
            gravarEstado(s); log(`ERRO ao gerar ${id}: ${est[id].erro}`);
          }
        }
      }
    }
    // 2. confere: 5 min, depois a cada 3 min, até 45 min
    const inicio = Date.now();
    const gerando = () => sites.flatMap(s => Object.entries(s.est).filter(([, v]) => v.status === 'gerando').map(([id, v]) => [s, id, v]));
    for (let volta = 0; ; volta++) {
      if (!gerando().length) break;
      if (volta === 0 && novos) await dorme(ESPERA_1);
      for (const [s, id, v] of gerando()) {
        try {
          const r = await conferir(b, p, id, v.notebook);
          if (r === 'gerando') continue;
          if (r === 'erro') { Object.assign(v, { status: 'erro', erro: 'o NotebookLM não gerou o áudio' }); log(`ERRO ${id}: áudio não gerado`); }
          else {
            try { publicar(s, id, r.arquivo, r.titulo); }
            catch (e) { git(s, 'reset', '-q', '--hard'); git(s, 'clean', '-qfd', 'podcast', 'scripts'); throw e; } // desfaz o meio-publicado
            fs.rmSync(path.dirname(r.arquivo), { recursive: true, force: true }); // original já conferido no HD
            Object.assign(v, { status: 'publicado', titulo: r.titulo, em: new Date().toISOString() });
            log(`publicado ${id}: "${r.titulo}"`);
          }
        } catch (e) { log(`falha ao conferir ${id}: ${e.message.split('\n')[0]}`); }
        gravarEstado(s);
      }
      if (Date.now() - inicio > MAX_RODADA || !gerando().length) break;
      await dorme(ESPERA_N);
    }
  } catch (e) {
    if (/deslogado/.test(e.message)) avisar('O NotebookLM saiu da conta. Entre de novo na janela do Chrome do robô.');
    else avisar('Falhou: ' + e.message.split('\n')[0]);
    process.exitCode = 1;
  } finally {
    await p.close().catch(() => {});
    await b.close(); // só desconecta: o Chrome do robô continua aberto e logado
  }
  const resto = sites.map(s => `${s.nome} ${pendentes(s, lerEstado(s)).length}`).join(', ');
  log(`fim da rodada: artigo(s) ainda sem episódio: ${resto}`);
}

main();
