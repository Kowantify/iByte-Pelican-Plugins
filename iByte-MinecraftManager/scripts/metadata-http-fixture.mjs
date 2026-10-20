import http from 'node:http';
let active=0, maximum=0, requests=0, mode='', paths=[];
const row=(name,directory=false)=>({name,directory,file:!directory,symlink:false,size:directory?0:1});
const folders=new Map([['',[row('archive',true),row('config',true),row('unsafe',true)]]]);
for(const root of ['archive','config']) {
  folders.set(root,Array.from({length:12},(_,i)=>row(`d${i}`,true)));
  for(let i=0;i<12;i++) folders.set(`${root}/d${i}`,[row('value.txt')]);
}
folders.set('unsafe',[{...row('link',true),symlink:true}]);
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://127.0.0.1');
  if(url.pathname==='/__stats'){
    const stats={active,maximum,requests,paths};
    if(url.searchParams.has('reset')){if(active){res.writeHead(409).end();return;}maximum=requests=0;paths=[];mode=url.searchParams.get('mode')||'';}
    res.setHeader('Content-Type','application/json');res.end(JSON.stringify(stats));return;
  }
  if(!req.headers.authorization?.startsWith('Bearer ')){res.writeHead(403).end();return;}
  res.setHeader('User-Agent','Pelican Wings/v1.0.0 (id:metadata25)');
  res.setHeader('Content-Type','application/json');
  ++active;++requests;maximum=Math.max(maximum,active);
  res.once('close',()=>--active);
  const directory=(url.searchParams.get('directory')||'').replace(/^\/|\/$/g,'');paths.push(directory);
  setTimeout(()=>{
    if(url.pathname.endsWith('/files/create-directory')){
      let body='';req.on('data',b=>body+=b);req.on('end',()=>{
        const {path,name}=JSON.parse(body),parent=path.replace(/^\/|\/$/g,'');
        if(!folders.has(parent)){res.writeHead(404).end('{}');return;}
        const dest=parent?`${parent}/${name}`:name;
        folders.get(parent).push(row(name,true));folders.set(dest,[]);res.writeHead(204).end();
      });return;
    }
    if(!url.pathname.endsWith('/files/list-directory')){res.writeHead(404).end('{}');return;}
    if(mode==='failure'&&directory==='config/d0'){res.writeHead(500).end('{}');return;}
    if(!folders.has(directory)){res.writeHead(404).end('{}');return;}
    res.end(JSON.stringify(folders.get(directory)));
  },60);
});
server.listen(0,'127.0.0.1',()=>process.stdout.write(JSON.stringify({port:server.address().port})+'\n'));
