<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }
foreach (['Privacy','Identity','Language','Knowledge','Provider','Tools','Conversation','Queue','Assets','Gateway'] as $module) {
    require_once __DIR__ . '/' . $module . '.php';
}
