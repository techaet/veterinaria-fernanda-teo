# Aprovação de artigos pelo Telegram

Fluxo: push em `artigo/**` → `artigo-revisao.yml` abre a issue **e** manda mensagem no Telegram com botões Publicar/Excluir → toque chega no Worker (`worker.js`) → Worker comenta PUBLICAR/EXCLUIR na issue → `artigo-decisao.yml` roda como sempre. O e-mail continua funcionando.

## Configuração (uma vez)
1. **Crie um bot novo só para aprovações** (@BotFather → `/newbot`) e guarde o token. **Não use o bot da agenda** (webhook em `vetplatform-alf9ll8o.manus.space`): um bot só tem um webhook, e apontá-lo para a Worker derrubaria a agenda.
2. Cada aprovador (Dra. Fernanda e Leonardo) abre o bot e manda `/start`. Descubra o ID de cada um (ex. @userinfobot).
3. Crie a Worker na Cloudflare com o código de `worker.js` e os secrets: `TELEGRAM_TOKEN`, `WEBHOOK_SECRET` (texto aleatório), `GITHUB_TOKEN` (PAT da conta LSFcamp com permissão de issues no repositório), `ALLOWED_IDS` (IDs separados por vírgula), `REPO` (`techaet/veterinaria-fernanda-teo`).
4. Aponte o bot para a Worker:
   `curl "https://api.telegram.org/bot<TOKEN>/setWebhook" -d url=<URL da Worker> -d secret_token=<WEBHOOK_SECRET>`
5. Secrets do GitHub (Settings → Secrets → Actions): `TELEGRAM_TOKEN` e `TELEGRAM_CHAT_IDS` (os mesmos IDs, separados por vírgula).

Sem os secrets do GitHub, o passo do Telegram é pulado e tudo segue só por e-mail.

## Estado atual (configurado em 12/10/2026)
- Bot: `@art_dra_fernanda_bot`. Worker: `https://aprovacao-artigos.tech-aetsolidez.workers.dev/`.
- `GITHUB_TOKEN` da Worker: PAT fine-grained da conta techaet, só este repositório, Issues read/write, sem expiração.
- Aprovadores (`ALLOWED_IDS`): Leonardo e Dra. Fernanda. O aviso por e-mail foi removido.
- Desde 25/10/2026 o Leonardo recebe os artigos pela fila de aprovações do bot Carrosseis AET (rotina-carrossel, um processo por vez). O aviso deste workflow pula o ID guardado no secret `TELEGRAM_FILA_ID`; sem esse secret, todos os IDs de `TELEGRAM_CHAT_IDS` recebem como antes.
- Se os botões pararem: confira o token (permissão/revogação), os secrets `TELEGRAM_TOKEN`/`TELEGRAM_CHAT_IDS` no GitHub e as variáveis da Worker.
