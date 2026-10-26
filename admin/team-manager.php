<?php
declare(strict_types=1);
require __DIR__.'/_guard.php';
admin_boot('staff.manage');
Response::redirect('admin/team.php'.(!empty($_GET['person'])?'?person='.rawurlencode((string)$_GET['person']):''));
