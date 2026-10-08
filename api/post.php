<?php
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_out(['ok' => false, 'error' => '只接受 POST']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$name = trim($input['name'] ?? '');
$msg  = trim($input['msg'] ?? '');

// 校验
if ($msg === '') {
    json_out(['ok' => false, 'error' => '留言内容不能为空']);
}
if (mb_strlen($msg) > 100) {
    json_out(['ok' => false, 'error' => '留言太长（最多 100 字）']);
}
if ($name === '') {
    $name = '匿名';
}
if (mb_strlen($name) > 32) {
    $name = mb_substr($name, 0, 32);
}

// 简单频率限制：同一 IP 10 秒内只能发一条
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM messages
        WHERE ip = ? AND created_at > (NOW() - INTERVAL 10 SECOND)
    ");
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() > 0) {
        json_out(['ok' => false, 'error' => '发得太快了，歇一会儿再发']);
    }
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO messages (name, msg, ip) VALUES (?, ?, ?)
    ");
    $stmt->execute([$name, $msg, $ip]);

    $id = (int)$pdo->lastInsertId();
    json_out([
        'ok' => true,
        'item' => [
            'id'   => $id,
            'name' => $name,
            'msg'  => $msg,
            'time' => round(microtime(true) * 1000),
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    json_out(['ok' => false, 'error' => '保存失败']);
}