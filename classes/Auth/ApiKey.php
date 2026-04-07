<?php
namespace Auth;

class ApiKey
{
    private ?int $last_key_id = null;
    private ?int $last_aiu_id = null;

    public function __construct(
        private \PDO $di_pdo,
    ) {}

    public function validateKey(string $raw_key): ?int
    {
        $key_hash = hash("sha256", $raw_key);

        $stmt = $this->di_pdo->prepare(
            "SELECT key_id, user_id, aiu_id FROM api_keys WHERE api_key_hash = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$key_hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $this->last_key_id = (int) $row["key_id"];
        $this->last_aiu_id = $row["aiu_id"] ? (int) $row["aiu_id"] : null;

        $update = $this->di_pdo->prepare(
            "UPDATE api_keys SET last_used = NOW() WHERE key_id = ?"
        );
        $update->execute([$this->last_key_id]);

        return (int) $row["user_id"];
    }

    public function getLastKeyId(): ?int
    {
        return $this->last_key_id;
    }

    public function getLastAiuId(): ?int
    {
        return $this->last_aiu_id;
    }
}
