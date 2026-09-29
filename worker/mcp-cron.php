<?php
declare(strict_types=1);

$batchMode=1;
$apiMode=true;
$contextForAttributes='global';
chdir('/var/www/html/tool');
require '/var/www/html/tool/cronRun.php';
