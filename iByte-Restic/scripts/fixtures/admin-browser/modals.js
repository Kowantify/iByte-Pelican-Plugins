(async () => {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const checks = [];
  const check = (yes, message) => { if (!yes) throw Error(message); checks.push(message); };
  const button = label => [...document.querySelectorAll('button')].find(b => b.textContent.trim() === label);
  check(location.pathname.endsWith('/administrative'), 'Administrative route');
  button('More').click(); await wait(150);
  check(button('Download recovery kit') && button('Delete entire repository'), 'More menu contains labelled export and deletion actions');
  document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', bubbles:true}));
  button('View keys').click();
  for (let i=0;i<40&&!document.querySelector('input[type=password]');i++) await wait(100);
  check(!!document.querySelector('input[type=password]'), 'key view requires Panel password');
  check(!document.body.innerHTML.includes('password_base64'), 'opening key confirmation does not reveal credentials');
  return {checks};
})()
