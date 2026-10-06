import https from 'node:https';
import fs from 'node:fs';

// 测试控制端的真实 TLS 对端；应用只连接显式 URL，不加载此文件。
const server = https.createServer({
  cert: fs.readFileSync(process.argv[2]),
  key: fs.readFileSync(process.argv[3]),
  minVersion: 'TLSv1.2',
}, (request, response) => {
  const finish = () => {
    response.writeHead(200, { 'content-type': 'text/plain', 'x-request-id': 'catalog-https' });
    response.end('catalog-https-ok');
  };
  if (request.url === '/slow') setTimeout(finish, 300);
  else finish();
});
server.listen(0, '127.0.0.1', () => {
  process.stdout.write(JSON.stringify({ port: server.address().port }) + '\n');
});
process.on('SIGTERM', () => server.close(() => process.exit(0)));
