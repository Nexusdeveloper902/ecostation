/**
 * test_console.cpp — TASK-003: serial console mode switching.
 * test_console.cpp — TASK-003: cambio de modo por consola serial.
 *
 * LineBuffer (line assembly, trimming, overflow) and ModeConsole
 * (password matching, wrong-attempt counting, lockout, expiry, wrap-safe
 * injected time) — plus the two new LED pattern kinds. All pure C++ with
 * injected time, so every rule is host-testable.
 * / LineBuffer y ModeConsole (coincidencia de contraseña, conteo de
 * intentos, bloqueo, expiración, aritmética a prueba de desbordamiento)
 * + los dos nuevos patrones LED. C++ puro con tiempo inyectado.
 */
#include <string>

#include <unity.h>

#include "FeedbackPatterns.h"
#include "ModeConsole.h"

// --- LineBuffer -------------------------------------------------------------

namespace {
EcoStation::LineBuffer makeBuffer() {
    return EcoStation::LineBuffer(8);  // small cap to exercise overflow
}
}  // namespace

void feedString(EcoStation::LineBuffer& b, const std::string& s, std::string& out) {
    for (char c : s) {
        b.feed(c, out);
    }
}

void test_linebuffer_accumulates_until_newline(void) {
    auto b = makeBuffer();
    std::string line;
    feedString(b, "abc", line);
    TEST_ASSERT_TRUE(line.empty());            // no completion yet
    TEST_ASSERT_TRUE(b.feed('\n', line));      // completion reports true
    TEST_ASSERT_EQUAL_STRING("abc", line.c_str());
}

void test_linebuffer_handles_crlf(void) {
    auto b = makeBuffer();
    std::string line;
    feedString(b, "abc\r", line);              // \r completes "abc"
    TEST_ASSERT_EQUAL_STRING("abc", line.c_str());
    TEST_ASSERT_TRUE(b.feed('\n', line));      // \n right after → empty completion
    TEST_ASSERT_TRUE(line.empty());
}

void test_linebuffer_trims_whitespace(void) {
    EcoStation::LineBuffer b(16);  // roomy cap: this test is about trimming
    std::string line;
    feedString(b, "  pw123 \t", line);
    TEST_ASSERT_TRUE(b.feed('\n', line));
    TEST_ASSERT_EQUAL_STRING("pw123", line.c_str());
}

void test_linebuffer_bare_enter_yields_empty_line(void) {
    auto b = makeBuffer();
    std::string line;
    TEST_ASSERT_TRUE(b.feed('\n', line));
    TEST_ASSERT_TRUE(line.empty());
}

void test_linebuffer_overflow_discards_line(void) {
    auto b = makeBuffer();  // cap = 8
    std::string line;
    feedString(b, "0123456789ABCDEF", line);  // way over the cap
    TEST_ASSERT_TRUE(b.feed('\n', line));
    TEST_ASSERT_TRUE(line.empty());      // discarded, boundary preserved
    TEST_ASSERT_TRUE(b.overflowed());
    // The buffer is usable again immediately after.
    feedString(b, "ok", line);
    TEST_ASSERT_TRUE(b.feed('\n', line));
    TEST_ASSERT_EQUAL_STRING("ok", line.c_str());
    TEST_ASSERT_FALSE(b.overflowed());
}

// --- ModeConsole -------------------------------------------------------------

void test_console_correct_password_accepted(void) {
    EcoStation::ModeConsole console("sekret");
    TEST_ASSERT_EQUAL(static_cast<int>(EcoStation::ConsoleResult::Accepted),
                      static_cast<int>(console.handleLine("sekret", 1000)));
}

void test_console_wrong_password_rejected_and_counted(void) {
    EcoStation::ModeConsole console("sekret");
    TEST_ASSERT_EQUAL(static_cast<int>(EcoStation::ConsoleResult::Rejected),
                      static_cast<int>(console.handleLine("nope", 1000)));
    TEST_ASSERT_EQUAL_UINT8(1, console.wrongAttempts());
    TEST_ASSERT_EQUAL(static_cast<int>(EcoStation::ConsoleResult::Rejected),
                      static_cast<int>(console.handleLine("nope2", 2000)));
    TEST_ASSERT_EQUAL_UINT8(2, console.wrongAttempts());
}

void test_console_empty_line_ignored(void) {
    EcoStation::ModeConsole console("sekret");
    TEST_ASSERT_EQUAL(static_cast<int>(EcoStation::ConsoleResult::Ignored),
                      static_cast<int>(console.handleLine("", 1000)));
    TEST_ASSERT_EQUAL_UINT8(0, console.wrongAttempts());  // bare Enter never counts
}

void test_console_success_resets_wrong_count(void) {
    EcoStation::ModeConsole console("sekret");
    console.handleLine("nope", 1000);
    console.handleLine("nope2", 2000);
    console.handleLine("sekret", 3000);
    TEST_ASSERT_EQUAL_UINT8(0, console.wrongAttempts());
}

