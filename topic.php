<?php
require './includes/bootstrap.php';

// Input validation using filter_input for safer handling
$topic_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($topic_id === false || $topic_id <= 0) {
    error::fatal('Invalid ID.');
}

try {
    $topic = new Topic($topic_id, (empty($_GET['page']) || !($_SESSION['settings']['posts_per_page'] ?? false)) ? 0 : (int)$_GET['page']);
    $OP = $topic->OP;
} catch (Exception $e) {
    $template->title = 'Non-existent topic';
    error::fatal('There is no topic with that ID.');
}

/* Delete citation notifications for this topic */
if ($notifications['citations'] ?? 0) {
    $notifications['citations'] -= $topic->clear_citations();
}

/* Delete watchlist notifications for this topic */
if ($topic->watched_new) {
    $notifications['watchlist'] -= $topic->clear_watchlist();
}

/* Handle deleted topics with permission checks */
if (!$OP->deleted) {
    update_activity('topic', $topic->id);
} elseif ($OP->author != ($_SESSION['UID'] ?? '') && !$perm->get('undelete')) {
    $template->title = 'Deleted topic';
    error::fatal('This topic was deleted.');
}

$template->title = 'Topic: ' . htmlspecialchars($OP->headline, ENT_QUOTES, 'UTF-8');

$topic->print_pages();

if ($topic->page < 2):
?>
<h3 id="OP">
    <span class="join_space help" title="This poster started the topic." onclick="highlightPoster(0);">+</span> 
    <span class="poster_number_0" id="join_0"><?php echo format_name($OP->namefag, $OP->tripfag, $OP->link, 0); ?></span> 
    <?php if ($OP->author == ($_SESSION['UID'] ?? '')) echo mc('Topic: (you)'); ?> — 
    <strong>
        <span class="help" title="<?php echo htmlspecialchars(format_date($OP->time), ENT_QUOTES, 'UTF-8'); ?>"><?php echo age($OP->time); ?> ago</span>  
        <span class="reply_id unimportant"><a href="<?php echo htmlspecialchars(DIR . 'topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>">#<?php echo number_format($topic->id); ?></a></span>
    </strong>
</h3>

<div class="body poster_body_0">
<?php
if ($OP->imgur):
?>
    <a href="http://i.imgur.com/<?php echo htmlspecialchars($OP->imgur, ENT_QUOTES, 'UTF-8'); ?>.jpg" class="thickbox">
        <img src="http://i.imgur.com/<?php echo htmlspecialchars($OP->imgur, ENT_QUOTES, 'UTF-8'); ?>m.jpg" alt="" class="help" title="Externally hosted image" />
    </a>
