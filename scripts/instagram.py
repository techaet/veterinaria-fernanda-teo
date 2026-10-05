#!/usr/bin/env python3
"""Mantém na home os 2 posts mais recentes do Instagram (embeds oficiais).
Uso: python3 scripts/instagram.py            (precisa de INSTAGRAM_TOKEN no ambiente)
     python3 scripts/instagram.py URL1 URL2  (sem API: usa os links dados — para teste ou uso manual)
Variáveis: INSTAGRAM_TOKEN (obrigatória na API) e INSTAGRAM_USER_ID (só se o token for de login do Facebook;
sem ela usa graph.instagram.com/me). O token NUNCA é impresso. Sai com erro se a API falhar ou se o token vencer em
menos de 10 dias (o GitHub avisa por e-mail quando o workflow falha). Idempotente: sem post novo, não muda nada."""
import json, os, re, sys, urllib.error, urllib.parse, urllib.request
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
HOME = RAIZ / "index.html"
QTD = 2
ESTILO = ("background:#FFF; border:0; border-radius:3px; box-shadow:0 0 1px 0 rgba(0,0,0,0.5),0 1px 10px 0 rgba(0,0,0,0.15); "
          "margin: 1px; max-width:540px; min-width:326px; padding:0; width:99.375%; width:-webkit-calc(100% - 2px); width:calc(100% - 2px);")
PERMALINK = re.compile(r"^https://www\.instagram\.com/(p|reel|reels|tv)/[A-Za-z0-9_-]+/?$")


def get(url, params, fatal=True):
    """GET JSON. Falha sem expor a URL (que leva o token). Com fatal=False devolve None em vez de sair."""
    try:
        with urllib.request.urlopen(f"{url}?{urllib.parse.urlencode(params)}", timeout=30) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        corpo = e.read().decode("utf-8", "replace")
        msg = (json.loads(corpo).get("error", {}).get("message") if corpo.startswith("{") else "") or e.reason
        if not fatal:
            return None
        sys.exit(f"ERRO: a API do Instagram respondeu {e.code}: {msg} (token vencido ou sem permissão? gere outro e atualize o secret INSTAGRAM_TOKEN)")
    except urllib.error.URLError as e:
        if not fatal:
            return None
        sys.exit(f"ERRO de rede ao falar com o Instagram: {e.reason}")


def buscar_links(token, user_id):
    if user_id:  # token do login do Facebook
        d = get(f"https://graph.facebook.com/v21.0/{user_id}/media", {"fields": "permalink", "limit": 10, "access_token": token})
    else:        # token do login do Instagram (long-lived)
        d = get("https://graph.instagram.com/me/media", {"fields": "permalink", "limit": 10, "access_token": token})
        # renova o prazo de 60 dias (a API recusa nas primeiras 24 h do token: aí só segue)
        r = get("https://graph.instagram.com/refresh_access_token", {"grant_type": "ig_refresh_token", "access_token": token}, fatal=False)
        if r and "expires_in" in r:
            dias = int(r["expires_in"]) // 86400
            print(f"Token do Instagram renovado: válido por mais {dias} dias.")
            if dias < 10:
                sys.exit("ERRO: o token do Instagram vence em menos de 10 dias; gere um novo e atualize o secret INSTAGRAM_TOKEN.")
        else:
            print("Aviso: não consegui renovar o token agora (normal se ele tem menos de 24 h).")
    links = [m["permalink"] for m in d.get("data", []) if PERMALINK.match(m.get("permalink", ""))]
    if len(links) < QTD:
        sys.exit(f"ERRO: a API devolveu {len(links)} post(s) válidos; esperava pelo menos {QTD}.")
    return links[:QTD]


def bloco(links):
    posts = "".join(
        f'\n            <div>\n              <blockquote class="instagram-media" data-instgrm-captioned '
        f'data-instgrm-permalink="{l.rstrip("/")}/?utm_source=ig_embed&amp;utm_campaign=loading" data-instgrm-version="14" style="{ESTILO}">'
        f'<a href="{l}" target="_blank" rel="noopener noreferrer">Ver no Instagram</a></blockquote>\n            </div>\n'
        for l in links)
    return posts + '            <script async src="//www.instagram.com/embed.js"></script>\n          '


def main(argv):
    if argv:
        links = [a for a in argv if PERMALINK.match(a)]
        if len(links) != len(argv) or len(links) < QTD:
            sys.exit(f"Informe {QTD} links de posts (https://www.instagram.com/p|reel/<código>/).")
        links = links[:QTD]
    else:
        token = os.environ.get("INSTAGRAM_TOKEN", "").strip()
        if not token:
            sys.exit("ERRO: defina INSTAGRAM_TOKEN (secret do GitHub) ou passe os links como argumento.")
        links = buscar_links(token, os.environ.get("INSTAGRAM_USER_ID", "").strip())
    s = HOME.read_text(encoding="utf-8")
    novo, n = re.subn(r"<!--instagram-posts-->.*?<!--/instagram-posts-->", lambda _: f"<!--instagram-posts-->{bloco(links)}<!--/instagram-posts-->", s, count=1, flags=re.S)
    if n != 1:
        sys.exit("ERRO: marcadores <!--instagram-posts--> não encontrados em index.html.")
    if novo == s:
        print("Instagram: já está com os 2 posts mais recentes.")
        return
    HOME.write_text(novo, encoding="utf-8")
    print("Instagram: home atualizada com\n  " + "\n  ".join(links))


if __name__ == "__main__":
    main(sys.argv[1:])
