<?php
require __DIR__ . '/db.php';

// 确保 messages 有 likes 字段
try {
    $pdo->exec("ALTER TABLE messages ADD COLUMN likes INT UNSIGNED NOT NULL DEFAULT 0");
} catch (PDOException $e) {
    // 已存在则忽略
}

$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
$limit = max($limit, 1);

try {
    $stmt = $pdo->prepare("
        SELECT id, name, msg, likes, created_at
        FROM messages
        ORDER BY id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $list = array_map(function ($r) {
        return [
            'id'    => (int)$r['id'],
            'name'  => $r['name'],
            'msg'   => $r['msg'],
            'likes' => (int)($r['likes'] ?? 0),
            'time'  => strtotime($r['created_at']) * 1000,
        ];
    }, $rows);

    json_out(['ok' => true, 'list' => $list, 'total' => count($list)]);
} catch (PDOException $e) {
    http_response_code(500);
    json_out(['ok' => false, 'error' => '查询失败']);
}