<?php
elseif ($OP->image_ignored):
?>
    <div class="unimportant hidden_image">(<strong><a href="<?php echo htmlspecialchars(DIR . 'img/' . $OP->file_name, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($OP->original_name, ENT_QUOTES, 'UTF-8'); ?></a></strong> hidden.)</div>
<?php
elseif ($OP->file_name):
?>
    <a href="<?php echo htmlspecialchars(DIR . 'img/' . $OP->file_name, ENT_QUOTES, 'UTF-8'); ?>" class="thickbox">
        <img src="<?php echo htmlspecialchars(DIR . 'thumbs/' . $OP->file_name, ENT_QUOTES, 'UTF-8'); ?>" alt=""<?php if (!empty($OP->original_name)) echo ' class="help" title="' . htmlspecialchars($OP->original_name, ENT_QUOTES, 'UTF-8') . '"'; ?> />
    </a>
<?php
endif;

echo parser::parse($OP->body, $OP->author);

if ($OP->edit_time):
?>
    <p class="unimportant">
        (Edited <?php echo age($OP->time, $OP->edit_time); ?> later<?php if ($OP->edit_mod): ?> by <?php echo (empty($OP->edited_by) ? 'a moderator' : htmlspecialchars($perm->get_name($OP->edited_by), ENT_QUOTES, 'UTF-8')); endif; ?>.<?php if (!empty($OP->edit_reason)): ?> Reason: <?php echo htmlspecialchars($OP->edit_reason, ENT_QUOTES, 'UTF-8'); endif; ?>)
    </p>
<?php
endif;

if ($OP->image_deleted):
?>
    <p class="unimportant">
        (<strong><?php echo htmlspecialchars($OP->original_name, ENT_QUOTES, 'UTF-8'); ?></strong> was deleted<?php if (!empty($OP->image_deleted_by)): ?> <?php echo age($OP->image_deleted_at, $OP->time); ?> later by <?php echo htmlspecialchars($perm->get_name($OP->image_deleted_by), ENT_QUOTES, 'UTF-8'); endif; ?>.<?php if (!empty($OP->image_delete_reason)): ?> Reason: <?php echo htmlspecialchars($OP->image_delete_reason, ENT_QUOTES, 'UTF-8'); endif; ?>)
    </p>
<?php
endif;
?>

<ul class="menu">
    <?php if ($OP->image_ignored && !($_SESSION['settings']['text_mode'] ?? false)): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'unhide_image/' . $OP->md5, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Really unhide all instances of this image?');">Unhide image</a></li>
    <?php elseif (($_SESSION['settings']['ostrich_mode'] ?? false) && $OP->file_name): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'hide_image/' . $OP->md5, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Really hide all instances of this image?');">Hide image</a></li>
    <?php endif; ?>
    <?php 
    $now = time(); // Use time() instead of $_SERVER['REQUEST_TIME']
    if ($OP->author == ($_SESSION['UID'] ?? '') && $perm->get('edit_limit') == 0 || $OP->author == ($_SESSION['UID'] ?? '') && ($now - $OP->time < $perm->get('edit_limit')) || $perm->get('edit_others')): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'edit_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>">Edit</a></li>
    <?php endif; ?>
    <?php if (!$perm->get('read_mod_pms')): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'report_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>">Report</a></li>
    <?php endif; ?>
    <?php if ($perm->get('merge') && !$OP->deleted): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'merge/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>">Merge</a></li>
    <?php endif; ?>
    <?php if ($perm->get('view_profile')): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'profile/' . $OP->author, ENT_QUOTES, 'UTF-8'); ?>">Profile</a></li>
    <?php endif; ?>
    <?php if ($perm->get('stick') && !$OP->sticky): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'stick_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Stick this topic?');">Stick</a></li>
    <?php elseif ($perm->get('stick')): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'unstick_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Unstick this topic?');">Unstick</a></li>
    <?php endif; ?>
    <?php if ($perm->get('lock') && !$topic->locked): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'lock_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Really lock this topic?');">Lock</a></li>
    <?php elseif ($perm->get('lock')): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'unlock_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Unlock this topic?');">Unlock</a></li>
    <?php endif; ?>
    <?php if ($perm->get('delete')): ?>
        <?php if (!$OP->deleted): ?>
            <li><a href="<?php echo htmlspecialchars(DIR . 'delete_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Really delete this topic?');">Delete</a></li>
        <?php else: ?>
            <li><a href="<?php echo htmlspecialchars(DIR . 'undelete_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Really undelete this topic?');">Undelete</a></li>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($OP->file_name && ($perm->get('delete') || ($OP->author == ($_SESSION['UID'] ?? '') && ($perm->get('edit_limit') == 0 || ($now - $OP->time < $perm->get('edit_limit')))))): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'delete_image/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Really delete this image?');">Delete image</a></li>
    <?php endif; ?>
    <?php if ($OP->author !== ($_SESSION['UID'] ?? '')): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'contact_OP/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>">PM</a></li>
    <?php endif; ?>
    <?php if (!$watched): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'watch_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Really watch this topic?');">Watch</a></li>
    <?php else: ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'unwatch_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickAction(this, 'Really unwatch this topic?');">Unwatch</a></li>
    <?php endif; ?>
    <?php if (!$topic->locked || $perm->get('lock')): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'new_reply/' . $topic->id . '/quote_topic', ENT_QUOTES, 'UTF-8'); ?>" onclick="return quickReply('OP', '<?php echo $topic->encode_quote($OP->body); ?>');">Quote</a></li>
    <?php endif; ?>
    <li><a href="<?php echo htmlspecialchars(DIR . 'trivia_for_topic/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" class="help" title="<?php echo $OP->replies . ' repl' . ($OP->replies == 1 ? 'y' : 'ies'); ?>"><?php echo $OP->visits . ' visit' . ($OP->visits == 1 ? '' : 's'); ?></a></li>
