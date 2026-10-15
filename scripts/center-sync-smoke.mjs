import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { createRequire } from 'node:module'
import { createInterface } from 'node:readline'
const require = createRequire(import.meta.url)
const WebSocket = require('../../mobile/node_modules/ws')
const fixture = spawn('php', ['scripts/center-management-fixture.php'], { env: { ...process.env, CENTER_TEST_BROADCAST: '1' }, stdio: ['pipe', 'pipe', 'inherit'] })
const responses = []; const pending = []
createInterface({ input: fixture.stdout }).on('line', line => {
  const data = JSON.parse(line)
  if (pending.length) pending.shift()(data); else responses.push(data)
})
const next = () => responses.length ? Promise.resolve(responses.shift()) : new Promise(resolve => pending.push(resolve))
async function rpc(path, method = 'GET', body) {
  fixture.stdin.write(JSON.stringify({ path, method, body }) + '\n')
  const response = await next()
  return { status: response.status, body: JSON.parse(response.body) }
}
let socket
const timeout = setTimeout(() => { console.error('Center synchronization test timed out.'); socket?.terminate(); fixture.kill(); process.exit(1) }, 25000)
try {
  const settings = await next()
  assert.ok(settings.key, 'Configure and start local Reverb before this test.')
  socket = new WebSocket(`ws://127.0.0.1:8080/app/${settings.key}?protocol=7&client=center-test&version=1`, { origin: 'http://localhost:5173' })
  const events = []; const waiters = []
  socket.on('message', raw => {
    const event = JSON.parse(raw)
    if (typeof event.data === 'string') event.data = JSON.parse(event.data)
    const index = waiters.findIndex(waiter => waiter.name === event.event)
    if (index >= 0) waiters.splice(index, 1)[0].resolve(event); else events.push(event)
  })
  function wait(name) {
    const index = events.findIndex(event => event.event === name)
    return index >= 0 ? Promise.resolve(events.splice(index, 1)[0]) : new Promise(resolve => waiters.push({ name, resolve }))
  }
  await wait('pusher:connection_established')
  socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'evacuation.centers' } }))
  await wait('pusher_internal:subscription_succeeded')
  const created = await rpc('/api/admin/evacuation/centers', 'POST', { name: 'Socket test center', place_id: settings.place_id, capacity: 150, latitude: 10.12, longitude: 124.56, camp_manager_id: settings.manager_id })
  assert.equal(created.status, 201)
  const id = created.body.data.id
  const notification = await wait('center.updated')
  assert.equal(notification.data.center_id, id)
  assert.deepEqual(Object.keys(notification.data).sort(), ['center_id', 'changed_at', 'event_id'])
  const resident = await rpc('/api/evacuation-centers')
  const admin = await rpc('/api/admin/evacuation/map')
  assert.deepEqual(resident.body.data, admin.body.data)
  assert.equal(resident.body.data[0].capacity, 150)
  assert.equal(resident.body.data[0].latitude, 10.12)
  const updated = await rpc(`/api/admin/evacuation/centers/${id}`, 'PATCH', { name: 'Updated socket center', latitude: 10.13, longitude: 124.57 })
  assert.equal(updated.status, 200)
  assert.equal((await wait('center.updated')).data.center_id, id)
  const moved = await rpc('/api/evacuation-centers')
  assert.equal(moved.body.data[0].latitude, 10.13)
  assert.equal(moved.body.data[0].name, 'Updated socket center')
  await new Promise(resolve => { socket.once('close', resolve); socket.close() })
  const closed = await rpc(`/api/admin/evacuation/centers/${id}`, 'PATCH', { status: 'Closed' })
  assert.equal(closed.status, 200)
  assert.equal((await rpc('/api/evacuation-centers')).body.data.length, 0)
  assert.equal((await rpc('/api/admin/evacuation/map')).body.data[0].status, 'Closed')
  console.log('PASS: committed create/edit events reach the public Reverb channel; Admin and Resident feeds agree; refresh after disconnection reflects deactivation without exposing private data.')
} finally { clearTimeout(timeout); socket?.close(); fixture.stdin.end() }
