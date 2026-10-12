# Aprovação de artigos pelo Telegram

Fluxo: push em `artigo/**` → `artigo-revisao.yml` abre a issue **e** manda mensagem no Telegram com botões Publicar/Excluir → toque chega no Worker (`worker.js`) → Worker comenta PUBLICAR/EXCLUIR na issue → `artigo-decisao.yml` roda como sempre. O e-mail continua funcionando.

## Configuração (uma vez)
1. Use o bot da veterinária que já existe (pegue o token com @BotFather → `/mybots`). **Atenção:** um bot só pode ter um webhook. Se o bot atual já usa webhook ou outro serviço lendo as mensagens, o passo 4 vai derrubá-lo; nesse caso crie um bot só para aprovações (`/newbot`).
2. Cada aprovador (Dra. Fernanda e Leonardo) abre o bot e manda `/start`. Descubra o ID de cada um (ex. @userinfobot).
3. Crie a Worker na Cloudflare com o código de `worker.js` e os secrets: `TELEGRAM_TOKEN`, `WEBHOOK_SECRET` (texto aleatório), `GITHUB_TOKEN` (PAT da conta LSFcamp com permissão de issues no repositório), `ALLOWED_IDS` (IDs separados por vírgula), `REPO` (`techaet/veterinaria-fernanda-teo`).
4. Aponte o bot para a Worker:
   `curl "https://api.telegram.org/bot<TOKEN>/setWebhook" -d url=<URL da Worker> -d secret_token=<WEBHOOK_SECRET>`
5. Secrets do GitHub (Settings → Secrets → Actions): `TELEGRAM_TOKEN` e `TELEGRAM_CHAT_IDS` (os mesmos IDs, separados por vírgula).

Sem os secrets do GitHub, o passo do Telegram é pulado e tudo segue só por e-mail.
