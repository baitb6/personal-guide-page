<?php
/**
 * 照片墙 API：列表 / 上传 / 点赞
 */
// 尽量提高上传限制（部分主机需在宝塔/php.ini 里改才真正生效）
@ini_set('upload_max_filesize', '8M');
@ini_set('post_max_size', '10M');

require __DIR__ . '/db.php';

// 自动建照片表
$pdo->exec("
    CREATE TABLE IF NOT EXISTS photos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        caption VARCHAR(120) DEFAULT '',
        likes INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// 给 messages 表加 likes 字段（若不存在）
try {
    $pdo->exec("ALTER TABLE messages ADD COLUMN likes INT UNSIGNED NOT NULL DEFAULT 0");
} catch (PDOException $e) {
    // 字段已存在则忽略
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 60;
    $limit = max($limit, 1);
    $photosDir = dirname(__DIR__) . '/photos';

    // 多取一些，过滤掉磁盘上已删除的文件后再截断到 limit
    $stmt = $pdo->prepare("SELECT id, filename, caption, likes, created_at FROM photos ORDER BY id DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit * 3, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $list = [];
    $orphanIds = [];
    foreach ($rows as $r) {
        $filePath = $photosDir . '/' . $r['filename'];
        // 文件不存在或空文件 → 记为脏数据，不返回给前端
        if (!is_file($filePath) || filesize($filePath) < 32) {
            $orphanIds[] = (int)$r['id'];
            continue;
        }
        $list[] = [
            'id'       => (int)$r['id'],
            'url'      => 'photos/' . $r['filename'],
            'caption'  => $r['caption'],
            'likes'    => (int)$r['likes'],
            'time'     => strtotime($r['created_at']) * 1000,
        ];
        if (count($list) >= $limit) {
            break;
        }
    }

    // 自动清理数据库里已无文件的记录，避免下次再出现空白图
    if ($orphanIds) {
        $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
        $del = $pdo->prepare("DELETE FROM photos WHERE id IN ($placeholders)");
        $del->execute($orphanIds);
    }

    json_out(['ok' => true, 'list' => $list]);
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? ($_GET['action'] ?? 'upload');

    // 点赞
    if ($action === 'like') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        $type = $input['type'] ?? 'photo'; // photo | message
        if ($id <= 0) {
            json_out(['ok' => false, 'error' => '无效 ID']);
        }
        $table = $type === 'message' ? 'messages' : 'photos';
        $stmt = $pdo->prepare("UPDATE {$table} SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([$id]);
        $stmt = $pdo->prepare("SELECT likes FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $likes = (int)$stmt->fetchColumn();
        json_out(['ok' => true, 'likes' => $likes]);
    }

    // 上传照片（已取消口令，公开可上传）
    if ($action === 'upload' || isset($_FILES['photo'])) {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => '图片超过服务器限制',
                UPLOAD_ERR_FORM_SIZE  => '图片太大',
                UPLOAD_ERR_PARTIAL    => '上传不完整',
                UPLOAD_ERR_NO_FILE    => '请选择图片',
                UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录缺失',
                UPLOAD_ERR_CANT_WRITE => '无法写入磁盘',
            ];
            $code = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
            json_out(['ok' => false, 'error' => $errMap[$code] ?? '请选择图片']);
        }

        $file = $_FILES['photo'];
        if ($file['size'] > 20 * 1024 * 1024) {
            json_out(['ok' => false, 'error' => '图片不能超过 20MB']);
        }

        // 兼容无 fileinfo / mime_content_type 的主机
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
            }
        }
        if (!$mime && function_exists('mime_content_type')) {
            $mime = @mime_content_type($file['tmp_name']);
        }
        // getimagesize 几乎所有主机都有
        if (!$mime && function_exists('getimagesize')) {
            $info = @getimagesize($file['tmp_name']);
            if ($info && !empty($info['mime'])) {
                $mime = $info['mime'];
            }
        }
        // 最后用扩展名兜底
        if (!$mime) {
            $orig = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
            $mime = $map[$orig] ?? ($file['type'] ?? '');
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowed, true)) {
            json_out(['ok' => false, 'error' => '仅支持 JPG / PNG / WebP / GIF']);
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir = dirname(__DIR__) . '/photos';
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                json_out(['ok' => false, 'error' => '无法创建 photos 目录，请检查权限']);
            }
        }
        if (!is_writable($destDir)) {
            json_out(['ok' => false, 'error' => 'photos 目录不可写，请 chmod 755 或 775']);
        }
        $dest = $destDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_out(['ok' => false, 'error' => '保存失败，请检查目录权限']);
        }

        // 可选压缩（有 GD 时）
        if (function_exists('imagecreatefromjpeg') && $ext === 'jpg') {
            $img = @imagecreatefromjpeg($dest);
            if ($img) {
                $w = imagesx($img);
                if ($w > 1920) {
                    $h = (int)(imagesy($img) * 1920 / $w);
                    $resized = imagecreatetruecolor(1920, $h);
                    imagecopyresampled($resized, $img, 0, 0, 0, 0, 1920, $h, $w, imagesy($img));
                    imagejpeg($resized, $dest, 85);
                    imagedestroy($resized);
                }
                imagedestroy($img);
            }
        }

        $caption = trim($_POST['caption'] ?? '');
        if (mb_strlen($caption) > 120) {
            $caption = mb_substr($caption, 0, 120);
        }

        $stmt = $pdo->prepare("INSERT INTO photos (filename, caption) VALUES (?, ?)");
        $stmt->execute([$filename, $caption]);
        $id = (int)$pdo->lastInsertId();

        json_out([
            'ok' => true,
            'item' => [
                'id'      => $id,
                'url'     => 'photos/' . $filename,
                'caption' => $caption,
                'likes'   => 0,
                'time'    => round(microtime(true) * 1000),
            ],
        ]);
    }

    json_out(['ok' => false, 'error' => '未知操作']);
}

http_response_code(405);
json_out(['ok' => false, 'error' => '方法不允许']);
