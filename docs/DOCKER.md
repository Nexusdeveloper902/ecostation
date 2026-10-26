# EcoStation en Docker

Un solo `docker compose up` levanta:

- **web**: Laravel en `:8000` (paneles, API de estaciones y fotos);
- **realtime**: el feed WebSocket en `:8081`;
- **scheduler**: purga de fotos y tareas programadas;
- **mdns**: anuncio `_ecostation._tcp` para que la estación y la app
  encuentren el servidor (Linux);
- **SQLite persistente**: la base de datos vive en el volumen
  `ecostation-data`, no en un servidor aparte. El proyecto es SQLite por
  diseño (dev, test y producción), así que no hay un contenedor de MariaDB
  ni Postgres que mantener;
- **storage persistente**: las fotos de los depósitos viven en el volumen
  `ecostation-storage`.

## Arranque

Requisitos: Docker Engine y Compose v2. En Linux, para mDNS, el host debe
estar en la misma LAN que la estación y el teléfono.

```bash
docker compose up -d --build
docker compose logs -f app
```

Abre `http://localhost:8000` (o `http://IP_DEL_SERVIDOR:8000`). La primera
arranque crea la base de datos, siembra las cuentas demo y (por defecto) un
poco de actividad para que los paneles no salgan vacíos.

Comprueba el estado:

```bash
docker compose ps
curl -fsS http://localhost:8000/up
```

Detener / reiniciar sin borrar datos:

```bash
docker compose down
docker compose up -d
```

## Cuentas demo

| Rol | Correo | Contraseña |
|---|---|---|
| Admin | `admin@ecostation.test` | `password` |
| Docente (5° A) | `docente@ecostation.test` | `password` |
| Estación / parlante | `estacion@ecostation.test` | `password` |
| Estudiantes | `maria@`, `carlos@`, `ana@`, `diego@`, `lucia@` `ecostation.test` | `password` |

La estación demo usa la clave de lector fija:

```text
DemoReaderKey0000000000000000000
```

Debe coincidir con `READER_API_KEY` en `firmware/include/secrets.h`. Si
cambias de base de datos (`down -v`), la clave se vuelve a sembrar con este
valor; si usas otra, cámbiala también en el firmware o rótala desde el panel
de administración.

## mDNS y la LAN

El servicio `mdns` usa `network_mode: host` y publica
`_ecostation._tcp.local` con el puerto 8000; no hay puertos que mapear para
él. Requiere Linux. Comprueba desde el host:

```bash
avahi-browse -rt _ecostation._tcp
docker compose logs mdns
```

Si usas Docker Desktop (macOS/Windows), `network_mode: host` no funciona:
elimina o comenta el servicio `mdns` y usa la **dirección manual** en la app
(`IP_DEL_SERVIDOR:8000`). La app y la estación siguen funcionando por IP.

Para que la web y el feed sean accesibles desde otros equipos:

- publica `8000` y `8081` en el cortafuegos del host;
- si el navegador abre el panel por `http://192.168.x.x:8000`, el feed se
  conecta a `ws://192.168.x.x:8081`; el compose permite cualquier origen por
  defecto (`REALTIME_ALLOWED_ORIGINS=*`) para la demo. En producción pon la
  lista exacta de orígenes;
- la app Android envía su WebSocket sin `Origin`, pero el login y la API usan
  el host que hayas puesto en Ajustes.

## Fotos de los depósitos

Las fotos **no** están en una galería pública. Se ven en el web:

1. entra como **admin** o como **docente de ese curso**;
2. **Docente → Estudiantes →** elige el estudiante;
3. cada depósito con foto muestra la miniatura; al abrirla se sirve por
   `GET /deposits/{id}/photo`.

La ruta exige sesión, respeta el alcance del docente (solo sus cursos) y
devuelve 404 si la foto ya se purgó. Los estudiantes no ven fotos.

En disco están en el volumen `ecostation-storage`:

```
/var/www/html/storage/app/private/recycling-captures/
```

Para listar o copiar una:

```bash
docker compose exec app find storage/app/private/recycling-captures -type f -maxdepth 1
docker compose cp app:/var/www/html/storage/app/private/recycling-captures/<archivo>.jpg ./foto.jpg
```

