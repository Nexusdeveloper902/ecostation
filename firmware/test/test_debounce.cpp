#include <unity.h>

#include <cstring>

#include <string>

#include "CardDebouncer.h"
#include "FeedbackPatterns.h"

// --- card debounce -----------------------------------------------------------

void test_same_uid_within_cooldown_is_ignored(void) {
    EcoStation::CardDebouncer d(2000);

    TEST_ASSERT_TRUE(d.shouldProcess("A1", 1000));
    TEST_ASSERT_FALSE(d.shouldProcess("A1", 1500));   // 500 ms later — same card
    TEST_ASSERT_FALSE(d.shouldProcess("A1", 2900));   // still inside 2000 ms
}

void test_same_uid_after_cooldown_is_accepted(void) {
    EcoStation::CardDebouncer d(2000);

    TEST_ASSERT_TRUE(d.shouldProcess("A1", 1000));
    d.markAbsent();                                    // card removed from reader
    TEST_ASSERT_TRUE(d.shouldProcess("A1", 3100));    // 2100 ms later — deliberate re-tap
}

void test_same_uid_without_absence_is_never_reaccepted(void) {
    // A card resting on the antenna is rejected FOREVER (even past the
    // cooldown) until it is physically removed.
    EcoStation::CardDebouncer d(2000);

    TEST_ASSERT_TRUE(d.shouldProcess("A1", 1000));
    TEST_ASSERT_FALSE(d.shouldProcess("A1", 10000));  // 9 s later, never absent
    TEST_ASSERT_FALSE(d.shouldProcess("A1", 60000));  // a minute later, still resting
}

void test_different_uid_is_accepted_immediately(void) {
    EcoStation::CardDebouncer d(2000);

    TEST_ASSERT_TRUE(d.shouldProcess("A1", 1000));
    TEST_ASSERT_TRUE(d.shouldProcess("B2", 1200));    // different card: no wait
}

void test_card_resting_on_antenna_yields_single_event(void) {
    // Simulates the RC522 re-reporting a resting card every ~100 ms.
    EcoStation::CardDebouncer d(2000);
    uint32_t t = 100000;
    int accepted = 0;

    for (int i = 0; i < 50; ++i) {          // 5 seconds of polling
        if (d.shouldProcess("A1", t)) {
            ++accepted;
        }
        t += 100;
    }

    TEST_ASSERT_EQUAL_INT32(1, accepted);
}

void test_debounce_window_is_measured_from_last_accept(void) {
    // An ignored re-read must NOT extend the cooldown.
    EcoStation::CardDebouncer d(2000);

    TEST_ASSERT_TRUE(d.shouldProcess("A1", 1000));
    d.markAbsent();
    TEST_ASSERT_FALSE(d.shouldProcess("A1", 2500));   // ignored — does not refresh
    TEST_ASSERT_TRUE(d.shouldProcess("A1", 3100));    // 2100 ms after the ACCEPT
}

void test_reset_forgets_last_card(void) {
    EcoStation::CardDebouncer d(2000);

    TEST_ASSERT_TRUE(d.shouldProcess("A1", 1000));
    d.reset();
    TEST_ASSERT_TRUE(d.shouldProcess("A1", 1100));    // reset → immediate re-accept
}

void test_millis_wraparound_is_safe(void) {
    EcoStation::CardDebouncer d(2000);
    const uint32_t nearWrap = 4294966000u;   // ~36 s before the 49.7-day wrap

    TEST_ASSERT_TRUE(d.shouldProcess("A1", nearWrap));
    d.markAbsent();
    // 1500 ms later, across the uint32 wrap boundary:
    TEST_ASSERT_FALSE(d.shouldProcess("A1", nearWrap + 1500));
    // and past the cooldown, still after the wrap:
    TEST_ASSERT_TRUE(d.shouldProcess("A1", nearWrap + 2100));
}

// --- LED patterns ------------------------------------------------------------

void test_mode_patterns_are_distinct_per_mode(void) {
    auto op = EcoStation::modeLedPattern(EcoStation::FeedbackKind::IdleOperation);
    auto pair = EcoStation::modeLedPattern(EcoStation::FeedbackKind::IdlePairing);
    auto boot = EcoStation::modeLedPattern(EcoStation::FeedbackKind::BootConnecting);

    TEST_ASSERT_TRUE(op != pair);
    TEST_ASSERT_TRUE(op != boot);
    TEST_ASSERT_TRUE(pair != boot);
    TEST_ASSERT_TRUE(op.size() >= 2);
    TEST_ASSERT_TRUE(pair.size() >= 2);
}

