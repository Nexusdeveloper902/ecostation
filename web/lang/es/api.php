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

    // Endpoint de clasificación
    'event_not_owned_by_reader' => 'El evento no pertenece a este lector',
    'event_expired' => 'El evento de tap es demasiado antiguo para clasificarlo',
    'classifier_unavailable' => 'El clasificador de materiales no está disponible, reintenta más tarde',

    // Flujo de captura (botella primero)
    'no_pending_capture' => 'No hay una captura pendiente disponible para este lector',
    'capture_not_owned_by_reader' => 'La captura no pertenece a este lector',
    'capture_already_associated' => 'La captura ya fue asociada a una tarjeta',

    // Endpoint de emparejamiento de tarjetas
    'pairing_no_active_session' => 'No hay ninguna sesión de emparejamiento activa',
    'pairing_card_already_paired' => 'La tarjeta ya está emparejada',
];
