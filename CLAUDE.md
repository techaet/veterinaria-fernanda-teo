# Site Dra. Fernanda Teo — Veterinária Domiciliar

Site estático (HTML/CSS/JS puro, sem build) em `https://www.fernandateo.vet.br`. Marca administrada pela Tech AET Solidez. Escreva tudo em português do Brasil.

## Perguntas ao usuário

Sempre que precisar perguntar algo ao usuário, use o questionário no chat (ferramenta `AskUserQuestion`), com opções clicáveis. Não faça perguntas soltas no texto da resposta.

## Antes de qualquer mudança

- **Regras de conteúdo e dados fixos do negócio: [regras-compliance.md](regras-compliance.md).** Leia antes de criar/editar qualquer texto público. Se um dado fixo mudar (bairro, preço, telefone), atualize esse arquivo primeiro e depois propague.
- **Rode `python3 scripts/verificar.py` antes de todo commit.** O mesmo script roda no deploy e bloqueia a publicação se falhar. Ele confere: sitemap, feed, card no blog, canonical, aviso educativo, CRMV, WhatsApp, lista de bairros, FAQ do schema x texto visível, vazamento de texto de status editorial e tamanho de imagem.

## Deploy

Push na `main` → GitHub Actions (`.github/workflows/deploy.yml`) → verificação → FTP para o cPanel. Não existe ambiente de homologação: trabalhe em branch, abra PR, faça merge.
Arquivos internos (este, `regras-compliance.md`, `scripts/`, `.claude/`, `incorporacao-agenda-vet/`) estão no `exclude` do deploy. **Arquivo interno novo na raiz? Adicione ao `exclude`**, senão ele vira público.

## Estrutura

| Caminho | O quê |
|---|---|
| `index.html`, `style.css`, `script.js` | Home + estilos/JS globais (usados por todas as páginas) |
| `agendar/` | Agenda online via iframe (`vetplatform-alf9ll8o.manus.space`). Sem botão flutuante de WhatsApp nesta página |
| `blog/index.html` | Lista de artigos (cards, do mais novo para o mais antigo) |
| `blog/<slug>/index.html` | Um artigo por pasta |
| `blog/assets/<slug>.webp` | Imagem de capa do artigo (também é o og:image) |
| `blog/blog.css`, `blog/blog.js`, `blog/feed.xml` | Estilos do blog, filtro por tema e RSS |
| `dra-fernanda-teo/` | Página da autora (E-E-A-T): credenciais, linkada na byline de todo artigo |
| `scripts/site.json` | Fonte única: marca, autora, **temas** do blog e artigos de "Comece por aqui" |
| `scripts/nav.html` | Menu de todas as páginas (o `sincronizar.py` o espalha) |
| `scripts/sincronizar.py` | Gera o que se repete: menu, `<head>` padrão, tema/byline/caixa da autora nos artigos, chips e cards do blog, "Últimos artigos" da home. **Não edite à mão** esses trechos |
| `scripts/instagram.py` + `.github/workflows/instagram-sync.yml` | Todo dia 08h (Brasília) busca os 2 posts mais recentes na API oficial do Instagram e reescreve o bloco entre `<!--instagram-posts-->` da home; só publica se mudou. Secrets do GitHub: `INSTAGRAM_TOKEN` (e `INSTAGRAM_USER_ID` só se o token for do login do Facebook). O token vence em 60 dias, a rotina o renova e falha avisando quando faltam <10 dias. Manual: `python3 scripts/instagram.py <link1> <link2>` |
| `scripts/telegram-worker/` | Worker da Cloudflare + passo a passo da aprovação pelo Telegram (interno, no `exclude` do deploy) |
| `pagefind/` | Índice de busca gerado no deploy (não versionado). Busca em `<pagefind-modal-trigger>` no menu |
| `guia-cuidados-pele-pet.html` | Guia/isca digital (HTML autocontido) |
| `privacidade.html`, `404.html` | Política de privacidade e página de erro |
| `sitemap.xml`, `robots.txt` | SEO |

Analytics: GA4 `G-V1JL02NC6J` — todo HTML novo leva o snippet `gtag` no `<head>` (copie de um artigo existente).

## Novo artigo do blog

1. **Texto:** use a skill `criar-artigo-blog` (ela lê a identidade da marca na pasta da Dra. Fernanda Teo em "Negócios AET" no Google Drive). Respeite `regras-compliance.md` §2.
2. **HTML:** copie um artigo existente como molde (ex. `blog/checkup-veterinario-frequencia-ideal/index.html`) para `blog/<slug>/index.html`. Mantenha a ordem: breadcrumb → `article-meta` → h1 → imagem → `article-lead` → `aside.article-safety` → sumário → corpo → FAQ → "Leia também" → CTA de agendamento (`/agendar/`) → Referências.
3. **Tema:** o 1º `<span>` de `article-meta` é um tema de `scripts/site.json` (o 2º é o subtópico livre). Sem tema válido, o `sincronizar.py` recusa.
3. **No `<head>`:** title, description, canonical, og:*, e os 3 JSON-LD (`Article` com `dateModified`, `BreadcrumbList`, `FAQPage`). FAQ do schema = texto visível, palavra por palavra.
4. **Imagem:** `blog/assets/<slug>.webp`, 1600px de largura, ≤ 400 KB. Conversão: `python3 -c "from PIL import Image; im=Image.open('in.png').convert('RGB'); im.thumbnail((1600,1600)); im.save('blog/assets/<slug>.webp','WEBP',quality=80,method=6)"` (o `cwebp` desta máquina está quebrado). `width`/`height` no `<img>` = dimensões reais.
5. **Registrar em 3 lugares:** card no topo de `blog/index.html` (com `loading="lazy"`), `<item>` no topo de `blog/feed.xml` (+ atualizar `lastBuildDate`), `<url>` em `sitemap.xml`.
6. **Links internos:** adicione o novo artigo no "Leia também" de 1–3 artigos relacionados.
7. `python3 scripts/sincronizar.py && python3 scripts/verificar.py` → commit → PR. (`publicar_artigo.py` já chama o sincronizar.)

