<?php
namespace Database;

const CONFIG_DATABASE_OUTPUT_ENCODING = "utf8mb4";

class EDatabaseException extends \RuntimeException {}
class ECouldNotConnectToServer extends EDatabaseException {}
class EDatabaseMissing extends EDatabaseException {}

class Base {
    private static $pdo;

    private static function initDB(\Config $config) {
        if (empty(self::$pdo)) {
            $dsn = "mysql:host={$config->dbHost}";
            if (!empty($config->dbName)) {
                $dsn .= ";dbname={$config->dbName}";
            }
            $dsn .= ";charset=" . CONFIG_DATABASE_OUTPUT_ENCODING;

            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                self::$pdo = new \PDO($dsn, $config->dbUser, $config->dbPass, $options);
                $stmt = self::$pdo->prepare("SET time_zone = ?");
                $stmt->execute(["+00:00"]);
            } catch (\PDOException $e) {
                sleep(1);
                try {
                    self::$pdo = new \PDO($dsn, $config->dbUser, $config->dbPass, $options);
                    $stmt = self::$pdo->prepare("SET time_zone = ?");
                    $stmt->execute(["+00:00"]);
                } catch (\PDOException $e2) {
                    throw new EDatabaseException("Could not connect: " . $e2->getMessage());
                }
            }
        }
    }

    public static function getPDO(\Config $config): \PDO {
        self::initDB($config);
        return self::$pdo;
    }
}
