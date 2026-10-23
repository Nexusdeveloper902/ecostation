/*
 * EcoStation · small progressive enhancements.
 * No network access, no framework. The live feed lives in realtime.js.
 */
(function () {
    "use strict";

    // Dismissible flash toasts.
    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-dismiss-toast]");
        if (!button) {
            return;
        }

        var toast = button.closest(".toast");
        if (toast) {
            toast.remove();
        }
    });

    // Password rules: neutral without JS, green/red once the user types.
    document.querySelectorAll("[data-password-form]").forEach(function (form) {
        var password = form.querySelector('input[name="password"]');
        var confirmation = form.querySelector('input[name="password_confirmation"]');
        var rules = form.querySelectorAll("[data-rule]");

        if (!password || rules.length === 0) {
            return;
        }

        rules.forEach(function (rule) {
            var use = rule.querySelector("use");

            if (use) {
                use.setAttribute("data-ok", use.getAttribute("href"));
                use.setAttribute("data-bad", use.getAttribute("href").replace("i-check", "i-error"));
            }
        });

        var check = function () {
            rules.forEach(function (rule) {
                var value = password.value;
                var ok;

                switch (rule.getAttribute("data-rule")) {
                    case "length":
                        ok = value.length >= 8;
                        break;
                    case "mix":
                        ok = /[a-zA-Z]/.test(value) && /[0-9]/.test(value);
                        break;
                    case "match":
                        ok = value !== "" && confirmation !== null && value === confirmation.value;
                        break;
                    default:
                        ok = false;
                }

                var use = rule.querySelector("use");
                rule.classList.toggle("is-ok", ok);
                rule.classList.toggle("is-bad", value !== "" && !ok);

                if (use) {
                    use.setAttribute("href", ok ? use.getAttribute("data-ok") : use.getAttribute("data-bad"));
                }
            });
        };

        password.addEventListener("input", check);
        if (confirmation) {
            confirmation.addEventListener("input", check);
        }
    });

    // ------------------------------------------------------------------
    // Modals (native <dialog>, so focus trapping and Esc come for free)
    // ------------------------------------------------------------------
    var openDialog = function (dialog) {
        if (!dialog || typeof dialog.showModal !== "function" || dialog.open) {
            return;
        }

        dialog.showModal();

        var focusable = dialog.querySelector(
            "input:not([type=hidden]):not([type=file]), select, textarea"
        ) || dialog.querySelector("button, [href]");

        if (focusable) {
            window.setTimeout(function () {
                focusable.focus();
            }, 0);
        }
    };

    document.querySelectorAll("dialog[data-modal-auto-open]").forEach(openDialog);

    document.addEventListener("click", function (event) {
        var opener = event.target.closest("[data-modal-open]");

        if (opener) {
            openDialog(document.getElementById(opener.getAttribute("data-modal-open")));

            return;
        }

        var closer = event.target.closest("[data-modal-close]");

        if (closer) {
            var dialog = closer.closest("dialog");

            if (dialog) {
                dialog.close();
            }
        }
    });

    document.querySelectorAll("dialog.modal").forEach(function (dialog) {
        dialog.addEventListener("click", function (event) {
            // A click on the dialog itself (not its panel) is the backdrop.
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    // ------------------------------------------------------------------
    // Dropzone: show the chosen file name and mark the label as filled.
    // ------------------------------------------------------------------
    document.querySelectorAll("[data-dropzone-input]").forEach(function (input) {
        input.addEventListener("change", function () {
            var dropzone = input.closest("[data-dropzone]");
            var name = dropzone ? dropzone.querySelector("[data-dropzone-name]") : null;

            if (!name) {
                return;
            }

            name.textContent = input.files && input.files.length > 0
                ? input.files[0].name
                : name.getAttribute("data-empty-label") || "Ningún archivo seleccionado";
            if (dropzone) {
                dropzone.classList.toggle("is-filled", Boolean(input.files && input.files.length));
            }
        });
    });

    // Drop support: the label is the drop target, the hidden input is the store.
    document.querySelectorAll("[data-dropzone]").forEach(function (dropzone) {
        var input = dropzone.querySelector("[data-dropzone-input]");

        if (!input) {
            return;
        }

        ["dragenter", "dragover"].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.add("is-dragging");
            });
        });

        ["dragleave", "drop"].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.remove("is-dragging");
            });
        });

        dropzone.addEventListener("drop", function (event) {
            var files = event.dataTransfer ? event.dataTransfer.files : null;

            if (!files || files.length === 0) {
                return;
            }

            try {
                input.files = files;
                input.dispatchEvent(new Event("change", { bubbles: true }));
            } catch (error) {
                // Some browsers refuse a synthetic FileList; the picker still works.
            }
        });
    });

    // ------------------------------------------------------------------
    // Copy-to-clipboard for one-time keys and passwords.
    // ------------------------------------------------------------------
    document.querySelectorAll("[data-copy-target]").forEach(function (button) {
        button.addEventListener("click", function () {
            var target = document.querySelector(button.getAttribute("data-copy-target"));

            if (!target) {
                return;
            }

            var text = (target.textContent || "").trim();
            var original = button.getAttribute("data-copy-label") || button.textContent.trim();
            var done = function () {
                button.textContent = "Copiado";
                window.setTimeout(function () {
                    button.textContent = original;
                }, 1600);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {
                    fallbackCopy(text);
                    done();
                });
            } else {
                fallbackCopy(text);
                done();
            }
        });
    });

    function fallbackCopy(text) {
        var field = document.createElement("textarea");
        field.value = text;
        field.setAttribute("readonly", "");
        field.style.position = "fixed";
        field.style.opacity = "0";
        document.body.appendChild(field);
        field.select();

        try {
            document.execCommand("copy");
        } catch (error) {
            // Nothing else to try: the panel still shows the value.
        }

        document.body.removeChild(field);
    }

    // ------------------------------------------------------------------
    // Retention presets on the settings desk.
    // ------------------------------------------------------------------
    document.querySelectorAll("[data-retention-preset]").forEach(function (chip) {
        chip.addEventListener("click", function () {
            var input = document.querySelector("[data-retention-input]");

            if (!input) {
                return;
            }

            input.value = chip.getAttribute("data-retention-preset");
            input.dispatchEvent(new Event("input", { bubbles: true }));

            document.querySelectorAll("[data-retention-preset]").forEach(function (other) {
                other.classList.toggle("is-active", other === chip);
                other.setAttribute("aria-pressed", other === chip ? "true" : "false");
            });
        });
    });

    // Close an open overflow menu when the click lands somewhere else.
    document.addEventListener("click", function (event) {
        document.querySelectorAll(".row-menu[open]").forEach(function (menu) {
            if (!menu.contains(event.target)) {
                menu.removeAttribute("open");
            }
        });
    });
})();
