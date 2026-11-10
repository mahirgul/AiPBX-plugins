<?php
/** Standalone regression tests; no core checkout, database, paid API or network needed. */
class PluginManager {
    public static array $items=[];
    public static function kvGet($slug,$collection,$key) { return self::$items[$key] ?? null; }
    public static function kvSet($slug,$collection,$key,$value) { self::$items[$key]=['value'=>$value]; }
}
function check($ok,$what) { if(!$ok){ throw new RuntimeException($what); } }
$handler=require dirname(__DIR__).'/events/handler.php';
$event=['event_id'=>'test','data'=>['extension'=>'101','caller'=>'test','duration'=>3]];
$handler('voicemail.created',$event,[]);
$handler('voicemail.transcribed',$event,[]);
check(PluginManager::$items===[],'Metadata created a summary');
$event['data']['transcript']='Please call me tomorrow.';
$event['data']['summary']='Callback requested tomorrow.';
$handler('voicemail.transcribed',$event,[]);
check(count(PluginManager::$items)===1,'Completed transcript not stored');
$value=reset(PluginManager::$items)['value'];
check($value['transcript']===$event['data']['transcript'],'Transcript lost');
check($value['summary']===$event['data']['summary'],'Summary lost');
$event['data']['summary']='Retry';$handler('voicemail.transcribed',$event,[]);
check(reset(PluginManager::$items)['value']===$value,'Retry replaced completed result');
$event=['event_id'=>'legacy','data'=>['extension'=>'102','summary'=>'Older core summary']];
$handler('voicemail.transcribed',$event,[]);
check(count(PluginManager::$items)===2,'Summary-only payload not supported');
$event=['event_id'=>'fallback','data'=>['extension'=>'103','transcript'=>str_repeat('text ',60)]];
$handler('voicemail.transcribed',$event,[]);
$v=PluginManager::$items[hash('sha256','103:fallback')]['value'];
check(mb_strlen($v['summary'])===200,'Transcript fallback not bounded');
$m=json_decode(file_get_contents(dirname(__DIR__).'/plugin.json'),true,512,JSON_THROW_ON_ERROR);
check($m['requires_aipbx']==='1.72.12' && $m['type']==='community','Manifest compatibility wrong');
check($m['scopes']===[],'Unneeded API privileges');
echo "OK: 9 standalone voicemail checks\n";
