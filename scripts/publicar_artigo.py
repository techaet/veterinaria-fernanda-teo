#!/usr/bin/env python3
"""Registra um artigo já escrito em blog/index.html, blog/feed.xml, sitemap.xml e no
"Leia também" dos artigos que ele mesmo indica.
Uso: python3 scripts/publicar_artigo.py <slug>
Lê título, descrição, kicker e capa do próprio artigo e usa a data de hoje como data de
publicação. Rodar de novo para o mesmo slug não duplica nada."""
import html, re, sys
from datetime import date, datetime, timedelta, timezone
from email.utils import format_datetime
from pathlib import Path
from xml.sax.saxutils import escape

RAIZ = Path(__file__).resolve().parent.parent
BASE = "https://veterinaria.aetsolidez.com.br"


def campo(s, padrao):
    m = re.search(padrao, s, re.S)
    if not m:
        sys.exit(f"ERRO: não achei {padrao!r} no artigo")
    return html.unescape(re.sub(r"<[^>]+>", "", m.group(1))).strip()


def main(slug):
    artigo = RAIZ / "blog" / slug / "index.html"
    s = artigo.read_text(encoding="utf-8")
    hoje = date.today()
    s = re.sub(r'"(datePublished|dateModified)":\s*"[0-9-]+"', lambda m: f'"{m.group(1)}": "{hoje}"', s)
    artigo.write_text(s, encoding="utf-8")

    titulo = campo(s, r'property="og:title" content="([^"]+)"')
    descricao = campo(s, r'property="og:description" content="([^"]+)"')
    kicker = campo(s, r'class="article-meta"><span>(.*?)</span>')
    img = re.search(r'class="article-feature-image"><img ([^>]+)>', s).group(1)
    alt, w, h_ = (re.search(f'{a}="([^"]*)"', img).group(1) for a in ("alt", "width", "height"))
    url = f"{BASE}/blog/{slug}/"
    agora = format_datetime(datetime.now(timezone(timedelta(hours=-3))).replace(microsecond=0))
    h = lambda t: html.escape(t, quote=True)

    indice = RAIZ / "blog/index.html"
    t = indice.read_text(encoding="utf-8")
    if f'href="{slug}/"' not in t:
        card = (f'<article class="article-card"><div class="article-card-visual"><img src="assets/{slug}.webp" alt="{alt}" width="{w}" height="{h_}" loading="lazy" decoding="async"></div>'
                f'<div class="article-card-content"><p class="article-kicker">{h(kicker)}</p><h3>{h(titulo)}</h3><p>{h(descricao)}</p>'
                f'<a class="article-card-link" href="{slug}/">Ler conteúdo <span aria-hidden="true">→</span></a></div></article>\n        ')
        t = t.replace('<article class="article-card">', card + '<article class="article-card">', 1)
        indice.write_text(t, encoding="utf-8")

    feed = RAIZ / "blog/feed.xml"
    t = feed.read_text(encoding="utf-8")
    if f"<link>{url}</link>" not in t:
        item = (f"<item>\n      <title>{escape(titulo)}</title>\n      <link>{url}</link>\n"
                f'      <guid isPermaLink="true">{url}</guid>\n      <pubDate>{agora}</pubDate>\n'
                f"      <description>{escape(descricao)}</description>\n    </item>\n    ")
        t = t.replace("<item>", item + "<item>", 1)
        t = re.sub(r"<lastBuildDate>[^<]*</lastBuildDate>", f"<lastBuildDate>{agora}</lastBuildDate>", t, count=1)
        feed.write_text(t, encoding="utf-8")

    sitemap = RAIZ / "sitemap.xml"
    t = sitemap.read_text(encoding="utf-8")
    if f"<loc>{url}</loc>" not in t:
        t = t.replace("</urlset>", f"  <url>\n    <loc>{url}</loc>\n    <lastmod>{hoje}</lastmod>\n"
                                   f"    <changefreq>monthly</changefreq>\n    <priority>0.7</priority>\n  </url>\n</urlset>")
        sitemap.write_text(t, encoding="utf-8")

    # "Leia também" recíproco: o novo artigo entra na lista dos artigos que ele indica
    relacionados = re.search(r'class="article-related">(.*?)</section>', s, re.S).group(1)
    for rel in re.findall(r'href="\.\./([a-z0-9-]+)/"', relacionados):
        p = RAIZ / "blog" / rel / "index.html"
        if not p.exists():
            continue
        t = p.read_text(encoding="utf-8")
        fim = '</ul></section><section class="article-cta">'
        if f'href="../{slug}/"' not in t and fim in t:
            p.write_text(t.replace(fim, f'<li><a href="../{slug}/">{h(titulo)}</a></li>' + fim, 1), encoding="utf-8")
    print(f"Registrado: {url}")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1].strip("/"))
