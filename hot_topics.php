<?php
declare(strict_types=1);

require './includes/bootstrap.php';
update_activity('hot_topics', 1);
$template->title = 'Hot topics';

// Prepare time-based queries with placeholders
$all_time = $db->q('SELECT headline, id, time, replies FROM topics WHERE deleted = 0 ORDER BY replies DESC LIMIT 50');
$last_hour = $db->q('SELECT headline, id, time, replies FROM topics WHERE time > ? AND deleted = 0 ORDER BY replies DESC LIMIT 10', $_SERVER['REQUEST_TIME'] - 3600);
$last_24_hours = $db->q('SELECT headline, id, time, replies FROM topics WHERE time > ? AND deleted = 0 ORDER BY replies DESC LIMIT 10', $_SERVER['REQUEST_TIME'] - 86400);
$this_week = $db->q('SELECT headline, id, time, replies FROM topics WHERE time > ? AND deleted = 0 ORDER BY replies DESC LIMIT 10', $_SERVER['REQUEST_TIME'] - 604800);
$this_month = $db->q('SELECT headline, id, time, replies FROM topics WHERE time > ? AND deleted = 0 ORDER BY replies DESC LIMIT 10', $_SERVER['REQUEST_TIME'] - 2629743);
?>

<div style="float: left; width: 50%;">
    <h2>Last hour:</h2>
    <ol>
        <?php while ($row = $last_hour->fetch(PDO::FETCH_ASSOC)): ?>
            <li>
                <a href="<?php echo DIR ?>topic/<?php echo $row['id'] ?>"><?php echo htmlspecialchars($row['headline']) ?></a> 
                (<?php echo number_format($row['replies']) ?>)
            </li>
        <?php endwhile; ?>
    </ol>
    
    <h2>Last 24 hours:</h2>
    <ol>
        <?php while ($row = $last_24_hours->fetch(PDO::FETCH_ASSOC)): ?>
            <li>
                <a href="<?php echo DIR ?>topic/<?php echo $row['id'] ?>"><?php echo htmlspecialchars($row['headline']) ?></a> 
                (<?php echo number_format($row['replies']) ?>)
            </li>
        <?php endwhile; ?>
    </ol>
    
    <h2>This week:</h2>
    <ol>
        <?php while ($row = $this_week->fetch(PDO::FETCH_ASSOC)): ?>
            <li>
                <a href="<?php echo DIR ?>topic/<?php echo $row['id'] ?>"><?php echo htmlspecialchars($row['headline']) ?></a> 
                (<?php echo number_format($row['replies']) ?>)
            </li>
        <?php endwhile; ?>
    </ol>
    
    <h2>This month:</h2>
    <ol>
        <?php while ($row = $this_month->fetch(PDO::FETCH_ASSOC)): ?>
            <li>
                <a href="<?php echo DIR ?>topic/<?php echo $row['id'] ?>"><?php echo htmlspecialchars($row['headline']) ?></a> 
                (<?php echo number_format($row['replies']) ?>)
            </li>
        <?php endwhile; ?>
    </ol>
</div>

<div style="float: right; width: 50%;">
    <h2>All time:</h2>
    <ol>
        <?php while ($row = $all_time->fetch(PDO::FETCH_ASSOC)): ?>
            <li>
                <a href="<?php echo DIR ?>topic/<?php echo $row['id'] ?>"><?php echo htmlspecialchars($row['headline']) ?></a> 
                (<?php echo number_format($row['replies']) ?>)
            </li>
        <?php endwhile; ?>
    </ol>
</div>

<?php
$template->render();
?>