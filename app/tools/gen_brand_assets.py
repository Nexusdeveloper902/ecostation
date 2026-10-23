#!/usr/bin/env python3
"""Genera los recursos de marca de la app Android desde `design/`.

Fuente de verdad: `design/brand/logo/boti.svg`,
`ecostation-horizontal.svg` (tinta sobre menta) y
`ecostation-horizontal-oscuro.svg` (claro sobre verde), más
`design/movil/icono-de-app.png` como referencia del icono.

El SVG se rasteriza con `rsvg-convert` (sin dependencias de Python para SVG)
y se compone con Pillow. Los PNG quedan en `drawable-nodpi`/`mipmap-*`, que
son recursos de fuente y sí se versionan; nunca se escribe en `build/`.

Uso:
    python3 app/tools/gen_brand_assets.py
    ECOSTATION_DESIGN=/ruta/a/design python3 app/tools/gen_brand_assets.py
"""
from __future__ import annotations

import os
import subprocess
import tempfile

from PIL import Image, ImageDraw

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT = os.path.dirname(HERE)                     # app/
RES = os.path.join(PROJECT, "app", "src", "main", "res")
WORKTREE = os.path.dirname(PROJECT)
DESIGN = os.environ.get("ECOSTATION_DESIGN", os.path.join(WORKTREE, "design"))

LOGO_DIR = os.path.join(DESIGN, "brand", "logo")
BOTI = os.path.join(LOGO_DIR, "boti.svg")
LOCKUP_INK = os.path.join(LOGO_DIR, "ecostation-horizontal.svg")
LOCKUP_LIGHT = os.path.join(LOGO_DIR, "ecostation-horizontal-oscuro.svg")

# Fondo del icono: el tablero `icono-de-app.png` usa la menta de la marca,
# no el verde de botón (Boti es verde y desaparecería sobre él).
ICON_BG = (242, 250, 246, 255)  # --eco-mint
DENSITIES = {"mdpi": 1.0, "hdpi": 1.5, "xhdpi": 2.0, "xxhdpi": 3.0, "xxxhdpi": 4.0}


def render(svg: str, height: int | None = None, width: int | None = None) -> Image.Image:
    """Rasteriza un SVG a PNG RGBA con rsvg-convert."""
    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
        out = tmp.name
    cmd = ["rsvg-convert"]
    if width:
        cmd += ["-w", str(width)]
    if height:
        cmd += ["-h", str(height)]
    cmd += ["-o", out, svg]
    subprocess.run(cmd, check=True)
    try:
        return Image.open(out).convert("RGBA")
    finally:
        os.unlink(out)


def tight(img: Image.Image, threshold: int = 8) -> Image.Image:
    """Recorta al recuadro de píxeles con alfa visible."""
    alpha = img.getchannel("A")
    box = alpha.point(lambda v: 255 if v > threshold else 0).getbbox()
    return img.crop(box) if box else img


def save_nodpi(img: Image.Image, name: str) -> None:
    out_dir = os.path.join(RES, "drawable-nodpi")
    os.makedirs(out_dir, exist_ok=True)
    img.save(os.path.join(out_dir, name))
    print(f"drawable-nodpi/{name:26} {img.width}x{img.height}")


def emit_legacy_icon(boti: Image.Image) -> None:
    """Icono clásico (API < 26) y su variante circular."""
    for bucket, scale in DENSITIES.items():
        out_dir = os.path.join(RES, f"mipmap-{bucket}")
        os.makedirs(out_dir, exist_ok=True)

        size = int(round(48 * scale))
        tile = Image.new("RGBA", (size, size), ICON_BG)
        inner = int(size * 0.68)
        mark = boti.copy()
        mark.thumbnail((inner, inner), Image.LANCZOS)
        tile.alpha_composite(mark, ((size - mark.width) // 2, (size - mark.height) // 2))
        tile.save(os.path.join(out_dir, "ic_launcher.png"))

        # Máscara circular: mismo dibujo, recortado en redondo.
        round_icon = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        mask = Image.new("L", (size, size), 0)
        ImageDraw.Draw(mask).ellipse((0, 0, size - 1, size - 1), fill=255)
        round_icon.paste(tile, (0, 0), mask)
        round_icon.save(os.path.join(out_dir, "ic_launcher_round.png"))

        # Capa frontal adaptativa: lienzo 108dp, Boti dentro de la zona segura.
        fg_size = int(round(108 * scale))
        fg = Image.new("RGBA", (fg_size, fg_size), (0, 0, 0, 0))
        safe = int(fg_size * 0.52)
        mark2 = boti.copy()
        mark2.thumbnail((safe, safe), Image.LANCZOS)
        fg.alpha_composite(mark2, ((fg_size - mark2.width) // 2, (fg_size - mark2.height) // 2))
        fg.save(os.path.join(out_dir, "ic_launcher_foreground.png"))
    print("mipmap-*: ic_launcher, ic_launcher_round, ic_launcher_foreground")


def main() -> None:
    if not os.path.isdir(DESIGN):
        raise SystemExit(f"No existe el directorio de diseño: {DESIGN}")
    boti = tight(render(BOTI, height=1024))
    save_nodpi(boti, "boti.png")
    save_nodpi(tight(render(LOCKUP_INK, height=360)), "logo_horizontal.png")
    save_nodpi(tight(render(LOCKUP_LIGHT, height=360)), "logo_horizontal_light.png")
    emit_legacy_icon(boti)
    print("Listo. Los archivos generados viven en res/, no en build/.")


if __name__ == "__main__":
    main()
