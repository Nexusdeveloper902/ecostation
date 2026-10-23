# Privacidad y tratamiento de datos — EcoStation

Este documento describe, en lenguaje claro, qué datos personales trata
EcoStation y durante cuánto tiempo se conservan. Aplica a la instalación
local del servidor web (`web/`) y a sus estaciones de reciclaje.

## Qué datos se guardan

- **Estudiantes:** nombre, curso y saldo de puntos. Los puntos se derivan
  de un libro contable append-only (`points_ledger`), nunca de un contador
  que pueda desviarse.
- **Cuentas:** correo, rol (administrador, docente, estudiante o estación)
  y la contraseña almacenada con hash bcrypt. No se guardan tokens en
  claro.
- **Eventos y depósitos:** cada tap crea un evento y cada clasificación un
  depósito con su material, confianza y puntos otorgados.
- **Fotos de depósito:** la imagen que la estación captura para clasificar
  el material. Las fotos se guardan en el disco **privado** del servidor
  (`storage/app/private`); nunca en un disco público. Solo el personal
  autorizado puede verlas: un administrador ve todas y un docente ve
  únicamente las de los estudiantes de sus propios cursos. Las cuentas de
  estudiante no tienen acceso.
- **Tarjetas y credenciales:** el identificador público de la credencial
  (`credential_uid`) —el UID de una tarjeta física o el id de una
  credencial HCE del teléfono—, su tipo, su estado (`active`, `lost` o
  `revoked`) y el estudiante al que está vinculada. Para las credenciales
  HCE se guarda la clave secreta del teléfono **cifrada con `APP_KEY`**;
  esa clave nunca sale del servidor y se destruye al revocar o
  desemparejar la credencial. El historial (eventos, depósitos y puntos)
  no se borra en ese trámite.

## 1. Envío de fotos a DeepSeek para la clasificación

Cuando el clasificador está configurado con el motor **`deepseek`**
(`RECYCLING_CLASSIFIER_DRIVER=deepseek` y una `DEEPSEEK_API_KEY` válida),
la foto del depósito **se envía a la API de DeepSeek**
(`https://api.deepseek.com/chat/completions`) para identificar el material.
El envío incluye únicamente la imagen y las instrucciones de clasificación;
no incluye el nombre del estudiante, su curso, su correo ni su saldo.

El envío ocurre **después** de asociar una tarjeta: en el flujo de botella
primero la imagen se guarda localmente y no se clasifica hasta que un
estudiante presenta su credencial. Si el motor activo es **`stub`** (el
valor por defecto en desarrollo y en las pruebas), **no se envía ninguna
foto a DeepSeek** ni a ningún servicio externo: la clasificación se
resuelve localmente.

El administrador del sistema es quien elige el motor. Antes de activar
`deepseek` en una instalación real, la institución debe revisar y aprobar
ese tratamiento con el proveedor.

## 2. Borrado automático de fotos tras N días

Las fotos se **eliminan automáticamente después de N días**. N es un
ajuste del administrador y su valor por defecto es **30 días**
(`photos.retention_days`, configurable entre 1 y 365 días).

La tarea programada `php artisan photos:purge` se ejecuta a diario y:

1. borra el archivo de cada depósito cuya fecha de evento supera la
   retención y deja `image_path` en `null`;
2. borra el archivo y la fila de las **capturas pendientes vencidas**
   (incluidas las que quedaron abiertas en `awaiting_card` o
   `validating`, no solo las terminales);
3. borra archivos huérfanos de `recycling-captures/` que ya ninguna fila
   referencia y que superan la retención (residuos de caídas o cargas
   abandonadas).

El puntero `image_path` **solo se limpia cuando el archivo realmente
desapareció**. Si el borrado falla (permisos, disco ocupado), el puntero y
la fila se conservan, se registra una advertencia en el log y la siguiente
ejecución lo reintenta. Repetir la purga es seguro: un archivo que ya no
está no es un error.

**Se conservan la clasificación, el evento y los puntos.** El borrado de
la foto no altera balances, estadísticas ni el historial: solo desaparece
la imagen.

## 3. Canal en vivo (WebSockets) y datos en tiempo real

`php artisan realtime:serve` empuja novedades a los paneles por WebSocket.
El canal viaja por la red local (por defecto el servidor de desarrollo
escucha solo en `127.0.0.1`; con `ECOSTATION_LAN=1` se expone a la LAN).

- **Tokens del feed** son HMAC de vida corta, emitidos para una sesión
  autenticada; no se guardan en la base de datos.
- **tap y recycling:** administrador y estación reciben todo; un docente
  recibe solo los eventos de sus propios cursos, usando el curso
  **congelado en el evento** (un traslado posterior no mueve el historial
  entre docentes). Las cuentas de estudiante no reciben token y, si
  llegara uno forjado con ese rol, no ven ninguna fila.
- **feedback:** solo administrador y estación (señales de audio de la
  estación).
- **pairing:** solo administrador; incluye el identificador de la
  credencial, el nombre del estudiante y las marcas de tiempo del
  emparejamiento.
- El feed **nunca transmite fotos, claves HCE ni claves de estación**.
  Los nonces de los dispositivos son de un solo uso para impedir replay.

## 4. Credenciales y estaciones

- Cada estación tiene una clave secreta que el servidor muestra **una sola
  vez**; solo se conserva su huella pública (`sha256` abreviado). Rotar la
  clave invalida la anterior sin borrar los eventos.
- Una estación con eventos registrados **no se puede eliminar** (borraría
  el historial por cascada); se ofrece rotar su clave.
- Desemparejar o revocar una credencial la deja en estado `revoked`,
  destruye la clave HCE y conserva eventos, depósitos y puntos. Una
  credencial revocada puede reactivarse deliberadamente desde la mesa de
  emparejamiento con una clave nueva.

## Resumen

| Dato | Destino | Retención |
|---|---|---|
| Foto del depósito | Disco privado del servidor y, si el motor es `deepseek`, API de DeepSeek | N días (30 por defecto), luego se borra |
| Foto de captura pendiente | Disco privado del servidor | N días o hasta resolverse; luego se borra |
| Clasificación, confianza y material | Base de datos local | Se conserva |
| Evento (tap, fecha, estación, curso) | Base de datos local | Se conserva |
| Puntos del estudiante | Base de datos local (`points_ledger`) | Se conserva |
| Nombre, curso y correo | Base de datos local | Se conserva mientras exista la cuenta |
| Identificador de tarjeta/credencial | Base de datos local | Se conserva (estado revocado incluido) |
| Clave secreta HCE | Base de datos local, cifrada con `APP_KEY` | Hasta revocar/desemparejar |

Para ejercer solicitudes de acceso, corrección o supresión de estos datos,
contacte al administrador de la instalación.

## Riesgos residuales conocidos

- El anuncio mDNS (`_ecostation._tcp`) **no está autenticado**: sirve para
  descubrir la estación en la LAN, no para confiar en ella. Un dispositivo
  debe seguir validando las respuestas firmadas del servidor.
- El despliegue de referencia usa HTTP en la red local; para exponer la
  aplicación fuera de una red de confianza se requiere TLS en un proxy
  inverso (fuera del alcance de esta instalación).
