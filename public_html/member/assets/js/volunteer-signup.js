/**
 * Volunteer slot signup widget interactions.
 * Shared by volunteers.php and calendar.php's selected-day panel
 * (see partials/volunteer_signup_table.php for the markup this drives).
 */

window.addEventListener('DOMContentLoaded', () => {
    // The same highlighted event can be rendered more than once on the page
    // (e.g. the desktop panel plus the always-present mobile fallback list,
    // each with its own id prefix -- see volunteer_signup_table.php's
    // $vsIdPrefix) -- only one copy is actually visible at a given viewport
    // width, so scroll to that one rather than whichever comes first in the DOM.
    for (const el of document.querySelectorAll('[id$="highlighted-event"]')) {
        if (el.offsetParent !== null) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            break;
        }
    }
});

// The day-cell AJAX click handler (Combo view's list panel) lives in
// assets/js/calendar-day-ajax.js, shared with calendar.php's day panel.

function myContactId() {
    return document.body.dataset.myContactId || '0';
}

// prefix namespaces which render of the table this call targets (see
// volunteer_signup_table.php's $vsIdPrefix) -- the same event/slot can be
// rendered more than once on one page (desktop panel + mobile fallback list),
// so ids must be disambiguated or getElementById silently grabs the wrong
// (hidden) copy and the visible button never responds to taps/clicks.
// slotKey is a slot id, or the literal 'ALL' for the sign-up-for-everything widget
function showSignupConfirm(prefix, evtId, slotKey) {
    document.getElementById(prefix + 'btn-container-' + evtId + '-' + slotKey).style.display = 'none';
    document.getElementById(prefix + 'confirm-container-' + evtId + '-' + slotKey).style.display = 'block';
}

function cancelSignup(prefix, evtId, slotKey) {
    document.getElementById(prefix + 'confirm-container-' + evtId + '-' + slotKey).style.display = 'none';
    document.getElementById(prefix + 'btn-container-' + evtId + '-' + slotKey).style.display = 'block';

    // Reset admin selection if applicable
    const radioSelf = document.querySelector('input[name="' + prefix + 'signup_type_' + evtId + '_' + slotKey + '"][value="self"]');
    if (radioSelf) {
        radioSelf.checked = true;
        toggleAdminSignupType(prefix, evtId, slotKey, 'self');
    }
    const searchInput = document.querySelector('#' + prefix + 'admin-search-' + evtId + '-' + slotKey + ' input');
    if (searchInput) {
        searchInput.value = '';
    }
}

function toggleAdminSignupType(prefix, evtId, slotKey, type) {
    const searchDiv = document.getElementById(prefix + 'admin-search-' + evtId + '-' + slotKey);
    const contactIdInput = document.getElementById(prefix + 'contact-id-' + evtId + '-' + slotKey);
    if (type === 'other') {
        searchDiv.style.display = 'block';
        contactIdInput.value = ''; // Clear so they must select
        const inputField = searchDiv.querySelector('input');
        if (inputField) {
            inputField.focus();
        }
    } else {
        searchDiv.style.display = 'none';
        contactIdInput.value = myContactId();
    }
}

function updateMemberId(input, prefix, evtId, slotKey) {
    const val = input.value;
    const match = val.match(/\(ID:\s*(\d+)\)/);
    const contactIdInput = document.getElementById(prefix + 'contact-id-' + evtId + '-' + slotKey);
    if (match) {
        contactIdInput.value = match[1];
    } else {
        // Fallback: check if the exact text matches one option's value in the datalist
        const datalist = document.getElementById('members-list');
        if (datalist) {
            let found = false;
            for (let option of datalist.options) {
                if (option.value === val) {
                    const optMatch = option.value.match(/\(ID:\s*(\d+)\)/);
                    if (optMatch) {
                        contactIdInput.value = optMatch[1];
                        found = true;
                        break;
                    }
                }
            }
            if (!found) {
                contactIdInput.value = '';
            }
        } else {
            contactIdInput.value = '';
        }
    }
}

function validateAdminSignup(form, prefix, evtId, slotKey) {
    const contactIdInput = document.getElementById(prefix + 'contact-id-' + evtId + '-' + slotKey);
    const radioOther = document.querySelector('input[name="' + prefix + 'signup_type_' + evtId + '_' + slotKey + '"][value="other"]');
    if (radioOther && radioOther.checked) {
        if (!contactIdInput.value || contactIdInput.value === myContactId()) {
            alert("Please search and select a valid member from the dropdown list.");
            return false;
        }
    }
    return true;
}
