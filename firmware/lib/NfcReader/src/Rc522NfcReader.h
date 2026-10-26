/**
 * Rc522NfcReader.h — concrete NFC reader: MFRC522 over SPI.
 * Rc522NfcReader.h — lector NFC concreto: MFRC522 por SPI.
 *
 * Uses the well-established miguelbalboa/rfid Arduino library. Pins come
 * from include/config.h (PIN_RC522_SS / PIN_RC522_RST / PIN_RC522_SCK /
 * PIN_RC522_MISO / PIN_RC522_MOSI + hardware VSPI defaults).
 * / Usa la librería miguelbalboa/rfid. Los pines vienen de config.h.
 *
 * Two credential paths, one poll() (HCE integration):
 *   physical — a MIFARE Classic UID read off the RF layer (as before);
 *   hce      — an Android phone answering as an ISO-DEP target: SELECT
 *              AID F0010203040506 + random 8-byte CHALLENGE, whose
 *              HMAC-SHA256(K_cred, credId || nonce) answer is RELAYED to
 *              el servidor (lastProof()) — this reader holds no HCE key
 *              (TASK-015, ADR-018). In pairing mode it also fetches the
 *              phone's key once (ENROLL) and wraps it. The phone's RF
 *              UID is randomized per tap by Android: it is logged only as
 *              a length and NEVER used as identity — lastKind() reports
 *              "hce" and poll() returns the application-level credential
 *              id instead. See docs/HCE_PROTOCOL.md (canonical spec) and
 *              lib/StationCore HceProtocol (host-testable APDU logic).
 *
 * Robustness beyond init (TASK-002): the reader tracks its own health.
 * A failed init (wiring, power) or a reader that dies at runtime is
 * retried on the RC522_REINIT_INTERVAL_MS cadence — non-blocking, so the
 * device self-heals without a reboot. Diagnostic events (init ok/failed,
 * recovery) go to the injected Print stream (Serial in production) or
 * nowhere if none is injected.
 * / Robustez (TASK-002): el lector vigila su propia salud. Un init
 * fallido (cableado, alimentación) o un lector que muere en ejecución se
 * reintenta cada RC522_REINIT_INTERVAL_MS — no bloqueante, el equipo se
 * recupera sin reiniciar. Los diagnósticos van al Print inyectado.
 */
#pragma once

#include <Arduino.h>

#include <MFRC522Extended.h>
#include <SPI.h>
#include <esp_system.h>  // esp_random() — fresh challenge nonce per tap
#include <cstring>       // memcpy/memset — ISO-DEP frame stripping

#include "HceProtocol.h"
#include "NfcReader.h"
#include "RequestSigner.h"  // Signer::toHex — relay proof encoding
#include "config.h"

// TASK-015 (ADR-018): there is NO HCE secret on the reader any more. Each
// phone has its own key (Android Keystore) and el servidor verifies the
// relayed transcript with it. An old secrets file that still defines
// HCE_SECRET is simply ignored. / Ya no hay secreto HCE en el lector.

namespace EcoStation {

class Rc522NfcReader : public NfcReader {
public:
    // rstPin of -1 (255/UINT8_MAX = MFRC522 UNUSED_PIN) means RST is
    // strapped to 3V3 — PCD_Init then uses soft reset only, no pin driven.
    explicit Rc522NfcReader(uint8_t ssPin = PIN_RC522_SS,
                            uint8_t rstPin = PIN_RC522_RST,
                            uint8_t sckPin = PIN_RC522_SCK,
                            uint8_t misoPin = PIN_RC522_MISO,
                            uint8_t mosiPin = PIN_RC522_MOSI,
                            Print* log = nullptr)
        : mfrc522_(ssPin, rstPin),
          sckPin_(sckPin),
          misoPin_(misoPin),
          mosiPin_(mosiPin),
          ssPin_(ssPin),
          log_(log) {}

    bool begin() override {
        // Explicit pins: non-default wiring is changed in config.h only.
        // / Pines explícitos: cableado no estándar se cambia en config.h.
        SPI.begin(sckPin_, misoPin_, mosiPin_, ssPin_);
        return reinit(millis());
    }

