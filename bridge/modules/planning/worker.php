<?php
declare(strict_types=1);
function mcpPlanningSnapshotWorker(int $jobId,array $arguments,string $username): array { return workerSnapshot($jobId,$arguments); }
function mcpPlanningCalculateWorker(int $jobId,array $arguments,string $username): array { return workerPlanning($jobId,$arguments); }
function mcpPlanningBaselineWorker(int $jobId,array $arguments,string $username): array { return workerBaseline($arguments); }
