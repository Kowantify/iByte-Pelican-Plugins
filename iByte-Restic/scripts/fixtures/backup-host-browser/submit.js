(async()=>{
  window.fixtureErrors=[];const old=console.error;console.error=(...a)=>{fixtureErrors.push(a.map(String).join(' '));old(...a)};
  const delay=ms=>new Promise(r=>setTimeout(r,ms));
  for(let i=0;i<50&&(!window.Livewire||!document.getElementById('form.name'));i++)await delay(100);
  const root=()=>Livewire.all().find(x=>x.name.endsWith('CreateBackupHost'));
  const check=(yes,message)=>{if(!yes)throw Error(message)};
  const choose=async(id,label)=>{document.getElementById(id).click();let option;for(let i=0;i<40&&!option;i++){await delay(100);option=[...document.querySelectorAll('*')].find(x=>x.children.length===0&&x.textContent.trim()===label)}check(option,'missing option '+label);option.click();await delay(250)};
  const name=document.getElementById('form.name');name.value='Browser fixed local';name.dispatchEvent(new Event('input',{bubbles:true}));
  await choose('form.schema','Restic');name.focus();await delay(800);
  check(root().ephemeral.data.configuration.backend==='local','local default missing');
  check(root().ephemeral.data.configuration.profile==='default','profile default missing');
  check(root().ephemeral.data.configuration.staging_enabled===false,'preview must default off');
  check(root().ephemeral.data.configuration.restore_fixture_enabled===false,'restore must default off');
  await choose('form.configuration.backend','S3 over HTTPS');check(root().ephemeral.data.configuration.backend==='s3','S3 did not bind');
  await choose('form.configuration.backend','Local filesystem');check(root().ephemeral.data.configuration.backend==='local','local did not bind');
  check(!document.getElementById('form.configuration.staging_enabled')?.matches('button'),'preview toggle must be managed in Administrative');
  check(!document.getElementById('form.configuration.restore_fixture_enabled')?.matches('button'),'restore toggle must be managed in Administrative');
  check(fixtureErrors.length===0,'browser errors: '+fixtureErrors.join(','));
  const result={checks:'defaults, local/S3 selection, advanced controls absent passed',errors:fixtureErrors,state:root().ephemeral.data};
  sessionStorage.setItem('ibyte-form-result',JSON.stringify(result));
  document.getElementById('key-bindings-1').click();
  return result;
})()