    bool poll(std::string& uidOut) override {
        if (!healthy_) {
            // Reader not talking (failed init, or died later: wiring
            // glitch, ESD, brown-out). Retry on the configured cadence —
            // non-blocking, wrap-safe unsigned arithmetic.
            // / Lector sin respuesta. Reintento con la cadencia
            // configurada — no bloqueante, aritmética sin desbordamiento.
            const uint32_t now = millis();
            if (now - lastInitAttemptMs_ >= RC522_REINIT_INTERVAL_MS) {
                reinit(now);  // logs the recovery when it succeeds
            }
            return false;
        }

        // Runtime health probe: "healthy" is only meaningful if it can be
        // REVOKED. Once a boot-time probe succeeded, nothing ever re-checked
        // the chip — a reader that died mid-day (ESD, power glitch) stayed
        // 'healthy' forever and taps silently did nothing. A VersionReg read
        // is one register transaction: every RC522_REINIT_INTERVAL_MS it
        // re-verifies the chip, and a dead chip flips healthy_ so the reinit
        // branch above takes over. Probed at the TOP of poll() — never
        // between IsNewCardPresent and ReadCardSerial.
        const uint32_t now = millis();
        if (now - lastProbeMs_ >= RC522_REINIT_INTERVAL_MS) {
            lastProbeMs_ = now;
            version_ = mfrc522_.PCD_ReadRegister(MFRC522::PCD_Register::VersionReg);
            if (version_ == 0x00 || version_ == 0xFF) {
                healthy_ = false;  // chip stopped answering — reinit cadence engages
                if (log_) {
                    log_->println("[NFC] el RC522 dejó de responder en ejecución — reintentando");
                }
                return false;
            }
        }

        // HCE park-and-activate (bench-proven constraints — read all).
        //
        // 1. Once THIS controller is HaltA'd it answers NOTHING (RATS,
        //    WUPA, even REQA) until field re-entry. Every post-halt
        //    retry is aimed at a corpse — the old sweep proved it.
        // 2. The driver's ReadCardSerial fires RATS microseconds after
        //    SELECT and HaltAs on timeout, poisoning the tap's only
        //    winnable chance. So discovery NEVER uses it: the qualified
        //    base Select below does anticollision + SELECT with NO RATS
        //    and NO surprise HaltA (identical frames to the override for
        //    anything that isn't ISO-DEP — the MIFARE path is untouched).
        // 3. Hence: the FIRST RATS of a tap goes out LATE (~400 ms after
        //    discovery), on an undisturbed selected session, with no
        //    HaltA before it. If the controller needs boot time after
        //    field entry, this is where it gets it.
        if (hceSettling_) {
            if (millis() - hceSeenMs_ < HCE_SETTLE_MS) {
                // Radio silence till settle ends — but NEVER spin: this
                // branch does zero RF and zero delay, and a ~100 kHz
                // no-yield spin trips the task watchdog (observed
                // TG1WDT_SYS_RESET mid-settle on the bench). delay()
                // yields; 5 ms granularity is plenty for a 400 ms window.
                delay(5);
                return false;
            }
            hceSettling_ = false;
            // Reset-locator (hce.11). adoptAts() runs BEFORE it logs
            // anything, so a reset between "spotted" and the RATS verdict
            // is either the settle loop or the RATS path. This line
            // splits them — see it and reset => RATS; miss it and reset
            // => the settle wait.
            // requestAtsSafe() (below) replaced the vendor
            // PICC_RequestATS, whose unconditional `bufferSize - 2` copy
            // overflowed the ATS struct on a CRC error. That was the
            // TG1WDT_SYS_RESET observed right after this line.
            // / Localizador de reinicios: separa el bucle de espera del
            //   RATS. requestAtsSafe() sustituye al driver, cuyo copiado
            //   desbordaba el ATS al fallar la CRC (el TG1WDT de aquí).
            if (log_) {
                log_->println("[NFC] espera cumplida — lanzando RATS ahora");
            }
            adoptAts(HCE_SETTLE_MS);
        } else {
            if (!mfrc522_.PICC_IsNewCardPresent()) {
                return false;
            }
            if (mfrc522_.MFRC522::PICC_Select(&mfrc522_.tag.uid) != MFRC522::STATUS_OK) {
                return false;
            }
            if (sakPromisesIsoDep() && atsMissing()) {
                // ISO-DEP sighted. Park it UNTOUCHED (no RATS yet, no
                // HaltA — both would end the session before it starts):
                // the target stays selected while its controller finishes
                // booting HCE routing. A card tapped during these ~ms
                // waits one settle window — bounded, rare, accepted.
                hceSeenMs_ = millis();
                hceSettling_ = true;
                if (log_) {
                    log_->print("[NFC] objetivo HCE detectado, esperando ");
                    log_->print(HCE_SETTLE_MS);
                    log_->println(" ms (silencio de radio hasta entonces)");
                }
                return false;
            }
        }

        // Bench diagnostic: one line per target BEFORE branching, so a
        // failed phone tap tells which side failed without guessing:
        //   SAK=0x08 ATS=0B → the phone is not offering ISO-DEP at all
        //     (HCE service not engaged: app state, NFC, or device support).
        //   SAK=0x20 ATS=0B → RATS adoption failed (read the RATS status
        //     lines above: TIMEOUT = phone mute/halted, else signal).
        // SAK=0x20 + ATS>0 continues into the HCE branch below.
        if (log_) {
            log_->print("[NFC] objetivo SAK=0x");
            if (mfrc522_.tag.uid.sak < 0x10) {
                log_->print('0');
            }
            log_->print(mfrc522_.tag.uid.sak, HEX);
            log_->print(" ATS=");
            log_->print(mfrc522_.tag.ats.size);
            log_->print("B UID=");
            log_->print(mfrc522_.tag.uid.size);
            log_->println("B");
        }

        // HCE integration: SAK bit 6 set + ATS present means an ISO-DEP
        // target (an Android HCE phone) — the application-level APDU
        // exchange decides the identity, never the RF UID below.
        // / Integración HCE: bit 6 del SAK + ATS = objetivo ISO-DEP (un
        // teléfono HCE) — la identidad la decide el intercambio APDU.
        // The SAK test mirrors the driver's own ((sak & 0x24) == 0x20:
        // T=CL offered AND UID complete).
        if (sakPromisesIsoDep()) {
            if (atsMissing()) {
                // Bench bug caught in the hce.8 log: a SAK=0x20 target
                // whose RATS failed used to FALL THROUGH to the MIFARE
                // branch below, which hexes tag.uid and reports it as a
                // real tap. On an Android phone that UID is an
                // OS-assigned RANDOM id (08-prefixed, the NFC Forum
                // random-ID range — see the PAIRING troubleshooting
                // section): a different value every tap, meaningless as a
                // credential. It reached the backend as 08BCAB46 and came
                // back 404, i.e. a failed phone activation was being
                // posted to the event spine as an unknown card. A phone
                // that did not activate is a FAILED TAP, never a card.
                // / Un teléfono cuyo RATS falló es un toque fallido, nunca
                // una tarjeta: su UID es aleatorio por toque.
                if (log_) {
                    log_->println("[NFC] objetivo ISO-DEP sin ATS (RATS falló) — toque fallido, no es una tarjeta");
                }
                mfrc522_.PICC_HaltA();
                return false;
            }
            return pollHce(uidOut);
        }

        // NOTE: reads tag.uid — discovery now uses the qualified base
        // Select (no driver RATS), which fills the passed Uid directly.
        // Same bytes the override's backward-compat copy used to hand us.
        uidOut.clear();
        uidOut.reserve(mfrc522_.tag.uid.size * 2);
        static const char* hex = "0123456789ABCDEF";
        for (byte i = 0; i < mfrc522_.tag.uid.size; ++i) {
            uidOut += hex[mfrc522_.tag.uid.uidByte[i] >> 4];
            uidOut += hex[mfrc522_.tag.uid.uidByte[i] & 0x0F];
        }
        lastKind_ = "physical";
        lastProof_.clear();

        // Release the card and stop any crypto session so the next tap works.
        mfrc522_.PICC_HaltA();
        mfrc522_.PCD_StopCrypto1();
        return true;
    }

    const char* label() const override { return "RC522 (SPI)"; }

    const char* lastKind() const override { return lastKind_; }

    const HceProof& lastProof() const override { return lastProof_; }

    void setEnrollment(bool on, const std::string& wrapSecret) override {
        enrollment_ = on;
        wrapSecret_ = on ? wrapSecret : std::string();
    }

    /** True when the last VersionReg probe answered (station health). */
    bool healthy() const { return healthy_; }

