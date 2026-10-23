#!/usr/bin/env python3
"""Transcreve um episódio do podcast e escolhe o trecho que aparece no player (campo "trecho" em site.json).
Uso: python3 scripts/trecho_podcast.py <slug> [<slug> ...]      (sem slug: todos os episódios sem trecho)
Faz: transcreve os minutos 1 a 6 do original do HD (whisper.cpp, português; o episódio inteiro levaria
~1,25× a duração neste Mac Intel) para <original>.trecho.txt ao lado dele; pede ao Claude
(claude -p) um trecho literal de 1 ou 2 frases dentro das regras de regras-compliance.md §2; confere que o
trecho está, palavra por palavra, na transcrição; grava em site.json e roda o sincronizar. Não faz commit."""
import json, re, subprocess, sys, unicodedata
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
PODCASTS_HD = Path("/Volumes/Extreme SSD/AET/fernanda-teo/audios/podcasts")
MODELO = Path.home() / "Library/Application Support/aet-podcast/modelos/ggml-large-v3-turbo.bin"
WHISPER = str(Path.home() / "Library/Application Support/aet-podcast/whisper.cpp/build/bin/whisper-cli")  # compilado do código-fonte 1.9.5
PEDIDO = """Abaixo está a transcrição de um episódio de podcast sobre saúde de cães e gatos, feito a partir de um artigo da Dra. Fernanda Teo, médica-veterinária.
Escolha UM trecho para aparecer como citação embaixo do player no site.

Regras:
- Copie o trecho exatamente como está na transcrição (mesmas palavras, na mesma ordem). Pode ajustar só pontuação e maiúsculas.
- 1 ou 2 frases, entre 100 e 230 caracteres, que façam sentido sozinhas e deem vontade de ouvir o episódio.
- Útil para o tutor; nada de vinheta, apresentação ou despedida dos apresentadores.
- Proibido: nome de medicamento ou dose, diagnóstico de um caso, promessa de cura, orientar o tutor a decidir sozinho se é urgência, números de estudo que você não consegue conferir.
Responda só com o trecho, sem aspas e sem mais nada.

Transcrição:
"""


def norm(t):
    t = unicodedata.normalize("NFKD", t.lower())
    t = "".join(c for c in t if not unicodedata.combining(c))
    return " ".join(re.findall(r"[a-z0-9]+", t))


assert norm("Olá, CÃO — gato!") == "ola cao gato"


def original(slug):
    achados = sorted(p for p in PODCASTS_HD.glob(f"*_{slug}.*") if p.suffix not in (".txt",))
    if not achados:
        raise SystemExit(f"ERRO {slug}: original não encontrado em {PODCASTS_HD}")
    return achados[-1]


def transcrever(audio):
    txt = audio.with_suffix(".trecho.txt")
    if not txt.exists():
        wav = Path("/tmp") / f"{audio.stem}.wav"  # whisper.cpp lê WAV 16 kHz mono
        subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-ss", "60", "-t", "300", "-i", str(audio), "-ar", "16000", "-ac", "1", str(wav)], check=True)
        subprocess.run([WHISPER, "-m", str(MODELO), "-l", "pt", "-nt", "-t", "8", "-otxt", "-of", str(txt.with_suffix("")), str(wav)],
                       check=True, capture_output=True)
        wav.unlink()
    return txt.read_text(encoding="utf-8")


def escolher(transcricao):
    for _ in range(2):
        r = subprocess.run(["claude", "-p", PEDIDO + transcricao], capture_output=True, text=True, timeout=300)
        trecho = r.stdout.strip().strip('"“”').strip()
        if 60 <= len(trecho) <= 260 and norm(trecho) in norm(transcricao):
            return trecho
    return None


def main(slugs):
    cfg_p = RAIZ / "scripts/site.json"
    cfg = json.loads(cfg_p.read_text(encoding="utf-8"))
    eps = {e["artigo"]: e for e in cfg["podcasts"]}
    for slug in slugs or [s for s, e in eps.items() if not e.get("trecho")]:
        texto = transcrever(original(slug))
        trecho = escolher(texto)
        if not trecho:
            print(f"{slug}: sem trecho válido (fica o aviso padrão)")
            continue
        eps[slug]["trecho"] = trecho
        cfg_p.write_text(json.dumps(cfg, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"{slug}: “{trecho}”")
    subprocess.run([sys.executable, str(RAIZ / "scripts/sincronizar.py")], check=True)


if __name__ == "__main__":
    main(sys.argv[1:])
