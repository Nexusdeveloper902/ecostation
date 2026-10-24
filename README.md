# EcoStation

Estación de reciclaje escolar con tarjeta o teléfono, puntos automáticos y
paneles para estudiantes, docentes y administración. Este repositorio es el
monorepo completo: servidor Laravel, app Android, firmware ESP32-CAM y los
diseños de Penpot.

```
web/        Laravel 13 + SQLite: API de estaciones, paneles, feed en vivo, PDF/CSV
app/        Android/Kotlin: teléfono como tarjeta (HCE), modo parlante, mis puntos
firmware/   PlatformIO: estación ESP32-CAM (RC522 + cámara), pruebas nativas y e2e
design/     Exportes de Penpot + DESIGN-SPEC.md (tokens, componentes y mapas de pantalla)
docs/       Especificación, planes de implementación y política de privacidad
```

## Inicio rápido (web)

Requisitos: **PHP 8.5.12+** con `pdo_sqlite`, Composer. Node no es necesario.
(8.5.12 es el primer parche de PHP2Shell; `bootstrap/app.php` y `./run` se
niegan a arrancar en versiones vulnerables.)

```bash
cd web
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
./run serve
```

`./run serve` arranca el servidor web (8000), el WebSocket (`realtime:serve`,
8081) y el planificador. Por defecto escucha solo en loopback; para la LAN:

```bash
ECOSTATION_LAN=1 ./run serve
```

Si el puerto 8081 está ocupado, define `REALTIME_PORT` y arranca ambos procesos
con el mismo valor. Detrás de un proxy TLS, `REALTIME_PUBLIC_URL=wss://…`
anuncia el feed por `wss`.

También puedes usar `./run test` y `./run ci` (Pint + PHPUnit).

### Cuentas demo (`local`/`testing`)

| Rol | Correo | Contraseña |
|---|---|---|
| Admin | `admin@ecostation.test` | `password` |
| Docente (5° A) | `docente@ecostation.test` | `password` |
| Estación / parlante | `estacion@ecostation.test` | `password` |
| Estudiantes | `maria@`, `carlos@`, `ana@`, `diego@`, `lucia@` `ecostation.test` | `password` |

`DemoSeeder` **se niega a ejecutarse en producción** salvo `ECOSTATION_DEMO=1`.
Las cuentas creadas desde el panel reciben una contraseña inicial aleatoria y
deben cambiarla al entrar.

Para poblar los paneles con depósitos, puntos y actividad de demostración:

```bash
cd web && php artisan db:seed --class=DemoActivitySeeder
```

Es opcional, determinista e idempotente; no forma parte de `migrate --seed`.
No crea fotos.

### Clasificación

El driver por defecto es `stub` (sin red). Para DeepSeek, define
`DEEPSEEK_API_KEY` en `web/.env` y `RECYCLING_CLASSIFIER_DRIVER=deepseek`.
`php artisan llm-check` hace una llamada real con `tests/Fixtures/bottle.jpg`.
Las fotos se envían al proveedor para clasificar y se borran según la retención
configurada (30 días por defecto); consulta `docs/PRIVACIDAD.md`.

## App Android

```bash
cd app
./gradlew :app:testDebugUnitTest :app:assembleDebug
```

APK de depuración: `app/app/build/outputs/apk/debug/app-debug.apk`.

- El teléfono usa HCE con el protocolo v1.1 **sin cambios** (AID
  `F0010203040506`, CHALLENGE/ENROLL, vector compartido).
- El modo parlante se configura en **Ajustes**; no hay credenciales en
  `BuildConfig` ni en Gradle.
- Solo acepta servidores de la LAN privada (`10/8`, `172.16/12`, `192.168/16`,
  `169.254/16`) o `*.local`/`*.lan`, sobre HTTP. El token del estudiante y la
  contraseña de la estación nunca se envían a un host público.
- **Firma de release:** una llave estable fuera del repositorio. Sin ella,
  `assembleRelease` y `bundleRelease` fallan a propósito. Ver `app/README.md`
  §Firma de release.