**Aprovação clínica:** todo artigo de saúde precisa do OK da Dra. Fernanda antes de ir para a `main`. Status de revisão fica na conversa/issue, **nunca** no HTML (já vazou antes).

### Aprovação pelo Telegram

- Rascunho = branch `artigo/<slug>` com **só** `blog/<slug>/` + `blog/assets/<slug>.webp` (sem mexer em `blog/index.html`, `feed.xml`, `sitemap.xml`). Pontos de atenção clínica vão no corpo da mensagem do commit.
- Validar antes do push: `python3 scripts/publicar_artigo.py <slug> && python3 scripts/verificar.py`, depois `git checkout -- blog sitemap.xml` (o script também edita o "Leia também" de outros artigos).
- Corte a branch da `main` **atual**: o workflow que roda é o da própria branch.
- Push em `artigo/**` → `artigo-revisao.yml` abre issue (label `artigo`, sem e-mail) e manda mensagem no Telegram (bot `@art_dra_fernanda_bot`) para Leonardo e Dra. Fernanda, com prévia (raw.githack), pontos clínicos e botões **Publicar**/**Excluir**.
- O toque chega à Worker da Cloudflare (`scripts/telegram-worker/`), que comenta `PUBLICAR`/`EXCLUIR` na issue com o token da conta techaet → `artigo-decisao.yml`: merge + `publicar_artigo.py` (card, feed, sitemap, "Leia também" recíproco, datas = hoje) + `verificar.py` + push na main + `deploy.yml`. Comentar direto na issue também funciona.
- Toque uma vez só (o toque duplo gera dois comandos; o segundo falha sem dano). Só os IDs em `ALLOWED_IDS` da Worker aprovam.
- Reenviar o aviso de uma branch já existente: fechar a issue aberta, dar merge da `main` na branch (com os pontos clínicos no corpo do commit) e push.
- Setup, secrets e cuidados: `scripts/telegram-worker/LEIA-ME.md`. Não use o bot da agenda (já tem webhook no Manus). O token `GITHUB_TOKEN` da Worker não expira; se for revogado, os botões param sem aviso.

## Podcast (áudio do NotebookLM)

**Automático:** o robô em `scripts/podcast-robo/` (launchd a cada 3 h) gera, baixa e publica o episódio de todo artigo sem podcast, direto na `main` e sem revisão. Detalhes e o que fazer se o Google deslogar: `scripts/podcast-robo/LEIA-ME.md`.

**Trecho sob o player:** `python3 scripts/trecho_podcast.py [slug]` (sem slug: todos sem trecho). Transcreve um pedaço do original no HD e grava `trecho` em `site.json`; sem trecho, aparece o `podcast_aviso`.

**À mão:** `python3 scripts/adicionar_podcast.py <slug> <arquivo.m4a> "<título>"` (original no HD com SHA-256, MP3 64 kbps em `podcast/audio/`, entrada no topo de `"podcasts"` em `scripts/site.json`, sincronizar) → `verificar.py` → commit. O `sincronizar.py` põe o player abaixo da capa do artigo, troca o destaque da home pelo mais novo e lista em `/podcast/`.

## Novo guia (isca digital)

Use a skill `criar-guia-html`. Salve como `guia-<tema>.html` na raiz, com o snippet GA4, canonical, CRMV e aviso educativo. Adicione ao `sitemap.xml` se o guia for público (se for só para quem deixou contato, use `<meta name="robots" content="noindex">` e não coloque no sitemap).

## Convenções

- Slugs em minúsculas, sem acento, com hífens. URL do artigo termina em `/`.
- Imagens sempre WebP em `blog/assets/` ou `images/`. Só a imagem acima da dobra leva `fetchpriority="high"`; o resto `loading="lazy"`.
- Links externos: `target="_blank" rel="noopener noreferrer"`.
- Todo convite a agendar (CTA de artigo, do site, de isca/guia) → `/agendar/`, nunca WhatsApp (regra de 17/10/2026, `regras-compliance.md` §1). CTA de artigo: `<a class="btn-whatsapp btn-online" href="/agendar/">Agendar consulta</a><p class="agendar-alt">Dúvidas? <a href="https://wa.me/5548999690448" ...>Fale no WhatsApp</a></p>`. WhatsApp só para dúvidas e pós-consulta.
- Pasta `incorporacao-agenda-vet/` é material de referência local, não versionar.

## SEO / lançamento / deploy técnico

Para Search Console, Bing, favicon, og:image, checklist de lançamento: skill `site-seo-deploy-identidade`.