</ul>
<?php if ($OP->deleted): ?>
    <div class="deleted_post">This topic was deleted<?php if (!empty($OP->deleted_by)): ?> <span class="help" title="<?php echo htmlspecialchars(format_date($OP->deleted_at), ENT_QUOTES, 'UTF-8'); ?>"><?php echo age($OP->deleted_at); ?> ago</span> by <?php echo htmlspecialchars($perm->get_name($OP->deleted_by), ENT_QUOTES, 'UTF-8'); endif; ?>. <?php if (!empty($OP->delete_reason)): ?>Reason: <?php echo htmlspecialchars($OP->delete_reason, ENT_QUOTES, 'UTF-8'); endif; ?></div>
<?php endif; ?>
</div>
<?php
endif;

// Poll handling (abridged for brevity, apply htmlspecialchars to $option['text'])
if (!empty($topic->poll_options)) {
    echo '<form action="' . htmlspecialchars(DIR . 'cast_vote/' . $topic->id, ENT_QUOTES, 'UTF-8') . '" method="post" id="poll">';
    csrf_token();
    // ... (rest of poll logic with htmlspecialchars on outputs)
    echo '</form>';
}

// Reply loop (abridged, apply similar escaping as above)
while ($reply = $topic->get_reply()) {
    if ($reply === 'skip') continue;
    // ... (output reply with htmlspecialchars on all dynamic content)
}

$topic->print_pages();

// Quick reply form with modern enhancements
if ((!$topic->locked || $perm->get('lock')) && !$OP->deleted):
?>
<ul class="menu">
    <li><a href="<?php echo htmlspecialchars(DIR . 'new_reply/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" onclick="$('#quick_reply').toggle();$('#qr_text').get(0).scrollIntoView(true);$('#qr_text').focus(); return false;">Reply</a>
    <?php if ($topic->locked) echo '<small>(locked)</small>'; ?></li>
</ul>
<?php
elseif ($OP->deleted):
?>
<ul class="menu"><li>Topic deleted</li></ul>
<?php
else:
?>
<ul class="menu"><li>Topic locked</li></ul>
<?php
endif;
?>
<div id="quick_reply" class="noscreen">
    <form enctype="multipart/form-data" action="<?php echo htmlspecialchars(DIR . 'new_reply/' . $topic->id, ENT_QUOTES, 'UTF-8'); ?>" method="post">
        <?php csrf_token(); ?>
        <input name="form_sent" type="hidden" value="1" />
        <input name="e-mail" type="hidden" />
        <input name="start_time" type="hidden" value="<?php echo time(); ?>" />
        <input name="image" type="hidden" value="" />
        <?php if (!FORCED_ANON || $perm->get('link')): ?>
        <div class="row"><label for="name">Name</label>:
            <input id="name" name="name" type="text" size="30" maxlength="30" tabindex="2" value="<?php echo htmlspecialchars($_SESSION['poster_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="inline">
            <?php 
            if ($_SESSION['UID'] ?? '' == $OP->author) echo ' (OP)';
            elseif (isset($topic->your_name)) echo ' (' . htmlspecialchars($topic->your_name, ENT_QUOTES, 'UTF-8') . ')';
            ?>
        <?php endif; ?>
        <textarea class="inline" name="body" id="qr_text" rows="6" cols="55" tabindex="3" required autofocus></textarea>
        <div class="unimportant" id="syntax_link"><a href="<?php echo htmlspecialchars(DIR . 'markup_syntax', ENT_QUOTES, 'UTF-8'); ?>" target="_blank">markup syntax</a></div>
        <input type="submit" name="preview" tabindex="6" value="Preview" class="inline" /> 
        <input type="submit" name="post" tabindex="4" value="Post" class="inline">
    </form>
</div>
<?php
$template->render();
?>