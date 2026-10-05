#!/usr/bin/env python3
"""Mantém o que se repete no site a partir de uma fonte só (scripts/site.json). Idempotente.
Uso: python3 scripts/sincronizar.py   (publicar_artigo.py já chama)
Faz: menu em todas as páginas; <head> padrão; em cada artigo, tema, byline, caixa da autora,
schema de autoria e marcação do Pagefind; no blog, chips de tema, "Comece por aqui" e data-tema
nos cards; na home, o bloco "Últimos artigos"."""
import html, json, re, sys
from datetime import date
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
CFG = json.loads((RAIZ / "scripts/site.json").read_text(encoding="utf-8"))
AUT = CFG["autora"]
TEMAS = {t["nome"]: t["slug"] for t in CFG["temas"]}
NOME_TEMA = {v: k for k, v in TEMAS.items()}
MESES = "janeiro fevereiro março abril maio junho julho agosto setembro outubro novembro dezembro".split()

h = lambda t: html.escape(t, quote=True)
ler = lambda p: p.read_text(encoding="utf-8")


def gravar(p, s):
    if ler(p) != s:
        p.write_text(s, encoding="utf-8")


def data_br(iso):
    d = date.fromisoformat(iso)
    return f"{d.day} de {MESES[d.month - 1]} de {d.year}"


def trocar_bloco(s, marca, conteudo):
    """Substitui o que está entre <!--marca--> e <!--/marca-->."""
    return re.sub(rf"<!--{marca}-->.*?<!--/{marca}-->", lambda _: f"<!--{marca}-->{conteudo}<!--/{marca}-->", s, count=1, flags=re.S)


# ---------- menu e <head> (todas as páginas públicas) ----------
NAV = ler(RAIZ / "scripts/nav.html").strip()  # o menu de TODAS as páginas; edite o arquivo, não as páginas
NAV_BUSCA = """  <pagefind-modal></pagefind-modal>
"""
HEAD = [  # (trecho que prova que já existe, linha a inserir) — só em páginas com og:title
    ('property="og:site_name"', f'<meta property="og:site_name" content="{h(CFG["marca"])}">'),
    ('name="twitter:card"', '<meta name="twitter:card" content="summary_large_image">'),
    ('type="application/rss+xml"', f'<link rel="alternate" type="application/rss+xml" title="{h(CFG["feed_titulo"])}" href="/blog/feed.xml">'),
]
HEAD_BUSCA = [  # só em páginas com menu
    ("pagefind-component-ui.css", '<link href="/pagefind/pagefind-component-ui.css" rel="stylesheet">'),
    ("pagefind-component-ui.js", '<script src="/pagefind/pagefind-component-ui.js" type="module"></script>'),
]


FONTES = """<link rel="preload" href="/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/fonts/poppins-800.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/fonts/fonts.css">"""


def paginas_publicas():
    return [p for p in RAIZ.glob("**/*.html")
            if not any(x in p.parts for x in ("incorporacao-agenda-vet", ".git", "node_modules", "pagefind"))
            and not p.name.startswith("google")]


def pagina(p):
    s = ler(p)
    if '<nav class="navbar"' in s:
        s = re.sub(r'<nav class="navbar".*?</nav>', lambda _: NAV, s, count=1, flags=re.S)
        if "<pagefind-modal>" not in s:
            s = s.replace("<main", NAV_BUSCA + "  <main", 1)
        for prova, linha in HEAD_BUSCA:
            if prova not in s:
                s = s.replace("</head>", f"  {linha}\n</head>", 1)
    if 'property="og:title"' in s:
        for prova, linha in HEAD:
            if prova not in s:
                s = s.replace("</head>", f"  {linha}\n</head>", 1)
    s = re.sub(r'\n[ \t]*<meta name="keywords"[^>]*>', "", s)  # o Google ignora
    # fontes hospedadas no lugar do Google Fonts (sem CSS bloqueante de terceiros)
    if (RAIZ / "fonts/fonts.css").exists():
        s = re.sub(r'[ \t]*<link rel="preconnect" href="https://fonts\.g[^>]*>\n', "", s)
    if (RAIZ / "fonts/fonts.css").exists():
        s = re.sub(r'<link href="https://fonts\.googleapis\.com/css2[^>]*>', lambda _: FONTES, s)
    gravar(p, s)


