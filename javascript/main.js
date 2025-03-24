// Highlight a specific reply by adding a class
function highlightReply(id) {
    const divs = document.querySelectorAll('div.body');
    divs.forEach(div => div.classList.remove('highlighted'));

    if (id) {
        const replyBox = document.getElementById(`reply_box_${id}`);
        if (replyBox) {
            replyBox.classList.add('highlighted');
        }

        const replyButton = document.getElementById(`reply_button_${id}`);
        if (replyButton) {
            const reply = document.getElementById(`reply_${id}`);
            reply.style.display = 'block';
            replyBox.style.display = 'block';
            replyButton.textContent = '[hide]';
            window.location.hash = `reply_${id}_info`;
            return false;
        }
    }
    return true;
}

// Highlight posts by a specific poster
function highlightPoster(number) {
    const divs = document.querySelectorAll('div.body');
    divs.forEach(div => {
        div.classList.remove('highlighted');
        if (div.classList.contains(`poster_body_${number}`)) {
            div.classList.add('highlighted');
        }
    });
}

// Highlight table row based on checkbox state
function highlightRow(checkbox) {
    const row = checkbox.closest('tr');
    if (checkbox.checked) {
        row.classList.add('checked');
    } else {
        row.classList.remove('checked');
    }
}

// Focus on an element by ID
function focusId(id) {
    const element = document.getElementById(id);
    if (element) {
        element.focus();
    }
    init();
}

// Add commas to numbers for readability
function addCommas(nStr) {
    nStr = String(nStr);
    const parts = nStr.split('.');
    let integerPart = parts[0];
    const decimalPart = parts.length > 1 ? `.${parts[1]}` : '';
    const regex = /(\d+)(\d{3})/;
    while (regex.test(integerPart)) {
        integerPart = integerPart.replace(regex, '$1,$2');
    }
    return integerPart + decimalPart;
}

// Add quick reply text to a textarea
function quickReply(id, content) {
    const textarea = document.getElementById('qr_text');
    if (textarea) {
        textarea.value += `@${addCommas(id)}\n`;
        if (content !== undefined) {
            textarea.value += `${decodeURIComponent(content)}\n\n`;
        }
        textarea.scrollIntoView(true);
        textarea.focus();
        textarea.scrollTop = textarea.scrollHeight;
        textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
    }
    return false;
}

// Check or uncheck all checkboxes in a form
function checkAll(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    const masterChecked = form.master_checkbox.checked;
    const inputs = form.querySelectorAll('input[type="checkbox"]');
    inputs.forEach(input => {
        input.checked = masterChecked;
        highlightRow(input);
    });
}

// Perform a quick action with confirmation
function quickAction(theElement, confirmMessage) {
    const message = confirmMessage || 'Really?';
    if (confirm(message)) {
        const form = document.getElementById('quick_action');
        if (form) {
            form.action = theElement.href;
            form.submit();
        }
    }
    return false;
}

// Update characters remaining counter
function updateCharactersRemaining(theInputOrTextarea, theElementToUpdate, maxCharacters) {
    const input = document.getElementById(theInputOrTextarea);
    const tracker = document.getElementById(theElementToUpdate);
    if (input && tracker) {
        tracker.textContent = maxCharacters - input.value.length;
    }
}

// Print characters remaining (used in HTML generation)
function printCharactersRemaining(idOfTrackerElement, numDefaultCharacters) {
    document.write(` (<strong id="${idOfTrackerElement}">${numDefaultCharacters}</strong> characters left)`);
}

// Remove snapback link if it exists
function removeSnapbackLink() {
    const snapbackLink = document.getElementById('snapback_link');
    if (snapbackLink) {
        snapbackLink.remove();
    }
}

// Create a snapback link to a reply
function createSnapbackLink(lastReplyId) {
    removeSnapbackLink();
    const div = document.createElement('div');
    div.id = 'snapback_link';
    const a = document.createElement('a');
    a.href = `#reply_${lastReplyId}`;
    a.className = 'help_cursor';
    a.title = 'Click me to snap back!';
    a.addEventListener('click', () => {
        highlightReply(lastReplyId);
        removeSnapbackLink();
    });
    const strong = document.createElement('strong');
    strong.textContent = '↕';
    a.appendChild(strong);
    div.appendChild(a);
    document.body.appendChild(div);
}

