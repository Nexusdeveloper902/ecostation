# Protocolo de credencial HCE de EcoStation (v1.1 — llaves por credencial)

> Los literales pulse-hce-key-wrap/v1, Pulse-HMAC y el vector de prueba conservan el nombre Pulse a propósito: son constantes del protocolo en el cable.

> Referencia canónica a nivel de bytes para la ruta teléfono-como-credencial.
> Lado teléfono: `app/` (`ApduProtocol`).
> Lado lector: `lib/StationCore` (`HceProtocol`) + `lib/NfcReader`
> (rama HCE de `Rc522NfcReader`). Contrato backend: `web/`
> (`docs/API.es.md`) §“Credenciales HCE de Android”. Adaptado del prototipo
> standalone verificado (`RC522-Android/`, `PROTOCOL.md` allí) — los bytes
> del protocolo no cambiaron; lo que cambió es la integración alrededor
> (kind de credencial, endpoints EcoStation, UI del escritorio).

Teléfono-como-credencial sobre NFC para ESP32 + RC522. Deliberadamente
mínimo: dos APDUs por toque, más un tercero (ENROLL) una sola vez al
emparejar.

> **v1.1 (TASK-015, ADR-018; `web/` ADR-068; `app/` ADR-004).** Los
> bytes de SELECT y CHALLENGE **no cambian**. Lo que cambió es la llave:
> **ya no existe un `HCE_SECRET` compartido** en ningún lado. Cada
> credencial de teléfono tiene su propia llave de 256 bits, guardada en el
> Android Keystore de ese teléfono; `web/` guarda la única otra copia,
> cifrada en reposo. El **lector no tiene llave HCE ni verifica nada**:
> retransmite su nonce y el MAC del teléfono, y **`web/` verifica** con
> la llave de esa credencial. Los cambios aditivos son el APDU `ENROLL`
> (solo al emparejar) y el status word `6A88` (teléfono sin llave).

```text
NFC-A
  ↓  anticolisión + SELECT (bit 6 del SAK => soporta T=CL)
ISO-DEP (ISO/IEC 14443-4)
  ↓  RATS / ATS (+ PPS), I-blocks vía MFRC522Extended::TCL_Transceive
APDU
  ↓  SELECT AID -> CHALLENGE -> verificación
Protocolo de credencial EcoStation (identidad a nivel de aplicación)
  ↓  credential_uid + hce_nonce + hce_mac (+ llave envuelta al emparejar) → web/
web/
  ↓  ¿HMAC-SHA256(K_cred, credId || nonce) == hce_mac? (tiempo constante, nonce de un solo uso)
```

Nada de MIFARE Classic en esta ruta: Android HCE no puede emularlo, así
que el teléfono se presenta como objetivo ISO-DEP. Las tarjetas físicas
conservan su propia ruta intacta (UID RF → sin `credential_kind`).

## Capa RF

| Capa | Detalle |
|---|---|
| RF | NFC-A, 106 kbit/s (PPS negocia ≤ 212 kbit/s solo si el teléfono lo ofrece) |
| Anticolisión | SELECT en cascada estándar; SAK `0x20` = objetivo ISO-DEP (HCE) |
| Activación | RATS (`E0 50` + CRC_A, FSD = 64, CID = 0) → ATS; PPS solo si ATS/TA1 presente |
| Tramas | I-blocks ISO-DEP, CID = 0, sin NAD; el lector confirma el encadenado teléfono→lector |
| Límites | FIFO del RC522 = 64 B, así un I-block lleva ≤ ~57 B de APDU |
| Driver | `MFRC522Extended` (misma librería `miguelbalboa/MFRC522`, sin dependencia extra): el override de `PICC_Select()` hace anticolisión + SELECT + RATS/ATS + PPS cuando el bit 6 del SAK señala T=CL; `TCL_Transceive(tag, apdu, len, resp, &respLen)` hace I-blocks con alternancia de número de bloque, manejo de CID y confirmación de respuestas encadenadas. RATS propio (`requestAtsSafe`): el `PICC_RequestATS` de 1.4.12 copia `bufferSize - 2` aunque la transcepción falle; con una respuesta parcial/CRC mala `bufferSize` es 0–1, el byte resta 254 y desborda `Ats::data` (reinicio `TG1WDT_SYS_RESET` justo tras «lanzando RATS ahora»). El reemplazo no toca el ATS si falla y rechaza tramas que no sean un ATS (p. ej. S(WTX) con CRC válida). Límites conocidos (documentados, no remediados): FSD fijo en 64, CID fijo en 0, sin NAD, sin WTX por S-block (el teléfono responde en ms — HCE lo hace), bitrates sobre 212 kbit/s no intentados. |

