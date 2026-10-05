<?php
/** Local-only real CF7 forms; temporary fixtures are removed and mail intercepted. */
require dirname(__DIR__, 4) . '/wp-load.php';
use Global360\Platform\Reviews\ContactFormIntegration as Integration;
use Global360\Platform\Reviews\PatientReviews as Reviews;
if (!in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1'], true)) exit('Local only');
define('REST_REQUEST', true);
function signature_expect($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
$posts=[]; $table=Reviews::table(); $forms=[]; $clinic=0;
$reset=new ReflectionProperty(WPCF7_Submission::class,'instance');
if(PHP_VERSION_ID<80100)$reset->setAccessible(true);
$markup='[select* clinic-id "Choose"] [select* review-doctor "Choose"] [radio review-rating "1" "2" "3" "4" "5"] [text* review-display-name] [email* review-email] [textarea* review-message] [acceptance review-consent]Allow publication[/acceptance] [submit "Send"]';
$mail=static function(){return true;}; add_filter('pre_wp_mail',$mail);
try {
 $clinic=wp_insert_post(['post_type'=>'clinic','post_status'=>'publish','post_title'=>'Signature fixture clinic']); $posts[]=$clinic;
 $doctor=wp_insert_post(['post_type'=>'doctor','post_status'=>'publish','post_title'=>'Signature fixture doctor']); $posts[]=$doctor;
 global360_platform()->relationships()->set_clinics_for_doctor($doctor,[$clinic]);
 foreach([$markup,$markup,'[text your-name] [submit "Send"]'] as $i=>$content){
  $form=WPCF7_ContactForm::get_template(); $form->set_title('Same ordinary title');
  $form->set_properties(['form'=>$content,'mail'=>['active'=>true,'recipient'=>'test@example.test','sender'=>'test@example.test','subject'=>'Local intercepted test','body'=>'Test']]);
  $form->save(); $posts[]=$form->id(); $form=wpcf7_contact_form($form->id()); $forms[]=$form;
  signature_expect(Integration::matches($form)===($i<2),'Field signature determines form '.$form->id().' regardless of shared title');
 }
 signature_expect($forms[0]->id()!==$forms[1]->id() && $forms[0]->hash()!==$forms[1]->hash(),'Review forms have different IDs and hashes');
 foreach(['clinic-id','review-doctor','review-rating','review-display-name','review-email','review-message','review-consent'] as $missing){
  $form=WPCF7_ContactForm::get_template();
  $form->set_properties(['form'=>str_replace($missing,'other-field',$markup).' '.$missing]);
  signature_expect(!Integration::matches($form),'Missing actual tag '.$missing.' does not match even with name in plain text');
 }
 $form=WPCF7_ContactForm::get_template();$form->set_properties(['form'=>str_replace('[email* review-email]','[[email* review-email]]',$markup)]);
 signature_expect(!Integration::matches($form),'Escaped pseudo-tag does not satisfy signature');
 // Same object edits must not reuse a stale ID-based detection result.
 $form->set_properties(['form'=>$markup]);signature_expect(Integration::matches($form),'Markup changes re-evaluate signature');
 foreach($forms as $i=>$form){
  wp_dequeue_script('global360-review-form');wp_dequeue_style('global360-patient-reviews');$_POST=[];
  $html=do_shortcode('[contact-form-7 id="'.$form->hash().'"]');
  signature_expect((strpos($html,'global360-patient-review-form')!==false)===($i<2),'Scoped class only on detected form '.$form->id());
  signature_expect(wp_script_is('global360-review-form','enqueued')===($i<2),'Script scoped to detected form '.$form->id());
  signature_expect(wp_style_is('global360-patient-reviews','enqueued')===($i<2),'Theme stylesheet uses detection outside page-slug context '.$form->id());
  if($i<2){
   signature_expect(strpos($html,'value="'.$clinic.'"')!==false && strpos($html,'disabled')!==false,'Dynamic clinic choices and initially disabled doctor');
   $_POST=['clinic-id'=>(string)$clinic];$selected=do_shortcode('[contact-form-7 id="'.$form->hash().'"]');
   signature_expect(strpos($selected,'value="'.$doctor.'"')!==false,'Doctor choices use selected clinic');
  }
  $schema=new WP_REST_Response(['rules'=>[['rule'=>'enum','field'=>'review-doctor'],['rule'=>'required','field'=>'review-doctor']]]);
  $request=new WP_REST_Request('GET','/contact-form-7/v1/contact-forms/'.$form->id().'/feedback/schema');
  $schema=\Global360\Platform\Reviews\ReviewForm::browser_schema($schema,null,$request);
  signature_expect(count($schema->get_data()['rules'])===($i<2?1:2),'Dependent browser schema scoped to detected form '.$form->id());
  $data=['clinic-id'=>(string)$clinic,'review-doctor'=>(string)$doctor,'review-rating'=>'5','review-display-name'=>'Signature Test','review-email'=>'signature@example.test','review-message'=>'Fixture '.$form->id(),'review-consent'=>'1','your-name'=>'Ordinary contact'];
  $submit=static function($values)use($form,$reset){$reset->setValue(null,null);$_POST=wp_slash($values+['_wpcf7_unit_tag'=>'wpcf7-f'.$form->id().'-o1']);$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_USER_AGENT']='Local signature test';return wpcf7_contact_form($form->id())->submit(['skip_mail'=>false]);};
  if($i<2){
   foreach(['clinic-id'=>'999999999','review-doctor'=>'999999999','review-consent'=>''] as $field=>$value){
    $result=$submit(array_replace($data,[$field=>$value]));signature_expect($result['status']==='validation_failed','Server rejects invalid '.$field.' for form '.$form->id());
   }
  }
  $result=$submit($data);signature_expect($result['status']==='mail_sent','Real CF7 submission succeeds for form '.$form->id());
  $submit($data);
  $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE form_id=%d",$form->id()));
  signature_expect($count===($i<2?1:0),'Storage and duplicate protection scoped to signature '.$form->id());
  if($i<2)signature_expect($wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE form_id=%d",$form->id()))==='pending','New review remains private pending moderation');
 }
} finally {
 remove_filter('pre_wp_mail',$mail);
 foreach($forms as $form)$wpdb->delete($table,['form_id'=>$form->id()],['%d']);
 foreach(array_reverse($posts) as $id)wp_delete_post($id,true);
}
