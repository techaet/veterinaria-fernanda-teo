// Robô do podcast: para cada artigo do blog sem episódio, gera no NotebookLM (Gemini Notebook) um
// "Resumo em Áudio" (Análise detalhada, duração Padrão, português), baixa quando fica pronto e publica
// em /podcast/ (adicionar_podcast.py + commit + push na main → deploy).
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
const REPO = TESTE || path.join(BASE, 'site');      // clone do site só do robô
const BAIXADOS = path.join(BASE, 'baixados');
const ESTADO = path.join(BASE, 'estado.json');
const LOG = path.join(os.homedir(), 'Library/Logs/aet-podcast.log');
const PORTA = 9333;
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SITE = 'https://www.fernandateo.vet.br';
const NB = 'https://notebook.google.com/';
const LOTE = 5;              // episódios novos por rodada
const ESPERA_1 = 5 * 60e3;   // 1ª conferência
const ESPERA_N = 3 * 60e3;   // conferências seguintes
const MAX_RODADA = 45 * 60e3; // o que não ficar pronto fica para a próxima rodada
const MAX_TENTATIVAS = 2;

const soConferir = process.argv.includes('--so-conferir');
const dorme = ms => new Promise(r => setTimeout(r, ms));
const log = msg => { const l = `${new Date().toISOString().slice(0, 16).replace('T', ' ')} ${msg}`; console.log(l); fs.appendFileSync(LOG, l + '\n'); };
const avisar = msg => { log('AVISO ' + msg); try { execFileSync('osascript', ['-e', `display notification ${JSON.stringify(msg)} with title "Robô do podcast"`]); } catch {} };
const git = (...a) => execFileSync('git', ['-C', REPO, ...a], { encoding: 'utf8' }).trim();
const lerEstado = () => { try { return JSON.parse(fs.readFileSync(ESTADO, 'utf8')); } catch { return {}; } };
const gravarEstado = e => fs.writeFileSync(ESTADO, JSON.stringify(e, null, 2));

// ---------- site ----------
function atualizarRepo() {
  if (TESTE) return;
  if (!fs.existsSync(REPO)) execFileSync('git', ['clone', '-q', 'https://github.com/techaet/veterinaria-fernanda-teo.git', REPO]);
  git('checkout', '-q', 'main');
  git('pull', '-q', '--ff-only');
}

function pendentes(estado) {
  const cfg = JSON.parse(fs.readFileSync(path.join(REPO, 'scripts/site.json'), 'utf8'));
  const feitos = new Set(cfg.podcasts.map(e => e.artigo));
  return fs.readdirSync(path.join(REPO, 'blog'))
    .filter(s => fs.existsSync(path.join(REPO, 'blog', s, 'index.html')) && !feitos.has(s))
    .filter(s => !estado[s] || (estado[s].status === 'erro' && (estado[s].tentativas || 0) < MAX_TENTATIVAS));
}

function publicar(slug, arquivo, titulo) {
  execFileSync('python3', [path.join(REPO, 'scripts/adicionar_podcast.py'), slug, arquivo, titulo], { stdio: 'inherit' });
  execFileSync('python3', [path.join(REPO, 'scripts/verificar.py')], { stdio: 'inherit' });
  git('add', '-A');
  git('-c', 'user.name=robo-podcast', '-c', 'user.email=tech.aetsolidez@gmail.com', 'commit', '-q', '-m', `Podcast: ${titulo} (${slug})`);
}

