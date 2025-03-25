<?php
declare(strict_types=1);

require './includes/bootstrap.php';
update_activity('date_and_time');
$template->title = 'Date and time';

$day = date('l');
$dayn = date('jS');
$month = date('F');
$year = date('Y');
$week = date('W');
$dayz = date('z');
$dayy = 365 + (int)date('L');
$percent = round(($dayz / $dayy) * 100);
$miltime = date('H:i');
$civtime = date('g:i A');
$intform = date('Y-m-d H:i:s');
?>
<p>Today is <strong><?php echo htmlspecialchars($day); ?></strong> the <strong><?php echo htmlspecialchars($dayn); ?></strong> of <strong><?php echo htmlspecialchars($month); ?></strong> in the year <strong><?php echo htmlspecialchars($year); ?></strong>, week <strong><?php echo htmlspecialchars($week); ?></strong> out of 52 and day <strong><?php echo htmlspecialchars($dayz); ?></strong> out of <?php echo htmlspecialchars($dayy); ?> (~<?php echo htmlspecialchars($percent); ?>%). The time is <strong><?php echo htmlspecialchars($miltime); ?></strong> (or <strong><?php echo htmlspecialchars($civtime); ?></strong>). In <a target="_blank" href="http://www.cl.cam.ac.uk/%7Emgk25/iso-time.html">international standard format</a>: <strong><?php echo htmlspecialchars($intform); ?></strong>.</p>
<?php
$template->render();
?>