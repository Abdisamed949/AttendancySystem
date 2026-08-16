<?php
/**
 * Staff Messages — a WhatsApp-style two-pane direct-message chat shared by
 * University Rector / Head of Academic Affairs / Dean / Lecturer /
 * Registration Office, so any one of them can message any other directly
 * (e.g. to ask about an issue) without leaving the app. Lives at the app
 * root (shared by five roles), not under any one role folder — same
 * pattern already used by attendance.php/reports.php. Students are
 * deliberately not part of this — see includes/chat_helpers.php's
 * CHAT_STAFF_ROLES, the single source of truth for who may chat here,
 * shared with both ajax/chat_send.php and ajax/chat_poll.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav_items.php';
require_once __DIR__ . '/includes/chat_helpers.php';

require_role(CHAT_STAFF_ROLES);

$conn = db();
$currentUser = current_user();
$myId = (int) $currentUser['id'];
$role = current_role();

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip)
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

// ---------------------------------------------------------------------
// No-JS fallback: a plain form POST lands here too (see the <form> below,
// which points at this same file when JS hasn't wired up fetch()).
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $receiverId = (int) ($_POST['receiver_id'] ?? 0);
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($receiverId !== $myId && chat_is_valid_contact($conn, $receiverId) && $body !== '') {
        $body = mb_substr($body, 0, 2000);
        $stmt = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)');
        $stmt->bind_param('iis', $myId, $receiverId, $body);
        $stmt->execute();
        $stmt->close();
    }
    redirect_to('messages.php?with=' . $receiverId);
}

// ---------------------------------------------------------------------
// Contacts — every other active staff user, most-recently-messaged first.
// ---------------------------------------------------------------------
$placeholders = implode(',', array_fill(0, count(CHAT_STAFF_ROLES), '?'));
$types = 'i' . str_repeat('s', count(CHAT_STAFF_ROLES));
$params = array_merge([$myId], CHAT_STAFF_ROLES);

$contactsStmt = $conn->prepare(
    "SELECT u.id, u.full_name, u.photo_path, r.name AS role_name
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.id != ? AND u.status = 'active' AND r.name IN ($placeholders)
     ORDER BY u.full_name"
);
$contactsStmt->bind_param($types, ...$params);
$contactsStmt->execute();
$contacts = $contactsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$contactsStmt->close();

foreach ($contacts as &$contact) {
    $partnerId = (int) $contact['id'];

    $lastStmt = $conn->prepare(
        'SELECT body, created_at, sender_id FROM messages
         WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
         ORDER BY id DESC LIMIT 1'
    );
    $lastStmt->bind_param('iiii', $myId, $partnerId, $partnerId, $myId);
    $lastStmt->execute();
    $last = $lastStmt->get_result()->fetch_assoc();
    $lastStmt->close();
    $contact['last_body'] = $last['body'] ?? null;
    $contact['last_at'] = $last['created_at'] ?? null;

    $unreadStmt = $conn->prepare('SELECT COUNT(*) AS c FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 0');
    $unreadStmt->bind_param('ii', $partnerId, $myId);
    $unreadStmt->execute();
    $contact['unread'] = (int) ($unreadStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $unreadStmt->close();
}
unset($contact);

usort($contacts, static function (array $a, array $b): int {
    return strtotime((string) ($b['last_at'] ?? '1970-01-01')) <=> strtotime((string) ($a['last_at'] ?? '1970-01-01'));
});

// ---------------------------------------------------------------------
// Selected conversation.
// ---------------------------------------------------------------------
$withId = (int) ($_GET['with'] ?? 0);
$activeContact = null;
foreach ($contacts as $contact) {
    if ((int) $contact['id'] === $withId) {
        $activeContact = $contact;
        break;
    }
}
if ($activeContact === null) {
    $withId = 0;
}

$history = [];
if ($withId > 0) {
    $histStmt = $conn->prepare(
        'SELECT id, sender_id, body, created_at FROM messages
         WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
         ORDER BY id ASC'
    );
    $histStmt->bind_param('iiii', $myId, $withId, $withId, $myId);
    $histStmt->execute();
    $history = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $histStmt->close();

    $markStmt = $conn->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0');
    $markStmt->bind_param('ii', $withId, $myId);
    $markStmt->execute();
    $markStmt->close();
}

function chat_initials(string $fullName): string
{
    $initials = '';
    foreach (preg_split('/\s+/', trim($fullName)) as $part) {
        if ($part !== '') {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    return mb_substr($initials, 0, 2) ?: '?';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Messages — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div class="page-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);"><i class="bi bi-chat-dots-fill" style="color: var(--admas-sky);"></i> Staff Messages</h4>
                    <p class="text-muted mb-0">Talk directly with any Rector / Head of Academic Affairs / Dean / Lecturer / Registration Office account.</p>
                </div>
            </div>

            <div class="chat-shell <?= $withId > 0 ? 'chat-conversation-open' : '' ?>" id="chatShell">
                <div class="chat-contacts">
                    <div class="chat-contacts-header"><i class="bi bi-people-fill"></i> Staff Directory</div>
                    <div class="chat-contacts-list">
                        <?php if (empty($contacts)): ?>
                            <div class="p-3 text-muted small">No other staff accounts yet.</div>
                        <?php endif; ?>
                        <?php foreach ($contacts as $contact): ?>
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/messages.php?with=<?= (int) $contact['id'] ?>"
                               class="chat-contact <?= (int) $contact['id'] === $withId ? 'active' : '' ?>">
                                <?php if (!empty($contact['photo_path'])): ?>
                                    <img class="chat-contact-photo" src="<?= htmlspecialchars(BASE_URL) ?>/uploads/profile_photos/<?= htmlspecialchars((string) $contact['photo_path']) ?>" alt="">
                                <?php else: ?>
                                    <div class="chat-contact-initials"><?= htmlspecialchars(chat_initials((string) $contact['full_name'])) ?></div>
                                <?php endif; ?>
                                <div class="chat-contact-body">
                                    <div class="chat-contact-name"><?= htmlspecialchars((string) $contact['full_name']) ?></div>
                                    <div class="chat-contact-role"><?= htmlspecialchars(role_label((string) $contact['role_name'])) ?></div>
                                </div>
                                <?php if ($contact['unread'] > 0): ?>
                                    <span class="chat-contact-unread"><?= (int) $contact['unread'] ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="chat-panel">
                    <?php if ($activeContact === null): ?>
                        <div class="chat-empty-state">
                            <i class="bi bi-chat-square-text"></i>
                            <div>Select a colleague on the left to start messaging.</div>
                        </div>
                    <?php else: ?>
                        <div class="chat-panel-header">
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/messages.php" class="btn-icon d-md-none" title="Back">
                                <i class="bi bi-arrow-left"></i>
                            </a>
                            <?php if (!empty($activeContact['photo_path'])): ?>
                                <img class="chat-contact-photo" src="<?= htmlspecialchars(BASE_URL) ?>/uploads/profile_photos/<?= htmlspecialchars((string) $activeContact['photo_path']) ?>" alt="">
                            <?php else: ?>
                                <div class="chat-contact-initials"><?= htmlspecialchars(chat_initials((string) $activeContact['full_name'])) ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold" style="color: var(--admas-text);"><?= htmlspecialchars((string) $activeContact['full_name']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars(role_label((string) $activeContact['role_name'])) ?></div>
                            </div>
                        </div>

                        <div class="chat-messages" id="chatMessages" data-with-id="<?= (int) $withId ?>" data-my-id="<?= $myId ?>">
                            <?php foreach ($history as $m): ?>
                                <?php $mine = (int) $m['sender_id'] === $myId; ?>
                                <div class="chat-bubble <?= $mine ? 'mine' : 'theirs' ?>" data-id="<?= (int) $m['id'] ?>">
                                    <?= nl2br(htmlspecialchars((string) $m['body'])) ?>
                                    <span class="chat-bubble-time"><?= htmlspecialchars(date('g:i A', strtotime((string) $m['created_at']))) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form class="chat-input-bar" id="chatSendForm" method="post" action="<?= htmlspecialchars(BASE_URL) ?>/messages.php">
                            <input type="hidden" name="action" value="send">
                            <input type="hidden" name="receiver_id" value="<?= (int) $withId ?>">
                            <input type="text" name="body" id="chatBodyInput" class="form-control" placeholder="Type a message..." autocomplete="off" maxlength="2000" required>
                            <button type="submit" class="btn text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                <i class="bi bi-send-fill"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>window.ADMAS_BASE_URL = <?= json_encode(BASE_URL, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/staff_chat.js"></script>
</body>
</html>
