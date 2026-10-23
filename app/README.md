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

## Decisiones

- **Sin AndroidX.** Solo se usa el framework de la plataforma (`Activity`,
  vistas nativas, `android.test.*` para pruebas instrumentadas): menos
  dependencias que auditar y un APK mínimo para una app de una sola función.
- Sin secretos en `BuildConfig`: solo el AID y un id de credencial en blanco;
  la llave de cada teléfono se genera en el Android Keystore.
