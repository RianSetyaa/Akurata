<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$pdo = db();

if (!table_column_exists($pdo, 'support_conversations', 'closed_at')
    || !table_column_exists($pdo, 'support_messages', 'id')) {
    json_response([
        'error' => 'Migration sesi chat CS belum dijalankan. Jalankan database/migrations/2026_06_11_support_chat_sessions.sql di database aaPanel.',
    ], 409);
}

$isAdministrator = user_role($user) === 'administrator' && empty($user['impersonating']);
$userId = (int) $user['id'];
$outletId = (int) $user['outlet_id'];

function support_message_rows(PDO $pdo, int $conversationId, int $sinceId = 0): array
{
    $stmt = $pdo->prepare("
        SELECT sm.id, sm.conversation_id, sm.outlet_id, sm.sender_user_id,
               sm.sender_type, sm.message, sm.created_at,
               COALESCE(u.name, IF(sm.sender_type = 'administrator', 'Administrator', 'User')) AS sender_name
        FROM support_messages sm
        LEFT JOIN users u ON u.id = sm.sender_user_id
        WHERE sm.conversation_id = :conversation_id
          AND sm.id > :since_id
        ORDER BY sm.id ASC
        LIMIT 200
    ");
    $stmt->execute([
        ':conversation_id' => $conversationId,
        ':since_id' => $sinceId,
    ]);

    return array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'conversation_id' => (int) $row['conversation_id'],
        'outlet_id' => (int) $row['outlet_id'],
        'sender_user_id' => $row['sender_user_id'] !== null ? (int) $row['sender_user_id'] : null,
        'sender_type' => $row['sender_type'],
        'sender_name' => $row['sender_name'],
        'message' => $row['message'],
        'created_at' => $row['created_at'],
    ], $stmt->fetchAll());
}

