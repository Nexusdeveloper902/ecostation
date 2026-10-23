# La estación EcoStation — guía de operación y banco

La estación completa de reciclaje en **un solo dispositivo**: ESP32-CAM
AI-Thinker (OV3660) con cámara + lector RC522. Resuelve la identidad de
tarjetas y teléfonos por el endpoint de tap, captura la botella y habla
con el servidor (`web/`) por HTTP firmado.

Este documento cubre la estación. El protocolo de teléfonos está en
[HCE_PROTOCOL.md](HCE_PROTOCOL.md) y el arranque del proyecto en
[../README.md](../README.md).

---

## 1. Cableado (autoritativo, verificado en banco)

| Pin RC522 | GPIO ESP32-CAM | Nota |
|---|---|---|
| SDA (SS) | **13** | selección SPI |
| SCK | **14** | reloj SPI (pin CLK de la SD, sin usar) |
| MOSI | **15** | MOSI SPI (pin CMD de la SD, sin usar) |
| MISO | **2** | MISO SPI (pin DATA0 de la SD, sin usar) |
| RST | **3V3** | reset a HIGH; solo soft reset (`PIN_RC522_RST -1`). GPIO4 es el LED de flash: no tocarlo |
| 3.3V / VCC | 3V3 | nunca 5 V |
| GND | GND | tierra común |

Reservado / prohibido:

- **GPIO16/17 = PSRAM** — nunca el RST del RC522.
- **GPIO4 = LED de flash integrado** — nunca manejarlo.
- **GPIO12 = botón a GND solamente** (strapping MTDI: nunca 3V3).
- **Zumbador ausente** (`PIN_CAM_BUZZER -1`: no hay pin libre).
- **SD intencionalmente sin usar** — nunca iniciar `SD_MMC`/`SD`: esos
  pines son el bus SPI del RC522.

LED único de estado: rojo en **GPIO33**, activo-LOW. En placas clon con
polaridad invertida el LED queda fijo encendido: pon
`PIN_STATION_LED_ACTIVE_LOW` en `0` en `include/config/esp32cam.h`.
GPIO25/26 son VSYNC/SIOD de la cámara, no LEDs.

---

## 2. Provisionamiento de `secrets.h`

```bash
cd firmware
cp include/secrets.h.example include/secrets.h
```

Completa:

| Macro | Qué es |
|---|---|
| `WIFI_SSID` / `WIFI_PASSWORD` | la red del colegio |
| `API_BASE_URL` | dirección LAN del servidor, p. ej. `http://192.168.1.50:8000`. Nunca `localhost` ni `127.0.0.1`: en la ESP32 eso es la propia placa |
| `MDNS_DISCOVERY` | `1` = buscar el servidor por mDNS; `0` = solo la dirección manual (redes escolares que bloquean multicast) |
| `READER_API_KEY` | clave de la estación en el servidor. **Es la identidad del equipo y también envuelve la llave de un teléfono al emparejar** |
| `MODE_PASSWORD` | contraseña de la consola de modos. No debe parecerse a un comando (`c`, `a …`, `e …`, `s …`) |

`secrets.h` está en `.gitignore` y **nunca se versiona**. El único archivo
versionado es la plantilla con marcadores. Si falta, el build usa la
plantilla y avisa con un `#warning`.

Después:

```bash
pio run -e esp32cam -t upload
pio device monitor -e esp32cam
```

`monitor_dtr = 0` y `monitor_rts = 0` ya están en `platformio.ini`:
**consérvalos**, porque el circuito auto-download del AI-Thinker deja el
chip en mal estado si el puerto serie se abre de otra forma.

---

## 3. Dirección del servidor

Prioridad, de mayor a menor:

1. **Dirección fijada por consola** con `s <url>` — se guarda en NVS y
   sobrevive reinicios.
2. **mDNS** `_ecostation._tcp.local` — se consulta al arrancar y, si hay
   un fallo de transporte, con un intervalo mínimo de 15 s.
3. **`API_BASE_URL`** de `secrets.h` — la reserva compilada.

Al arrancar (o al ejecutar `s -`) el serial imprime la dirección elegida
y su origen:

```text
[SERVIDOR] http://192.168.1.50:8000 (origen: mDNS)
```

