<?php
/**
 * Credits endpoint — view balance and ledger history.
 *
 * GET /credits          — current balance + recent ledger entries
 * GET /credits/ledger   — full ledger with pagination
 */

$sub = $segments[3] ?? "";

if ($method === "GET" && $sub === "") {
    // Current balance + last 20 ledger entries
    $bal = $pdo->prepare("SELECT credit_balance FROM accounts WHERE account_id = ?");
    $bal->execute([$auth_account_id]);
    $balance = (int) $bal->fetchColumn();

    $recent = $pdo->prepare(
        "SELECT ledger_id, delta, balance_after, reason, related_endpoint, created_at
         FROM credit_ledger
         WHERE account_id = ?
         ORDER BY created_at DESC
         LIMIT 20"
    );
    $recent->execute([$auth_account_id]);
    $entries = $recent->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($entries as &$e) {
        $e["ledger_id"] = (int) $e["ledger_id"];
        $e["delta"] = (int) $e["delta"];
        $e["balance_after"] = (int) $e["balance_after"];
    }

    echo json_encode([
        "balance" => $balance,
        "cost_model" => [
            "reads" => 0,
            "writes" => 1,
            "note" => "GET requests are free. POST/PATCH/DELETE cost 1 credit each.",
        ],
        "recent_ledger" => $entries,
    ]);
    exit;
}

if ($method === "GET" && $sub === "ledger") {
    $limit = min((int) ($_GET["limit"] ?? 50), 200);
    $offset = max((int) ($_GET["offset"] ?? 0), 0);
    $reason = $_GET["reason"] ?? null;

    $where = "WHERE account_id = ?";
    $params = [$auth_account_id];

    if ($reason) {
        $where .= " AND reason = ?";
        $params[] = $reason;
    }

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM credit_ledger $where");
    $count_stmt->execute($params);
    $total = (int) $count_stmt->fetchColumn();

    $query = "SELECT ledger_id, delta, balance_after, reason, related_endpoint, created_at
              FROM credit_ledger $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($entries as &$e) {
        $e["ledger_id"] = (int) $e["ledger_id"];
        $e["delta"] = (int) $e["delta"];
        $e["balance_after"] = (int) $e["balance_after"];
    }

    echo json_encode([
        "total" => $total,
        "limit" => $limit,
        "offset" => $offset,
        "entries" => $entries,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed", "allowed" => ["GET /credits", "GET /credits/ledger"]]);
