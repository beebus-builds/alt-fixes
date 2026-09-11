<?php
/** Universal image discovery for WordPress content and page builders. */
if (!defined('ABSPATH')) exit;

class Alt_Fixes_Discovery {
    public static function scan($page=1,$per_page=100) {
        $page=max(1,absint($page)); $per_page=min(500,max(1,absint($per_page)));
        $posts=get_posts(['post_type'=>self::post_types(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'DESC']);
        $found=[];
        foreach($posts as $post_id){
            $post=get_post($post_id); if(!$post) continue;
            $html=(string)$post->post_content;
            foreach(self::extract_urls($html) as $url) self::add($found,$url,$post_id,'content');
            foreach(self::builder_meta($post_id) as $blob) foreach(self::extract_urls($blob) as $url) self::add($found,$url,$post_id,'builder-data');
        }
        $attachments=get_posts(['post_type'=>'attachment','post_mime_type'=>'image','post_status'=>'inherit','posts_per_page'=>-1,'fields'=>'ids']);
        foreach($attachments as $id){$url=wp_get_attachment_url($id);if($url&&isset($found[self::normalize($url)]))$found[self::normalize($url)]['attachment_id']=$id;}
        foreach($found as &$item) unset($item['_seen']); unset($item);
        $items=array_values($found); usort($items,function($a,$b){return strcasecmp($a['url'],$b['url']);});
        $total=count($items); return ['items'=>array_slice($items,($page-1)*$per_page,$per_page),'page'=>$page,'per_page'=>$per_page,'total'=>$total,'pages'=>$total?(int)ceil($total/$per_page):0];
    }
    public static function post_types(){ $types=get_post_types(['public'=>true],'names'); foreach(['attachment','revision','nav_menu_item'] as $bad) unset($types[$bad]); return array_values($types); }
    private static function builder_meta($post_id){
        $out=[]; $meta=get_post_meta($post_id);
        foreach((array)$meta as $key=>$values){
            $key_l=strtolower((string)$key);
            if(strpos($key_l,'elementor')!==false||strpos($key_l,'divi')!==false||strpos($key_l,'avada')!==false||strpos($key_l,'bricks')!==false||strpos($key_l,'wpbakery')!==false||strpos($key_l,'vc_')===0||strpos($key_l,'fl_builder')!==false||strpos($key_l,'oxygen')!==false||strpos($key_l,'beaver')!==false||strpos($key_l,'fusion')!==false||strpos($key_l,'kadence')!==false||strpos($key_l,'breakdance')!==false||strpos($key_l,'spectra')!==false||strpos($key_l,'generateblocks')!==false){foreach((array)$values as $value) if(is_scalar($value)) $out[]=(string)$value;}
        }
        return $out;
    }
    private static function extract_urls($html){
        if(!is_string($html)||$html==='')return [];
        $urls=[];
        if(preg_match_all('/(?:src|srcset|data-src|data-lazy-src|data-image|data-background-image|data-bg|href)=["\']([^"\']+)["\']/i',$html,$m)) foreach($m[1] as $raw){foreach(preg_split('/\s*,\s*/',$raw) as $candidate){$candidate=preg_replace('/\s+\d+[wx](?:\s|$)/i','',trim($candidate));if(self::is_image_url($candidate))$urls[]=html_entity_decode($candidate);}}
        if(preg_match_all('/url\((?:["\']?)([^\)"\']+)(?:["\']?)\)/i',$html,$m)) foreach($m[1] as $url) if(self::is_image_url($url))$urls[]=html_entity_decode(trim($url));
        if(preg_match_all('/https?:\/\/[^\s"\'<>]+/i',$html,$m)) foreach($m[0] as $url) if(self::is_image_url($url))$urls[]=$url;
        return array_values(array_unique(array_filter($urls)));
    }
    private static function is_image_url($url){$url=trim((string)$url);if($url===''||stripos($url,'data:image/')===0)return false;$path=(string)wp_parse_url($url,PHP_URL_PATH);return(bool)preg_match('/\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?|ico)(?:$|\?)/i',$path);}
    private static function normalize($url){$url=html_entity_decode(trim((string)$url));$url=preg_replace('/\?.*$/','',$url);return untrailingslashit($url);}
    private static function add(&$found,$url,$post_id,$source){$key=self::normalize($url);if($key==='')return;if(!isset($found[$key]))$found[$key]=['url'=>$url,'attachment_id'=>0,'usages'=>[],'sources'=>[]];$usage=['id'=>(int)$post_id,'title'=>get_the_title($post_id),'type'=>get_post_type($post_id)];$signature=$post_id.':'.$source;if(!isset($found[$key]['_seen'][$signature])){$found[$key]['_seen'][$signature]=1;$found[$key]['usages'][]=$usage;$found[$key]['sources'][]=$source;}}
}