// Play or hide a video
function play_video(provider, media_ID, element, record_class, record_ID) {
    const my_ID = `${record_class}-${record_ID}-media-${media_ID}`;
    const container = document.getElementById(my_ID);
    if (element.textContent === 'play') {
        element.textContent = 'close';
        if (!container) {
            let video_player_html = '';
            if (provider === 'youtube') {
                video_player_html = `<div id="${my_ID}" style="display: none;" class="video wrapper c"><iframe width="500" height="405" src="https://www.youtube-nocookie.com/embed/${media_ID}?autoplay=1" frameborder="0" allowfullscreen></iframe></div>`;
            } else if (provider === 'vimeo') {
                video_player_html = `<div id="${my_ID}" style="display: none;" class="video wrapper c"><iframe src="https://player.vimeo.com/video/${media_ID}?autoplay=1" width="512" height="294" frameborder="0" allowfullscreen></iframe></div>`;
            }
            element.parentElement.insertAdjacentHTML('afterend', video_player_html);
            const newContainer = document.getElementById(my_ID);
            if (newContainer) {
                newContainer.style.display = 'block';
            }
        } else {
            container.style.display = 'block';
        }
    } else {
        element.textContent = 'play';
        if (container) {
            container.style.display = 'none';
        }
    }
}

// Upload an image to Imgur
function imgurUpload(file, apiKey) {
    if (!file || !file.type.match(/image.*/)) {
        return false;
    }

    const statusElement = document.getElementById('imgur_status');
    if (statusElement) {
        statusElement.textContent = 'Uploading...';
    }

    const fd = new FormData();
    fd.append('image', file);
    fd.append('key', apiKey);

    fetch('https://api.imgur.com/2/upload.json', {
        method: 'POST',
        body: fd
    })
    .then(response => response.json())
    .then(data => {
        const imgurInput = document.getElementById('imgur');
        if (imgurInput) {
            imgurInput.value = data.upload.links.original;
        }
        if (statusElement) {
            statusElement.remove();
        }
    })
    .catch(error => {
        console.error('Error uploading to Imgur:', error);
        if (statusElement) {
            statusElement.textContent = 'Upload failed';
        }
    });

    return false;
}

// Edit moderation reason
function editReason(editLink, currentReason, token) {
    document.querySelectorAll('.mod_reason').forEach(el => el.remove());
    document.querySelectorAll('.mod_edit').forEach(el => el.style.display = 'block');

    const form = document.createElement('form');
    form.action = editLink.href;
    form.method = 'post';
    form.className = 'mod_reason';

    const csrf = document.createElement('input');
    csrf.name = 'CSRF_token';
    csrf.type = 'hidden';
    csrf.value = token;

    const input = document.createElement('input');
    input.name = 'reason';
    input.type = 'text';
    input.size = '46';
    input.maxLength = '260';
    input.value = decodeURIComponent(currentReason);

    const submit = document.createElement('input');
    submit.type = 'submit';
    submit.value = currentReason === '' ? 'Add reason' : 'Edit reason';

    form.appendChild(csrf);
    form.appendChild(input);
    form.appendChild(submit);

    const td = editLink.closest('td');
    if (td) {
        td.appendChild(form);
    }
    editLink.style.display = 'none';
    input.focus();

    return false;
}

// Initialize based on URL hash
function init() {
    const hash = window.location.hash.substring(1);
    if (document.getElementById(hash)) {
        if (hash.startsWith('reply_')) {
            highlightReply(hash.substring(6));
        } else if (hash.startsWith('join_')) {
            highlightPoster(hash.substring(5));
        } else if (hash.startsWith('new')) {
            const newIdInput = document.getElementById('new_id');
            if (newIdInput) {
                highlightReply(newIdInput.value);
            }
        }
    }
}

// Run init when the page loads
window.addEventListener('load', init);