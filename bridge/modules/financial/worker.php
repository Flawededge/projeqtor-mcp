<?php
declare(strict_types=1);

function mcpFinancialAbacusApplyWorker(int $jobId,array $arguments,string $username): array {
  $project=mcpFinancialTarget('Project',(int)$arguments['idProject'],'update');
  if(!hash_equals(mcpObjectVersion($project),(string)$arguments['expectedVersion']))throw new RuntimeException('Project version conflict');
  $mode=(string)$arguments['mode'];$applied=array();workerUpdate($jobId,'running',10);
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');
  Sql::beginTransaction();
  try{
    if(in_array($mode,array('affectations','both'),true)){$raw=$project->affectAbacusResourcesToProject();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));$applied[]='affectations';workerUpdate($jobId,'running',45);}
    if(workerCancelled($jobId))throw new RuntimeException('cancelled');
    if(in_array($mode,array('assignments','both'),true)){$raw=$project->createAssignmentsFromAbacus();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));$applied[]='assignments';workerUpdate($jobId,'running',85);}
    Sql::commitTransaction();
  }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  $saved=new Project((int)$project->id);$item=array('index'=>0,'status'=>'updated','objectClass'=>'Project','id'=>(int)$saved->id,'relatedIds'=>array(),'requestedFields'=>array('mode'),'appliedFields'=>$applied,'recalculatedFields'=>array('Affectation','Assignment'),'ignoredFields'=>array(),'rejectedFields'=>array(),'saved'=>array('id'=>(int)$saved->id,'_version'=>mcpObjectVersion($saved)),'concurrencyUnchecked'=>false);
  return array('ok'=>true,'rolledBack'=>false,'transactionMode'=>'atomic','items'=>array($item),'effects'=>array(array('action'=>'update','objectClass'=>'Project','id'=>(int)$saved->id)));
}

function mcpFinancialFacturXText(DOMXPath $xpath,string $query,?DOMNode $context=null): string {
  $nodes=$xpath->query($query,$context);return !$nodes||$nodes->length===0?'':trim((string)$nodes->item(0)->textContent);
}
function mcpFinancialFacturXAttribute(DOMXPath $xpath,string $query,string $attribute,?DOMNode $context=null): string {
  $nodes=$xpath->query($query,$context);if(!$nodes||$nodes->length===0)return '';$node=$nodes->item(0);return $node instanceof DOMElement?$node->getAttribute($attribute):'';
}
function mcpFinancialFacturXDate(string $value): ?string {$value=trim($value);if(preg_match('/^\d{8}$/D',$value))return substr($value,0,4).'-'.substr($value,4,2).'-'.substr($value,6,2);return preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)?$value:null;}
function mcpFinancialFacturXNumber(string $value): ?float {$value=trim($value);return $value===''?null:(float)$value;}

