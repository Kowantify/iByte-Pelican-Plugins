import http from 'node:http';
let active=0, maximum=0, requests=0, completed=0;
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://127.0.0.1');
  if(url.pathname==='/__stats') {
    const stats={active,maximum,requests,completed};
    if(url.searchParams.has('reset')) { if(active){res.writeHead(409).end();return;} maximum=0;requests=0;completed=0; }
    res.setHeader('Content-Type','application/json');res.end(JSON.stringify(stats));return;
  }
  if(!req.headers.authorization?.startsWith('Bearer ')){res.writeHead(403).end();return;}
  res.setHeader('User-Agent','Pelican Wings/v1.0.0 (id:verifycontract19)');
  if(url.pathname.endsWith('/files/list-directory')){res.setHeader('Content-Type','application/json');res.end(JSON.stringify([{name:'existing-retired.jar',file:true,directory:false,symlink:false,size:1}]));return;}
  if(!url.pathname.endsWith('/files/contents')){res.writeHead(404).end();return;}
  const file=url.searchParams.get('file');++active;++requests;maximum=Math.max(maximum,active);
  res.once('close',()=>{--active;++completed;});
  res.setHeader('User-Agent','Pelican Wings/v1.0.0 (id:verifycontract19)');
  setTimeout(()=>{
    if(file.includes('missing')){res.writeHead(404).end('missing');return;}
    if(file.includes('disconnect')){res.destroy();return;}
    if(file.includes('oversized')){res.writeHead(200,{'Content-Length':'600000000'}).end();return;}
    const bytes=file.includes('empty')?Buffer.alloc(0):file.includes('big')?Buffer.alloc(1_100_000,97):Buffer.from('verification:'+file);
    if(file.includes('chunked')){res.writeHead(200,{'Transfer-Encoding':'chunked'});res.end(Buffer.alloc(262144,97));return;}
    res.setHeader('Content-Length',bytes.length+(file.includes('truncated')?1:0));
    if(file.includes('truncated')){res.setHeader('Connection','close');}
    res.end(bytes);
  },100);
});
server.listen(18097,'127.0.0.1',()=>process.stdout.write('Verification latency fixture ready\n'));
