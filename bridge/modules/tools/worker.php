<?php
declare(strict_types=1);
function mcpToolsImportWorker(int $jobId,array $arguments,string $username): array { return workerImport($arguments,$username); }
function mcpToolsExportWorker(int $jobId,array $arguments,string $username): array { return workerExport($jobId,$arguments); }
