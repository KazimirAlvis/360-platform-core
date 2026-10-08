<?php
require dirname(__DIR__,4).'/wp-load.php';
use Global360\Platform\Reviews\PatientReviews as R;
if(!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))exit('Local only');
define('REST_REQUEST',true);
function mail_expect($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "PASS: $msg\n";}
$posts=[];$forms=[];$mails=[];$table=R::table();
$intercept=static function($pre,$args)use(&$mails){$mails[]=$args;return true;};add_filter('pre_wp_mail',$intercept,10,2);
$reset=new ReflectionProperty(WPCF7_Submission::class,'instance');if(PHP_VERSION_ID<80100)$reset->setAccessible(true);
try{
 $clinic=wp_insert_post(['post_type'=>'clinic','post_status'=>'publish','post_title'=>'Clinic & Care']);$posts[]=$clinic;
 $doctor=wp_insert_post(['post_type'=>'doctor','post_status'=>'publish','post_title'=>'Fallback doctor']);$posts[]=$doctor;
 update_post_meta($doctor,'doctor_name','Dr. Expert & Team');global360_platform()->relationships()->set_clinics_for_doctor($doctor,[$clinic]);
 $markup='[select* clinic-id "Choose"] [select* review-doctor "Choose"] [radio review-rating "5"] [text* review-display-name] [email* review-email] [textarea* review-message] [acceptance review-consent]Publish[/acceptance] [submit "Send"]';
 foreach([false,true] as $html){
  $form=WPCF7_ContactForm::get_template();$form->set_title('Mail fixture');$form->set_properties(['form'=>$markup,'mail'=>['active'=>true,'recipient'=>'test@example.test','sender'=>'test@example.test','subject'=>'Review: [review-clinic-name] / [review-doctor-name]','body'=>'Clinic: [review-clinic-name]; Doctor: [review-doctor-name]; IDs: [clinic-id]/[review-doctor]','use_html'=>$html]]);$form->save();$posts[]=$form->id();$forms[]=$form->id();
  foreach([(string)$doctor,'0'] as $selection){
   $data=['clinic-id'=>(string)$clinic,'review-doctor'=>$selection,'review-rating'=>'5','review-display-name'=>'Mail test','review-email'=>'mail@example.test','review-message'=>'Email fixture','review-consent'=>'1','review-clinic-name'=>'FORGED CLINIC','review-doctor-name'=>'FORGED DOCTOR'];
   $reset->setValue(null,null);$_POST=wp_slash($data+['_wpcf7_unit_tag'=>'wpcf7-f'.$form->id().'-o1']);$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_USER_AGENT']='Local mail tag test';
   $result=wpcf7_contact_form($form->id())->submit(['skip_mail'=>false]);mail_expect($result['status']==='mail_sent','CF7 sends intercepted notification');
   $mail=end($mails);$name=$selection==='0'?'Clinic overall / No specific doctor':'Dr. Expert & Team';
   mail_expect($mail['subject']==='Review: Clinic & Care / '.$name,'Subject resolves trusted names for '.$selection);
   mail_expect(strpos($mail['message'],'Clinic: '.($html?'Clinic &amp; Care':'Clinic & Care').'; Doctor: '.($html?esc_html($name):$name))!==false,'Body resolves trusted names with correct HTML escaping');
   mail_expect(strpos(json_encode($mail),'FORGED')===false,'Browser-supplied names are ignored');
   $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE form_id=%d AND doctor_id=%d",$form->id(),(int)$selection));
   mail_expect((int)$row->clinic_id===$clinic && (int)$row->doctor_id===(int)$selection,'Stored IDs remain unchanged');
  }
  // Invalid clinic/doctor input must fail before notification.
  $before=count($mails);$_POST['clinic-id']='999999999';$reset->setValue(null,null);
  $result=wpcf7_contact_form($form->id())->submit(['skip_mail'=>false]);mail_expect($result['status']==='validation_failed' && count($mails)===$before,'Invalid IDs cannot produce named notifications');
 }
 $other=WPCF7_ContactForm::get_template();$other->set_properties(['form'=>'[text review-clinic-name] [text review-doctor-name]']);
 $tag=new WPCF7_MailTag('[review-clinic-name]','review-clinic-name',[]);
 mail_expect(\Global360\Platform\Reviews\ContactFormIntegration::mail_tag('Ordinary value','Ordinary value',false,$tag)==='Ordinary value','Unrelated contact form replacement is unchanged');
}finally{remove_filter('pre_wp_mail',$intercept,10);foreach($forms as $id)$wpdb->delete($table,['form_id'=>$id]);foreach(array_reverse($posts) as $id)wp_delete_post($id,true);}