void test_console_lockout_after_max_wrong_attempts(void) {
    EcoStation::ModeConsole console("sekret", 3, 10000);
    console.handleLine("w1", 1000);
    console.handleLine("w2", 2000);
    TEST_ASSERT_EQUAL(static_cast<int>(EcoStation::ConsoleResult::Rejected),
                      static_cast<int>(console.handleLine("w3", 3000)));  // triggers
    TEST_ASSERT_TRUE(console.lockedOut(3000));
    TEST_ASSERT_TRUE(console.lockedOut(12000));   // still inside 10 s
    TEST_ASSERT_FALSE(console.lockedOut(13001));  // expired (wrap-safe math)
}

void test_console_locked_rejects_even_correct_password(void) {
    EcoStation::ModeConsole console("sekret", 3, 10000);
    console.handleLine("w1", 1000);
    console.handleLine("w2", 2000);
    console.handleLine("w3", 3000);
    TEST_ASSERT_EQUAL(static_cast<int>(EcoStation::ConsoleResult::LockedOut),
                      static_cast<int>(console.handleLine("sekret", 5000)));
}

void test_console_lockout_expiry_allows_password_again(void) {
    EcoStation::ModeConsole console("sekret", 3, 10000);
    console.handleLine("w1", 1000);
    console.handleLine("w2", 2000);
    console.handleLine("w3", 3000);
    // Inside the lockout: refused.
    console.handleLine("sekret", 5000);
    // After expiry: accepted, and the counter starts fresh.
    TEST_ASSERT_EQUAL(static_cast<int>(EcoStation::ConsoleResult::Accepted),
                      static_cast<int>(console.handleLine("sekret", 30000)));
    TEST_ASSERT_EQUAL_UINT8(0, console.wrongAttempts());
}

void test_console_time_wraps_safely(void) {
    // 0xFFFFFFFF is ~49 days of millis(); a naive stored-deadline compare
    // (now >= lockStart + lockout) would misbehave across the wrap — the
    // elapsed-diff compare must not.
    EcoStation::ModeConsole console("sekret", 2, 10000);
    console.handleLine("w1", 0xFFFFFF00u);
    console.handleLine("w2", 0xFFFFFF10u);  // locks here, at the wrap edge
    TEST_ASSERT_TRUE(console.lockedOut(0xFFFFFF15u));   // 5 ms into the lock
    // lockStart + 10000 ms crosses 2^32: 0xFFFFFF10 + 0x2710 -> 0x2620
    TEST_ASSERT_FALSE(console.lockedOut(0x2620u));      // exactly at expiry
    TEST_ASSERT_FALSE(console.lockedOut(0x2630u));      // 16 ms past expiry
}

// --- New LED pattern kinds ------------------------------------------------------

void test_mode_console_patterns_exist_and_are_distinct(void) {
    auto switched = EcoStation::eventLedPattern(EcoStation::FeedbackKind::ModeSwitched);
    auto rejected = EcoStation::eventLedPattern(EcoStation::FeedbackKind::ModeRejected);
    auto tap404   = EcoStation::eventLedPattern(EcoStation::FeedbackKind::TapRejected);
    auto netErr   = EcoStation::eventLedPattern(EcoStation::FeedbackKind::NetworkError);
    auto success  = EcoStation::eventLedPattern(EcoStation::FeedbackKind::TapSuccess);

    TEST_ASSERT_TRUE(!switched.empty());
    TEST_ASSERT_TRUE(!rejected.empty());
    TEST_ASSERT_TRUE(switched != rejected);
    TEST_ASSERT_TRUE(switched != tap404);   // different tempo than 2x200 ms
    TEST_ASSERT_TRUE(rejected != netErr);   // different count than 5x120 ms
    TEST_ASSERT_TRUE(switched != success);  // not a solid block
    TEST_ASSERT_TRUE(rejected != success);
}

void runConsoleTests() {
    RUN_TEST(test_linebuffer_accumulates_until_newline);
    RUN_TEST(test_linebuffer_handles_crlf);
    RUN_TEST(test_linebuffer_trims_whitespace);
    RUN_TEST(test_linebuffer_bare_enter_yields_empty_line);
    RUN_TEST(test_linebuffer_overflow_discards_line);

    RUN_TEST(test_console_correct_password_accepted);
    RUN_TEST(test_console_wrong_password_rejected_and_counted);
    RUN_TEST(test_console_empty_line_ignored);
    RUN_TEST(test_console_success_resets_wrong_count);
    RUN_TEST(test_console_lockout_after_max_wrong_attempts);
    RUN_TEST(test_console_locked_rejects_even_correct_password);
    RUN_TEST(test_console_lockout_expiry_allows_password_again);
    RUN_TEST(test_console_time_wraps_safely);

    RUN_TEST(test_mode_console_patterns_exist_and_are_distinct);
}
