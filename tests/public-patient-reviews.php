<?php
require dirname( __DIR__, 4 ) . '/wp-load.php';
use Global360\Platform\Reviews\PatientReviews as Reviews;
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { exit('Local only'); }
function public_expect($ok,$message) { if(!$ok)throw new RuntimeException($message); echo "PASS: $message\n"; }
function public_http($path) { $r=wp_remote_get(home_url($path),['timeout'=>20,'redirection'=>0]); public_expect(!is_wp_error($r),'Local HTTP request'); return $r; }
$posts=[]; $ids=[]; $table=Reviews::table(); $repo=global360_platform()->patient_reviews();
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']); wp_set_current_user((int)$admins[0]);
$marker='Public review fixture '.uniqid();
try {
 foreach(['clinic','doctor'] as $type) { $posts[$type]=wp_insert_post(['post_type'=>$type,'post_status'=>'publish','post_title'=>$type==='clinic'?'Review Test Clinic':'Review Test Doctor']); }
 global360_platform()->relationships()->set_clinics_for_doctor($posts['doctor'],[$posts['clinic']]);
 $posts['second_clinic']=wp_insert_post(['post_type'=>'clinic','post_status'=>'publish','post_title'=>'Second Review Clinic']);
 global360_platform()->relationships()->set_clinics_for_doctor($posts['doctor'],[$posts['clinic'],$posts['second_clinic']]);
 $base=['clinic-id'=>(string)$posts['clinic'],'review-doctor'=>(string)$posts['doctor'],'review-rating'=>'5','review-display-name'=>'Test Patient','review-email'=>'never-public@example.test','review-consent'=>'1'];
 for($i=0;$i<12;$i++) {
  $id=Reviews::store($base+['review-message'=>"$marker approved $i"],17316,'Private consent test'); public_expect(!is_wp_error($id),'Test submission saved'); $ids[]=$id;
  public_expect(true===Reviews::moderate($id,'approve',wp_create_nonce('global360_review_'.$id)),'Approval succeeds');
 }
 foreach(['pending','rejected','hidden'] as $state) {
  $id=Reviews::store(array_replace($base,['review-doctor'=>'0','review-message'=>"$marker $state"]),17316,'Private consent test'); $ids[]=$id;
  if($state!=='pending')Reviews::moderate($id,$state==='rejected'?'reject':'hide',wp_create_nonce('global360_review_'.$id));
 }
 $first=$repo->query(['clinic_id'=>$posts['clinic'],'per_page'=>5]);
 $second=$repo->query(['clinic_id'=>$posts['clinic'],'per_page'=>5,'page'=>2]);
 public_expect($first['total']===12 && $first['pages']===3 && count($first['items'])===5,'Approved-only count and pagination');
 public_expect(array_column($first['items'],'id')===array_reverse(array_slice($ids,7,5)),'Newest-first ordering has stable ID tie-break');
 public_expect(!array_intersect(array_column($first['items'],'id'),array_column($second['items'],'id')),'Pages do not overlap');
 $allowed=['id','clinic_id','doctor_id','display_name','rating','review_text','submitted_at','clinic_name','doctor_name','clinic_url','doctor_url'];
 foreach($first['items'] as $item) {
  public_expect(!array_diff(array_keys($item),$allowed),'Public projection contains only allowed fields');
  public_expect($item['clinic_id']===$posts['clinic'] && $item['doctor_id']===$posts['doctor'] && $item['doctor_name']==='Review Test Doctor','Clinic and doctor associations retained');
 }
 public_expect($first['items'][0]['clinic_url']===get_permalink($posts['clinic']) && $first['items'][0]['doctor_url']===get_permalink($posts['doctor']),'Stored IDs resolve correct permalinks for a doctor with multiple clinics');
 $r=public_http('/patient-reviews/'); $html=wp_remote_retrieve_body($r);
 public_expect(200===wp_remote_retrieve_response_code($r),'Public page returns 200');
 foreach(['clinic','doctor'] as $type) {
  public_expect(strpos($html,'href="'.esc_url(get_permalink($posts[$type])).'"')!==false, $type.' permalink rendered');
  public_expect(200===wp_remote_retrieve_response_code(public_http(wp_parse_url(get_permalink($posts[$type]),PHP_URL_PATH))),$type.' destination is published');
 }
 public_expect(strpos($html,$marker.' approved')!==false,'Approved review visible via anonymous HTTP');
 foreach(['pending','rejected','hidden'] as $state)public_expect(strpos($html,"$marker $state")===false,"$state excluded from public HTML");
 foreach(['never-public@example.test','Private consent test','moderator_id','notification_status'] as $private)public_expect(strpos($html,$private)===false,'Private fields absent from HTML');
 public_expect(strpos($html,'Patient reviews pages')!==false && strpos($html,'aria-current="page"')!==false && strpos($html,'review_page=2')!==false,'Accessible pagination present');
 public_expect(strpos($html,'patient-reviews-heading')===false && strpos($html,'patient-reviews-cta')===false,'No hardcoded hero or CTA; editor owns introductory content');
 public_expect(strpos((string)wp_remote_retrieve_header($r,'cache-control'),'no-cache')!==false,'HTTP response prevents stale browser caching');
 $r=public_http('/patient-reviews/?review_page=2'); public_expect(200===wp_remote_retrieve_response_code($r),'Second page accessible');
 // Seed a harmless cached descendant, proving the installed cache purge handles page trees.
 $cache_dir=WP_CONTENT_DIR.'/cache/all/patient-reviews/public-review-test';
 wp_mkdir_p($cache_dir); file_put_contents($cache_dir.'/index.html','test cache');
 $hide_id=$ids[11]; Reviews::moderate($hide_id,'hide',wp_create_nonce('global360_review_'.$hide_id));
 public_expect(!file_exists($cache_dir.'/index.html'),'Hide purges existing WP Fastest Cache descendants');
 $r=public_http('/patient-reviews/'); public_expect(strpos(wp_remote_retrieve_body($r),"$marker approved 11")===false,'Hide removes review on next anonymous request');
 Reviews::moderate($hide_id,'approve',wp_create_nonce('global360_review_'.$hide_id));
 $r=public_http('/patient-reviews/'); public_expect(strpos(wp_remote_retrieve_body($r),"$marker approved 11")!==false,'Approval restores public visibility');
 // Simulate legacy unsanitized content: the public view must still escape it.
 $wpdb->update($table,['review_text'=>'<img src=x onerror=alert(1)> & text'],['id'=>$hide_id]);
 $r=public_http('/patient-reviews/'); $html=wp_remote_retrieve_body($r);
 public_expect(strpos($html,'&lt;img src=x onerror=alert(1)&gt; &amp; text')!==false && strpos($html,'<img src=x onerror=alert(1)>')===false,'Review output is escaped');
 $wpdb->update($table,['review_text'=>"$marker approved 11"],['id'=>$hide_id]);
 $no_link=static function($url,$post) use ($posts) { return in_array($post->ID,[$posts['clinic'],$posts['doctor']],true) ? '' : $url; };
 add_filter('post_type_link',$no_link,10,2);
 $no_page=$repo->query(['clinic_id'=>$posts['clinic']])['items'][0];
 public_expect($no_page['clinic_url']==='' && $no_page['doctor_url']==='' && $no_page['doctor_name']==='Review Test Doctor','No public permalink retains names without links');
 remove_filter('post_type_link',$no_link,10);
 $wpdb->update($table,['doctor_id'=>0],['id'=>$hide_id]);
 public_expect($repo->query(['clinic_id'=>$posts['clinic']])['items'][0]['doctor_url']==='','Clinic overall has no doctor link');
 $wpdb->update($table,['doctor_id'=>PHP_INT_MAX],['id'=>$hide_id]);
 public_expect($repo->query(['clinic_id'=>$posts['clinic']])['items'][0]['doctor_url']==='','Missing doctor has no link');
 $wpdb->update($table,['doctor_id'=>$posts['doctor']],['id'=>$hide_id]);
 wp_update_post(['ID'=>$posts['doctor'],'post_status'=>'draft']);
 $result=$repo->query(['clinic_id'=>$posts['clinic']]); public_expect($result['items'][0]['doctor_name']==='' && $result['items'][0]['doctor_url']==='','Unpublished doctor name not disclosed');
 wp_update_post(['ID'=>$posts['doctor'],'post_status'=>'publish']);
 wp_update_post(['ID'=>$posts['clinic'],'post_status'=>'draft']); public_expect($repo->query(['clinic_id'=>$posts['clinic']])['total']===0,'Unpublished clinic names not disclosed');
 wp_update_post(['ID'=>$posts['clinic'],'post_status'=>'publish']);
 $wpdb->update($table,['clinic_id'=>PHP_INT_MAX],['id'=>$hide_id]);
 public_expect($repo->query(['clinic_id'=>PHP_INT_MAX])['total']===0,'Missing clinic preserves existing privacy exclusion');
 $wpdb->update($table,['clinic_id'=>$posts['clinic']],['id'=>$hide_id]);
 // Exercise the actual template's empty branch without changing any existing reviews.
 $empty_filter=static function($sql) { return strpos($sql,"WHERE r.status='approved'")!==false ? str_replace("WHERE r.status='approved'","WHERE r.status='approved' AND 1=0",$sql) : $sql; };
 add_filter('query',$empty_filter); $_GET=[];
 ob_start(); try { include get_template_directory().'/page-patient-reviews.php'; $empty_html=ob_get_contents(); } finally { ob_end_clean(); remove_filter('query',$empty_filter); }
 public_expect(strpos($empty_html,'No patient reviews have been published yet.')!==false,'Empty state rendered by actual theme template');
 if(in_array('--preview',$argv,true)) { echo "Preview fixtures ready. Press Enter after browser checks to remove them.\n"; fgets(STDIN); }
} finally {
 foreach($ids as $id)$wpdb->delete($table,['id'=>$id],['%d']);
 foreach(array_reverse($posts) as $id)wp_delete_post($id,true);
 \Global360\Platform\Reviews\PublicReviewRepository::purge_page();
 wp_set_current_user(0);
}
