<?php

/*
|--------------------------------------------------------------------------
| Mensajes de la API (dispositivos y endpoints)
|--------------------------------------------------------------------------
*/

return [
    // Autenticación de lectores
    'missing_bearer_token' => 'Falta el token de portador (bearer)',
    'invalid_bearer_token' => 'Token de portador (bearer) no válido',
    'invalid_device_signature' => 'Firma de dispositivo no válida',

    // Endpoint de tap
    'card_not_recognized' => 'Tarjeta no reconocida',
    'card_not_active' => 'La tarjeta no está activa',
    'hce_credential_unverified' => 'No se pudo verificar la credencial del teléfono',
];