Comandos:

| Comando | Efecto |
|---|---|
| `s` | muestra la dirección actual y su origen (`consola`, `mDNS`, `secrets.h`) |
| `s 192.168.1.50:8000` | valida, normaliza a `http://…`, la fija y **la guarda tras reiniciar** |
| `s -` | borra la dirección guardada y vuelve a mDNS/`API_BASE_URL` |

Se acepta `http://IP:puerto`, `IP:puerto` (se añade el esquema) o un
nombre DNS/mDNS con al menos dos etiquetas, por ejemplo
`servidor.local:8000`. Se rechazan `https://`, `localhost` (también en
mayúsculas), `127.*`, `0.0.*`, `0`, `0.0.0.0`, nombres de una sola
etiqueta (`basura`), credenciales (`usuario:clave@IP`), espacios,
corchetes IPv6, puertos fuera de rango y direcciones de más de 64
caracteres. Una ruta pegada tras el puerto se descarta al normalizar. Una
dirección fijada por consola **nunca** es sobrescrita por mDNS. Con
`MDNS_DISCOVERY 0` la estación solo usa la dirección manual o
`API_BASE_URL`.

Límite efectivo al teclear: el búfer de la línea serie es de **64
caracteres e incluye el prefijo `s `**, así que la URL tecleada más larga
es de **62 caracteres**. Una línea más larga se descarta entera (no se
trunca); el validador admite hasta 64 por otras vías.

---

## 4. Flujo A — tarjeta primero

1. El estudiante acerca una tarjeta emparejada (o un teléfono vinculado).
2. El serial muestra:

   ```text
   [NFC] tarjeta: <credential_uid>
   [OK] evento registrado — <nombre> (RECYCLING_DEPOSIT)
   [i] siguiente paso: awaiting_classification
   [STATION] tarjeta-primero armado para el evento <id> — auto-captura en 5s (BOTÓN/ENTER ya)
   ```

3. Deja la botella a la vista. La estación toma la foto sola a los **5 s**
   (`CARD_FIRST_AUTO_CAPTURE_DELAY_MS`) y la sube como clasificación de
   ese evento:

   ```text
   [STATION] clasificación tarjeta-primero para el evento <id>...
   [STATION] clasificación: HTTP 200
   ```

4. ENTER o el botón capturan de inmediato, sin esperar los 5 s.
5. Si la subida falla (Wi-Fi o 5xx), el brazo **se conserva** hasta 90 s:
   ENTER o el botón reintentan la clasificación. La foto automática no se
   repite sola.

Si el estudiante vuelve a tocar la misma tarjeta mientras el evento sigue
armado, no se rearma ni se dispara una segunda foto: el backend deduplica
al mismo `event_id`.

---

## 5. Flujo B — botella primero

1. El operador coloca la botella y presiona **ENTER** (o el botón de
   GPIO12).
2. La estación captura y sube la imagen sola:

   ```text
   [STATION] captura botella-primero: subiendo imagen (sin tarjeta aún)...
   [STATION] captura: HTTP 200
   [STATION] captura <id> en el servidor — toca una tarjeta dentro de 90s (o escribe 'a <credential_uid>')
   ```

3. El estudiante toca su tarjeta dentro de los **90 s**
   (`PENDING_CAPTURE_TIMEOUT_MS`). El toque **asocia** esa captura y no
   crea un evento nuevo:

   ```text
   [STATION] el toque cierra la captura pendiente <id> — asociando, sin evento nuevo
   [STATION] asociación: HTTP 200
   ```

4. Alternativa sin toque físico: `a <credential_uid>` en la consola.
5. Si la ventana expira, el serial lo avisa y el siguiente toque vuelve a
   ser un tap normal (tarjeta primero); nunca asocia una captura vieja.
6. Si un 404 significa «tarjeta desconocida» (no «captura ausente»), la
   captura pendiente se conserva para que el operador toque la tarjeta
   correcta.

---

## 6. Emparejamiento

Emparejar son dos pasos, en este orden:

1. **Un admin arma una sesión** en el panel de `web/` para un estudiante
   concreto. La ventana la configura el servidor (45 s por defecto).
