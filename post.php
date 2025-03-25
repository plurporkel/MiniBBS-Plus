<?php
declare(strict_types=1);

require './includes/bootstrap.php';
force_id();

$now = time(); // Consistent timestamp for all time checks

/* Check DEFCON */
if (!$perm->is_admin() && !$perm->is_mod()) {
    if (!defined('DEFCON') || DEFCON < 3) {
        error::fatal(m('DEFCON 2'));
    }
    if (DEFCON < 4 && ($_SESSION['post_count'] ?? 0) < POSTS_TO_DEFY_DEFCON_3) {
        error::fatal(m('DEFCON 3'));
    }
}

$topic_id = filter_input(INPUT_GET, 'reply', FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
$edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);

if ($topic_id) {
    /* This is a reply */
    if (!$perm->get('post_reply')) {
        error::fatal('You do not have permission to reply.');
    }

    $res = $db->q('SELECT headline, author, replies, deleted, locked, last_post FROM topics WHERE id = ?', $topic_id);
    $topic = $res->fetchObject();

    if (!$topic) {
        $template->title = 'Non-existent topic';
        error::fatal('There is no such topic. It may have been deleted.');
    }

    if ($topic->deleted) {
        error::fatal('You cannot respond to a deleted topic.');
    }

    if (AUTOLOCK && ($now - $topic->last_post) > AUTOLOCK && $topic->author !== ($_SESSION['UID'] ?? '')) {
        $topic->locked = true;
    }

    update_activity('replying', $topic_id);
    $reply = true;
    $template->onload = "focusId('body');";
    $template->title = 'New reply in topic: <a href="' . DIR . 'topic/' . $topic_id . '">' . htmlspecialchars($topic->headline) . '</a>';

    $check_watchlist = $db->q('SELECT 1 FROM watchlists WHERE uid = ? AND topic_id = ?', $_SESSION['UID'] ?? '', $topic_id);
    $watching_topic = (bool) $check_watchlist->fetchColumn();
} else {
    /* This is a topic */
    if (!$perm->get('post_topic')) {
        error::fatal('You do not have permission to create a topic.');
    }

    update_activity('new_topic');
    $reply = false;
    $template->onload = "focusId('headline')";
    $template->head = '<script type="text/javascript" src="' . DIR . 'javascript/polls.js"></script>';
    $template->title = 'New topic';

    if (!empty($_POST['headline'])) {
        $template->title .= ': ' . htmlspecialchars($_POST['headline'] ?? '');
    }
}

if ($edit_id) {
    /* Editing a post */
    $editing = true;

    if (!$perm->get('edit')) {
        error::fatal('You do not have permission to edit posts.');
    }

    if ($reply) {
        $fetch_edit = $db->q('SELECT author, time, body, edit_mod AS `mod` FROM replies WHERE id = ?', $edit_id);
        $template->title = 'Editing <a href="' . DIR . 'topic/' . $topic_id . '#reply_' . $edit_id . '">reply</a> to topic: <a href="' . DIR . 'topic/' . $topic_id . '">' . htmlspecialchars($topic->headline) . '</a>';
    } else {
        $fetch_edit = $db->q('SELECT author, time, body, edit_mod AS `mod`, headline FROM topics WHERE id = ?', $edit_id);
        $template->title = 'Editing topic';
    }

    $edit_data = $fetch_edit->fetchObject();

    if (!$edit_data) {
        error::fatal('There is no such post. It may have been deleted.');
    }

    $uid = $_SESSION['UID'] ?? '';
    if ($edit_data->author === $uid) {
        $edit_mod = 0;
        if ($perm->get('edit_limit') != 0 && ($now - $edit_data->time > $perm->get('edit_limit'))) {
            error::fatal('You can no longer edit your post.');
        }
        if ($edit_data->mod) {
            error::fatal('You cannot edit a post that has been edited by a moderator.');
        }
    } elseif ($perm->get('edit_others')) {
        $edit_mod = 1;
    } else {
        error::fatal('You are not allowed to edit that post.');
    }

    if (!isset($_POST['form_sent'])) {
        $body = $edit_data->body;
        if (!$reply) {
            $headline = $edit_data->headline;
            $template->title .= ': <a href="' . DIR . 'topic/' . $edit_id . '">' . htmlspecialchars($edit_data->headline) . '</a>';
        }
    } elseif (!empty($_POST['headline'])) {
        $template->title .= ': <a href="' . DIR . 'topic/' . $edit_id . '">' . htmlspecialchars($_POST['headline'] ?? '') . '</a>';
    }
}

