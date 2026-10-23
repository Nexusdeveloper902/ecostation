#include "FeedbackPatterns.h"

namespace EcoStation {

std::vector<LedPhase> modeLedPattern(FeedbackKind state) {
    switch (state) {
        case FeedbackKind::BootConnecting:
            // rapid blink: 100 ON / 100 OFF, forever
            return {{100, true}, {100, false}};
        case FeedbackKind::IdlePairing:
            // double blip every 2 s — clearly distinct from operation
            return {{100, true}, {100, false}, {100, true}, {1700, false}};
        case FeedbackKind::StationDegraded:
            // triple blip every 2 s — alive but degraded; distinct count
            // from operation (1) and pairing (2)
            return {{100, true}, {100, false}, {100, true}, {100, false},
                    {100, true}, {1400, false}};
        case FeedbackKind::IdleOperation:
        default:
            // single short heartbeat blip every 2 s
            return {{100, false}, {100, true}, {1800, false}};
    }
}

std::vector<LedPhase> eventLedPattern(FeedbackKind kind) {
    switch (kind) {
        case FeedbackKind::TapSuccess:
        case FeedbackKind::PairSuccess:
        case FeedbackKind::CaptureSuccess:
            // solid 1.5 s — unmistakable "yes"
            return {{1500, true}};

        case FeedbackKind::TapRejected:
            // 2 blinks
            return {{200, true}, {200, false}, {200, true}, {200, false}};

        case FeedbackKind::PairNoSession:
            // 3 blinks
            return {{200, true}, {200, false}, {200, true}, {200, false},
                    {200, true}, {200, false}};

        case FeedbackKind::PairAlreadyPaired:
            // 4 blinks
            return {{200, true}, {200, false}, {200, true}, {200, false},
                    {200, true}, {200, false}, {200, true}, {200, false}};

        case FeedbackKind::NetworkError:
            // 5 fast blinks
            return {{120, true}, {120, false}, {120, true}, {120, false},
                    {120, true}, {120, false}, {120, true}, {120, false},
                    {120, true}, {120, false}};

        case FeedbackKind::AuthError:
            // 6 fast blinks — distinct count from network error
            return {{120, true}, {120, false}, {120, true}, {120, false},
                    {120, true}, {120, false}, {120, true}, {120, false},
                    {120, true}, {120, false}, {120, true}, {120, false}};

        case FeedbackKind::ServerError:
            // long solid — "backend answered, but wrong"
            return {{2000, true}};

        case FeedbackKind::ModeSwitched:
            // 2 slow blinks — operator action acknowledged; a clearly
            // different tempo from the 200 ms rejection family
            return {{500, true}, {250, false}, {500, true}, {250, false}};

        case FeedbackKind::ModeRejected:
            // 2 very fast blinks — wrong mode password (console)
            return {{80, true}, {80, false}, {80, true}, {80, false}};

        default:
            // idle kinds never reach the event LED
            return {};
    }
}

}  // namespace EcoStation
