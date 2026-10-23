/**
 * test_station_flow.cpp — la máquina de estados de la transacción de estación.
 *
 * Fija los dos flujos que station.cpp tenía en línea y sin pruebas:
 *   A) tarjeta primero: el toque arma el evento y la foto sale sola a los 5 s
 *      (o con BOTÓN/ENTER); una subida fallida conserva el brazo sin repetir
 *      la auto-captura.
 *   B) botella primero: la foto sube sin tarjeta y queda pendiente; el toque
 *      asocia esa captura. Una captura vencida NUNCA se roba el toque.
 *
 * Todo con temporizadores seguros al desbordamiento de millis().
 */
#include <unity.h>

#include <string>

#include "StationFlow.h"

using namespace EcoStation;

static const FlowTimings kT = {90000, 90000, 5000};

// Un toque que el backend aceptó y dejó esperando clasificación.
static TapResult ok(int id) {
    TapResult r;
    r.outcome = TapOutcome::Success;
    r.awaitingClassification = true;
    r.eventId = id;
    return r;
}

// Tarjeta primero: un único disparo automático, exactamente al vencer el plazo.
static void card_first_auto_captures_once_after_5s() {
    StationFlow f(kT);
    TEST_ASSERT_TRUE(f.onTapResult(ok(11), 1000) == ArmResult::Armed);
    TEST_ASSERT_FALSE(f.autoCaptureDue(5999));
    TEST_ASSERT_TRUE(f.autoCaptureDue(6000));
    TEST_ASSERT_FALSE(f.autoCaptureDue(6001));
    TEST_ASSERT_TRUE(f.routeCapture() == CaptureRoute::Classify);
    f.onClassifyResult(true, 200);
    TEST_ASSERT_EQUAL(-1, f.armedEventId());
    TEST_ASSERT_TRUE(f.routeCapture() == CaptureRoute::Capture);
}

// Una clasificación fallida conserva el brazo para BOTÓN/ENTER, pero la
// auto-captura NO se repite sola.
static void failed_classify_keeps_arm_without_refiring_auto_capture() {
    StationFlow f(kT);
    f.onTapResult(ok(11), 0);
    TEST_ASSERT_TRUE(f.autoCaptureDue(5000));
    f.onClassifyResult(false, -1);
    TEST_ASSERT_EQUAL(11, f.armedEventId());
    TEST_ASSERT_FALSE(f.autoCaptureDue(9000));
    f.onClassifyResult(true, 503);
    TEST_ASSERT_EQUAL(11, f.armedEventId());
    f.onClassifyResult(true, 200);
    TEST_ASSERT_EQUAL(-1, f.armedEventId());
}

// Re-toque del MISMO evento: sin rearme ni segunda foto. Otro evento sí
// reemplaza el brazo y reinicia el plazo.
static void retap_of_armed_event_keeps_the_arm() {
    StationFlow f(kT);
    f.onTapResult(ok(11), 0);
    TEST_ASSERT_TRUE(f.autoCaptureDue(5000));
    TEST_ASSERT_TRUE(f.onTapResult(ok(11), 7000) == ArmResult::AlreadyArmed);
    TEST_ASSERT_FALSE(f.autoCaptureDue(7500));
    TEST_ASSERT_TRUE(f.onTapResult(ok(12), 8000) == ArmResult::Replaced);
    TEST_ASSERT_TRUE(f.autoCaptureDue(13000));
}

// Botella primero: la captura queda pendiente y el siguiente toque asocia
// (no crea un evento nuevo). Un 200 la libera.
static void bottle_first_then_tap_associates() {
    StationFlow f(kT);
    TEST_ASSERT_TRUE(f.routeCapture() == CaptureRoute::Capture);
    TEST_ASSERT_EQUAL(-1, f.onCaptureResult(true, 200, 77, 0));
    TEST_ASSERT_TRUE(f.routeTap(ModeKind::Operation) == TapRoute::Associate);
    TEST_ASSERT_TRUE(f.onAssociateResult(true, 200, "{}"));
    TEST_ASSERT_TRUE(f.routeTap(ModeKind::Operation) == TapRoute::Tap);
}

// Una captura pendiente vencida (más de 90 s) no puede secuestrar el toque:
// se descarta y el toque sigue el camino normal de evento.
static void stale_pending_capture_does_not_steal_the_tap() {
    StationFlow f(kT);
    f.onCaptureResult(true, 200, 77, 0);
    Expired e = f.expire(90000);
    TEST_ASSERT_EQUAL(77, e.captureId);
    TEST_ASSERT_TRUE(f.routeTap(ModeKind::Operation) == TapRoute::Tap);
}

// Un 404 solo libera la captura cuando el backend dice que ya no existe o ya
// se resolvió; una tarjeta desconocida (u otro 404) la conserva.
static void associate_404_keeps_or_clears_by_reason() {
    StationFlow f(kT);
    f.onCaptureResult(true, 200, 77, 0);
    TEST_ASSERT_FALSE(f.onAssociateResult(true, 404, "{\"message\":\"Tarjeta no reconocida\"}"));
    TEST_ASSERT_FALSE(f.onAssociateResult(false, -1, ""));
    TEST_ASSERT_EQUAL(77, f.pendingCaptureId());
    TEST_ASSERT_TRUE(f.onAssociateResult(true, 404, "{\"status\":\"error\",\"reason\":\"no_pending_capture\"}"));
    TEST_ASSERT_EQUAL(-1, f.pendingCaptureId());
}

