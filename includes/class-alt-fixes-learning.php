<?php
/** Lightweight site-local learning, correction analytics, and site-specific generation rules. */
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
        foreach(array_reverse($rows) as $row){if(!is_array($row))continue;$p=sanitize_key($row['purpose']??'informative');$purpose[$p]=($purpose[$p]??0)+1;foreach((array)($row['flags']??[]) as $f){$f=sanitize_key($f);if($f)$flags[$f]=($flags[$f]??0)+1;}if(count($recent)<10)$recent[]=['edited'=>!empty($row['edited']),'purpose'=>$p,'generated'=>sanitize_text_field($row['generated']??''),'approved'=>sanitize_text_field($row['approved']??''),'flags'=>array_slice(array_map('sanitize_key',(array)($row['flags']??[])),0,8),'at'=>sanitize_text_field($row['at']??'')];}
        arsort($purpose);arsort($flags);
        return['sample_size'=>count($rows),'approvals'=>$approvals,'edits'=>$edits,'edit_rate'=>$approvals?round($edits/$approvals*100,1):0,'unchanged_rate'=>$approvals?round(($approvals-$edits)/$approvals*100,1):0,'auto_approvals'=>$auto,'auto_approval_rate'=>($approvals+$auto)?round($auto/($approvals+$auto)*100,1):0,'purpose_breakdown'=>$purpose,'common_flags'=>array_slice($flags,0,8,true),'recent'=>$recent,'site_rules'=>self::rules($rows)];
    }
    public static function for_prompt($context=[]){
        $learning=get_option(self::OPTION,[]);$rows=array_values((array)($learning['corrections']??[]));if(!$rows)return['available'=>false,'examples'=>[],'site_rules'=>[],'stats'=>(array)($learning['stats']??[])];
        $examples=[];foreach(array_reverse($rows) as $row){if(!is_array($row)||empty($row['approved']))continue;$examples[]=['purpose'=>sanitize_key($row['purpose']??'informative'),'generated'=>sanitize_text_field($row['generated']??''),'human_approved'=>sanitize_text_field($row['approved']??''),'edited'=>!empty($row['edited']),'flags'=>array_slice(array_map('sanitize_key',(array)($row['flags']??[])),0,6)];if(count($examples)>=8)break;}
        return['available'=>!empty($examples),'site_context'=>['parent_title'=>sanitize_text_field($context['parent']['title']??''),'headings'=>array_slice(array_map('sanitize_text_field',(array)($context['parent']['headings']??[])),0,5)],'site_rules'=>self::rules($rows),'examples'=>$examples,'stats'=>(array)($learning['stats']??[])];
    }
    private static function rules(array $rows){
        $edited=array_values(array_filter($rows,function($r){return is_array($r)&&!empty($r['edited'])&&!empty($r['generated'])&&!empty($r['approved']);}));
        if(count($edited)<3)return[];
        $lengths=[];$added=[];$removed=[];$purpose_lengths=[];
        foreach($edited as $row){$g=self::tokens($row['generated']);$a=self::tokens($row['approved']);$lengths[]=count($a);$p=sanitize_key($row['purpose']??'informative');$purpose_lengths[$p][] = count($a);foreach(array_diff($a,$g) as $word)$added[$word]=($added[$word]??0)+1;foreach(array_diff($g,$a) as $word)$removed[$word]=($removed[$word]??0)+1;}
        arsort($added);arsort($removed);$rules=[];$n=count($edited);$median=self::median($lengths);
        if($median>0)$rules['preferred_alt_words']=$median;
        $rules['avoid_terms']=array_keys(array_filter($removed,function($count)use($n){return $count>=max(2,(int)ceil($n*.25));}));
        $rules['preferred_terms']=array_keys(array_filter($added,function($count)use($n){return $count>=max(2,(int)ceil($n*.25));}));
        foreach($purpose_lengths as $purpose=>$vals)if(count($vals)>=2)$rules['preferred_words_by_purpose'][$purpose]=self::median($vals);
        if(!empty($rules['preferred_terms']))$rules['preferred_terms']=array_slice($rules['preferred_terms'],0,12);
        if(!empty($rules['avoid_terms']))$rules['avoid_terms']=array_slice($rules['avoid_terms'],0,12);
        return$rules;
    }
    private static function tokens($value){$value=preg_replace('/[^\pL\pN\s-]+/u',' ',strtolower((string)$value));$parts=preg_split('/\s+/u',trim($value));$stop=['the','a','an','of','and','in','on','for','to','with','is','are'];return array_values(array_unique(array_filter($parts,function($w)use($stop){return $w!==''&&!in_array($w,$stop,true)&&mb_strlen($w)>=3;})));}
    private static function median(array $values){sort($values,SORT_NUMERIC);$n=count($values);if(!$n)return 0;$m=(int)floor($n/2);return$n%2?$values[$m]:round(($values[$m-1]+$values[$m])/2);}
    private static function normalize($value){return preg_replace('/\s+/u',' ',strtolower(trim((string)$value)));}
}
Alt_Fixes_Learning::boot();
