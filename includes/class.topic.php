<?php

declare(strict_types=1);

use PDO;

/**
 * Fetches and formats information for a discussion topic
 */
class Topic
{
    private PDO $db;
    public int $id;
    public int $page = 0;
    private int $pageStart = 1;
    private ?int $pageEnd = null;
    private bool $stoppedPrematurely = false;
    public ?int $lastReadPost = null;
    public int $replyCount = 0;
    public int $replyRecount = 0;
    public bool $locked = false;
    public bool $pollHide = false;
    public int $pollVotes = 0;
    public array $pollOptions = [];
    public bool $voted = false;
    public ?int $chosenOption = null;
    public bool $watched = false;
    public bool $watchedNew = false;
    public array $posters = [];
    public array $history = [];
    public array $merges = [];
    private int $posterNumber = 1;
    public ?int $previousTime = null;
    public ?int $previousAuthor = null;
    public ?int $previousId = null;
    public ?string $yourName = null;
    public ?stdClass $OP = null;
    public ?int $lastPost = null;

    public function __construct(int $id, int $page = 0)
    {
        global $db;
        
        $this->db = $db;
        $this->id = $id;
        $this->page = $page;

        $this->initializePagination();
        $this->fetchTopicData();
    }

    private function initializePagination(): void
    {
        if (!empty($_SESSION['settings']['posts_per_page']) && $this->page > 0) {
            $postsPerPage = (int)$_SESSION['settings']['posts_per_page'];
            $this->pageStart += $postsPerPage * ($this->page - 1);
            $this->pageEnd = $this->pageStart + $postsPerPage;
        }
    }

    private function fetchTopicData(): void
    {
        $query = $this->db->prepare(
            'SELECT t.time, t.author, t.visits, t.replies, t.headline, t.body,
                    t.edit_time, t.edit_mod, t.namefag, t.tripfag, t.link, t.deleted,
                    t.sticky, t.locked, t.poll, t.poll_hide, t.last_post, t.imgur' .
            (ALLOW_IMAGES ? ', i.file_name, i.original_name, i.md5, i.deleted AS image_deleted' : '') .
            ' FROM topics t' .
            (ALLOW_IMAGES ? ' LEFT JOIN images i ON t.id = i.topic_id' : '') .
            ' WHERE t.id = ?'
        );
        
        $query->execute([$this->id]);
        $this->OP = $query->fetchObject();

        if (!$this->OP) {
            throw new RuntimeException('Topic not found with ID: ' . $this->id);
        }

        $this->initializeTopicProperties();
    }

    private function initializeTopicProperties(): void
    {
        $this->OP->image_ignored = $this->OP->file_name && 
            ($_SESSION['settings']['text_mode'] || is_ignored($this->OP->md5));
        
        $this->lastPost = (int)$this->OP->last_post;
        $this->replyCount = (int)$this->OP->replies;
        $this->previousTime = (int)$this->OP->time;
        $this->previousAuthor = (int)$this->OP->author;
        $this->posters[$this->OP->author] = ['number' => 0];
        
        $this->initializeVisits();
        $this->handleLockStatus();
        $this->handlePoll();
        $this->checkWatchStatus();
        $this->handleDeletionsAndEdits();
    }

    private function initializeVisits(): void
    {
        $_SESSION['topic_visits'] ??= [];
        $this->lastReadPost = $_SESSION['topic_visits'][$this->id] ?? 0;

        if (!isset($_SESSION['topic_visits'][$this->id]) && isset($_COOKIE['SID'])) {
            $this->db->prepare('UPDATE topics SET visits = visits + 1 WHERE id = ?')
                ->execute([$this->id]);
        }
    }

    private function handleLockStatus(): void
    {
        $this->locked = (bool)$this->OP->locked;
        if (AUTOLOCK && 
            ($_SERVER['REQUEST_TIME'] - $this->lastPost) > AUTOLOCK && 
            $this->OP->author != $_SESSION['UID']) {
            $this->locked = true;
        }
    }

    private function handlePoll(): void
    {
        if ($this->OP->poll) {
            $this->pollHide = (bool)$this->OP->poll_hide;
            $this->fetchPollData();
        }
    }