    /** Last PCD firmware-version byte seen (0 when never probed). */
    uint8_t lastVersion() const { return version_; }

private:
    /**
     * HCE tap: SELECT the EcoStation AID, challenge the phone with a fresh
     * random nonce, and capture its len + credId + MAC answer as the
     * relay proof (lastProof_). NOT verified here — the reader holds no
     * key; el servidor checks it with the credential's own key (ADR-018).
     * In pairing mode, ENROLL then fetches the phone's key, which is
     * wrapped under this reader's API key and wiped from RAM.
     * On success uidOut holds the application-level credential id and
     * lastKind_ is "hce". Any failure releases the target and returns
     * false — a MIFARE tap is never affected (that path never runs here).
     * / Toque HCE: SELECT del AID EcoStation, challenge aleatorio; la respuesta
     * se retransmite al backend, que la verifica con la llave propia.
     */
    bool pollHce(std::string& uidOut) {
        // The phone randomizes its RF UID per tap (Android HCE): log the
        // length only, to prove discovery while proving nothing uses it.
        // / El teléfono aleatoriza su UID RF: solo se registra la longitud.
        if (log_) {
            log_->print("[NFC] objetivo ISO-DEP, UID ");
            log_->print(mfrc522_.tag.uid.size);
            log_->println(" bytes (aleatorio por toque en HCE, se ignora como identidad)");
        }

        // From here the driver TRANSMITS ONLY — we own every byte received.
        // See setPcdTimeoutMs for why; releaseHce() restores the stock value
        // before the MIFARE path can ever see it.
        setPcdTimeoutMs(kHceTxOnlyTimeoutMs);

        uint8_t cmd[16], resp[64];
        uint8_t respLen = sizeof(resp);
        const uint8_t cmdLen = static_cast<uint8_t>(Hce::buildSelectAid(cmd, sizeof(cmd)));
        // BEWARE the mutual-timeout race (bench-proven): our RX window is
        // only ~10 ms, while a warm app answer takes several ms via
        // controller → DH → app → DH → controller — and the PHONE runs
        // its own frame-wait timer, tearing the session down if our next
        // block is late (that's why the app logs exactly ONE select-aid:
        // retries 200 ms apart arrive at a dead session). So retries go
        // TIGHT (~15 ms apart): continuous RX coverage for slow answers
        // AND each arrival resets the phone's timer, keeping the session
        // alive. Safe by driver construction: the block number toggles
        // ONLY on success, so a timed-out attempt reuses the number the
        // phone expects; and our SELECT is idempotent app-side anyway.
        MFRC522::StatusCode sc =
            tclWithRetries(cmd, cmdLen, resp, &respLen, sizeof(resp), 10, 15, "SELECT");
        if (sc != MFRC522::STATUS_OK) {
            if (log_) {
                log_->print("[NFC] SELECT HCE falló: ");
                log_->println(mfrc522_.GetStatusCodeName(sc));
            }
            releaseHce();
            return false;
        }
        const Hce::SelectOutcome outcome = Hce::parseSelectResponse(resp, respLen);
        if (outcome != Hce::SelectOutcome::Selected) {
            if (log_) {
                // Reported identically until hce.9, which cost several bench
                // cycles: a MALFORMED answer is a reader-side framing/read
                // fault, NOT the phone refusing our AID. Never conflate them
                // again. / No confundir: malformado = fallo nuestro.
                if (outcome == Hce::SelectOutcome::UnknownAid) {
                    log_->println("[NFC] SELECT HCE -> 6A82: el teléfono no enrutó ninguna app a nuestro AID (no es un teléfono EcoStation, o el enrutado HCE está apagado)");
                } else {
                    log_->print("[NFC] SELECT HCE respuesta mal formada (");
                    log_->print(respLen);
                    log_->println("B INF) — fallo de trama del lector, no del teléfono");
                }
            }
            releaseHce();
            return false;
        }

        // Fresh random challenge per tap. The nonce travels in the clear
        // over NFC, so logging it is safe (prototype behavior). No key is
        // involved on this side at all (ADR-018).
        uint8_t nonce[Hce::NONCE_LEN];
        for (size_t i = 0; i < Hce::NONCE_LEN; i++) {
            nonce[i] = static_cast<uint8_t>(esp_random());
        }
        if (log_) {
            log_->print("[NFC] nonce del reto HCE: ");
            for (size_t i = 0; i < Hce::NONCE_LEN; i++) {
                if (nonce[i] < 0x10) {
                    log_->print('0');
                }
                log_->print(nonce[i], HEX);
                if (i + 1 < Hce::NONCE_LEN) {
                    log_->print(' ');
                }
            }
            log_->println();
        }
        const uint8_t chalLen = static_cast<uint8_t>(Hce::buildChallenge(nonce, cmd, sizeof(cmd)));
        respLen = sizeof(resp);
        // Same race as SELECT (see above): session is live and warm here,
        // so fewer, slightly wider-spaced attempts suffice.
        sc = tclWithRetries(cmd, chalLen, resp, &respLen, sizeof(resp), 6, 25, "CHALLENGE");
        if (sc != MFRC522::STATUS_OK) {
            if (log_) {
                log_->print("[NFC] tiempo de espera del RETO HCE: ");
                log_->println(mfrc522_.GetStatusCodeName(sc));
            }
            releaseHce();
            return false;
        }

        Hce::ChallengeResponse answer;
        if (!Hce::parseChallengeResponse(resp, respLen, answer)) {
            if (log_) {
                if (Hce::isKeyMissing(resp, respLen)) {
                    log_->println("[NFC] HCE 6A88: el teléfono no tiene llave: en la app toca «Vincular este teléfono» y empareja en modo EMPAREJAR");
                } else {
                    log_->println("[NFC] respuesta al RETO HCE mal formada");
                }
            }
            releaseHce();
            return false;
        }

        lastProof_.clear();
        lastProof_.nonceHex = Signer::toHex(nonce, Hce::NONCE_LEN);
        lastProof_.macHex = Signer::toHex(answer.mac, Hce::HMAC_LEN);

        if (enrollment_ && !collectKey(answer.credId, cmd, sizeof(cmd), resp, sizeof(resp))) {
            lastProof_.clear();
            releaseHce();
            return false;
        }

        // Identified (proof relayed, verified server-side): the credential
        // id — never the RF UID — is the tap.
        uidOut = answer.credId;
        lastKind_ = "hce";
        if (log_) {
            log_->print("[NFC] credencial HCE leída (prueba retransmitida al servidor): ");
            log_->println(uidOut.c_str());
        }
        releaseHce();
        return true;
    }

