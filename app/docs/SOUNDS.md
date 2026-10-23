# Sonidos — procedencia y verificación

Cinco sonidos cortos viven en `app/src/main/res/raw/`. Todos salen de dos
archivos de Wikimedia Commons que no exigen atribución ni compartir igual.
`tools/gen_sounds.sh` vuelve a descargar las fuentes y reconstruye los assets
con el mismo procesamiento (necesita `curl` + `ffmpeg` con libvorbis; los bytes
codificados pueden variar un poco entre versiones de ffmpeg).

## Fuentes

| Fuente | Autor | Licencia | Qué es |
|---|---|---|---|
| [File:Short_Beep.ogg](https://commons.wikimedia.org/wiki/File:Short_Beep.ogg) | YJJcoolcool | **CC0 1.0** (dedicación al dominio público) | un tono de 3520 Hz, 0.19 s, 48 kHz estéreo |
| [File:Beep_example.ogg](https://commons.wikimedia.org/wiki/File:Beep_example.ogg) | D V S | **Dominio público** (liberado por el autor) | una demo de `Beep()` de Windows: pasos de 294/440/524/660 Hz, un paso intermedio de 415 Hz y luego un seno estable de 440 Hz hasta 2.0 s |

La segunda fuente ya contiene las cuatro frecuencias que necesitan los cues de
resultado, así que no entra ninguna licencia nueva al repositorio.

## Assets y procesamiento

| Asset | Cue | Construido con | Procesamiento |
|---|---|---|---|
| `eco_beep_detected.ogg` | a este teléfono le leyeron la credencial (HCE local) | Short_Beep | remuestreado a 44.1 kHz mono, −9.3 dB |
| `eco_beep_accepted.ogg` | el backend registró el toque (`feedback=accepted`) | Short_Beep ×2 | igual, dos veces, 60 ms de separación |
| `eco_beep_rejected.ogg` | el backend marcó el toque (`feedback=rejected`) o falló el reto local | Beep_example | corte 1.20–1.65 s (solo la parte estable de 440 Hz), fundidos de 10 ms, 44.1 kHz mono |
| `eco_cue_recycled.ogg` | una clasificación nueva dio puntos (`points > 0`) | Beep_example | 524 Hz (0.400–0.580 s) + 660 Hz (0.660–0.840 s), 40 ms de silencio, fundidos de 10 ms en cada tono |
| `eco_cue_not_recyclable.ogg` | una clasificación nueva no dio puntos (`points == 0`) | Beep_example | 440 Hz (0.140–0.320 s) + 294 Hz, 40 ms de silencio, fundidos de 10 ms en cada tono |

Todos los assets son Ogg Vorbis, mono, 44.1 kHz, con picos alrededor de
−6 dBFS. El tono de 3520 Hz decodifica con un sobrepico de +3.2 dBFS: por eso
recibe −9.3 dB de ganancia. `Beep_example` ya pica en 0.5 y se deja a ganancia
unitaria.

### Fronteras de paso en `Beep_example.ogg`

Medidas con FFT por ventanas (ventana de 20–30 ms cada 5–10 ms, interpolación
parabólica del pico), antes de fijar los `atrim` del script:

| Paso | Intervalo | Frecuencia medida |
|---|---|---|
| 1 | 0.000–0.112 s | 294.00 Hz |
| 2 | 0.112–0.372 s | 440.00 Hz |
| 3 | 0.372–0.620 s | 524.00 Hz |
| 4 | 0.620–0.871 s | 660.00 Hz |
| 5 | 0.871–0.992 s | 415.00 Hz |
| 6 | 0.992–2.000 s | 440.00 Hz |

El paso de 294 Hz dura solo 112 ms (33 ciclos: 150 muestras por periodo a
44.1 kHz) y el cue pide 180 ms. `gen_sounds.sh` toma las primeras 4500 muestras
(30 ciclos exactos) y las repite una vez con `aloop`; como el bucle cae en un
cruce por cero y contiene un número entero de periodos, la unión es continua y
no cambia el tono. Después recorta a 7938 muestras (0.18 s). Es la única
diferencia de procesamiento frente a un corte simple, y está comentada en el
script.

## Verificación

No había altavoces en el entorno de compilación, así que cada archivo se
comprobó por análisis de señal y no de oído: decodificar a PCM y tomar una FFT
con ventana Hanning sobre la parte estable de cada tono (fuera de los fundidos).

| Asset | Duración | Frecuencia medida | Fuga del otro tono |
|---|---|---|---|
| `eco_beep_detected.ogg` | 0.189 s | **3519.96 Hz** | — |
| `eco_beep_accepted.ogg` | 0.441 s | **3519.96 Hz** y **3520.02 Hz** | — |
| `eco_beep_rejected.ogg` | 0.447 s | **440.00 Hz** | — |
| `eco_cue_recycled.ogg` | 0.397 s | **524.00 Hz** y **660.01 Hz** | 660 Hz −78.9 dB; 524 Hz −73.7 dB |
| `eco_cue_not_recyclable.ogg` | 0.397 s | **440.01 Hz** y **293.99 Hz** | 294 Hz −74.0 dB; 440 Hz −62.2 dB |

Cada tono queda dentro de ±3 Hz de su frecuencia nominal y ningún vecino se
cuela por encima de −60 dB. El hueco entre los dos tonos de un cue mide 40 ms
y su nivel es silencio (RMS < 0.002), y la pendiente máxima de las muestras no
supera la de un seno limpio, así que no hay clics ni saltos en las uniones.

Son senos cortos: no hay voces, melodías ni música.