La retención por defecto es **30 días** (editable en Admin → Ajustes). La
purga diaria borra el archivo; el evento, la clasificación y los puntos se
conservan. Las fotos de la actividad demo no existen (el seeder no crea
imágenes); solo las capturas reales de la estación generan archivos.

## Base de datos, respaldo y reinicio

La base SQLite está en `/data/ecostation.sqlite` dentro del volumen
`ecostation-data`. La `APP_KEY` que cifra las llaves HCE se guarda en
`/data/app.key` en el mismo volumen: si pierdes la llave, las credenciales
HCE dejan de verificar.

Respaldo:

```bash
docker compose exec app sqlite3 /data/ecostation.sqlite ".backup '/data/backup.sqlite'"
docker compose cp app:/data/backup.sqlite ./ecostation-backup.sqlite
```

Restaurar (con el stack detenido o reemplazando el archivo):

```bash
docker compose cp ./ecostation-backup.sqlite app:/data/ecostation.sqlite
docker compose restart app
```

Reinicio total (borra base, llave, fotos y actividad demo):

```bash
docker compose down -v
docker compose up -d --build
```

No borres el volumen `ecostation-data` conservando solo `app.key`: la base y
la llave deben viajar juntas. `down -v` borra ambos a propósito.

## Configuración

Copia el ejemplo si quieres cambiar valores sin editar el compose:

```bash
cp .env.docker.example .env
docker compose up -d
```

Variables útiles:

| Variable | Default | Uso |
|---|---|---|
| `APP_URL` | `http://localhost:8000` | URL pública para enlaces |
| `REALTIME_PORT` | `8081` | puerto del feed (host y contenedor); cámbialo si está ocupado |
| `REALTIME_ALLOWED_ORIGINS` | `*` | Orígenes del feed; usa la lista exacta en producción |
| `ECOSTATION_DEMO_READER_KEY` | `DemoReaderKey…` | clave de la estación; solo en el primer seed |
| `ECOSTATION_DEMO_ACTIVITY` | `1` | siembra depósitos/ranking demo en el primer arranque |
| `RECYCLING_CLASSIFIER_DRIVER` | `stub` | `stub` offline o `deepseek` |
| `DEEPSEEK_API_KEY` | vacío | clave real para el clasificador |

La app no usa variables de entorno de Android: la dirección del servidor se
configura en **Ajustes**. La estación usa `firmware/include/secrets.h`.

## Producción

El compose está pensado para una LAN escolar y un arranque sencillo
(`php artisan serve` dentro del contenedor). Para producción:

- cambia `APP_ENV=production` y no siembres las cuentas demo;
- pon `REALTIME_ALLOWED_ORIGINS` con los orígenes reales, no `*`;
- cambia la clave demo de la estación y rota las credenciales;
- pon TLS en un proxy inverso (Nginx/Caddy) delante de `:8000` y `:8081`;
- usa un `APP_KEY` respaldado y un volumen de base de datos respaldado;
- mantén la imagen PHP en **8.5.12+** (PHP2Shell, CVE‑2026‑109618/109619);
- si necesitas más concurrencia que el servidor embebido de PHP, sustituye
  `artisan serve` por PHP‑FPM + Nginx manteniendo el mismo volumen.

## Problemas comunes

| Síntoma | Causa / solución |
|---|---|
| `port is already allocated` | Ya hay un `./run serve` o un proceso en 8000/8081. Deténlo o cambia los puertos en el compose. |
| El feed dice “Reconectando…” | El origen del navegador no está permitido; revisa `REALTIME_ALLOWED_ORIGINS` o usa `*` en la demo. |
| La app no encuentra el servidor | mDNS no funciona en Docker Desktop o la red bloquea multicast: pon `IP:8000` en Ajustes. |
| La estación recibe 401 | `READER_API_KEY` no coincide con la fila `readers`; usa la clave demo o rótala en Admin → Estaciones. |
| La estación recibe 403 al emparejar un teléfono | La app no está en la versión con la llave pendiente o la ventana de vinculación expiró; rearma e inténtalo de nuevo. |
| Las fotos no aparecen | La retención las borró, el depósito no tenía foto o el volumen `ecostation-storage` no está montado. |
| `APP_KEY` cambió y los teléfonos fallan | Se perdió `/data/app.key`; restaura el volumen o vuelve a emparejar los teléfonos. |
