// ── Toolbar click ─────────────────────────────────────────────────────────────
chrome.action.onClicked.addListener(async (tab) => {
  // Step 1: collect data from the page (runs in page context)
  let results;
  try {
    results = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: collectData,
    });
  } catch (err) {
    console.error('Specter: failed to inject script', err);
    return;
  }

  const data = results?.[0]?.result;
  if (!data) {
    console.error('Specter: collectData returned nothing');
    return;
  }

  // Step 2: POST to Specter from the background worker (no mixed-content block)
  let ok = false;
  let status = 0;
  try {
    const r = await fetch('http://localhost:3333/api/import', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    ok = r.ok;
    status = r.status;
  } catch (err) {
    console.error('Specter: fetch failed', err);
  }

  // Step 3: show toast in the page
  await chrome.scripting.executeScript({
    target: { tabId: tab.id },
    func: showToast,
    args: [ok, data.title || data.ticketId, status],
  });
});

// ── Runs inside the Axosoft page ──────────────────────────────────────────────
function collectData() {
  // Ticket ID — label with class containing "item-field-id"
  var id = document.querySelector('[class*="item-field-id"]')?.innerText?.trim() || '';

  // Title
  var title = document.querySelector('h1')?.innerText?.trim() || '';

  // Description — find the label "Description:" then grab its sibling value cell
  var descEl = null;
  var labelEls = Array.from(document.querySelectorAll('[class*="full-left"]'));
  for (var li = 0; li < labelEls.length; li++) {
    if (labelEls[li].innerText.trim().toLowerCase().includes('description')) {
      descEl = labelEls[li].parentElement
        ? labelEls[li].parentElement.querySelector('[class*="full-right"]')
        : null;
      break;
    }
  }
  // Grab text from the live element before cloning (innerText needs live DOM)
  var notes = descEl ? descEl.innerText.trim() : '';

  // Clone for HTML processing — use textContent (works on detached nodes)
  var descClone = descEl ? descEl.cloneNode(true) : null;
  if (descClone) {
    var allEls = Array.from(descClone.querySelectorAll('*'));
    var metaEl = allEls.find(function(el) {
      return el.childElementCount === 0 &&
             (el.textContent || '').trim().toLowerCase() === 'metadata';
    });
    if (metaEl) {
      var target = metaEl;
      while (target.parentElement && target.parentElement !== descClone) {
        target = target.parentElement;
      }
      while (target) {
        var next = target.nextSibling;
        target.remove();
        target = next;
      }
    }
  }
  var descriptionHtml = descClone ? descClone.innerHTML : '';

  // Ticket URL — try the hidden web-link input, otherwise build from ID
  var ticketUrl = document.querySelector('#url-web-input')?.value || '';
  if (!ticketUrl && id) {
    ticketUrl = location.origin + '?search=' + encodeURIComponent(id);
  }
  if (!ticketUrl) ticketUrl = location.href;

  // Images — strip thumbnail size constraints to get full-size
  var imgs = Array.from(document.querySelectorAll('li img.img-node'))
    .map(function(el) {
      try {
        var u = new URL(el.src);
        u.searchParams.delete('max_width');
        u.searchParams.delete('max_height');
        return u.toString();
      } catch(e) { return el.src; }
    })
    .filter(function(s) { return s && s.startsWith('http'); });

  // Links — from within the description element
  var links = [];
  if (descEl) {
    links = Array.from(descEl.querySelectorAll('a[href]'))
      .filter(function(a) { return a.href && !a.href.startsWith('javascript'); })
      .map(function(a) { return { text: a.innerText.trim() || a.href, href: a.href }; })
      .filter(function(v, i, arr) { return arr.findIndex(function(x) { return x.href === v.href; }) === i; });
  }

  // Testing doc — scan comments for a Google Docs link in a comment mentioning "test"
  var testingUrl = '';
  var commentItems = Array.from(document.querySelectorAll('.axo-commentsui-content li, [class*="comment"] li'));
  for (var ci = 0; ci < commentItems.length; ci++) {
    var commentText = commentItems[ci].innerText.toLowerCase();
    if (commentText.includes('test')) {
      // Prefer an <a> tag pointing to Google Docs
      var docLink = commentItems[ci].querySelector('a[href*="docs.google.com"]');
      if (docLink) { testingUrl = docLink.href; break; }
      // Fall back to a bare URL in the text
      var match = commentItems[ci].innerText.match(/https:\/\/docs\.google\.com\/\S+/);
      if (match) { testingUrl = match[0].replace(/[.,)]+$/, ''); break; }
    }
  }

  return { ticketId: id, title: title, description: notes, descriptionHtml: descriptionHtml, notes: '', url: ticketUrl, testingUrl: testingUrl, attachments: imgs, links: links };
}

// ── Runs inside the Axosoft page ──────────────────────────────────────────────
function showToast(ok, title, status) {
  var existing = document.getElementById('specter-toast');
  if (existing) existing.remove();

  var t = document.createElement('div');
  t.id = 'specter-toast';
  t.style.cssText =
    'position:fixed;bottom:20px;right:20px;z-index:99999;' +
    'padding:10px 16px;border-radius:8px;font:13px/1.4 sans-serif;' +
    'color:#fff;background:' + (ok ? '#2a5c3f' : '#7a2020') + ';' +
    'box-shadow:0 4px 16px rgba(0,0,0,.4);transition:opacity .4s;';

  t.textContent = ok
    ? '\u2713 "' + title + '" added to Specter'
    : status === 0
      ? 'Could not reach Specter \u2014 is it running?'
      : 'Specter import failed (' + status + ')';

  document.body.appendChild(t);
  setTimeout(function() {
    t.style.opacity = '0';
    setTimeout(function() { t.remove(); }, 400);
  }, 3500);
}