if (isset($_POST['form_sent'])) {
    $headline = super_trim($_POST['headline'] ?? '');
    $body = super_trim($_POST['body'] ?? '');
    $name = (!defined('FORCED_ANON') || !FORCED_ANON || $perm->get('link')) ? super_trim($_POST['name'] ?? '') : '';
    $trip = '';
    if (!empty($name)) {
        list($name, $trip) = tripcode($name);
    }

    /* Parse mass quote tags */
    $body = preg_replace_callback(
        '/\[quote\](.+?)\[\/quote\]/s',
        fn($matches) => preg_replace('/.*[^\s]$/m', '> $0', $matches[1]),
        $body
    );

    $user_link = $perm->get('link');
    if (isset($_POST['post_as_group'])) {
        $_SESSION['show_group'] = true;
    } else {
        unset($_SESSION['show_group']);
        $user_link = '';
    }

    if (isset($_POST['post'])) {
        if (!$editing && ($now - ($_POST['start_time'] ?? 0)) < 3) {
            error::add('Wait a few seconds between starting to compose a post and actually submitting it.');
        }

        if (!empty($_POST['e-mail'])) {
            error::add('Bot detected.');
        }

        if (strpos($body, 'http') !== false) {
            if (!$perm->get('post_link')) {
                error::add('You do not have permission to post links.');
            } elseif (!($_SESSION['post_count'] ?? 0) && RECAPTCHA_ENABLE) {
                show_captcha('Your first post includes a link. To prove that you\'re not a spambot, complete the following CAPTCHA.');
            }
        }

        check_token();

        $min_body = ALLOW_IMAGES || !empty($_POST['imgur']) || $editing ? 0 : MIN_LENGTH_BODY;
        check_length($body, 'body', $min_body, MAX_LENGTH_BODY);
        check_length($name, 'name', 0, 30);

        if (!$reply) {
            check_length($headline, 'headline', MIN_LENGTH_HEADLINE, MAX_LENGTH_HEADLINE);
        }

        if (count(explode("\n", $body)) > MAX_LINES) {
            error::add('Your post has too many lines.');
        }

        if (ALLOW_IMAGES && $perm->get('post_image') && !empty($_FILES['image']['name'])) {
            try {
                $image = new Upload($_FILES['image']);
            } catch (Exception $e) {
                error::add($e->getMessage());
            }
        }

        $imgur = '';
        if (!isset($image) && !empty($_POST['imgur'])) {
            $imgur_input = trim($_POST['imgur'] ?? '');
            if (!preg_match('/imgur\.com\/([a-zA-Z0-9]{3,10})/', $imgur_input, $matches)) {
                error::add('That does not appear to be a valid imgur URL.');
            } else {
                $imgur = $matches[1];
            }
        }

        if ($editing && error::valid()) {
            if ($reply) {
                $db->q(
                    'UPDATE replies SET body = ?, edit_mod = ?, edit_time = ? WHERE id = ?',
                    $body,
                    $edit_mod,
                    $now,
                    $edit_id
                );
                $congratulation = m('Notice: Reply edited');
            } else {
                $db->q(
                    'UPDATE topics SET headline = ?, body = ?, edit_mod = ?, edit_time = ? WHERE id = ?',
                    $headline,
                    $body,
                    $edit_mod,
                    $now,
                    $edit_id
                );
                $congratulation = m('Notice: Topic edited');
            }

            if ($edit_mod) {
                $type = $reply ? 'reply' : 'topic';
                $db->q(
                    'INSERT INTO revisions (type, foreign_key, text) VALUES (?, ?, ?)',
                    $type,
                    $edit_id,
                    $edit_data->body
                );
                log_mod('edit_' . $type, $edit_id, $db->lastInsertId());
            }
        } elseif ($reply) {
            if ($topic->locked && !$perm->get('lock')) {
                error::add('You cannot reply to a locked thread.');
            }

            if ($now - ($_SESSION['first_seen'] ?? 0) < REQUIRED_LURK_TIME_REPLY) {
                error::add('Lurk for at least ' . REQUIRED_LURK_TIME_REPLY . ' seconds before posting your first reply.');
            }

            $too_early = $now - FLOOD_CONTROL_REPLY;
            $res = $db->q('SELECT 1 FROM replies WHERE author_ip = ? AND time > ?', $_SERVER['REMOTE_ADDR'], $too_early);
            if ($res->fetchColumn()) {
                error::add('Wait at least ' . FLOOD_CONTROL_REPLY . ' seconds between each reply.');
            }

            if (error::valid()) {
                $db->q(
                    'INSERT INTO replies (author, author_ip, parent_id, body, namefag, tripfag, link, time, imgur) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $_SESSION['UID'] ?? '',
                    $_SERVER['REMOTE_ADDR'],
                    $topic_id,
                    $body,
                    $name,
                    $trip,
                    $user_link,
                    $now,
                    $imgur
                );
                $inserted_id = $db->lastInsertId();

                /* Notify cited posters */
                preg_match_all('/@([0-9,]+)/m', $body, $matches);
                $citations = [];
                foreach ($matches[1] as $match) {
                    $citations = array_merge($citations, explode(',', $match));
                }
                $citations = array_unique($citations);
                $citations = array_slice($citations, 0, 9);

                foreach ($citations as $citation) {
                    $db->q(
                        'INSERT INTO citations (reply, topic, uid) SELECT ?, ?, `author` FROM replies WHERE replies.id = ? AND replies.parent_id = ?',
                        $inserted_id,
                        $topic_id,
                        (int)$citation,
                        $topic_id
                    );
                }
                if (strpos($body, '@OP') !== false) {
                    $db->q('INSERT INTO citations (reply, topic, uid) VALUES (?, ?, ?)', $inserted_id, $topic_id, $topic->author);
                }

                $db->q('UPDATE watchlists SET new_replies = 1 WHERE topic_id = ? AND uid != ?', $topic_id, $_SESSION['UID'] ?? '');
                $congratulation = m('Notice: Reply posted');
            }
        } else {
            if ($now - ($_SESSION['first_seen'] ?? 0) < REQUIRED_LURK_TIME_TOPIC) {
                error::add('Lurk for at least ' . REQUIRED_LURK_TIME_TOPIC . ' seconds before posting your first topic.');
            }

            $too_early = $now - FLOOD_CONTROL_TOPIC;
            $res = $db->q('SELECT 1 FROM topics WHERE author_ip = ? AND time > ?', $_SERVER['REMOTE_ADDR'], $too_early);
            if ($res->fetchColumn()) {
                error::add('Wait at least ' . FLOOD_CONTROL_TOPIC . ' seconds before creating another topic.');
            }

            $poll = 0;
            if (isset($_POST['enable_poll']) && isset($_POST['option'][0])) {
                $_POST['option'] = array_slice($_POST['option'], 0, 10);
                $_POST['option'] = array_filter($_POST['option'], fn($opt) => trim($opt) !== '');
                if (count($_POST['option']) > 1) {
                    $poll = 1;
                } else {
                    $_POST['option'] = [];
                }
            }

            $hide_results = isset($_POST['hide_results']) ? 1 : 0;
            $sticky = isset($_POST['sticky']) && $perm->get('stick') ? 1 : 0;
            $locked = isset($_POST['locked']) && $perm->get('lock') ? 1 : 0;

            if (error::valid()) {
                $db->q(
                    'INSERT INTO topics (author, author_ip, headline, body, last_post, time, namefag, tripfag, link, sticky, locked, poll, poll_hide, imgur) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $_SESSION['UID'] ?? '',
                    $_SERVER['REMOTE_ADDR'],
                    $headline,
                    $body,
                    $now,
                    $now,
                    $name,
                    $trip,
                    $user_link,
                    $sticky,
                    $locked,
                    $poll,
                    $hide_results,
                    $imgur
                );
                $inserted_id = $db->lastInsertId();

                if ($poll) {
                    foreach ($_POST['option'] as $option) {
                        $db->q('INSERT INTO poll_options (`parent_id`, `option`) VALUES (?, ?)', $inserted_id, $option);
                    }
                }

                $congratulation = m('Notice: Topic created');
            }
        }

        if (error::valid()) {
            if (!$editing) {
                $raw_name = super_trim($_POST['name'] ?? '');
                $db->q(
                    'UPDATE users SET post_count = post_count + 1, namefag = ?, updated_at = ? WHERE uid = ?', 
                    $raw_name, time(), $_SESSION['UID'] ?? ''
                );
                $_SESSION['post_count'] = ($_SESSION['post_count'] ?? 0) + 1;
                $_SESSION['poster_name'] = $raw_name;

                setcookie('last_bump', (string)$now, $now + 315569260, '/');

                if ($reply) {
                    $db->q("UPDATE last_actions SET time = ? WHERE feature = 'last_bump'", $now);
                    $db->q('UPDATE topics SET replies = replies + 1, last_post = ? WHERE id = ?', $now, $topic_id);
                    $target_topic = $topic_id;
                    $redir_loc = $topic_id . ($_SESSION['settings']['posts_per_page'] ? '/reply/' : '#reply_') . $inserted_id;
                } else {
                    setcookie('last_topic', (string)$now, $now + 315569260, '/');
                    $db->q("UPDATE last_actions SET time = ? WHERE feature = 'last_topic' OR feature = 'last_bump'", $now);
                    $target_topic = $inserted_id;
                    $redir_loc = $inserted_id;
                }
            } else {
                $target_topic = $reply ? $topic_id : $edit_id;
                $redir_loc = $reply ? $topic_id . ($_SESSION['settings']['posts_per_page'] ? '/reply/' : '#reply_') . $edit_id : $edit_id;
            }

            if (isset($image) && $image->success) {
                $post_type = $reply ? 'reply' : 'topic';
                if ($editing) {
                    delete_image($post_type, $edit_id, true);
                    $image_target = $edit_id;
                } else {
                    $image_target = $inserted_id;
                }
                $image->move($post_type, $image_target);
            }

            if (isset($_POST['watch_topic']) && !$watching_topic) {
                $db->q('INSERT INTO watchlists (uid, topic_id) VALUES (?, ?)', $_SESSION['UID'] ?? '', $target_topic);
            }

            redirect($congratulation, 'topic/' . $redir_loc);
        } else {
            if ($reply) {
                $db->q('INSERT INTO failed_postings (time, uid, reason, body) VALUES (?, ?, ?, ?)', $now, $_SESSION['UID'] ?? '', serialize(error::$errors), substr($body, 0, MAX_LENGTH_BODY));
            } else {
                $db->q('INSERT INTO failed_postings (time, uid, reason, body, headline) VALUES (?, ?, ?, ?, ?)', $now, $_SESSION['UID'] ?? '', serialize(error::$errors), substr($body, 0, MAX_LENGTH_BODY), substr($headline, 0, MAX_LENGTH_HEADLINE));
            }
        }
    }
}