    /**
     * True when the target promises ISO-DEP: SAK bit 6 set (T=CL) with
     * bit 3 clear (UID complete) — the driver's own test.
     */
    bool sakPromisesIsoDep() const {
        return (mfrc522_.tag.uid.sak & 0x24) == 0x20;
    }

    /** True while no ATS has been adopted yet (tag.ats.size == 0). */
    bool atsMissing() const { return mfrc522_.tag.ats.size == 0; }

    /**
     * RATS with the vendor's overflow fixed.
     *
     * MFRC522Extended::PICC_RequestATS (1.4.12) keeps decoding after
     * PCD_TransceiveData fails. On a CRC error the driver has already set
     * bufferSize to the number of bytes it read — often 0 or 1 — and then
     * does:
     *
     *     memcpy(ats->data, bufferATS, bufferSize - 2);
     *
     * bufferSize is a byte, so 0 - 2 wraps to 254 and writes 254 bytes
     * into the 62-byte Ats::data field on the caller's stack. That is the
     * TG1WDT_SYS_RESET seen immediately after "lanzando RATS ahora": the
     * returned frame was a partial/WTX answer, not an ATS, and the copy
     * smashed the loop task's stack. Do the transceive here and return on
     * every failure BEFORE touching `ats`; also reject frames whose
     * declared ATS length does not match the received length (an S(WTX)
     * has a valid CRC but is not an ATS).
     * / RATS con el desbordamiento del driver corregido.
     */
    MFRC522::StatusCode requestAtsSafe(MFRC522Extended::Ats& ats) {
        byte buffer[MFRC522::FIFO_SIZE];
        byte received = MFRC522::FIFO_SIZE;
        memset(buffer, 0, sizeof(buffer));

        buffer[0] = MFRC522::PICC_CMD_RATS;
        buffer[1] = 0x50;  // FSDI=5 (64 B), CID=0

        MFRC522::StatusCode sc =
            mfrc522_.PCD_CalculateCRC(buffer, 2, &buffer[2]);
        if (sc != MFRC522::STATUS_OK) {
            return sc;
        }

        sc = mfrc522_.PCD_TransceiveData(buffer, 4, buffer, &received,
                                         nullptr, 0, true);
        if (sc != MFRC522::STATUS_OK) {
            mfrc522_.PICC_HaltA();
            return sc;
        }

        // A valid ATS is: T0 (1 B) + optional TA/TB/TC + CRC_A (2 B).
        // Its first byte is the ATS length without the CRC. Anything that
        // does not agree is a lower-layer frame (S(WTX), NAK, noise) and
        // must not be adopted as an ATS.
        const byte atsSize = buffer[0];
        if (atsSize < 1 || atsSize > sizeof(ats.data) ||
            static_cast<byte>(atsSize + 2) != received) {
            mfrc522_.PICC_HaltA();
            return MFRC522::STATUS_CRC_WRONG;
        }

        ats.size = atsSize;
        memcpy(ats.data, buffer, atsSize);

        const byte t0 = buffer[1];
        ats.ta1.transmitted = (t0 & 0x40) != 0;
        ats.tb1.transmitted = (t0 & 0x20) != 0;
        ats.tc1.transmitted = (t0 & 0x10) != 0;

        switch (t0 & 0x0F) {
            case 0x00: ats.fsc = 16; break;
            case 0x01: ats.fsc = 24; break;
            case 0x02: ats.fsc = 32; break;
            case 0x03: ats.fsc = 40; break;
            case 0x04: ats.fsc = 48; break;
            case 0x05: ats.fsc = 64; break;
            case 0x06: ats.fsc = 96; break;
            case 0x07: ats.fsc = 128; break;
            default: ats.fsc = 32; break;  // FSCI 8+ cannot fit the FIFO
        }

        byte index = 2;  // first optional byte, after T0
        if (ats.ta1.transmitted) {
            if (index >= atsSize) {
                mfrc522_.PICC_HaltA();
                return MFRC522::STATUS_CRC_WRONG;
            }
            const byte ta1 = buffer[index++];
            ats.ta1.sameD = (ta1 & 0x80) != 0;
            ats.ta1.ds = static_cast<MFRC522Extended::TagBitRates>(
                (ta1 & 0x70) >> 4);
            ats.ta1.dr = static_cast<MFRC522Extended::TagBitRates>(
                ta1 & 0x07);
        } else {
            ats.ta1.ds = MFRC522Extended::BITRATE_106KBITS;
            ats.ta1.dr = MFRC522Extended::BITRATE_106KBITS;
        }

        if (ats.tb1.transmitted) {
            if (index >= atsSize) {
                mfrc522_.PICC_HaltA();
                return MFRC522::STATUS_CRC_WRONG;
            }
            const byte tb1 = buffer[index++];
            ats.tb1.fwi = (tb1 & 0xF0) >> 4;
            ats.tb1.sfgi = tb1 & 0x0F;
        }

        if (ats.tc1.transmitted) {
            if (index >= atsSize) {
                mfrc522_.PICC_HaltA();
                return MFRC522::STATUS_CRC_WRONG;
            }
            const byte tc1 = buffer[index++];
            ats.tc1.supportsCID = (tc1 & 0x02) != 0;
            ats.tc1.supportsNAD = (tc1 & 0x01) != 0;
        } else {
            // No TC1: CID is supported by default (ISO/IEC 14443-4).
            ats.tc1.supportsCID = true;
            ats.tc1.supportsNAD = false;
        }

        return MFRC522::STATUS_OK;
    }

    /**
     * Adopt the ATS the driver drops (the actual bench blocker).
     *
     * MFRC522 1.4.12's PICC_Select parses a good ATS into a STACK LOCAL
     * and never stores it in tag — so tag.ats.size is ALWAYS 0 through
     * the driver path, and any `ats.size > 0` gate (ours, and the
     * standalone prototype's) is dead code with this library version.
     * RATS itself was almost certainly succeeding all along: the phone
     * answers, the driver even PPS-negotiates internally — then throws
     * the bytes away.
     *
     * Fix: re-request RATS on the still-selected target (a legal RATS
     * retransmission) and keep the result in tag.ats ourselves, where
     * TCL_Transceive expects it (tc1.supportsCID + blockNumber). The
     * logged status is also the first honest RATS diagnosis on this
     * bench: STATUS_TIMEOUT = phone mute/halted, anything else =
     * signal trouble. Deliberately no PPS: 106 kbit/s is mandatory and
     * plenty for our <70 B exchanges — one less moving part.
     */
    void adoptAts(uint32_t settleMs) {
        MFRC522Extended::Ats ats;
        memset(&ats, 0, sizeof(ats));
        const MFRC522::StatusCode sc = requestAtsSafe(ats);
        if (log_) {
            log_->print("[NFC] RATS (espera ");
            log_->print(settleMs);
            log_->print(" ms) -> ");
            log_->print(mfrc522_.GetStatusCodeName(sc));
            if (sc == MFRC522::STATUS_OK) {
                log_->print(" ATS=");
                log_->print(ats.size);
                log_->print("B");
            }
            log_->println();
        }
        if (sc == MFRC522::STATUS_OK && ats.size > 1) {
            mfrc522_.tag.ats = ats;
            mfrc522_.tag.blockNumber = false;
        }
    }

