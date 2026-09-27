<?php
declare(strict_types=1);
function mcpToolsAttachmentAction(array $arguments,string $username,string $action): array { return mcpExecuteUploadAction($action,$arguments,$username); }
function mcpToolsCleanupImport(array $arguments,string $username,string $action): array { return mcpCleanupImportRun($username,$arguments); }
function mcpToolsPreviewImport(array $arguments,string $username,string $action): array { return mcpImportCleanupPreview($username,$arguments); }
