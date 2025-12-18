<?php
require __DIR__ . '/config.php';

$db = getDB();

// Check if image_path column exists in forum_topics
$result = $db->query("SHOW COLUMNS FROM forum_topics LIKE 'image_path'");
if ($result->num_rows === 0) {
    echo "Adding image_path column to forum_topics...\n";
    $db->query("ALTER TABLE forum_topics ADD COLUMN image_path VARCHAR(500) NULL");
    echo "Column added successfully.\n";
} else {
    echo "image_path column already exists.\n";
}

// Create forum_images directory
$dir = 'assets/forum_images/';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    echo "Created directory: $dir\n";
} else {
    echo "Directory already exists: $dir\n";
}

echo "Database check completed.\n";
?>