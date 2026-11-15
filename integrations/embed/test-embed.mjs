/**
 * Tests for the universal pay-button embed, run in Node with a
 * minimal DOM stub implementing exactly the surface the script uses.
 *
 * Run: node integrations/embed/test-embed.mjs
 */
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('./bmaglass-pay-button.js', import.meta.url), 'utf8');

let failures = 0;
const check = (label, cond) => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}`);
  if (!cond) failures++;
};

function makeDom(placeholders) {
  const created = [];
  const warnings = [];
  const errors = [];
  const document = {
    readyState: 'complete',
    querySelectorAll: (sel) => (sel === '.bmaglass-pay-button' ? placeholders : []),
    createElement: (tag) => {
      const el = {
        tagName: tag.toUpperCase(), style: {}, attrs: {},
        setAttribute(k, v) { this.attrs[k] = v; },
      };
      created.push(el);
      return el;
    },
    addEventListener: () => {},
  };
  for (const ph of placeholders) {
    ph.parentNode = {
      children: [],
      replaceChild(newEl, oldEl) { this.replaced = { newEl, oldEl }; },
    };
  }
  const window = { console: { warn: (...a) => warnings.push(a.join(' ')), error: (...a) => errors.push(a.join(' ')) } };
  return { document, window, created, warnings, errors };
}

function run(placeholders) {
  const dom = makeDom(placeholders);
  const ctx = vm.createContext({ document: dom.document, window: dom.window, console: dom.window.console });
  vm.runInContext(src, ctx);
  return dom;
}

// 1. Valid full URL renders a button.
let ph = { dataset: { link: 'https://bmaglasspay.online/pay/12db7755bf28243f' } };
let dom = run([ph]);
check('valid link renders one anchor', dom.created.length === 1 && dom.created[0].tagName === 'A');
check('anchor href is the payment link', dom.created[0].href === 'https://bmaglasspay.online/pay/12db7755bf28243f');
check('default label', dom.created[0].textContent === 'Pay with BMaGlass Pay');
check('placeholder replaced', !!ph.parentNode.replaced);

// 2. data-code + label + target.
ph = { dataset: { code: 'ABC123', label: 'Pay K250', target: '_blank' } };
dom = run([ph]);
check('code builds URL', dom.created[0].href === 'https://bmaglasspay.online/pay/ABC123');
check('custom label', dom.created[0].textContent === 'Pay K250');
check('target _blank + noopener', dom.created[0].target === '_blank' && dom.created[0].rel === 'noopener');

// 3. An API key in the config is REFUSED (never rendered).
ph = { dataset: { link: 'bmp_live_supersecret' } };
dom = run([ph]);
check('API key config refused', dom.created.length === 0);
check('refusal is explained in console', dom.errors.some((e) => e.includes('must never be embedded')));

// 4. Foreign/phishing URL is refused.
ph = { dataset: { link: 'https://evil.example/pay/ABC123' } };
dom = run([ph]);
check('foreign host refused', dom.created.length === 0 && dom.errors.length === 1);
check('refusal is an error, not a warning', dom.warnings.length === 0);

// 5. Non-HTTPS BMaGlass URL refused.
ph = { dataset: { link: 'http://bmaglasspay.online/pay/ABC123' } };
dom = run([ph]);
check('http (not https) refused', dom.created.length === 0 && dom.errors.length === 1);

// 6. An API key in data-label is refused too: the label is rendered as
// visible button text, so a key there would leak onto the page.
ph = { dataset: { link: 'https://bmaglasspay.online/pay/ABC123', label: 'Pay with bmg_live_supersecret' } };
dom = run([ph]);
check('API key in label refused', dom.created.length === 0 && dom.errors.length === 1);

// 7. An API key in data-code is refused.
ph = { dataset: { code: 'bmg_sandbox_supersecret' } };
dom = run([ph]);
check('API key in code refused', dom.created.length === 0 && dom.errors.length === 1);

// 8. Whitespace-padded code still renders (trimmed); other targets
// do not gain _blank/rel.
ph = { dataset: { code: '  ABC123  ', target: '_self' } };
dom = run([ph]);
check('padded code renders trimmed', dom.created.length === 1 && dom.created[0].href === 'https://bmaglasspay.online/pay/ABC123');
check('non-blank target sets nothing', dom.created[0].target === undefined && dom.created[0].rel === undefined);

// 9. A link code with a slash in it cannot smuggle a path past the check.
ph = { dataset: { code: 'ABC/def' } };
dom = run([ph]);
check('code with slash refused', dom.created.length === 0 && dom.errors.length === 1);

console.log(failures === 0 ? '\nALL EMBED TESTS PASSED' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
