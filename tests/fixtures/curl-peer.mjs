import http from 'node:http';
import { writeFileSync } from 'node:fs';

// 独立 HTTP 对端回传请求所属线程/协程的标识；慢响应只用于真实 curl 截止。
const sockets = new Set();
const server = http.createServer((request, response) => {
    const body = String(request.headers['x-probe-owner'] ?? '');
    const send = () => {
        if (response.destroyed) return;
        response.writeHead(200, { 'Content-Type': 'text/plain', 'Content-Length': Buffer.byteLength(body), 'Connection': 'close' });
        response.end(body);
    };
    if (request.url === '/slow') {
        const timer = setTimeout(send, 500);
        response.once('close', () => clearTimeout(timer));
    } else send();
});
server.on('connection', socket => {
    sockets.add(socket);
    socket.once('close', () => sockets.delete(socket));
    socket.on('error', () => {});
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
writeFileSync(process.argv[2], JSON.stringify({ port: server.address().port }));
const stop = () => {
    for (const socket of sockets) socket.destroy();
    server.close();
};
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