    /**
     * DO NOT extend the PCD receive timer to the phone's advertised FWT.
     * Tried in hce.12/13 and REVERTED — it looks correct and it breaks
     * the reader.
     *
     * The reasoning was sound on paper: PCD_Init hardcodes TReloadReg to
     * 25 ms for every target while ISO/IEC 14443-4 §7.2 has the PICC
     * declare its own budget as FWI in ATS TB(1) (this phone says FWI=7,
     * ~39 ms), so honoring it should keep the receiver armed through a
     * slow HCE answer. What actually happened, measured:
     *
     *   25 ms timer (stock): ComIrq=0x44, RxIRq CLEAR — the driver gives
     *     up having received nothing, the S(WTX) lands a moment later,
     *     and salvageLateAnswer (which understands S-blocks) harvests it.
     *     SELECT succeeded on 100% of taps.
     *   40 ms timer (FWT):   ComIrq=0x64, RxIRq SET, "CRC_A does not
     *     match" x10 — TCL_Transceive now stays in its loop long enough
     *     to CONSUME the S(WTX) itself. It has no notion of S-blocks, so
     *     it mangles the frame and empties the FIFO; salvage then finds
     *     FIFO=0B and never runs. SELECT succeeded on 0% of taps.
     *
     * So the library's "too short" timer is load-bearing: it makes the
     * driver bail BEFORE the WTX arrives, leaving the frame to code that
     * can actually handle it. Leave TReloadReg alone.
     * / NO ampliar el temporizador al FWT del teléfono: el driver se come
     *   el S(WTX) y lo corrompe. El timer corto es intencionadamente útil.
     */

    /**
     * Program the PCD timer from the FRAME WAITING TIME the phone itself
     * advertised, instead of the library's fixed 25 ms.
     *
     * PCD_Init hardcodes TReloadReg = 1000 ticks @ 40 kHz = 25 ms for
     * every target and ignores the ATS entirely — but ISO/IEC 14443-4
     * §7.2 says the PICC declares its own budget as FWI in ATS TB(1):
     * FWT = (256 x 16 / fc) x 2^FWI = 302 us x 2^FWI. An Android HCE
     * stack routinely advertises far more than 25 ms because its answer
     * crosses controller -> DH -> app -> back, and this bench measured
     * exactly that (13-16 ms, sometimes preceded by an S(WTX) asking for
     * more still). Tearing the receiver down at 25 ms is why late answers
     * had to be salvaged out of the FIFO at all.
     *
     * Note this does NOT remove the salvage path: the driver ALSO bails
     * on its own hardcoded 36 ms software deadline
     * (PCD_CommunicateWithPICC), which lives in vendored code. Keeping
     * the receiver armed simply means the late frame is reliably SITTING
     * in the FIFO when salvage goes looking for it.
     * / Programa el temporizador con el FWT que anuncia el teléfono.
     */

    /**
     * I-block exchange with bounded same-block retries (see pollHce).
     * Each attempt is logged; only the final outcome decides. MIFARE
     * never calls this (UID path only).
     *
     * Bench fact driving the salvage below: FIFO=4B arrived just AFTER
     * our ~10 ms RX window closed (seen in the post-failure dump) — the
     * phone DOES answer SELECT, ~13-16 ms after our TX, i.e. just late.
     * So on every TCL timeout we wait out the slow answer and harvest
     * it straight from the FIFO with our own CRC check (CRC gates
     * everything: a partial/garbled harvest can never false-accept).
     * Attempts stay tight (~15-25 ms apart) so each arrival also resets
     * the phone's frame-wait timer and the session stays alive.
     */
    MFRC522::StatusCode tclWithRetries(uint8_t* cmd, uint8_t cmdLen,
                                        uint8_t* resp, uint8_t* respLen, uint8_t respCap,
                                        uint8_t attempts, uint16_t retryDelayMs,
                                        const char* what) {
        MFRC522::StatusCode sc = MFRC522::STATUS_TIMEOUT;
        for (uint8_t i = 0; i < attempts; ++i) {
            if (i > 0) {
                delay(retryDelayMs);
            }
            *respLen = respCap;
            sc = mfrc522_.TCL_Transceive(&mfrc522_.tag, cmd, cmdLen, resp, respLen);
            if (sc != MFRC522::STATUS_OK && log_) {
                log_->print("[NFC] HCE ");
                log_->print(what);
                log_->print(" intento ");
                log_->print(i + 1);
                log_->print(" -> ");
                log_->println(mfrc522_.GetStatusCodeName(sc));
                dumpRxState();
            }
            if (sc != MFRC522::STATUS_OK) {
                sc = salvageLateAnswer(resp, respLen, respCap, what);
            }
            if (sc == MFRC522::STATUS_OK) {
                break;
            }
        }
        return sc;
    }

