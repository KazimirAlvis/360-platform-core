<?php
/** Verify real custom block rendering; restore all editor content afterward. */
require dirname(__DIR__,4).'/wp-load.php';
if(!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true))exit('Local only');
function editor_expect($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$originals=[]; $temporary=[];
try {
 foreach(['patient-reviews','leave-a-review'] as $slug){
  $p=get_page_by_path($slug); editor_expect((bool)$p,"$slug exists"); $originals[$p->ID]=$p->post_content;
  $temporary[$p->ID]='<!-- wp:global360blocks/page-title-hero {"title":"Editable hero test","subtitle":"Introductory content from the editor","align":"full"} /-->' . "\n" . '<!-- wp:paragraph --><p>Temporary editor content verification.</p><!-- /wp:paragraph -->' . "\n" . $p->post_content;
  wp_update_post(wp_slash(['ID'=>$p->ID,'post_content'=>$temporary[$p->ID]]));
  $r=wp_remote_get(get_permalink($p),['timeout'=>20]); editor_expect(!is_wp_error($r)&&200===wp_remote_retrieve_response_code($r),"$slug HTTP 200");
  $html=wp_remote_retrieve_body($r);
  editor_expect(strpos($html,'wp-block-global360blocks-page-title-hero')!==false && strpos($html,'Editable hero test')!==false,"$slug renders the existing custom hero");
  editor_expect(strpos($html,'Temporary editor content verification.')!==false,"$slug renders editor paragraphs");
  $dom=new DOMDocument(); @$dom->loadHTML($html); $x=new DOMXPath($dom);
  editor_expect($x->query('//h1[contains(@class,"hero-title") and normalize-space(.)="Editable hero test"]')->length===1,"$slug renders the temporary editor hero exactly once");
  editor_expect($x->query('//form[contains(@class,"global360-patient-review-form")]')->length===($slug==='leave-a-review'?1:0),"$slug renders the correct number of review forms");
  if($slug==='patient-reviews')editor_expect(strpos($html,'Temporary editor content verification.')<strpos($html,'class="patient-reviews-listing"'),'Listing follows editable content');
 }
 $f=current(array_filter(WPCF7_ContactForm::find(), [\Global360\Platform\Reviews\ContactFormIntegration::class, 'matches'])); editor_expect($f->prop('messages')['mail_sent_ok']==='Thank you. Your review has been submitted and will be reviewed before publication.','Configured success message matches request');
 if(in_array('--preview',$argv,true)){echo "Temporary hero previews ready. Press Enter after browser checks to restore editor content.\n";fgets(STDIN);}
} finally {
 foreach($originals as $id=>$content){
  if(get_post_field('post_content',$id)===$temporary[$id])wp_update_post(wp_slash(['ID'=>$id,'post_content'=>$content]));
  else fwrite(STDERR,"Page $id changed during preview; preserved newer edits.\n");
 }
}
