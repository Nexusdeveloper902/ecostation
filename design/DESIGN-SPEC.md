# EcoStation — design spec (extracted from Penpot)

Source of truth: the Penpot file connected over MCP, pages **Marca**, **Componentes**,
**Web** and **Móvil**. Exported PNGs live in this folder and are the pixel reference.
Brand concept: **Concepto B · Boti** (marked "Elegido" in Penpot). Read-only: do not
edit the Penpot file; implement from it.

## 1. Palette (semantic)

| Token | Hex | Use |
|---|---|---|
| `--eco-primary` | `#18B26B` | brand green, secondary buttons, highlights |
| `--eco-primary-strong` | `#0B7A47` | primary buttons, links, student hero, login panel |
| `--eco-primary-deep` | `#087F47` | headings/accents on light, success text |
| `--eco-secondary` | `#14B8B0` | turquoise accents, glass material |
| `--eco-secondary-deep` | `#0D7F7A` | turquoise text on light |
| `--eco-sun` | `#FFC93C` | points badges, accent |
| `--eco-coral` | `#FF7A59` | warm accent, metal material |
| `--eco-ink` | `#0F2A2E` | primary text, dark surfaces (tooltips, points pill) |
| `--eco-slate` | `#4A6468` | secondary text |
| `--eco-mist` | `#DCEBE6` | borders, dividers |
| `--eco-mint` | `#F2FAF6` | app background |
| `--eco-white` | `#FFFFFF` | cards, surfaces |

Support:

| Token | Hex |
|---|---|
| success bg / text | `#E6F8EE` / `#0B7A47` |
| warning bg / text | `#FDF2D6` / `#7A4A00` |
| warning icon | `#F59E0B` |
| danger bg / text | `#FDECEC` / `#C2262C` |
| material plastic / bg | `#2A78D6` / `#E3EEFB` |
| material metal / bg | `#EB6834` / `#FDEAE1` |
| material glass / bg | `#14B8B0` / `#E2F5EE` |
| material paper / bg | `#EDA100` / `#FFF4D6` |
| material other / bg | `#E87BA4` / `#FCEAF1` |

Contrast rule: body text is `--eco-ink` on white/mint (AA), white on
`--eco-primary-strong` (5.40), ink on sun (9.83). Never white on `#18B26B` for
small text (2.76 fails); use it for shapes or large display only.

## 2. Typography

Self-hosted Google Fonts (all OFL — ship the license files):

- **Fredoka** 500/600 — display, headings, logo wordmark.
- **Nunito** 400/700/800 — body, labels, buttons, captions.
- **JetBrains Mono** 600 — numbers, balances, times, point deltas.

| Style | Font | Size / line | Use |
|---|---|---|---|
| Display | Fredoka 600 | 56 / 64 | hero statements |
| H1 | Fredoka 600 | 48 / 56 | login statement |
| H2 | Fredoka 500 | 28 / 36 | page titles, card titles |
| Body | Nunito 400 | 18 / 28 | intro copy |
| Body small | Nunito 400 | 16 / 24 | rows, table text |
| Small / label | Nunito 700 | 14 / 20 | labels, sidebar, tabs |
| Micro | Nunito 700 | 12 / 16 | eyebrow, chips |
| Figures | JetBrains Mono 600 | 32 / 40 | balances, KPI numbers |

Numbers are formatted Spanish/Colombian: thousands `.` (`1.245`), decimals `,`, `pts`.
Dates `dd/MM`, timezone `America/Bogota`.

## 3. Shape and space

- Radii: `xs 4`, `sm 8`, `md 12` (inputs/buttons), `lg 16` (cards), `xl 24`
  (hero/panels), `pill 999`.
- Spacing scale (base 4): 4, 8, 12, 16, 24, 32, 48, 64.
- Cards: white, 1px `--eco-mist`, radius 16–24, shadow
  `0 1px 2px rgba(15,42,46,.04), 0 8px 24px rgba(15,42,46,.05)`.
- Focus ring: 2px `--eco-primary` with 2px offset.

## 4. Components (page "Componentes")

- **Buttons** height 48, radius 12, Nunito 700 14, padding 0 24:
  - primary: bg `#0B7A47`, white text;
  - secondary: bg `#18B26B`, white text;
  - outline: white bg, 1px mist border, ink text;
  - danger: `#C2262C` bg, white;
  - ghost: transparent, primary-strong text.
  Icon 18 px, 8 px gap. Disabled = 40 % opacity.
- **Inputs**: label Nunito 700 14 above; field white, 1px `#DCEBE6`, radius 12,
  height 48, 16 px horizontal padding, leading icon 18 px slate. Error border
  `#C2262C` + error text 12 px. Password fields masked.
