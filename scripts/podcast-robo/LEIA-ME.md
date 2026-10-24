# Robô do podcast

Gera no NotebookLM (Gemini Notebook) o podcast de cada artigo do blog que ainda não tem episódio e publica em `/podcast/`, sem revisão (decisão do Leonardo em 22/10/2026).

Atende mais de um site (lista `SITES` no `robo.mjs`):

| Site | Repo | Episódios | Clone do robô | Estado |
|---|---|---|---|---|
| Dra. Fernanda Teo | `techaet/veterinaria-fernanda-teo` (o robô mora aqui) | 1 por artigo, em português | `site/` | `estado.json` |
| Pousada Campeche Lofts | `techaet/pousada-campeche-lofts` | 2 por artigo: português e espanhol (`español (Latinoamérica)` + instrução de voseo argentino no campo de foco; pedido do Leonardo em 24/10/2026) | `site-pousada-campeche-lofts/` | `estado-pousada-campeche-lofts.json` |

Um site só entra na rodada quando a `main` dele tem `scripts/adicionar_podcast.py`. Cada repo tem os próprios `adicionar_podcast.py`, `trecho_podcast.py` e verificação (`verificar.py` na Fernanda, `check_site.py` na Pousada). Na Pousada o id do episódio é `<slug>/<pt|es>`, e é ele que vai para os dois scripts. Os apresentadores comentam o artigo: nunca são a Dra. nem a pousada.

## Como roda

- `~/Library/LaunchAgents/com.aet.podcast-robo.plist`: a cada 3 h, `node robo.mjs` a partir do clone do robô.
- Por rodada: começa até 5 episódios, somando os sites (Resumo em Áudio → **Análise detalhada**, duração **Padrão**, idioma do episódio; o robô escolhe o idioma na caixa e confere), espera 5 min, confere a cada 3 min por até 45 min. Baixa o que ficou pronto e publica (`scripts/adicionar_podcast.py`: original no HD com SHA-256, MP3 64 kbps, `site.json`, sincronizar). Depois roda o `verificar.py`, faz commit e push na `main`, e o deploy sai sozinho. O que não ficar pronto é conferido na próxima rodada.
- Se o Chrome do robô estiver aberto sem nenhuma janela, o robô abre uma aba antes de conectar (sem isso o Playwright não conecta).
- Sem HD conectado não faz nada. Se bater no limite de uso do NotebookLM, para de começar episódios novos até a rodada seguinte.
- O título do episódio é o que o NotebookLM dá ao áudio.
- O trecho sob o player sai de `scripts/trecho_podcast.py`: transcreve os minutos 1 a 6 com o whisper.cpp (compilado em `~/Library/Application Support/aet-podcast/whisper.cpp/`, modelo `modelos/ggml-large-v3-turbo.bin`) e o `claude -p` escolhe uma frase literal dentro das regras clínicas. Se falhar, o player mostra o aviso padrão (`podcast_aviso`), e dá para rodar o script depois.

## Onde fica cada coisa (`~/Library/Application Support/aet-podcast/`)

| Caminho | O quê |
|---|---|
| `chrome/` | Perfil do Chrome só do robô, logado em tech.aetsolidez@gmail.com. Ele abre com `--remote-debugging-port=9333` e o robô se conecta por CDP |
| `site/` | Clone do site só do robô. Ele faz `pull` a cada rodada, então o código do robô se atualiza sozinho com a `main` |
| `estado.json` | Por artigo: `gerando` (com o link do notebook), `publicado` ou `erro` (tenta de novo até 2 vezes) |
| `baixados/` | Áudios baixados, apagados depois de copiados e conferidos no HD |

Log: `~/Library/Logs/aet-podcast.log`. Os avisos (deslogado, falha) também aparecem como notificação do macOS.

## Se o Google deslogar

Feche o Chrome do robô e rode:

    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --user-data-dir="$HOME/Library/Application Support/aet-podcast/chrome" --remote-debugging-port=9333 https://notebook.google.com/

Entre com tech.aetsolidez@gmail.com e deixe a janela aberta. O login só funciona assim, num Chrome comum: aberto pelo Playwright, o Google recusa.

## Refazer um episódio

Tire o episódio de `"podcasts"` em `scripts/site.json` e o MP3 de `podcast/audio/`, apague a entrada dele no arquivo de estado do site e rode `node robo.mjs`.

## Teste sem publicar

    PODCAST_REPO=<checkout do site> node robo.mjs --so-conferir

Usa o checkout como está (sem pull nem push; faz commit nele) e só confere os episódios que já estão gerando. Sem `PODCAST_SITE`, o checkout é tratado como o da Fernanda. Para testar a Pousada com um artigo só:

    PODCAST_REPO=<checkout da pousada> PODCAST_SITE=pousada-campeche-lofts PODCAST_ARTIGO=<slug> node robo.mjs

Instalação no clone: `cd scripts/podcast-robo && npm install`.
