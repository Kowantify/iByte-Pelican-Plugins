(() => {
  const checks = [];
  const check = (yes, message) => { if (!yes) throw Error(message); checks.push(message); };
  const tabs = [...document.querySelectorAll('.fi-page-sub-navigation-tabs a')];
  check(tabs.map(a => a.textContent.trim()).join('|') === 'Servers|Stats|Administrative', 'exactly three native top tabs');
  const sidebar = [...document.querySelectorAll('.fi-sidebar a')].map(a => a.textContent.trim());
  check(sidebar.filter(x => x === 'Restic Backups').length === 1, 'one Restic Backups sidebar entry');
  check(!sidebar.includes('Stats') && !sidebar.includes('Administrative'), 'no extra plugin sidebar entries');
  const selected = tabs.filter(a => a.getAttribute('aria-current') === 'page');
  check(selected.length === 1 && new URL(selected[0].href).pathname === location.pathname, 'current tab is marked for assistive technology');
  const text = document.body.innerText;
  check(!/500 \| Server Error|419 Page Expired/.test(text), 'no server error page');
  check(document.documentElement.scrollWidth <= innerWidth, 'page stays within viewport; tables scroll inside their container');
  check(!document.body.innerHTML.includes('password_base64') && !document.body.innerHTML.includes('credentials_base64'), 'no credential payload in initial DOM');
  if (location.pathname.endsWith('/stats')) {
    check(text.includes('4.00 GiB') && text.includes('10.00 GiB') && text.includes('2.00x') && text.includes('1.25x'), 'saved storage totals and separate ratios render');
    check(text.includes('Measured at') && text.includes('Pending / unavailable') && text.includes('Understanding storage'), 'measurement dates, missing values and definitions render');
    check(!text.includes('View keys') && !text.includes('Remove Panel records'), 'Stats has no key or deletion buttons');
  } else if (location.pathname.endsWith('/administrative')) {
    for (const label of ['View keys', 'Key history', 'Remove Panel records', 'Preview settings', 'Repository maintenance']) {
      check([...document.querySelectorAll('button')].some(b => b.textContent.trim() === label), 'readable Administrative button: '+label);
    }
  } else {
    check(text.includes('Server repositories') && text.includes('Former client (retained)'), 'Servers includes active and retained inventory');
    check(!text.includes('View keys') && !text.includes('Remove Panel records'), 'Servers has no key or deletion buttons');
  }
  return {page:location.pathname, viewport:innerWidth, checks};
})()