    /**
     * Harvest a slow answer that finished arriving after our RX window
     * closed (see tclWithRetries). Waits out the phone's DH/app path,
     * then reads whatever sits in the FIFO and CRC-checks it exactly
     * like the driver would. Returns OK only on a complete,
     * CRC-valid frame — anything else keeps the attempts's verdict.
     * Read-only w.r.t. protocol state (no block-number/sequence side
     * effects); the next retry re-flushes the FIFO regardless.
     */
    MFRC522::StatusCode salvageLateAnswer(uint8_t* resp, uint8_t* respLen, uint8_t respCap,
                                           const char* what) {
        // hce.9 bench log, the two facts this function now exists to handle:
        //
        // 1. The phone answers SELECT correctly but LATE, and the frame it
        //    sends is a full ISO-DEP frame: 0A 00 "HCE-OK" 9000 C8 C0 =
        //    PCB + CID + INF + CRC. TCL_Transceive would have stripped the
        //    prologue and CRC for us; harvesting the FIFO ourselves does
        //    not, so we MUST strip them here. Handing the raw frame to
        //    parseSelectResponse (which wants the bare 8-byte INF) is what
        //    made a perfect answer read as "not a EcoStation phone".
        // 2. Most taps answer FA 00 01 D3 4B = S(WTX): "I need more time."
        //    MFRC522Extended never answers WTX (its documented gap), so the
        //    exchange died there every time. Per ISO/IEC 14443-4 §7.5.4.3
        //    the PCD must echo an S(WTX) response and keep waiting — then
        //    the app's real answer follows.
        // / El teléfono responde tarde y en trama ISO-DEP completa, y suele
        //   pedir más tiempo con S(WTX): hay que contestarlo y seguir.
        uint8_t frame[64];
        for (uint8_t round = 0; round < kMaxWtxRounds; ++round) {
            const uint8_t n = awaitFifoFrame(kSalvageBudgetMs);
            if (n < kMinFrame || n > sizeof(frame)) {
                return MFRC522::STATUS_TIMEOUT;  // nothing (complete) arrived late
            }
            for (uint8_t i = 0; i < n; ++i) {
                frame[i] = mfrc522_.PCD_ReadRegister(MFRC522::FIFODataReg);
            }
            uint8_t check[2];
            if (mfrc522_.PCD_CalculateCRC(frame, n - 2, check) != MFRC522::STATUS_OK ||
                check[0] != frame[n - 2] || check[1] != frame[n - 1]) {
                if (log_) {
                    log_->print("[NFC] HCE ");
                    log_->print(what);
                    log_->print(" rescate ");
                    log_->print(n);
                    log_->println("B llegó tarde pero falló el CRC");
                }
                return MFRC522::STATUS_CRC_WRONG;
            }
            if (log_) {
                log_->print("[NFC] HCE ");
                log_->print(what);
                log_->print(" rescatada ");
                log_->print(n);
                log_->print("B respuesta tardía, CRC ok: ");
                if (redactFrames_) {
                    log_->println("<redactado: material de llave>");  // TASK-015: el ENROLL lleva la llave
                } else {
                    logFrame(frame, n);
                }
            }

            // S(WTX) request: grant it and go round again for the real answer.
            if ((frame[0] & 0xC0) == 0xC0 && (frame[0] & 0x30) == 0x30) {
                if (!answerWtx(frame, n)) {
                    return MFRC522::STATUS_ERROR;
                }
                continue;
            }

            // I-block: strip PCB [+ CID] [+ NAD] and the trailing CRC, so the
            // caller receives exactly the INF field TCL_Transceive returns.
            if ((frame[0] & 0xC0) != 0x00) {
                return MFRC522::STATUS_TIMEOUT;  // R-block/DESELECT: no app data
            }
            uint8_t off = 1;
            if (frame[0] & 0x08) {
                ++off;  // CID follows
            }
            if (frame[0] & 0x04) {
                ++off;  // NAD follows
            }
            if (n < off + 2u + 1u) {
                return MFRC522::STATUS_TIMEOUT;  // prologue + CRC only, no INF
            }
            const uint8_t infLen = static_cast<uint8_t>(n - off - 2);
            if (infLen > respCap) {
                return MFRC522::STATUS_NO_ROOM;
            }
            memcpy(resp, &frame[off], infLen);
            *respLen = infLen;
            if (redactFrames_) {
                memset(frame, 0, sizeof(frame));  // key-bearing copy off the stack
            }

            // RESYNC THE BLOCK NUMBER — the bug that made every salvaged
            // tap die at CHALLENGE (hce.11 bench log).
            //
            // TCL_Transceive advances tag.blockNumber ONLY on its own
            // success (MFRC522Extended.cpp: `if (result != STATUS_OK)
            // return result;` sits above the swap). Every salvaged frame
            // took that early return, so the driver kept block number 0
            // while the phone — having answered SELECT with PCB 0A — was
            // already expecting 1. The next I-block therefore looked like
            // a RETRANSMISSION of the previous one, so the phone never ran
            // the challenge and simply went quiet: 6/6 timeouts with an
            // empty FIFO, on exactly the taps that needed salvage. The two
            // taps that authenticated were the two the driver completed
            // by itself.
            //
            // Resync from the phone's own answer rather than blind-toggling:
            // it echoes the block number it received, so the next block we
            // send is the opposite. Self-correcting even if we ever lose a
            // frame. / Resincroniza desde la respuesta del propio teléfono.
            mfrc522_.tag.blockNumber = ((frame[0] & 0x01) == 0);
            return MFRC522::STATUS_OK;
        }
        if (log_) {
            log_->println("[NFC] el teléfono HCE siguió pidiendo más tiempo (rondas WTX agotadas)");
        }
        return MFRC522::STATUS_TIMEOUT;
    }

    /**
     * Poll the FIFO until a complete frame has landed, instead of blindly
     * sleeping out the worst case. Speed matters now: an S(WTX) must be
     * ANSWERED, and the sooner we see it the further inside the phone's
     * frame-wait our reply lands. A level that stops growing across two
     * polls is a finished frame (the CRC check downstream is the real gate).
     */
    uint8_t awaitFifoFrame(uint16_t budgetMs) {
        const uint32_t deadline = millis() + budgetMs;
        uint8_t last = 0;
        uint8_t stable = 0;
        while (static_cast<int32_t>(millis() - deadline) < 0) {
            const uint8_t n = mfrc522_.PCD_ReadRegister(MFRC522::FIFOLevelReg);
            if (n >= kMinFrame && n == last) {
                if (++stable >= 2) {
                    return n;
                }
            } else {
                stable = 0;
            }
            last = n;
            delay(2);  // yields — never spin (TG1WDT_SYS_RESET, bench-observed)
        }
        return last >= kMinFrame ? last : 0;
    }

