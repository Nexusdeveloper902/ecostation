# Fuentes

EcoStation usa tres familias auto-hospedadas. No hay peticiones a Google
Fonts ni a ningún CDN en tiempo de ejecución.

| Familia | Ejes incluidos | Archivo | Licencia |
|---|---|---|---|
| Fredoka | 500–600 (variable) | `public/fonts/fredoka-500-600.woff2` | SIL OFL 1.1 · `public/fonts/OFL-Fredoka.txt` |
| Nunito | 400–800 (variable) | `public/fonts/nunito-400-800.woff2` | SIL OFL 1.1 · `public/fonts/OFL-Nunito.txt` |
| JetBrains Mono | 600 | `public/fonts/jetbrains-mono-600.woff2` | SIL OFL 1.1 · `public/fonts/OFL-JetBrainsMono.txt` |

`public/css/base.css` declara los `@font-face` con `font-display: swap`.
El layout precarga Nunito y Fredoka porque son las caras del primer
pintado.

## Cómo regenerarlas

Los archivos son los `.woff2` del subconjunto `latin` que sirve la API de
Google Fonts:

```sh
curl -A '<navegador moderno>' \
  'https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Nunito:wght@400;700;800&family=JetBrains+Mono:wght@600&display=swap'
```

Descarga la URL `latin` de cada familia y conserva los nombres de archivo
de la tabla. Las licencias están copiadas textualmente desde
`github.com/google/fonts/ofl/{fredoka,nunito,jetbrainsmono}/OFL.txt`.

## Reglas de uso

- Títulos y wordmark: **Fredoka 500/600**.
- Texto, etiquetas, botones: **Nunito 400/700/800**.
- Cifras, saldos, horas y deltas: **JetBrains Mono 600** con
  `font-variant-numeric: tabular-nums`.
- No se permite cargar una fuente desde un origen remoto. Si se cambia
  una familia, actualiza esta tabla y los `@font-face`.
