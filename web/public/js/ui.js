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
})();
