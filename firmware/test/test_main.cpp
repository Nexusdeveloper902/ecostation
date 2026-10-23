/**
 * test_main.cpp — punto de entrada único de las pruebas nativas.
 *
 * El entorno native de PlatformIO enlaza todos los .cpp de test/ en UN solo
 * programa: un único main() aquí; cada archivo expone un registrador
 * run<X>Tests() que encola sus RUN_TEST.
 */
#include <unity.h>

void runHceProtocolTests();
void runRequestSignerTests();
void runPayloadTests();
void runResponseTests();
void runCapturePayloadTests();
void runStationConfigTests();
void runModeTests();
void runDebounceTests();
void runConsoleTests();
void runCaptureTriggerTests();
void runEndpointTests();

// Compartido por todas las pruebas (Unity llama estas funciones en cada RUN_TEST).
void setUp() {}
void tearDown() {}

int main() {
    UNITY_BEGIN();

    runHceProtocolTests();     // APDU HCE: constructores, parsers, HMAC
    runRequestSignerTests();   // firma Pulse-HMAC de la cabecera Authorization
    runPayloadTests();         // cargas JSON (tap, emparejar, asociar)
    runResponseTests();        // lectura de respuestas del servidor
    runCapturePayloadTests();  // bytes multipart + canónicos de firma
    runStationConfigTests();  // mapa de hardware de la estación
    runModeTests();            // estrategias de modo + mapeo de feedback
    runDebounceTests();        // antirrebote de tarjeta + patrones LED
    runConsoleTests();         // consola serial (LineBuffer + ModeConsole)
    runCaptureTriggerTests();  // disparador de captura (disciplina de línea + enfriamiento)
    runEndpointTests();        // dirección del servidor (mDNS + manual)

    return UNITY_END();
}
