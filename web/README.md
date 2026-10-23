# EcoStation web

Aplicación Laravel 13 (PHP 8.3+, SQLite, Sanctum) de EcoStation. Idioma `es`, zona horaria `America/Bogota`.

```bash
./run setup   # dependencias, .env, base SQLite, migraciones y datos de demostración
./run serve   # http://127.0.0.1:8000 (solo local)
./run test    # pruebas
./run ci      # Pint + pruebas
```

`./run serve` escucha **solo en 127.0.0.1** por defecto y deja
`APP_DEBUG=false` salvo que se pida explícitamente:

```bash
ECOSTATION_LAN=1 ./run serve     # expone web y feed en 0.0.0.0 para las estaciones
ECOSTATION_DEBUG=1 ./run serve   # APP_DEBUG=true (nunca en una instalación real)
ECOSTATION_MDNS=0 ./run serve    # sin anuncio _ecostation._tcp (también: false/no/off)
```

En modo LAN el anuncio mDNS se activa si `avahi-publish-service` o
`python3` están disponibles. La autenticidad de ese anuncio no está
firmada: es una comodidad de descubrimiento en la red local, no un
mecanismo de confianza (riesgo residual documentado).

## Advertencia: cuenta de demostración

Las cuentas de demostración (`admin@ecostation.test`, `docente@ecostation.test`, `estacion@ecostation.test` y una por estudiante, p. ej. `maria@ecostation.test`) usan la clave `password` y son **solo para desarrollo**. Se siembran únicamente en los entornos `local` y `testing`. Cámbiala o elimínala en cualquier entorno compartido.