function mcpFinancialParseFacturX(string $xml): array {
  $dom=new DOMDocument();libxml_use_internal_errors(true);if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOCDATA))throw new RuntimeException('Factur-X XML is malformed');
  $xpath=new DOMXPath($dom);$xpath->registerNamespace('rsm','urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');$xpath->registerNamespace('ram','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');$xpath->registerNamespace('udt','urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
  $data=array(
    'reference'=>mcpFinancialFacturXText($xpath,'//rsm:ExchangedDocument/ram:ID'),
    'issueDate'=>mcpFinancialFacturXDate(mcpFinancialFacturXText($xpath,'//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString')),
    'buyerOrderReference'=>mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeAgreement/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'),
    'provider'=>array('name'=>mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name'),'taxNumber'=>mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'),'zip'=>mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode'),'street'=>mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineOne'),'city'=>mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName'),'country'=>mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID')),
    'paymentCondition'=>mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms/ram:Description'),
    'paymentDueDate'=>mcpFinancialFacturXDate(mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString')),
    'untaxedAmount'=>mcpFinancialFacturXNumber(mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount')),
    'taxAmount'=>mcpFinancialFacturXNumber(mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount')),
    'fullAmount'=>mcpFinancialFacturXNumber(mcpFinancialFacturXText($xpath,'//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount')),
    'lines'=>array()
  );
  foreach($xpath->query('//ram:IncludedSupplyChainTradeLineItem')?:array() as $node){$quantity=mcpFinancialFacturXNumber(mcpFinancialFacturXText($xpath,'./ram:SpecifiedLineTradeDelivery/ram:BilledQuantity',$node));$unitPrice=mcpFinancialFacturXNumber(mcpFinancialFacturXText($xpath,'./ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount',$node));$amount=mcpFinancialFacturXNumber(mcpFinancialFacturXText($xpath,'./ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount',$node));$data['lines'][]=array('line'=>(int)mcpFinancialFacturXText($xpath,'./ram:AssociatedDocumentLineDocument/ram:LineID',$node),'name'=>mcpFinancialFacturXText($xpath,'./ram:SpecifiedTradeProduct/ram:Name',$node),'detail'=>mcpFinancialFacturXText($xpath,'./ram:SpecifiedTradeProduct/ram:Description',$node),'quantity'=>$quantity??1,'unitCode'=>mcpFinancialFacturXAttribute($xpath,'./ram:SpecifiedLineTradeDelivery/ram:BilledQuantity','unitCode',$node),'price'=>$unitPrice,'amount'=>$amount??(($quantity??1)*($unitPrice??0)));}
  if($data['reference']===''||($data['provider']['name']===''&&$data['provider']['taxNumber']===''))throw new RuntimeException('Factur-X is missing an invoice reference or seller identity');
  if(count($data['lines'])<1||count($data['lines'])>200)throw new RuntimeException('Factur-X must contain 1 to 200 invoice lines');
  return $data;
}

function mcpFinancialProviderForFacturX(array $provider,bool $allowCreate): array {
  $tax=preg_replace('/\s+/','',(string)$provider['taxNumber']);$existing=null;
  if($tax!=='')$existing=SqlElement::getFirstSqlElementFromCriteria('Provider',array('numTax'=>$tax));
  if(!$existing||!$existing->id)$existing=SqlElement::getFirstSqlElementFromCriteria('Provider',array('name'=>(string)$provider['name']));
  if($existing&&$existing->id){if(!mcpFinancialDirectAccess($existing,'read'))throw new RuntimeException('Matched provider is not readable');return array($existing,false);}
  if(!$allowCreate)throw new RuntimeException('No matching provider exists and createProvider is false');
  mcpRequireClassOperation('Provider','create');$object=new Provider();if(!mcpFinancialDirectAccess($object,'create'))throw new RuntimeException('Provider creation access is denied');
  foreach(array('name'=>'name','taxNumber'=>'numTax','zip'=>'zip','street'=>'street','city'=>'city','country'=>'country') as $source=>$target)$object->$target=(string)$provider[$source];
  $raw=$object->save();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));return array($object,true);
}

