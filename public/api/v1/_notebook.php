<?php
/**
 * Private Notebook endpoints — symmetric encryption (sodium_crypto_secretbox)
 * Each actor can only read/write their own entries.
 *
 * Vocab:
 *   GET    /notebook/vocab              — list vocab terms
 *   POST   /notebook/vocab              — create vocab term
 *   PATCH  /notebook/vocab/{id}         — update vocab term
 *   DELETE /notebook/vocab/{id}         — deactivate vocab term
 *
 * Entries:
 *   GET    /notebook/entries            — list entries (decrypted)
 *   POST   /notebook/entries            — create entry (encrypted)
 *   GET    /notebook/entries/{id}       — get single entry
 *   DELETE /notebook/entries/{id}       — delete entry
 */

$method = $_SERVER["REQUEST_METHOD"];
$sub_resource = $segments[3] ?? "";
$id_param = isset($segments[4]) ? (int)$segments[4] : null;

// Derive symmetric key from raw API key
$notebook_key = \Crypto\Notebook::deriveKey($raw_key);
$my_actor_id = (int)$auth_actor["actor_id"];

// ─── VOCAB ───────────────────────────────────────────────────────────
if ($sub_resource === "vocab") {

    switch ($method) {
        case "GET":
            // List vocab terms for this actor
            $stmt = $pdo->prepare(
                "SELECT vocab_id, slug, label_ciphertext, label_nonce,
                        description_ciphertext, description_nonce,
                        value_type, scale_min, scale_max, is_active, created_at
                 FROM notebook_vocab
                 WHERE actor_id = ? AND is_active = 1
                 ORDER BY slug"
            );
            $stmt->execute([$my_actor_id]);
            $rows = $stmt->fetchAll();

            $vocab = [];
            foreach ($rows as $row) {
                $label = \Crypto\Notebook::decrypt(
                    $row["label_ciphertext"], $row["label_nonce"], $notebook_key
                );
                $desc = null;
                if ($row["description_ciphertext"] !== null) {
                    $desc = \Crypto\Notebook::decrypt(
                        $row["description_ciphertext"], $row["description_nonce"], $notebook_key
                    );
                    if ($desc === false) $desc = "[DECRYPTION FAILED]";
                }

                $item = [
                    "vocab_id"   => (int)$row["vocab_id"],
                    "slug"       => $row["slug"],
                    "label"      => $label !== false ? $label : "[DECRYPTION FAILED]",
                    "value_type" => $row["value_type"],
                    "is_active"  => (bool)$row["is_active"],
                    "created_at" => $row["created_at"],
                ];
                if ($desc !== null) $item["description"] = $desc;
                if ($row["value_type"] === "scale") {
                    $item["scale_min"] = (int)$row["scale_min"];
                    $item["scale_max"] = (int)$row["scale_max"];
                }
                $vocab[] = $item;
            }

            echo json_encode(["vocab" => $vocab]);
            break;

        case "POST":
            $input = json_decode(file_get_contents("php://input"), true);
            $slug = $input["slug"] ?? "";
            $label = $input["label"] ?? "";
            $description = $input["description"] ?? null;
            $value_type = $input["value_type"] ?? "text";
            $scale_min = $input["scale_min"] ?? null;
            $scale_max = $input["scale_max"] ?? null;

            if (empty($slug) || empty($label)) {
                http_response_code(400);
                echo json_encode(["error" => "slug and label are required"]);
                break;
            }

            if (!preg_match('/^[a-z0-9_]{1,100}$/', $slug)) {
                http_response_code(400);
                echo json_encode(["error" => "slug must be lowercase alphanumeric with underscores, max 100 chars"]);
                break;
            }

            $valid_types = ["text", "number", "boolean", "scale"];
            if (!in_array($value_type, $valid_types)) {
                http_response_code(400);
                echo json_encode(["error" => "value_type must be one of: " . implode(", ", $valid_types)]);
                break;
            }

            if ($value_type === "scale") {
                if ($scale_min === null || $scale_max === null) {
                    http_response_code(400);
                    echo json_encode(["error" => "scale_min and scale_max required for scale type"]);
                    break;
                }
            }

            // Encrypt label and description
            $enc_label = \Crypto\Notebook::encrypt($label, $notebook_key);
            $enc_desc_ct = null;
            $enc_desc_nonce = null;
            if ($description !== null) {
                $enc_desc = \Crypto\Notebook::encrypt($description, $notebook_key);
                $enc_desc_ct = $enc_desc["ciphertext"];
                $enc_desc_nonce = $enc_desc["nonce"];
            }

            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO notebook_vocab
                     (actor_id, slug, label_ciphertext, label_nonce, description_ciphertext, description_nonce, value_type, scale_min, scale_max)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $my_actor_id, $slug,
                    $enc_label["ciphertext"], $enc_label["nonce"],
                    $enc_desc_ct, $enc_desc_nonce,
                    $value_type, $scale_min, $scale_max,
                ]);
                $vocab_id = (int)$pdo->lastInsertId();

                http_response_code(201);
                echo json_encode([
                    "vocab_id"   => $vocab_id,
                    "slug"       => $slug,
                    "label"      => $label,
                    "value_type" => $value_type,
                ]);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) {
                    http_response_code(409);
                    echo json_encode(["error" => "Vocab slug already exists for this actor: $slug"]);
                } else {
                    throw $e;
                }
            }
            break;

        case "PATCH":
            if (!$id_param) {
                http_response_code(400);
                echo json_encode(["error" => "vocab_id required"]);
                break;
            }

            // Verify ownership
            $stmt = $pdo->prepare("SELECT vocab_id FROM notebook_vocab WHERE vocab_id = ? AND actor_id = ?");
            $stmt->execute([$id_param, $my_actor_id]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(["error" => "Vocab term not found"]);
                break;
            }

            $input = json_decode(file_get_contents("php://input"), true);
            $updates = [];
            $params = [];

            if (isset($input["label"])) {
                $enc = \Crypto\Notebook::encrypt($input["label"], $notebook_key);
                $updates[] = "label_ciphertext = ?, label_nonce = ?";
                $params[] = $enc["ciphertext"];
                $params[] = $enc["nonce"];
            }
            if (array_key_exists("description", $input)) {
                if ($input["description"] === null) {
                    $updates[] = "description_ciphertext = NULL, description_nonce = NULL";
                } else {
                    $enc = \Crypto\Notebook::encrypt($input["description"], $notebook_key);
                    $updates[] = "description_ciphertext = ?, description_nonce = ?";
                    $params[] = $enc["ciphertext"];
                    $params[] = $enc["nonce"];
                }
            }
            if (isset($input["value_type"])) {
                $updates[] = "value_type = ?";
                $params[] = $input["value_type"];
            }
            if (isset($input["scale_min"])) {
                $updates[] = "scale_min = ?";
                $params[] = $input["scale_min"];
            }
            if (isset($input["scale_max"])) {
                $updates[] = "scale_max = ?";
                $params[] = $input["scale_max"];
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(["error" => "No fields to update"]);
                break;
            }

            $params[] = $id_param;
            $params[] = $my_actor_id;
            $stmt = $pdo->prepare(
                "UPDATE notebook_vocab SET " . implode(", ", $updates) .
                " WHERE vocab_id = ? AND actor_id = ?"
            );
            $stmt->execute($params);

            echo json_encode(["status" => "updated", "vocab_id" => $id_param]);
            break;

        case "DELETE":
            if (!$id_param) {
                http_response_code(400);
                echo json_encode(["error" => "vocab_id required"]);
                break;
            }

            $stmt = $pdo->prepare(
                "UPDATE notebook_vocab SET is_active = 0 WHERE vocab_id = ? AND actor_id = ?"
            );
            $stmt->execute([$id_param, $my_actor_id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(["error" => "Vocab term not found"]);
                break;
            }

            echo json_encode(["status" => "deactivated", "vocab_id" => $id_param]);
            break;

        default:
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
    }

