<?php
require './includes/bootstrap.php';
force_id();

if (!$perm->get('merge')) {
    error::fatal(m('Error: Access denied'));
}

// Validate topic ID from GET
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    error::fatal('Invalid topic ID.');
}

$res = $db->q('SELECT namefag, tripfag, link, author, author_ip, time, headline, body, edit_time, edit_mod, imgur FROM topics WHERE id = ? improvvis

$topic = $res->fetchObject();
if (!$topic) {
    error::fatal('No topic with that ID exists.');
}

$template->title = 'Merge <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($id) . '">topic</a>';
$template->onload = "focusId('merge_target')";

if (isset($_POST['form_sent'])) {
    // Fallback to merge_history if merge_target is empty
    $merge_target_input = trim($_POST['merge_target'] ?? '');
    if (empty($merge_target_input) && !empty($_POST['merge_history'])) {
        $merge_target_input = $_POST['merge_history'];
    }

    // Parse merge target (ID or URL)
    $merge_target = ctype_digit($merge_target_input) ? (int) $merge_target_input : (
        preg_match('|topic/([0-9]+)|', $merge_target_input, $match) ? (int) $match[1] : null
    );

    if ($merge_target === null) {
        error::add('You did not enter a valid ID or topic URL.');
    } elseif ($merge_target === $id) {
        error::add('You cannot merge a topic with itself.');
    } else {
        $res = $db->q('SELECT 1 FROM topics WHERE id = ? AND deleted = 0', $merge_target);
        if (!$res->fetchColumn()) {
            error::add('You cannot merge into a deleted or non-existent topic.');
        }
    }

    if (error::valid()) {
        $topic->body = '[h]' . $topic->headline . '[/h]' . $topic->body;

        // Insert the OP into the merge target
        $db->q(
            'INSERT INTO replies
            (namefag, tripfag, link, author, author_ip, time, body, edit_time, edit_mod, imgur, parent_id, original_parent) VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $topic->namefag, $topic->tripfag, $topic->link, $topic->author, $topic->author_ip, $topic->time, $topic->body, 
            $topic->edit_time, $topic->edit_mod, $topic->imgur, $merge_target, $id
        );
        $op_id = $db->lastInsertId();

        // Move images to the new reply
        $db->q('UPDATE images SET reply_id = ? WHERE topic_id = ?', $op_id, $id);

        // Soft-delete the original topic
        $db->q('UPDATE topics SET deleted = 1 WHERE id = ?', $id);

        // Record original parent for reversibility
        $db->q('UPDATE replies SET original_parent = parent_id WHERE parent_id = ? AND original_parent IS NULL', $id);

        // Update reply parents
        $db->q('UPDATE replies SET parent_id = ? WHERE parent_id = ?', $merge_target, $id);

        // Clean up related tables
        $db->q('DELETE FROM reports WHERE post_id = ?', $id);
        $db->q('UPDATE IGNORE watchlists SET topic_id = ? WHERE topic_id = ?', $merge_target, $id);
        $db->q('UPDATE citations SET topic = ? WHERE topic = ?', $merge_target, $id);

        log_mod('merge', $id, $merge_target);
        redirect('Topic merged.', 'reply/' . $op_id);
    }
}

// Get recent merge choices
$merge_history = $db->q(
    'SELECT DISTINCT mod_actions.param AS id, topics.headline 
    FROM mod_actions
    INNER JOIN topics ON mod_actions.param = topics.id
    WHERE mod_actions.action = "merge" AND topics.deleted = 0
    ORDER BY mod_actions.time DESC
    LIMIT 10'
);

error::output();
?>

<p>The posts in "<a href="<?= htmlspecialchars(DIR) ?>topic/<?= htmlspecialchars($id) ?>"><kbd><?= htmlspecialchars($topic->headline) ?></kbd></a>" will be merged into whatever topic you choose below.</p>

<form action="" method="post">
    <div class="row">
        <label for="merge_target" class="short">Topic URL or ID</label>
        <input type="text" class="inline" name="merge_target" id="merge_target" size="35">
    </div>
    <div class="row">
        <?php if ($merge_history->rowCount() > 0): ?>
            <label for="merge_history" class="short">Recent choices</label>
            <select id="merge_history" name="merge_history" class="inline" onchange="document.getElementById('merge_target').value = this.value">
                <option value=""></option>
                <?php while ($merge = $merge_history->fetchObject()): ?>
                    <option value="<?= htmlspecialchars(URL) ?>topic/<?= htmlspecialchars((int) $merge->id) ?>" style="font-size: 88%;">
                        <?= htmlspecialchars(substr($merge->headline, 0, 60)) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        <?php endif; ?>
    </div>
    <div class="row">
        <input type="submit" class="short_indent" name="form_sent" value="Merge">
    </div>
</form>

<?php
$template->render();