<?php
/** Real MySQL tables; controlled core helpers and Asterisk transport. Never places calls. */
$dsn=getenv('AUTODIAL_TEST_DSN')?:'';
if (!preg_match('/dbname=([a-z0-9_]*_test)(?:;|$)/D',$dsn)) { throw new RuntimeException('Use an isolated *_test database'); }
$testDB=new PDO($dsn,getenv('AUTODIAL_TEST_USER')?:'',getenv('AUTODIAL_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require __DIR__.'/host/core/PluginManager.php';
require dirname(__DIR__).'/AutoDialerService.php';
foreach (explode(';',preg_replace('/^--.*$/m','',file_get_contents(dirname(__DIR__).'/schema.sql'))) as $sql) {
    if(trim($sql)!=='') { $testDB->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$sql)); }
}
function check($ok,$why) { if(!$ok){throw new RuntimeException($why);} }
$id=AutoDialerService::createCampaign(['name'=>'Regression','dest_type'=>'extension','dest_id'=>'101','max_concurrent'=>1,'numbers_bulk'=>"15550001111\n15550002222"]);
check(str_contains($generatedDialplan,'__AUTODIAL_ATTEMPT'),'Attempt not inherited');
check(str_contains($generatedDialplan,'hangup_handler_push'),'Hangup handler missing');
AutoDialerService::setCampaignStatus($id,'running');
check(AutoDialerService::tickCampaigns()===1,'First call not originated');
$num=(int)$testDB->query("SELECT id FROM plg_autodial_numbers WHERE status='dialing'")->fetchColumn();
AutoDialerService::handleCallback(['num_id'=>$num,'attempt'=>1,'event'=>'answered','amd_status'=>'HUMAN']);
check(AutoDialerService::tickCampaigns()===0,'Live answered slot reused');
foreach (['answered','hangup','dial_failed'] as $event) {
    $r=AutoDialerService::handleCallback(['num_id'=>$num,'attempt'=>0,'event'=>$event]);
    check($r['state']==='stale_attempt','Old callback accepted');
}
AutoDialerService::handleCallback(['num_id'=>$num,'attempt'=>1,'event'=>'hangup','duration'=>3]);
check(AutoDialerService::tickCampaigns()===1,'Hangup did not release slot');
$handler=require dirname(__DIR__).'/public/handler.php';
$_SERVER['REMOTE_ADDR']='203.0.113.5';$_GET=[];$_POST=[];
ob_start();$handler('/callback',[]);$response=ob_get_clean();
check(http_response_code()===403,'External callback allowed');
check(str_contains($response,'Loopback access only'),'External callback did not reject');
$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_X_FORWARDED_FOR']='127.0.0.1';
$_GET=['num_id'=>$num,'attempt'=>1,'event'=>'hangup'];
foreach (['', 'wrong'] as $token) {
    $_SERVER['HTTP_X_AUTODIALER_TOKEN']=$token;
    ob_start();$handler('/callback',[]);ob_end_clean();
    check(http_response_code()===403,'Missing/wrong token accepted');
}
$token=PluginManager::getSetting('auto-dialer','_callback_token');
check(preg_match('/^[a-f0-9]{64}$/D',$token)===1,'Callback token not generated');
AutoDialerService::syncDialplan();check($token===PluginManager::getSetting('auto-dialer','_callback_token'),'Token changed during normal sync');
$_SERVER['HTTP_X_AUTODIALER_TOKEN']=$token;http_response_code(200);
ob_start();$handler('/callback',[]);$response=ob_get_clean();
check(!empty(json_decode($response,true)['success']),'Authenticated loopback request failed');
unset($_SERVER['HTTP_X_AUTODIALER_TOKEN']);
$_SERVER['HTTP_AUTHORIZATION']='Basic '.base64_encode('autodialer:'.$token);
ob_start();$handler('/callback',[]);$response=ob_get_clean();
check(!empty(json_decode($response,true)['success']),'Basic loopback authentication failed');
check(str_contains($generatedDialplan,'Set(CURLOPT(userpwd)=)'),'Callback credentials not cleared');
$named=AutoDialerService::createCampaign(['name'=>'Sale (Q4), 50% ${X}','dest_type'=>'extension','dest_id'=>'101','max_concurrent'=>1,'numbers_bulk'=>"15550003333"]);
AutoDialerService::syncDialplan();
check(str_contains($generatedDialplan,"NoOp(AutoDialer Campaign #{$named}: Sale Q4 50 X)\n"),'Campaign name not reduced to plain text in the dialplan');
// AI campaigns: the person called reaches the AI as the caller; what it understood is kept.
check(str_contains(end(AsteriskHelper::$commands),'extension route_'.$id.'_'),'route extension missing');
check(preg_match('/route_\d+_\d+_\d+_15550002222@aipbx-autodial-amd$/',end(AsteriskHelper::$commands))===1,'Phone not carried to the answered leg');
check(str_contains($generatedDialplan,'Set(__AUTODIAL_PHONE=${FILTER(0-9+,${CUT(EXTEN,_,5)})})'),'Answered leg does not know the phone');
check(!str_contains($generatedDialplan,'Set(CALLERID(num)=${AUTODIAL_PHONE})'),'Caller changed for a non-AI destination');
$ai=AutoDialerService::createCampaign(['name'=>'Confirm','dest_type'=>'ai_app','dest_id'=>'3','max_concurrent'=>1,'numbers_bulk'=>"15550004444"]);
AutoDialerService::syncDialplan();
check(str_contains($generatedDialplan,'ExecIf($["${AUTODIAL_PHONE}" != ""]?Set(CALLERID(num)=${AUTODIAL_PHONE}))'),'AI destination does not see the person called');
check(str_contains($generatedDialplan,'&ai=${FILTER(a-z0-9_-,${AI_INTENT})}'),'AI result not reported');
check(in_array('ai_agent',AutoDialerService::ALLOWED_DEST_TYPES,true),'External agent not a campaign destination');
$testDB->exec("UPDATE plg_autodial_campaigns SET status='paused'");
AutoDialerService::setCampaignStatus($ai,'running');
AutoDialerService::tickCampaigns();
$an=(int)$testDB->query("SELECT id FROM plg_autodial_numbers WHERE campaign_id={$ai}")->fetchColumn();
AutoDialerService::handleCallback(['num_id'=>$an,'attempt'=>1,'event'=>'answered','amd_status'=>'HUMAN']);
AutoDialerService::handleCallback(['num_id'=>$an,'attempt'=>1,'event'=>'hangup','duration'=>20]);          // the other leg first
AutoDialerService::handleCallback(['num_id'=>$an,'attempt'=>1,'event'=>'hangup','duration'=>20,'ai'=>'confirm']);
check($testDB->query("SELECT call_result FROM plg_autodial_numbers WHERE id={$an}")->fetchColumn()==='AI:confirm','AI result not stored');
AutoDialerService::handleCallback(['num_id'=>$an,'attempt'=>1,'event'=>'hangup','ai'=>'x;DROP']);
check($testDB->query("SELECT call_result FROM plg_autodial_numbers WHERE id={$an}")->fetchColumn()==='AI:confirm','Bad AI value stored');
echo "OK: package bootstrap, real schema, campaign pacing, stale callbacks, hangup, endpoint access, campaign names and AI campaigns\n";
