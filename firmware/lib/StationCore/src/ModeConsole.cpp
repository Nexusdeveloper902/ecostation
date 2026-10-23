#include "ModeConsole.h"

namespace EcoStation {

// --- LineBuffer -------------------------------------------------------------

bool LineBuffer::feed(char c, std::string& lineOut) {
    if (c == '\n' || c == '\r') {
        // A terminator completes whatever is buffered (possibly nothing).
        // An overflowed line completes EMPTY (fully discarded), and the
        // overflow flag latches for the caller's "too long" log.
        lastLineOverflowed_ = discarding_;
        lineOut = discarding_ ? std::string() : buffer_;
        discarding_ = false;
        buffer_.clear();

        // Trim both ends so terminal artifacts (\r, stray spaces) and
        // bare Enters never reach the password comparison or the UID tap.
        const size_t begin = lineOut.find_first_not_of(" \t");
        if (begin == std::string::npos) {
            lineOut.clear();
        } else {
            const size_t end = lineOut.find_last_not_of(" \t");
            lineOut = lineOut.substr(begin, end - begin + 1);
        }
        return true;  // caller decides what an empty completion means
    }

    if (discarding_) {
        return false;  // keep dropping until the terminator arrives
    }

    if (buffer_.size() >= maxLength_) {
        // Overflow: discard this whole line. Everything up to the next
        // terminator is dropped; the completion reports empty + flag.
        discarding_ = true;
        buffer_.clear();
        return false;
    }

    buffer_ += c;
    return false;
}

// --- ModeConsole -------------------------------------------------------------

bool ModeConsole::lockedOut(uint32_t nowMs) const {
    // Wrap-safe: unsigned elapsed-time comparison, no stored deadline.
    return locked_ && (nowMs - lockStartMs_) < lockoutMs_;
}

ConsoleResult ModeConsole::handleLine(const std::string& line, uint32_t nowMs) {
    if (line.empty()) {
        return ConsoleResult::Ignored;  // bare Enter never counts as wrong
    }

    if (locked_) {
        if ((nowMs - lockStartMs_) < lockoutMs_) {
            return ConsoleResult::LockedOut;  // even the correct password
        }
        // Lockout expired: start with a fresh attempt counter.
        locked_ = false;
        wrongAttempts_ = 0;
    }

    if (line == password_) {  // line is non-empty here
        wrongAttempts_ = 0;  // success resets the consecutive-wrong count
        return ConsoleResult::Accepted;
    }

    ++wrongAttempts_;
    if (wrongAttempts_ >= maxWrongAttempts_) {
        // This line is still a Rejection; the caller can detect the new
        // lockout via lockedOut(nowMs) right after and log it.
        lock(nowMs);
    }
    return ConsoleResult::Rejected;
}

}  // namespace EcoStation
