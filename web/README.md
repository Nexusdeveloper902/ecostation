# EcoStation web

Aplicación Laravel 13 (PHP 8.3+, SQLite, Sanctum) de EcoStation. Idioma `es`, zona horaria `America/Bogota`.

```bash
./run setup   # dependencias, .env, base SQLite, migraciones y datos de demostración
./run serve   # http://localhost:8000
./run test    # pruebas
./run ci      # Pint + pruebas
```

## Advertencia: cuenta de demostración

`demo@ecostation.test` / `demo1234` es una cuenta **solo para desarrollo**. Se siembra únicamente en los entornos `local` y `testing`. Cámbiala o elimínala en cualquier entorno compartido.
