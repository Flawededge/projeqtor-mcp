<?php
declare(strict_types=1);

function mcpLegacyActionDomain(string $actionId,string $moduleId): string {
  if($actionId==='project.snapshot')return 'project';
  if(str_starts_with($actionId,'planning.'))return 'planning';
  if(str_starts_with($actionId,'import.')||str_starts_with($actionId,'export.'))return 'exchange';
  if(str_starts_with($actionId,'attachment.'))return 'document';
  if(str_starts_with($actionId,'report.')||str_starts_with($actionId,'reports.'))return 'report';
  if(str_starts_with($actionId,'cron.')||str_starts_with($actionId,'user.'))return 'administration';
  if(str_starts_with($actionId,'workflow.'))return 'workflow';
  return $moduleId;
}
