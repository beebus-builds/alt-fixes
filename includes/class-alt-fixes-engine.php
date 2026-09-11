<?php
/** Core alt-text generation engine. */
if (!defined('ABSPATH')) exit;
require_once ALT_FIXES_PATH.'includes/class-alt-fixes-learning.php';
class Alt_Fixes_Engine {
    public static function suggest($attachment_id) {
        $attachment_id=absint($attachment_id);$file=get_attached_file($attachment_id);
        if(!$file||!file_exists($file))return new WP_Error('missing_image','Image file could not be found.',['status'=>404]);
        $settings=get_option(ALT_FIXES_OPTION,[]);$provider=self::provider($settings['provider']??'openai',$settings);$context=Alt_Fixes_Context::for_attachment($attachment_id);$context['learning']=Alt_Fixes_Learning::for_prompt($context);
        $bytes=file_get_contents($file);if($bytes===false)return new WP_Error('read_error','The image file could not be read.',['status'=>500]);
        $mime=get_post_mime_type($attachment_id)?:'image/jpeg';$data_url='data:'.$mime.';base64,'.base64_encode($bytes);if(is_wp_error($provider))return$provider;
        $result=$provider->suggest($data_url,$context);if(is_wp_error($result))return$result;
        $analysis=self::normalize_analysis($result);$alt=sanitize_text_field($result['alt']??'');$gate=self::quality_gate($alt,$analysis,$context);$analysis['approval']=$gate;
        if($gate['auto_approvable'])$analysis['review_reason']='';elseif($analysis['review_reason']==='')$analysis['review_reason']=$gate['reason'];
        if($alt===''&&!$analysis['decorative'])return new WP_Error('empty_suggestion','The AI provider returned an empty suggestion.',['status'=>502]);
        $analysis['decision_trace']=self::decision_trace($analysis,$context);
        update_post_meta($attachment_id,'_alt_fixes_suggestion',$alt);update_post_meta($attachment_id,'_alt_fixes_analysis',$analysis);
        return ['id'=>$attachment_id,'suggestion'=>$alt,'analysis'=>$analysis,'context'=>$context];
    }
    private static function normalize_analysis(array $result){$flags=[];foreach((array)($result['quality_flags']??[]) as$flag){$flag=sanitize_key($flag);if($flag!==''&&!in_array($flag,$flags,true))$flags[]=$flag;}return['purpose'=>sanitize_key($result['purpose']??'informative'),'decorative'=>!empty($result['decorative']),'confidence'=>isset($result['confidence'])?max(0,min(1,(float)$result['confidence'])):null,'quality_score'=>isset($result['quality_score'])?max(0,min(100,(int)$result['quality_score'])):null,'quality_flags'=>$flags,'ocr_text'=>sanitize_textarea_field($result['ocr_text']??''),'review_reason'=>sanitize_text_field($result['review_reason']??''),'evidence'=>sanitize_text_field($result['evidence']??''),'model'=>sanitize_text_field($result['model']??''),'generated_at'=>current_time('mysql',true)];}
    private static function decision_trace(array $analysis,array $context){$parent=$context['parent']??[];$usages=[];foreach((array)($context['usages']??[]) as$usage){$usages[]=['title'=>sanitize_text_field($usage['title']??''),'type'=>sanitize_key($usage['type']??''),'headings'=>array_values(array_filter(array_map('sanitize_text_field',(array)($usage['headings']??[]))))];}return['image_evidence'=>$analysis['evidence'],'purpose'=>$analysis['purpose'],'page_context'=>['attachment_title'=>sanitize_text_field($context['attachment_title']??''),'caption'=>sanitize_text_field($context['caption']??''),'parent'=>['title'=>sanitize_text_field($parent['title']??''),'type'=>sanitize_key($parent['type']??''),'headings'=>array_values(array_filter(array_map('sanitize_text_field',(array)($parent['headings']??[]))))],'usages'=>$usages],'ocr'=>$analysis['ocr_text'],'learned_guidance'=>is_array($context['learning']??null)?($context['learning']['site_rules']??[]):[],'accessibility_checks'=>['quality_score'=>$analysis['quality_score'],'confidence'=>$analysis['confidence'],'quality_flags'=>$analysis['quality_flags'],'approval'=>$analysis['approval']??[],'review_reason'=>$analysis['review_reason']]];}
    private static function quality_gate($alt,array &$analysis,array $context){$score=(int)($analysis['quality_score']??50);$confidence=(float)($analysis['confidence']??0);$purpose=$analysis['purpose'];$flags=$analysis['quality_flags'];$reasons=[];
        if($analysis['decorative']){$analysis['quality_score']=100;$analysis['confidence']=max($confidence,.85);return['auto_approvable'=>true,'decision'=>'pass','reason'=>'Decorative image: use a null alt value.','threshold'=>90,'score'=>100];}
        if($alt===''){$reasons[]='No usable alt text was generated.';}
        if(preg_match('/^(?:an? |the )?(?:image|picture|photo|graphic)\s+(?:of|showing)\b/i',$alt)){$flags[]='generic_opening';$score-=10;$reasons[]='Alt text starts with a redundant image label.';}
        $words=preg_split('/\s+/',trim($alt));$word_count=count(array_filter($words));if($word_count<3){$flags[]='too_short';$score-=15;$reasons[]='Alt text is too short to establish the image purpose.';}elseif($word_count>25){$flags[]='excessive_length';$score-=10;$reasons[]='Alt text is longer than a concise alternative should normally be.';}
        if(in_array($purpose,['chart','diagram','complex'],true)){$flags[]='complex_visual';$score-=5;$reasons[]='Complex visual requires a separate detailed text equivalent.';}
        if($confidence<.85){$flags[]='low_confidence';$score-=10;$reasons[]='Model confidence is below the automatic approval threshold.'}
        if(!empty($analysis['ocr_text'])&&in_array($purpose,['text','logo','chart','diagram','screenshot'],true)&&strlen($analysis['ocr_text'])>0){$flags[]='ocr_present';}
        if(in_array('unreadable_text',$flags,true)||in_array('ambiguous_purpose',$flags,true)||in_array('hallucination_risk',$flags,true)||in_array('context_conflict',$flags,true)){$score-=10;$reasons[]='The analysis contains a high-risk quality flag.';}
        $score=max(0,min(100,$score));$analysis['quality_score']=$score;$analysis['quality_flags']=array_values(array_unique($flags));
        $hard_review=in_array($purpose,['chart','diagram','complex'],true)||!empty(array_intersect($analysis['quality_flags'],['unreadable_text','ambiguous_purpose','hallucination_risk','context_conflict']));
        $auto=$score>=90&&$confidence>=.90&&!$hard_review&&$word_count>=3&&$word_count<=25;
        if(!$auto&&$reasons===[])$reasons[]='Quality score does not meet the automatic approval threshold.';
        return['auto_approvable'=>$auto,'decision'=>$auto?'pass':'review','reason'=>implode(' ',$reasons),'threshold'=>90,'score'=>$score];}
    private static function provider($name,array $settings){switch($name){case'openai':return new Alt_Fixes_OpenAI_Provider($settings['api_key']??'',$settings['model']??'gpt-5.6-luna');default:return new WP_Error('unsupported_provider','The selected AI provider is not implemented.',['status'=>400]);}}
}