    private function fetchPollData(): void
    {
        $voteQuery = $this->db->prepare(
            'SELECT option_id FROM poll_votes WHERE uid = ? AND parent_id = ? LIMIT 1'
        );
        $voteQuery->execute([$_SESSION['UID'], $this->id]);
        [$this->voted, $this->chosenOption] = $voteQuery->fetch(PDO::FETCH_NUM) ?: [false, null];

        $optionsQuery = $this->db->prepare(
            'SELECT id, option, votes FROM poll_options WHERE parent_id = ?'
        );
        $optionsQuery->execute([$this->id]);

        while ($option = $optionsQuery->fetch(PDO::FETCH_ASSOC)) {
            $this->pollOptions[$option['id']] = [
                'text' => $option['option'],
                'votes' => (int)$option['votes']
            ];
            $this->pollVotes += $option['votes'];
        }
    }

    private function checkWatchStatus(): void
    {
        if (!empty($_SESSION['ID_activated'])) {
            $watchQuery = $this->db->prepare(
                'SELECT new_replies FROM watchlists WHERE topic_id = ? AND uid = ? LIMIT 1'
            );
            $watchQuery->execute([$this->id, $_SESSION['UID']]);
            $status = $watchQuery->fetchColumn();
            
            $this->watched = $status !== false;
            $this->watchedNew = (bool)$status;
        }
    }

    private function handleDeletionsAndEdits(): void
    {
        if ($this->OP->deleted) {
            [$this->OP->deleted_by, $this->OP->deleted_at, $this->OP->delete_reason] = 
                $this->getModLog('delete_topic', $this->id);
        }
        if ($this->OP->image_deleted) {
            $this->OP->file_name = false;
            [$this->OP->image_deleted_by, $this->OP->image_deleted_at, $this->OP->image_delete_reason] = 
                $this->getModLog('delete_image', $this->id);
        }
        if ($this->OP->edit_mod) {
            [$this->OP->edited_by, , $this->OP->edit_reason] = 
                $this->getModLog('edit_topic', $this->id);
        }
    }

    public function __destruct()
    {
        if (!$this->stoppedPrematurely && 
            ($this->replyRecount != $this->replyCount || $this->previousTime != $this->lastPost)) {
            $this->db->prepare('UPDATE topics SET replies = ?, last_post = ? WHERE id = ?')
                ->execute([$this->replyRecount, $this->previousTime, $this->id]);
            $this->replyCount = $this->replyRecount;
        }
        $this->updateVisits();
    }

    private function updateVisits(): void
    {
        if (!isset($_SESSION['topic_visits'][$this->id]) || $this->lastReadPost !== $this->replyCount) {
            $_SESSION['topic_visits'] = array_merge(
                [$this->id => $this->replyCount],
                $_SESSION['topic_visits']
            );
            $_SESSION['topic_visits'] = array_slice($_SESSION['topic_visits'], 0, MEMORABLE_TOPICS, true);
            
            $this->db->prepare('UPDATE users SET topic_visits = ?, last_seen = ? WHERE uid = ? LIMIT 1')
                ->execute([json_encode($_SESSION['topic_visits']), $_SERVER['REQUEST_TIME'], $_SESSION['UID']]);
        }
    }

    private function getModLog(string $action, int $id): array
    {
        $query = $this->db->prepare(
            'SELECT mod_uid, time, reason FROM mod_actions WHERE action = ? AND target = ? LIMIT 1'
        );
        $query->execute([$action, $id]);
        return $query->fetch(PDO::FETCH_NUM) ?: [false, false, false];
    }

    public function getReply(): string|stdClass|bool
    {
        global $perm;

        if (!isset($this->replyHandle)) {
            $this->prepareReplyQuery();
        }

        $reply = $this->replyHandle->fetchObject();
        if (!$reply) {
            return false;
        }

        return $this->processReply($reply, $perm);
    }

    private function prepareReplyQuery(): void
    {
        $query = 'SELECT r.id, r.time, r.author, r.body, r.deleted, r.edit_time,
                        r.edit_mod, r.namefag, r.tripfag, r.link, r.imgur, r.original_parent' .
                (ALLOW_IMAGES ? ', i.file_name, i.original_name, i.md5, i.deleted AS image_deleted' : '') .
                ' FROM replies r' .
                (ALLOW_IMAGES ? ' LEFT JOIN images i ON r.id = i.reply_id' : '') .
                ' WHERE r.parent_id = ? ORDER BY r.time';
        
        $this->replyHandle = $this->db->prepare($query);
        $this->replyHandle->execute([$this->id]);
    }

