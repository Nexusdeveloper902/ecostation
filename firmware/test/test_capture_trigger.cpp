/**
 * test_capture_trigger.cpp — TASK-008: the CaptureTrigger seam.
 * test_capture_trigger.cpp — TASK-008: la costura de CaptureTrigger.
 *
 * Pins the line discipline (ENTER / a <uid> / e <id> / c), the
 * key-repeat cooldown (injectable clock), and the buffered-input rules
 * the spec demands: Enter must not multi-fire, half-typed lines must
 * never fire, garbage must never become an upload.
 */
#include <unity.h>

#include <string>

#include "CaptureTrigger.h"

using namespace EcoStation;

namespace {
unsigned long fakeNow = 0;
unsigned long fakeClock() { return fakeNow; }
}  // namespace

static TerminalCaptureTrigger makeTrigger(unsigned long cooldownMs = 2000) {
    return TerminalCaptureTrigger(fakeClock, cooldownMs);
}

// Feed a whole line at once (helper: mirrors a pasted burst).
static CaptureCommand feedLine(TerminalCaptureTrigger& t, const std::string& line) {
    CaptureCommand last;
    for (char c : line) {
        last = t.feed(c);
    }
    last = t.feed('\n');
    return last;
}

// ENTER (an empty line) is the capture trigger.
static void enter_line_triggers_capture() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();
    CaptureCommand cmd = feedLine(t, "");
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Capture, cmd.kind);
}

// "a <uid>" asks to associate the last capture with that card.
static void associate_line_parses_the_uid() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();
    CaptureCommand cmd = feedLine(t, "a ABC123DEF456");
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Associate, cmd.kind);
    TEST_ASSERT_EQUAL_STRING("ABC123DEF456", cmd.arg.c_str());
}

// "e <event_id>" arms card-first classify; digits only.
static void arm_event_line_parses_the_id() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();
    CaptureCommand cmd = feedLine(t, "e 421");
    TEST_ASSERT_EQUAL_INT(CaptureCommand::ArmEvent, cmd.kind);
    TEST_ASSERT_EQUAL_STRING("421", cmd.arg.c_str());
}

// Non-numeric event ids are noise, never half-parsed uploads.
static void non_numeric_event_id_is_rejected() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, feedLine(t, "e 12x9").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, feedLine(t, "e -5").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, feedLine(t, "e").kind);
}

// "c" captures locally without uploading (dev command preserved from
// the reference firmware).
static void local_capture_line() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();
    TEST_ASSERT_EQUAL_INT(CaptureCommand::LocalOnly, feedLine(t, "c").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::LocalOnly, feedLine(t, "C").kind);
}

// Key-repeat: a second ENTER inside the cooldown is swallowed.
static void enter_repeat_inside_cooldown_is_swallowed() {
    fakeNow = 1000;
    TerminalCaptureTrigger t = makeTrigger(2000);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Capture, feedLine(t, "").kind);

    fakeNow = 1500;  // 500 ms later — a held key, not a new bottle
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, feedLine(t, "").kind);

    fakeNow = 9999;  // past the 2 s cooldown — a genuine new bottle
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Capture, feedLine(t, "").kind);
}

// A buffered burst of newlines fires exactly one capture (the burst
// arrives within the cooldown window).
static void buffered_newline_burst_fires_once() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger(2000);

    int fired = 0;
    for (int i = 0; i < 6; i++) {
        if (t.feed('\n').kind == CaptureCommand::Capture) {
            fired++;
        }
    }
    TEST_ASSERT_EQUAL_INT(1, fired);
}

// The cooldown applies to CAPTURE only: associate commands are never
// rate-limited away (an operator may correct a typo immediately).
static void cooldown_never_blocks_associate() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger(60000);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Capture, feedLine(t, "").kind);

    fakeNow = 10;
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Associate, feedLine(t, "a UID1").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Associate, feedLine(t, "a UID2").kind);
}

// A half-typed line without Enter never fires on the next burst — the
// line discipline owns completion.
static void half_typed_line_never_fires() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();
    for (char c : std::string("a AB")) {
        TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feed(c).kind);
    }
    // No newline yet: nothing has fired. A later ENTER completes it.
    CaptureCommand cmd = feedLine(t, "C123");
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Associate, cmd.kind);
    TEST_ASSERT_EQUAL_STRING("ABC123", cmd.arg.c_str());
}

// CRLF endings behave like LF (Serial Monitor line endings).
static void crlf_is_tolerated() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();
    CaptureCommand cmd = t.feed('a');
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, cmd.kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feed('\r').kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feed('\n').kind);
}

// Overlong garbage lines are rejected wholesale — a flood can never
// smuggle a command through.
static void overlong_line_is_rejected_wholesale() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger(0);  // no cooldown: isolate length rule
    std::string flood(200, 'x');
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, feedLine(t, flood).kind);
    // The trigger recovers and behaves after the flood.
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Capture, feedLine(t, "").kind);
}

// Surrounding whitespace is tolerated (terminal padding).
static void whitespace_padding_is_tolerated() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger(0);
    CaptureCommand cmd = feedLine(t, "   a  ABC123   ");
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Associate, cmd.kind);
    TEST_ASSERT_EQUAL_STRING("ABC123", cmd.arg.c_str());
}

namespace {
bool fakePressed = false;
bool fakeLevel() { return fakePressed; }
}  // namespace

static ButtonCaptureTrigger makeButton(unsigned long debounceMs = 50) {
    return ButtonCaptureTrigger(fakeLevel, fakeClock, debounceMs);
}

