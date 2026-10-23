#!/usr/bin/env bash
# Regenera los cinco sonidos de app/src/main/res/raw/ desde sus fuentes de
# dominio público (procedencia y verificación: docs/SOUNDS.md).
# Necesita curl + ffmpeg con libvorbis.
set -euo pipefail

cd "$(dirname "$0")/.."
RAW=app/src/main/res/raw
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
UA="EcoStation-asset-script/1.0"

# CC0 1.0 — YJJcoolcool — tono de 3520 Hz, 0.19 s
curl -fsSL -A "$UA" -o "$WORK/short.ogg" \
    https://upload.wikimedia.org/wikipedia/commons/8/87/Short_Beep.ogg
# Dominio público — D V S — demo de Windows Beep(): pasos de 294/440/524/660 Hz
# (y un paso intermedio de 415 Hz), luego un seno estable de 440 Hz.
curl -fsSL -A "$UA" -o "$WORK/long.ogg" \
    https://upload.wikimedia.org/wikipedia/commons/f/f9/Beep_example.ogg

mkdir -p "$RAW"
# Mono 44.1 kHz, picos cerca de -6 dBFS. El tono de 3520 Hz decodifica con un
# sobrepico de +3.2 dBFS: -9.3 dB lo deja en ~0.5. Beep_example ya pica en 0.5
# y se deja a ganancia unitaria.
enc() { ffmpeg -v error -y "$@" -ac 1 -ar 44100 -c:a libvorbis -q:a 5; }

# detected: el único pitido agudo, tal cual (la fuente ya trae su envolvente).
enc -i "$WORK/short.ogg" -af "aformat=channel_layouts=mono,aresample=44100,volume=-9.3dB" \
    "$RAW/eco_beep_detected.ogg"

# accepted: el mismo pitido dos veces, separados 60 ms.
enc -i "$WORK/short.ogg" -i "$WORK/short.ogg" -filter_complex \
    "[0:a]aformat=channel_layouts=mono,aresample=44100,apad=pad_dur=0.06[a];[1:a]aformat=channel_layouts=mono,aresample=44100[b];[a][b]concat=n=2:v=0:a=1,volume=-9.3dB" \
    "$RAW/eco_beep_accepted.ogg"

# rejected: 450 ms del seno de 440 Hz con fundidos de 10 ms (sin clics).
# La fuente es una SECUENCIA de Beep(): solo 1.0-2.0 s es un 440 Hz estable.
enc -i "$WORK/long.ogg" -af "atrim=1.2:1.65,asetpts=PTS-STARTPTS,afade=t=in:d=0.01,afade=t=out:st=0.44:d=0.01,aresample=44100" \
    "$RAW/eco_beep_rejected.ogg"

# Cues de resultado: dos tonos de 0.18 s, 40 ms de silencio entre ellos y
# fundidos de 10 ms en cada tono.
#
# Fronteras de paso medidas con FFT por ventanas (docs/SOUNDS.md):
#   294 Hz   0.000-0.112   (solo 33 ciclos: el paso más corto del archivo)
#   440 Hz   0.112-0.372
#   524 Hz   0.372-0.620
#   660 Hz   0.620-0.871
#   415 Hz   0.871-0.992
#   440 Hz   0.992-2.000
# Los cortes quedan holgados dentro de cada paso, salvo el de 294 Hz, que se
# repite una vez (aloop) porque su paso dura 112 ms y el cue pide 180 ms; el
# bucle es un número entero de ciclos (4500 muestras = 33 periodos exactos de
# 150 muestras), así que la unión es continua y no hay clic ni cambio de tono.

# recycled: 524 Hz y luego 660 Hz.
enc -i "$WORK/long.ogg" -filter_complex \
    "[0:a]atrim=0.400:0.580,asetpts=PTS-STARTPTS,afade=t=in:d=0.01,afade=t=out:st=0.17:d=0.01,apad=pad_dur=0.04[a];[0:a]atrim=0.660:0.840,asetpts=PTS-STARTPTS,afade=t=in:d=0.01,afade=t=out:st=0.17:d=0.01[b];[a][b]concat=n=2:v=0:a=1,aresample=44100" \
    "$RAW/eco_cue_recycled.ogg"

# not_recyclable: 440 Hz y luego 294 Hz.
enc -i "$WORK/long.ogg" -filter_complex \
    "[0:a]atrim=0.140:0.320,asetpts=PTS-STARTPTS,afade=t=in:d=0.01,afade=t=out:st=0.17:d=0.01,apad=pad_dur=0.04[a];[0:a]atrim=start_sample=0:end_sample=4500,asetpts=PTS-STARTPTS,aloop=loop=1:size=4500,atrim=end_sample=7938,afade=t=in:d=0.01,afade=t=out:st=0.17:d=0.01[b];[a][b]concat=n=2:v=0:a=1,aresample=44100" \
    "$RAW/eco_cue_not_recyclable.ogg"

ls -l "$RAW"