    /**
     * Answer an S(WTX) request (ISO/IEC 14443-4 §7.5.4.3): same PCB, echo
     * the CID when present, and return the requested WTXM with the
     * power-level bits cleared. The reply is frame-identical to the
     * request for WTXM=1, which is what this phone asks for.
     */
    bool answerWtx(const uint8_t* req, uint8_t reqLen) {
        uint8_t out[5];
        uint8_t i = 0;
        out[i++] = req[0];
        uint8_t infIdx = 1;
        if (req[0] & 0x08) {
            out[i++] = req[1];  // echo CID
            infIdx = 2;
        }
        if (infIdx + 2u > reqLen) {
            return false;  // malformed WTX: no INF byte before the CRC
        }
        out[i++] = req[infIdx] & 0x3F;  // WTXM, power-level bits cleared
        if (mfrc522_.PCD_CalculateCRC(out, i, &out[i]) != MFRC522::STATUS_OK) {
            return false;
        }
        i += 2;
        uint8_t back[8];
        uint8_t backLen = sizeof(back);
        mfrc522_.PCD_TransceiveData(out, i, back, &backLen);
        if (log_) {
            log_->print("[NFC] WTX HCE concedido (WTXM=");
            log_->print(out[infIdx] & 0x3F);
            log_->println(") — esperando la respuesta real de la app");
        }
        return true;
    }

    /**
     * Print a raw ISO-DEP frame and NAME its PCB class (ISO/IEC 14443-4
     * §7.1). This exists because the hce.8 bench log proved that a
     * 5-byte CRC-valid answer is ambiguous by LENGTH ALONE, and the two
     * readings demand opposite fixes:
     *
     *   PCB + SW1 SW2 + CRC   → a bare-status I-block. If SW=6A82 the
     *     phone's OS answered "no service routed for this AID" and our
     *     app never saw the SELECT — an ANDROID ROUTING problem.
     *   PCB + CID + WTXM + CRC → an S(WTX) request: the phone is asking
     *     for MORE TIME because its controller→DH→app path is slow
     *     (bench-measured 13-16 ms). MFRC522Extended does not answer
     *     WTX (documented limit) — a gap in THIS reader's ISO-DEP layer,
     *     with the phone entirely healthy.
     *
     * Both are exactly 5 bytes depending on CID presence, so the bytes
     * decide, never the length. / Los bytes deciden, nunca la longitud.
     */
    void logFrame(const uint8_t* f, uint8_t n) {
        for (uint8_t i = 0; i < n; ++i) {
            if (f[i] < 0x10) {
                log_->print('0');
            }
            log_->print(f[i], HEX);
            if (i + 1 < n) {
                log_->print(' ');
            }
        }
        const uint8_t pcb = f[0];
        if ((pcb & 0xC0) == 0x00) {
            log_->print("  -> I-block, SW=");
            if (f[n - 4] < 0x10) {
                log_->print('0');
            }
            log_->print(f[n - 4], HEX);
            if (f[n - 3] < 0x10) {
                log_->print('0');
            }
            log_->print(f[n - 3], HEX);
            log_->println(" (respondió la capa APP del teléfono — 6A82 = AID no enrutado)");
        } else if ((pcb & 0xE0) == 0xA0) {
            log_->println("  -> R-block (ACK/NAK, no lleva datos de la app)");
        } else if ((pcb & 0x30) == 0x30) {
            log_->println("  -> S(WTX): el teléfono pide MÁS TIEMPO — el driver nunca lo responde");
        } else {
            log_->println("  -> S(DESELECT): el teléfono cerró la sesión");
        }
    }

    /** Post-failure receiver snapshot (see tclWithRetries). Read-only. */
    void dumpRxState() {
        const uint8_t err = mfrc522_.PCD_ReadRegister(MFRC522::ErrorReg);
        const uint8_t irq = mfrc522_.PCD_ReadRegister(MFRC522::ComIrqReg);
        const uint8_t fifo = mfrc522_.PCD_ReadRegister(MFRC522::FIFOLevelReg);
        log_->print("[NFC] estado RX ERR=0x");
        if (err < 0x10) {
            log_->print('0');
        }
        log_->print(err, HEX);
        log_->print(" IRQ=0x");
        if (irq < 0x10) {
            log_->print('0');
        }
        log_->print(irq, HEX);
        log_->print(" FIFO=");
        log_->print(fifo);
        log_->println("B");
    }

    /** Polite release of the ISO-DEP target; errors ignored (see prototype). */
    /**
     * PAIRING only: ENROLL → the phone's 32-byte key (the phone answers
     * only inside its user-opened enrollment window, else 6985), wrapped
     * at once under this reader's API key with a fresh nonce; the raw key
     * is wiped before return and is never logged.
     */
    bool collectKey(const std::string& credId, uint8_t* cmd, size_t cmdCap,
                    uint8_t* resp, size_t respCap) {
        if (wrapSecret_.empty()) {
            if (log_) {
                log_->println("[NFC] enrolamiento HCE: no hay clave de lector para envolver");
            }
            return false;
        }
        const uint8_t enrollLen = static_cast<uint8_t>(Hce::buildEnroll(cmd, cmdCap));
        uint8_t respLen = static_cast<uint8_t>(respCap);
        redactFrames_ = true;  // never print the ENROLL answer's bytes
        MFRC522::StatusCode sc = tclWithRetries(cmd, enrollLen, resp, &respLen,
                                                static_cast<uint8_t>(respCap), 6, 25, "ENROLL");
        redactFrames_ = false;
        uint8_t key[Hce::KEY_LEN];
        if (sc != MFRC522::STATUS_OK || !Hce::parseEnrollResponse(resp, respLen, key)) {
            if (log_) {
                log_->println("[NFC] ENROLL HCE rechazado — en el teléfono toca «Vincular este teléfono» primero y vuelve a acercarlo");
            }
            memset(resp, 0, respCap);
            return false;
        }
        uint8_t keyNonce[Hce::KEY_NONCE_LEN];
        for (size_t i = 0; i < Hce::KEY_NONCE_LEN; i++) {
            keyNonce[i] = static_cast<uint8_t>(esp_random());
        }
        lastProof_.keyNonceHex = Signer::toHex(keyNonce, Hce::KEY_NONCE_LEN);
        lastProof_.keyWrappedHex = Hce::wrapKeyHex(wrapSecret_, credId, lastProof_.keyNonceHex, key);
        memset(key, 0, sizeof(key));
        memset(resp, 0, respCap);  // the ENROLL frame held the raw key
        if (log_) {
            log_->println("[NFC] llave HCE recibida y envuelta para emparejar (nunca se registra)");
        }
        return true;
    }

    void releaseHce() {
        setPcdTimeoutMs(kStockTimeoutMs);  // MIFARE needs the driver's RX back
        mfrc522_.TCL_Deselect(&mfrc522_.tag);
        mfrc522_.PICC_HaltA();
    }

