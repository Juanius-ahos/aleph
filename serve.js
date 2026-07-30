const http = require('http');
const fs = require('fs');
const path = require('path');
const root = 'C:\\Users\\Samer\\Downloads\\CRM versite';
const port = 8082;
http.createServer((req, res) => {
  let file = path.join(root, req.url === '/' ? 'index.html' : req.url);
  if (!fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    file = path.join(file, 'index.html');
  }
  if (fs.existsSync(file)) {
    const ext = path.extname(file);
    const types = { '.html':'text/html','.css':'text/css','.js':'application/javascript','.png':'image/png','.jpg':'image/jpeg','.jpeg':'image/jpeg','.webp':'image/webp','.svg':'image/svg+xml','.ico':'image/x-icon','.mp4':'video/mp4','.json':'application/json','.xml':'text/xml','.woff2':'font/woff2' };
    res.writeHead(200, { 'Content-Type': types[ext] || 'text/plain' });
    fs.createReadStream(file).pipe(res);
  } else {
    res.writeHead(404);
    res.end('Not found');
  }
}).listen(port, () => console.log('Server at http://localhost:' + port));
