/*
 * EcoStation · realtime browser client.
 *
 * Plain JS, no libraries and no CDN. Two independent surfaces share one
 * short-lived token and one WebSocket:
 *   - [data-live-feed]     the teacher/admin deposit feed
 *   - [data-pairing-desk]  the admin pairing desk (also polls every 2 s)
 *
 * Security rules kept from the server design: the token is never logged,
 * server data is only inserted through textContent / DOM APIs, and the
 * connection re-mints the token on every reconnect.
 */
(function () {
    "use strict";

    var feed = document.querySelector("[data-live-feed]");
    var desk = document.querySelector("[data-pairing-desk]");

    if (!feed && !desk) {
        return;
    }

    var tokenUrl = (feed && feed.getAttribute("data-realtime-token-url"))
        || (desk && desk.getAttribute("data-realtime-token-url"));

    var MATERIALS = {
        plastic: { label: "Plástico", tone: "blue" },
        metal: { label: "Metal", tone: "coral" },
        glass: { label: "Vidrio", tone: "turquoise" },
        paper: { label: "Papel", tone: "amber" },
        other: { label: "Otro", tone: "pink" }
    };

    var FEED_LIMIT = 100;
    var numberFormat = new Intl.NumberFormat("es-CO");

    /* ------------------------------------------------------------------ */
    /* Small DOM helpers                                                   */
    /* ------------------------------------------------------------------ */

    function node(tag, className, text) {
        var element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined && text !== null) {
            element.textContent = String(text);
        }

        return element;
    }

    function clear(element) {
        while (element && element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function setText(scope, selector, value) {
        var target = scope ? scope.querySelector(selector) : null;

        if (target && value !== undefined && value !== null) {
            target.textContent = String(value);
        }
    }

    function initials(name) {
        var words = String(name || "").trim().split(/\s+/).filter(Boolean);
        var value = "";

        words.slice(0, 2).forEach(function (word) {
            value += word.charAt(0).toUpperCase();
        });

        return value || "·";
    }

    function nowTime() {
        var now = new Date();

        return String(now.getHours()).padStart(2, "0") + ":" + String(now.getMinutes()).padStart(2, "0");
    }

    /** Today shows HH:MM; older history shows DD/MM · HH:MM. */
    function timeLabel(time, date) {
        if (!date) {
            return time || nowTime();
        }

        var now = new Date();
        var today = now.getFullYear() + "-"
            + String(now.getMonth() + 1).padStart(2, "0") + "-"
            + String(now.getDate()).padStart(2, "0");

        if (date === today) {
            return time || nowTime();
        }

        var parts = String(date).split("-");

        if (parts.length !== 3) {
            return time || nowTime();
        }

        return parts[2] + "/" + parts[1] + " · " + (time || "");
    }

    function formatClock(seconds) {
        var safe = Math.max(0, seconds);

        return Math.floor(safe / 60) + ":" + String(safe % 60).padStart(2, "0");
    }

    /* ------------------------------------------------------------------ */
    /* Connection status                                                   */
    /* ------------------------------------------------------------------ */

    var statusChip = document.querySelector("[data-live-status]");
    var statusText = document.querySelector("[data-live-status-text]");
    var statusDot = document.querySelector("[data-live-dot]");

    function setConnection(state, text) {
        if (statusText) {
            statusText.textContent = text;
        }

        if (statusChip) {
            statusChip.setAttribute("data-live-state", state);
            statusChip.classList.toggle("chip--success", state === "live");
            statusChip.classList.toggle("chip--neutral", state === "connecting");
            statusChip.classList.toggle("chip--danger", state === "offline");
        }

        if (statusDot) {
            statusDot.classList.toggle("live-dot--on", state === "live");
            statusDot.classList.toggle("live-dot--off", state !== "live");
        }
    }

    /* ------------------------------------------------------------------ */
    /* Live feed                                                           */
    /* ------------------------------------------------------------------ */

    var feedRows = feed ? feed.querySelector("[data-live-rows]") : null;
    var rowsById = {};
    var seenFeedback = {};
    var selectedClassId = feed ? (feed.getAttribute("data-live-selected-class") || "") : "";

    function eventVisible(event) {
        if (!selectedClassId) {
            return true;
        }

        return String(event.class_id) === String(selectedClassId);
    }

    function materialChip(material) {
        if (!material) {
            var pending = node("span", "chip chip--warning");
            pending.appendChild(node("span", "chip__dot"));
            pending.lastChild.setAttribute("aria-hidden", "true");
            pending.appendChild(document.createTextNode("Clasificando…"));

            return pending;
        }

        var info = MATERIALS[material] || { label: String(material), tone: "pink" };
        var chip = node("span", "chip chip--" + info.tone);
        var dot = node("span", "chip__dot");
        dot.setAttribute("aria-hidden", "true");
        chip.appendChild(dot);
        chip.appendChild(document.createTextNode(info.label));
        chip.setAttribute("data-material", material);

        return chip;
    }

    function pointsBadge(points) {
        if (points === null || points === undefined || points === "") {
            var empty = node("span", "muted", "—");
            empty.setAttribute("aria-label", "Puntos pendientes");

            return empty;
        }

        var value = Number(points) || 0;
        var badge = node("span", "pts" + (value <= 0 ? " pts--zero" : ""));

        if (value > 0) {
            var star = node("span", "pts__star", "★");
            star.setAttribute("aria-hidden", "true");
            badge.appendChild(star);
            badge.appendChild(document.createTextNode("+" + numberFormat.format(value) + " "));
        } else {
            badge.appendChild(document.createTextNode(numberFormat.format(value) + " "));
        }

        badge.appendChild(node("span", "sr-only", "puntos"));
        var unit = node("span", null, "pts");
        unit.setAttribute("aria-hidden", "true");
        badge.appendChild(unit);

        return badge;
    }

    function buildRow(data) {
        var row = node("article", "live-row");
        row.setAttribute("data-live-row", "");

        if (data.id !== undefined && data.id !== null) {
            row.setAttribute("data-event-id", String(data.id));
        }

        if (data.student_id !== undefined && data.student_id !== null) {
            row.setAttribute("data-student-id", String(data.student_id));
        }

        if (data.class_id !== undefined && data.class_id !== null) {
            row.setAttribute("data-class-id", String(data.class_id));
        }

        row.appendChild(node("span", "live-row__time", timeLabel(data.time, data.date)));

        var student = node("div", "live-row__student");
        var avatar = node("span", "avatar avatar--sm", initials(data.student_name));
        avatar.setAttribute("aria-hidden", "true");
        student.appendChild(avatar);

        var meta = node("div");
        meta.appendChild(node("span", "live-row__name", data.student_name || "Estudiante"));
        meta.appendChild(node("span", "live-row__class", data.class_name || "Sin curso"));
        student.appendChild(meta);
        row.appendChild(student);

        var material = node("div", "live-row__material");
        material.appendChild(materialChip(data.material || null));
        row.appendChild(material);

        row.appendChild(node("span", "live-row__station", data.reader_label || "Sin estación"));

        var status = node("div", "live-row__status");
        status.appendChild(pointsBadge(data.points));
        row.appendChild(status);

        return row;
    }

    function updateRow(row, data) {
        if (!row) {
            return;
        }

        if (data.time || data.date) {
            setText(row, ".live-row__time", timeLabel(data.time, data.date));
        }

        if (data.student_name) {
            setText(row, ".live-row__name", data.student_name);
        }

        if (data.class_name) {
            setText(row, ".live-row__class", data.class_name);
        }

        if (data.reader_label) {
            setText(row, ".live-row__station", data.reader_label);
        }

        if (data.material !== undefined) {
            var material = row.querySelector(".live-row__material");

            if (material) {
                clear(material);
                material.appendChild(materialChip(data.material || null));
            }
        }

        if (data.points !== undefined) {
            var status = row.querySelector(".live-row__status");

            if (status) {
                clear(status);
                status.appendChild(pointsBadge(data.points));
            }
        }
    }

    function removeEmpty() {
        var empty = feedRows ? feedRows.querySelector("[data-live-empty]") : null;

        if (empty) {
            empty.remove();
        }
    }

    function capRows() {
        if (!feedRows) {
            return;
        }

        var rows = feedRows.querySelectorAll("[data-live-row]");

        for (var index = FEED_LIMIT; index < rows.length; index += 1) {
            rows[index].remove();
        }
    }

    function insertRow(row) {
        removeEmpty();
        feedRows.insertBefore(row, feedRows.firstChild);
        capRows();
        row.classList.add("live-row--new");
        window.setTimeout(function () {
            row.classList.remove("live-row--new");
        }, 1800);
    }

    function applyTap(event) {
        if (!feedRows || !event || !eventVisible(event)) {
            return;
        }

        var id = Number(event.id);

        if (id && rowsById[id]) {
            updateRow(rowsById[id], event);

            return;
        }

        var row = buildRow(event);

        if (id) {
            rowsById[id] = row;
        }

        insertRow(row);
    }

    function applyRecycling(update) {
        if (!feedRows || !update || !update.payload) {
            return;
        }

        var payload = update.payload;
        var type = String(update.type || "");

        if (type !== "validated" && type !== "points_awarded") {
            return;
        }

        if (!eventVisible(payload)) {
            return;
        }

        var id = Number(payload.event_id);
        var existing = id ? rowsById[id] : null;

        var data = {
            id: id || null,
            student_id: payload.student_id,
            student_name: payload.student_name,
            class_id: payload.class_id,
            time: payload.occurred_at ? String(payload.occurred_at).substr(11, 5) : nowTime(),
            material: payload.material_class || undefined,
            points: type === "points_awarded" ? (payload.points === undefined ? 0 : payload.points) : undefined
        };

        if (existing) {
            updateRow(existing, data);

            return;
        }

        var row = buildRow(data);

        if (id) {
            rowsById[id] = row;
        }

        insertRow(row);
    }

    var REASONS = {
        not_found: "Credencial no registrada",
        inactive: "Credencial inactiva",
        hce_auth_failed: "Teléfono no verificado"
    };

    function applyFeedback(cue) {
        if (!feedRows || !cue) {
            return;
        }

        var key = String(cue.id || "");

        if (key && seenFeedback[key]) {
            return;
        }

        seenFeedback[key] = true;

        var accepted = String(cue.cue) === "accepted";
        var reason = REASONS[cue.reason] || "No se pudo leer la credencial";

        var row = node("article", "live-row");
        row.setAttribute("data-live-row", "");
        row.setAttribute("data-feedback-id", key);
        row.appendChild(node("span", "live-row__time", nowTime()));

        var student = node("div", "live-row__student");
        var avatar = node("span", "avatar avatar--sm", accepted ? "✓" : "!");
        avatar.setAttribute("aria-hidden", "true");
        student.appendChild(avatar);

        var meta = node("div");
        meta.appendChild(node("span", "live-row__name", accepted ? "Toque aceptado" : "Toque rechazado"));
        meta.appendChild(node("span", "live-row__class", accepted ? "Credencial leída" : reason));
        student.appendChild(meta);
        row.appendChild(student);

        var material = node("div", "live-row__material");
        var chip = node("span", "chip " + (accepted ? "chip--success" : "chip--danger"));
        var dot = node("span", "chip__dot");
        dot.setAttribute("aria-hidden", "true");
        chip.appendChild(dot);
        chip.appendChild(document.createTextNode(accepted ? "Aceptado" : "Rechazado"));
        material.appendChild(chip);
        row.appendChild(material);

        row.appendChild(node("span", "live-row__station", "Estación"));

        var status = node("div", "live-row__status");
        status.appendChild(node("span", "chip " + (accepted ? "chip--success" : "chip--danger"), accepted ? "Aceptado" : "Rechazado"));
        row.appendChild(status);

        insertRow(row);
    }

    function seedFeed(hello) {
        if (!feedRows) {
            return;
        }

        var recycling = {};

        (hello.recycling || []).forEach(function (update) {
            var payload = update && update.payload ? update.payload : {};
            var id = Number(payload.event_id);

            if (!id) {
                return;
            }

            recycling[id] = recycling[id] || {};

            if (update.type === "validated") {
                recycling[id].material = payload.material_class;
            }

            if (update.type === "points_awarded") {
                recycling[id].points = payload.points;

                if (payload.material_class) {
                    recycling[id].material = payload.material_class;
                }
            }
        });

        feedRows.querySelectorAll("[data-live-row]").forEach(function (row) {
            row.remove();
        });

        var events = (hello.events || []).slice().sort(function (a, b) {
            var left = String(b.date || "") + String(b.time || "");
            var right = String(a.date || "") + String(a.time || "");

            return left.localeCompare(right);
        });

        rowsById = {};

        events.forEach(function (event) {
            if (!eventVisible(event)) {
                return;
            }

            var extra = recycling[Number(event.id)] || {};
            var row = buildRow({
                id: event.id,
                student_id: event.student_id,
                student_name: event.student_name,
                class_id: event.class_id,
                class_name: event.class_name,
                reader_label: event.reader_label,
                time: event.time,
                date: event.date,
                material: extra.material || undefined,
                points: extra.points === undefined ? undefined : extra.points
            });

            rowsById[Number(event.id)] = row;
            feedRows.appendChild(row);
        });

        if (events.length > 0) {
            removeEmpty();
        }

        capRows();
    }

    /* ------------------------------------------------------------------ */
    /* Pairing desk                                                        */
    /* ------------------------------------------------------------------ */

    var pairing = desk ? {
        statusUrl: desk.getAttribute("data-pairing-status-url"),
        cancelUrl: desk.getAttribute("data-pairing-cancel-url"),
        pending: desk.querySelector("[data-pairing-pending]"),
        success: desk.querySelector("[data-pairing-success]"),
        idle: desk.querySelector("[data-pairing-idle]"),
        countdown: desk.querySelector("[data-pairing-countdown]"),
        search: desk.querySelector("[data-pairing-search]"),
        filters: desk.querySelectorAll("[data-pairing-filter]"),
        list: desk.querySelector("[data-pairing-list]"),
        rows: desk.querySelectorAll("[data-pairing-student-id]"),
        lastPayload: null,
        deadline: null,
        expiredPolled: false,
        polling: false,
        sawPending: false,
        dismissedPairedAt: null,
        activeFilter: "unlinked"
    } : null;

    function kindLabel(kind) {
        return kind === "hce" ? "Teléfono" : "Tarjeta NFC";
    }

    function markStudentRows(mode, studentId) {
        if (!pairing) {
            return;
        }

        var target = Number(studentId);

        pairing.rows.forEach(function (row) {
            var id = Number(row.getAttribute("data-pairing-student-id"));
            var form = row.querySelector("[data-pairing-arm-form]");
            var state = row.querySelector("[data-pairing-state]");
            var armed = mode === "armed" && id === target;
            var linked = mode === "linked" && id === target;

            row.classList.toggle("is-armed", armed);
            row.classList.toggle("is-linked", linked);

            if (form) {
                form.hidden = armed || linked;
            }

            if (state) {
                state.hidden = !(armed || linked);
                state.textContent = armed ? "Armado" : (linked ? "Vinculado ✓" : "");
            }

            if (linked) {
                row.setAttribute("data-has-credential", "1");
            }
        });

        applyListFilter();
    }

    function showDeskPanel(panel) {
        if (!pairing) {
            return;
        }

        [pairing.pending, pairing.success, pairing.idle].forEach(function (section) {
            if (section) {
                section.hidden = section !== panel;
            }
        });
    }

    function fillSuccess(last) {
        if (!pairing || !last) {
            return;
        }

        setText(desk, "[data-pairing-student]", last.student_name);

        var classRow = null;

        pairing.rows.forEach(function (row) {
            if (Number(row.getAttribute("data-pairing-student-id")) === Number(last.student_id)) {
                classRow = row;
            }
        });

        var className = classRow ? classRow.getAttribute("data-class-name") : "";
        setText(desk, "[data-pairing-student-class]", className ? "(" + className + ")" : "");
        setText(desk, "[data-pairing-credential]", kindLabel(last.card_kind));
        setText(desk, "[data-pairing-uid]", last.card_uid || "—");
        setText(desk, "[data-pairing-station]", last.reader_label || "Sin estación");

        var pairedAt = last.paired_at ? new Date(last.paired_at) : null;

        if (pairedAt && !isNaN(pairedAt.getTime())) {
            var parts = new Intl.DateTimeFormat("es-CO", {
                timeZone: "America/Bogota",
                day: "numeric",
                month: "short",
                year: "numeric",
                hour: "2-digit",
                minute: "2-digit",
                hour12: false
            }).formatToParts(pairedAt);

            var values = {};

            parts.forEach(function (part) {
                values[part.type] = part.value;
            });

            var month = (values.month || "").replace(".", "");
            var clock = (values.hour || "00") + ":" + (values.minute || "00");
            var formatted = values.day + " " + month + " " + values.year + " · " + clock;

            setText(desk, "[data-pairing-time]", formatted);
        }
    }

    function renderPairing(payload) {
        if (!pairing || !payload) {
            return;
        }

        pairing.lastPayload = payload;

        if (payload.pending) {
            pairing.sawPending = true;
            pairing.deadline = payload.pending.expires_at ? Date.parse(payload.pending.expires_at) : null;
            pairing.expiredPolled = false;
            showDeskPanel(pairing.pending);
            setText(desk, "[data-pairing-pending-student]", payload.pending.student_name || "");
            markStudentRows("armed", payload.pending.student_id);
            tickCountdown();

            return;
        }

        pairing.deadline = null;

        var last = payload.last_pairing;
        var pairedAt = last && last.paired_at ? Date.parse(last.paired_at) : null;
        var recent = pairedAt !== null && !isNaN(pairedAt) && Date.now() - pairedAt < 60000;

        if (recent && pairing.dismissedPairedAt !== last.paired_at) {
            fillSuccess(last);
            showDeskPanel(pairing.success);
            markStudentRows("linked", last.student_id);

            // The pairing changed the credential list and the recent table;
            // refresh once so both reflect the new row. The reload keeps the
            // success panel (last_pairing is still inside the 60 s window).
            if (pairing.sawPending) {
                pairing.sawPending = false;
                window.setTimeout(function () {
                    window.location.reload();
                }, 1200);
            }

            return;
        }

        pairing.sawPending = false;
        showDeskPanel(pairing.idle);
        markStudentRows("none", null);
    }

    function tickCountdown() {
        if (!pairing || !pairing.countdown || pairing.deadline === null) {
            return;
        }

        var seconds = Math.max(0, Math.round((pairing.deadline - Date.now()) / 1000));
        pairing.countdown.textContent = formatClock(seconds);

        if (seconds <= 0 && !pairing.expiredPolled) {
            pairing.expiredPolled = true;
            pollPairing();
        }
    }

    function pollPairing() {
        if (!pairing || !pairing.statusUrl || pairing.polling) {
            return;
        }

        pairing.polling = true;

        fetch(pairing.statusUrl, {
            headers: { Accept: "application/json" },
            credentials: "same-origin"
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("pairing status");
                }

                return response.json();
            })
            .then(function (payload) {
                if (payload && payload.status === "ok") {
                    renderPairing(payload);
                }
            })
            .catch(function () {
                // The polling is a fallback; the WebSocket may still be live.
            })
            .finally(function () {
                pairing.polling = false;
            });
    }

    function applyListFilter() {
        if (!pairing || !pairing.list) {
            return;
        }

        var term = pairing.search ? pairing.search.value.trim().toLowerCase() : "";
        var visible = 0;

        pairing.rows.forEach(function (row) {
            var haystack = (row.getAttribute("data-search") || "").toLowerCase();
            var hasCredential = row.getAttribute("data-has-credential") === "1";
            var keepByState = !hasCredential
                || row.classList.contains("is-armed")
                || row.classList.contains("is-linked");
            var keepByFilter = pairing.activeFilter === "all" || keepByState;
            var show = keepByFilter && haystack.indexOf(term) !== -1;

            row.hidden = !show;

            if (show) {
                visible += 1;
            }
        });

        var empty = pairing.list.querySelector("[data-pairing-empty]");

        if (empty) {
            empty.hidden = visible !== 0;
        }
    }

    if (pairing) {
        pairing.filters.forEach(function (button) {
            button.addEventListener("click", function () {
                pairing.activeFilter = button.getAttribute("data-pairing-filter");

                pairing.filters.forEach(function (other) {
                    var active = other === button;
                    other.classList.toggle("is-active", active);
                    other.setAttribute("aria-pressed", active ? "true" : "false");
                });

                applyListFilter();
            });
        });

        if (pairing.search) {
            pairing.search.addEventListener("input", applyListFilter);
        }

        var cancelForm = desk.querySelector("[data-pairing-cancel-form]");
        var cancelButton = desk.querySelector("[data-pairing-cancel]");

        if (cancelForm && pairing.cancelUrl) {
            cancelForm.addEventListener("submit", function (event) {
                event.preventDefault();

                if (!cancelButton || cancelButton.disabled) {
                    return;
                }

                cancelButton.disabled = true;

                var meta = document.querySelector('meta[name="csrf-token"]');
                var token = meta ? meta.getAttribute("content") : "";

                fetch(pairing.cancelUrl, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": token,
                        "X-Requested-With": "XMLHttpRequest"
                    },
                    credentials: "same-origin"
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error("cancel");
                        }

                        return response.json();
                    })
                    .then(function (payload) {
                        if (payload && payload.status === "ok") {
                            renderPairing(payload);
                        } else {
                            pollPairing();
                        }
                    })
                    .catch(function () {
                        pollPairing();
                    })
                    .finally(function () {
                        cancelButton.disabled = false;
                    });
            });
        }

        var dismiss = desk.querySelector("[data-pairing-dismiss]");

        if (dismiss) {
            dismiss.addEventListener("click", function () {
                pairing.dismissedPairedAt = pairing.lastPayload && pairing.lastPayload.last_pairing
                    ? pairing.lastPayload.last_pairing.paired_at
                    : null;

                if (pairing.lastPayload) {
                    renderPairing(pairing.lastPayload);
                } else {
                    showDeskPanel(pairing.idle);
                }
            });
        }

        applyListFilter();
        pollPairing();
        window.setInterval(pollPairing, 2000);
        window.setInterval(tickCountdown, 1000);
    }

    /* ------------------------------------------------------------------ */
    /* Frames and connection lifecycle                                     */
    /* ------------------------------------------------------------------ */

    function handleFrame(frame) {
        if (!frame || !frame.type) {
            return;
        }

        switch (frame.type) {
            case "hello":
                seedFeed(frame);

                if (pairing && frame.pairing) {
                    renderPairing(frame.pairing);
                }
                break;
            case "tap":
                applyTap(frame.event);
                break;
            case "recycling":
                applyRecycling(frame.update);
                break;
            case "feedback":
                applyFeedback(frame.feedback);
                break;
            case "pairing":
                if (pairing) {
                    renderPairing(frame);
                }
                break;
            default:
                break;
        }
    }

    var socket = null;
    var attempts = 0;
    var reconnectTimer = null;
    var stopped = false;

    function scheduleReconnect() {
        if (stopped) {
            return;
        }

        attempts += 1;

        if (attempts >= 3) {
            setConnection("offline", "Sin conexión");
        } else {
            setConnection("connecting", "Reconectando…");
        }

        var delay = Math.min(30000, 1000 * Math.pow(2, attempts - 1)) + Math.floor(Math.random() * 400);

        window.clearTimeout(reconnectTimer);
        reconnectTimer = window.setTimeout(connect, delay);
    }

    function connect() {
        if (stopped || !tokenUrl) {
            return;
        }

        setConnection(attempts === 0 ? "connecting" : "connecting", attempts === 0 ? "Conectando…" : "Reconectando…");

        fetch(tokenUrl, {
            headers: { Accept: "application/json" },
            credentials: "same-origin"
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("realtime token");
                }

                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.token || !payload.url) {
                    throw new Error("realtime token payload");
                }

                var separator = payload.url.indexOf("?") === -1 ? "?" : "&";
                var next = new WebSocket(payload.url + separator + "token=" + encodeURIComponent(payload.token));

                socket = next;

                next.addEventListener("open", function () {
                    if (socket !== next) {
                        return;
                    }

                    attempts = 0;
                    setConnection("live", "En vivo");
                });

                next.addEventListener("message", function (event) {
                    try {
                        handleFrame(JSON.parse(event.data));
                    } catch (error) {
                        // Ignore malformed frames; a later frame can recover.
                    }
                });

                next.addEventListener("close", function () {
                    if (socket !== next) {
                        return;
                    }

                    socket = null;
                    scheduleReconnect();
                });

                next.addEventListener("error", function () {
                    if (socket === next) {
                        try {
                            next.close();
                        } catch (error) {
                            // The close handler schedules the reconnect.
                        }
                    }
                });
            })
            .catch(function () {
                scheduleReconnect();
            });
    }

    if (tokenUrl) {
        connect();
    }

    window.addEventListener("beforeunload", function () {
        stopped = true;
        window.clearTimeout(reconnectTimer);

        if (socket) {
            try {
                socket.close();
            } catch (error) {
                // Nothing left to clean up.
            }
        }
    });
})();
