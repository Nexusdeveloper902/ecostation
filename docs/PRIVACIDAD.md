# Privacidad y tratamiento de datos — EcoStation

Este documento describe, en lenguaje claro, qué datos personales trata
EcoStation y durante cuánto tiempo se conservan. Aplica a la instalación
local del servidor web (`web/`) y a sus estaciones de reciclaje.

## Qué datos se guardan

- **Estudiantes:** nombre, curso y saldo de puntos. Los puntos se derivan
  de un libro contable append-only (`points_ledger`), nunca de un contador
  que pueda desviarse.
- **Cuentas:** correo, rol (administrador, docente, estudiante o estación)
  y la contraseña almacenada con hash. No se guardan tokens en claro.
- **Eventos y depósitos:** cada tap crea un evento y cada clasificación un
  depósito con su material, confianza y puntos otorgados.
- **Fotos de depósito:** la imagen que la estación captura para clasificar
  el material. Las fotos se guardan en el disco **privado** del servidor
  (`storage/app/private`); nunca en un disco público. Solo el personal
  autorizado puede verlas: un administrador ve todas y un docente ve
  únicamente las de los estudiantes de sus propios cursos. Las cuentas de
  estudiante no tienen acceso.

## 1. Envío de fotos a DeepSeek para la clasificación

Cuando el clasificador está configurado con el motor **`deepseek`**
(`RECYCLING_CLASSIFIER_DRIVER=deepseek` y una `DEEPSEEK_API_KEY` válida),
la foto del depósito **se envía a la API de DeepSeek**
(`https://api.deepseek.com/chat/completions`) para identificar el material.
El envío incluye únicamente la imagen y las instrucciones de clasificación;
no incluye el nombre del estudiante, su curso, su correo ni su saldo.

Si el motor activo es **`stub`** (el valor por defecto en desarrollo y en
las pruebas), **no se envía ninguna foto a DeepSeek** ni a ningún servicio
externo: la clasificación se resuelve localmente.

El administrador del sistema es quien elige el motor. Antes de activar
`deepseek` en una instalación real, la institución debe revisar y aprobar
ese tratamiento con el proveedor.

## 2. Borrado automático de fotos tras N días

Las fotos de depósito se **eliminan automáticamente después de N días**.
N es un ajuste del administrador y su valor por defecto es **30 días**
(`photos.retention_days`, configurable entre 1 y 365 días).

La tarea programada `php artisan photos:purge` se ejecuta a diario y:

1. borra el archivo de cada depósito cuya fecha de evento supera la
   retención y deja `image_path` en `null`;
2. borra el archivo y la fila de las capturas pendientes que ya están en
   un estado terminal (`expired`, `rejected`, `failed` o `accepted`) y
   superan la retención.

**Se conservan la clasificación, el evento y los puntos.** El borrado de
la foto no altera balances, estadísticas ni el historial: solo desaparece
la imagen. Repetir la purga es seguro (no falla si el archivo ya no está).

## Resumen

| Dato | Destino | Retención |
|---|---|---|
| Foto del depósito | Disco privado del servidor y, si el motor es `deepseek`, API de DeepSeek | N días (30 por defecto), luego se borra |
| Clasificación, confianza y material | Base de datos local | Se conserva |
| Evento (tap, fecha, estación) | Base de datos local | Se conserva |
| Puntos del estudiante | Base de datos local (`points_ledger`) | Se conserva |
| Nombre, curso y correo | Base de datos local | Se conserva mientras exista la cuenta |

Para ejercer solicitudes de acceso, corrección o supresión de estos datos,
contacte al administrador de la instalación.