## Capa de aplicación

- AID de EcoStation: **`F0010203040506`** (7 bytes, categoría propietaria
  `F…` — válido, consistente en ambos lados, no colisiona con nada
  productivo).
- Id de credencial: ASCII de longitud variable (prototipo:
  `TEST-ANDROID-001`, 16 bytes, público, no secreto). El lector acepta
  1–32 bytes.
- **El UID NFC del teléfono es aleatorio por toque en Android HCE** — el
  lector solo registra su longitud (prueba de descubrimiento) y JAMÁS lo
  usa como identidad. El backend jamás lo recibe: no hay columna
  `rf_uid`, no hay entrada `rf_uid`, y la búsqueda del tap es solo por
  `credential_uid`.

## APDU 1 — SELECT AID

Petición (12 bytes, sin `Le`):

```text
CLA | INS | P1 | P2 | Lc | AID (7 bytes)
 00 | A4  | 04 | 00 | 07 | F0 01 02 03 04 05 06
```

o sea `00 A4 04 00 07 F0010203040506`. Un `Le = 00` final se acepta y se
ignora.

Respuesta, AID coincidente:

```text
48 43 45 2D 4F 4B ("HCE-OK") | 90 00
```

Respuesta, AID no coincidente: `6A 82` (app no encontrada). La sesión
pasa a "seleccionada" solo al coincidir; se reinicia al desactivarse RF.

## APDU 2 — CHALLENGE (challenge-response)

Petición (13 bytes, sin `Le`; `Le = 00` final aceptado):

```text
CLA | INS | P1 | P2 | Lc | nonce (8 bytes, esp_random fresco por toque)
 80 | 10  | 00 | 00 | 08 | NN NN NN NN NN NN NN NN
```

Respuesta:

```text
credLen (1) | credId (1-32, ASCII) | HMAC-SHA256 (32) | 90 00
MAC = HMAC-SHA256(key = K_cred, msg = credId || nonce)
```

`K_cred` es **la llave propia de esta credencial** (32 bytes, Android
Keystore en el teléfono). Un teléfono sin llave utilizable responde
**`6A88`** en vez de un MAC (aún no vinculado, o una reinstalación borró
el Keystore).

El lector **no** verifica el MAC — no tiene llave. Envía a `web/` el id
de credencial, su propio nonce (`hce_nonce`, 16 hex) y el MAC del
teléfono (`hce_mac`, 64 hex); el backend recomputa el MAC con la llave
guardada de esa credencial, compara en tiempo constante y acepta cada
nonce una sola vez por credencial. Nonce y MAC pueden registrarse; el
material de llave jamás. Todo mismatch falla cerrado en el backend (`403`).

## APDU 3 — ENROLL (solo al emparejar, entrega única de la llave)

Lo envía un lector **solo en modo EMPAREJAR**, justo después de CHALLENGE
en la misma sesión. Petición (5 bytes; también se acepta la cabecera de
4 bytes sin `Le`; un campo de datos es `6700`):

```text
CLA | INS | P1 | P2 | Le
 80 | 20  | 00 | 00 | 20
```

Respuesta, solo mientras el titular abrió la **ventana de vinculación**
del teléfono («Vincular este teléfono», 60 s), y **una sola vez** por
ventana:

```text
K_cred (32) | 90 00          (34 bytes: cabe en un I-block del RC522)
```

Si no, `6985` (ventana cerrada, vencida, ya entregada o sin SELECT). Abrir
la ventana **reemplaza** primero la llave en el Keystore, así que una
copia leída por otro queda inútil en cuanto el titular reintenta.

El lector nunca envía `K_cred` en claro por Wi-Fi. La envuelve con un
nonce fresco de 16 bytes y su propia clave de API:

```text
hce_key_nonce   = 32 caracteres hex (esp_random, fresco por emparejamiento)
pad             = HMAC-SHA256(READER_API_KEY,
                    "pulse-hce-key-wrap/v1\n" || credId || "\n" || hce_key_nonce)
hce_key_wrapped = hex(K_cred XOR pad)
```

La petición de emparejamiento además va firmada con Pulse-HMAC (ADR-016:
atada al cuerpo, nonce de un solo uso). `web/` desenvuelve la llave,
exige que la prueba CHALLENGE del mismo toque verifique con ella (prueba
de posesión) y la guarda cifrada. La llave cruda se borra de la RAM del
lector justo después de envolverla y jamás se registra (la ruta de
reintento censura las tramas ENROLL).

## Vector de prueba compartido (fijado en los tres repos)

