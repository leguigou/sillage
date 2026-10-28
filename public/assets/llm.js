(() => {
  'use strict';
  const $ = (s,r=document) => r.querySelector(s);
  const $$ = (s,r=document) => [...r.querySelectorAll(s)];
  const csrf = $('meta[name="csrf-token"]')?.content;
  async function post(path,data) {
    const response=await fetch(path,{method:'POST',headers:{Accept:'application/json'},body:new URLSearchParams({_csrf:csrf,...data})});
    let result;try { result=await response.json(); } catch { throw new Error(response.status===403?'Accès refusé ou session expirée. Rechargez la page.':'Réponse serveur inattendue. Rechargez la page.'); }
    if (!response.ok || result.error) throw new Error(result.error||'La requête a échoué.');
    return result;
  }
  const configDialog=$('#llm-config-dialog'), config=$('[data-llm-config]');
  if (config) {
    const catalog=JSON.parse($('#llm-profile-data').textContent);
    const provider=$('[name=provider]',config),url=$('[name=base_url]',config),key=$('[name=token]',config),model=$('[name=model]',config);
    const test=$('[data-llm-test]',config),save=$('[data-llm-save]',config),status=$('[data-llm-status]',config);
    let current=null,proof='',busy=false;
    function invalidate() { proof='';model.replaceChildren(new Option('Testez la connexion d’abord',''));model.disabled=true;save.disabled=true;status.textContent=''; }
    function hints() {
      const spec=catalog.providers[provider.value];
      $('[data-llm-url-help]',config).textContent=spec.local?'Adresse accessible depuis PHP. HTTP est réservé à votre serveur local ou réseau privé.':'URL HTTPS de l’API, avec son préfixe de version. Une passerelle personnalisée doit être accessible publiquement.';
      $('[data-llm-key-help]',config).textContent=(spec.local?'Facultatif si votre serveur ne demande pas d’authentification. ':'Clé API propre à ce fournisseur. ')+(current?'Champ vide : conserve la clé uniquement si le fournisseur et l’URL restent identiques.':'Collez la clé seule, sans « Bearer ».');
    }
    function lock(value) {
      busy=value;
      $$('input,select,button',config).forEach(el=>el.disabled=value);
      if (!value) { model.disabled=!proof;save.disabled=!proof; }
    }
    function open(profile=null) {
      current=profile;config.reset();provider.value=profile?.provider||'openai';url.value=profile?.base_url||catalog.providers[provider.value].url;key.value='';
      $('[name=name]',config).value=profile?.name||'';
      $('[name=enabled]',config).checked=profile?!!Number(profile.enabled):true;
      $('[name=make_default]',config).checked=!!Number(profile?.is_default||0);
      invalidate();
      if(profile) model.replaceChildren(new Option(profile.model,profile.model,true,true));
      hints();configDialog.showModal();
    }
    $('[data-llm-new]')?.addEventListener('click',()=>open());
    $$('[data-llm-edit]').forEach(button=>button.addEventListener('click',()=>open(catalog.profiles.find(p=>Number(p.id)===Number(button.dataset.llmEdit)))));
    provider.addEventListener('change',()=>{url.value=catalog.providers[provider.value].url;key.value='';invalidate();hints();});
    [url,key].forEach(el=>el.addEventListener('input',invalidate));
    configDialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
    configDialog.addEventListener('close',()=>{key.value='';invalidate();});
    test.addEventListener('click',async()=>{
      if(!url.reportValidity())return;
      invalidate();status.textContent='Vérification de la connexion et chargement des modèles…';
      const input={provider:provider.value,base_url:url.value,token:key.value,id:current?.id||0,version:current?.version||0};
      lock(true);
      try {
        const result=await post('/llm/profiles/test',input);proof=result.proof;
        model.replaceChildren(new Option('Choisir un modèle',''),...result.models.map(m=>new Option(m.name===m.id?m.id:`${m.name} · ${m.id}`,m.id)));
        if(current && result.models.some(m=>m.id===current.model))model.value=current.model;
        status.textContent=result.message;key.value='';
      } catch(error) { status.textContent=error.message; }
      finally { lock(false); }
    });
    config.addEventListener('submit',async event=>{
      event.preventDefault();if(!proof || !config.reportValidity())return;
      const input={proof,name:$('[name=name]',config).value,model:model.value,enabled:$('[name=enabled]',config).checked?'1':'',make_default:$('[name=make_default]',config).checked?'1':''};
      lock(true);status.textContent='Enregistrement du profil…';
      try { await post('/llm/profiles',input);window.location.assign('/settings#llm');window.location.reload(); }
      catch(error){status.textContent=error.message;lock(false);}
    });
  }
  const dialog=$('#llm-assistant-dialog'),form=$('[data-llm-assistant]');
  if (!form) return;
  const mode=$('[name=mode]',form),text=$('[name=text]',form),status=$('[data-llm-assist-status]',form),result=$('[data-llm-result]',form),output=$('[data-llm-output]',form),apply=$('[data-llm-apply]',form),generate=$('[data-llm-generate]',form);
  let target=null,original='',pending=false;
  function syncMode(){ $('[data-llm-scope]',form).hidden=mode.value!=='search';apply.hidden=!target||mode.value==='search'||!output.value; }
  function resetResult(){ result.hidden=true;output.value='';status.textContent='';apply.hidden=true; }
  function open(field=null) {
    target=field;original=field?(field.isContentEditable?field.innerHTML:field.value):'';
    mode.value=field?(field.isContentEditable?'rewrite':'draft'):'search';
    text.value=field?(field.isContentEditable?field.innerText:(field.form?.querySelector('[name=title]')?.value||'')+'\n'+field.value):'';
    resetResult();syncMode();dialog.showModal();
  }
  $$('[data-llm-open]').forEach(button=>button.addEventListener('click',()=>open()));
  if(document.body.dataset.role!=='viewer') {
    $$('form[action="/tickets"] textarea[name=description],form[action^="/tickets/"] textarea[name=description],.conversation-editor').forEach(field=>{
      const button=document.createElement('button');button.type='button';button.className='button subtle llm-field-help';button.textContent='✦ Aide à la rédaction';
      button.addEventListener('click',()=>open(field));field.insertAdjacentElement('afterend',button);
    });
  }
  mode.addEventListener('change',()=>{resetResult();syncMode();});
  text.addEventListener('input',resetResult);
  $('[name=project_id]',form).addEventListener('change',resetResult);
  dialog.addEventListener('cancel',event=>{if(pending)event.preventDefault();});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(pending || !form.reportValidity())return;
    resetResult();pending=true;
    const input={mode:mode.value,text:text.value,project_id:$('[name=project_id]',form).value,profile_id:dialog.dataset.llmProfile,profile_version:dialog.dataset.llmVersion};
    $$('button,input,textarea,select',form).forEach(el=>el.disabled=true);
    status.textContent='Le modèle prépare une proposition…';
    try {
      const answer=await post('/llm/assist',input);output.value=answer.text;result.hidden=false;
      $('[data-llm-copy]',form).dataset.copy=answer.text;
      const sources=$('[data-llm-sources]',form);sources.replaceChildren();
      (answer.sources||[]).forEach(source=>{const link=document.createElement('a');link.href='/tickets/'+Number(source.id);link.target='_blank';link.rel='noopener';link.textContent=source.key+' · '+source.title;sources.append(link);});
      const examined=(answer.sources||[]).length;
      $('[data-llm-coverage]',form).textContent=mode.value==='search'?`${examined} ticket${examined>1?'s':''} examiné${examined>1?'s':''} parmi ${answer.total} correspondance${answer.total>1?'s':''} lexicale${answer.total>1?'s':''}. Les extraits sont limités ; cette recherche n’est pas exhaustive.`:'';
      status.textContent=answer.sent?'Proposition reçue. Relisez-la avant utilisation.':'Aucun texte envoyé au modèle.';
      syncMode();
    } catch(error) { status.textContent=error.message; }
    finally { pending=false;$$('button,input,textarea,select',form).forEach(el=>el.disabled=false); }
  });
  apply.addEventListener('click',()=>{
    if(!target || !output.value)return;
    const now=target.isContentEditable?target.innerHTML:target.value;
    if(now!==original){status.textContent='Le champ a changé depuis l’ouverture. Copiez la proposition pour conserver vos modifications.';return;}
    if(target.isContentEditable) {
      if(target.innerText.length+output.value.length>10000){status.textContent='Le commentaire dépasserait 10 000 caractères. Copiez une version plus courte.';return;}
      const paragraph=document.createElement('p');paragraph.textContent=output.value;target.append(paragraph);
    } else target.value=output.value;
    target.dispatchEvent(new Event('input',{bubbles:true}));dialog.close();target.focus();
  });
})();
