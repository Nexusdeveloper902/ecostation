# EcoStation — estado de implementación

Fecha: 2026-10-23. Rama de integración: `main`.

## Resumen

| Pista | Plan | Estado | Verificación |
|---|---|---|---|
| Web (`web/`) | Tasks 1–20 | Completa, incluida la piel visual | `./run ci`: 382 pruebas, 1.758 aserciones, Pint limpio |
| App (`app/`) | Tasks 1–9 | Completa, incluida la piel Boti | 110 pruebas JVM (109 + 1 e2e opt-in), `assembleDebug`, `assembleDebugAndroidTest`, `lintDebug` |
| Firmware (`firmware/`) | Tasks 1–10 | Completa | 160 pruebas nativas, `pio run -e esp32cam` SUCCESS, e2e 13/13 |
| Diseño (`design/`) | Penpot | Concepto B · Boti aplicado | `DESIGN-SPEC.md` + exportes PNG |

El e2e firmado se ejecutó contra `web/` en `main`: `RESULTADO: E2E OK`
(13/13 casos: tap, classify, captura, asociación, pairing, HMAC, replay).

## Decisiones de implementación que difieren del plan

Se tomaron para cerrar hallazgos de seguridad/integridad detectados en las
revisiones. Todas están cubiertas por pruebas.

- **Desemparejar revoca, no borra.** `DELETE /admin/cards/{card}` deja la fila
  en `revoked` y elimina solo la llave HCE; el historial, los depósitos y los
  puntos se conservan. Una tarjeta revocada puede reactivarse **solo para el
  mismo estudiante**.
- **Borrar una estación con historial se rechaza** (422 con mensaje en
  español); se ofrece rotar la clave. Una estación sin eventos sí se puede
  borrar.
- **Ranking del estudiante por curso.** La vista web usa el puesto dentro del
  curso (como el tablero de Penpot), con selector “Mi curso / Todo el colegio”.
  La API `/me/balance` mantiene el `rank` global del contrato original.
- **Contraseñas iniciales aleatorias** por estudiante (12 caracteres) y
  `must_change_password=true`; el `DemoSeeder` se niega a correr en producción
  salvo `ECOSTATION_DEMO=1`.
- **App: solo LAN.** Se aceptan IPv4 privadas y `*.local`/`*.lan` sobre HTTP;
  nunca hosts públicos, loopback ni `https` (TLS queda para un proxy inverso).
- **App: vinculación segura.** Abrir la ventana ya no reemplaza la llave; la
  nueva llave se adopta antes de responder `ENROLL` y la anterior sigue
  funcionando si se cancela. “LLAVE ENVIADA” confirma que el lector recibió la
  llave, no que el backend la aceptó; “Vincular de nuevo” siempre está
  disponible.
- **Firmware: validación estricta de la dirección manual** (IPv4 privada o
  nombre con al menos dos etiquetas; rechaza localhost, userinfo, puertos
  fuera de rango y esquemas no HTTP).
- **`./run serve` escucha en loopback** por defecto; `ECOSTATION_LAN=1` lo abre
  a la LAN. `APP_DEBUG` se activa solo con `ECOSTATION_DEBUG=1` fuera de local.

## Datos de demostración

`migrate --seed` crea cuentas, cursos, estudiantes y una estación. Para poblar
los paneles con depósitos, puntos, ranking y feed:

```bash
cd web && php artisan db:seed --class=DemoActivitySeeder
```

Es opcional, determinista e idempotente. No crea fotos. Las cuentas demo están
en el `README.md`; todas usan `password` en `local`/`testing`.

## Pendiente / límites conocidos

- **DeepSeek en vivo:** no se ejecutó `php artisan llm-check` porque la clave
  real se añadirá manualmente. El driver, el reintento, el 402 y el comando
  están cubiertos con `Http::fake`; el driver `stub` es el predeterminado.
- **Sin dispositivo Android ni ESP32 conectados.** No se ejecutaron pruebas
  instrumentadas, NFC real, cámara ni flasheo. El APK compila y las pruebas JVM
  pasan; el firmware compila y sus pruebas nativas/e2e pasan. La verificación
  de banco está en `firmware/docs/ESTACION.md`.
- **TLS / autenticidad de mDNS:** v1 es HTTP en una LAN de confianza. La app
  exige el manifiesto de EcoStation y fija el servidor tras el primer inicio de
  sesión, pero el primer contacto no es criptográficamente autenticado. Un
  proxy TLS con `REALTIME_PUBLIC_URL=wss://…` es el camino de producción.
- **Firma de release Android:** la llave debe vivir fuera del repositorio; sin
  ella `assembleRelease` y `bundleRelease` fallan a propósito.
- **Worktrees:** `.worktrees/{web,app,firmware}` quedaron en sus ramas de
  trabajo. `main` es la fuente de verdad integrada; los worktrees son
  descartables.

## Artefactos de QA

Las capturas de la verificación visual están en `qa/` (ignorado por git):
login, estudiante (escritorio y 390 px), historial, ranking, docente, clase,
detalle de estudiante, exportar, en vivo, admin (estudiantes, cursos,
vinculación, estaciones, personal, ajustes), estación y PDF.

## Cómo reproducir la verificación

```bash
# Web
cd web && ./run ci

# App
cd app && ./gradlew :app:testDebugUnitTest :app:assembleDebug :app:lintDebug

# Firmware
cd firmware && pio test -e native && pio run -e esp32cam
cd .. && ./firmware/scripts/e2e_web.sh
```
