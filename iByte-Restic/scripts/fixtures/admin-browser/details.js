(async () => {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const button = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Details');
  if (!button) throw Error('Servers Details button missing');
  button.click();
  for (let i=0;i<40&&!document.body.innerText.includes('Repository identity');i++) await wait(100);
  const text = document.body.innerText;
  for (const label of ['Repository identity', 'Recent operations', 'Last successful integrity check']) {
    if (!text.includes(label)) throw Error('Missing structured detail: '+label);
  }
  if (document.body.innerHTML.includes('password_base64')) throw Error('Details leaked credential payload');
  return {checks:['structured repository identity', 'recent operation statuses', 'last successful integrity check or not recorded', 'no credential payload']};
})()