2. **La estación entra en modo EMPAREJAR**: escribe `MODE_PASSWORD` +
   Enter en el Monitor Serial (los caracteres se muestran como `*`).

   ```text
   [MODE] cambiado a: EMPAREJAR
   [MODE] arma primero una sesión en la web (admin),
        luego acerca una tarjeta NUEVA o el teléfono con «Vincular este teléfono» abierto
   ```

3. Acerca una **tarjeta nueva** dentro de la ventana:

   ```text
   [OK] tarjeta emparejada con: <nombre del estudiante>
   ```

4. **Teléfono**: en la app abre «Vincular este teléfono» (ventana de
   entrega de un solo uso) y sostén el teléfono quieto 1–2 s sobre la
   antena. La estación pide la llave (ENROLL), la envuelve con
   `READER_API_KEY` y la envía al servidor; la llave cruda se borra de la
   RAM y nunca se registra.
5. Vuelve a OPERACIÓN con la contraseña de modo. Cada sesión armada es de
   un solo uso; para el siguiente estudiante se arma otra.

Resultados:

| Serial | HTTP | Significado |
|---|---|---|
| `[OK] tarjeta emparejada con: <nombre>` | 200 | tarjeta creada y vinculada; sesión consumida |
| `[409] …` + «arma primero una sesión…» | 409 | nadie armó, la ventana expiró o ya se consumió |
| `[422] …` + «usa una tarjeta NUEVA…» | 422 | ese UID ya tiene fila en `cards`; **no** se reasigna en silencio |
| `[403] …` + «en el teléfono toca «Vincular este teléfono»…» | 403 | la prueba del teléfono no verificó con la llave entregada |
| `[401] clave de lector rechazada` | 401 | `READER_API_KEY` no tiene fila de lector en el servidor |
| `[NET] fallo de red` | transporte | Wi-Fi o servidor inalcanzable; la estación reintenta |

Teléfono sin llave en modo OPERACIÓN (status word `6A88`):

```text
[NFC] HCE 6A88: el teléfono no tiene llave: en la app toca «Vincular este teléfono» y empareja en modo EMPAREJAR
```

---

## 7. Comandos de consola serial

La consola comparte la línea con el disparador de captura. El eco es
enmascarado (`*`) y nunca imprime lo tecleado.

| Entrada | Acción |
|---|---|
| `ENTER` (línea vacía) | captura + subida (botella-primero si nada está armado) |
| **Botón GPIO12** | igual que ENTER (una foto por pulsación; mantenerlo no repite) |
| `a <credential_uid>` | asocia la última captura pendiente con esa tarjeta |
| `e <event_id>` | arma la clasificación tarjeta-primero para ese evento |
| `c` | captura local, sin subir (desarrollo y visualizador) |
| `s` | muestra la dirección actual del servidor y su origen |
| `s <url>` | fija y guarda la dirección del servidor |
| `s -` | borra la dirección guardada y vuelve a mDNS/`secrets.h` |
| `MODE_PASSWORD` + Enter | alterna OPERACIÓN ↔ EMPAREJAR |

Un par CRLF cuenta como un solo Enter. Tres contraseñas incorrectas
bloquean la consola 10 s (`MODE_CONSOLE_LOCKOUT_MS`). Una línea con forma
de comando (`c`, `a …`, `e …`, `s …`) nunca se interpreta como
contraseña.

---

## 8. Patrones de LED

La estación tiene un solo LED rojo (GPIO33, activo-LOW) que lleva los dos
canales por precedencia: un evento de un solo disparo interrumpe el
estado continuo, que se reanuda al terminar.

| Canal | Estado / evento | Patrón |
|---|---|---|
| Modo | OPERACIÓN | 100 ms encendido cada 2000 ms (latido simple) |
| Modo | EMPAREJAR | 100 encendido, 100 apagado, 100 encendido, 1700 apagado (doble cada 2 s) |
| Modo | conectando | 100 encendido / 100 apagado (parpadeo rápido) |
| Modo | degradado (cámara/NFC/red caídos) | triple destello cada 2 s |
| Evento | éxito | sólido 1500 ms |
| Evento | toque rechazado (404) | 2 × (200 encendido / 200 apagado) |
| Evento | sin sesión (409) | 3 × (200 / 200) |
| Evento | tarjeta ya emparejada (422) | 4 × (200 / 200) |
| Evento | fallo de red | 5 × (120 / 120) |
| Evento | clave rechazada (401) | 6 × (120 / 120) |
| Evento | error del servidor | sólido 2000 ms |
| Evento | modo cambiado | 2 × (500 encendido / 250 apagado) |
| Evento | contraseña incorrecta | 2 × (80 / 80) |

