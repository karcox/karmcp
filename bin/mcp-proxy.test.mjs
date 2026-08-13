/**
 * Unit tests for the multi-site proxy's pure logic.
 * Run: node --test bin/mcp-proxy.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

process.env.KARMCP_PROXY_NO_MAIN = '1';
const { loadSites, META_TOOLS, isMetaCall, injectMetaTools, handleMetaCall, sitePath, resolveCallSite } = await import('./mcp-proxy.mjs');

test('loadSites: single-site env → one default site', () => {
  const { sites, defaultSite } = loadSites({
    WP_URL: 'https://a.test', WP_USERNAME: 'u', WP_APP_PASSWORD: 'p',
  });
  assert.equal(defaultSite, 'default');
  assert.equal(Object.keys(sites).length, 1);
  assert.equal(sites.default.url, 'https://a.test');
  assert.equal(sites.default.username, 'u');
});

test('loadSites: KARMCP_SITES JSON registry', () => {
  const registry = JSON.stringify({
    clientA: { url: 'https://a.test', username: 'ua', appPassword: 'pa' },
    clientB: { url: 'https://b.test/', username: 'ub', appPassword: 'pb' },
  });
  const { sites, defaultSite } = loadSites({ KARMCP_SITES: registry, KARMCP_DEFAULT_SITE: 'clientB' });
  assert.equal(Object.keys(sites).length, 2);
  assert.equal(defaultSite, 'clientB');
  assert.equal(sites.clientB.url, 'https://b.test'); // trailing slash trimmed
});

test('loadSites: registry default falls back to first key', () => {
  const registry = JSON.stringify({ x: { url: 'https://x.test', username: 'u', appPassword: 'p' } });
  const { defaultSite } = loadSites({ KARMCP_SITES: registry });
  assert.equal(defaultSite, 'x');
});

test('loadSites: no config → empty', () => {
  const { sites } = loadSites({});
  assert.equal(Object.keys(sites).length, 0);
});

test('META_TOOLS has the two switching tools', () => {
  const names = META_TOOLS.map((t) => t.name);
  assert.ok(names.includes('karmcp_list_sites'));
  assert.ok(names.includes('karmcp_use_site'));
});

test('isMetaCall', () => {
  assert.ok(isMetaCall('karmcp_use_site'));
  assert.ok(isMetaCall('karmcp_list_sites'));
  assert.ok(!isMetaCall('karmcp-tools-list-pages'));
});

test('injectMetaTools: appends only when >1 site', () => {
  const single = injectMetaTools({ result: { tools: [{ name: 'x' }] } }, 1);
  assert.equal(single.result.tools.length, 1); // single site → unchanged

  const multi = injectMetaTools({ result: { tools: [{ name: 'x' }] } }, 2);
  const names = multi.result.tools.map((t) => t.name);
  assert.ok(names.includes('karmcp_use_site'));
  assert.equal(multi.result.tools.length, 3);
});

test('sitePath: root install → empty string', () => {
  const { sites } = loadSites({ WP_URL: 'https://a.test', WP_USERNAME: 'u', WP_APP_PASSWORD: 'p' });
  assert.equal(sitePath(sites.default), '');
});

test('sitePath: subdirectory install → base path without trailing slash', () => {
  const { sites } = loadSites({ WP_URL: 'https://a.test/claude2wp', WP_USERNAME: 'u', WP_APP_PASSWORD: 'p' });
  assert.equal(sitePath(sites.default), '/claude2wp');
});

test('sitePath: nested subdirectory install', () => {
  const { sites } = loadSites({ WP_URL: 'https://a.test/sites/client-a', WP_USERNAME: 'u', WP_APP_PASSWORD: 'p' });
  assert.equal(sitePath(sites.default), '/sites/client-a');
});

test('handleMetaCall: list + switch', () => {
  const state = {
    sites: { a: { url: 'https://a.test' }, b: { url: 'https://b.test' } },
    active: 'a', session: {}, permalinks: {},
  };
  const list = handleMetaCall('karmcp_list_sites', {}, state, 1);
  assert.match(JSON.stringify(list.result), /"a"/);
  assert.match(JSON.stringify(list.result), /"b"/);

  const ok = handleMetaCall('karmcp_use_site', { site: 'b' }, state, 2);
  assert.equal(state.active, 'b');
  assert.match(JSON.stringify(ok.result), /"active":"b"/);

  const bad = handleMetaCall('karmcp_use_site', { site: 'nope' }, state, 3);
  assert.ok(bad.result.isError);
  assert.equal(state.active, 'b'); // unchanged on bad switch
});

test('resolveCallSite: per-call site arg routes to that site and strips it', () => {
  const state = { sites: { a: { url: 'x' }, b: { url: 'y' } }, active: 'a' };
  const msg = { method: 'tools/call', params: { name: 'karmcp-tools-list-pages', arguments: { site: 'b', foo: 1 } } };
  const { alias, message } = resolveCallSite(msg, state);
  assert.equal(alias, 'b');
  assert.deepEqual(message.params.arguments, { foo: 1 });
});

test('resolveCallSite: unknown site falls back to active and keeps args', () => {
  const state = { sites: { a: {} }, active: 'a' };
  const msg = { method: 'tools/call', params: { name: 't', arguments: { site: 'nope', foo: 1 } } };
  const { alias, message } = resolveCallSite(msg, state);
  assert.equal(alias, 'a');
  assert.deepEqual(message.params.arguments, { site: 'nope', foo: 1 });
});

test('resolveCallSite: non-tools/call uses the active site', () => {
  const state = { sites: { a: {} }, active: 'a' };
  assert.equal(resolveCallSite({ method: 'tools/list' }, state).alias, 'a');
});
