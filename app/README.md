# EcoStation (app Android)

## Requisitos

- JDK 17 y Android SDK con la plataforma 35 (`ANDROID_HOME` o `local.properties`).
- Dispositivo con NFC y emulación de tarjeta (HCE); minSdk 24.

## Compilar

```bash
cd app
./gradlew :app:assembleDebug        # APK de depuración
./gradlew :app:testDebugUnitTest    # pruebas unitarias (JVM pura)
./gradlew :app:assembleRelease      # requiere la llave de firma (ver abajo)
```

## Firma de release

La llave de release vive fuera del repositorio. Genérala una sola vez:

```bash
keytool -genkeypair -v -keystore ~/.ecostation/release.jks -alias ecostation \
  -keyalg RSA -keysize 4096 -validity 10000
```

Define las cuatro entradas en `~/.gradle/gradle.properties`:

```properties
ecostation.signing.storeFile=/home/usuario/.ecostation/release.jks
ecostation.signing.storePassword=...
ecostation.signing.keyAlias=ecostation
ecostation.signing.keyPassword=...
```

o como variables de entorno: `ECOSTATION_SIGNING_STORE_FILE`,
`ECOSTATION_SIGNING_STORE_PASSWORD`, `ECOSTATION_SIGNING_KEY_ALIAS`,
`ECOSTATION_SIGNING_KEY_PASSWORD`.

Si falta alguna, o si el keystore está dentro del monorepo, `assembleRelease`
falla con «Falta la llave de firma de release». Las compilaciones de depuración
no se ven afectadas.

**Advertencia:** perder o cambiar la llave cambia el identificador de
credencial de todos los teléfonos (el ANDROID_ID depende de la llave de firma)
y obliga a volver a emparejarlos.

## Modo parlante

El teléfono puede además escuchar el feed en tiempo real de EcoStation y
sonar en cada toque, como el altavoz de una estación. El modo se enciende en
**Ajustes** y solo vive mientras `MainActivity` está visible: al salir se
detiene el puente, se apaga el keep-alive de audio y se suelta la pantalla.

En **Ajustes** se configuran cuatro cosas:

- **Dirección del servidor (opcional).** Si se deja vacía, la app busca el
  servicio `_ecostation._tcp` en la red y, si no aparece, sondea las subredes
  privadas de tamaño /22 o menor. Una dirección escrita a mano
  (`192.168.1.10`, `192.168.1.10:8080` o `http://servidor.local:8000/`) gana
  siempre y se salta el descubrimiento. Un valor inválido se rechaza al
  guardar con «Dirección no válida».
- **Modo parlante.** El interruptor que arranca el puente.
- **Correo de la cuenta del parlante.** La cuenta debe tener rol `station` en
  la web y no puede tener cambio de contraseña pendiente; de lo contrario el
  servidor no entrega el token del feed.
- **Contraseña.** Se guarda sellada con una llave AES-GCM del Android
  Keystore y nunca se vuelve a mostrar en claro: al reabrir Ajustes el campo
  aparece vacío con la pista «Contraseña guardada» y solo se reemplaza si se
  escribe una nueva.

Con el modo encendido, la pantalla queda encendida y las teclas de volumen
mueven el flujo multimedia (no el del timbre). Los cinco sonidos son:

| Sonido | Cuándo |
|---|---|
| `eco_beep_detected` | a este teléfono le acaban de leer la credencial (HCE local) |
| `eco_beep_accepted` | el backend registró el toque |
| `eco_beep_rejected` | el backend marcó el toque o falló el reto local |
| `eco_cue_recycled` | una clasificación nueva dio puntos |
| `eco_cue_not_recyclable` | una clasificación nueva no dio puntos |

Los pitidos locales suenan siempre, aunque el modo parlante esté apagado. El
diagnóstico muestra «Eventos del servidor (sonido)» con el estado del puente y
el último sonido, y «Salida de audio» con el dispositivo que Android usa (A2DP
o el altavoz del teléfono); **tocar esa línea reproduce los cinco sonidos**,
que es la prueba rápida en el banco.

Estados del puente: `buscando servidor…`, `servidor <host:puerto>: iniciando
sesión…`, `EN VIVO · <host:puerto>` y `reintento en Ns: <motivo>`. Si falta la
cuenta, el estado es «falta la cuenta del parlante en Ajustes» y espera 30 s.
Las caídas reintentan con retroceso 1/2/4/8/10 s y redescubren la dirección; un
rechazo de inicio de sesión o un 429 esperan 30 s para no agotar el límite de
5 intentos por minuto. La procedencia y verificación de los sonidos están en
[`docs/SOUNDS.md`](docs/SOUNDS.md).

La prueba de extremo a extremo contra un backend real es opcional y se salta
salvo que estén las cinco variables `ECO_E2E_BASE`, `ECO_E2E_EMAIL`,
`ECO_E2E_PASSWORD`, `ECO_E2E_READER_KEY` y `ECO_E2E_CARD`:

```bash
ECO_E2E_BASE=http://127.0.0.1:8100 ECO_E2E_EMAIL=... ECO_E2E_PASSWORD=... \
ECO_E2E_READER_KEY=... ECO_E2E_CARD=... ./gradlew :app:testDebugUnitTest --tests '*LiveBackendE2ETest*'
```

## Decisiones

- **Sin AndroidX.** Solo se usa el framework de la plataforma (`Activity`,
  vistas nativas, `android.test.*` para pruebas instrumentadas): menos
  dependencias que auditar y un APK mínimo para una app de una sola función.
- Sin secretos en `BuildConfig`: solo el AID y un id de credencial en blanco;
  la llave de cada teléfono se genera en el Android Keystore.
