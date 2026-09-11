<?php
/**
 * OpenAI vision provider for Alt Fixes AI.
 */
if (!defined('ABSPATH')) exit;
class Alt_Fixes_OpenAI_Provider implements Alt_Fixes_Provider {
    private $api_key;
    private $model;
    public function __construct($api_key, $model = 'gpt-5.6-luna') {
        $this->api_key = trim((string)$api_key);
        $this->model = trim((string)$model) ?: 'gpt-5.6-luna';
    }
    public function suggest($image_data_url, array $context = []) {
        if ($this->api_key === '') return new WP_Error('missing_api_key','Configure an OpenAI API key first.',['status'=>400]);
        $response = wp_remote_post('https://api.openai.com/v1/responses',[
            'timeout'=>90,
            'headers'=>['Authorization'=>'Bearer '.$this->api_key,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode(['model'=>$this->model,'input'=>[['role'=>'user','content'=>[
                ['type'=>'input_text','text'=>$this->build_prompt($context)],
                ['type'=>'input_image','image_url'=>$image_data_url],
            ]]],'max_output_tokens'=>500]),
        ]);
        if (is_wp_error($response)) return $response;
        $code=wp_remote_retrieve_response_code($response);$body=json_decode(wp_remote_retrieve_body($response),true);
        if ($code>=400) return new WP_Error('provider_error',$body['error']['message']??'OpenAI request failed.',['status'=>502]);
        $text=$this->extract_text($body);
        if ($text==='') return new WP_Error('empty_analysis','OpenAI returned no image analysis.',['status'=>502]);
        $analysis=json_decode($text,true);
        if (!is_array($analysis)) return new WP_Error('invalid_analysis','The vision provider returned invalid analysis JSON.',['status'=>502]);
        $purpose=sanitize_key($analysis['purpose']??'informative');
        $allowed=['informative','decorative','logo','product','chart','diagram','screenshot','linked_control','text','complex'];
        if(!in_array($purpose,$allowed,true))$purpose='informative';
        $confidence=max(0,min(1,(float)($analysis['confidence']??.5)));
        $quality=max(0,min(100,(int)($analysis['quality_score']??50)));
        $decorative=!empty($analysis['decorative'])||$purpose==='decorative';
        $alt=sanitize_text_field((string)($analysis['alt']??''));
        $ocr=sanitize_textarea_field((string)($analysis['ocr_text']??''));
        $flags=[];foreach((array)($analysis['quality_flags']??[]) as $flag){$flag=sanitize_text_field($flag);if($flag!=='')$flags[]=$flag;}
        return [
            'alt'=>$decorative?'':$alt,'purpose'=>$purpose,'decorative'=>$decorative,'confidence'=>$confidence,
            'quality_score'=>$quality,'quality_flags'=>$flags,'ocr_text'=>$ocr,
            'review_reason'=>sanitize_text_field((string)($analysis['review_reason']??'')),
            'evidence'=>sanitize_text_field((string)($analysis['evidence']??'')),
            'model'=>$this->model,'raw'=>$body,
        ];
    }
    private function build_prompt(array $context) {
        $json=wp_json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return "You are an expert accessibility editor and visual QA system. Analyze the image pixels together with WordPress context. Return JSON only with exactly these keys: alt, purpose, decorative, confidence, quality_score, quality_flags, ocr_text, review_reason, evidence.\n\nPurpose must be one of: informative, decorative, logo, product, chart, diagram, screenshot, linked_control, text, complex.\n\nRules:\n- Determine the image's communicative purpose, not merely the objects visible.\n- Decorative/redundant imagery: alt=\"\", decorative=true.\n- Informative imagery: describe the information necessary to understand the surrounding content.\n- Logos: name the organization only when supported by visible text or context.\n- Products: identify only supported product details.\n- Charts/diagrams: summarize the key takeaway and exact values only when clearly readable. Flag dense or ambiguous visuals for review.\n- Screenshots: describe the relevant interface/content.\n- Linked controls: describe the action/function when supported by context.\n- OCR: transcribe meaningful visible text that affects accessibility. Do not invent unreadable text. Keep ocr_text concise; use an empty string if no meaningful text is present.\n- If text is blurry, tiny, obstructed, stylized, or uncertain, mention that in quality_flags and lower confidence.\n- Never invent names, locations, brands, statistics, dates, relationships, or other facts.\n- Alt text is normally 5-15 words, does not start with 'image of' or 'picture of', and should not repeat nearby text unnecessarily.\n- Complex images may need a concise alt plus human review; do not cram a full data table into alt text.\n- quality_score is 0-100 and measures how confidently a human accessibility editor could approve the proposed alt from the available visual/context evidence.\n- quality_flags must be an array of short strings such as unreadable_text, ambiguous_purpose, complex_visual, missing_context, likely_redundant, low_contrast, dense_chart, or context_conflict. Use [] when none apply.\n- review_reason must explain why review is needed when confidence/quality is low or the image is complex; otherwise empty.\n- evidence should briefly identify the visual/context evidence behind the classification.\n\nWordPress context:\n".$json;
    }
    private function extract_text(array $body) {
        if(!empty($body['output_text'])&&is_string($body['output_text']))return $this->clean_text($body['output_text']);
        foreach((array)($body['output']??[]) as $item)foreach((array)($item['content']??[]) as $content)if(!empty($content['text'])&&is_string($content['text']))return $this->clean_text($content['text']);
        return '';
    }
    private function clean_text($text){$text=trim((string)$text);$text=preg_replace('/^```(?:json)?\s*/i','',$text);$text=preg_replace('/\s*```$/','',$text);return trim($text);}
}
