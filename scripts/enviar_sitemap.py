#!/usr/bin/env python3
"""Reenvia o sitemap ao Google Search Console (Sitemaps API), logo após o deploy.

Env: GSC_SERVICE_ACCOUNT_JSON (conteúdo do JSON da conta de serviço, dono da propriedade)
     GSC_SITE (opcional; "sc-domain:fernandateo.vet.br" se a propriedade for de domínio)
Sem a credencial, sai sem erro (o site não depende disso).
"""
import json, os, sys, urllib.parse

cred = os.environ.get("GSC_SERVICE_ACCOUNT_JSON")
if not cred:
    print("GSC_SERVICE_ACCOUNT_JSON ausente: pulando reenvio do sitemap.")
    sys.exit(0)

from google.oauth2 import service_account
from google.auth.transport.requests import AuthorizedSession

site = os.environ.get("GSC_SITE") or "https://www.fernandateo.vet.br/"
sitemap = "https://www.fernandateo.vet.br/sitemap.xml"
creds = service_account.Credentials.from_service_account_info(
    json.loads(cred), scopes=["https://www.googleapis.com/auth/webmasters"])
url = ("https://www.googleapis.com/webmasters/v3/sites/%s/sitemaps/%s"
       % (urllib.parse.quote(site, safe=""), urllib.parse.quote(sitemap, safe="")))
r = AuthorizedSession(creds).put(url)
print(r.status_code, r.text)
sys.exit(0 if r.ok else 1)
