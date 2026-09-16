<?php
/**
 * admin/logout.php — end the staff session.
 */
declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::adminLogout();
Response::redirect('admin/login.php');