# ---------- artigos ----------
def autor_ld():
    return {"@type": "Person", "name": AUT["nome"], "jobTitle": AUT["funcao"], "identifier": AUT["registro"],
            "url": CFG["dominio"] + AUT["pagina"], "sameAs": AUT["sameAs"]}


def byline(pub, mod):
    datas = f'Publicado em <time datetime="{pub}">{data_br(pub)}</time>'
    if mod != pub:
        datas += f' · Atualizado em <time datetime="{mod}">{data_br(mod)}</time>'
    quem = CFG["byline_html"].format(nome=h(AUT["nome"]), registro=h(AUT["registro"]), pagina=AUT["pagina"])
    return f'<p class="article-byline" data-pagefind-ignore><span>{quem}</span><span class="article-datas">{datas}</span></p>'


CAIXA = (f'<aside class="article-author" data-pagefind-ignore><img src="{AUT["foto"]}" alt="{h(AUT["nome"])}" '
         f'width="{AUT["foto_w"]}" height="{AUT["foto_h"]}" loading="lazy" decoding="async"><div>'
         f'<p class="article-author-titulo">{h(AUT["caixa_titulo"])}</p><p><strong>{h(AUT["nome"])}</strong> · {h(AUT["registro"])}</p>'
         f'<p>{h(AUT["resumo"])}</p><a href="{AUT["pagina"]}">{h(AUT["caixa_link"])}</a></div></aside>')


def artigo(p):
    s, slug = ler(p), p.parent.name
    m = re.search(r'<div class="article-meta">(?:<span>|<a class="article-tema"[^>]*>)(.*?)</(?:span|a)>', s)
    nome = html.unescape(m.group(1)) if m else ""
    if nome not in TEMAS:
        sys.exit(f"ERRO {slug}: o primeiro item de article-meta deve ser um tema de scripts/site.json "
                 f"({' | '.join(TEMAS)}), veio {nome!r}")
    tema = (f'<a class="article-tema" href="/blog/?tema={TEMAS[nome]}" data-pagefind-filter="Tema">{h(nome)}</a>')
    s = re.sub(r'(<div class="article-meta">)(?:<span>.*?</span>|<a class="article-tema".*?</a>)', lambda x: x.group(1) + tema, s, count=1)

    # schema Article: datePublished obrigatório, autor com url e sameAs
    def ld(x):
        d = json.loads(x.group(2))
        if d.get("@type") != "Article":
            return x.group(0)
        mod = d.get("dateModified") or str(date.today())
        novo = {}
        for k, v in d.items():
            if k == "dateModified":
                novo["datePublished"] = d.get("datePublished") or mod
            if k not in ("datePublished", "author"):
                novo[k] = v
            if k == "author":
                novo["author"] = autor_ld()
        novo.setdefault("datePublished", mod)
        return x.group(1) + json.dumps(novo, ensure_ascii=False) + x.group(3)
    s = re.sub(r'(<script type="application/ld\+json">)(.*?)(</script>)', ld, s, flags=re.S)
    pub, mod = (re.search(f'"{k}": "([0-9-]+)"', s).group(1) for k in ("datePublished", "dateModified"))

    by = byline(pub, mod)
    s = re.sub(r'<p class="article-byline".*?</p>', lambda _: by, s, count=1, flags=re.S) if 'class="article-byline"' in s \
        else s.replace("</h1>", "</h1>" + by, 1)
    s = re.sub(r'<aside class="article-author".*?</aside>', lambda _: CAIXA, s, count=1, flags=re.S) if 'class="article-author"' in s \
        else s.replace("</article>", CAIXA + "</article>", 1)

    # Pagefind: indexa só o artigo; ignora o que não ajuda em trecho de busca
    s = s.replace('<article class="article-shell">', '<article class="article-shell" data-pagefind-body>', 1)
    for velho, novo in (('<nav class="article-toc"', '<nav class="article-toc" data-pagefind-ignore'),
                        ('<section class="article-related"', '<section class="article-related" data-pagefind-ignore'),
                        ):
        if novo not in s:
            s = s.replace(velho, novo, 1)
    s = s.replace(' data-pagefind-meta="image[src]"', "")
    meta = f'<meta data-pagefind-meta="image[content]" content="/blog/assets/{slug}.webp">'
    if meta not in s:
        s = s.replace("data-pagefind-body>", "data-pagefind-body>" + meta, 1)
    gravar(p, s)
    return slug, nome


