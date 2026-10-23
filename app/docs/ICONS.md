# Iconos

Todos los iconos de la app son **vectores dibujados para EcoStation** con un
viewport de 24 × 24, trazo de 1,8 dp, extremos y uniones redondeados. No se
reutilizó path data de Material Icons ni de otra colección, así que no hay
atribución de terceros que añadir.

| Recurso | Uso |
|---|---|
| `ic_tab_card` | pestaña Tarjeta |
| `ic_tab_speaker` | pestaña Parlante |
| `ic_tab_points` | pestaña Mis puntos |
| `ic_tab_settings` | pestaña Ajustes |
| `ic_globe`, `ic_mail`, `ic_lock`, `ic_server`, `ic_user` | campos y filas de estado |
| `ic_bluetooth`, `ic_contactless`, `ic_phone` | tarjeta y parlante |
| `ic_check`, `ic_alert`, `ic_info` | señales y diagnóstico |
| `ic_chevron_right`, `ic_arrow_right`, `ic_cancel` | navegación y acciones |
| `ic_star` | pastilla «Esta semana» |
| `ic_dot_*` | puntos de estado y de material |
| `ic_mail_18`, `ic_lock_18`, `ic_server_18`, `ic_check_16` | variantes ópticas para campos y pastillas |

Los iconos se pintan con `ImageView.setColorFilter` o `android:tint` según el
estado (menta/verde para activo, pizarra para inactivo, rojo para error), de
modo que un mismo vector sirve en los tres contextos.

Las marcas y afordancias grandes (Boti, logotipo horizontal) NO son vectores
dibujados: salen de `design/brand/logo/*.svg` y se regeneran con
`app/tools/gen_brand_assets.py`.
