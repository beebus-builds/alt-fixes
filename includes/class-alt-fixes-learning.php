<?php
/** Lightweight site-local learning and analytics from human alt-text corrections. */
if (!defined('ABSPATH')) exit;
class Alt_Fixes_Learning {
    const OPTION='alt_fixes_learning'; const LIMIT=100;
    public static function boot(){add_action('updated_post_meta',[__CLASS__,'capture'],10,4);add_action('added_post_meta',[__CLASS__,'capture'],10,4);}
    public static function capture($meta_id,$post_id,$meta_key,$meta_value){
        if($meta_key!=='_wp_attachment_image_alt'||!wp_attachment_is_image($post_id))return;
        $approved=sanitize_text_field((string)$meta_value);if($approved==='')return;
        $generated=sanitize_text_field((string)get_post_meta($post_id,'_alt_fixes_suggestion',true));if($generated==='')return;
        $analysis=get_post_meta($post_id,'_alt_fixes_analysis',true);$analysis=is_array($analysis)?$analysis:[];
        $entry=['generated'=>$generated,'approved'=>$approved,'edited'=>self::normalize($generated)!==self::normalize($approved),'purpose'=>sanitize_key($analysis['purpose']??'informative'),'flags'=>array_slice(array_map('sanitize_key',(array)($analysis['quality_flags']??[])),0,8),'at'=>current_time('mysql',true)];
        $learning=get_option(self::OPTION,[]);if(!is_array($learning))$learning=[];$learning['corrections']=array_values((array)($learning['corrections']??[]));$learning['corrections'][]=$entry;if(count($learning['corrections'])>self::LIMIT)$learning['corrections']=array_slice($learning['corrections'],-self::LIMIT);
        $learning['stats']=['approvals'=>(int)($learning['stats']['approvals']??0)+1,'edits'=>(int)($learning['stats']['edits']??0)+($entry['edited']?1:0),'auto_approvals'=>(int)($learning['stats']['auto_approvals']??0)];update_option(self::OPTION,$learning,false);
    }
    public static function record_auto_approval(){
        $learning=get_option(self::OPTION,[]);if(!is_array($learning))$learning=[];$stats=(array)($learning['stats']??[]);$stats['approvals']=(int)($stats['approvals']??0);$stats['edits']=(int)($stats['edits']??0);$stats['auto_approvals']=(int)($stats['auto_approvals']??0)+1;$learning['stats']=$stats;update_option(self::OPTION,$learning,false);
    }
    public static function analytics(){
        $learning=get_option(self::OPTION,[]);$rows=array_values((array)($learning['corrections']??[]));$stats=(array)($learning['stats']??[]);$approvals=(int)($stats['approvals']??0);$edits=(int)($stats['edits']??0);$auto=(int)($stats['auto_approvals']??0);$purpose=[];$flags=[];$recent=[];
        foreach(array_reverse($rows) as $row){if(!is_array($row))continue;$p=sanitize_key($row['purpose']??'informative');$purpose[$p]=($purpose[$p]??0)+1;foreach((array)($row['flags']??[]) as $f){$f=sanitize_key($f);if($f)$flags[$f]=($flags[$f]??0)+1;}if(count($recent)<10)$recent[]=['edited'=>!empty($row['edited']),'purpose'=>$p,'at'=>sanitize_text_field($row['at']??'')];}
        arsort($purpose);arsort($flags);
        return['sample_size'=>count($rows),'approvals'=>$approvals,'edits'=>$edits,'edit_rate'=>$approvals?round($edits/$approvals*100,1):0,'unchanged_rate'=>$approvals?round(($approvals-$edits)/$approvals*100,1):0,'auto_approvals'=>$auto,'auto_approval_rate'=>($approvals+$auto)?round($auto/($approvals+$auto)*100,1):0,'purpose_breakdown'=>$purpose,'common_flags'=>array_slice($flags,0,8,true),'recent'=>$recent];
    }
    public static function for_prompt($context=[]){
        $learning=get_option(self::OPTION,[]);$rows=array_values((array)($learning['corrections']??[]));if(!$rows)return['available'=>false,'examples'=>[],'stats'=>(array)($learning['stats']??[])];
        $examples=[];foreach(array_reverse($rows) as $row){if(!is_array($row)||empty($row['approved']))continue;$examples[]=['purpose'=>sanitize_key($row['purpose']??'informative'),'generated'=>sanitize_text_field($row['generated']??''),'human_approved'=>sanitize_text_field($row['approved']??''),'edited'=>!empty($row['edited']),'flags'=>array_slice(array_map('sanitize_key',(array)($row['flags']??[])),0,6)];if(count($examples)>=8)break;}
        return['available'=>!empty($examples),'site_context'=>['parent_title'=>sanitize_text_field($context['parent']['title']??''),'headings'=>array_slice(array_map('sanitize_text_field',(array)($context['parent']['headings']??[])),0,5)],'examples'=>$examples,'stats'=>(array)($learning['stats']??[])];
    }
    private static function normalize($value){return preg_replace('/\s+/u',' ',strtolower(trim((string)$value)));}
}
Alt_Fixes_Learning::boot();
