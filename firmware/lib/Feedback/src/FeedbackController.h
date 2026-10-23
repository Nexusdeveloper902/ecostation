/**
 * FeedbackController.h — the interface separating LED/buzzer presentation
 * from mode and networking logic. / Interfaz que separa la presentación
 * LED/zumbador de la lógica de modos y red.
 *
 * Business logic emits FeedbackSignals (CoreTypes.h); a FeedbackController
 * renders them. Swapping LEDs, adding a display or a buzzer never touches
 * the mode/API code. / La lógica emite FeedbackSignals; un
 * FeedbackController los renderiza. Cambiar LEDs o añadir pantalla/zumbador
 * jamás toca el código de modos/API.
 */
#pragma once

#include "CoreTypes.h"
#include "FeedbackPatterns.h"

namespace EcoStation {

class FeedbackController {
public:
    virtual ~FeedbackController() = default;

    /** Continuous state for the MODE LED (loops until replaced). */
    virtual void indicate(FeedbackKind continuousState) = 0;

    /** One-shot event pattern on the EVENT LED (and optional buzzer). */
    virtual void showEvent(const FeedbackSignal& signal) = 0;

    /** Non-blocking pattern stepping — call every loop iteration. */
    virtual void tick(uint32_t nowMs) = 0;
};

}  // namespace EcoStation
