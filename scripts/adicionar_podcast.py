#!/usr/bin/env python3
"""Põe um podcast (áudio do NotebookLM) no site. Idempotente por artigo.
Uso: python3 scripts/adicionar_podcast.py <slug-do-artigo> <arquivo.m4a> "<título do episódio>"
Faz: copia o original para o HD (SHA-256 conferido, linha no _LOG.md e no manifesto.csv), gera o MP3
mono 64 kbps em podcast/audio/, põe o episódio no topo de "podcasts" em scripts/site.json e roda o sincronizar.
Não faz commit."""
import hashlib, json, shutil, subprocess, sys
from datetime import date, datetime
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
HD = Path("/Volumes/Extreme SSD")
MARCA = HD / "AET/fernanda-teo"


def sha(p):
    h = hashlib.sha256()
    with open(p, "rb") as f:
        for bloco in iter(lambda: f.read(1 << 20), b""):
            h.update(bloco)
    return h.hexdigest()


def main(slug, origem, titulo):
    origem = Path(origem)
    if not (RAIZ / f"blog/{slug}/index.html").exists():
        sys.exit(f"ERRO: artigo não existe: blog/{slug}/")
    if not (HD / "AET").is_dir():  # AGENTS.md: sem HD montado, para
        sys.exit("ERRO: HD não montado (/Volumes/Extreme SSD/AET)")
    hoje = str(date.today())
    cfg_p = RAIZ / "scripts/site.json"
    cfg = json.loads(cfg_p.read_text(encoding="utf-8"))
    if any(e["artigo"] == slug for e in cfg["podcasts"]):
        print(f"{slug}: já tem podcast, nada a fazer")
        return

    # 1. original no HD: copiar, conferir, registrar
    destino = MARCA / f"audios/podcasts/{hoje}_{slug}{origem.suffix}"
    destino.parent.mkdir(parents=True, exist_ok=True)
    if not destino.exists():
        shutil.copy2(origem, destino)
    s = sha(origem)
    if sha(destino) != s:
        sys.exit(f"ERRO: SHA-256 da cópia no HD não confere: {destino}")
    agora = datetime.now().strftime("%Y-%m-%d %H:%M")
    with open(HD / "_LOG.md", "a", encoding="utf-8") as f:
        f.write(f"{agora} | robo-podcast | copia (SHA-256 conferido) | {origem} → {destino.relative_to(HD)} | podcast NotebookLM; MP3 64 kbps em site-fernanda-teo/podcast/audio/\n")
    mp3 = RAIZ / f"podcast/audio/{slug}.mp3"
    with open(MARCA / "imagens/site/manifesto.csv", "a", encoding="utf-8") as f:
        f.write(f"{mp3.relative_to(RAIZ)},{destino.relative_to(HD)},{s},{hoje}\n")

    # 2. MP3 do site
    mp3.parent.mkdir(parents=True, exist_ok=True)
    subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-i", str(origem), "-ac", "1", "-b:a", "64k", "-id3v2_version", "3",
                    "-metadata", f"title={titulo}", "-metadata", "artist=Dra. Fernanda Teo", str(mp3)], check=True)
    seg = float(subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(mp3)],
                               check=True, capture_output=True, text=True).stdout)

    # 3. site.json + sincronizar
    cfg["podcasts"].insert(0, {"artigo": slug, "titulo": titulo, "data": hoje, "duracao": f"{round(seg / 60)} min"})
    cfg_p.write_text(json.dumps(cfg, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    subprocess.run([sys.executable, str(RAIZ / "scripts/sincronizar.py")], check=True)
    print(f"{slug}: podcast adicionado ({round(seg / 60)} min)")


if __name__ == "__main__":
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    main(*sys.argv[1:])
