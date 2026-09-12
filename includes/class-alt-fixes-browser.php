<?php
/** Browser-local AI provider helpers. */
if (!defined('ABSPATH')) exit;
require_once ALT_FIXES_PATH.'includes/class-alt-fixes-learning.php';
require_once ALT_FIXES_PATH.'includes/class-alt-fixes-discovery.php';

class Alt_Fixes_Browser {
    public static function boot() { add_action('rest_api_init', [__CLASS__, 'routes']); }
    public static function routes() {
        register_rest_route('alt-fixes/v1', '/browser-context/(?P<id>\d+)', ['methods'=>'GET','permission_callback'=>'alt_fixes_rest_permission','callback'=>[__CLASS__,'context']]);
        register_rest_route('alt-fixes/v1', '/browser-suggest/(?P<id>\d+)', ['methods'=>'POST','permission_callback'=>'alt_fixes_rest_permission','callback'=>[__CLASS__,'suggest']]);
        register_rest_route('alt-fixes/v1', '/site-discovery', ['methods'=>'GET','permission_callback'=>'alt_fixes_rest_permission','callback'=>[__CLASS__,'discovery']]);
        register_rest_route('alt-fixes/v1', '/site-pages', ['methods'=>'GET','permission_callback'=>'alt_fixes_rest_permission','callback'=>[__CLASS__,'pages']]);
    }
    public static function context(WP_REST_Request $request) {
        $id=absint($request['id']);
        if(!alt_fixes_validate_image($id)) return new WP_Error('invalid_image','The requested attachment is not an image.',['status'=>400]);
        return rest_ensure_response(['id'=>$id,'image_url'=>wp_get_attachment_image_url($id,'full'),'title'=>get_the_title($id),'context'=>Alt_Fixes_Context::for_attachment($id),'provider'=>'browser-local','model'=>'Xenova/vit-gpt2-image-captioning','purpose_model'=>'Xenova/clip-vit-base-patch32','ocr_model'=>'Xenova/trocr-small-printed']);
    }
    public static function pages(WP_REST_Request $request) {
        $per_page=min(100,max(1,absint($request->get_param('per_page'))?:50));
        $page=max(1,absint($request->get_param('page'))?:1);
        $query=new WP_Query(['post_type'=>'any','post_status'=>'publish','posts_per_page'=>$per_page,'paged'=>$page,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','no_found_rows'=>false]);
        $items=[];
        foreach((array)$query->posts as $id){$url=get_permalink($id);if($url)$items[]=['id'=>(int)$id,'url'=>esc_url_raw($url),'title'=>wp_strip_all_tags(get_the_title($id)),'type'=>get_post_type($id)];}
        return rest_ensure_response(['items'=>$items,'page'=>$page,'per_page'=>$per_page,'total'=>(int)$query->found_posts,'pages'=>(int)$query->max_num_pages]);
    }
    public static function discovery(WP_REST_Request $request) {
        $page=max(1,absint($request->get_param('page'))?:1);
        $per_page=min(500,max(1,absint($request->get_param('per_page'))?:100));
        $result=Alt_Fixes_Discovery::scan($page,$per_page);
        $attachment_count=0;$external_count=0;$builder_count=0;
        foreach((array)$result['items'] as $item){if(!empty($item['attachment_id']))$attachment_count++;else$external_count++;if(in_array('builder-data',(array)($item['sources']??[]),true))$builder_count++;}
        $result['summary']=['attachment_images'=>$attachment_count,'external_images'=>$external_count,'builder_images'=>$builder_count,'supported_builder_detection'=>['Elementor','WPBakery','Divi','Avada','Bricks','Beaver Builder','Oxygen','Kadence','Breakdance','Spectra','GenerateBlocks','custom/unknown']];
        return rest_ensure_response($result);
    }
    public static function suggest(WP_REST_Request $request) {
        $id=absint($request['id']);
        if(!alt_fixes_validate_image($id)) return new WP_Error('invalid_image','The requested attachment is not an image.',['status'=>400]);
        $caption=sanitize_text_field($request->get_param('caption')?:'');
        $ocr_text=sanitize_textarea_field($request->get_param('ocr_text')?:'');
        $purpose=sanitize_key($request->get_param('purpose')?:'informative');
        $allowed=['decorative','logo','product','chart','text','functional','informative'];
        if(!in_array($purpose,$allowed,true)) $purpose='informative';
        $purpose_confidence=max(0,min(1,(float)$request->get_param('purpose_confidence')));
        $purpose_flags=array_values(array_filter(array_map('sanitize_key',(array)$request->get_param('purpose_flags'))));
        $purpose_scores=[];
        foreach((array)$request->get_param('purpose_scores') as $score) if(is_array($score)) $purpose_scores[]=['label'=>sanitize_text_field($score['label']??''),'score'=>max(0,min(1,(float)($score['score']??0)))];
        if($caption==='') return new WP_Error('empty_caption','The local vision model did not return a caption.',['status'=>422]);
        $context=Alt_Fixes_Context::for_attachment($id);$context['learning']=Alt_Fixes_Learning::for_prompt($context);
        $context['browser_purpose']=['purpose'=>$purpose,'confidence'=>$purpose_confidence,'flags'=>$purpose_flags,'scores'=>$purpose_scores];
        $alt=self::caption_to_alt($caption,$ocr_text,$context,$purpose);
        $result=Alt_Fixes_Engine::finalize_browser($id,$alt,$caption,$ocr_text,$context,$purpose,$purpose_confidence,$purpose_flags);
        if(is_wp_error($result)) return $result;
        return rest_ensure_response($result);
    }
    private static function context_phrase(array $context,$purpose) {
        $parent=$context['parent']??[];$values=[$context['attachment_title']??'',$context['caption']??'',$parent['title']??''];
        foreach((array)($context['usages']??[]) as $usage){$values[]=$usage['title']??'';foreach((array)($usage['headings']??[]) as $heading)$values[]=$heading;}
        foreach($values as $value){$value=trim(preg_replace('/\s+/',' ',(string)$value));if($value==='')continue;if(in_array($purpose,['logo','product'],true))return $value;if($purpose==='functional'&&strlen($value)<=80)return $value;}
        return '';
    }
    private static function caption_to_alt($caption,$ocr_text,array $context,$purpose) {
        if($purpose==='decorative') return '';
        $alt=trim(preg_replace('/\s+/',' ',$caption));
        $alt=preg_replace('/^(?:a|an|the)\s+(?:photo|photograph|picture|image|graphic)\s+(?:of|showing)\s+/i','',$alt);
        $alt=preg_replace('/^(?:a|an|the)\s+/i','',$alt);$alt=trim($alt," \t\n\r\0\x0B.,;:-");
        $context_phrase=self::context_phrase($context,$purpose);
        if($context_phrase!==''&&$purpose==='logo'&&stripos($alt,$context_phrase)===false)$alt=$context_phrase.' logo'.($alt!==''?' — '.$alt:'');
        if($context_phrase!==''&&$purpose==='product'&&stripos($alt,$context_phrase)===false)$alt=$context_phrase.($alt!==''?' — '.$alt:'');
        $ocr=trim(preg_replace('/\s+/',' ',$ocr_text));
        if($ocr!==''&&strlen($alt)<12)$alt=$alt!==''?$alt.' — '.mb_substr($ocr,0,80):mb_substr($ocr,0,80);
        $alt=trim(preg_replace('/\s+/',' ',$alt));$words=preg_split('/\s+/',$alt,-1,PREG_SPLIT_NO_EMPTY);if(count($words)>25)$alt=implode(' ',array_slice($words,0,25));if($alt!=='')$alt=strtoupper(substr($alt,0,1)).substr($alt,1);return $alt;
    }
}
Alt_Fixes_Browser::boot();
