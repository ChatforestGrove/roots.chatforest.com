<?php
preg_match("#^(/home/[^/]+/[^/]+)#", __DIR__, $matches);
include_once $matches[1] . "/prepend.php";

header("Content-Type: application/json");

// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-Key, Content-Type");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// Health check — no auth required
$path = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
if ($path === "api/v1/health") {
    echo json_encode(["status" => "ok", "service" => "roots.chatforest.com", "time" => gmdate("c")]);
    exit;
}

// Auth via API key
$raw_key = $_SERVER["HTTP_X_API_KEY"] ?? "";
if (empty($raw_key)) {
    http_response_code(401);
    echo json_encode(["error" => "Missing X-API-Key header"]);
    exit;
}

$pdo = \Database\Base::getPDO($config);
$auth = new \Auth\ApiKey($pdo);
$auth_user_id = $auth->validateKey($raw_key);

if ($auth_user_id === null) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid API key"]);
    exit;
}

$auth_key_id = $auth->getLastKeyId();
$auth_aiu_id = $auth->getLastAiuId();

// Look up actor details
$actor_stmt = $pdo->prepare(
    "SELECT aiu_id, name, actor_type, can_read_inbox, can_write_inbox
     FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ?"
);
$actor_stmt->execute([$auth_aiu_id, $auth_user_id]);
$auth_actor = $actor_stmt->fetch(\PDO::FETCH_ASSOC);

if (!$auth_actor) {
    http_response_code(403);
    echo json_encode(["error" => "No actor linked to this API key"]);
    exit;
}

// Route to resource handlers
$segments = explode("/", $path);
// path = api/v1/{resource}
$resource = $segments[2] ?? "";

$handler_map = [
    "inbox" => "_inbox.php",
];

if (isset($handler_map[$resource])) {
    include __DIR__ . "/" . $handler_map[$resource];
} else {
    http_response_code(404);
    echo json_encode(["error" => "Unknown resource: " . $resource, "available" => array_keys($handler_map)]);
}
