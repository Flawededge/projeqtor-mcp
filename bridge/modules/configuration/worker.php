<?php
declare(strict_types=1);
function mcpConfigurationCronWorker(int $jobId,array $arguments,string $username,string $action=''): array {
  $payloadAction=$arguments['_action']??$action;return workerCron((string)$payloadAction);
}
function mcpConfigurationCronStartWorker(int $jobId,array $arguments,string $username): array { return workerCron('cron.start'); }
function mcpConfigurationCronRestartWorker(int $jobId,array $arguments,string $username): array { return workerCron('cron.restart'); }