// A settled press fires exactly once.
static void button_press_fires_once() {
    fakeNow = 0;
    fakePressed = false;
    ButtonCaptureTrigger b = makeButton();
    TEST_ASSERT_FALSE(b.poll());
    fakePressed = true;  // press: edge seen, stability window restarts
    TEST_ASSERT_FALSE(b.poll());
    fakeNow = 49;  // still bouncing
    TEST_ASSERT_FALSE(b.poll());
    fakeNow = 50;  // settled
    TEST_ASSERT_TRUE(b.poll());
}

// Holding the button never refires (edge, not level).
static void button_hold_never_refires() {
    fakeNow = 0;
    fakePressed = true;
    ButtonCaptureTrigger b = makeButton();
    TEST_ASSERT_FALSE(b.poll());  // edge at boot still debounces, not fires
    fakeNow = 1000;
    TEST_ASSERT_TRUE(b.poll());  // the one settled press
    fakeNow = 100000;  // held for ages
    TEST_ASSERT_FALSE(b.poll());
    TEST_ASSERT_FALSE(b.poll());
}

// Contact bounce (rapid toggling) collapses into a single press.
static void button_bounce_fires_once() {
    fakeNow = 0;
    fakePressed = false;
    ButtonCaptureTrigger b = makeButton();
    int fired = 0;
    for (int i = 0; i < 10; i++) {  // 10 ms of chatter
        fakeNow = i;
        fakePressed = (i % 2 == 0);
        if (b.poll()) {
            fired++;
        }
    }
    fakePressed = true;  // then genuinely held
    for (int i = 10; i < 100; i++) {
        fakeNow = i;
        if (b.poll()) {
            fired++;
        }
    }
    TEST_ASSERT_EQUAL_INT(1, fired);
}

// Release + press is a new press (one photo per push).
static void button_release_then_press_fires_again() {
    fakeNow = 0;
    fakePressed = false;
    ButtonCaptureTrigger b = makeButton();
    fakePressed = true;
    TEST_ASSERT_FALSE(b.poll());
    fakeNow = 50;
    TEST_ASSERT_TRUE(b.poll());
    fakeNow = 100;
    fakePressed = false;  // release
    TEST_ASSERT_FALSE(b.poll());
    fakeNow = 200;  // settled released
    TEST_ASSERT_FALSE(b.poll());
    fakeNow = 300;
    fakePressed = true;  // second push
    TEST_ASSERT_FALSE(b.poll());
    fakeNow = 350;
    TEST_ASSERT_TRUE(b.poll());
}


// --- feedLine: the station's dispatch routing primitive -------------------
// The camera station shares one serial line between the capture commands
// and the mode-password console. The dispatcher MUST try the command
// grammar first and hand only unrecognized lines to the password console
// — the reversed order once made a/e/c unreachable (they were eaten as
// wrong-password attempts, poisoning the lockout).

void feedLine_routes_command_lines_to_the_trigger() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();

    TEST_ASSERT_EQUAL_INT(CaptureCommand::Capture, t.feedLine("").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Associate, t.feedLine("a A1B2C3D4E5F6").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::ArmEvent, t.feedLine("e 88").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::LocalOnly, t.feedLine("c").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::LocalOnly, t.feedLine("C").kind);
}

void feedLine_hands_non_command_lines_to_the_console() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();

    // Password-shaped lines yield None — the dispatcher's signal to give
    // the line to the mode console instead.
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feedLine("hunter2").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feedLine("correct horse battery staple").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feedLine("a").kind);          // 'a' without a uid
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feedLine("e").kind);          // 'e' without an id
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feedLine("e -5").kind);       // not digits-only
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feedLine("e 12x9").kind);     // not digits-only
}

void feedLine_leaves_no_residue_after_an_unknown_line() {
    fakeNow = 0;
    TerminalCaptureTrigger t = makeTrigger();

    // A password attempt flows through the trigger's line buffer on its
    // way to the console — the next ENTER afterwards must still be a
    // clean capture, not a half-parsed garbage command.
    TEST_ASSERT_EQUAL_INT(CaptureCommand::None, t.feedLine("wrong-pw").kind);
    TEST_ASSERT_EQUAL_INT(CaptureCommand::Capture, t.feedLine("").kind);
}

void runCaptureTriggerTests() {
    RUN_TEST(enter_line_triggers_capture);
    RUN_TEST(associate_line_parses_the_uid);
    RUN_TEST(arm_event_line_parses_the_id);
    RUN_TEST(non_numeric_event_id_is_rejected);
    RUN_TEST(local_capture_line);
    RUN_TEST(enter_repeat_inside_cooldown_is_swallowed);
    RUN_TEST(buffered_newline_burst_fires_once);
    RUN_TEST(cooldown_never_blocks_associate);
    RUN_TEST(half_typed_line_never_fires);
    RUN_TEST(crlf_is_tolerated);
    RUN_TEST(overlong_line_is_rejected_wholesale);
    RUN_TEST(whitespace_padding_is_tolerated);
    RUN_TEST(button_press_fires_once);
    RUN_TEST(button_hold_never_refires);
    RUN_TEST(button_bounce_fires_once);
    RUN_TEST(button_release_then_press_fires_again);
    RUN_TEST(feedLine_routes_command_lines_to_the_trigger);
    RUN_TEST(feedLine_hands_non_command_lines_to_the_console);
    RUN_TEST(feedLine_leaves_no_residue_after_an_unknown_line);
}
