<?php
/**
 * Waitlist endpoint — no auth required.
 * POST /waitlist  { "email": "..." }
 */

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid email address is required']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? null;

// Simple IP rate limit: max 5 signups per IP per hour
if ($ip) {
    $rate_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM waitlist WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $rate_stmt->execute([$ip]);
    if ((int)$rate_stmt->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many signups from this IP. Try again later.']);
        exit;
    }
}

try {
    $stmt = $pdo->prepare("INSERT INTO waitlist (email, ip_address) VALUES (?, ?)");
    $stmt->execute([$email, $ip]);
    http_response_code(201);
    echo json_encode(['status' => 'ok', 'message' => "You're on the list. We'll be in touch."]);
} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        // Duplicate email
        echo json_encode(['status' => 'ok', 'message' => "You're already on the list!"]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Could not save signup']);
    }
}
