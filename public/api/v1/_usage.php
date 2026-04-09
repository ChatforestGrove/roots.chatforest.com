<?php
/**
 * Usage stats endpoint — view API usage for the authenticated account.
 *
 * GET /usage          — summary: this month's credits + top endpoints
 * GET /usage/history  — daily breakdown for current month
 */

$sub = $segments[3] ?? "";

if ($method === "GET" && $sub === "") {
    // Monthly summary + top endpoints
    $period_start = date("Y-m-01");

    // Credits
    $cr = $pdo->prepare(
        "SELECT monthly_limit, used_this_month FROM api_credits
         WHERE account_id = ? AND period_start = ?"
    );
    $cr->execute([$auth_account_id, $period_start]);
    $credit_row = $cr->fetch(\PDO::FETCH_ASSOC);

    $limit = $credit_row ? (int) $credit_row["monthly_limit"] : 10000;
    $used  = $credit_row ? (int) $credit_row["used_this_month"] : 0;

    // Top endpoints this month
    $top = $pdo->prepare(
        "SELECT u.endpoint, u.method, COUNT(*) AS calls
         FROM api_usage u
         JOIN api_keys k ON k.key_id = u.key_id
         WHERE k.account_id = ? AND u.called_at >= ?
         GROUP BY u.endpoint, u.method
         ORDER BY calls DESC
         LIMIT 20"
    );
    $top->execute([$auth_account_id, $period_start]);
    $top_endpoints = $top->fetchAll(\PDO::FETCH_ASSOC);

    // Cast counts
    foreach ($top_endpoints as &$row) {
        $row["calls"] = (int) $row["calls"];
    }

    echo json_encode([
        "period" => $period_start,
        "monthly_limit" => $limit,
        "used" => $used,
        "remaining" => max(0, $limit - $used),
        "top_endpoints" => $top_endpoints,
    ]);
    exit;
}

if ($method === "GET" && $sub === "history") {
    // Daily breakdown for current month
    $period_start = date("Y-m-01");

    $daily = $pdo->prepare(
        "SELECT DATE(u.called_at) AS day, COUNT(*) AS calls
         FROM api_usage u
         JOIN api_keys k ON k.key_id = u.key_id
         WHERE k.account_id = ? AND u.called_at >= ?
         GROUP BY DATE(u.called_at)
         ORDER BY day"
    );
    $daily->execute([$auth_account_id, $period_start]);
    $rows = $daily->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row["calls"] = (int) $row["calls"];
    }

    echo json_encode(["period" => $period_start, "daily" => $rows]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed", "allowed" => ["GET /usage", "GET /usage/history"]]);
