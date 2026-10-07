const crypto = require('node:crypto');
const fs = require('node:fs');
const privateKey = fs.readFileSync('./keypair.pem', 'utf8');

const payload = request.body.getRaw();
const signer = crypto.createSign('RSA-SHA256');
signer.update(payload);
const sig = signer.sign({ key: privateKey, padding: crypto.constants.RSA_PKCS1_PADDING }, 'base64');
console.log('created signature: %s', sig);

request.variables.set('SIG', sig);
