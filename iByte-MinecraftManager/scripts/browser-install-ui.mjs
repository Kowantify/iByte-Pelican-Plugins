import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Node 22 built-in WebSocket talks to a locally launched Edge CDP instance.
// No external browser package, page scripts or production account are used.
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const tabs = await (await fetch('http://127.0.0.1:9226/json/list')).json();
const tab = tabs.find(tab => tab.type === 'page');
if (!tab) throw new Error('No disposable browser page');
const socket = new WebSocket(tab.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
let sequence = 0;
const pending = new Map();
socket.onmessage = event => {
  const message = JSON.parse(event.data);
  if (message.method === 'Runtime.exceptionThrown') console.error('Browser JS: ' + (message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text));
  const request = pending.get(message.id);
  if (request) { pending.delete(message.id); message.error ? request.reject(new Error(message.error.message)) : request.resolve(message.result); }
};
const send = (method, params = {}) => new Promise((resolve, reject) => {
  const id = ++sequence;
  const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
  pending.set(id, { resolve: value => { clearTimeout(timer); resolve(value); }, reject: error => { clearTimeout(timer); reject(error); } });
  socket.send(JSON.stringify({ id, method, params }));
});
const evaluate = async expression => {
  const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
  return result.result.value;
};
const until = async (expression, description) => {
  for (let count = 0; count < 80; count++) {
    try { if (await evaluate(expression)) return; } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error('Browser condition failed: ' + description);
};
const navigate = async role => {
  await send('Page.navigate', { url: 'http://127.0.0.1:8096/__fixture/login/' + role });
  await until("document.readyState === 'complete' && !!window.Livewire && document.body.innerText.includes('Minecraft Manager')", role + ' native page');
};
const results = [];
const pass = name => { results.push({ name, status: 'pass' }); console.log('PASS ' + name); };
const click = async text => {
  const clicked = await evaluate(`(() => { const button = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === ${JSON.stringify(text)}); if (!button) return false; button.click(); return true; })()`);
  if (!clicked) throw new Error('Missing button: ' + text);
};
try {
  await send('Page.enable'); await send('Runtime.enable');
  await send('Page.navigate', {url:'http://127.0.0.1:8096/__fixture/login/admin?plugins=1'});
  await until("document.readyState === 'complete' && !!window.Livewire && location.pathname === '/admin/plugins'", 'native plugins page');
  pass('native Admin Plugins page before ZIP import');
  const clicked = await evaluate("(() => { const button=[...document.querySelectorAll('button')].find(b => b.getAttribute('title') === 'Import from File' || b.getAttribute('aria-label') === 'Import from File'); if(!button)return false;button.click();return true;})()");
  if (!clicked) throw new Error('Native import action missing');
  await until("!!document.querySelector('input[type=file]')", 'native upload form');
  const documentNode = await send('DOM.getDocument');
  const input = await send('DOM.querySelector', {nodeId: documentNode.root.nodeId, selector: 'input[type=file]'});
  const version = JSON.parse(fs.readFileSync(path.join(root,'plugin/plugin.json'),'utf8')).version;
  await send('DOM.setFileInputFiles', {nodeId:input.nodeId, files:[path.join(root,'.build','ibyte-minecraft-manager-v'+version+'.zip')]});
  await until("document.body.innerText.includes('Upload complete') || !!document.querySelector('.filepond--item[data-filepond-item-state=idle]')", 'ZIP upload completion');
  pass('ZIP uploaded through native Filament file upload');
  const submitted = await evaluate("(() => { const modal=[...document.querySelectorAll('[role=dialog]')].find(d=>d.offsetParent!==null);const button=[...(modal??document).querySelectorAll('button[type=submit]')].find(b=>!b.disabled); if(!button)return false;button.click();return true;})()");
  if (!submitted) throw new Error('Native import submit missing');
  await until("document.body.innerText.includes('iByte Minecraft Manager') && document.body.innerText.includes('Not Installed')", 'native imported plugin row');
  pass('native ZIP import yields Not Installed row');
  // Native row action groups expose the install action in their dropdown.
  let installed = await evaluate("(() => {const row=[...document.querySelectorAll('tr')].find(r=>r.innerText.includes('iByte Minecraft Manager')); if(!row)return false;const direct=[...row.querySelectorAll('button')].find(b=>b.innerText.trim()==='Install'); if(direct){direct.click();return true;}const triggers=[...row.querySelectorAll('button')].filter(b=>b.getAttribute('aria-haspopup')==='menu'); if(triggers.length){triggers.at(-1).click();return 'menu';} return false;})()");
  if (installed === 'menu') { await until("[...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='Install')", 'native Install menu'); await click('Install'); }
  else if (!installed) throw new Error('Native row install action missing');
  pass('Install clicked through native table action; real queue worker started before import');
  let enabled = false;
  for (let count=0; count<100;count++) {
    await new Promise(r=>setTimeout(r,1500));
    await send('Page.reload');
    await new Promise(r=>setTimeout(r,500));
    enabled = await evaluate("[...document.querySelectorAll('tr')].some(r=>r.innerText.includes('iByte Minecraft Manager') && r.innerText.includes('Enabled'))");
    if (enabled) break;
  }
  if(!enabled) throw new Error('Queued native installation did not reach Enabled');
  const manifest=path.join(root,'.research/panel/public/build/manifest.json');
  if(!fs.existsSync(manifest)) throw new Error('Native Vite manifest missing after install');
  const ready=JSON.parse(fs.readFileSync(path.join(root,'.build/ui-install-worker-ready.json'),'utf8'));
  if(ready.helper_autoloadable || ready.process_fake || fs.statSync(manifest).mtimeMs < ready.started*1000) throw new Error('Real asset build / unregistered-worker precondition not satisfied');
  pass('real native installer enables plugin and regenerates Vite manifest without helper autoload');
  if (ready.cached_old_failure) pass('same pre-failed worker installs corrected migration paths without restart');
  const screenshot = await send('Page.captureScreenshot',{format:'png'});
  fs.writeFileSync(path.join(root,'docs/evidence/native-ui-install.png'),Buffer.from(screenshot.data,'base64'));
} catch(error) {
  results.push({name:error.message,status:'fail'});process.exitCode=1;console.error(error.message);
  console.error(await evaluate('document.body.innerText.slice(0,4500)'));
  console.error(await evaluate("[...document.querySelectorAll('button')].map(b=>({text:b.innerText,title:b.title,aria:b.getAttribute('aria-label'),popup:b.getAttribute('aria-haspopup')}))"));
} finally {
  const testedVersion=JSON.parse(fs.readFileSync(path.join(root,'plugin/plugin.json'),'utf8')).version;
  const testedZip=path.join(root,'.build','ibyte-minecraft-manager-v'+testedVersion+'.zip');
  fs.writeFileSync(path.join(root,'docs/evidence/native-ui-install.json'),JSON.stringify({version:testedVersion,artifact_sha256:crypto.createHash('sha256').update(fs.readFileSync(testedZip)).digest('hex'),tests:results,scope:'Native UI ZIP upload and Install action; actual pre-existing queue worker with no plugin autoload; real yarn install/build; Windows/SQLite fixture'},null,2)+'\n');
  socket.close();
}