    private function processReply(stdClass $reply, object $perm): string|stdClass
    {
        $reply->image_ignored = $reply->file_name && 
            ($_SESSION['settings']['text_mode'] || is_ignored($reply->md5));
        $reply->joined_in = !isset($this->posters[$reply->author]);

        $this->updatePosterInfo($reply);
        $this->updateHistory($reply);

        if ($this->shouldSkipReply($reply, $perm)) {
            return 'skip';
        }

        return $this->formatReply($reply);
    }

    private function updatePosterInfo(stdClass $reply): void
    {
        if ($reply->joined_in) {
            $this->posters[$reply->author] = [
                'first_reply' => $reply->id,
                'number' => $this->posterNumber++
            ];
        }
        
        $reply->poster_number = $this->posters[$reply->author]['number'];
        $reply->first_post_number_by_author = ($reply->author == $this->OP->author) 
            ? 0 
            : $this->history[$this->posters[$reply->author]['first_reply']]['post_number'];

        if ($reply->author == $_SESSION['UID']) {
            $this->yourName = $reply->namefag ?? $reply->tripfag ?? number_to_letter($reply->poster_number);
        }
    }

    private function updateHistory(stdClass $reply): void
    {
        $this->history[$reply->id] = [
            'body' => $reply->body,
            'author' => $reply->author,
            'name' => $reply->namefag,
            'trip' => $reply->tripfag,
            'poster_number' => $reply->poster_number,
            'post_number' => $this->replyRecount + 1
        ];
    }

    private function shouldSkipReply(stdClass $reply, object $perm): bool
    {
        if ($reply->deleted) {
            $this->history[$reply->id]['deleted'] = true;
            if ($reply->author != $_SESSION['UID'] && !$perm->get('undelete')) {
                return true;
            }
        } else {
            $this->replyRecount++;
        }

        if (is_ignored($reply->body, $reply->namefag, $reply->tripfag)) {
            $this->history[$reply->id]['hidden'] = true;
            return true;
        }

        if (!empty($_SESSION['settings']['posts_per_page']) && $this->page) {
            if ($this->page > 1 && $this->replyRecount < $this->pageStart) {
                return true;
            }
            if ($this->replyRecount === $this->pageEnd) {
                $this->stoppedPrematurely = true;
                return true;
            }
        }

        return false;
    }

    private function formatReply(stdClass $reply): stdClass
    {
        $reply->parsed_body = parser::parse($reply->body, $reply->author);
        $reply->parsed_body = $this->processCitations($reply->parsed_body, $reply->id, $reply->original_parent);

        if ($reply->deleted) {
            [$reply->deleted_by, $reply->deleted_at, $reply->delete_reason] = 
                $this->getModLog('delete_reply', $reply->id);
        }
        if ($reply->image_deleted) {
            $reply->file_name = false;
            [$reply->image_deleted_by, $reply->image_deleted_at, $reply->image_delete_reason] = 
                $this->getModLog('delete_image', $reply->id);
        }
        if ($reply->edit_mod) {
            [$reply->edited_by, , $reply->edit_reason] = 
                $this->getModLog('edit_reply', $reply->id);
        }

        return $reply;
    }

    private function processCitations(string $body, int $replyId, ?int $originalParent): string
    {
        $body = str_ireplace('@OP', '<span class="unimportant poster_number_0"><a href="#OP">@OP</a></span>', $body);
        
        preg_match_all('/@([0-9,]+)/m', $body, $matches);
        $citations = array_unique($matches[1]);

        if (!$citations && $originalParent && isset($this->merges[$originalParent])) {
            $mergeCitation = number_format($this->merges[$originalParent]);
            $body = "@{$mergeCitation}<br>{$body}";
            $citations[] = $mergeCitation;
        }

        foreach ($citations as $citation) {
            $pureId = str_replace(',', '', $citation);
            $body = str_replace("@{$citation}", $this->formatCitation($pureId, $citation, $replyId), $body);
        }

        return $body;
    }

