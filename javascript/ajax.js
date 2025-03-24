// Show the topic panel with fetched data
function showTopic(data) {
    const topicPanel = document.getElementById('topic_panel');
    if (topicPanel) {
        topicPanel.innerHTML = `<button id="hide_topic">X</button>${data}`;
        topicPanel.style.display = 'block';

        const hideButton = document.getElementById('hide_topic');
        if (hideButton) {
            hideButton.addEventListener('click', hideTopic);
        }

        const submitButtons = topicPanel.querySelectorAll('input[type="submit"]');
        submitButtons.forEach(button => {
            button.addEventListener('click', async (event) => {
                event.preventDefault();
                const form = button.closest('form');
                if (form) {
                    const formData = new FormData(form);
                    formData.append(button.name, 'submit');
                    const action = form.action;

                    try {
                        const response = await fetch(action, {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.text();
                        showTopic(result);
                    } catch (error) {
                        console.error('Error submitting form:', error);
                    }
                }
            });
        });
    }
}

// Hide the topic panel
function hideTopic() {
    const topicPanel = document.getElementById('topic_panel');
    const mainPanel = document.getElementById('main_panel');
    if (topicPanel && mainPanel) {
        topicPanel.style.display = 'none';
        mainPanel.style.width = '98%';
        const rows = document.querySelectorAll('tr');
        rows.forEach(row => row.classList.remove('active_topic'));
    }
}

// Show a notice message
function showNotice(notice) {
    const existingNotice = document.getElementById('notice');
    if (existingNotice) {
        existingNotice.remove();
    }
    const noticeDiv = document.createElement('div');
    noticeDiv.id = 'notice';
    noticeDiv.innerHTML = `<strong>Notice</strong>: ${notice}`;
    noticeDiv.addEventListener('click', () => noticeDiv.remove());
    document.body.appendChild(noticeDiv);
    setTimeout(() => noticeDiv.style.opacity = '0', 3000);
    setTimeout(() => noticeDiv.remove(), 3500); // Fade out after 3s, remove after 3.5s
}

// Handle clicks on links to load topics via AJAX
document.addEventListener('click', async (event) => {
    const target = event.target.closest('a');
    if (target && target.href.includes(window.location.hostname)) {
        const regex = /\/topic\/(\d+)/;
        const match = regex.exec(target.href);
        if (match) {
            event.preventDefault();
            const threadID = match[1];
            const rows = document.querySelectorAll('tr');
            rows.forEach(row => row.classList.remove('active_topic', 'loading_topic'));
            const activeTr = target.closest('tr');
            if (activeTr) {
                activeTr.classList.add('loading_topic');
                try {
                    const response = await fetch(`topic.php?id=${threadID}`);
                    const data = await response.text();
                    showTopic(data);
                    const mainPanel = document.getElementById('main_panel');
                    if (mainPanel) {
                        mainPanel.style.width = '44%';
                    }
                    activeTr.classList.remove('loading_topic');
                    activeTr.classList.add('active_topic');
                    const newReplies = activeTr.querySelector('.new_replies');
                    if (newReplies) {
                        newReplies.remove();
                    }
                    if (target.href.includes('#new')) {
                        const topicPanel = document.getElementById('topic_panel');
                        const newElement = document.getElementById('new');
                        if (topicPanel && newElement) {
                            topicPanel.scrollTop = newElement.offsetTop - 70;
                            const newIdInput = document.getElementById('new_id');
                            if (newIdInput) {
                                highlightReply(newIdInput.value);
                            }
                        }
                    }
                } catch (error) {
                    console.error('Error loading topic:', error);
                    activeTr.classList.remove('loading_topic');
                }
            }
        }
    }
});