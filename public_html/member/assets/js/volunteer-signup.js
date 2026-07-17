/**
 * Volunteer slot signup widget interactions.
 * Shared by volunteers.php and calendar.php's selected-day panel
 * (see partials/volunteer_signup_table.php for the markup this drives).
 */

window.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('highlighted-event');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

function myContactId() {
    return document.body.dataset.myContactId || '0';
}

// slotKey is a slot id, or the literal 'ALL' for the sign-up-for-everything widget
function showSignupConfirm(evtId, slotKey) {
    document.getElementById('btn-container-' + evtId + '-' + slotKey).style.display = 'none';
    document.getElementById('confirm-container-' + evtId + '-' + slotKey).style.display = 'block';
}

function cancelSignup(evtId, slotKey) {
    document.getElementById('confirm-container-' + evtId + '-' + slotKey).style.display = 'none';
    document.getElementById('btn-container-' + evtId + '-' + slotKey).style.display = 'block';

    // Reset admin selection if applicable
    const radioSelf = document.querySelector('input[name="signup_type_' + evtId + '_' + slotKey + '"][value="self"]');
    if (radioSelf) {
        radioSelf.checked = true;
        toggleAdminSignupType(evtId, slotKey, 'self');
    }
    const searchInput = document.querySelector('#admin-search-' + evtId + '-' + slotKey + ' input');
    if (searchInput) {
        searchInput.value = '';
    }
}

function toggleAdminSignupType(evtId, slotKey, type) {
    const searchDiv = document.getElementById('admin-search-' + evtId + '-' + slotKey);
    const contactIdInput = document.getElementById('contact-id-' + evtId + '-' + slotKey);
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

function updateMemberId(input, evtId, slotKey) {
    const val = input.value;
    const match = val.match(/\(ID:\s*(\d+)\)/);
    const contactIdInput = document.getElementById('contact-id-' + evtId + '-' + slotKey);
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

function validateAdminSignup(form, evtId, slotKey) {
    const contactIdInput = document.getElementById('contact-id-' + evtId + '-' + slotKey);
    const radioOther = document.querySelector('input[name="signup_type_' + evtId + '_' + slotKey + '"][value="other"]');
    if (radioOther && radioOther.checked) {
        if (!contactIdInput.value || contactIdInput.value === myContactId()) {
            alert("Please search and select a valid member from the dropdown list.");
            return false;
        }
    }
    return true;
}
