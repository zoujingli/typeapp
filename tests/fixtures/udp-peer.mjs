import dgram from 'node:dgram';
import { writeFileSync } from 'node:fs';

// 独立标准 UDP 对端：不加载任何 TypeApp 生产实现。
const ready = process.argv[2];
const sockets = [];
const addresses = {};
let messages = 0;
let closed = false;
const send = (socket, bytes, peer) => socket.send(bytes, peer.port, peer.address, error => {
    if (error && !closed) throw error;
});
for (const [kind, host] of [['udp4', '127.0.0.1'], ['udp6', '::1']]) {
    const socket = dgram.createSocket({ type: kind, ipv6Only: kind === 'udp6' });
    sockets.push(socket);
    socket.on('error', error => { throw error; });
    socket.on('message', (data, peer) => {
        messages++;
        const command = data.toString();
        if (command === '@oversize') {
            send(socket, Buffer.alloc(1025, 120), peer);
            send(socket, Buffer.from('after-oversize'), peer);
        } else if (command === '@burst') {
            // 有界突发；OS 可以丢 UDP，测试从实际收到的完整报文计数，不推定可靠交付。
            for (let i = 0; i < 256; i++) {
                const packet = Buffer.alloc(1024, 98);
                packet.writeUInt32BE(i);
                send(socket, packet, peer);
            }
        } else if (command.startsWith('@server:')) {
            send(socket, Buffer.from('server-request'), { address: peer.address, port: Number(command.slice(8)) });
        } else {
            send(socket, data, peer);
        }
    });
    await new Promise(resolve => socket.bind(0, host, resolve));
    socket.setSendBufferSize(262144);
    socket.setRecvBufferSize(262144);
    addresses[kind] = socket.address();
}
writeFileSync(ready, JSON.stringify(addresses));
const shutdown = () => {
    if (closed) return;
    closed = true;
    for (const socket of sockets) socket.close();
    process.stdout.write(JSON.stringify({ messages, addresses }) + '\n');
};
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
