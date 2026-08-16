<?php
/**
 * Shared helpers for messages.php (Staff Messages) and its two ajax/
 * endpoints — kept in one place so the "who is allowed to chat with whom"
 * rule can never drift between the page and the endpoints that actually
 * write/read messages.
 */
declare(strict_types=1);

/** Roles allowed into the staff chat. Students are deliberately excluded. */
const CHAT_STAFF_ROLES = ['university_rector', 'head_academic', 'dean', 'lecturer', 'registration'];

/**
 * True if $userId is an active user whose role is one of CHAT_STAFF_ROLES.
 * Used to validate a receiver_id/with id before ever reading/writing a
 * message for it — never trust a posted/queried id on its own.
 */
function chat_is_valid_contact(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count(CHAT_STAFF_ROLES), '?'));
    $types = 'i' . str_repeat('s', count(CHAT_STAFF_ROLES));
    $params = array_merge([$userId], CHAT_STAFF_ROLES);

    $stmt = $conn->prepare(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND u.status = 'active' AND r.name IN ($placeholders)"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found !== null;
}
