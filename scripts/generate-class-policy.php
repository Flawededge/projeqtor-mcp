<?php
declare(strict_types=1);
if($argc!==5){fwrite(STDERR,"Usage: php generate-class-policy.php <source> <v2-manifest> <active-menus> <output>\n");exit(2);}
$root=rtrim(realpath($argv[1])?:'','/');$seed=json_decode((string)file_get_contents($argv[2]),true);$menus=json_decode((string)file_get_contents($argv[3]),true);
if(!$root){fwrite(STDERR,"Invalid source root\n");exit(2);}
if(!is_array($seed)||!is_array($seed['knownClasses']??null)){fwrite(STDERR,"Invalid v2 class seed\n");exit(2);}
if(!is_array($menus)||!is_array($menus['classes']??null)){fwrite(STDERR,"Invalid active-menu manifest\n");exit(2);}
if(count($menus['classes'])!==229){fwrite(STDERR,"Active-menu manifest must contain exactly 229 classes\n");exit(2);}
$relations=array_flip(array('Affectation','Assignment','AssignmentRecurring','Attachment','Approver','BusinessFeature','Calendar','Checklist','ChecklistLine','Dependency','DocumentRight','DocumentVersion','ExpenseDetail','Link','Note','Origin','OtherVersion','ProductAsset','ProductContext','ProductLanguage','ProductProject','ProductStructure','ProductVersionStructure','RaciAssignment','Recipient','ResourceCapacity','ResourceCost','ResourceIncompatible','ResourceSkill','ResourceSupport','ResourceSurbooking','ResourceTeamAffectation','StatusPeriod','Subscription','TenderEvaluationCriteria','TestCaseRun','VersionCompatibility','VersionContext','VersionLanguage','VersionProject','Work','WorkPeriod'));
$references=array_flip(array('ActivityPlanningMode','CalendarDefinition','Role','Status','Profile','PlanningMode','Priority','Urgency','Severity','Quality','Health','Trend','Likelihood','Criticality','Resolution','Language','Skill','SkillLevel','Location','Context','Context1','Context2','Context3','Workflow','Module','MeasureUnit','PaymentMode','PaymentType','PaymentDelay','DeliveryMode','RunStatus','ApprovalStatus','ProgressMode','RevenueMode','WeightMode'));
$derived=array_flip(array('Audit','Baseline','History','HistoryArchive','PlanningElement','PlanningHistory','PlannedWork','ProjectHistory'));
$sensitive=array_flip(array('OAuthClient','OtpRequest','PasswordResetRequest','SSO','UserOld'));
function classPolicyModule(string $class):string{
 $v=strtolower($class);
 if(preg_match('/(planning|work|calendar|leave|resource|capacity|assignment|affectation|environment|activity|project|milestone|dependency|schedule|critical|followup|gantt)/',$v))return 'planning_followup_environment';
 if(preg_match('/(ticket|sprint|kanban|backlog|scrum|agile)/',$v))return 'ticketing_scrum';
 if(preg_match('/(report|indicator|risk|issue|opportunity|decision|question|meeting|review|vote)/',$v))return 'steering_reports';
 if(preg_match('/(expense|budget|bill|payment|tender|quotation|order|invoice|contract|product|version|component|supplier|revenue|cost)/',$v))return 'financial_products';
 return 'hr_tools_configuration';
}
$classes=array();
foreach($seed['knownClasses'] as $class){
 $path=is_file("$root/model/$class.php")?"model/$class.php":(is_file("$root/model/custom/$class.php")?"model/custom/$class.php":null);
 if($path===null){fwrite(STDERR,"Missing model source for $class\n");exit(1);}$reason=null;
 if(isset($sensitive[$class])){$classification='sensitive';$supported=false;$reason='credential_or_authentication_state';}
 elseif(isset($derived[$class])){$classification='derived';$supported=true;}
 elseif(isset($relations[$class])){$classification='relation';$supported=true;}
 elseif(isset($references[$class])||preg_match('/Type$/D',$class)){$classification='reference';$supported=true;}
 elseif(preg_match('/(Main|Select|Selection|All|Current|Summary|SimpleMain|Full)$/D',$class)||preg_match('/^(Menu|AccessScope|List|Favorite|Layout|ColumnSelector|Extra|ImportProgress|Mutex|Locker)/D',$class)){$classification='internal';$supported=false;$reason='ui_projection_or_internal_state';}
 elseif(isset($menus['classes'][$class])){$classification=!empty($menus['classes'][$class]['administrative'])||preg_match('/^(User|Resource|Contact|Profile|Access|Workflow|Module|Parameter|Menu|Role|Status|Organization|Team)/',$class)?'administrative':'business';$supported=true;}
 else{$classification='internal';$supported=false;$reason='unpublished_support_model';}
 $operations=!$supported?array():($classification==='derived'?array('read'):array('read','create','update','delete'));
 $guarded=$classification==='administrative'||(bool)preg_match('/^(User|Resource|Contact|Profile|Access|Workflow|Module|Parameter|Menu|Role|Status|Organization|Team)/',$class);
 $classes[$class]=array('objectClass'=>$class,'classification'=>$classification,'supported'=>$supported,'reason'=>$reason,'operations'=>$operations,'guarded'=>$guarded,'module'=>classPolicyModule($class),'menu'=>$menus['classes'][$class]['menu']??null,'sourcePath'=>$path,'sourceHash'=>hash_file('sha256',"$root/$path"));
}
ksort($classes,SORT_STRING);$hash=hash('sha256',json_encode($classes,JSON_UNESCAPED_SLASHES));
$manifest=array('policyVersion'=>3,'sourceVersion'=>'13.1.0','sourceArchiveSha256'=>'221c2a0b2facbdfc0b5af9878e030cdd7ded6e3f989b1da9cd609eccebaa0a69','expectedInstalledClassCount'=>count($classes),'manifestHash'=>$hash,'classes'=>$classes);
file_put_contents($argv[4],json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");fwrite(STDOUT,json_encode(array('classes'=>count($classes),'manifestHash'=>$hash))."\n");
