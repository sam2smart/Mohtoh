const fs = require('fs');
let lines = fs.readFileSync('assets/js/search.js', 'utf8').split('\n');
// Remove lines 234 to 285 inclusive (index 233 to 284)
lines.splice(233, 52);
fs.writeFileSync('assets/js/search.js', lines.join('\n'));
console.log('Fixed syntax error by removing duplicate block.');
