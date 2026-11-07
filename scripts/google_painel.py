#!/usr/bin/env python3
"""Visitas (GA4) e páginas no Google (Search Console) para a aba Site do painel da Fernanda. Roda 1x por dia no GitHub (painel.yml),
com o acesso da conta de serviço ga4-leitura (sem chave: federação de identidade do GitHub), e manda o JSON a
painel/api.php?acao=google. Local: GOOGLE_TOKEN=$(gcloud auth print-access-token --impersonate-service-account=…
--scopes=https://www.googleapis.com/auth/analytics.readonly,https://www.googleapis.com/auth/webmasters.readonly)
python3 scripts/google_painel.py --ver
"""
import json, os, re, sys, urllib.error, urllib.parse, urllib.request
from datetime import date, timedelta

PROPRIEDADE = "553040006"  # GA4 "Veterinária Fernanda Teo" (conta Tech AET Solidez)
SITE = "https://www.fernandateo.vet.br/"  # propriedade do Search Console (prefixo de URL)
TOKEN = os.environ.get("GOOGLE_TOKEN", "")


def api(url, corpo=None):
    req = urllib.request.Request(url, data=None if corpo is None else json.dumps(corpo).encode(), method="GET" if corpo is None else "POST",
                                 headers={"Authorization": "Bearer " + TOKEN, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        msg = json.loads(e.read() or b"{}").get("error", {}).get("message", "")
        raise RuntimeError(f"HTTP {e.code}: {msg[:160]}") from None


def relatorio(dims, metricas, datas, limite=None):
    corpo = {"dimensions": [{"name": d} for d in dims], "metrics": [{"name": m} for m in metricas], "dateRanges": datas}
    if limite:
        corpo |= {"limit": limite, "orderBys": [{"metric": {"metricName": metricas[0]}, "desc": True}]}
    return api(f"https://analyticsdata.googleapis.com/v1beta/properties/{PROPRIEDADE}:runReport", corpo).get("rows", [])


def ga4():
    d28 = {"startDate": "28daysAgo", "endDate": "yesterday"}
    tot = {r["dimensionValues"][0]["value"]: [int(m["value"]) for m in r["metricValues"]]
           for r in relatorio([], ["sessions", "activeUsers"], [d28, {"startDate": "56daysAgo", "endDate": "29daysAgo"}])}
    agora, antes = tot.get("date_range_0", [0, 0]), tot.get("date_range_1", [0, 0])
    paginas = [{"caminho": r["dimensionValues"][0]["value"], "titulo": re.sub(r"\s*[|–—-]\s*Dra\.? Fernanda Teo.*$", "", r["dimensionValues"][1]["value"]),
                "vistas": int(r["metricValues"][0]["value"])} for r in relatorio(["pagePath", "pageTitle"], ["screenPageViews"], [d28], 5)]
    origens = [{"nome": r["dimensionValues"][0]["value"], "sessoes": int(r["metricValues"][0]["value"])}
               for r in relatorio(["sessionDefaultChannelGroup"], ["sessions"], [d28], 6)]
    return {"visitas": agora[0], "visitas_antes": antes[0], "usuarios": agora[1], "paginas": paginas, "origens": origens}


def search_console():
    fim = date.today() - timedelta(days=3)  # o Search Console atrasa uns 2 a 3 dias
    q = api(f"https://www.googleapis.com/webmasters/v3/sites/{urllib.parse.quote(SITE, safe='')}/searchAnalytics/query",
            {"startDate": str(fim - timedelta(days=27)), "endDate": str(fim)})
    linha = (q.get("rows") or [{}])[0]
    # o servidor (mod_security da HostGator) responde 406 ao "Python-urllib" padrão
    with urllib.request.urlopen(urllib.request.Request(SITE + "sitemap.xml", headers={"User-Agent": "painel-fernandateo/1.0"}), timeout=30) as r:
        urls = re.findall(r"<loc>([^<]+)</loc>", r.read().decode())
    fora = []
    for u in urls:
        res = api("https://searchconsole.googleapis.com/v1/urlInspection/index:inspect", {"inspectionUrl": u, "siteUrl": SITE})
        st = res.get("inspectionResult", {}).get("indexStatusResult", {})
        if st.get("verdict") != "PASS":
            fora.append({"url": u, "estado": st.get("coverageState", "") or st.get("verdict", "")})
    return {"cliques": int(linha.get("clicks", 0)), "impressoes": int(linha.get("impressions", 0)), "total": len(urls),
            "indexadas": len(urls) - len(fora), "fora": fora}


if __name__ == "__main__":
    dados = {}
    for chave, f in (("ga", ga4), ("sc", search_console)):
        try:
            dados[chave] = f()
        except Exception as e:  # uma fonte fora do ar não impede a outra
            dados[chave] = {"erro": str(e)[:200]}
    print(json.dumps(dados, ensure_ascii=False, indent=None if "--ver" not in sys.argv else 1))
