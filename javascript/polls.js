// Global variable to track the number of poll options
let numPollOptions;

// Function to toggle the visibility of the poll section
function togglePoll(elem) {
    const pollSection = document.getElementById('topic_poll');
    const enablePollInput = document.getElementById('enable_poll');

    if (pollSection.style.display === 'none') {
        elem.textContent = '[-] Poll';
        pollSection.style.display = 'block';
        const lastOption = document.getElementById(`poll_option_${numPollOptions - 1}`);
        if (lastOption) lastOption.focus();
        enablePollInput.value = '1';
    } else {
        elem.textContent = '[+] Poll';
        pollSection.style.display = 'none';
        enablePollInput.value = '0';
    }
}

// Function to handle focus, keypress, and blur events for poll inputs
function pollFocus(event) {
    if (numPollOptions >= 9) return;

    const pollInputs = document.querySelectorAll('.poll_input');
    const allFilled = Array.from(pollInputs).every(input => input.value.trim() !== '');

    if (allFilled) {
        numPollOptions++;
        const tr = document.createElement('tr');
        if (numPollOptions % 2 === 1) tr.classList.add('odd');

        const td1 = document.createElement('td');
        td1.textContent = `Poll option #${numPollOptions}`;
        td1.classList.add('minimal');

        const td2 = document.createElement('td');
        const input = document.createElement('input');
        input.type = 'text';
        input.id = `poll_option_${numPollOptions}`;
        input.name = 'option[]';
        input.classList.add('poll_input');
        input.size = '50';
        input.maxLength = '80';

        // Attach event listeners
        input.addEventListener('focus', pollFocus);
        input.addEventListener('keypress', pollFocus);
        input.addEventListener('blur', pollFocus);

        td2.appendChild(input);
        tr.appendChild(td1);
        tr.appendChild(td2);

        const pollSection = document.getElementById('topic_poll');
        pollSection.appendChild(tr);
    }
}

// Initialization function
function initPoll() {
    const pollInputs = document.querySelectorAll('.poll_input');
    numPollOptions = pollInputs.length;

    const firstInput = pollInputs[0];
    const pollSection = document.getElementById('topic_poll');
    const enablePollInput = document.getElementById('enable_poll');
    const pollToggle = document.getElementById('poll_toggle');

    if (!firstInput.value) {
        pollSection.style.display = 'none';
        enablePollInput.value = '0';
        pollToggle.textContent = '[+] Poll';
    } else {
        pollToggle.textContent = '[-] Poll';
        enablePollInput.value = '1';
    }

    // Attach event listeners to poll inputs
    pollInputs.forEach(input => {
        input.addEventListener('focus', pollFocus);
        input.addEventListener('keypress', pollFocus);
        input.addEventListener('blur', pollFocus);
    });

    // Attach click event to poll toggle
    pollToggle.addEventListener('click', () => togglePoll(pollToggle));
}

// Run initialization when the document is ready
document.addEventListener('DOMContentLoaded', initPoll);