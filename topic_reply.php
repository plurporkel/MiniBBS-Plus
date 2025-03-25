<?php
/**
 * This file determines a reply's page number and/or parent topic, so we can link to a reply without said
 * information. For example, /reply/46 or /topic/1/reply/46 might redirect to /topic/1/2#reply_46  
 */
define('MINIMAL_BOOTSTRAP', true);
require './includes/bootstrap.php';

// Safely retrieve and validate input
$reply_id = filter_input(INPUT_GET, 'reply', FILTER_VALIDATE_INT);
$topic_id = filter_input(INPUT_GET, 'topic', FILTER_VALIDATE_INT);

if ($reply_id === false || $reply_id <= 0) {
    redirect('No reply specified', '');
}

// If topic ID is not provided, fetch it from the database
if ($topic_id === false || $topic_id <= 0) {
    $res = $db->q('SELECT parent_id FROM replies WHERE id = ?', $reply_id);
    $topic_id = $res->fetchColumn();
    if ($topic_id === false) {
        redirect('Reply not found', '');
    }
}

// Safely access session settings
$posts_per_page = $_SESSION['settings']['posts_per_page'] ?? 0;

if ($posts_per_page > 0) {
    // Calculate the reply's position (number of replies before this one)
    $res = $db->q('SELECT COUNT(*) FROM replies WHERE parent_id = ? AND id < ? AND deleted = 0', $topic_id, $reply_id);
    $reply_number = $res->fetchColumn() + 1;

    // Determine the total number of replies
    if ($reply_number > $posts_per_page) {
        $total_replies = $reply_number;
    } else {
        $res = $db->q('SELECT replies FROM topics WHERE id = ?', $topic_id);
        $total_replies = $res->fetchColumn();
    }
} else {
    // If posts_per_page is 0, treat as no pagination
    $total_replies = 1;
    $reply_number = 1;
}

// Construct and perform the redirect
header('Location: ' . URL . 'topic/' . (int)$topic_id . page($total_replies, $reply_number) . '#reply_' . (int)$reply_id);
exit();