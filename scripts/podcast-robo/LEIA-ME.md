# Robô do podcast

Gera no NotebookLM (Gemini Notebook) o podcast de cada artigo do blog que ainda não tem episódio e publica em `/podcast/`, sem revisão (decisão do Leonardo em 22/10/2026).

## Como roda

- `~/Library/LaunchAgents/com.aet.podcast-robo.plist`: a cada 3 h, `node robo.mjs` a partir do clone do robô.
- Por rodada: começa até 5 episódios (Resumo em Áudio → **Análise detalhada**, duração **Padrão**, **português (Brasil)**), espera 5 min, confere a cada 3 min por até 45 min. Baixa o que ficou pronto e publica (`scripts/adicionar_podcast.py`: original no HD com SHA-256, MP3 64 kbps, `site.json`, sincronizar). Depois roda o `verificar.py`, faz commit e push na `main`, e o deploy sai sozinho. O que não ficar pronto é conferido na próxima rodada.
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

Tire o episódio de `"podcasts"` em `scripts/site.json` e o MP3 de `podcast/audio/`, apague a entrada dele no `estado.json` e rode `node robo.mjs`.

## Teste sem publicar

    PODCAST_REPO=<checkout do site> node robo.mjs --so-conferir

Usa o checkout como está (sem pull nem push) e só confere os episódios que já estão gerando.

Instalação no clone: `cd scripts/podcast-robo && npm install`.
