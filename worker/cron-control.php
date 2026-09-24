<?php
declare(strict_types=1);

function workerCron(string $action): array {
  $status=Cron::check();
  if($action==='cron.restart'&&$status==='running'){
    Cron::setRestartFlag();
    return array('ok'=>true,'cronStatus'=>'restart_requested');
  }
  if($status==='running')return array('ok'=>true,'cronStatus'=>'already_running');
  exec('/usr/local/bin/php /usr/local/lib/projeqtor/mcp-cron.php >/dev/null 2>&1 &');
  usleep(250000);
  return array('ok'=>true,'cronStatus'=>Cron::check());
}