error::output();

$start_time = ctype_digit($_POST['start_time'] ?? '') ? (int)$_POST['start_time'] : $now;
$set_name = $_POST['form_sent'] ? ($_POST['name'] ?? '') : ($_SESSION['poster_name'] ?? '');

if ($reply) {
    $cited_reply = isset($_GET['cite']) ? (int)$_GET['cite'] : (isset($_GET['quote_reply']) ? (int)$_GET['quote_reply'] : false);

    if ($cited_reply) {
        $new_body = '@' . number_format($cited_reply) . "\n\n";
        $res = $db->q('SELECT body, namefag, tripfag FROM replies WHERE id = ? AND deleted = 0', $cited_reply);
    } else {
        $res = $db->q('SELECT body, namefag, tripfag FROM topics WHERE id = ? AND deleted = 0', $topic_id);
    }

    list($cited_text, $cited_name, $cited_trip) = $res->fetch() ?: ['', '', ''];

    if (isset($_GET['quote_topic']) || isset($_GET['quote_reply'])) {
        $quoted_text = trim(preg_replace('/^@([0-9,]+|OP)/m', '', $cited_text));
        $quoted_text = preg_replace('/^/m', '> ', $cited_text);
        $new_body .= $quoted_text . "\n\n";
    }

    $body = $body ?? ($new_body ?? '');
    $cited_text = parser::parse($cited_text);
    $cited_text = preg_replace('/^@([0-9]+|OP),?([0-9]+)?/m', '<span class="unimportant"><a href="' . DIR . 'topic/' . $topic_id . '#reply_$1$2">$0</a></span>', $cited_text);
}