// ─── ENTRIES ─────────────────────────────────────────────────────────
} elseif ($sub_resource === "entries" || $sub_resource === "") {

    switch ($method) {
        case "GET":
            if ($id_param) {
                // GET /notebook/entries/{id}
                $stmt = $pdo->prepare(
                    "SELECT e.entry_id, e.vocab_id, v.slug as vocab_slug,
                            e.ciphertext, e.nonce, e.logged_at, e.created_at
                     FROM notebook_entries e
                     LEFT JOIN notebook_vocab v ON v.vocab_id = e.vocab_id
                     WHERE e.entry_id = ? AND e.actor_id = ?"
                );
                $stmt->execute([$id_param, $my_actor_id]);
                $row = $stmt->fetch();

                if (!$row) {
                    http_response_code(404);
                    echo json_encode(["error" => "Entry not found"]);
                    break;
                }

                $plaintext = \Crypto\Notebook::decrypt($row["ciphertext"], $row["nonce"], $notebook_key);

                $entry = [
                    "entry_id"   => (int)$row["entry_id"],
                    "vocab_id"   => $row["vocab_id"] ? (int)$row["vocab_id"] : null,
                    "vocab_slug" => $row["vocab_slug"],
                    "data"       => $plaintext !== false ? json_decode($plaintext, true) : "[DECRYPTION FAILED]",
                    "logged_at"  => $row["logged_at"],
                    "created_at" => $row["created_at"],
                ];

                echo json_encode($entry);
            } else {
                // GET /notebook/entries — list
                $limit = min((int)($_GET["limit"] ?? 50), 100);
                $offset = max((int)($_GET["offset"] ?? 0), 0);
                $vocab_slug = $_GET["vocab"] ?? null;
                $since = $_GET["since"] ?? null;
                $until = $_GET["until"] ?? null;

                $where = "e.actor_id = ?";
                $params = [$my_actor_id];

                if ($vocab_slug !== null) {
                    $where .= " AND v.slug = ?";
                    $params[] = $vocab_slug;
                }
                if ($since !== null) {
                    $where .= " AND e.logged_at >= ?";
                    $params[] = $since;
                }
                if ($until !== null) {
                    $where .= " AND e.logged_at <= ?";
                    $params[] = $until;
                }

                $params[] = $limit;
                $params[] = $offset;

                $stmt = $pdo->prepare(
                    "SELECT e.entry_id, e.vocab_id, v.slug as vocab_slug,
                            e.ciphertext, e.nonce, e.logged_at, e.created_at
                     FROM notebook_entries e
                     LEFT JOIN notebook_vocab v ON v.vocab_id = e.vocab_id
                     WHERE $where
                     ORDER BY e.logged_at DESC
                     LIMIT ? OFFSET ?"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                $entries = [];
                foreach ($rows as $row) {
                    $plaintext = \Crypto\Notebook::decrypt($row["ciphertext"], $row["nonce"], $notebook_key);
                    $entries[] = [
                        "entry_id"   => (int)$row["entry_id"],
                        "vocab_id"   => $row["vocab_id"] ? (int)$row["vocab_id"] : null,
                        "vocab_slug" => $row["vocab_slug"],
                        "data"       => $plaintext !== false ? json_decode($plaintext, true) : "[DECRYPTION FAILED]",
                        "logged_at"  => $row["logged_at"],
                        "created_at" => $row["created_at"],
                    ];
                }

                // Total count
                $count_params = array_slice($params, 0, -2);
                $count_stmt = $pdo->prepare(
                    "SELECT COUNT(*) as total FROM notebook_entries e
                     LEFT JOIN notebook_vocab v ON v.vocab_id = e.vocab_id
                     WHERE $where"
                );
                $count_stmt->execute($count_params);
                $total = (int)$count_stmt->fetch()["total"];

                echo json_encode([
                    "entries" => $entries,
                    "total"   => $total,
                    "limit"   => $limit,
                    "offset"  => $offset,
                ]);
            }
            break;

        case "POST":
            $input = json_decode(file_get_contents("php://input"), true);
            $data = $input["data"] ?? null;
            $vocab_slug = $input["vocab"] ?? null;
            $logged_at = $input["logged_at"] ?? null;

            if ($data === null) {
                http_response_code(400);
                echo json_encode(["error" => "data is required (object or string)"]);
                break;
            }

            // Resolve vocab slug to ID if provided
            $vocab_id = null;
            if ($vocab_slug !== null) {
                $stmt = $pdo->prepare(
                    "SELECT vocab_id FROM notebook_vocab WHERE slug = ? AND actor_id = ? AND is_active = 1"
                );
                $stmt->execute([$vocab_slug, $my_actor_id]);
                $vocab_row = $stmt->fetch();
                if (!$vocab_row) {
                    http_response_code(400);
                    echo json_encode(["error" => "Unknown vocab slug: $vocab_slug"]);
                    break;
                }
                $vocab_id = (int)$vocab_row["vocab_id"];
            }

            // Encrypt the data payload as JSON
            $payload = is_string($data) ? json_encode(["value" => $data]) : json_encode($data);
            $encrypted = \Crypto\Notebook::encrypt($payload, $notebook_key);

            $stmt = $pdo->prepare(
                "INSERT INTO notebook_entries (actor_id, vocab_id, ciphertext, nonce, logged_at)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $my_actor_id,
                $vocab_id,
                $encrypted["ciphertext"],
                $encrypted["nonce"],
                $logged_at ?? gmdate("Y-m-d H:i:s"),
            ]);
            $entry_id = (int)$pdo->lastInsertId();

            http_response_code(201);
            echo json_encode([
                "entry_id"   => $entry_id,
                "vocab_id"   => $vocab_id,
                "vocab_slug" => $vocab_slug,
                "logged_at"  => $logged_at ?? gmdate("Y-m-d H:i:s"),
            ]);
            break;

        case "DELETE":
            if (!$id_param) {
                http_response_code(400);
                echo json_encode(["error" => "entry_id required"]);
                break;
            }

            $stmt = $pdo->prepare(
                "DELETE FROM notebook_entries WHERE entry_id = ? AND actor_id = ?"
            );
            $stmt->execute([$id_param, $my_actor_id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(["error" => "Entry not found"]);
                break;
            }

            echo json_encode(["status" => "deleted", "entry_id" => $id_param]);
            break;

        default:
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
    }

} else {
    http_response_code(404);
    echo json_encode([
        "error" => "Unknown notebook sub-resource: $sub_resource",
        "available" => ["vocab", "entries"],
    ]);
}
