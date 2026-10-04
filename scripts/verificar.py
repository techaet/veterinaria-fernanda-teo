#!/usr/bin/env python3
"""Verifica o site antes do deploy. Uso: python3 scripts/verificar.py
Sai com código 1 se houver erro (o deploy no GitHub Actions para)."""
import html, json, re, sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
DOMINIO = "https://veterinaria.aetsolidez.com.br"
CRMV = "CRMV/SC 5669"
WHATS = "5548999690448"
BAIRROS = ["Campeche", "Morro das Pedras", "Rio Tavares", "Lagoa da Conceição"]
# Textos de status editorial que já vazaram para o HTML público (ver regras-compliance.md §3)
VAZAMENTOS = re.compile(r"revisão obrigatória|pendente de aprovação|rascunho|aguardando aprovação|antes da publicação|antes de publicar", re.I)
MAX_IMG_KB = 400

erros = []

def erro(arquivo, msg):
    erros.append(f"{arquivo.relative_to(RAIZ)}: {msg}")

def texto_visivel(s):
    s = re.sub(r"<script.*?</script>|<style.*?</style>", " ", s, flags=re.S)
    return html.unescape(re.sub(r"<[^>]+>", " ", s))

paginas = [p for p in RAIZ.glob("**/*.html")
           if not any(x in p.parts for x in ("incorporacao-agenda-vet", ".git", "node_modules"))
           and not p.name.startswith("google")]
artigos = sorted(RAIZ.glob("blog/*/index.html"))
sitemap = (RAIZ / "sitemap.xml").read_text(encoding="utf-8")
feed = (RAIZ / "blog/feed.xml").read_text(encoding="utf-8")
indice_blog = (RAIZ / "blog/index.html").read_text(encoding="utf-8")

for p in paginas:
    s = p.read_text(encoding="utf-8")
    visivel = texto_visivel(s)
    if m := VAZAMENTOS.search(visivel):
        erro(p, f'texto de status editorial visível: "{m.group(0)}"')
    if "CRMV" in s and CRMV not in s:
        erro(p, f"CRMV fora do padrão (esperado {CRMV})")
    for num in re.findall(r"wa\.me/(\d+)", s):
        if num != WHATS:
            erro(p, f"link de WhatsApp com número diferente: {num}")
    # Lista de bairros: se cita um bairro do sul da ilha junto com outro, cita os quatro
    citados = [b for b in BAIRROS if b in visivel]
    if 1 < len(citados) < len(BAIRROS):
        erro(p, f"lista de bairros incompleta: {', '.join(citados)}")
    for bloco in re.findall(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
        try:
            dados = json.loads(bloco)
        except json.JSONDecodeError as e:
            erro(p, f"JSON-LD inválido: {e}")
            continue
        if dados.get("@type") == "FAQPage":
            for q in dados["mainEntity"]:
                for t in (q["name"], q["acceptedAnswer"]["text"]):
                    if " ".join(t.split()) not in " ".join(visivel.split()):
                        erro(p, f'FAQ do schema não bate com o texto visível: "{t[:60]}…"')

for a in artigos:
    s = a.read_text(encoding="utf-8")
    slug = a.parent.name
    url = f"{DOMINIO}/blog/{slug}/"
    if 'class="article-safety"' not in s:
        erro(a, "falta o aviso aside.article-safety")
    if f'href="{url}"' not in s:
        erro(a, f"canonical ausente ou diferente de {url}")
    if f"<loc>{url}</loc>" not in sitemap:
        erro(a, "artigo não está no sitemap.xml")
    if f"<link>{url}</link>" not in feed:
        erro(a, "artigo não está no blog/feed.xml")
    if f'href="{slug}/"' not in indice_blog:
        erro(a, "artigo sem card em blog/index.html")

for img in list(RAIZ.glob("images/**/*")) + list(RAIZ.glob("blog/assets/*")):
    if img.suffix.lower() in (".png", ".jpg", ".jpeg", ".webp") and img.stat().st_size > MAX_IMG_KB * 1024:
        erro(img, f"imagem com {img.stat().st_size // 1024} KB (máx. {MAX_IMG_KB} KB) — converta com cwebp -q 80 -resize 1600 0")

if erros:
    print(f"✗ {len(erros)} problema(s):")
    print("\n".join(f"  - {e}" for e in erros))
    sys.exit(1)
print(f"✓ {len(paginas)} páginas e {len(artigos)} artigos verificados, nenhum problema.")
