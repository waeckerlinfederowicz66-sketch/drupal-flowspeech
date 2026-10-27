import fs from 'node:fs';
import path from 'node:path';
import tls from 'node:tls';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sdk = path.resolve(root, '../flowspeech-php');
const cert = process.env.FLOWSPEECH_TEST_CERT_DIR || path.join(sdk, '.tools/fixture-certificates');
const php = process.env.PHP_BIN || path.join(sdk, '.tools/php/bin/php');
const phpunit = process.env.PHPUNIT_BIN || path.join(root, '.tools/vendor/bin/phpunit');
const sockets = new Set();
const server = tls.createServer({ key: fs.readFileSync(path.join(cert, 'server.key')),
  cert: fs.readFileSync(path.join(cert, 'server.pem')) }, socket => {
  sockets.add(socket);
  socket.on('error', () => {});
  socket.on('close', () => sockets.delete(socket));
  socket.once('data', bytes => {
    const url = bytes.toString().split(' ')[1];
    if (url === '/ok') {
      socket.end('HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}');
      return;
    }
    const body = url === '/slow-body';
    socket.write(body ? 'HTTP/1.1 200 OK\r\nContent-Length: 30\r\nConnection: close\r\n\r\n' : 'HTTP/1.1 200 OK\r\n');
    let count = 0;
    const timer = setInterval(() => {
      if (++count < 30) socket.write(body ? 'x' : 'X-Drip-' + count + ': x\r\n');
      else {
        clearInterval(timer);
        socket.end(body ? 'x' : 'Content-Length: 2\r\nConnection: close\r\n\r\n{}');
      }
    }, 100);
    socket.on('close', () => clearInterval(timer));
  });
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const env = { ...process.env, FLOWSPEECH_DEADLINE_ENDPOINT: 'https://127.0.0.1:' + server.address().port,
  NO_PROXY: '127.0.0.1', no_proxy: '127.0.0.1' };
delete env.FLOWSPEECH_API_KEY;
try {
  process.exitCode = await new Promise((resolve, reject) => {
    const child = spawn(php, ['-d', 'curl.cainfo=' + path.join(cert, 'ca.pem'),
      '-d', 'openssl.cafile=' + path.join(cert, 'ca.pem'), phpunit, '--no-configuration',
      '--bootstrap', 'tests/framework-bootstrap.php', 'tests/DeadlineTransportTest.php'],
    { cwd: root, env, stdio: 'inherit' });
    const watchdog = setTimeout(() => child.kill('SIGKILL'), 15000);
    child.once('error', error => { clearTimeout(watchdog); reject(error); });
    child.once('exit', code => { clearTimeout(watchdog); resolve(code ?? 1); });
  });
} finally {
  for (const socket of sockets) socket.destroy();
  await new Promise(resolve => server.close(resolve));
}
