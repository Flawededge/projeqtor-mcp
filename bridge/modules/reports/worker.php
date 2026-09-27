<?php
declare(strict_types=1);
function mcpReportsStartWorker(int $jobId,array $arguments,string $username): array {
  return workerExport($jobId,array('objectClass'=>'Report','format'=>'json'));
}
function mcpReportsRenderWorker(int $jobId,array $arguments,string $username): array { throw new RuntimeException('Native report rendering is not installed'); }
function mcpReportsRenderAvailable(array $action): bool { return false; }