| Ítem | Valor |
|---|---|
| `K_cred` | `000102…1f` (bytes 0..31) |
| `credId` | `PLS-K3Y7V3CT0R5Z` |
| `nonce` | `0123456789abcdef` |
| `MAC` | `ba6d0fbf5106f79257727819d19a17b7e15abc4b8a0d1bfe71cd76090488a295` |
| Respuesta CHALLENGE | `10` `504c532d4b335937563343543052355a` `ba6d…a295` `9000` |
| `READER_API_KEY` | `test-secret-000000000000000001` |
| `hce_key_nonce` | `000102030405060708090a0b0c0d0e0f` |
| `hce_key_wrapped` | `c5bb9fe15dff66a8636366dd4e73e0a3146601e3b3c36472961de142a9a914c2` |

Calculado fuera de las tres implementaciones (`hmac` de Python); fijado
en `test/test_hce_protocol.cpp` aquí, `HceCredentialAuthTest` en `web/`
y `ApduProtocolTest` en `app/`. Cada suite fija además
vectores HMAC conocidos del RFC 4231.

## Integración EcoStation (lo que el prototipo no tenía)

| Paso | Comportamiento |
|---|---|
| Lector → backend (emparejar) | `POST /api/v1/admin/cards/pair` con `{"credential_uid", "credential_kind": "hce", "hce_nonce", "hce_mac", "hce_key_nonce", "hce_key_wrapped"}` — mismo flujo arma-then-pair y semántica 409/422 que las tarjetas físicas; `403` = la prueba no verificó con la llave entregada; un teléfono activo del **mismo** estudiante armado recibe llave nueva (`"rekeyed": true`) |
| Lector → backend (tap) | `POST /api/v1/events/tap` con `{"credential_uid", "hce_nonce", "hce_mac"}` — el kind NO se envía (vive en el servidor); una tarjeta de teléfono sin prueba válida y fresca responde `403 hce_auth_failed`. Mismos campos en `/recycling/captures/{id}/associate` |
| Almacenamiento | `cards.kind` (`physical` \| `hce`) + `hce_credential_keys` (una llave cifrada por tarjeta de teléfono); la revocación (`POST /admin/cards/{id}/revoke`) destruye la llave |
| UI del escritorio | Credenciales de teléfono marcadas (“Phone” / “Teléfono”) en chips del roster, historial reciente (filas SSR + en vivo por WS) y escritorio de estudiantes |
| Provisionamiento de clave | Por credencial, al emparejar: el titular abre «Vincular este teléfono», el lector (modo EMPAREJAR) envía ENROLL y envuelve la llave con `READER_API_KEY`. **No existe llave HCE en ningún secrets, flag de compilación ni código.** Un secrets viejo que aún defina `HCE_SECRET` se ignora |

## Status words

| SW | Significado | Cuándo |
|---|---|---|
| `9000` | Éxito | SELECT coincidente / CHALLENGE respondido |
| `6A82` | App no encontrada | SELECT con otro AID |
| `6985` | Condiciones no satisfechas | CHALLENGE/ENROLL antes de SELECT; ENROLL sin ventana de vinculación abierta |
| `6A88` | Dato referenciado no encontrado | CHALLENGE en un teléfono sin llave utilizable (no vinculado / reinstalado) |
| `6700` | Longitud errónea | `Lc` ≠ 7 (SELECT) / ≠ 8 (CHALLENGE), ENROLL con datos, APDU truncado |
| `6D00` | INS desconocido | CLA conocido, instrucción desconocida |
| `6E00` | CLA desconocido | Primer byte no `00`/`80` |

## Longitudes máximas

| Ítem | Máx |
|---|---|
| Petición SELECT | 13 bytes (con Le opcional) |
| Respuesta SELECT | 8 bytes |
| Petición CHALLENGE | 14 bytes (con Le opcional) |
| Respuesta CHALLENGE | hasta 67 bytes (1 + 32 + 32 + 2; los ids `PLS-` usan 51) |
| Petición / respuesta ENROLL | 5 bytes / 34 bytes |
| INF de un I-block | ~57 B — la respuesta de 51 bytes del prototipo cabe; un id de 32 bytes necesita los ACKs de encadenado del lector (ya implementados en `TCL_Transceive`) |

## Condiciones de error (diagnóstico del lector)

