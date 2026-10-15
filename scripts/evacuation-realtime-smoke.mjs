import { spawn } from 'node:child_process'
import { createInterface } from 'node:readline'
import { createRequire } from 'node:module'
import { randomUUID } from 'node:crypto'
import assert from 'node:assert/strict'
const require = createRequire(import.meta.url)
const WebSocket = require('../../mobile/node_modules/ws')
const fixture = spawn('php', ['scripts/evacuation-realtime-fixture.php'], { stdio: ['pipe', 'pipe', 'inherit'] })
const queue = []; const pending = []
createInterface({ input: fixture.stdout }).on('line', (line) => {
  const data = JSON.parse(line)
  if (pending.length) pending.shift()(data); else queue.push(data)
})
function next() { return queue.length ? Promise.resolve(queue.shift()) : new Promise(resolve => pending.push(resolve)) }
async function rpc(body) { fixture.stdin.write(JSON.stringify(body)+'\n'); return await next() }
const timeout = setTimeout(() => { console.error('WebSocket verification timed out.'); fixture.kill(); process.exit(1) }, 20000)
let socket
try {
  const settings = await next()
  assert.ok(settings.key, 'Configure Reverb first')
  socket = new WebSocket(`ws://127.0.0.1:8080/app/${settings.key}?protocol=7&client=islasafe-test&version=1.0`, { origin: 'http://localhost:5173' })
  const events = []; const waiters = []
  socket.on('message', raw => {
    const event = JSON.parse(raw)
    if (typeof event.data === 'string') event.data = JSON.parse(event.data)
    events.push(event)
    const index = waiters.findIndex(waiter => waiter.name === event.event)
    if (index >= 0) waiters.splice(index, 1)[0].resolve(event)
  })
  const wait = name => events.find(event => event.event === name) ? Promise.resolve(events.find(event => event.event === name)) : new Promise(resolve => waiters.push({ name, resolve }))
  const connection = await wait('pusher:connection_established')
  for (const [role, status] of [['campmanager',403], ['residents',403], ['invalid',401]]) {
    const result = await rpc({ command: 'auth', role, socket_id: connection.data.socket_id })
    assert.equal(result.status, status, `Private channel denies ${role}`)
  }
  const authorization = await rpc({ command: 'auth', role: 'admin', socket_id: connection.data.socket_id })
  assert.equal(authorization.status, 200)
  socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'private-evacuation.admin', auth: authorization.data.auth } }))
  await wait('pusher_internal:subscription_succeeded')
  const result = await rpc({ command: 'register', request_id: randomUUID() })
  assert.equal(result.status, 201)
  assert.equal(result.data.data.center.current, 1)
  assert.equal(result.data.data.center.demographics.age.seniors, 1)
  const change = await wait('center.updated')
  assert.equal(change.data.center_id, settings.center)
  assert.notEqual(change.data.center_id, settings.other)
  assert.deepEqual(Object.keys(change.data).sort(), ['center_id','changed_at','event_id'])
  console.log('PASS: Sanctum private channel admits only Admin; committed registration updates its center through the real Reverb socket without personal data.')
  await new Promise(resolve => { socket.once('close', resolve); socket.close() })
  const missed = await rpc({ command: 'register', request_id: randomUUID() })
  assert.equal(missed.status, 201)
  const refreshed = await rpc({ command: 'summary' })
  assert.equal(refreshed.status, 200)
  assert.equal(refreshed.data.data.find(center => center.id === settings.center).current, 2)
  assert.equal(refreshed.data.data.find(center => center.id === settings.other).current, 0)
  socket = new WebSocket(`ws://127.0.0.1:8080/app/${settings.key}?protocol=7&client=islasafe-test&version=1.0`, { origin: 'http://localhost:5173' })
  const reconnected = await new Promise(resolve => socket.once('message', raw => resolve(JSON.parse(raw))))
  const auth = await rpc({ command: 'auth', role: 'admin', socket_id: JSON.parse(reconnected.data).socket_id })
  assert.equal(auth.status, 200)
  const subscribed = new Promise(resolve => socket.on('message', raw => { if (JSON.parse(raw).event === 'pusher_internal:subscription_succeeded') resolve() }))
  socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'private-evacuation.admin', auth: auth.data.auth } }))
  await subscribed
  console.log('PASS: A refresh retrieves registrations missed during disconnection, leaves unrelated centers unchanged, and the private socket can reconnect.')
} finally { clearTimeout(timeout); socket?.close(); fixture.stdin.end() }
