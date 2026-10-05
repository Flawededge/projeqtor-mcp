<?php
declare(strict_types=1);
$fixture=getenv('OAUTH_TEST_ID');
if (!is_string($fixture)||!preg_match('/^[0-9a-f-]{36}$/D',$fixture)) throw new RuntimeException('Disposable fixture ID required');
$_SERVER['SCRIPT_NAME']='/tool/oauth-fixtures.php';$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_HOST']='localhost';$_SERVER['SERVER_NAME']='localhost';$_SERVER['SERVER_PORT']='80';
$batchMode=true;$apiMode=true;$contextForAttributes='global';chdir('/var/www/html/mcp-api');require '/var/www/html/tool/projeqtor.php';
$admin=SqlElement::getSingleSqlElementFromCriteria('User',array('name'=>'beta4-admin'));
if (!$admin->id) throw new RuntimeException('Disposable administrator missing');
$admin->_API=true;setSessionUser($admin);$batchMode=false;
$profile=SqlElement::getSingleSqlElementFromCriteria('Profile',array('profileCode'=>'TM'));$ids=array();
foreach(array('one','two','duplicate-one','duplicate-two') as $suffix) {
  $user=new User();$user->name='oauth-'.$fixture.'-'.$suffix;$user->resourceName=$user->name;
  $user->email=(str_starts_with($suffix,'duplicate-')?'duplicate':$suffix).'-'.$fixture.'@hikoterra.com';
  $user->idProfile=(int)$profile->id;$user->isResource=1;$user->isEmployee=1;$user->idle=0;$user->locked=0;
  if (getLastOperationStatus($user->save())!=='OK') throw new RuntimeException('Native fixture creation failed');
  $ids[]=(int)$user->id;
}
if(ob_get_level()>0)ob_clean();echo json_encode(array('ids'=>$ids));
