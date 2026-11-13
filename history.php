<?php
require_once 'config.php';
require_login();
$q = db()->prepare('SELECT j.*, (SELECT COUNT(*) FROM clips c WHERE c.job_id=j.id) AS n FROM jobs j WHERE user_id=? ORDER BY id DESC');
$q->execute([$_SESSION['uid']]);
include 'header.php'; ?>
<h2>Meri purani videos</h2>
<div class="card"><table>
<tr><th>#</th><th>Video</th><th>Status</th><th>Clips</th><th>Date</th></tr>
<?php foreach ($q->fetchAll() as $j): ?>
<tr>
  <td><?= $j['id'] ?></td>
  <td><a href="job.php?id=<?= $j['id'] ?>"><?= h($j['title'] ?: $j['youtube_url']) ?></a></td>
  <td><?= h($j['status']) ?></td><td><?= (int)$j['n'] ?></td><td><?= h($j['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</table></div>
<?php include 'footer.php'; ?>