echo '<div>';

if ($reply && !$editing) {
    echo '<p>You <strong>' . (($_SESSION['UID'] ?? '') === $topic->author ? 'are' : 'are not') . '</strong> recognized as the original poster of this topic.</p>';
}

if ($editing && $perm->get('edit_limit') != 0) {
    echo '<p>You have <strong>' . age($now, $edit_data->time + $perm->get('edit_limit')) . '</strong> left to finish editing this post.</p>';
}

if (isset($_POST['preview']) && !empty($body)) {
    $preview_body = parser::parse($body, $_SESSION['UID'] ?? '');
    $preview_body = preg_replace('/^@([0-9]+|OP),?([0-9]+)?/m', '<span class="unimportant"><a href="' . DIR . 'topic/' . $topic_id . '#reply_$1$2">$0</a></span>', $preview_body);
    echo '<h3 id="preview">Preview</h3><div class="body standalone">' . $preview_body . '</div>';
}

if ($reply && isset($_SESSION['topic_visits'][$topic_id]) && $_SESSION['topic_visits'][$topic_id] < $topic->replies) {
    $new_replies = $topic->replies - $_SESSION['topic_visits'][$topic_id];
    echo '<p><a href="' . DIR . 'topic/' . $topic_id . '#new"><strong>' . $new_replies . '</strong> new repl' . ($new_replies == 1 ? 'y</a> has' : 'ies</a> have') . ' been posted in this topic since you last checked!</p>';
}

