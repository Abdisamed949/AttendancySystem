<?php
/**
 * Sends one Staff Message. Called by assets/js/staff_chat.js on submit;
 * messages.php itself also POSTs here (as a plain form fallback) so the
 * page still works with JS disabled — see the "no-JS fallback" branch at
 * the bottom, which redirects back instead of returning JSON.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_helpers.php';

require_role(CHAT_STAFF_ROLES);

$conn = db();
$senderId = (int) $_SESSION['user_id'];
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

function chat_send_fail(bool $isAjax, string $message, int $withId = 0): never
{
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => $message]);
        exit;
    }
    $_SESSION['flash_error'] = $message;
    redirect_to('messages.php' . ($withId > 0 ? '?with=' . $withId : ''));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chat_send_fail($isAjax, 'Method not allowed.');
}

$receiverId = (int) ($_POST['receiver_id'] ?? 0);
$body = trim((string) ($_POST['body'] ?? ''));

if ($receiverId === $senderId || !chat_is_valid_contact($conn, $receiverId)) {
    chat_send_fail($isAjax, 'Invalid recipient.');
}

if ($body === '') {
    chat_send_fail($isAjax, 'Message cannot be empty.', $receiverId);
}

if (mb_strlen($body) > 2000) {
    $body = mb_substr($body, 0, 2000);
}

$stmt = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)');
$stmt->bind_param('iis', $senderId, $receiverId, $body);
$stmt->execute();
$messageId = (int) $stmt->insert_id;
$stmt->close();

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'message' => [
            'id' => $messageId,
            'sender_id' => $senderId,
            'body' => htmlspecialchars($body, ENT_QUOTES, 'UTF-8'),
            'created_at' => date('Y-m-d H:i:s'),
            'time_label' => date('g:i A'),
        ],
    ]);
    exit;
}

redirect_to('messages.php?with=' . $receiverId);
