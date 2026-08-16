<?php
/**
 * Polled by assets/js/staff_chat.js every ~3s while a conversation is open
 * on messages.php. Returns any messages newer than ?after_id in either
 * direction between the current user and ?with, and marks the ones
 * addressed TO the current user as read (so the unread badge/topbar bell
 * drop the moment they're actually seen, not just on next page load).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_helpers.php';

require_role(CHAT_STAFF_ROLES);

header('Content-Type: application/json');

$conn = db();
$myId = (int) $_SESSION['user_id'];
$withId = (int) ($_GET['with'] ?? 0);
$afterId = (int) ($_GET['after_id'] ?? 0);

if (!chat_is_valid_contact($conn, $withId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid conversation.']);
    exit;
}

$stmt = $conn->prepare(
    'SELECT id, sender_id, body, created_at
     FROM messages
     WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
       AND id > ?
     ORDER BY id ASC'
);
$stmt->bind_param('iiiii', $myId, $withId, $withId, $myId, $afterId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$markStmt = $conn->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0');
$markStmt->bind_param('ii', $withId, $myId);
$markStmt->execute();
$markStmt->close();

$messages = array_map(static function (array $row): array {
    return [
        'id' => (int) $row['id'],
        'sender_id' => (int) $row['sender_id'],
        'body' => htmlspecialchars((string) $row['body'], ENT_QUOTES, 'UTF-8'),
        'time_label' => date('g:i A', strtotime((string) $row['created_at'])),
    ];
}, $rows);

echo json_encode(['ok' => true, 'messages' => $messages]);