?>
<form action="" method="post"<?php if (ALLOW_IMAGES) echo ' enctype="multipart/form-data"'; ?>>
    <?php csrf_token(); ?>
    <div class="noscreen">
        <input name="form_sent" type="hidden" value="1" />
        <input name="e-mail" type="hidden" />
        <input name="start_time" type="hidden" value="<?php echo $start_time; ?>" />
    </div>
    <?php if (!$reply): ?>
    <div class="row">
        <label for="headline">Headline</label> <script type="text/javascript"> printCharactersRemaining('headline_remaining_characters', 100); </script>.
        <input id="headline" name="headline" tabindex="1" type="text" size="124" maxlength="100" onkeydown="updateCharactersRemaining('headline', 'headline_remaining_characters', 100);" onkeyup="updateCharactersRemaining('headline', 'headline_remaining_characters', 100);" value="<?php echo htmlspecialchars($headline ?? ''); ?>">
    </div>
    <?php endif; ?>
    <?php if (!$editing && (!defined('FORCED_ANON') || !FORCED_ANON || $perm->get('link'))): ?>
    <div class="row">
        <label for="name">Name</label>: <input id="name" name="name" type="text" size="30" maxlength="30" tabindex="2" value="<?php echo htmlspecialchars($set_name); ?>" class="inline">
        <?php if ($perm->get('link')): ?>
        <input type="checkbox" name="post_as_group" id="post_as_group" value="1" class="inline" <?php if (isset($_SESSION['show_group'])) echo ' checked="checked"'; ?> />
        <label for="post_as_group" class="inline"> Post as <?php echo htmlspecialchars($perm->get('name')); ?></label>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="row">
        <label for="body" class="noscreen">Post body</label>
        <textarea name="body" cols="120" rows="18" tabindex="2" id="body"><?php echo htmlspecialchars($body ?? ''); ?></textarea>
        <?php if (ALLOW_IMAGES && $perm->get('post_image')): ?>
        <label for="image" class="noscreen">Image</label> <input type="file" name="image" id="image" />
        <?php endif; ?>
        <?php if (IMGUR_KEY && !$editing): ?>
        <div>
            <?php if (ALLOW_IMAGES) echo 'Or use an'; ?> imgur URL:
            <input type="text" name="imgur" id="imgur" class="inline" size="21" placeholder="http://i.imgur.com/wDizy.gif" />
            <a href="http://imgur.com/" id="imgur_status" onclick="$('#imgur_file').click(); return false;">[upload]</a>
            <input type="file" id="imgur_file" class="noscreen" onchange="imgurUpload(this.files[0], '<?php echo IMGUR_KEY; ?>')" />
        </div>
        <?php endif; ?>
        <p><?php echo m('Post: Help'); ?></p>
    </div>
    <?php if (!$watching_topic): ?>
    <div class="row">
        <input type="checkbox" name="watch_topic" id="watch_topic" class="inline"<?php if (isset($_POST['watch_topic'])) echo ' checked="checked"'; ?> />
        <label for="watch_topic" class="inline"> Watch</label>
    </div>
    <?php endif; ?>
    <?php if (!$reply && !$editing): ?>
    <?php if ($perm->get('stick')): ?>
    <div>
        <input type="checkbox" name="sticky" value="1" class="inline"/>
        <label for="sticky" class="inline"> Stick</label>
    </div>
    <?php endif; ?>
    <?php if ($perm->get('lock')): ?>
    <div class="row">
        <input type="checkbox" name="locked" value="1" class="inline"/>
        <label for="locked" class="inline"> Lock</label>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$reply && !$editing): ?>
    <input type="hidden" id="enable_poll" name="enable_poll" value="1" />
    <ul class="menu"><li><a id="poll_toggle" onclick="showPoll(this);">Poll options</a></li></ul>
    <table id="topic_poll">
        <tr class="odd">
            <th colspan="2"><input type="checkbox" name="hide_results" id="hide_results" value="1" class="inline"<?php if ($_POST['hide_results'] ?? false) echo ' checked="checked"'; ?>/><label for="hide_results" class="inline help" title="If checked, the results of the poll will be hidden until a user either votes or chooses to 'show results'."> Hide results before voting</label></th>
        </tr>
        <?php for ($i = 1, $s = count($_POST['option'] ?? []); $i <= max(2, $s); $i++): ?>
        <tr>
            <td class="minimal">
                <label for="poll_option_<?php echo $i; ?>">Poll option #<?php echo $i; ?></label>
            </td>
            <td>
                <input type="text" size="50" maxlength="80" id="poll_option_<?php echo $i; ?>" name="option[]" value="<?php echo htmlspecialchars($_POST['option'][$i - 1] ?? ''); ?>" class="poll_input" />
            </td>
        </tr>
        <?php endfor; ?>
    </table>
    <?php endif; ?>

    <div class="row">
        <input type="submit" name="preview" tabindex="3" value="Preview" class="inline"<?php if (ALLOW_IMAGES) echo ' onclick="document.getElementById(\'image\').value=\'\'"'; ?> />
        <input type="submit" name="post" tabindex="4" value="<?php echo $editing ? 'Update' : 'Post'; ?>" class="inline">
    </div>
</form>
</div>

<?php if (!empty($cited_text)): ?>
<h3 id="replying_to">Replying to <?php echo format_name($cited_name, $cited_trip); ?>&hellip;</h3>
<div class="body standalone"><?php echo $cited_text; ?></div>
<?php endif; ?>

<?php $template->render(); ?>