<?php
require dirname(__DIR__,4).'/wp-load.php';
use Global360\Platform\Reviews\PatientReviews as R;
use Global360\Platform\Reviews\Admin;
if(!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))exit('Local only');
function trash_expect($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "PASS: $msg\n";}
$clinic=0;$ids=[];$table=R::table();$repo=global360_platform()->patient_reviews();
$admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
$act=static function($id,$action,$confirm=false){return R::trash_action($id,$action,wp_create_nonce('global360_review_'.$action.'_'.$id),$confirm);};
$render=static function(){ob_start();try{Admin::render();return ob_get_contents();}finally{ob_end_clean();}};
try {
 wp_set_current_user($admin);
 $clinic=wp_insert_post(['post_type'=>'clinic','post_status'=>'publish','post_title'=>'Trash test '.uniqid()]);
 foreach(['pending','approved','rejected','hidden'] as $state){
  $id=R::store(['clinic-id'=>(string)$clinic,'review-doctor'=>'0','review-rating'=>'5','review-display-name'=>'Trash fixture','review-email'=>'trash@example.test','review-message'=>'Fixture '.$state,'review-consent'=>'1'],0,'Test consent');$ids[]=$id;
  if($state!=='pending')R::moderate($id,['approved'=>'approve','rejected'=>'reject','hidden'=>'hide'][$state],wp_create_nonce('global360_review_'.$id));
  $before=R::get($id);
  trash_expect(is_wp_error($act($id,'delete',true)),'Cannot permanently delete active review');
  trash_expect(is_wp_error(R::trash_action($id,'trash','bad')),'Invalid trash nonce denied');
  wp_set_current_user(0);foreach(['trash','restore','delete'] as $a)trash_expect(is_wp_error($act($id,$a,true)),'Unauthorized '.$a.' denied');wp_set_current_user($admin);
  $cache=WP_CONTENT_DIR.'/cache/all/patient-reviews/trash-test';wp_mkdir_p($cache);file_put_contents($cache.'/index.html','fixture');
  trash_expect(true===$act($id,'trash'),'Move '.$state.' to Trash');
  trash_expect(!file_exists($cache.'/index.html'),'Trash invalidates public page cache');
  trash_expect($repo->query(['clinic_id'=>$clinic])['total']===0,'Trashed review absent from approved-only query');
  trash_expect(is_wp_error($act($id,'trash')),'Repeated trash does not overwrite prior status');
  trash_expect(is_wp_error(R::moderate($id,'approve',wp_create_nonce('global360_review_'.$id))),'Trashed review cannot bypass Restore through approval');
  $_GET=['review_id'=>$id,'confirm_delete'=>'1'];$html=$render();trash_expect(strpos($html,'name="confirm_delete"')!==false && strpos($html,'required')!==false && strpos($html,'name="moderation" value="approve"')===false,'Explicit confirmation and no moderation bypass on trashed detail');
  $_GET=['view'=>'trash'];$html=$render();trash_expect(strpos($html,'Restore')!==false && strpos($html,'Delete Permanently')!==false,'Trash view includes row actions');
  trash_expect(is_wp_error($act($id,'delete')),'Permanent deletion requires server-side confirmation');
  trash_expect(is_wp_error(R::trash_action($id,'delete',wp_create_nonce('global360_review_restore_'.$id),true)),'Restore nonce cannot authorize deletion');
  wp_mkdir_p($cache);file_put_contents($cache.'/index.html','fixture');
  trash_expect(true===$act($id,'restore'),'Restore succeeds');
  trash_expect(!file_exists($cache.'/index.html'),'Restore invalidates public page cache');
  $after=R::get($id);trash_expect($after->status===$state,'Restore preserves '.$state.' status');
  foreach(['review_text','rating','email','consent','submitted_at'] as $field)trash_expect($before->$field===$after->$field,'Immutable '.$field.' preserved');
  trash_expect($repo->query(['clinic_id'=>$clinic])['total']===($state==='approved'?1:0),'Only restored approved review becomes public');
  $_GET=['review_id'=>$id];trash_expect(strpos($render(),'Move to Trash')!==false,'Active detail offers Move to Trash');
  $act($id,'trash');wp_mkdir_p($cache);file_put_contents($cache.'/index.html','fixture');trash_expect(true===$act($id,'delete',true) && !R::get($id),'Confirmed permanent deletion removes only fixture');
  trash_expect(!file_exists($cache.'/index.html'),'Permanent deletion invalidates cache');
 }
 $_GET=[];trash_expect(strpos($render(),'Trash')!==false,'Default list exposes Trash view');
} finally {foreach($ids as $id)$wpdb->delete($table,['id'=>$id]);if($clinic)wp_delete_post($clinic,true);wp_set_current_user(0);$_GET=[];}
