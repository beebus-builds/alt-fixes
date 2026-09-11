(function(){'use strict';
const cfg=window.AltFixesAdmin||{};
const MODEL='Xenova/vit-gpt2-image-captioning';
let captionerPromise=null;
let busy=false;

function esc(v){return String(v??'').replace(/[&<>\"]/g,x=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[x]));}
function root(){return (cfg.root||'/wp-json/alt-fixes/v1/').replace(/\/$/,'/');}
async function api(path,options){const response=await fetch(root()+path.replace(/^\//,''),{credentials:'same-origin',...options,headers:{'X-WP-Nonce':cfg.nonce||'','Content-Type':'application/json',...(options&&options.headers||{})}});const data=await response.json();if(!response.ok)throw new Error(data.message||'Request failed.');return data;}
async function getCaptioner(setStatus){
    if(!captionerPromise){
        setStatus('Loading the free browser AI model. The first download can be large; later runs use the browser cache.');
        captionerPromise=import('https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2').then(({pipeline,env})=>{
            env.allowRemoteModels=true;
            return pipeline('image-to-text',MODEL,{quantized:true});
        }).catch(error=>{captionerPromise=null;throw error;});
    }
    return captionerPromise;
}
async function runCard(card){
    if(busy)return;
    const id=Number(card.dataset.id||0), input=card.querySelector('.alt-fixes-input'), status=card.querySelector('.alt-fixes-status');
    if(!id||!input||!status)return;
    busy=true;const buttons=card.querySelectorAll('button');buttons.forEach(b=>b.disabled=true);
    try{
        const ctx=await api('browser-context/'+id);
        const model=await getCaptioner(t=>status.textContent=t);
        status.textContent='Analyzing the image locally on this browser…';
        const output=await model(ctx.image_url,{max_new_tokens:32});
        const caption=String(output?.[0]?.generated_text||'').trim();
        if(!caption)throw new Error('The local vision model returned no caption.');
        status.textContent='Applying accessibility checks and WordPress context…';
        const result=await api('browser-suggest/'+id,{method:'POST',body:JSON.stringify({caption})});
        input.value=result.suggestion||'';
        status.textContent='Local AI suggestion ready. Nothing was approved automatically.';
        if(result.analysis?.review_reason)status.textContent+=' '+result.analysis.review_reason;
        if(window.jQuery){jQuery(card).addClass('is-local-ai');}
    }catch(error){status.textContent='Local AI error: '+error.message;}
    finally{buttons.forEach(b=>b.disabled=false);busy=false;}
}
async function runSelected(){
    if(busy)return;
    const cards=[...document.querySelectorAll('#alt-fixes-results .alt-fixes-card')].filter(card=>card.querySelector('.item-select:checked'));
    if(!cards.length){alert('Select at least one image.');return;}
    busy=true;
    try{
        const model=await getCaptioner(()=>{});
        for(const card of cards){
            const id=Number(card.dataset.id||0),input=card.querySelector('.alt-fixes-input'),status=card.querySelector('.alt-fixes-status');
            if(!id||!input||!status)continue;
            const buttons=card.querySelectorAll('button');buttons.forEach(b=>b.disabled=true);
            try{
                const ctx=await api('browser-context/'+id);status.textContent='Analyzing locally…';
                const output=await model(ctx.image_url,{max_new_tokens:32});
                const caption=String(output?.[0]?.generated_text||'').trim();
                if(!caption)throw new Error('No caption returned.');
                const result=await api('browser-suggest/'+id,{method:'POST',body:JSON.stringify({caption})});
                input.value=result.suggestion||'';status.textContent='Local AI suggestion ready for review.';
            }catch(error){status.textContent='Local AI error: '+error.message;}
            finally{buttons.forEach(b=>b.disabled=false);}
        }
    }finally{busy=false;}
}
function inject(){
    if(cfg.provider&&cfg.provider!=='browser-local')return;
    document.querySelectorAll('#alt-fixes-results .alt-fixes-card').forEach(card=>{
        if(card.querySelector('.alt-fixes-local-generate'))return;
        const actions=card.querySelector('.alt-fixes-actions');if(!actions)return;
        const button=document.createElement('button');button.type='button';button.className='button button-primary alt-fixes-local-generate';button.textContent='Local AI';button.title='Analyze with the free browser-local vision model';
        actions.insertBefore(button,actions.firstChild);
    });
    const toolbar=document.querySelector('.alt-fixes-toolbar-actions');
    if(toolbar&&!toolbar.querySelector('.alt-fixes-local-bulk')){
        const button=document.createElement('button');button.type='button';button.className='button alt-fixes-local-bulk';button.textContent='Run Local AI';button.title='Analyze selected images locally in this browser';toolbar.insertBefore(button,toolbar.firstChild);
    }
    if(!document.querySelector('.alt-fixes-local-banner')){
        const results=document.getElementById('alt-fixes-results');if(results){const banner=document.createElement('div');banner.className='alt-fixes-local-banner';banner.innerHTML='<strong>Free browser AI</strong><span>Vision inference runs on this device with no OpenAI key, server AI bill, or Ollama. The first model download is cached by your browser.</span>';results.prepend(banner);}}
}
document.addEventListener('click',function(event){
    const local=event.target.closest('.alt-fixes-local-generate');if(local){event.preventDefault();event.stopImmediatePropagation();runCard(local.closest('.alt-fixes-card'));return;}
    const bulk=event.target.closest('.alt-fixes-local-bulk');if(bulk){event.preventDefault();event.stopImmediatePropagation();runSelected();return;}
},true);
const observer=new MutationObserver(inject);observer.observe(document.body,{childList:true,subtree:true});
inject();
})();
