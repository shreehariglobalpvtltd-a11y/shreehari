<?php
declare(strict_types=1);require __DIR__.'/_cron.php';require_once INCLUDE_PATH.'/carddelivery.php';cron_done(['card_jobs_processed'=>CardDelivery::drain(5)]);
