import net from 'node:net';
import tls from 'node:tls';
import dgram from 'node:dgram';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

// 独立标准对端：固定有界测试数据，不加载 TypeApp 的网络/协议实现。
const ready = process.argv[2];
const directory = dirname(ready);
const certFile = join(directory, 'certificate.pem');
const keyFile = join(directory, 'key.pem');
const cert = readFileSync(certFile);
const key = readFileSync(keyFile);
const servers = [];
const connections = new Set();
const observations = { accepted: 0, verified: 0, slow: 0, bytes: 0, dns: {} };
const track = socket => {
    connections.add(socket);
    observations.accepted++;
    socket.on('error', () => {});
    socket.on('close', () => connections.delete(socket));
    socket.setTimeout(15000, () => socket.destroy());
    return socket;
};
const echo = socket => {
    track(socket);
    socket.on('data', data => {
        observations.bytes += data.length;
        if (!socket.write(data)) socket.pause();
    });
    socket.on('drain', () => socket.resume());
    socket.on('end', () => socket.end());
};
const addresses = { certificate: certFile, key: keyFile };
const dns = dgram.createSocket('udp4');
dns.on('message', (query, peer) => {
    if (query.length < 17 || query.length > 512 || query.readUInt16BE(4) !== 1) return;
    const labels = [];
    let offset = 12;
    while (offset < query.length && query[offset] !== 0) {
        const length = query[offset++];
        if (length > 63 || offset + length >= query.length) return;
        labels.push(query.subarray(offset, offset + length).toString());
        offset += length;
    }
    offset++;
    if (offset + 4 > query.length) return;
    const name = labels.join('.');
    observations.dns[name] = (observations.dns[name] ?? 0) + 1;
    if (name.startsWith('slow.typeapp.test')) return;
    const rejected = name.startsWith('nxdomain.typeapp.test');
    const header = Buffer.from(query.subarray(0, 12));
    header.writeUInt16BE(rejected ? 0x8183 : 0x8180, 2);
    header.writeUInt16BE(rejected ? 0 : 1, 6);
    header.writeUInt16BE(0, 8);
    header.writeUInt16BE(0, 10);
    const answer = Buffer.from([0xc0, 0x0c, 0, 1, 0, 1, 0, 0, 0, 0, 0, 4, 127, 0, 0, 1]);
    const response = Buffer.concat([header, query.subarray(12, offset + 4), ...(rejected ? [] : [answer])]);
    const send = () => { if (!closed) dns.send(response, peer.port, peer.address); };
    if (name.startsWith('late.typeapp.test')) setTimeout(send, 150);
    else send();
});
await new Promise(resolve => dns.bind(0, '127.0.0.1', resolve));
addresses.dns = dns.address();
const listen = async (name, host, server) => {
    servers.push(server);
    server.on('error', error => { throw error; });
    await new Promise(resolve => server.listen({ host, port: 0, ipv6Only: host === '::1' }, resolve));
    addresses[name] = server.address();
};
await listen('echo4', '127.0.0.1', net.createServer({ allowHalfOpen: true }, echo));
await listen('echo6', '::1', net.createServer({ allowHalfOpen: true }, echo));
const secure = tls.createServer({ cert, key, minVersion: 'TLSv1.2', allowHalfOpen: true }, echo);
secure.on('tlsClientError', () => {});
await listen('tls', '127.0.0.1', secure);
const secure6 = tls.createServer({ cert, key, minVersion: 'TLSv1.2', allowHalfOpen: true }, echo);
secure6.on('tlsClientError', () => {});
await listen('tls6', '::1', secure6);
await listen('idle', '127.0.0.1', net.createServer(socket => { track(socket); socket.pause(); }));
await listen('reset', '127.0.0.1', net.createServer(socket => {
    track(socket);
    socket.once('data', () => socket.resetAndDestroy());
}));

const verifyServer = (command, control) => {
    const payload = Buffer.alloc(4096);
    for (let i = 0; i < payload.length; i++) payload[i] = i % 256;
    let slow = null;
    const launch = () => {
        const options = { host: command.host, port: command.port, allowHalfOpen: true, ca: cert,
            servername: 'localhost', rejectUnauthorized: true, minVersion: 'TLSv1.2' };
        const client = track(command.tls ? tls.connect(options) : net.connect(options));
        let received = Buffer.alloc(0);
        const event = command.tls ? 'secureConnect' : 'connect';
        client.once(event, () => client.end(payload));
        client.on('data', data => {
            if (received.length + data.length > payload.length) {
                control.end(JSON.stringify({ ok: false, reason: 'oversized' }) + '\n');
                client.destroy();
                return;
            }
            received = Buffer.concat([received, data]);
        });
        client.once('end', () => {
            const ok = received.equals(payload);
            if (ok) observations.verified++;
            control.end(JSON.stringify({ ok, bytes: received.length }) + '\n');
            client.destroy();
            if (slow) setTimeout(() => slow.destroy(), 250);
        });
        client.once('error', error => control.end(JSON.stringify({ ok: false, reason: error.code }) + '\n'));
    };
    if (command.slow) {
        observations.slow++;
        slow = track(net.connect({ host: command.host, port: command.port }));
        slow.once('connect', () => setTimeout(launch, 20));
    } else {
        launch();
    }
};
await listen('control', '127.0.0.1', net.createServer(socket => {
    track(socket);
    let input = '';
    socket.on('data', data => {
        input += data.toString();
        if (input.length > 4096) return socket.destroy();
        if (!input.endsWith('\n')) return;
        const command = JSON.parse(input);
        input = '';
        verifyServer(command, socket);
    });
}));
writeFileSync(ready, JSON.stringify(addresses));
let closed = false;
const shutdown = () => {
    if (closed) return;
    closed = true;
    for (const socket of connections) socket.destroy();
    for (const server of servers) server.close();
    dns.close();
    process.stdout.write(JSON.stringify(observations) + '\n');
};
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
