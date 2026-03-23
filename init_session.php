<?php
require_once 'config.php';

// Konfigurasi Durasi Login (detik) 30 Hari
$lifetime = 2592000;

class DBSessionHandler implements SessionHandlerInterface {
    private $mysqli;

    public function __construct($host, $user, $pass, $name) {
        $this->mysqli = new mysqli($host, $user, $pass, $name);
        if ($this->mysqli->connect_errno) {
            throw new RuntimeException('Gagal konek database untuk session: ' . $this->mysqli->connect_error);
        }
        $this->mysqli->set_charset('utf8mb4');
    }

    public function open($savePath, $sessionName) {
        return true;
    }

    public function close() {
        return $this->mysqli->close();
    }

    public function read($id) {
        $stmt = $this->mysqli->prepare('SELECT session_data FROM sessions WHERE session_id = ? AND session_expires > ?');
        $now = time();
        $stmt->bind_param('si', $id, $now);
        $stmt->execute();
        $stmt->bind_result($data);
        $stmt->fetch();
        $stmt->close();
        return $data ?: '';
    }

    public function write($id, $data) {
        $expires = time() + $GLOBALS['lifetime'];
        $stmt = $this->mysqli->prepare('REPLACE INTO sessions (session_id, session_expires, session_data) VALUES (?, ?, ?)');
        $stmt->bind_param('sis', $id, $expires, $data);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public function destroy($id) {
        $stmt = $this->mysqli->prepare('DELETE FROM sessions WHERE session_id = ?');
        $stmt->bind_param('s', $id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public function gc($maxLifetime) {
        $stmt = $this->mysqli->prepare('DELETE FROM sessions WHERE session_expires < ?');
        $time = time();
        $stmt->bind_param('i', $time);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}

$handler = new DBSessionHandler(DB_HOST, DB_USER, DB_PASS, DB_NAME);
session_set_save_handler($handler, true);

ini_set('session.gc_maxlifetime', $lifetime);

session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>