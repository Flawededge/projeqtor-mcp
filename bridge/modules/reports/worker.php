<?php
declare(strict_types=1);
function mcpReportsRenderWorker(int $jobId,array $arguments,string $username): array { return workerRenderReport($jobId,$arguments); }
