<?php
declare(strict_types=1);

function mcpHrLeaveCalendarExportWorker(int $jobId,array $arguments,string $username): array {
  $year=(int)$arguments['year'];$month=(int)$arguments['month'];$format=(string)($arguments['format']??'xlsx');
  if(!in_array($format,array('xlsx','csv','json'),true))throw new RuntimeException('Unsupported leave calendar export format');
  $start=sprintf('%04d-%02d-01',$year,$month);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
  $clauses=array('endDate>='.Sql::str($start),'startDate<='.Sql::str($end));
  foreach(array('idStatus'=>'statusId','idLeaveType'=>'leaveTypeId','idEmployee'=>'employeeId') as $field=>$argument)if(!empty($arguments[$argument]))$clauses[]=$field.'='.Sql::fmtId((int)$arguments[$argument]);
  $leave=new Leave();$source=$leave->getSqlElementsFromCriteria(null,false,implode(' and ',$clauses),'startDate asc,id asc',false,true);$rows=array();
  foreach($source as $index=>$row){
    if($index%100===0){if(workerCancelled($jobId))throw new RuntimeException('cancelled');workerUpdate($jobId,'running',min(70,5+(int)(65*($index+1)/max(1,count($source)))));}
    if(!mcpHrMayManageEmployee((int)$row->idEmployee)||!Security::checkValidAccessForUser($row,'read',null,null,false))continue;
    $rows[]=array(
      'id'=>(int)$row->id,'employeeId'=>(int)$row->idEmployee,'employee'=>SqlList::getNameFromId('Employee',$row->idEmployee),
      'leaveTypeId'=>(int)$row->idLeaveType,'leaveType'=>SqlList::getNameFromId('LeaveType',$row->idLeaveType),
      'startDate'=>(string)$row->startDate,'startAMPM'=>(string)$row->startAMPM,
      'endDate'=>(string)$row->endDate,'endAMPM'=>(string)$row->endAMPM,'nbDays'=>(float)$row->nbDays,
      'statusId'=>(int)$row->idStatus,'status'=>SqlList::getNameFromId('Status',$row->idStatus),'comment'=>(string)$row->comment
    );
  }
  $path=workerArtifactPath($jobId,$format);$temporary=$path.'.tmp-'.bin2hex(random_bytes(6));
  if($format==='xlsx'){
    $autoload=version_compare(PHP_VERSION,'8.0.0','>=')?'/var/www/html/external/HtmlPhpExcel/vendor/autoload.php':'/var/www/html/external/HtmlPhp7Excel/vendor/autoload.php';
    if(!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet'))require_once $autoload;
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$headers=count($rows)?array_keys($rows[0]):array('id','employeeId','employee','leaveTypeId','leaveType','startDate','startAMPM','endDate','endAMPM','nbDays','statusId','status','comment');
    foreach($headers as $column=>$header)$sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column+1)."1",$header);
    foreach($rows as $line=>$row)foreach($headers as $column=>$header)$sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column+1).($line+2),$row[$header]??null);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($temporary);$book->disconnectWorksheets();
  }else{
    $handle=fopen($temporary,'xb');if(!$handle)throw new RuntimeException('Unable to create leave calendar artifact');
    if($format==='json')fwrite($handle,json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    else{$headers=count($rows)?array_keys($rows[0]):array('id','employeeId','employee','leaveTypeId','leaveType','startDate','startAMPM','endDate','endAMPM','nbDays','statusId','status','comment');fputcsv($handle,$headers);foreach($rows as $row)fputcsv($handle,array_map(fn($header)=>$row[$header]??null,$headers));}
    fflush($handle);fclose($handle);
  }
  $bytes=is_file($temporary)?(int)filesize($temporary):0;$maxBytes=max(1048576,(int)(getenv('MCP_JOB_ARTIFACT_MAX_BYTES')?:536870912));
  if($bytes>$maxBytes){@unlink($temporary);throw new RuntimeException('Leave calendar artifact exceeds the configured limit');}
  if(workerCancelled($jobId)){@unlink($temporary);throw new RuntimeException('cancelled');}
  if(!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Atomic leave calendar publication failed');}
  return array('ok'=>true,'format'=>$format,'count'=>count($rows),'bytes'=>$bytes,'maxBytes'=>$maxBytes,'period'=>array('startDate'=>$start,'endDate'=>$end),'resource'=>'projeqtor://jobs/'.$jobId.'/result','path'=>$path,'effects'=>array());
}