// ---------- Chrome / NotebookLM ----------
async function conectar() {
  try { return await chromium.connectOverCDP(`http://127.0.0.1:${PORTA}`); } catch {}
  // Chrome comum (sem as marcas de automação do Playwright, que o login do Google recusa)
  spawn(CHROME, [`--user-data-dir=${PERFIL}`, `--remote-debugging-port=${PORTA}`, '--no-first-run', '--no-default-browser-check', NB],
    { detached: true, stdio: 'ignore' }).unref();
  for (let i = 0; i < 30; i++) { await dorme(1000); try { return await chromium.connectOverCDP(`http://127.0.0.1:${PORTA}`); } catch {} }
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

async function gerar(p, slug) {
  await abrir(p, NB);
  await p.getByRole('button', { name: 'Novo notebook' }).click();
  await p.waitForURL(/\/notebook\//, { timeout: 30000 });
  await p.locator('button', { hasText: 'Sites' }).first().click();
  await p.getByLabel('Insira os URLs').fill(`${SITE}/blog/${slug}/`);
  await p.getByRole('button', { name: 'Inserir', exact: true }).click();
  // fonte carregada: o título do artigo aparece na lista de fontes
  await p.getByText('Dra. Fernanda Teo', { exact: false }).first().waitFor({ timeout: 90000 });
  await p.waitForTimeout(3000);
  await fecharAvisos(p);
  await p.locator('[aria-label="Resumo em Áudio"]').first().click();
  const d = p.getByRole('dialog');
  await d.getByText('Análise detalhada', { exact: true }).click();
  await d.getByRole('radio', { name: 'Padrão' }).click();
  const idioma = await d.getByRole('combobox').first().innerText();
  if (!/portugu[eê]s \(Brasil\)/i.test(idioma)) throw new Error(`idioma do áudio é "${idioma}", esperado português (Brasil)`);
  const gerarJa = d.getByRole('button', { name: 'Gere agora mesmo' });
  if (!(await gerarJa.isEnabled())) return 'limite';
  await gerarJa.click();
  await p.getByText('Gerando Resumo em Áudio', { exact: false }).first().waitFor({ timeout: 30000 });
  return p.url().split('?')[0];
}

// 'gerando' | 'erro' | { titulo, arquivo }
async function conferir(b, p, slug, url) {
  await abrir(p, url);
  const item = p.locator('artifact-library-item').filter({ has: p.locator('[aria-description="Resumo em Áudio"]') }).first();
  if (!(await item.count())) return (await p.getByText('Gerando Resumo em Áudio').count()) ? 'gerando' : 'erro';
  const texto = (await item.innerText()).replace(/\s+/g, ' ');
  if (/Gerando/i.test(texto)) return 'gerando';
  if (!/\d+:\d\d/.test(texto)) return 'erro'; // pronto mostra a duração (31:50 · Deep Dive)
  const titulo = (await item.locator('.artifact-title').innerText()).trim();
  const pasta = path.join(BAIXADOS, slug);
  fs.rmSync(pasta, { recursive: true, force: true }); fs.mkdirSync(pasta, { recursive: true });
  const cdp = await b.newBrowserCDPSession();
  await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: pasta, eventsEnabled: true });
  const fim = new Promise((ok, falha) => {
    cdp.on('Browser.downloadProgress', e => { if (e.state === 'completed') ok(e.filePath); if (e.state === 'canceled') falha(new Error('download cancelado')); });
    setTimeout(() => falha(new Error('download não terminou em 5 min')), 5 * 60e3);
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
  atualizarRepo();
  const estado = lerEstado();
  const b = await conectar();
  const ctx = b.contexts()[0];
  const p = await ctx.newPage();
  let novos = 0, publicados = 0;
  try {
    // 1. começa os novos
    if (!soConferir) {
      for (const slug of pendentes(estado).slice(0, LOTE)) {
        try {
          const r = await gerar(p, slug);
          if (r === 'limite') { log('limite de uso do NotebookLM: paro de começar novos nesta rodada'); break; }
          estado[slug] = { status: 'gerando', notebook: r, pedido: new Date().toISOString(), tentativas: (estado[slug]?.tentativas || 0) + 1 };
          gravarEstado(estado); novos++; log(`gerando ${slug} → ${r}`);
        } catch (e) {
          if (/deslogado/.test(e.message)) throw e;
          estado[slug] = { status: 'erro', erro: e.message.split('\n')[0], tentativas: (estado[slug]?.tentativas || 0) + 1 };
          gravarEstado(estado); log(`ERRO ao gerar ${slug}: ${estado[slug].erro}`);
        }
      }
    }
    // 2. confere: 5 min, depois a cada 3 min, até 45 min
    const inicio = Date.now();
    for (let volta = 0; ; volta++) {
      const gerando = Object.entries(estado).filter(([, v]) => v.status === 'gerando');
      if (!gerando.length) break;
      if (volta === 0 && novos) await dorme(ESPERA_1);
      for (const [slug, v] of gerando) {
        try {
          const r = await conferir(b, p, slug, v.notebook);
          if (r === 'gerando') continue;
          if (r === 'erro') { Object.assign(v, { status: 'erro', erro: 'o NotebookLM não gerou o áudio' }); log(`ERRO ${slug}: áudio não gerado`); }
          else {
            try { publicar(slug, r.arquivo, r.titulo); }
            catch (e) { git('reset', '-q', '--hard'); git('clean', '-qfd', 'podcast', 'scripts'); throw e; } // desfaz o meio-publicado
            fs.rmSync(path.dirname(r.arquivo), { recursive: true, force: true }); // original já conferido no HD
            Object.assign(v, { status: 'publicado', titulo: r.titulo, em: new Date().toISOString() });
            publicados++; log(`pronto ${slug}: "${r.titulo}"`);
          }
        } catch (e) { log(`falha ao conferir ${slug}: ${e.message.split('\n')[0]}`); }
        gravarEstado(estado);
      }
      if (publicados && !TESTE) { git('push', '-q', 'origin', 'main'); log(`push: ${publicados} episódio(s) a caminho do site`); publicados = 0; }
      if (Date.now() - inicio > MAX_RODADA || !Object.values(estado).some(v => v.status === 'gerando')) break;
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
  const resto = pendentes(lerEstado()).length;
  log(`fim da rodada: ${resto} artigo(s) ainda sem episódio`);
}

main();