    private function formatCitation(string $pureId, string $citation, int $currentReplyId): string
    {
        $citedName = $this->getCitedName($pureId);
        
        if (!isset($this->history[$pureId])) {
            return "<span class=\"unimportant help\" title=\"{$citation}\">(Citing a non-existent reply.)</span>";
        }
        
        if (isset($this->history[$pureId]['deleted']) && 
            $this->history[$pureId]['author'] != $_SESSION['UID'] && 
            !$GLOBALS['perm']->get('undelete')) {
            return "<span class=\"unimportant help\" title=\"@{$citation}\">@deleted{$citedName}</span>";
        }
        
        if (isset($this->history[$pureId]['hidden'])) {
            return "<span class=\"unimportant help\" title=\"" . parser::snippet($this->history[$pureId]['body']) . 
                   "\">@hidden{$citedName}</span>";
        }

        $linkText = ($pureId == $this->previousId) ? 'previous' : $citation;
        $pageLink = (!empty($_SESSION['settings']['posts_per_page']) && $this->page) 
            ? DIR . 'topic/' . $this->id . page($this->replyCount, $this->history[$pureId]['post_number'])
            : '';

        return sprintf(
            '<span class="unimportant poster_number_%d"><a href="%s#reply_%s" onclick="createSnapbackLink(\'%s\'); highlightReply(\'%s\');" class="help" title="%s">@%s</a>%s</span>',
            $this->history[$pureId]['poster_number'],
            $pageLink,
            $pureId,
            $currentReplyId,
            $pureId,
            parser::snippet($this->history[$pureId]['body']),
            $linkText,
            $citedName
        );
    }

    private function getCitedName(string $pureId): string
    {
        if ($this->history[$pureId]['author'] == $_SESSION['UID']) {
            return '<em class="you">(you)</em>';
        }
        if ($this->history[$pureId]['name']) {
            return '(' . trim(htmlspecialchars($this->history[$pureId]['name'])) . ')';
        }
        if ($this->history[$pureId]['trip']) {
            return '(' . trim($this->history[$pureId]['trip']) . ')';
        }
        return '(<strong>' . number_to_letter($this->history[$pureId]['poster_number']) . '</strong>)';
    }

    public function clearCitations(): int
    {
        $query = $this->db->prepare('DELETE FROM citations WHERE uid = ? AND topic = ?');
        $query->execute([$_SESSION['UID'], $this->id]);
        return $query->rowCount();
    }

    public function clearWatchlist(): int
    {
        $query = $this->db->prepare('UPDATE watchlists SET new_replies = 0 WHERE topic_id = ? AND uid = ? LIMIT 1');
        $query->execute([$this->id, $_SESSION['UID']]);
        return $query->rowCount();
    }

    public function printPages(): void
    {
        if (empty($_SESSION['settings']['posts_per_page']) || !$this->page || 
            $this->replyCount <= $_SESSION['settings']['posts_per_page']) {
            return;
        }

        $pages = ceil($this->replyCount / $_SESSION['settings']['posts_per_page']);
        echo '<div class="topic_pages">';
        
        if ($this->page > 1) {
            printf('<span class="topic_page"><a href="%stopic/%d/%d">«</a></span>', DIR, $this->id, $this->page - 1);
        }
        
        for ($i = 1; $i <= $pages; $i++) {
            $class = ($i === $this->page) ? 'topic_page current_page' : 'topic_page';
            $link = ($i === $this->page) 
                ? number_format($i)
                : sprintf('<a href="%stopic/%d/%d">%s</a>', DIR, $this->id, $i, number_format($i));
            echo "<span class=\"{$class}\">{$link}</span> ";
        }
        
        if ($this->page < $pages) {
            printf('<span class="topic_page"><a href="%stopic/%d/%d">»</a></span>', DIR, $this->id, $this->page + 1);
        }
        
        printf('<span class="topic_page all_pages"><a href="%stopic/%d">All</a></span></div>', DIR, $this->id);
    }

    public function encodeQuote(string $body): string
    {
        $body = trim(preg_replace('/^@([0-9,]+|OP)/m', '', $body));
        $body = preg_replace('/^/m', '> ', $body);
        return rawurlencode($body);
    }
}