Advertencia del driver (hallada en banco): el `PICC_Select` de MFRC522
1.4.12 parsea un buen ATS en un local de pila y jamás lo guarda en
`tag` — así `tag.ats.size` siempre es 0 por la ruta del driver y
cualquier compuerta sobre él es código muerto (esto también mordió al
prototipo standalone). Dato más profundo: una vez que este teléfono
recibe HaltA no responde nada (RATS, WUPA, ni REQA) hasta re-entrar al
campo — así que el select del driver (RATS inmediato + HaltA al timeout)
envenena el toque, y todo reintento post-halt apunta a un cadáver. Por
tanto park-and-activate: el descubrimiento usa el Select BASE
calificado (sin RATS, sin HaltA sorpresa — tramas idénticas al override
para no-ISO-DEP), un avistamiento ISO-DEP estaciona la sesión intacta
400 ms en silencio de radio, y solo entonces sale el PRIMER RATS del
toque (`adoptAts`, status registrado, ATS adoptado). Un objetivo que no
responda ese RATS cae a la ruta UID. No se negocia PPS — 106 kbit/s es
obligatorio y sobra para intercambios <70 B.

`[NFC] espera cumplida — lanzando RATS ahora` seguido de un reinicio
`TG1WDT_SYS_RESET`: era el desbordamiento de `PICC_RequestATS` descrito
en la tabla de driver; desde `requestAtsSafe` esa ruta no puede escribir
fuera del ATS. Si el reinicio reaparece, ya no es esta causa.

`[NFC] SELECT HCE falló` (sin
respuesta I-block) · `[NFC] SELECT HCE -> 6A82` (AID no enrutado) ·
`[NFC] tiempo de espera del RETO HCE` · `[NFC] HCE 6A88: el teléfono no
tiene llave…` · `[NFC] respuesta al RETO HCE mal formada` ·
`[NFC] ENROLL HCE rechazado` (emparejando: la ventana del teléfono no
está abierta). Una llave errónea ya no es un error
del lector: el toque llega al backend y responde `403` (rechazo tipo
`[404]` en el serial). Cada fallo libera el objetivo (`TCL_Deselect` +
`PICC_HaltA`) y el equipo sigue respondiendo; los toques MIFARE jamás se afectan (esa
ruta no corre para objetivos ISO-DEP, y viceversa).

## Evidencia de independencia del UID

1. **Unitaria** (`test_hce_protocol.cpp::test_hce_identity_ignores_the_rf_uid`):
   los parsers ni siquiera RECIBEN un UID RF — bytes APDU idénticos
   siempre dan la misma credencial.
2. **Backend** (`HceCredentialTest::identification_does_not_depend_on_the_rf_uid`):
   no existe columna `rf_uid`; un id no-hex (imposible como UID RF) se
   empareja y toca de punta a punta, dos veces, al mismo estudiante.
3. **Banco** (checklist §10): registra la longitud del UID RF entre
   toques (cambia), verifica que el mismo id se acepta siempre.

## Límites de seguridad (qué garantiza v1.1 y qué no)

**Garantizado ahora**
- Sin secreto HCE compartido: extraer un APK, un lector o un secrets no
  da ninguna llave que sirva para otra credencial.
- La llave de un teléfono solo autentica su propio id (el id va dentro
  del MAC y el backend busca la llave por ese id).
- Los lectores no pueden falsificar toques de teléfono: una firma de
  lector válida sin prueba válida y fresca del teléfono se rechaza (`403`).
- Las transcripciones repetidas se rechazan (memoria de nonces por
  credencial, 7 días).
- La revocación (teléfono perdido/robado) destruye la llave del backend;
  la llave del teléfono queda inútil aunque se reactive el estado a mano.
- El provisionamiento no puede sobrescribir la credencial de otro
  estudiante: solo recibe llave nueva un teléfono activo del estudiante
  que el admin armó.

**Sigue siendo cierto (límites documentados, no resueltos aquí)**
- Autenticación unilateral con nonce elegido por el lector: quien tenga
  una clave de lector y lea un teléfono a escondidas puede presentar esa
  transcripción una vez (pre-play) antes del siguiente toque legítimo. El
  backend la rechaza tras el primer uso.
- La llave cruza NFC en claro **una vez**, durante la ventana de
  vinculación abierta por el titular en la mesa (entrega única). Un
  espía en ese instante exacto podría copiarla. Mitigación: ventana corta
  y de un solo uso; volver a vincular rota la llave.
- El backend es la raíz de confianza: su base de datos más `APP_KEY`
  exponen todas las llaves (esquema simétrico). Una credencial asimétrica
  (ECDSA) lo eliminaría, a costa de un CHALLENGE nuevo que no cabe en un
  I-block del RC522.
- El teléfono responde bloqueado (política: `requireDeviceUnlock=false`,
  llave sin autenticación de usuario). Un teléfono robado toca hasta que
  se revoque.
- Los UID MIFARE físicos siguen sin criptografía (clonables).
- Autenticación mutua, canal NFC cifrado y revisión de canal lateral
  siguen fuera de alcance.
