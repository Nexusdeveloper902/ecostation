/**
 * ModeConsole.h — serial-console mode switching: line assembly + a
 * password-gated state machine.
 * ModeConsole.h — cambio de modo por consola serial: ensamblado de líneas
 * + máquina de estados protegida por contraseña.
 *
 * TASK-003 replaced the boot-time mode button (ADR-002, superseded) with
 * this: the operator types the MODE PASSWORD (secrets.h) + Enter in the
 * Serial Monitor and the device toggles OPERATION <-> PAIRING at runtime.
 * / TASK-003 sustituye el botón de arranque (ADR-002, sustituido) por
 * esto: el operador escribe la CLAVE DE MODO (secrets.h) + Enter y el
 * equipo alterna OPERACIÓN <-> EMPAREJAR en ejecución.
 *
 * Everything here is pure C++ with injected time (millis() is passed in),
 * so the full behavior — password matching, wrong-attempt counting,
 * lockout, expiry, line assembly, overflow — is host-testable in the
 * `native` env, exactly like CardDebouncer. / Todo es C++ puro con tiempo
 * inyectado: la conducta completa es testeable en el host (env native).
 *
 * Security honesty (ADR-005): the serial console is a PHYSICAL-ACCESS
 * channel (USB). The password is an operator gate, not cryptography;
 * pairing itself still requires an admin-armed session on the backend
 * (45 s window) plus the reader Bearer key. Wrong attempts lock the
 * console for MODE_CONSOLE_LOCKOUT_MS. / Honestidad de seguridad: la
 * consola serial es un canal de ACCESO FÍSICO. La contraseña es una
 * compuerta de operador, no criptografía; emparejar sigue requiriendo
 * una sesión armada por un admin en el backend + la clave Bearer.
 */
#pragma once

#include <cstdint>
#include <string>

namespace EcoStation {

/**
 * Assembles complete lines from raw serial characters.
 * / Ensambla líneas completas a partir de caracteres seriales.
 *
 * - '\n' or '\r' terminates a line (CRLF therefore yields the payload
 *   line + one empty completion — callers ignore empties).
 * - Completed lines are whitespace-trimmed at both ends.
 * - Lines longer than maxLength are DISCARDED: buffering stops, the
 *   terminator arrives, feed() reports an empty line and overflowed()
 *   flags it. / Las líneas más largas que maxLength se descartan.
 */
class LineBuffer {
public:
    explicit LineBuffer(size_t maxLength = 64)
        : maxLength_(maxLength) {}

    /**
     * Feed one raw character. Returns true when a line just completed
     * (lineOut = trimmed content; possibly empty — bare Enter or a
     * discarded overflow line). / Devuelve true al completarse una línea.
     */
    bool feed(char c, std::string& lineOut);

    /** True when the LAST completed line was discarded for length. */
    bool overflowed() const { return lastLineOverflowed_; }

private:
    size_t maxLength_;
    std::string buffer_;
    bool discarding_ = false;       // overflow in progress — drop until EOL
    bool lastLineOverflowed_ = false;  // latched for the accessor
};

/** Outcome of one console line handed to the ModeConsole. */
enum class ConsoleResult {
    Ignored,    ///< empty line — nothing counted / línea vacía
    Accepted,   ///< correct password — toggle the mode / clave correcta
    Rejected,   ///< wrong password — attempt counted / clave incorrecta
    LockedOut,  ///< console locked (attempts exhausted) / entrada bloqueada
};

/**
 * Password-gated mode-switch console with wrong-attempt lockout.
 * / Consola de cambio de modo con contraseña y bloqueo por intentos.
 *
 * Semantics:
 * - Correct password → Accepted; the wrong-attempt counter resets.
 * - Wrong (non-empty) password → Rejected; the counter increments.
 * - After maxWrongAttempts consecutive wrongs the console locks for
 *   lockoutMs (wrap-safe injected time). During the lockout EVERY line,
 *   even the correct password, returns LockedOut.
 * - When the lockout expires the counter starts fresh.
 */
class ModeConsole {
public:
    ModeConsole(const std::string& password,
                uint8_t maxWrongAttempts = 3,
                uint32_t lockoutMs = 10000u)
        : password_(password),
          maxWrongAttempts_(maxWrongAttempts),
          lockoutMs_(lockoutMs) {}

    /** Process one (already line-assembled) input line. */
    ConsoleResult handleLine(const std::string& line, uint32_t nowMs);

    /** True while the wrong-attempt lockout is active at nowMs. */
    bool lockedOut(uint32_t nowMs) const;

    /** Consecutive wrong attempts since the last success/expiry. */
    uint8_t wrongAttempts() const { return wrongAttempts_; }

private:
    void lock(uint32_t nowMs) {
        locked_ = true;
        lockStartMs_ = nowMs;
        wrongAttempts_ = 0;
    }

    std::string password_;
    uint8_t maxWrongAttempts_;
    uint32_t lockoutMs_;

    uint8_t wrongAttempts_ = 0;
    bool locked_ = false;
    uint32_t lockStartMs_ = 0;  // meaningful only while locked_ (wrap-safe)
};

}  // namespace EcoStation