## Firmware ESP32-CAM

```bash
cd firmware
cp include/secrets.h.example include/secrets.h   # completa Wi-Fi y API_BASE_URL
pio run -e esp32cam
pio test -e native
pio device monitor -e esp32cam
```

- La estación busca `_ecostation._tcp`; si la red bloquea multicast, fija la
  dirección con `s <url>` en el monitor serial (`s -` la borra).
- Los flujos de tarjeta primero y botella primero están cubiertos por pruebas
  nativas (`StationFlow`).
- E2E firmado contra el servidor:

```bash
cd firmware
WEB=../web ./scripts/e2e_web.sh
```

Comprueba 13 casos (tap, classify, captura, asociación, pairing, firma,
replay). `WEB` acepta la ruta al `web/` cuando se ejecuta desde un worktree.

## Diseño

La fuente de verdad es el archivo de Penpot (Concepto B · **Boti**), con las
páginas Marca, Componentes, Web y Móvil. Los PNG de `design/` son la referencia
visual y `design/DESIGN-SPEC.md` resume tokens, tipografía, componentes y el
mapa tablero → vista. Las fuentes (Fredoka, Nunito, JetBrains Mono) son OFL y se
autoalojan; no hay CDN.

## Seguridad y límites conocidos

- **PHP2Shell (CVE-2026-109618 / CVE-2026-109619, 21 oct 2026).** RCE no
  autenticado en PHP 5.4–8.5.11. Este proyecto exige **8.5.12+** en
  `composer.json`, CI, `./run` y `bootstrap/app.php`; una versión vulnerable
  falla cerrada con un mensaje claro. Actualiza el runtime del servidor, no
  solo el lockfile.
- El despliegue v1 es **HTTP en la LAN escolar**. mDNS y el sondeo de subred
  sirven para descubrir, no para autenticar criptográficamente al servidor; la
  app fija la dirección tras el primer inicio de sesión y exige el manifiesto
  de EcoStation. Para una red hostil hace falta TLS en un proxy inverso.
- El literal `Pulse-HMAC`, el label `pulse-hce-key-wrap/v1` y los vectores de
  prueba conservan el nombre Pulse **a propósito**: son constantes del protocolo
  en el cable y no deben renombrarse.
- Nunca se versionan `secrets.h`, `.env`, bases SQLite, llaves de firma ni
  material de claves. `gitleaks` corre en CI.
- El ledger de puntos es append-only; las estadísticas se derivan de `events` y
  `points_ledger`. Desemparejar una credencial la revoca sin borrar historial.

## Documentación

- `docs/superpowers/specs/2026-10-23-ecostation-design.md` — especificación.
- `docs/superpowers/plans/2026-10-23-ecostation-{web,app,firmware}.md` — planes.
- `docs/PRIVACIDAD.md` — fotos, retención, feed y datos de tarjetas.
- `firmware/docs/HCE_PROTOCOL.md` — protocolo HCE v1.1 y vectores.
- `firmware/docs/ESTACION.md` — cableado, modos, comandos y checklist de banco.
- `app/docs/SOUNDS.md` y `app/docs/FONTS.md` — activos y licencias.

## Estado de pruebas

| Componente | Comando | Último resultado |
|---|---|---|
| Web | `cd web && ./run ci` | 383 pruebas, 1.760 aserciones, Pint limpio |
| App | `cd app && ./gradlew :app:testDebugUnitTest` | 110 pruebas (109 + 1 e2e opt-in) |
| Firmware | `cd firmware && pio test -e native` | 160 pruebas nativas |
| Firmware e2e | `./firmware/scripts/e2e_web.sh` | 13/13 casos, `RESULTADO: E2E OK` |

La app no incluye pruebas instrumentadas ejecutadas en CI (requieren un
dispositivo con NFC); el APK y las pruebas JVM sí se compilan y pasan. La
verificación de banco con lector y estación real está descrita en
`firmware/docs/ESTACION.md`.
