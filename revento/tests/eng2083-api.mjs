// Explicitly targeted synthetic API/realtime acceptance; Node 22+ built-ins only.
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { mkdir, readFile, writeFile, stat } from 'node:fs/promises';
import { resolve, join } from 'node:path';

const [configFile, outputDirectory, target] = process.argv.slice(2);
assert(configFile && outputDirectory, 'Usage: node eng2083-api.mjs PRIVATE_CONFIG NEW_OUTPUT_DIR [--remote=TARGET_NAME]');
assert.equal((await stat(configFile)).mode & 0o077, 0, 'Config must be private');
const config = JSON.parse(await readFile(configFile, 'utf8'));
const endpoint = new URL(config.endpoint);
assert(endpoint.pathname === '/v1' && !endpoint.username && !endpoint.password && !endpoint.search && !endpoint.hash);
assert.equal(config.syntheticOnly, true, 'Target must explicitly prohibit real data');
assert(['baseline-harness-validation', 'candidate-acceptance'].includes(config.evidenceLabel));
assert.match(config.expectedImage, /^sha256:[a-f0-9]{64}$/);
if (target?.startsWith('--remote=')) {
  assert.equal(target.slice('--remote='.length), config.targetName);
  assert(config.targetName && endpoint.protocol === 'https:');
} else {
  assert.equal(target, undefined);
  assert(['127.0.0.1', 'localhost'].includes(endpoint.hostname), 'Remote target requires explicit named selection');
  assert(config.project.startsWith('eng2083'), 'Use an isolated local test project');
}
assert(typeof config.apiKey === 'string' && config.apiKey.length > 0);
const output = resolve(outputDirectory);
await mkdir(output, { mode: 0o700 });
const database = `qevents_${randomBytes(6).toString('hex')}`;
const base = `/tablesdb/${database}`;
const pending = new Set();
let created = null;
let ambiguous = false;
let failed = false;
let socket;
let acceptance;
const frames = [];
const errors = [];
let connected = false;
let unexpectedClose = false;
let closing = false;
const receipts = [];
const save = (name, value) => writeFile(join(output, name), JSON.stringify(value, null, 2) + '\n', { mode: 0o600 });
async function state() {
  await save('state.json', { database, created, pending: [...pending], ambiguous, failed });
}
async function call(method, path, body, allowed = [200, 201, 202, 204]) {
  assert(path === '/tablesdb' || path.startsWith(`${base}/`) || path === base || path === '/tablesdb/transactions' || [...pending].some(id => path === `/tablesdb/transactions/${id}` || path === `/tablesdb/transactions/${id}/operations`));
  if (path === '/tablesdb') assert(method === 'POST' && body.databaseId === database);
  const start = performance.now();
  let response, value;
  try {
    response = await fetch(config.endpoint + path, {
      method, redirect: 'error', signal: AbortSignal.timeout(180_000),
      headers: { 'content-type': 'application/json', 'x-appwrite-project': config.project, 'x-appwrite-key': config.apiKey },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const raw = await response.text();
    value = raw ? JSON.parse(raw) : {};
  } catch (error) {
    if (method !== 'GET') ambiguous = true;
    await state();
    throw new Error(`Transport failure during ${method} ${path}`, { cause: error });
  }
  receipts.push({ method, path, status: response.status, seconds: (performance.now() - start) / 1000 });
  if (!allowed.includes(response.status)) {
    await save('http-error.json', { method, path, status: response.status, type: value.type, message: value.message });
    throw new Error(`Unexpected HTTP ${response.status}: ${value.type}`);
  }
  return value;
}
async function until(condition, label, timeout = 60_000) {
  const deadline = performance.now() + timeout;
  while (!(await condition())) {
    assert(performance.now() < deadline, `Timeout: ${label}`);
    assert.equal(errors.length, 0, 'Realtime connection error');
    await new Promise(resolve => setTimeout(resolve, 50));
  }
}
async function tx() {
  const result = await call('POST', '/tablesdb/transactions', { ttl: 900 });
  pending.add(result.$id);
  await state();
  return result.$id;
}
async function stage(id, operations) {
  for (let i = 0; i < operations.length; i += 100) await call('POST', `/tablesdb/transactions/${id}/operations`, { operations: operations.slice(i, i + 100) });
}
async function commit(id) {
  const result = await call('PATCH', `/tablesdb/transactions/${id}`, { commit: true });
  assert.equal(result.status, 'committed');
  pending.delete(id);
  await state();
}
const operation = (tableId, action, rowId, data = {}) => ({ databaseId: database, tableId, action, rowId, ...(action === 'delete' ? {} : { data }) });
await save('intent.json', { issue: 'ENG-2083', endpoint: config.endpoint, project: config.project, database, syntheticOnly: true, noFunctionPublication: true, evidenceLabel: config.evidenceLabel, expectedImage: config.expectedImage, imageVerification: 'External deployment receipt required; not observable via this API' });
try {
  await call('GET', base, undefined, [404]);
  created = await call('POST', '/tablesdb', { databaseId: database, name: database });
  await state();
  for (const id of ['first', 'second']) {
    await call('POST', `${base}/tables`, { tableId: id, name: id, permissions: ['read("any")'], rowSecurity: true });
    await call('POST', `${base}/tables/${id}/columns/varchar`, { key: 'label', size: 64, required: false });
    await until(async () => {
      const result = await call('GET', `${base}/tables/${id}/columns`);
      assert(!result.columns.some(column => ['failed', 'stuck'].includes(column.status)), 'Column creation failed');
      return result.columns.length === 1 && result.columns.every(column => column.status === 'available');
    }, 'column readiness');
    await call('POST', `${base}/tables/${id}/rows`, { rowId: 'shared', data: { label: `initial_${id}` } });
  }
  await call('POST', `${base}/tables/first/rows`, { rowId: 'deleted', data: { label: 'delete_snapshot' } });
  const ws = new URL(config.realtimeEndpoint ?? config.endpoint.replace(/^http/, 'ws') + '/realtime');
  assert.equal(ws.pathname, '/v1/realtime');
  assert(!ws.username && !ws.password && !ws.search && !ws.hash);
  if (!target) assert(['127.0.0.1', 'localhost'].includes(ws.hostname));
  else assert.equal(ws.protocol, 'wss:');
  ws.searchParams.set('project', config.project);
  ws.searchParams.append('channels[]', `databases.${database}.tables.first.rows`);
  ws.searchParams.append('channels[]', `databases.${database}.tables.second.rows`);
  socket = new WebSocket(ws);
  socket.addEventListener('close', () => { if (!closing) unexpectedClose = true; });
  socket.addEventListener('error', () => errors.push('connection error'));
  socket.addEventListener('message', event => {
    try {
      const message = JSON.parse(event.data);
      if (message.type === 'connected') connected = true;
      if (message.type === 'error') errors.push('protocol error');
      if (message.type === 'event') frames.push(message.data);
    } catch { errors.push('invalid frame'); }
  });
  await until(() => connected, 'realtime connected');

  // Repeated operations cross the100-operation fetch boundary and alternate tables.
  const operations = [];
  const expected = [];
  for (let i = 0; i < 102; i++) {
    const table = i % 2 ? 'second' : 'first';
    operations.push(operation(table, 'update', 'shared', { label: `final_${i}` }));
    expected.push({ table, id: 'shared', action: 'update', label: table === 'first' ? 'final_100' : 'final_101' });
  }
  operations.push(operation('first', 'create', 'created', { label: 'created_final' }));
  expected.push({ table: 'first', id: 'created', action: 'create', label: 'created_final' });
  operations.push(operation('first', 'delete', 'deleted'));
  expected.push({ table: 'first', id: 'deleted', action: 'delete', label: 'delete_snapshot' });
  operations.push(operation('first', 'create', 'transient', { label: 'transient_snapshot' }));
  operations.push(operation('first', 'delete', 'transient'));
  // Native semantics suppress the create of a finally absent row, retain its delete snapshot.
  expected.push({ table: 'first', id: 'transient', action: 'delete', label: 'transient_snapshot' });
  const id = await tx();
  await stage(id, operations);
  assert.equal((await call('GET', `${base}/tables/first/rows/shared`)).label, 'initial_first');
  assert.equal(frames.length, 0, 'Staged changes emitted realtime events');
  await commit(id);
  await until(() => frames.length >= expected.length, 'transaction realtime events');
  const eventSummary = () => frames.map(frame => {
    const payload = frame.payload;
    const exact = frame.events.find(value => value.startsWith(`databases.${database}.tables.${payload.$tableId}.rows.${payload.$id}.`));
    assert(exact, 'Missing exact event identity');
    return { table: payload.$tableId, id: payload.$id, action: exact.split('.').at(-1), label: payload.label };
  });
  assert.deepEqual(eventSummary(), expected, 'Event order, multiplicity, or final/snapshot payload differs');
  for (const table of ['first', 'second']) assert.equal((await call('GET', `${base}/tables/${table}/rows/shared`)).label, table === 'first' ? 'final_100' : 'final_101');
  for (const row of ['deleted', 'transient']) await call('GET', `${base}/tables/first/rows/${row}`, undefined, [404]);

  const rollback = await tx();
  await stage(rollback, [operation('first', 'update', 'shared', { label: 'rolled_back' })]);
  const rolledBack = await call('PATCH', `/tablesdb/transactions/${rollback}`, { rollback: true });
  assert.equal(rolledBack.status, 'failed');
  pending.delete(rollback);
  assert.equal((await call('GET', `${base}/tables/first/rows/shared`)).label, 'final_100');
  // Observe a bounded quiet interval after rollback and a read round-trip barrier.
  // This proves no extra events within the interval, not indefinite absence.
  const quietUntil = performance.now() + 2000;
  while (performance.now() < quietUntil) {
    assert.deepEqual(eventSummary(), expected, 'Late duplicate or rollback event');
    assert.equal(errors.length, 0, 'Realtime error after expected events');
    assert.equal(unexpectedClose, false, 'Realtime connection closed unexpectedly');
    assert.equal(socket.readyState, WebSocket.OPEN);
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  assert.deepEqual(eventSummary(), expected);
  assert.equal(errors.length, 0);
  assert.equal(unexpectedClose, false);
  closing = true;
  socket.close();
  acceptance = { passed: true, issue: 'ENG-2083', evidenceLabel: config.evidenceLabel, expectedImage: config.expectedImage, operations: operations.length, events: frames.length, exactOrderAndPayloads: true, precommitInvisible: true, precommitEventsAbsent: true, committedStateVerified: true, rollbackPreserved: true, noExtraEventsObservationMs: 2000, scope: 'API/realtime synthetic fixture; no function publication, ownership/actor matrix, or webhook/function delivery qualification' };

} catch (error) {
  failed = true;
  throw error;
} finally {
  if (socket) {
    closing = true;
    const closed = new Promise(resolve => {
      if (socket.readyState === WebSocket.CLOSED) return resolve();
      socket.addEventListener('close', resolve, { once: true });
    });
    socket.close();
    await Promise.race([closed, new Promise(resolve => setTimeout(resolve, 2000))]);
  }
  let closeError;
  if (acceptance) {
    try {
      assert.equal(socket.readyState, WebSocket.CLOSED, 'Realtime close handshake did not complete');
      assert.equal(frames.length, acceptance.events, 'Extra event arrived during socket close');
      assert.equal(errors.length, 0, 'Realtime error during socket close');
    } catch (error) {
      failed = true;
      closeError = error;
    }
  }
  await save('requests.json', receipts);
  await save('events.json', frames);
  await state();
  if (created && !pending.size && !ambiguous && !failed) {
    const fresh = await call('GET', base);
    for (const key of ['$id', '$createdAt', 'name']) assert.equal(fresh[key], created[key], 'Ownership changed');
    await call('DELETE', base);
    await call('GET', base, undefined, [404]);
    await save('cleanup.json', { database, closed: true, absenceVerified: true });
    console.log(JSON.stringify({ database, closed: true }));
  } else if (created) {
    console.error('Fixture retained: acceptance failed, or operation state is pending or ambiguous. Inspect private receipt before cleanup.');
  }
  if (closeError) throw closeError;
  if (acceptance && !failed) {
    await save('acceptance.json', acceptance);
    console.log(JSON.stringify({ passed: true, evidenceLabel: config.evidenceLabel, database, operations: acceptance.operations, events: frames.length }));
  }
}
