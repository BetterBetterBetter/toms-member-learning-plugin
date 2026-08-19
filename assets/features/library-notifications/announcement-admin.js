(function () {
    "use strict";

    var config = window.tsolAnnouncementAdmin || {};

    function selectedPreset() {
        var selected = document.querySelector('[name="tsol_announcement[audience_preset]"]:checked');
        return selected ? selected.value : "all_linked";
    }

    function updateConditionalFields() {
        var preset = selectedPreset();
        document.querySelectorAll("[data-audience-fields]").forEach(function (field) {
            field.hidden = field.getAttribute("data-audience-fields") !== preset;
        });
        if (preset !== "active_membership") {
            document.querySelectorAll("[data-membership-option]").forEach(function (membership) {
                membership.setCustomValidity("");
            });
        }
        if (preset !== "specific_users") {
            var specificSearch = document.querySelector('[data-user-picker][data-field="specific_user_ids"] [data-user-search]');
            if (specificSearch) {
                specificSearch.setCustomValidity("");
            }
        }
    }

    function markAudienceDirty() {
        var button = document.querySelector("[data-announcement-preview]");
        var result = document.querySelector("[data-announcement-preview-result]");
        if (!button || !result) {
            return;
        }
        button.disabled = true;
        button.setAttribute("data-audience-dirty", "true");
        result.textContent = config.strings.saveBeforePreview;
    }

    function hasCurrentDestination() {
        var select = document.getElementById("tsol-announcement-destination-select");
        if (!select || select.value === "0") {
            return false;
        }
        var option = select.options[select.selectedIndex];
        return Boolean(option && !option.hasAttribute("data-unavailable-destination"));
    }

    function updateDestinationDependentPresets(markDirty) {
        var hasDestination = hasCurrentDestination();
        var changed = false;
        document.querySelectorAll("[data-requires-destination]").forEach(function (radio) {
            radio.disabled = !hasDestination;
            var label = radio.closest(".tsol-announcement-preset");
            if (label) {
                label.classList.toggle("is-disabled", !hasDestination);
                label.setAttribute("aria-disabled", hasDestination ? "false" : "true");
            }
            if (!hasDestination && radio.checked) {
                var fallback = document.querySelector('[name="tsol_announcement[audience_preset]"][value="all_linked"]');
                if (fallback) {
                    fallback.checked = true;
                    changed = true;
                }
            }
        });
        var guidance = document.querySelector("[data-announcement-destination-guidance]");
        if (guidance) {
            guidance.hidden = hasDestination;
        }
        updateConditionalFields();
        if (markDirty || changed) {
            markAudienceDirty();
        }
    }

    function selectedUserIds() {
        var ids = new Set();
        document.querySelectorAll("[data-user-picker] [data-user-id]").forEach(function (item) {
            ids.add(item.getAttribute("data-user-id"));
        });
        return ids;
    }

    function updateUserCounts() {
        var total = selectedUserIds().size;
        document.querySelectorAll("[data-user-picker]").forEach(function (picker) {
            var count = picker.querySelectorAll("[data-user-id]").length;
            var output = picker.querySelector("[data-user-count]");
            var search = picker.querySelector("[data-user-search]");
            if (output) {
                output.textContent = String(count);
            }
            if (search) {
                search.disabled = total >= 100;
                if (total < 100 && search.validationMessage === config.strings.userLimit) {
                    search.setCustomValidity("");
                }
            }
        });
    }

    function updateMembershipCount() {
        var memberships = Array.from(document.querySelectorAll("[data-membership-option]"));
        var count = memberships.filter(function (item) { return item.checked; }).length;
        var output = document.querySelector("[data-membership-count]");
        if (output) {
            output.textContent = String(count);
        }
        memberships.forEach(function (item) {
            item.disabled = !item.checked && count >= 20;
            if (count > 0) {
                item.setCustomValidity("");
            }
        });
    }

    function chip(picker, user) {
        if (picker.querySelector('[data-user-id="' + user.id + '"]')) {
            return false;
        }
        var alreadySelectedElsewhere = selectedUserIds().has(String(user.id));
        if (!alreadySelectedElsewhere && selectedUserIds().size >= 100) {
            var limitedSearch = picker.querySelector("[data-user-search]");
            limitedSearch.setCustomValidity(config.strings.userLimit);
            limitedSearch.reportValidity();
            return false;
        }
        var field = picker.getAttribute("data-field");
        var wrapper = document.createElement("span");
        wrapper.className = "tsol-announcement-user-chip";
        wrapper.setAttribute("data-user-id", String(user.id));

        var text = document.createElement("span");
        text.textContent = user.label;
        wrapper.appendChild(text);

        var input = document.createElement("input");
        input.type = "hidden";
        input.name = "tsol_announcement[" + field + "][]";
        input.value = String(user.id);
        wrapper.appendChild(input);

        var remove = document.createElement("button");
        remove.type = "button";
        remove.className = "button-link";
        remove.setAttribute("data-remove-user", "");
        remove.setAttribute("aria-label", (config.strings && config.strings.removeUser ? config.strings.removeUser : "Remove") + " " + user.label);
        var icon = document.createElement("span");
        icon.className = "dashicons dashicons-no-alt";
        icon.setAttribute("aria-hidden", "true");
        remove.appendChild(icon);
        wrapper.appendChild(remove);

        picker.querySelector("[data-user-chips]").appendChild(wrapper);
        var search = picker.querySelector("[data-user-search]");
        search.setCustomValidity("");
        updateUserCounts();
        markAudienceDirty();
        return true;
    }

    function renderSearchResults(picker, users) {
        var results = picker.querySelector("[data-user-results]");
        results.replaceChildren();
        if (!users.length) {
            var empty = document.createElement("p");
            empty.textContent = config.strings.noUsers;
            results.appendChild(empty);
        } else {
            users.forEach(function (user) {
                var button = document.createElement("button");
                button.type = "button";
                button.className = "button-link";
                button.textContent = user.label;
                button.addEventListener("click", function () {
                    if (chip(picker, user)) {
                        results.hidden = true;
                        picker.querySelector("[data-user-search]").value = "";
                    }
                });
                results.appendChild(button);
            });
        }
        results.hidden = false;
    }

    function initUserPicker(picker) {
        var search = picker.querySelector("[data-user-search]");
        var timer;
        search.addEventListener("input", function () {
            window.clearTimeout(timer);
            var term = search.value.trim();
            var results = picker.querySelector("[data-user-results]");
            if (term.length < 3) {
                results.hidden = true;
                return;
            }
            timer = window.setTimeout(function () {
                results.hidden = false;
                results.textContent = config.strings.searching;
                var params = new URLSearchParams({
                    action: "tsol_announcement_user_search",
                    nonce: config.nonce,
                    term: term,
                });
                fetch(config.ajaxUrl + "?" + params.toString(), { credentials: "same-origin" })
                    .then(function (response) { return response.json(); })
                    .then(function (payload) {
                        if (!payload.success || !payload.data || !Array.isArray(payload.data.users)) {
                            throw new Error("search_failed");
                        }
                        renderSearchResults(picker, payload.data.users);
                    })
                    .catch(function () {
                        results.textContent = config.strings.searchFailed;
                    });
            }, 250);
        });
        picker.addEventListener("click", function (event) {
            var remove = event.target.closest("[data-remove-user]");
            if (remove) {
                remove.closest("[data-user-id]").remove();
                updateUserCounts();
                markAudienceDirty();
            }
        });
    }

    function updateBodyCount() {
        var output = document.querySelector("[data-announcement-body-count]");
        var textarea = document.getElementById("tsol_announcement_body");
        if (!output || !textarea) {
            return;
        }
        var text = textarea.value.replace(/<[^>]*>/g, "").trim();
        if (window.tinymce) {
            var editor = window.tinymce.get("tsol_announcement_body");
            if (editor) {
                text = editor.getContent({ format: "text" }).trim();
            }
        }
        output.textContent = String(Array.from(text).length);
    }

    function initBodyCounter() {
        var textarea = document.getElementById("tsol_announcement_body");
        if (!textarea) {
            return;
        }
        textarea.addEventListener("input", updateBodyCount);
        if (window.tinymce) {
            window.tinymce.on("AddEditor", function (event) {
                if (event.editor.id === "tsol_announcement_body") {
                    event.editor.on("input change keyup SetContent", updateBodyCount);
                    updateBodyCount();
                }
            });
        }
        updateBodyCount();
    }

    function initPreview(button) {
        button.addEventListener("click", function () {
            var result = document.querySelector("[data-announcement-preview-result]");
            if (!result || button.disabled) {
                return;
            }
            button.disabled = true;
            result.textContent = config.strings.previewing;
            var body = new URLSearchParams({
                action: "tsol_announcement_preview",
                nonce: config.nonce,
                postId: button.getAttribute("data-post-id"),
            });
            fetch(config.ajaxUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
                body: body.toString(),
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload.success || !payload.data || typeof payload.data.html !== "string") {
                        var error = new Error("preview_failed");
                        error.safeMessage = payload && payload.data && typeof payload.data.message === "string" ? payload.data.message : "";
                        throw error;
                    }
                    result.innerHTML = payload.data.html;
                })
                .catch(function (error) {
                    result.textContent = error && error.safeMessage ? error.safeMessage : config.strings.previewFailed;
                })
                .finally(function () {
                    button.disabled = false;
                });
        });
    }

    function validateAudience(event) {
        var destination = document.getElementById("tsol-announcement-destination-select");
        if (destination) {
            destination.setCustomValidity("");
            var selectedOption = destination.options[destination.selectedIndex];
            if (selectedOption && selectedOption.hasAttribute("data-unavailable-destination")) {
                destination.setCustomValidity("Choose a published Course, Series, or the general Notifications page.");
                event.preventDefault();
                destination.reportValidity();
                return;
            }
        }

        var preset = selectedPreset();
        if (preset === "active_membership") {
            var memberships = Array.from(document.querySelectorAll("[data-membership-option]"));
            if (!memberships.some(function (item) { return item.checked; })) {
                event.preventDefault();
                if (memberships[0]) {
                    memberships[0].setCustomValidity(config.strings.membershipRequired);
                    memberships[0].reportValidity();
                }
                return;
            }
        }
        if (preset === "specific_users") {
            var picker = document.querySelector('[data-user-picker][data-field="specific_user_ids"]');
            if (picker && !picker.querySelector("[data-user-id]")) {
                event.preventDefault();
                var search = picker.querySelector("[data-user-search]");
                search.setCustomValidity(config.strings.specificUserRequired);
                search.reportValidity();
            }
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        var title = document.getElementById("title");
        if (title) {
            title.maxLength = 160;
            title.required = true;
        }
        document.querySelectorAll('[name="tsol_announcement[audience_preset]"]').forEach(function (radio) {
            radio.addEventListener("change", function () {
                updateConditionalFields();
                markAudienceDirty();
            });
        });
        var destination = document.getElementById("tsol-announcement-destination-select");
        if (destination) {
            destination.addEventListener("change", function () {
                destination.setCustomValidity("");
                updateDestinationDependentPresets(true);
            });
        }
        document.querySelectorAll("[data-membership-option]").forEach(function (membership) {
            membership.addEventListener("change", function () {
                updateMembershipCount();
                markAudienceDirty();
            });
        });
        updateDestinationDependentPresets(false);
        updateMembershipCount();
        updateUserCounts();
        document.querySelectorAll("[data-user-picker]").forEach(initUserPicker);
        document.querySelectorAll("[data-announcement-preview]").forEach(initPreview);
        var form = document.getElementById("post");
        if (form) {
            form.addEventListener("submit", validateAudience);
        }
        initBodyCounter();
    });
})();
