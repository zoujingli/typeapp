import dgram from 'node:dgram';
import { writeFileSync } from 'node:fs';

// 独立标准 UDP 对端：不加载任何 TypeApp 生产实现。
const ready = process.argv[2];
const burstDelay = Number(process.env.TYPE_TEST_UDP_BURST_DELAY_MS ?? '0');
if (!Number.isInteger(burstDelay) || burstDelay < 0 || burstDelay > 1000) throw new Error('invalid burst test delay');
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
        } else if (command.startsWith('@burst:')) {
            // 有界突发；OS 可以丢 UDP，测试从实际收到的完整报文计数，不推定可靠交付。
            const controlPort = Number(command.slice(7));
            if (!Number.isInteger(controlPort) || controlPort < 1 || controlPort > 65535) throw new Error('invalid burst control port');
            setTimeout(() => {
                let pending = 256;
                for (let i = 0; i < 256; i++) {
                    const packet = Buffer.alloc(1024, 98);
                    packet.writeUInt32BE(i);
                    socket.send(packet, peer.port, peer.address, error => {
                        if (closed) return;
                        if (error) throw error;
                        if (--pending === 0) {
                            // 独立控制端点不会被突发数据填满；确认发送完成后才开始排空被测端点。
                            send(socket, Buffer.from('burst-sent:256'), { address: peer.address, port: controlPort });
                        }
                    });
                }
            }, burstDelay);
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