// Una subida fallida no borra la captura pendiente anterior; una nueva sí
// informa cuál reemplazó.
static void failed_capture_upload_keeps_previous_pending() {
    StationFlow f(kT);
    f.onCaptureResult(true, 200, 77, 0);
    TEST_ASSERT_EQUAL(-1, f.onCaptureResult(false, -1, -1, 100));
    TEST_ASSERT_EQUAL(77, f.pendingCaptureId());
    TEST_ASSERT_EQUAL(77, f.onCaptureResult(true, 200, 78, 200));
    TEST_ASSERT_EQUAL(78, f.pendingCaptureId());
}

// En EMPAREJAR todo toque va a emparejar, aunque haya una captura pendiente.
static void pairing_mode_never_associates() {
    StationFlow f(kT);
    f.onCaptureResult(true, 200, 77, 0);
    TEST_ASSERT_TRUE(f.routeTap(ModeKind::Pairing) == TapRoute::Pair);
}

// Un cambio de modo descarta captura pendiente y brazo.
static void reset_clears_everything() {
    StationFlow f(kT);
    f.onCaptureResult(true, 200, 77, 0);
    f.armFromConsole("5", 0);
    f.reset();
    TEST_ASSERT_EQUAL(-1, f.pendingCaptureId());
    TEST_ASSERT_EQUAL(-1, f.armedEventId());
}

// 'e <id>' solo acepta ids positivos y de hasta 9 dígitos.
static void console_arm_rejects_bad_ids() {
    StationFlow f(kT);
    TEST_ASSERT_FALSE(f.armFromConsole("0", 0));
    TEST_ASSERT_FALSE(f.armFromConsole("1234567890", 0));
    TEST_ASSERT_TRUE(f.armFromConsole("42", 0));
    TEST_ASSERT_EQUAL(42, f.armedEventId());
}

// BOTÓN/ENTER antes de los 5 s: un intento manual de clasificación cuenta
// como el intento de este brazo — la auto-captura NO debe disparar otra foto.
static void manual_classify_attempt_disables_auto_capture() {
    StationFlow f(kT);
    f.onTapResult(ok(11), 0);
    f.onClassifyResult(false, -1);  // intento manual a los ~2 s, falla
    TEST_ASSERT_EQUAL(11, f.armedEventId());  // el brazo se conserva
    TEST_ASSERT_FALSE(f.autoCaptureDue(5000));  // sin segunda foto automática
    TEST_ASSERT_FALSE(f.autoCaptureDue(9000));
    f.onClassifyResult(true, 200);
    TEST_ASSERT_EQUAL(-1, f.armedEventId());
}

// 'e <id>' se rechaza mientras hay una captura pendiente: nunca dos
// transacciones en vuelo. Estado intacto; tras resolver, armar sí funciona.
static void console_arm_refused_while_capture_pending() {
    StationFlow f(kT);
    f.onCaptureResult(true, 200, 77, 0);
    TEST_ASSERT_FALSE(f.armFromConsole("5", 100));
    TEST_ASSERT_EQUAL(-1, f.armedEventId());
    TEST_ASSERT_EQUAL(77, f.pendingCaptureId());
    TEST_ASSERT_TRUE(f.onAssociateResult(true, 200, "{}"));
    TEST_ASSERT_TRUE(f.armFromConsole("5", 200));
    TEST_ASSERT_EQUAL(5, f.armedEventId());
}

// Solo un toque exitoso y en espera de clasificación arma un evento.
static void non_awaiting_or_failed_taps_never_arm() {
    StationFlow f(kT);
    TapResult r = ok(11); r.awaitingClassification = false;
    TEST_ASSERT_TRUE(f.onTapResult(r, 0) == ArmResult::NotArmed);
    TapResult bad; bad.outcome = TapOutcome::CardNotRecognized;
    TEST_ASSERT_TRUE(f.onTapResult(bad, 0) == ArmResult::NotArmed);
}

// Los plazos sobreviven al desbordamiento de millis() a los ~49,7 días.
static void timers_survive_millis_wrap() {
    StationFlow f(kT);
    const uint32_t t0 = 0xFFFFF000u;           // 4096 ms before the wrap
    f.onTapResult(ok(11), t0);
    TEST_ASSERT_FALSE(f.autoCaptureDue(t0 + 4999));
    TEST_ASSERT_TRUE(f.autoCaptureDue(t0 + 5000));  // wraps past 0
    TEST_ASSERT_EQUAL(-1, f.expire(t0 + 89999).eventId);
    TEST_ASSERT_EQUAL(11, f.expire(t0 + 90000).eventId);
}

void runStationFlowTests() {
    RUN_TEST(card_first_auto_captures_once_after_5s);
    RUN_TEST(failed_classify_keeps_arm_without_refiring_auto_capture);
    RUN_TEST(retap_of_armed_event_keeps_the_arm);
    RUN_TEST(bottle_first_then_tap_associates);
    RUN_TEST(stale_pending_capture_does_not_steal_the_tap);
    RUN_TEST(associate_404_keeps_or_clears_by_reason);
    RUN_TEST(failed_capture_upload_keeps_previous_pending);
    RUN_TEST(pairing_mode_never_associates);
    RUN_TEST(reset_clears_everything);
    RUN_TEST(console_arm_rejects_bad_ids);
    RUN_TEST(manual_classify_attempt_disables_auto_capture);
    RUN_TEST(console_arm_refused_while_capture_pending);
    RUN_TEST(non_awaiting_or_failed_taps_never_arm);
    RUN_TEST(timers_survive_millis_wrap);
}
