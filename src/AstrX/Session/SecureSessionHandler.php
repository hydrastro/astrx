<?php

/** @noinspection PhpUnused */

declare(strict_types = 1);
namespace AstrX\SecureSessionHandler;

use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;
use PDO;

/**
 * Secure Session Handler Class.
 */
class SecureSessionHandler implements SessionHandlerInterface,
                                      SessionIdInterface,
                                      SessionUpdateTimestampHandlerInterface
{
    /**
     * @var PDO $pdo PDO.
     */
    private PDO $pdo;
    /**
     * @var int $sid_bytes Session ID bytes.
     */
    private int $sid_bytes = 128;
    /**
     * @var string $current_session_id Current session ID.
     */
    private string $current_session_id;
    private bool $encrypt = true;

    public function setEncrypt(bool $encrypt)
    : void {
        $this->encrypt = $encrypt;
    }

    /**
     * @param PDO $pdo PDO.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get Configuration Methods.
     * Returns the methods that will be called by the injector.
     * @return array<int, string>
     */
    public function getConfigurationMethods()
    : array
    {
        return array("setSidBytes");
    }

    /**
     * @param int $sid_bytes
     */
    public function setSidBytes(int $sid_bytes)
    : void {
        $this->sid_bytes = $sid_bytes;
    }

    /**
     * Open.
     * Opens a new session.
     *
     * @param string $path Session path.
     * @param string $name Session name.
     *
     * @return bool
     */
    public function open(string $path, string $name)
    : bool {
        return true;
    }

    /**
     * Close.
     * Closes a session.
     * @return bool
     */
    public function close()
    : bool
    {
        return true;
    }

    /**
     * Destroy.
     * Destroys a session.
     *
     * @param string $id Session id.
     *
     * @return bool
     */
    public function destroy(string $id)
    : bool {
        $database_id = $this->getDatabaseSessionId($id);
        $stmt = $this->pdo->prepare("DELETE FROM `session` WHERE `id` = :id");
        $stmt->execute(array("id" => $database_id));

        return true;
    }

    /**
     * Get Database Session ID.
     * Returns the key associated to the session id to be stored in the
     * database.
     *
     * @param string $id
     *
     * @return string
     */
    public function getDatabaseSessionId(string $id)
    : string {
        return hash("SHA512", $id);
    }

    /**
     * Garbage Collector.
     * Cleans up the database.
     *
     * @param int $max_lifetime Max lifetime.
     *
     * @return int|false
     */
    public function gc(int $max_lifetime)
    : int|false {
        $max_timestamp = time() - $max_lifetime;
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(`id`) AS `count` FROM `session` WHERE `timestamp` < :max_timestamp"
        );
        $stmt->execute(array("max_timestamp" => $max_timestamp));
        $result = $stmt->fetch();
        $stmt = $this->pdo->prepare(
            "DELETE FROM `session` WHERE `timestamp` < :max_timestamp"
        );
        $stmt->execute(array("max_timestamp" => $max_timestamp));
        if (!is_array($result)) {
            return 0;
        }

        return $result["count"];
    }

    /**
     * Read.
     * Retrieves the session data.
     *
     * @param string $id Session id.
     *
     * @return string|false
     */
    public function read(string $id)
    : string|false {
        $database_id = $this->getDatabaseSessionId($id);
        $data = $this->readDatabaseSession($database_id);
        if (!$data) {
            return "";
        }

        return $this->decryptSessionData($id, $data["data"]);
    }

    /**
     * Read Database Session.
     * Reads the session from the database.
     *
     * @param string $id Session id.
     *
     * @return array<string, string>|false
     */
    public function readDatabaseSession(string $id)
    : array|false {
        $query = $this->pdo->prepare(
            "SELECT `data` FROM `session` WHERE `id` = :id"
        );
        $query->execute(array("id" => $id));
        $result = $query->fetch();
        if (!is_array($result)) {
            return false;
        }

        return $result;
    }

    /**
     * Decrypt Session Data.
     * Decrypts the session data.
     *
     * @param string $id
     * @param string $data
     *
     * @return string
     */
    public function decryptSessionData(string $id, string $data)
    : string {
        $cipher_algo = "AES-256-CTR";
        $hmac = mb_substr($data, 0, 32, "8bit");
        $iv = mb_substr($data, 32, 16, "8bit");
        $ciphertext = mb_substr($data, 48, null, "8bit");
        $new_hmac = hash_hmac(
            "SHA256",
            $iv . $ciphertext,
            mb_substr($id, 32, null, "8bit"),
            true
        );

        // assert(hash_equals($hmac, $new_hmac));
        if (!hash_equals($hmac, $new_hmac)) {
            // tampered session: treat as empty session data
            return "";
        }

        $decrypted = openssl_decrypt(
            $ciphertext,
            $cipher_algo,
            mb_substr($id, 0, 32, "8bit"),
            OPENSSL_RAW_DATA,
            $iv
        );
        assert(is_string($decrypted));

        return $decrypted;
    }

    /**
     * Create Sid.
     * Creates database session id.
     * @return string
     * @throws Exception
     */
    public function create_sid()
    : string
    {
        while (true) {
            try {
                $sid = bin2hex(random_bytes(max(1, $this->sid_bytes)));
            } catch (\Throwable $t) {
                // catastrophic: no randomness available
                // TODO: custom class for this.
                throw new \RuntimeException(
                    'Failed to generate session id',
                    0,
                    $t
                );
            }

            $hashed = $this->getDatabaseSessionId($sid);

            $stmt = $this->pdo->prepare(
                "SELECT `id` FROM `session` WHERE `id` = :id"
            );
            $stmt->execute(["id" => $hashed]);
            $result = $stmt->fetch();

            if ($result === false) {
                $this->current_session_id = $sid;

                return $sid;
            }
        }
    }

    /**
     * Validate ID.
     * Validates Session ID.
     *
     * @param string $id Session ID.
     *
     * @return bool
     */
    public function validateId(string $id)
    : bool {
        if (isset($this->current_session_id) &&
            $id === $this->current_session_id) {
            return true;
        }
        $hashed = $this->getDatabaseSessionId($id);
        $stmt = $this->pdo->prepare(
            "SELECT `id` FROM `session` WHERE `id` = :id"
        );
        $stmt->execute(array("id" => $hashed));
        $result = $stmt->fetch();

        return ($result !== false);
    }

    /**
     * Update Timestamp.
     * Updates a session timestamp.
     *
     * @param string $id   Session ID.
     * @param string $data Session data.
     *
     * @return bool
     * @throws Exception
     */
    public function updateTimestamp(string $id, string $data)
    : bool {
        return $this->write($id, $data);
    }

    /**
     * Write.
     * Writes session data into the database.
     *
     * @param string $id   Session id.
     * @param string $data Session data.
     *
     * @return bool
     * @throws Exception
     */
    public function write(string $id, string $data)
    : bool
    {
        $database_id = $this->getDatabaseSessionId($id);
        $payload = $this->encrypt ? $this->encryptSessionData($id, $data) :
            $data;

        // Checking if session exists.
        if ($this->readDatabaseSession($database_id)) {
            $query = "UPDATE `session` SET `data` = :data, `timestamp` = :timestamp
    WHERE `id` = :id";
        } else {
            $query
                = "INSERT INTO `session` (id, timestamp, data) VALUES (:id, :timestamp, :data)";
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute(array(
                           "id" => $database_id,
                           "timestamp" => time(),
                           "data" => $payload
                       ));

        return true;
    }

    /**
     * Encrypt Session Data.
     * Encrypts the session data.
     *
     * @param string $id
     * @param string $data
     *
     * @return string
     * @throws Exception
     */
    public function encryptSessionData(string $id, string $data)
    : string {
        $cipher_algo = "AES-256-CTR";
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt(
            $data,
            $cipher_algo,
            mb_substr($id, 0, 32, "8bit"),
            OPENSSL_RAW_DATA,
            $iv
        );
        $hmac = hash_hmac(
            "SHA256",
            $iv . $ciphertext,
            mb_substr($id, 32, null, "8bit"),
            true
        );

        return $hmac . $iv . $ciphertext;
    }
}