- **KPI card**: 260×126, label 14 slate, figure JetBrains Mono 32 ink, delta 12.
- **Avatar**: 40 circle, colored bg (rotating palette), initials 14 bold.
- **Tabs**: pill container on mint; active = white card + shadow, inactive = slate.
- **Chips**: pill, tinted bg, dot + label 14.
- **Badges**: points badge = sun pill, star + `+15 pts`; balance pill = ink bg.
- **Sidebar** (web, 264 px, mint bg, full height):
  brand at top (24 px padding), role eyebrow 12 uppercase slate, items 44 px tall
  radius 12, active mint-strong/white with green text and 20 px icon, logout pinned
  bottom. Student / Docente / Admin variants differ only in items.
- **Top bar**: 88 px, page title H2 + subtitle 16 slate on the left; user pill
  (avatar + name + role) on the right; filter controls (class/period selects)
  below or right.
- **Rows**: deposit row 64 px with material chip, title, meta, points badge;
  ranking row with position, avatar, name, class, points; live row 68 px with
  material dot, station, student, time, status.
- **Charts**: line chart per material (series colors = material colors), y grid
  `#DCEBE6`, tooltip ink surface, legend dots; horizontal bars for distribution.

## 5. Brand assets

- Logo light: `brand/logo/ecostation-horizontal.svg` (wordmark + Boti).
- Logo on dark: `brand/logo/ecostation-horizontal-oscuro.svg`.
- Symbol: `brand/logo/boti.svg`, dark-surface `boti-oscuro.svg`.
- App icon: `movil/icono-de-app.png` (generate Android mipmaps from it).
- Boti is a friendly green bottle with a yellow cap and leaf; it appears large on
  the login panel and in the student balance hero only. Do not recolor or
  redraw it; use the exported SVG.

## 6. Screen map — web (`web/resources/views`)

| Penpot board (`design/web/`) | Route / view |
|---|---|
| `login.png` | `GET /login` — split screen: 50 % primary-strong panel with Boti + "Recicla, toca y suma puntos.", right white form |
| `login-cambio-contrasena.png` | `GET /password/change` |
| `estudiante-inicio.png` | `GET /student` — sidebar Estudiante |
| `estudiante-historial.png` | `GET /student/history` |
| `estudiante-ranking-estudiantes.png` | `GET /student/leaderboard` (tab Estudiantes) |
| `estudiante-ranking-cursos.png` | same route, tab Cursos |
| `docente-resumen.png` | `GET /teacher` |
| `docente-estudiantes.png` | `GET /teacher/classes/{class}` and `/teacher/students/{student}` |
| `docente-en-vivo.png` | `GET /teacher/live` |
| `docente-exportar.png` | export actions on the teacher pages |
| `admin-estudiantes-y-cursos.png` | `GET /admin/students` + `/admin/classes` (tabs) |
| `admin-vinculacion-esperando.png` | `GET /admin/pairing` (waiting) |
| `admin-vinculacion-exito.png` | `GET /admin/pairing` (success state, live) |
| `admin-estaciones.png` | `GET /admin/stations` |
| `admin-personal.png` | `GET /admin/staff` |
| `admin-ajustes.png` | `GET /admin/settings` |
| `movil-estudiante-inicio.png`, `movil-estudiante-historial.png`, `movil-estudiante-ranking.png` | responsive 390 px variants of the student pages |

Responsive: below 1024 px the sidebar collapses to the 76 px bottom bar
(`Componentes / Barra inferior / Estudiante`) and cards stack 1 column; student
pages must match the `movil-*` boards at 390 px.

Web shell details from the **Web** boards:

- **Sidebar variants** (264 px, all share the logo, user block and logout):
  - Estudiante: eyebrow `ESTUDIANTE`; Inicio, Historial, Ranking.
  - Docente: eyebrow `DOCENTE`; Resumen, Estudiantes, En vivo, Exportar;
    bottom card "Mis cursos" listing the assigned classes.
  - Admin: eyebrow `SUPERVISIÓN` → Resumen, En vivo; eyebrow `ADMINISTRACIÓN`
    → Estudiantes y cursos, Vinculación, Estaciones, Personal, Ajustes.
    Resumen/En vivo reuse the teacher routes (admins are allowed there).
- **Top bar**: page title H2 + one-line subtitle; right user pill with avatar
  initials, name and role; admin/teacher role pages also show filter controls
  (class select, period select with calendar icon) under the title.
