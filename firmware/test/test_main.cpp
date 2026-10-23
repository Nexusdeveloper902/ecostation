/**
 * test_main.cpp — punto de entrada único de las pruebas nativas.
 *
 * El entorno native de PlatformIO enlaza todos los .cpp de test/ en UN solo
 * programa: un único main() aquí; cada archivo expone un registrador
 * run<X>Tests() que encola sus RUN_TEST.
 */
#include <unity.h>

void runStationConfigTests();

// Compartido por todas las pruebas (Unity llama estas funciones en cada RUN_TEST).
void setUp() {}
void tearDown() {}

int main() {
    UNITY_BEGIN();

    runStationConfigTests();  // mapa de hardware de la estación

    return UNITY_END();
}