El LED descansa **apagado** entre destellos. Si queda fijo encendido,
mira la polaridad (`PIN_STATION_LED_ACTIVE_LOW 0`) antes que el cableado.

---

## 9. Lista de verificación de banco (solo estación)

Preparación: servidor sembrado y sirviendo en la LAN, `secrets.h`
completo, estación flasheada y Monitor Serial abierto.

**Arranque**

- [ ] 9.1 El banner muestra `ECOSTATION — ESTACIÓN ESP32-CAM`, la
      compilación (`eco.1`) y una dirección en `[SERVIDOR] … (origen: …)`.
- [ ] 9.2 `[WiFi] conectado — IP: <ip>` en ≤ 15 s; si no, sigue
      reintentando sin colgarse.
- [ ] 9.3 LED de modo en latido simple; modo `OPERACION`.

**Flujo A — tarjeta primero**

- [ ] 9.4 Toca una tarjeta sembrada: `[OK] evento registrado — …` y
      `awaiting_classification`.
- [ ] 9.5 Deja la botella: la foto sale sola a los ~5 s y la
      clasificación responde 200.
- [ ] 9.6 Repite tocando y usando ENTER de inmediato: captura sin esperar.
- [ ] 9.7 Vuelve a tocar la MISMA tarjeta con el evento armado: no hay
      rearme ni segunda foto automática.
- [ ] 9.8 Con el Wi-Fi caído, fuerza una clasificación: el brazo se
      conserva; al volver la red, ENTER reintenta y el 200 lo libera.

**Flujo B — botella primero**

- [ ] 9.9 ENTER sin nada armado: sube la captura y muestra «toca una
      tarjeta dentro de 90s».
- [ ] 9.10 Toca la tarjeta: asocia y **no** crea un evento nuevo.
- [ ] 9.11 Deja pasar más de 90 s antes del toque: la captura expira y el
      toque arranca un tarjeta-primero normal.
- [ ] 9.12 `a <credential_uid>` con una tarjeta desconocida: la captura
      pendiente se conserva.

**Emparejamiento**

- [ ] 9.13 Contraseña de modo → `[MODE] cambiado a: EMPAREJAR`; LED de
      doble destello.
- [ ] 9.14 Sin sesión armada, toca una tarjeta: `[409]` + guía.
- [ ] 9.15 Arma en el panel y toca una tarjeta NUEVA: `[OK] tarjeta
      emparejada con: …`; la sesión queda consumida.
- [ ] 9.16 La misma tarjeta con otra sesión armada: `[422]`, la sesión
      sigue armada.
- [ ] 9.17 Teléfono sin vincular en OPERACIÓN: `6A88` y la guía de
      «Vincular este teléfono»; ninguna petición al servidor.
- [ ] 9.18 Teléfono con «Vincular este teléfono» abierto en EMPAREJAR:
      `[OK] tarjeta emparejada con: …` y el log **no** muestra la llave.
- [ ] 9.19 Tres contraseñas incorrectas: consola bloqueada 10 s; la
      contraseña correcta se rechaza hasta que expira.

**Servidor y resiliencia**

- [ ] 9.20 `s` muestra la dirección y el origen; `s <url>` la fija y
      sobrevive un reinicio; `s -` la borra y vuelve a mDNS.
- [ ] 9.21 Una dirección inválida (`localhost`, `https://…`, basura) se
      rechaza y no cambia la actual.
- [ ] 9.22 Apaga el AP ~30 s: el LED sigue latiendo, sin reinicios; al
      volver, se reconecta y el siguiente toque funciona.
- [ ] 9.23 Tres ciclos de energía: cada arranque llega al reposo en menos
      de 20 s.

Registra fecha, compilación y CUMPLE/FALLA por punto. Los agentes no
tienen hardware: esta lista se completa en banco.
