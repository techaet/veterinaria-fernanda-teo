# Painel da Dra. Fernanda Teo (`/painel/`)

Criado em 07/11/2026 a pedido do Leonardo, no molde das abas Site, Instagram e Anúncios do `/admin/` da Pousada Campeche Lofts
(`site-pousada-campeche-lofts/scripts/ESPECIFICACAO_PAINEL_ADMIN.md`). Responde, ao abrir: o site, o Instagram e os anúncios estão bem? O que tenho que fazer?

## Arquivos

| Arquivo | O quê |
|---|---|
| `painel/index.php` | Instalação, login, as abas Resumo · Site · Instagram · Anúncios · Ajustes e as ações (POST com CSRF) |
| `painel/lib.php` | Base: JSON com trava em `_dados/`, config, sessão, "lembrar neste aparelho", CSRF, limite de tentativas, período, layout e CSS |
| `painel/fontes.php` | Leitura de cada fonte e as regras de "o que fazer" (funções puras testadas) |
| `painel/api.php` | Porta dos robôs do GitHub (POST, header `X-Painel-Key` = `cron_token`): `velocidade`, `google`, `deploy` |
| `painel/.htaccess`, `painel/_dados/.htaccess` | Força https e www; nega `lib.php`, `fontes.php` e toda a pasta `_dados/` |
| `scripts/test_painel.php` | Teste (roda no `deploy.yml`, antes do FTP); pasta temporária, sem internet |
| `scripts/google_painel.py` | GA4 (propriedade 553040006) e Search Console (`https://www.fernandateo.vet.br/`) |
| `.github/workflows/painel.yml` | 1x por dia: velocidade (Lighthouse, 3 medidas, fica a do meio) às 6h e Google às 6h30 |

## Acesso

- 1ª vez: código de instalação (o mesmo do painel da pousada; o painel guarda só o sha256) e senha de 10+ caracteres.
- Senha com `password_hash`; 5 erros em 15 min por IP bloqueiam. "Lembrar neste aparelho" = cookie assinado (HMAC com o `segredo` da config) por 180 dias, só em `/painel/`. Trocar a senha sorteia outro segredo e desconecta os outros aparelhos.
- Nada de dado pessoal de cliente no painel: só contagens.

## Abas

- **Resumo**: um cartão por área (Site, Instagram, Anúncios) com a luz `tudo certo` / `atenção` / `problema` (o pior item da área), o número principal e o "O que fazer" das três áreas juntas, problemas primeiro.
- **Site** (não usa o período): no ar (código, tempo, validade do cadeado, lido a cada 30 min), velocidade celular/computador, visitas de 28 dias contra os 28 anteriores, páginas no Google (indexadas de quantas do sitemap, cliques e vezes na busca), último deploy, páginas mais vistas e de onde vêm as visitas (com barras), páginas fora do Google com o motivo.
- **Instagram**: seguidores (+ novos no período, somando o `follower_count` diário; o Instagram só dá 30 dias, então o painel guarda cada dia em `_dados/ig_seguidores.json` e mostra "desde" enquanto a história não cobre), alcance, visualizações e interações com ▲▼ contra o período anterior, engajamento (interações ÷ alcance) e os 6 últimos posts com o alcance de cada um. Lido na hora pela API do Instagram (login do Instagram), cache de 1 h por período.
- **Anúncios**: gasto, leads (ou conversas no WhatsApp, quando a campanha é de mensagem), custo por resultado (▲ é ruim), alcance e cliques, com ▲▼; campanhas no ar ou que gastaram no período, com orçamento. Mesmas regras da pousada: "no ar" só quando entrega (limite de gastos da conta atingido = "parada: limite da conta"; data de fim passada = "encerrada"), avisos de conta inativa e anúncio reprovado. Cache de 30 min.
- **Ajustes**: token do Instagram, conta de anúncios (padrão 10151653454237128), token dos anúncios, a chave dos robôs (para o secret `PAINEL_TOKEN`) e a troca de senha. Tokens nunca aparecem: só "termina em …xxxx".

Período (Resumo, Instagram, Anúncios): 1, 2, 4 (padrão), 8 ou 12 semanas com hoje, ou datas escolhidas; vai na URL e acompanha a troca de aba. A comparação é sempre com o mesmo tanto de dias logo antes.

## O que vira "atenção" ou "problema"

- Site: fora do ar (problema); cadeado vence em < 20 dias (atenção) ou < 7 (problema); nota no celular < 50; velocidade ou Google sem chegar há 3+ dias; páginas fora do Google; Search Console ou GA4 sem leitura; site sem deploy há 30+ dias.
- Instagram: sem token; leitura falhou; token vence em < 10 dias (< 3 = problema); último post há 7+ dias; alcance caiu 30%+.
- Anúncios: sem token; leitura falhou; conta inativa, limite de gastos atingido ou anúncio reprovado (problema); nenhuma campanha no ar; custo por resultado subiu 30%+.

## Tokens

- **Instagram**: token de login do Instagram (`IGAA…`), o mesmo tipo que o motor dos carrosséis usa (`META_TOKEN_FERNANDA_TEO`). O painel renova sozinho 1x por semana (`refresh_access_token`) e guarda a nova validade.
- **Anúncios**: token do Facebook com `ads_read` na conta `act_10151653454237128`. O token do Instagram é recusado pela Meta ("Cannot parse access token").
- **Google**: sem chave. O `painel.yml` usa a federação de identidade do GitHub e age como a conta de serviço `ga4-leitura`. Precisa: (1) o provedor `techaet` do pool `github` aceitar também `techaet/veterinaria-fernanda-teo` e a conta de serviço ter `roles/iam.workloadIdentityUser` para esse repositório; (2) a conta de serviço ser usuária (Restrita) do Search Console da propriedade `https://www.fernandateo.vet.br/`. Enquanto faltar, o painel mostra o motivo.

## Fora do escopo (por ora)

- Arrumar os blocos arrastando (a pousada tem); leads da planilha; Telegram e e-mail marketing (a Fernanda não tem canal nem Brevo).
- História de seguidores antes de 30 dias da instalação.
- Conjunto de anúncios pausado dentro de campanha ligada não é detectado (mesma limitação da pousada).