function mcpFinancialFacturXWorker(int $jobId,array $arguments,string $username): array {
  $project=mcpFinancialTarget('Project',(int)$arguments['idProject'],'read');$meta=mcpReadUpload((string)$arguments['uploadId'],$username);$path=mcpUploadDataPath((string)$arguments['uploadId']);
  if(!is_file($path)||is_link($path))throw new RuntimeException('Factur-X upload is unavailable');$bytes=(int)filesize($path);$configured=(int)Parameter::getGlobalParameter('paramAttachmentMaxSize')*1024*1024;$limit=$configured>0?min($configured,52428800):52428800;
  if($bytes<5||$bytes!==(int)$meta['expectedBytes']||$bytes>$limit)throw new RuntimeException('Factur-X upload size is invalid');if(strtolower(pathinfo((string)$meta['fileName'],PATHINFO_EXTENSION))!=='pdf')throw new RuntimeException('Factur-X import requires a PDF');
  $handle=fopen($path,'rb');$magic=$handle?fread($handle,5):'';if($handle)fclose($handle);if($magic!=='%PDF-')throw new RuntimeException('Factur-X upload does not have a PDF signature');Security::checkEvilFile($path);
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');workerUpdate($jobId,'running',10);global $hideAutoloadError;$hideAutoloadError=true;require_once '/var/www/html/external/factur-x/autoload.php';
  $reader=new \Atgp\FacturX\Reader();$xml=$reader->extractXML((string)file_get_contents($path));if(!$xml)throw new RuntimeException('The PDF does not contain Factur-X XML');$validator=new \Atgp\FacturX\XsdValidator();$validator->validateWithException($xml);$data=mcpFinancialParseFacturX($xml);workerUpdate($jobId,'running',30);
  mcpRequireClassOperation('ProviderBill','create');$candidate=new ProviderBill();if(!mcpFinancialDirectAccess($candidate,'create'))throw new RuntimeException('Provider bill creation access is denied');
  Sql::beginTransaction();$effects=array();
  try{
    list($provider,$createdProvider)=mcpFinancialProviderForFacturX($data['provider'],(bool)($arguments['createProvider']??true));if($createdProvider)$effects[]=array('action'=>'create','objectClass'=>'Provider','id'=>(int)$provider->id);
    if(workerCancelled($jobId))throw new RuntimeException('cancelled');$type=new Type();$types=$type->getSqlElementsFromCriteria(array('scope'=>'ProviderBill'),false,null,'id asc');if(!$types||!$types[0]->id)throw new RuntimeException('No ProviderBill type is configured');
    $bill=new ProviderBill();$bill->reference=$data['reference'];$bill->name=$data['reference'];$bill->date=$data['issueDate']?:date('Y-m-d');$bill->externalReference=$data['buyerOrderReference'];$bill->paymentCondition=$data['paymentCondition'];$bill->paymentDueDate=$data['paymentDueDate'];$bill->idProvider=(int)$provider->id;$bill->idProject=(int)$project->id;$bill->untaxedAmount=$data['untaxedAmount'];$bill->taxAmount=$data['taxAmount'];$bill->fullAmount=$data['fullAmount'];$bill->totalUntaxedAmount=$data['untaxedAmount'];$bill->totalTaxAmount=$data['taxAmount'];$bill->totalFullAmount=$data['fullAmount'];$bill->idUser=(int)getSessionUser()->id;$bill->idProviderBillType=(int)$types[0]->id;$bill->idStatus=1;
    if(!mcpFinancialDirectAccess($bill,'create'))throw new RuntimeException('Provider bill creation access is denied for the selected project');
    $raw=$bill->save();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));$effects[]=array('action'=>'create','objectClass'=>'ProviderBill','id'=>(int)$bill->id);$lineIds=array();workerUpdate($jobId,'running',50);
    foreach($data['lines'] as $index=>$line){if(workerCancelled($jobId))throw new RuntimeException('cancelled');$record=new BillLine();$record->refType='ProviderBill';$record->refId=(int)$bill->id;$record->line=(int)$line['line'];$record->quantity=$line['quantity'];$record->price=$line['price'];$record->amount=$line['amount'];$record->description=$line['name'];$record->detail=$line['detail'];if($line['unitCode']!==''){$missing=array();$record->idMeasureUnit=MeasureUnit::getMeasureUnitIdFromInput($line['unitCode'],'1',$missing);}$raw=$record->save();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));$lineIds[]=(int)$record->id;$effects[]=array('action'=>'create','objectClass'=>'BillLine','id'=>(int)$record->id);workerUpdate($jobId,'running',50+(int)(45*($index+1)/count($data['lines'])));}
    Sql::commitTransaction();
  }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  @unlink(mcpUploadDataPath((string)$arguments['uploadId']));@unlink(mcpUploadMetaPath((string)$arguments['uploadId']));
  return array('ok'=>true,'providerId'=>(int)$provider->id,'providerBillId'=>(int)$bill->id,'lineIds'=>$lineIds,'effects'=>$effects);
}