# ---------- blog e home ----------
def chips(total, contagem):
    itens = [f'<a class="tema-chip" href="/blog/" data-tema="" aria-current="true">Todos <span>{total}</span></a>']
    itens += [f'<a class="tema-chip" href="/blog/?tema={t["slug"]}" data-tema="{t["slug"]}">{h(t["nome"])} <span>{contagem.get(t["slug"], 0)}</span></a>'
              for t in CFG["temas"] if contagem.get(t["slug"])]
    return '<nav class="tema-chips" aria-label="Filtrar por tema">' + "".join(itens) + "</nav>"


def blog_e_home(temas_por_slug):
    contagem = {}
    for nome in temas_por_slug.values():
        contagem[TEMAS[nome]] = contagem.get(TEMAS[nome], 0) + 1
    total = len(temas_por_slug)

    indice = RAIZ / "blog/index.html"
    t = ler(indice)

    def card(x):
        slug = re.search(r'href="([a-z0-9-]+)/"', x.group(0)).group(1)
        nome = temas_por_slug[slug]
        c = re.sub(r'<article class="article-card"[^>]*>', f'<article class="article-card" data-tema="{TEMAS[nome]}">', x.group(0), count=1)
        return re.sub(r'(<p class="article-kicker">).*?(</p>)', lambda y: y.group(1) + h(nome) + y.group(2), c, count=1)
    t = re.sub(r'<article class="article-card".*?</article>', card, t, flags=re.S)
    cartoes = {re.search(r'href="([a-z0-9-]+)/"', c).group(1): c for c in re.findall(r'<article class="article-card".*?</article>', t, re.S)}

    t = trocar_bloco(t, "chips", chips(total, contagem))
    destaques = "".join(cartoes[s] for s in CFG["comece_por_aqui"])
    t = trocar_bloco(t, "destaques", f'<section class="blog-destaques" aria-labelledby="comece"><p class="blog-section-label" id="comece">Comece por aqui</p>'
                                     f'<div class="article-grid article-grid--3">{destaques}</div></section>')
    gravar(indice, t)

    recentes = list(cartoes.values())[:3]
    para_home = "".join(re.sub(r'(src|href)="(assets/|[a-z0-9-]+/")', r'\1="blog/\2', c) for c in recentes)
    home = RAIZ / "index.html"
    s = ler(home)
    bloco = ('<section id="artigos" class="home-artigos" aria-label="Artigos recentes"><div class="container">'
             '<div class="blog-intro"><div><p class="blog-section-label">' + h(CFG["home"]["rotulo"]) + '</p>'
             '<h2>' + h(CFG["home"]["titulo"]) + '</h2></div><p>' + h(CFG["home"]["texto"]) + '</p></div>'
             + chips(total, contagem).replace('aria-current="true"', "")
             + f'<div class="article-grid article-grid--3">{para_home}</div>'
             '<p class="home-artigos-link"><a class="btn-secundario" href="/blog/">Ver todos os artigos</a></p></div></section>')
    gravar(home, trocar_bloco(s, "artigos-home", bloco))


def main():
    for p in paginas_publicas():
        pagina(p)
    temas = dict(artigo(p) for p in sorted((RAIZ / "blog").glob("*/index.html")))
    blog_e_home(temas)
    print(f"Sincronizado: {len(temas)} artigos.")


if __name__ == "__main__":
    main()