void test_all_event_kinds_have_nonempty_patterns(void) {
    const EcoStation::FeedbackKind events[] = {
        EcoStation::FeedbackKind::TapSuccess,      EcoStation::FeedbackKind::TapRejected,
        EcoStation::FeedbackKind::PairSuccess,     EcoStation::FeedbackKind::PairNoSession,
        EcoStation::FeedbackKind::PairAlreadyPaired, EcoStation::FeedbackKind::AuthError,
        EcoStation::FeedbackKind::NetworkError,    EcoStation::FeedbackKind::ServerError,
        EcoStation::FeedbackKind::ModeSwitched,    EcoStation::FeedbackKind::ModeRejected,
    };
    for (EcoStation::FeedbackKind k : events) {
        TEST_ASSERT_TRUE(!EcoStation::eventLedPattern(k).empty());
    }
}

void test_rejected_patterns_have_increasing_blink_counts(void) {
    // 404 (2) < 409 (3) < 422 (4): a human can count the blinks.
    auto rejected = EcoStation::eventLedPattern(EcoStation::FeedbackKind::TapRejected);
    auto noSession = EcoStation::eventLedPattern(EcoStation::FeedbackKind::PairNoSession);
    auto alreadyPaired = EcoStation::eventLedPattern(EcoStation::FeedbackKind::PairAlreadyPaired);

    TEST_ASSERT_TRUE(rejected.size() < noSession.size());
    TEST_ASSERT_TRUE(noSession.size() < alreadyPaired.size());
}

// --- pattern player ----------------------------------------------------------

void test_pattern_player_oneshot_finishes(void) {
    EcoStation::PatternPlayer p;
    std::vector<EcoStation::LedPhase> pattern = {{100, true}, {100, false}};
    p.start(pattern, false);

    bool level = false;
    TEST_ASSERT_TRUE(p.step(1000, level));
    TEST_ASSERT_TRUE(level);                          // phase 1: ON
    TEST_ASSERT_TRUE(p.step(1050, level));
    TEST_ASSERT_TRUE(level);
    TEST_ASSERT_TRUE(p.step(1100, level));
    TEST_ASSERT_FALSE(level);                         // phase 2: OFF
    TEST_ASSERT_FALSE(p.step(1300, level));           // finished
    TEST_ASSERT_FALSE(level);
    TEST_ASSERT_FALSE(p.step(20000, level));          // stays finished
}

void test_pattern_player_loops_forever(void) {
    EcoStation::PatternPlayer p;
    std::vector<EcoStation::LedPhase> pattern = {{100, true}, {100, false}};
    p.start(pattern, true);

    bool level = false;
    for (uint32_t t = 1000; t <= 60000; t += 50) {    // 59 s of stepping
        p.step(t, level);
    }
    // Still playing (looping), never finished:
    bool playing = p.step(60100, level);
    TEST_ASSERT_TRUE(playing);
}

void test_pattern_player_empty_pattern_is_finished(void) {
    EcoStation::PatternPlayer p;
    p.start({}, true);

    bool level = true;
    TEST_ASSERT_FALSE(p.step(0, level));
    TEST_ASSERT_FALSE(level);
}

void test_pattern_player_off_phase_of_operation_idle(void) {
    // The operation idle pattern starts OFF for 100 ms — a steady, quiet
    // baseline for the mode LED.
    auto pattern = EcoStation::modeLedPattern(EcoStation::FeedbackKind::IdleOperation);
    TEST_ASSERT_FALSE(pattern.front().on);

    EcoStation::PatternPlayer p;
    p.start(pattern, true);

    bool level = false;
    TEST_ASSERT_TRUE(p.step(500, level));
    TEST_ASSERT_FALSE(level);
}

void runDebounceTests() {
    RUN_TEST(test_same_uid_within_cooldown_is_ignored);
    RUN_TEST(test_same_uid_after_cooldown_is_accepted);
    RUN_TEST(test_same_uid_without_absence_is_never_reaccepted);
    RUN_TEST(test_different_uid_is_accepted_immediately);
    RUN_TEST(test_card_resting_on_antenna_yields_single_event);
    RUN_TEST(test_debounce_window_is_measured_from_last_accept);
    RUN_TEST(test_reset_forgets_last_card);
    RUN_TEST(test_millis_wraparound_is_safe);
    RUN_TEST(test_mode_patterns_are_distinct_per_mode);
    RUN_TEST(test_all_event_kinds_have_nonempty_patterns);
    RUN_TEST(test_rejected_patterns_have_increasing_blink_counts);
    RUN_TEST(test_pattern_player_oneshot_finishes);
    RUN_TEST(test_pattern_player_loops_forever);
    RUN_TEST(test_pattern_player_empty_pattern_is_finished);
    RUN_TEST(test_pattern_player_off_phase_of_operation_idle);
}
