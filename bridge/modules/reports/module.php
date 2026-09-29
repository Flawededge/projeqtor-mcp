<?php
declare(strict_types=1);
require_once __DIR__.'/actions.php';
require_once __DIR__.'/personal.php';
require_once __DIR__.'/scheduling.php';
require_once __DIR__.'/worker.php';
require_once __DIR__.'/descriptor.php';
return array('id'=>'reports','version'=>'4.0.0','dependencies'=>array('core','configuration','tools'),'enabledStateRequirements'=>array(),'actions'=>mcpReportsDescriptor());