    /**
     * Set the PCD receive timer (TReloadReg @ the 40 kHz timer PCD_Init
     * configures, so 40 ticks per ms).
     *
     * This is what makes the HCE exchange DETERMINISTIC instead of a coin
     * flip. MFRC522Extended cannot parse an S-block, and this phone
     * routinely answers SELECT with S(WTX). Whether that hurts depended
     * entirely on a race against the driver's own receive window, proven
     * across three builds by the ComIrq byte:
     *
     *   WTX lands AFTER the window  -> ComIrq=0x45, RxIRq clear. The driver
     *     reports a timeout having touched nothing, the frame stays in the
     *     FIFO, salvageLateAnswer (S-block aware) handles it. Tap works.
     *   WTX lands INSIDE the window -> ComIrq=0x64, RxIRq set. The driver
     *     receives it, mis-parses it as an I-block, fails CRC and DRAINS
     *     the FIFO. Salvage then sees FIFO=0B. Tap fails, 10/10 retries.
     *
     * hce.13 widened the window to the phone's advertised FWT and made the
     * bad case happen always (0% SELECT). hce.14 restored the stock 25 ms
     * and made it a coin flip (~70%). The cure is the opposite extreme:
     * clamp the window BELOW the phone's fastest answer (measured 13-16 ms)
     * so the driver ALWAYS times out first and never drains the FIFO —
     * PCD_CommunicateWithPICC returns on the timer IRQ before it ever reads
     * FIFOLevelReg. The driver becomes a pure transmitter; reception is
     * entirely ours, where the S-block logic lives.
     * / Recorta la ventana del driver por debajo de la respuesta más rápida
     *   del teléfono: así el driver solo transmite y nosotros recibimos.
     */
    void setPcdTimeoutMs(uint16_t ms) {
        uint32_t ticks = static_cast<uint32_t>(ms) * 40UL;
        if (ticks > 0xFFFF) {
            ticks = 0xFFFF;
        }
        mfrc522_.PCD_WriteRegister(MFRC522::TReloadRegH, static_cast<uint8_t>(ticks >> 8));
        mfrc522_.PCD_WriteRegister(MFRC522::TReloadRegL, static_cast<uint8_t>(ticks & 0xFF));
    }

    /**
     * (Re)initialize the MFRC522 and probe its VersionReg. Valid versions:
     * 0x91 (v1.0), 0x92 (v2.0); 0x90 / 0x88 show on some clones. 0x00 /
     * 0xFF mean the chip is not answering (wiring or power).
     * /(Re)inicializa el MFRC522 y sondea su VersionReg.
     */
    bool reinit(uint32_t now) {
        lastInitAttemptMs_ = now;
        mfrc522_.PCD_Init();
        // ponytail: real-hardware calibration knob — max receiver gain.
        // PCD_Init leaves the typical 33 dB default, which is plenty for
        // MIFARE cards but marginal for a phone's weak load modulation
        // (short SAK frames decode while longer ATS frames CRC-fail →
        // SAK=0x20 ATS=0B). 48 dB costs nothing for cards (strong
        // targets) and is THE standard RC522-vs-phone fix. Re-applied
        // after every init (PCD_Init resets the register).
        mfrc522_.PCD_SetAntennaGain(MFRC522::RxGain_max);
        version_ = mfrc522_.PCD_ReadRegister(MFRC522::PCD_Register::VersionReg);
        healthy_ = (version_ != 0x00 && version_ != 0xFF);

        if (log_) {
            if (healthy_) {
                log_->print("[NFC] RC522 detectado — versión de firmware 0x");
                log_->print(version_ < 0x10 ? "0" : "");
                log_->print(version_, HEX);
                log_->println("");
            } else {
                log_->print("[NFC] RC522 NO responde (versión 0x");
                log_->print(version_ < 0x10 ? "0" : "");
                log_->print(version_, HEX);
                log_->print(") — revisa el cableado (SCK ");
                log_->print(sckPin_);
                log_->print(" / MISO ");
                log_->print(misoPin_);
                log_->print(" / MOSI ");
                log_->print(mosiPin_);
                log_->print(" / SDA ");
                log_->print(ssPin_);
                log_->print(" / RST ");
                log_->print(PIN_RC522_RST);
                log_->print(") y la alimentación de 3,3 V");
                log_->print(" — reintentando cada ");
                log_->print(RC522_REINIT_INTERVAL_MS / 1000);
                log_->println(" s");
            }
        }
        return healthy_;
    }

    MFRC522Extended mfrc522_;
    // HCE park-and-activate state: discovery spots the target, the settle
    // window passes in radio silence, then the tap's first RATS fires.
    static constexpr uint32_t HCE_SETTLE_MS = 400;
    // Late-answer salvage (see salvageLateAnswer). Budget covers the
    // bench-measured 13-16 ms answer latency with margin; the FIFO poll
    // returns as soon as the frame is complete, so the usual cost is far
    // lower. kMinFrame is the shortest frame worth reading: PCB + SW(2) +
    // CRC(2), which is also the size of an S(WTX) request carrying a CID.
    static constexpr uint8_t kMinFrame = 5;
    static constexpr uint16_t kSalvageBudgetMs = 60;
    static constexpr uint8_t kMaxWtxRounds = 4;
    // Driver receive window (see setPcdTimeoutMs). 5 ms sits far below the
    // phone's fastest measured answer (13 ms) and above our own ~1 ms frame
    // transmission, so the driver reliably gives up before anything lands.
    // 25 ms is PCD_Init's stock value, restored for the MIFARE path.
    static constexpr uint16_t kHceTxOnlyTimeoutMs = 5;
    static constexpr uint16_t kStockTimeoutMs = 25;
    uint32_t hceSeenMs_ = 0;
    bool hceSettling_ = false;
    uint8_t sckPin_;
    uint8_t misoPin_;
    uint8_t mosiPin_;
    uint8_t ssPin_;
    Print* log_;                 // nullable diagnostics sink
    const char* lastKind_ = "physical";
    HceProof lastProof_;         // TASK-015: relayed transcript (+ wrapped key in pairing)
    bool enrollment_ = false;    // pairing mode → ENROLL after CHALLENGE
    bool redactFrames_ = false;  // true while an ENROLL (key-bearing) exchange runs
    std::string wrapSecret_;     // this reader's API key, only while enrolling

    bool healthy_ = false;       // last probe answered?
    uint8_t version_ = 0;        // last VersionReg byte seen
    uint32_t lastInitAttemptMs_ = 0;
    uint32_t lastProbeMs_ = 0;   // last runtime health probe (healthy path)
};

}  // namespace EcoStation
