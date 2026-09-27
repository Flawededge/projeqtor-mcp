<?php
declare(strict_types=1);

function mcpReportsFavoriteAction(array $arguments,string $username,string $actionId): array {
  $items=array();$effects=array();Sql::beginTransaction();
  try{foreach($arguments['items'] as $index=>$entry){$operation=(string)$entry['operation'];$report=mcpReportsRequireReport((int)$entry['reportId']);
    if($operation==='create'){$favorite=new Favorite();$favorite->idUser=getSessionUser()->id;$favorite->scope='report';$favorite->idReport=$report->id;$favorite->idle=0;$favorite->sortOrder=(int)($entry['sortOrder']??($favorite->getMaxValueFromCriteria('sortOrder',array('idUser'=>$favorite->idUser))+1));}
    else{$favorite=new Favorite((int)$entry['id'],true);mcpReportsRequireOwned($favorite,(string)($entry['expectedVersion']??''));if((int)$favorite->idReport!==(int)$report->id)mcpReportsError('favorite_report_mismatch','Favorite report cannot change');if(isset($entry['sortOrder']))$favorite->sortOrder=(int)$entry['sortOrder'];if(isset($entry['idle']))$favorite->idle=$entry['idle']?1:0;}
    mcpReportsSave($favorite);foreach((new FavoriteParameter())->getSqlElementsFromCriteria(array('idFavorite'=>$favorite->id)) as $stored)mcpReportsDelete($stored);
    foreach(mcpReportsValidateParameters($entry['parameters']??array()) as $name=>$value){$stored=new FavoriteParameter();$stored->idUser=getSessionUser()->id;$stored->idReport=$report->id;$stored->idFavorite=$favorite->id;$stored->parameterName=$name;$stored->parameterValue=is_array($value)?implode(',',$value):(string)$value;mcpReportsSave($stored);}
    $saved=new Favorite($favorite->id,true);$status=$operation==='create'?'created':'updated';$items[]=array('index'=>$index,'status'=>$status,'id'=>(int)$saved->id,'version'=>mcpObjectVersion($saved));$effects[]=mcpReportsEffect($status,'Favorite',(int)$saved->id);
  }Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'items'=>$items,'effects'=>$effects);
}

function mcpReportsLayoutAction(array $arguments,string $username,string $actionId): array {
  $items=array();$effects=array();Sql::beginTransaction();
  try{foreach($arguments['items'] as $index=>$entry){$operation=(string)$entry['operation'];$class=(string)$entry['objectClass'];Security::checkValidClass($class);
    $probe=new $class();if(!Security::checkValidAccessForUser($probe,'read',null,null,false))mcpReportsError('layout_class_access_denied','List access is denied');
    if($operation==='create'){$layout=new ReportLayout();$layout->idUser=getSessionUser()->id;$layout->objectClass=$class;$layout->sortOrder=(int)($entry['sortOrder']??($layout->getMaxValueFromCriteria('sortOrder',array('idUser'=>$layout->idUser,'objectClass'=>$class))+1));}
    else{$layout=new ReportLayout((int)$entry['id'],true);mcpReportsRequireOwned($layout,(string)($entry['expectedVersion']??''));if($layout->objectClass!==$class)mcpReportsError('layout_class_mismatch','Layout class cannot change');}
    $layout->scope=(string)$entry['name'];$layout->comment=(string)($entry['comment']??'');$layout->isShared=!empty($entry['shared'])?1:0;mcpReportsSave($layout);
    if(array_key_exists('columns',$entry)){foreach((new LayoutColumnSelector())->getSqlElementsFromCriteria(array('idLayout'=>$layout->id,'isReportList'=>'1')) as $column)mcpReportsDelete($column);foreach($entry['columns'] as $position=>$data){$column=new LayoutColumnSelector();$column->idLayout=$layout->id;$column->scope='list';$column->objectClass=$class;$column->idUser=getSessionUser()->id;$column->field=(string)$data['field'];$column->attribute=(string)($data['attribute']??$data['field']);$column->hidden=!empty($data['hidden'])?1:0;$column->sortOrder=$position+1;$column->widthPct=(int)($data['widthPercent']??0);$column->isReportList=1;mcpReportsSave($column);}}
    $saved=new ReportLayout($layout->id,true);$status=$operation==='create'?'created':'updated';$items[]=array('index'=>$index,'status'=>$status,'id'=>(int)$saved->id,'version'=>mcpObjectVersion($saved));$effects[]=mcpReportsEffect($status,'ReportLayout',(int)$saved->id);
  }Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'items'=>$items,'effects'=>$effects);
}

function mcpReportsDashboardPinAction(array $arguments,string $username,string $actionId): array {
  $items=array();$effects=array();Sql::beginTransaction();
  try{foreach($arguments['items'] as $index=>$entry){$operation=(string)$entry['operation'];$report=mcpReportsRequireReport((int)$entry['reportId']);
    if($operation==='create'){$today=new Today();$today->idUser=getSessionUser()->id;$today->scope='report';$today->idReport=$report->id;$today->idle=0;$today->sortOrder=(int)($entry['sortOrder']??($today->getMaxValueFromCriteria('sortOrder',array('idUser'=>$today->idUser))+1));}
    else{$today=new Today((int)$entry['id'],true);mcpReportsRequireOwned($today,(string)($entry['expectedVersion']??''));if((int)$today->idReport!==(int)$report->id)mcpReportsError('dashboard_report_mismatch','Pinned report cannot change');if(isset($entry['sortOrder']))$today->sortOrder=(int)$entry['sortOrder'];if(isset($entry['idle']))$today->idle=$entry['idle']?1:0;}
    mcpReportsSave($today);$saved=new Today($today->id,true);$status=$operation==='create'?'created':'updated';$items[]=array('index'=>$index,'status'=>$status,'id'=>(int)$saved->id,'version'=>mcpObjectVersion($saved));$effects[]=mcpReportsEffect($status,'Today',(int)$saved->id);
  }Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'items'=>$items,'effects'=>$effects);
}

function mcpReportsDeleteOwnedAction(array $arguments,string $username,string $actionId): array {
  $class=str_contains($actionId,'layout')?'ReportLayout':(str_contains($actionId,'dashboard')?'Today':'Favorite');$items=array();$effects=array();Sql::beginTransaction();
  try{foreach($arguments['items'] as $index=>$entry){$object=new $class((int)$entry['id'],true);mcpReportsRequireOwned($object,(string)$entry['expectedVersion']);mcpReportsDelete($object);$items[]=array('index'=>$index,'status'=>'deleted','id'=>(int)$entry['id']);$effects[]=mcpReportsEffect('delete',$class,(int)$entry['id']);}Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'items'=>$items,'effects'=>$effects);
}
