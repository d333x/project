<?php
   session_start();
   require __DIR__ . '/../config.php';

   header('Content-Type: application/json');

   if (!isLoggedIn()) {
       die(json_encode(['status' => 'error', 'message' => 'Not authenticated']));
   }

   $input = json_decode(file_get_contents('php://input'), true);
   if (!$input) {
       die(json_encode(['status' => 'error', 'message' => 'Invalid data format']));
   }

   $receiver_id = (int)($input['receiver_id'] ?? 0);
   $message = trim($input['message'] ?? '');
   $sender_id = $_SESSION['user_id'];

   if ($receiver_id <= 0) {
       die(json_encode(['status' => 'error', 'message' => 'Invalid recipient ID']));
   }
   if (empty($message)) {
       die(json_encode(['status' => 'error', 'message' => 'Message cannot be empty']));
   }
   if (strlen($message) > 1000) {
       die(json_encode(['status' => 'error', 'message' => 'Message is too long (max 1000 characters)']));
   }

   try {
       $db = getDB();
       
       // Ensure proper charset for this connection
       $db->set_charset("utf8mb4");
       $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
       
       // Try to alter message column to utf8mb4 if it's not already
       // This is safe to run multiple times - it will only alter if needed
       try {
           $db->query("ALTER TABLE messages MODIFY COLUMN message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
       } catch (Exception $e) {
           // Column might already be utf8mb4 or table structure might be different
           // Continue anyway
       }
       
       $stmt_check = $db->prepare("SELECT id FROM users WHERE id = ? AND banned = 0 LIMIT 1");
       $stmt_check->bind_param("i", $receiver_id);
       $stmt_check->execute();
       
       if (!$stmt_check->get_result()->num_rows) {
           die(json_encode(['status' => 'error', 'message' => 'Recipient not found or banned']));
       }
       
       // Simply insert message - charset is handled by connection settings
       // The connection is already set to utf8mb4, so data will be stored correctly
       $stmt_insert = $db->prepare("INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())");
       $stmt_insert->bind_param("iis", $sender_id, $receiver_id, $message);
       
       if (!$stmt_insert->execute()) {
           throw new Exception("Failed to save message.");
       }
       
       $message_id = $db->insert_id;
       logChatMessage($sender_id, $receiver_id, $message);
       logAction($sender_id, 'send_message', "Sent message to ID: $receiver_id");
       
       echo json_encode(['status' => 'success', 'message_id' => $message_id]);
   } catch (Exception $e) {
       error_log("Error in send_message.php: " . $e->getMessage());
       echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
   }
   ?>