function support_conversation_rows(PDO $pdo, ?int $outletId = null): array
{
    $where = $outletId !== null ? 'WHERE sc.outlet_id = :outlet_id' : '';
    $stmt = $pdo->prepare("
        SELECT sc.id, sc.outlet_id, sc.status, sc.last_message_at, sc.closed_at, sc.created_at,
               o.name AS outlet_name,
               COALESCE((
                 SELECT sm.message
                 FROM support_messages sm
                 WHERE sm.conversation_id = sc.id
                 ORDER BY sm.id DESC
                 LIMIT 1
               ), '') AS last_message,
               (
                 SELECT COUNT(*)
                 FROM support_messages sm
                 WHERE sm.conversation_id = sc.id
                   AND sm.sender_type = 'outlet'
                   AND sm.id > sc.admin_last_read_message_id
               ) AS admin_unread_count,
               (
                 SELECT COUNT(*)
                 FROM support_messages sm
                 WHERE sm.conversation_id = sc.id
                   AND sm.sender_type = 'administrator'
                   AND sm.id > sc.outlet_last_read_message_id
               ) AS outlet_unread_count
        FROM support_conversations sc
        JOIN outlets o ON o.id = sc.outlet_id
        {$where}
        ORDER BY (sc.status = 'open') DESC, COALESCE(sc.last_message_at, sc.created_at) DESC, sc.id DESC
        LIMIT 100
    ");
    $stmt->execute($outletId !== null ? [':outlet_id' => $outletId] : []);

    return array_map(fn ($row) => [
        'id' => (int) $row['id'],
        'outlet_id' => (int) $row['outlet_id'],
        'outlet_name' => $row['outlet_name'],
        'status' => $row['status'],
        'last_message' => $row['last_message'],
        'last_message_at' => $row['last_message_at'],
        'closed_at' => $row['closed_at'],
        'admin_unread_count' => (int) $row['admin_unread_count'],
        'outlet_unread_count' => (int) $row['outlet_unread_count'],
        'unread_count' => (int) $row['admin_unread_count'],
        'created_at' => $row['created_at'],
    ], $stmt->fetchAll());
}

function support_find_conversation(PDO $pdo, int $conversationId, ?int $outletId = null): ?array
{
    $outletFilter = $outletId !== null ? 'AND sc.outlet_id = :outlet_id' : '';
    $stmt = $pdo->prepare("
        SELECT sc.id, sc.outlet_id, sc.status, sc.last_message_at, sc.closed_at,
               sc.created_at, o.name AS outlet_name
        FROM support_conversations sc
        JOIN outlets o ON o.id = sc.outlet_id
        WHERE sc.id = :id
          {$outletFilter}
        LIMIT 1
    ");
    $params = [':id' => $conversationId];
    if ($outletId !== null) {
        $params[':outlet_id'] = $outletId;
    }
    $stmt->execute($params);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'outlet_id' => (int) $row['outlet_id'],
        'outlet_name' => $row['outlet_name'],
        'status' => $row['status'],
        'last_message_at' => $row['last_message_at'],
        'closed_at' => $row['closed_at'],
        'created_at' => $row['created_at'],
    ];
}

function support_find_open_conversation(PDO $pdo, int $outletId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM support_conversations
        WHERE outlet_id = :outlet_id
          AND status = 'open'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $id = $stmt->fetchColumn();

    return $id ? support_find_conversation($pdo, (int) $id, $outletId) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = max(0, (int) ($_GET['since_id'] ?? 0));
    $waitSeconds = min(15, max(0, (int) ($_GET['wait'] ?? 0)));
    $requestedConversationId = max(0, (int) ($_GET['conversation_id'] ?? 0));
    $conversation = null;

    if ($requestedConversationId > 0) {
        $conversation = support_find_conversation(
            $pdo,
            $requestedConversationId,
            $isAdministrator ? null : $outletId
        );
        if (!$conversation) {
            json_response(['error' => 'Percakapan tidak ditemukan.'], 404);
        }
    } elseif (!$isAdministrator) {
        $conversation = support_find_open_conversation($pdo, $outletId);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $messages = [];
    if ($conversation) {
        $effectiveWait = $conversation['status'] === 'open' ? $waitSeconds : 0;
        $deadline = microtime(true) + $effectiveWait;
        do {
            $messages = support_message_rows($pdo, (int) $conversation['id'], $sinceId);
            if ($messages || $effectiveWait === 0) {
                break;
            }
            usleep(500000);
        } while (microtime(true) < $deadline);

        $maxMessageId = $messages ? max(array_column($messages, 'id')) : $sinceId;
        $markRead = (string) ($_GET['mark_read'] ?? '1') !== '0';
        if ($maxMessageId > 0 && $markRead) {
            $column = $isAdministrator ? 'admin_last_read_message_id' : 'outlet_last_read_message_id';
            $readStmt = $pdo->prepare("
                UPDATE support_conversations
                SET {$column} = GREATEST({$column}, :message_id)
                WHERE id = :id
            ");
            $readStmt->execute([
                ':message_id' => $maxMessageId,
                ':id' => (int) $conversation['id'],
            ]);
        }
    } elseif ($waitSeconds > 0) {
        usleep(min($waitSeconds, 3) * 1000000);
    }

    $conversations = support_conversation_rows($pdo, $isAdministrator ? null : $outletId);
    if (!$isAdministrator && $conversation) {
        foreach ($conversations as $row) {
            if ((int) $row['id'] === (int) $conversation['id']) {
                $conversation['unread_count'] = (int) $row['outlet_unread_count'];
                break;
            }
        }
    }

    json_response([
        'role' => $isAdministrator ? 'administrator' : 'outlet',
        'conversation' => $conversation,
        'conversations' => $conversations,
        'messages' => $messages,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json();
    $message = trim((string) ($data['message'] ?? ''));
    $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

    if ($message === '' || $messageLength > 2000) {
        json_response(['error' => 'Pesan wajib diisi dan maksimal 2000 karakter.'], 422);
    }

    if ($isAdministrator) {
        $conversationId = (int) ($data['conversation_id'] ?? 0);
        $conversation = support_find_conversation($pdo, $conversationId);
        if (!$conversation) {
            json_response(['error' => 'Percakapan tidak ditemukan.'], 404);
        }
        if ($conversation['status'] !== 'open') {
            json_response(['error' => 'Sesi ini sudah berakhir dan hanya dapat dilihat sebagai riwayat.'], 409);
        }
        $targetOutletId = (int) $conversation['outlet_id'];
        $senderType = 'administrator';
        $pdo->beginTransaction();
    } else {
        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare("SELECT id FROM outlets WHERE id = :id FOR UPDATE");
        $lockStmt->execute([':id' => $outletId]);

        $conversation = support_find_open_conversation($pdo, $outletId);
        if (!$conversation) {
            $stmt = $pdo->prepare("
                INSERT INTO support_conversations (outlet_id, status, last_message_at)
                VALUES (:outlet_id, 'open', NOW(3))
            ");
            $stmt->execute([':outlet_id' => $outletId]);
            $conversation = support_find_conversation($pdo, (int) $pdo->lastInsertId(), $outletId);
        }
        $conversationId = (int) $conversation['id'];
        $targetOutletId = $outletId;
        $senderType = 'outlet';
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO support_messages (
              conversation_id, outlet_id, sender_user_id, sender_type, message
            )
            VALUES (
              :conversation_id, :outlet_id, :sender_user_id, :sender_type, :message
            )
        ");
        $stmt->execute([
            ':conversation_id' => $conversationId,
            ':outlet_id' => $targetOutletId,
            ':sender_user_id' => $userId,
            ':sender_type' => $senderType,
            ':message' => $message,
        ]);
        $messageId = (int) $pdo->lastInsertId();

        $readColumn = $isAdministrator ? 'admin_last_read_message_id' : 'outlet_last_read_message_id';
        $stmt = $pdo->prepare("
            UPDATE support_conversations
            SET last_message_at = NOW(3),
                {$readColumn} = :message_id
            WHERE id = :id
              AND status = 'open'
        ");
        $stmt->execute([
            ':message_id' => $messageId,
            ':id' => $conversationId,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    json_response([
        'message' => 'Pesan terkirim.',
        'conversation_id' => $conversationId,
        'message_id' => $messageId,
    ], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = read_json();
    $conversationId = (int) ($data['conversation_id'] ?? 0);
    $conversation = support_find_conversation(
        $pdo,
        $conversationId,
        $isAdministrator ? null : $outletId
    );

    if (!$conversation) {
        json_response(['error' => 'Percakapan tidak ditemukan.'], 404);
    }
    if ($conversation['status'] === 'closed') {
        json_response(['message' => 'Sesi chat sudah tersimpan sebagai riwayat.']);
    }

    $stmt = $pdo->prepare("
        UPDATE support_conversations
        SET status = 'closed',
            closed_at = NOW(3)
        WHERE id = :id
          AND status = 'open'
    ");
    $stmt->execute([':id' => $conversationId]);

    json_response([
        'message' => 'Sesi chat diakhiri dan disimpan sebagai riwayat.',
        'conversation_id' => $conversationId,
    ]);
}

json_response(['error' => 'Method tidak didukung.'], 405);
