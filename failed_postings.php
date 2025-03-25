<?php
declare(strict_types=1);

require './includes/bootstrap.php';
update_activity('failed_postings');
$template->title = 'Failed postings';
$items_per_page = ITEMS_PER_PAGE;

$res = $db->q('SELECT time, uid, reason, headline, body FROM failed_postings ORDER BY time DESC LIMIT ?', $items_per_page);

$columns = [
    'Error message',
    'Poster',
    'Age ▼'
];
if (!$perm->get('view_profile')) {
    unset($columns[1]);
}

$table = new Table($columns, 0);

while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
    $fail_time = $row['time'];
    $fail_uid = $row['uid'];
    $fail_reason = $row['reason'];
    $fail_headline = $row['headline'];
    $fail_body = $row['body'];
    
    if (strlen($fail_body) > 600) {
        $fail_body = substr($fail_body, 0, 600) . ' …';
    }
    
    $tooltip = '';
    if (empty($fail_headline)) {
        $tooltip = $fail_body;
    } elseif (!empty($fail_body)) {
        $tooltip = 'Headline: ' . $fail_headline . ' Body: ' . $fail_body;
    }
    
    $fail_reasons = unserialize($fail_reason);
    if (!is_array($fail_reasons)) {
        $fail_reasons = [$fail_reason]; // Fallback if unserialize fails
    }
    
    $error_message = '<ul class="error_message';
    if (!empty($tooltip)) {
        $error_message .= ' help';
    }
    $error_message .= '" title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($fail_reasons as $reason) {
        $error_message .= '<li>' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $error_message .= '</ul>';
    
    $values = [
        $error_message,
        '<a href="' . DIR . 'profile/' . htmlspecialchars($fail_uid, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($fail_uid, ENT_QUOTES, 'UTF-8') . '</a>',
        '<span class="help" title="' . format_date($fail_time) . '">' . age($fail_time) . '</span>'
    ];
    
    if (!$perm->get('view_profile')) {
        unset($values[1]);
    }
    
    $table->row($values);
}

$table->output('(No failed postings to display.)');
$template->render();
?>