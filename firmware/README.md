# Firmware de la estación EcoStation

Firmware de la estación de reciclaje sobre **ESP32-CAM AI-Thinker (OV3660)**
con cámara, lector **RC522** y conexión Wi-Fi. La estación resuelve la
identidad de una tarjeta o de un teléfono, captura la botella y habla con
el servidor (`web/`) por HTTP firmado.

## Qué hace

Dos flujos, una sola transacción:

- **Tarjeta primero** — el estudiante toca su tarjeta o teléfono, la
  estación registra el evento y, si el servidor responde
  `awaiting_classification`, toma la foto automáticamente **5 s después**
  (o de inmediato con ENTER o el botón) y la clasifica para ese evento.
- **Botella primero** — el operador coloca la botella y presiona ENTER o
  el botón; la imagen se sube sin tarjeta y queda `awaiting_card`. Una
  tarjeta tocada dentro de los **90 s** siguientes asocia esa captura.

Además:

- **Emparejamiento** — con una sesión armada por un admin en `web/`, se
  cambia a modo EMPAREJAR con la contraseña de consola y se acerca una
  tarjeta nueva (o un teléfono con «Vincular este teléfono» abierto).
- **Teléfonos Android (HCE)** — el teléfono se presenta como objetivo
  ISO-DEP; la estación retransmite la prueba del teléfono y no guarda
  ninguna llave HCE. Ver [docs/HCE_PROTOCOL.md](docs/HCE_PROTOCOL.md).
- **Servidor configurable** — descubrimiento mDNS de `_ecostation._tcp`,
  dirección manual por `API_BASE_URL` y comando `s <url>` que persiste en
  NVS. El visualizador web local muestra la vista en vivo y la última
  captura.

## Requisitos

- **Hardware**: ESP32-CAM AI-Thinker con OV3660, lector RC522, tarjetas
  MIFARE Classic y una fuente de 3,3 V capaz de alimentar la cámara.
  Cableado en [docs/ESTACION.md](docs/ESTACION.md).
- **Software**: [PlatformIO Core](https://platformio.org/) (`pio`).
  Los entornos y dependencias (`esp32-camera`, `MFRC522`, `ArduinoJson`)
  los resuelve PlatformIO; no hay que instalar nada más.
- **Servidor** (opcional para compilar): la app `web/` del monorepo, con
  la misma red Wi-Fi y una clave de estación (`READER_API_KEY`).

## Inicio rápido

```bash
cd firmware
cp include/secrets.h.example include/secrets.h   # completa Wi-Fi y clave
pio run -e esp32cam -t upload                    # sin placa: omite -t upload
pio device monitor -e esp32cam
pio test -e native                               # pruebas de host, sin placa
```

`secrets.h` es local y está en `.gitignore`: **nunca se commitea**. Si no
existe, el build compila con `secrets.h.example` y avisa por `#warning`.

## Verificación E2E contra `web/` (sin placa)

`scripts/e2e_web.sh` habla con la app real de `web/` usando las cargas y la
firma del propio firmware. Crea una base SQLite desechable, siembra el
`DemoSeeder`, arranca `artisan serve` en el puerto 8177 y recorre 13 casos
firmados: tap conocido, clasificación, tarjeta desconocida, clave
equivocada, nonce repetido, captura «botella primero», asociación (y su
repetición) y los cinco de emparejamiento. Cada respuesta real pasa por el
`ResponseParser` del firmware; el guion termina con `RESULTADO: E2E OK`
solo si los 13 veredictos son correctos.

```bash
cd firmware
pio test -e native                        # deja ArduinoJson en .pio/libdeps/native
WEB=../web ./scripts/e2e_web.sh           # o WEB=/ruta/a/web
E2E_PORT=8188 ./scripts/e2e_web.sh        # otro puerto
```

Requisitos: `g++`, `curl` y PHP con GD. No lee ni modifica `web/.env`:
todo va por entorno (`APP_KEY`, `DB_*`, `CACHE_STORE=database`,
`RECYCLING_CLASSIFIER_DRIVER=stub`, `PAIRING_WINDOW_SECONDS=45`). Si `web/`
no existe, sale con código 2 y `web/ no encontrado`; si le faltan
dependencias, con `web/ sin dependencias: ejecuta ./run setup en web/`.

Las tres herramientas de `tools/e2e/` se compilan con `g++ -std=gnu++17`
contra `lib/StationCore`:

- `device_request` — arma UNA petición firmada e imprime por stdout el
  `Authorization` y el `Content-Type`; el cuerpo va a un fichero.
  Modos `json`, `capture` y `classify`, con `--nonce <hex>` opcional.
- `build_payloads` — escribe los cuerpos JSON exactos del dispositivo
  (tap, emparejar y asociar).
- `verify_responses` — juzga las respuestas reales con el parser del
  firmware (`tap`, `pair`, `capture` y `status`) y sale 0 solo si todas
  pasan.

## Estructura

```text
firmware/
├── include/
│   ├── config.h              # compilación + placa
│   ├── config/common.h       # tiempos y ventanas
│   ├── config/esp32cam.h     # pines de la estación
│   └── secrets.h.example     # plantilla (el real no se versiona)
├── lib/
│   ├── StationCore/          # lógica pura, probada en el host
│   ├── ApiClient/            # HTTP firmado (Pulse-HMAC)
│   ├── WifiService/          # conexión y reconexión no bloqueante
│   ├── Discovery/            # DNS-SD _ecostation._tcp
│   ├── Feedback/             # LED de estado
│   └── NfcReader/            # RC522 + retransmisión HCE + ENROLL
├── src/
│   ├── main.cpp              # setup()/loop()
│   └── station.{h,cpp}       # raíz de composición de la estación
├── test/                     # suite Unity del entorno native
├── tools/e2e/                # device_request, build_payloads, verify_responses
└── scripts/e2e_web.sh        # e2e firmado contra web/ (13 casos)
```

`lib/StationCore` no incluye cabeceras de Arduino: HCE, firma, payloads,
parsers, modos, consola, antirrebote, patrones LED, disparador y
`StationFlow` se compilan y prueban en el host con `pio test -e native`.

## Seguridad

- `secrets.h` está ignorado por git; solo se versiona la plantilla con
  valores de marcador.
- La estación **no envía su clave**: cada petición se firma con
  **Pulse-HMAC** (esquema `Pulse-HMAC <kid>:<nonce>:<firma>`), con un
  nonce nuevo por petición. El nombre `Pulse-HMAC` se conserva a propósito
  porque es una constante del protocolo en el cable.
- `READER_API_KEY` es la identidad de la estación y también envuelve la
  llave de un teléfono durante el emparejamiento. Rótala en el servidor si
  un equipo se compromete.
- La contraseña de modo es una compuerta de operador por consola física,
  no criptografía: emparejar siempre exige una sesión armada por un admin.