- **Admin · Estudiantes y cursos**: tabs `Todos` + one pill per class with the
  count, then `+ Nuevo curso`; toolbar with search ("Buscar por nombre o
  correo"), a `Credencial: todas` filter, `Importar CSV` (outline) and
  `Nuevo estudiante` (primary). Table columns: Estudiante (avatar, name,
  email), Curso, Credencial chip, Puntos (mono), action (`Gestionar` outline
  or `Vincular` primary) + overflow. Credential chips: green `Tarjeta`,
  `Teléfono`, `Tarjeta + teléfono`; red `Sin vincular`; amber
  `Pendiente: tocar estación`. Footer "Mostrando N de M".
- **Admin · Vinculación**: two cards side by side. Left: "1. Elige al
  estudiante" with search, `Sin vincular (N)` / `Todos` tabs and a scrollable
  student list (avatar, name, class, "sin credencial", `Armar` outline or
  `Armado` + green highlight). Right: "2. Acerca la credencial" with the
  concentric reader graphic, heading "Acerca la tarjeta o el teléfono a
  cualquier estación", the armed-student explanation, an amber
  `Esperando toque 0:47` pill and `Cancelar` outline. Success swaps the right
  card for a green check, student name and the linked credential.
- **Admin · Estaciones**: table with label, key fingerprint (mono), last
  event, actions rotate/delete; `Nueva estación` primary; one-time key flash
  in a warning panel.
- **Admin · Personal**: name, email, role chip, actions; `Nuevo usuario`.
- **Admin · Ajustes**: sections for points per material (number inputs with
  material chips), photo retention days, pairing window; save primary.
- **Docente · Exportar**: range selector, scope summary, large `Exportar CSV`
  and `Exportar PDF` cards with format descriptions, recent-export hint.
- **Docente · En vivo**: feed list of live rows with material dot, student,
  station, time and status chip; connection status in the header.

## 7. Screen map — Android (`app/app/src/main`)

| Penpot board (`design/movil/`) | Screen / state |
|---|---|
| `tarjeta-sin-vincular.png` | MainActivity, `SIN VINCULAR` |
| `tarjeta-vinculando.png` | MainActivity, `VINCULANDO 0:42` |
| `tarjeta-lista.png` | MainActivity, `LISTA` (card face, ISO ID-1 ratio) |
| `modo-parlante.png` | MainActivity speaker mode diagnostics/on-air |
| `mis-puntos-iniciar-sesion.png` | StudentActivity login group |
| `mis-puntos.png` | StudentActivity account group |
| `ajustes.png` | SettingsActivity |
| `icono-de-app.png` | launcher icon source |

Bottom navigation from `Componentes / Android / Navegación inferior`; Android status
bar is part of the system, do not paint it. Keep error states (`NFC APAGADO`,
`NO COMPATIBLE`) visually distinct from brand greens (danger red).

Android shell details (from the Móvil boards, 360×800 @1x):

- **Bottom nav** (4 tabs, 76 px + system inset): `Tarjeta`, `Parlante`,
  `Mis puntos`, `Ajustes`. Active tab = mint pill (#E6F8EE) with primary-strong
  icon/label; inactive = slate. Each tab is a real destination: MainActivity
  (Tarjeta), MainActivity speaker section (Parlante), StudentActivity (Mis puntos),
  SettingsActivity (Ajustes).
- **Top bar** on every screen: 64 px, Boti symbol 28 px + screen title Fredoka 500
  22, mint background.
- **Tarjeta**: green gradient card (#18B26B → #0B7A47, radius 24, 24 px padding)
  with the horizontal light logo, student name (Fredoka 600 24), class/school line,
  credential id in JetBrains Mono 13 and a white status pill ("Lista para tocar").
  Below: concentric mint circles (three rings) with a phone outline icon, then the
  state heading (Fredoka 500 24) and a 2-line hint (Nunito 16 slate). Bottom: last
  tap pill (white, check icon, "Último toque: hoy 10:42 · Cafetería").
  States: `SIN VINCULAR` (grey card, button "Vincular este teléfono"),
  `VINCULANDO 0:42` (countdown), `LISTA` (green), `NFC APAGADO` / `NO COMPATIBLE`
  (danger-tinted card and red heading).
- **Parlante**: title + switch on the right; white status card with three rows
  (Servidor, Parlante Bluetooth, Cuenta de estación), each 20 px icon in mint tile,
  label + value, right status chip (green dot "Conectado" / "Activa"); "Últimas
  señales" list with success/warning icon tile, title, material + station + time,
  and the cue name; "Dirección del servidor" field at the bottom.
- **Mis puntos**: login group = green hero with Boti + "Inicia sesión" white card
  form; account group = balance hero (green gradient, "Tu saldo", JetBrains Mono
  32 figure, sun chip "+85 esta semana", Boti on the right) + "Historial" rows:
  material chip left, title/meta, sun points badge right (grey "0 pts" for zero);
  change-password group = white card, three masked fields, primary button.
- **Ajustes**: section eyebrow `SERVIDOR`, address field with globe icon,
  "Buscar automáticamente" switch row with `_ecostation._tcp` caption, outline
  "Probar conexión" button + status dot/latency; eyebrow `CUENTA DEL PARLANTE`,
  email field, masked password field, primary "Iniciar sesión del parlante";
  centered footer `EcoStation 1.0.0 · co.ecostation.app`.
