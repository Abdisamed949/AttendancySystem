/**
 * messages.php's live layer: polls ajax/chat_poll.php for new messages in
 * the open conversation every 3s and appends them without a full reload,
 * and intercepts the send form to post via ajax/chat_send.php instead of
 * a full page round-trip. The <form> itself still points at messages.php
 * (see the "no-JS fallback" branch there), so the page keeps working with
 * JS disabled — this file only upgrades the experience when it's present.
 */
(function () {
    const messagesEl = document.getElementById('chatMessages');
    if (!messagesEl) {
        return;
    }

    const base = window.ADMAS_BASE_URL || '';
    const withId = messagesEl.getAttribute('data-with-id');
    const myId = messagesEl.getAttribute('data-my-id');
    const form = document.getElementById('chatSendForm');
    const input = document.getElementById('chatBodyInput');

    let lastId = 0;
    messagesEl.querySelectorAll('.chat-bubble').forEach((el) => {
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (id > lastId) {
            lastId = id;
        }
    });

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function appendMessage(m, mine) {
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (mine ? 'mine' : 'theirs');
        bubble.setAttribute('data-id', String(m.id));
        bubble.innerHTML = m.body.replace(/\n/g, '<br>') + '<span class="chat-bubble-time"></span>';
        bubble.querySelector('.chat-bubble-time').textContent = m.time_label;
        messagesEl.appendChild(bubble);
        lastId = Math.max(lastId, m.id);
    }

    function poll() {
        fetch(base + '/ajax/chat_poll.php?with=' + encodeURIComponent(withId) + '&after_id=' + lastId)
            .then((r) => r.json())
            .then((data) => {
                if (!data.ok || !data.messages || !data.messages.length) {
                    return;
                }
                const wasAtBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 60;
                data.messages.forEach((m) => appendMessage(m, String(m.sender_id) === String(myId)));
                if (wasAtBottom) {
                    scrollToBottom();
                }
            })
            .catch(() => {
                // transient network hiccup — keep polling, don't give up
            });
    }

    scrollToBottom();
    setInterval(poll, 3000);

    if (form && input) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const body = input.value.trim();
            if (body === '') {
                return;
            }
            const params = new URLSearchParams();
            params.set('receiver_id', withId);
            params.set('body', body);

            fetch(base + '/ajax/chat_send.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: params.toString(),
            })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.ok) {
                        alert(data.message || 'Could not send message.');
                        return;
                    }
                    appendMessage({
                        id: data.message.id,
                        body: data.message.body,
                        time_label: data.message.time_label,
                        sender_id: myId,
                    }, true);
                    scrollToBottom();
                    input.value = '';
                    input.focus();
                })
                .catch(() => {
                    alert('Could not reach the server.');
                });
        });
    }
})();
