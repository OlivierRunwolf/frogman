<?php if (!defined("FREEPBX_IS_AUTH")) { die("No direct script access allowed"); } ?>
<!DOCTYPE html>
<html>
<head>
<title>Frogman API Test</title>
<style>
body { font-family: -apple-system, sans-serif; background: #f0f8f8; padding: 40px; }
.container { max-width: 700px; margin: 0 auto; }
h1 { color: #0f5a59; }
label { display: block; margin-top: 15px; font-weight: 600; color: #0f5a59; }
input, select { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #b8dede; border-radius: 4px; font-size: 14px; }
textarea { width: 100%; height: 300px; margin-top: 10px; padding: 10px; font-family: monospace; font-size: 12px; border: 1px solid #b8dede; border-radius: 4px; background: #1a2e2e; color: #e8f4f4; }
button { margin-top: 15px; padding: 12px 24px; background: #009d9d; color: #fff; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; }
button:hover { background: #0f5a59; }
.row { display: flex; gap: 10px; }
.row > div { flex: 1; }
</style>
</head>
<body>
<div class="container">
<h1>🐸 Frogman API Test</h1>

<label>PBX Host</label>
<input type="text" id="host" value="https://144.202.20.53" placeholder="https://your-pbx-ip">

<label>API Token</label>
<input type="text" id="token" value="5b14bfb6e2d93cf771ab2eeaee94d0f34aca2c3de3177aa8265b9f5755823be3" placeholder="X-Frogman-Token">

<div class="row">
<div>
<label>Action</label>
<select id="action">
<option value="catalog">Get Catalog (all tools)</option>
<option value="tool" selected>Execute Tool</option>
</select>
</div>
<div>
<label>Tool Name</label>
<input type="text" id="tool" value="oc_list_extensions" placeholder="oc_list_extensions">
</div>
</div>

<label>Params (JSON)</label>
<input type="text" id="params" value="{}" placeholder='{"ext":"101"}'>

<button onclick="run()">Run</button>

<label>Response</label>
<textarea id="output" readonly>Click Run to test...</textarea>
</div>

<script>
async function run() {
  var host = document.getElementById('host').value;
  var token = document.getElementById('token').value;
  var action = document.getElementById('action').value;
  var tool = document.getElementById('tool').value;
  var params = document.getElementById('params').value;
  var output = document.getElementById('output');

  output.value = 'Loading...';

  try {
    var url = host + '/admin/ajax.php?module=frogman&command=' + action;
    var opts = {
      headers: {
        'X-Frogman-Token': token,
        'Content-Type': 'application/json'
      }
    };

    if (action === 'tool') {
      opts.method = 'POST';
      opts.body = JSON.stringify({tool: tool, params: JSON.parse(params)});
    }

    var resp = await fetch(url, opts);
    var data = await resp.json();
    output.value = JSON.stringify(data, null, 2);
  } catch(e) {
    output.value = 'Error: ' + e.message + '\n\nThis may be a CORS or SSL issue. Try from the PBX server itself.';
  }
}
</script>
</body>
</html>
