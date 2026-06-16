<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activiteitenrooster → iCloud Agenda</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #f0f2f5;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.card {
    background: white;
    border-radius: 16px;
    padding: 40px;
    width: 100%;
    max-width: 560px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
}
h1 {
    font-size: 22px;
    font-weight: 700;
    color: #1a1a1a;
    margin-bottom: 6px;
}
.subtitle {
    color: #666;
    font-size: 14px;
    margin-bottom: 32px;
}
.drop-zone {
    border: 2px dashed #c8d0db;
    border-radius: 12px;
    padding: 48px 24px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: #fafbfc;
}
.drop-zone:hover, .drop-zone.drag-over {
    border-color: #007AFF;
    background: #f0f6ff;
}
.drop-icon {
    font-size: 40px;
    margin-bottom: 12px;
}
.drop-text {
    color: #444;
    font-size: 15px;
    margin-bottom: 6px;
}
.drop-sub {
    color: #999;
    font-size: 13px;
}
.drop-zone input[type=file] { display: none; }

.config-section {
    margin-top: 28px;
    border-top: 1px solid #eee;
    padding-top: 24px;
}
.config-section label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #444;
    margin-bottom: 6px;
}
.config-section input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #dde0e6;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 14px;
    outline: none;
    transition: border-color 0.2s;
}
.config-section input:focus { border-color: #007AFF; }

.btn {
    width: 100%;
    padding: 14px;
    background: #007AFF;
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    margin-top: 8px;
}
.btn:hover { background: #0066dd; }
.btn:disabled { background: #b0c8f0; cursor: not-allowed; }
.btn-secondary {
    background: #f0f2f5;
    color: #333;
    border: 1px solid #dde0e6;
}
.btn-secondary:hover { background: #e2e5ea; }
.btn-secondary:disabled { background: #f5f5f5; color: #aaa; cursor: not-allowed; }
select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #dde0e6;
    border-radius: 8px;
    font-size: 14px;
    background: white;
    outline: none;
    cursor: pointer;
}

#status {
    margin-top: 20px;
    padding: 14px 16px;
    border-radius: 10px;
    font-size: 14px;
    display: none;
}
#status.success { background: #e8f5e9; color: #2e7d32; }
#status.error   { background: #fdecea; color: #c62828; }
#status.info    { background: #e3f2fd; color: #1565c0; }

.file-chosen {
    margin-top: 12px;
    font-size: 13px;
    color: #007AFF;
    text-align: center;
    min-height: 18px;
}
</style>
</head>
<body>
<div class="card">
    <h1>📅 Activiteitenrooster</h1>
    <p class="subtitle">Sleep een Excel-export van LVP en zet activiteiten in je iCloud agenda.</p>

    <form id="uploadForm" enctype="multipart/form-data">
        <div class="drop-zone" id="dropZone">
            <div class="drop-icon">📂</div>
            <div class="drop-text">Sleep je Excel-bestand hier naartoe</div>
            <div class="drop-sub">of klik om te bladeren (.xlsx)</div>
            <input type="file" id="fileInput" name="file" accept=".xlsx,.xls">
        </div>
        <div class="file-chosen" id="fileChosen"></div>

        <div class="config-section">
            <label>iCloud gebruikersnaam (Apple ID)</label>
            <input type="email" name="icloud_user" id="icloudUser"
                   placeholder="naam@mac.com"
                   value="peterwitjes@mac.com"
                   autocomplete="username">

            <label>iCloud wachtwoord (app-specifiek)</label>
            <input type="password" name="icloud_pass" id="icloudPass"
                   placeholder="xxxx-xxxx-xxxx-xxxx"
                   autocomplete="current-password">

            <button type="button" class="btn btn-secondary" id="loadCalendarsBtn">Laad agenda's uit iCloud</button>

            <div id="calendarPickerWrap" style="display:none; margin-top:14px;">
                <label>Kies agenda</label>
                <select name="calendar_name" id="calendarName">
                    <option value="">— kies een agenda —</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn" id="submitBtn" disabled>Importeer in iCloud</button>
    </form>

    <div id="status"></div>
</div>

<script>
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileChosen= document.getElementById('fileChosen');
const submitBtn = document.getElementById('submitBtn');
const status    = document.getElementById('status');

dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.classList.add('drag-over');
});
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) setFile(file);
});

fileInput.addEventListener('change', () => {
    if (fileInput.files[0]) setFile(fileInput.files[0]);
});

function setFile(file) {
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;
    fileChosen.textContent = '✓ ' + file.name;
    submitBtn.disabled = false;
}

document.getElementById('loadCalendarsBtn').addEventListener('click', async () => {
    const user = document.getElementById('icloudUser').value.trim();
    const pass = document.getElementById('icloudPass').value.trim();
    if (!user || !pass) { showStatus('Vul je Apple ID en wachtwoord in.', 'error'); return; }

    const btn = document.getElementById('loadCalendarsBtn');
    btn.disabled = true;
    btn.textContent = 'Bezig…';
    showStatus('Agenda\'s ophalen uit iCloud…', 'info');

    const fd = new FormData();
    fd.append('icloud_user', user);
    fd.append('icloud_pass', pass);

    try {
        const resp = await fetch('calendars.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            const select = document.getElementById('calendarName');
            select.innerHTML = '<option value="">— kies een agenda —</option>';
            data.calendars.forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (name === 'Cultura Dans en Atrium') opt.selected = true;
                select.appendChild(opt);
            });
            document.getElementById('calendarPickerWrap').style.display = 'block';
            showStatus('✓ ' + data.calendars.length + ' agenda\'s gevonden.', 'success');
        } else {
            showStatus('✗ ' + data.message, 'error');
        }
    } catch (err) {
        showStatus('Verbindingsfout: ' + err.message, 'error');
    }
    btn.disabled = false;
    btn.textContent = 'Laad agenda\'s uit iCloud';
});

document.getElementById('uploadForm').addEventListener('submit', async e => {
    e.preventDefault();
    const pass = document.getElementById('icloudPass').value.trim();
    const cal  = document.getElementById('calendarName').value.trim();
    if (!pass) { showStatus('Vul je iCloud app-wachtwoord in.', 'error'); return; }
    if (!cal)  { showStatus('Kies eerst een agenda via "Laad agenda\'s uit iCloud".', 'error'); return; }
    if (!fileInput.files[0]) { showStatus('Kies eerst een Excel-bestand.', 'error'); return; }

    submitBtn.disabled = true;
    showStatus('Bezig met verwerken…', 'info');

    const formData = new FormData(e.target);
    try {
        const resp = await fetch('process.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.success) {
            showStatus('✓ ' + data.message, 'success');
        } else {
            showStatus('✗ ' + data.message, 'error');
        }
    } catch (err) {
        showStatus('Verbindingsfout: ' + err.message, 'error');
    }
    submitBtn.disabled = false;
});

function showStatus(msg, type) {
    status.textContent = msg;
    status.className = type;
    status.style.display = 'block';
}
</script>
</body>
</html>
