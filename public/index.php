<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../src/app.php';
boot();

$route = $_GET['route'] ?? 'dashboard';
$syncAction = $_GET['action'] ?? '';
function jsonResponse(array $body, int $status = 200): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }
if ($route === 'sync-api') {
    $sessionId = $_GET['session'] ?? ''; $token = $_GET['token'] ?? '';
    if (!validSyncSession($sessionId, $token)) jsonResponse(['error' => 'Pairing token is invalid or expired. Scan a new QR code.'], 401);
    if ($syncAction === 'pull') jsonResponse(allSyncData());
    if ($syncAction === 'push' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload) || !is_array($payload['collections'] ?? null)) jsonResponse(['error' => 'Invalid sync payload.'], 400);
        $allowed = ['years', 'master_items', 'expenses', 'sales', 'purchases', 'payments']; $merged = 0;
        foreach ($payload['collections'] as $collection => $records) if (in_array($collection, $allowed, true) && is_array($records)) foreach ($records as $record) if (is_array($record) && mergeRemoteRecord($collection, $record)) $merged++;
        foreach (($payload['photos'] ?? []) as $name => $encoded) {
            $name = basename((string)$name); $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name && in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) && !file_exists(UPLOAD_DIR . '/' . $name)) { $binary = base64_decode((string)$encoded, true); if ($binary !== false) file_put_contents(UPLOAD_DIR . '/' . $name, $binary, LOCK_EX); }
        }
        jsonResponse(['ok' => true, 'merged_records' => $merged, 'server_time' => syncTime()]);
    }
    jsonResponse(['error' => 'Unsupported sync request.'], 400);
}
$photoName = basename($_GET['name'] ?? '');
if ($route === 'photo') {
    $path = UPLOAD_DIR . '/' . $photoName;
    if (!$photoName || !is_file($path)) { http_response_code(404); exit; }
    $type = mime_content_type($path) ?: 'application/octet-stream';
    if (!str_starts_with($type, 'image/')) { http_response_code(403); exit; }
    header('Content-Type: ' . $type); header('Content-Length: ' . filesize($path)); readfile($path); exit;
}
$yearId = $_GET['year_id'] ?? $_POST['year_id'] ?? '';
$years = readCollection('years');
$types = masterTypes();

function redirect(string $route, array $params = []): never { header('Location: ?' . http_build_query(['route' => $route] + $params)); exit; }
function pageStart(string $title): void {
    $flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' · Brick Kiln ERP</title><link rel="stylesheet" href="style.css"></head><body><header class="top"><a class="brand" href="?">Brick Kiln ERP</a><nav class="nav"><a href="?route=years">Years</a><a href="?route=masters">Masters</a><a href="?route=expense">Expenses</a><a href="?route=sale">Sales</a><a href="?route=purchase">Purchases</a><a href="?route=payment">Payments</a><a href="?route=reports">Reports</a><a href="?route=sync">Sync Receiver</a></nav></header><main class="wrap">';
    if ($flash) echo '<div class="flash">' . e($flash) . '</div>';
    echo '<h1>' . e($title) . '</h1>';
}
function pageEnd(): void { echo '</main></body></html>'; }
function yearSelect(string $yearId, string $extraRoute): string {
    global $years;
    $out = '<form method="get" class="card"><input type="hidden" name="route" value="' . e($extraRoute) . '"><label>Working Year</label><select name="year_id" onchange="this.form.submit()"><option value="">Choose a year</option>';
    foreach ($years as $year) $out .= '<option value="' . e($year['id']) . '"' . selected($yearId, $year['id']) . '>' . e($year['year']) . '</option>';
    return $out . '</select></form>';
}
function masterField(string $field, string $label, array $data): string {
    $value = $data[$field] ?? '';
    if ($field === 'photo') return '<div><label>' . e($label) . '</label><input type="file" name="photo" accept="image/*">' . (!empty($value) ? '<img class="photo" src="?route=photo&amp;name=' . e($value) . '" alt="Photo">' : '') . '</div>';
    if ($field === 'address' || $field === 'note') return '<div><label>' . e($label) . '</label><textarea name="data[' . e($field) . ']">' . e($value) . '</textarea></div>';
    $inputType = $field === 'password' ? 'password' : ($field === 'mobile' ? 'tel' : 'text');
    return '<div><label>' . e($label) . '</label><input type="' . $inputType . '" name="data[' . e($field) . ']" value="' . e($field === 'password' ? '' : $value) . '"' . ($field === 'password' ? ' placeholder="Leave blank to keep unchanged"' : '') . '></div>';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'year') {
            $record = ['id' => $_POST['id'] ?: id(), 'year' => trim($_POST['year'] ?? ''), 'note' => trim($_POST['note'] ?? '')];
            if ($record['year'] === '') throw new RuntimeException('Year is required.');
            upsert('years', $record); flash('Year saved.'); redirect('years');
        }
        if ($action === 'sync_session') {
            $host = trim($_POST['host'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9.:-]+$/', $host)) throw new RuntimeException('Enter the laptop Wi-Fi IP address, for example 192.168.1.5:8080.');
            $session = createSyncSession($host); redirect('sync', ['session' => $session['id'], 'token' => $session['token']]);
        }
        if ($action === 'master') {
            $type = $_POST['type']; if (!isset($types[$type])) throw new RuntimeException('Invalid master type.');
            $old = !empty($_POST['id']) ? find('master_items', $_POST['id']) : null;
            $data = array_intersect_key($_POST['data'] ?? [], $types[$type]['fields']);
            if (isset($data['password']) && $data['password'] === '' && $old) $data['password'] = $old['data']['password'] ?? '';
            if (isset($data['password']) && $data['password'] !== '') $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            if (isset($types[$type]['fields']['photo'])) $data['photo'] = photo($old['data'] ?? []);
            upsert('master_items', ['id' => $_POST['id'] ?: id(), 'year_id' => $_POST['year_id'], 'type' => $type, 'data' => $data]);
            flash($types[$type]['label'] . ' saved.'); redirect('masters', ['year_id' => $_POST['year_id'], 'type' => $type]);
        }
        if (in_array($action, ['expense', 'sale', 'purchase', 'payment'], true)) {
            $collection = $action === 'sale' ? 'sales' : ($action === 'purchase' ? 'purchases' : $action . 's');
            $record = $_POST['record'] ?? []; $record['id'] = $_POST['id'] ?: id(); $record['year_id'] = $_POST['year_id'];
            foreach (['amount', 'rate', 'weight', 'quantity'] as $number) if (isset($record[$number])) $record[$number] = (float)$record[$number];
            if (empty($record['date'])) throw new RuntimeException('Date is required.');
            upsert($collection, $record); flash(ucfirst($action) . ' saved.'); redirect($action, ['year_id' => $_POST['year_id']]);
        }
    }
    if (isset($_GET['delete'], $_GET['id'])) {
        $collection = $_GET['delete'];
        if (!in_array($collection, ['years', 'master_items', 'expenses', 'sales', 'purchases', 'payments'], true)) throw new RuntimeException('Invalid delete request.');
        remove($collection, $_GET['id']); flash('Record deleted.'); redirect($_GET['back'] ?? 'dashboard', $yearId ? ['year_id' => $yearId] : []);
    }
} catch (Throwable $error) { flash($error->getMessage()); redirect($route, $yearId ? ['year_id' => $yearId] : []); }

if ($route === 'dashboard') {
    pageStart('Dashboard');
    echo '<div class="card"><p>Start by creating a financial year, then create year-specific master records. All transaction forms draw their party, vehicle, and driver choices from those masters.</p><div class="actions"><a class="button" href="?route=years">Manage Years</a><a class="button secondary" href="?route=masters">Manage Masters</a></div></div>';
}
elseif ($route === 'years') {
    pageStart('Year Master'); $edit = isset($_GET['edit']) ? find('years', $_GET['edit']) : null;
    echo '<form method="post" class="card grid"><input type="hidden" name="action" value="year"><input type="hidden" name="id" value="' . e($edit['id'] ?? '') . '"><div><label>Year</label><input required name="year" placeholder="2026-27" value="' . e($edit['year'] ?? '') . '"></div><div><label>Note</label><input name="note" value="' . e($edit['note'] ?? '') . '"></div><div class="actions"><button>Save Year</button></div></form><table><tr><th>Year</th><th>Note</th><th>Actions</th></tr>';
    foreach ($years as $item) echo '<tr><td>' . e($item['year']) . '</td><td>' . e($item['note']) . '</td><td><a href="?route=years&edit=' . e($item['id']) . '">Edit</a> · <a href="?delete=years&id=' . e($item['id']) . '&back=years" onclick="return confirm(\'Delete this year?\')">Delete</a></td></tr>';
    echo '</table>';
}
elseif ($route === 'masters') {
    pageStart('Master Records'); $type = $_GET['type'] ?? array_key_first($types); $edit = isset($_GET['edit']) ? find('master_items', $_GET['edit']) : null;
    echo yearSelect($yearId, 'masters');
    if (!$yearId) echo '<div class="card">Select a working year to manage its master records.</div>';
    else {
        echo '<form method="get" class="card"><input type="hidden" name="route" value="masters"><input type="hidden" name="year_id" value="' . e($yearId) . '"><label>Master Type (parent)</label><select name="type" onchange="this.form.submit()">';
        foreach ($types as $key => $definition) echo '<option value="' . e($key) . '"' . selected($type, $key) . '>' . e($definition['label']) . '</option>';
        echo '</select></form><form method="post" enctype="multipart/form-data" class="card"><input type="hidden" name="action" value="master"><input type="hidden" name="id" value="' . e($edit['id'] ?? '') . '"><input type="hidden" name="year_id" value="' . e($yearId) . '"><input type="hidden" name="type" value="' . e($type) . '"><div class="grid">';
        foreach ($types[$type]['fields'] as $field => $label) echo masterField($field, $label, $edit['data'] ?? []);
        echo '</div><div class="actions"><button>Save ' . e($types[$type]['label']) . '</button></div></form><table><tr><th>Name / Number</th><th>Mobile</th><th>Address</th><th>Actions</th></tr>';
        foreach (masterItems($yearId, [$type]) as $item) { $d = $item['data']; echo '<tr><td>' . e($d['name'] ?? $d['user_name'] ?? $d['vehicle_number'] ?? '') . '</td><td>' . e($d['mobile'] ?? '') . '</td><td>' . e($d['address'] ?? '') . '</td><td><a href="?route=masters&year_id=' . e($yearId) . '&type=' . e($type) . '&edit=' . e($item['id']) . '">Edit</a> · <a href="?delete=master_items&id=' . e($item['id']) . '&back=masters&year_id=' . e($yearId) . '" onclick="return confirm(\'Delete this record?\')">Delete</a></td></tr>'; }
        echo '</table>';
    }
}
elseif ($route === 'sync') {
    pageStart('Mobile Sync Receiver');
    $sessionId = $_GET['session'] ?? ''; $token = $_GET['token'] ?? '';
    if (!$sessionId || !$token || !validSyncSession($sessionId, $token)) {
        $ip = gethostbyname(gethostname());
        echo '<div class="card"><p>This laptop is the local data receiver. Connect your phone and laptop to the same Wi-Fi network (or laptop hotspot), then create a pairing QR.</p><form method="post" class="grid"><input type="hidden" name="action" value="sync_session"><div><label>Laptop Wi-Fi IP and port</label><input required name="host" value="' . e($ip . ':8080') . '" placeholder="192.168.1.5:8080"><p class="muted">Do not use localhost; the phone cannot reach it. Run <code>ipconfig</code> and use the laptop IPv4 address.</p></div><div class="actions"><button>Create 10-minute pairing QR</button></div></form></div>';
    } else {
        $session = null; foreach (rawCollection('sync_sessions') as $item) if ($item['id'] === $sessionId) $session = $item;
        $host = $session['host'] ?? ''; $api = 'http://' . $host . '/?route=sync-api&session=' . rawurlencode($sessionId) . '&token=' . rawurlencode($token); $pair = 'brickkiln-sync://pair?api=' . rawurlencode($api);
        echo '<div class="card"><h2>Scan from the Brick Kiln mobile app</h2><p class="warning">This QR expires in 10 minutes. Confirm the same 6-digit code on both devices before syncing.</p><div id="qrcode" class="qr"></div><p><b>Pairing code:</b> <span id="pair-code"></span></p><p class="muted">The mobile app uses this session to download the current laptop vault or upload new changes. Internet is not required.</p><details><summary>Technical fallback</summary><p class="code">' . e($pair) . '</p></details></div><script src="assets/qrcode.js"></script><script>const payload=' . json_encode($pair) . ';const qr=qrcode(0,"M");qr.addData(payload);qr.make();document.querySelector("#qrcode").innerHTML=qr.createSvgTag({cellSize:5,margin:4});document.querySelector("#pair-code").textContent=' . json_encode(strtoupper(substr(hash('sha256', $sessionId . $token), 0, 6))) . ';</script>';
    }
}
else {
    $configs = [
        'expense' => ['title' => 'Expenses', 'collection' => 'expenses', 'fields' => ['date' => 'Date', 'party_id' => 'Party', 'amount' => 'Amount', 'note' => 'Note']],
        'sale' => ['title' => 'Sales / Income', 'collection' => 'sales', 'fields' => ['date' => 'Date', 'challan_no' => 'Challan No', 'party_id' => 'Party Name', 'city' => 'City / Village', 'material_type' => 'Material Type', 'vehicle_id' => 'Vehicle', 'driver_id' => 'Driver Name', 'sell_by_id' => 'Sell By', 'quantity' => 'Quantity', 'rate' => 'Rate', 'note' => 'Note']],
        'purchase' => ['title' => 'Material Purchase', 'collection' => 'purchases', 'fields' => ['date' => 'Date', 'party_id' => 'Party Name', 'material_type' => 'Material Type', 'weight' => 'Weight', 'rate' => 'Rate / Ton', 'vehicle_id' => 'Vehicle No', 'driver_id' => 'Driver Name', 'note' => 'Note']],
        'payment' => ['title' => 'Payment Received', 'collection' => 'payments', 'fields' => ['date' => 'Date', 'party_id' => 'Party Name', 'amount' => 'Amount', 'note' => 'Note']],
    ];
    if (isset($configs[$route])) {
        $config = $configs[$route]; pageStart($config['title']); echo yearSelect($yearId, $route);
        if ($yearId) {
            $edit = isset($_GET['edit']) ? find($config['collection'], $_GET['edit']) : null; $r = $edit ?: [];
            $parties = masterItems($yearId, ['bricks_party', 'material_party', 'seth', 'farmer']); $vehicles = masterItems($yearId, ['vehicle', 'mitti_vehicle']); $drivers = masterItems($yearId, ['driver', 'jcb_driver']); $users = masterItems($yearId, ['user']);
            echo '<form method="post" class="card"><input type="hidden" name="action" value="' . e($route) . '"><input type="hidden" name="id" value="' . e($r['id'] ?? '') . '"><input type="hidden" name="year_id" value="' . e($yearId) . '"><div class="grid">';
            foreach ($config['fields'] as $key => $label) {
                $value = $r[$key] ?? ($key === 'date' ? date('Y-m-d') : ''); echo '<div' . (in_array($key, ['note'], true) ? ' class="wide"' : '') . '><label>' . e($label) . '</label>';
                if ($key === 'party_id') echo '<select required name="record[' . $key . ']">' . options($parties, $value, 'Select party') . '</select>';
                elseif ($key === 'vehicle_id') echo '<select name="record[' . $key . ']">' . options($vehicles, $value, 'Select vehicle') . '</select>';
                elseif ($key === 'driver_id') echo '<select name="record[' . $key . ']">' . options($drivers, $value, 'Select driver') . '</select>';
                elseif ($key === 'sell_by_id') echo '<select name="record[' . $key . ']">' . options($users, $value, 'Select user') . '</select>';
                elseif ($key === 'material_type') echo '<select name="record[' . $key . ']"><option' . selected($value, 'Bricks') . '>Bricks</option><option' . selected($value, 'Roda') . '>Roda</option></select>';
                elseif ($key === 'note') echo '<textarea name="record[' . $key . ']">' . e($value) . '</textarea>';
                else { $input = $key === 'date' ? 'date' : (in_array($key, ['amount','rate','weight','quantity'], true) ? 'number' : 'text'); echo '<input ' . ($input === 'number' ? 'step="0.01" min="0" ' : '') . 'type="' . $input . '" required name="record[' . $key . ']" value="' . e((string)$value) . '">'; }
                echo '</div>';
            }
            echo '</div><div class="actions"><button>Save ' . e($config['title']) . '</button></div></form><table><tr><th>Date</th><th>Party</th><th>Details</th><th>Amount</th><th>Actions</th></tr>';
            foreach (array_reverse(array_filter(readCollection($config['collection']), fn($x) => $x['year_id'] === $yearId)) as $item) { $amount = $item['amount'] ?? (($item['quantity'] ?? $item['weight'] ?? 0) * ($item['rate'] ?? 0)); echo '<tr><td>' . e($item['date']) . '</td><td>' . e(labelFor($item['party_id'] ?? '')) . '</td><td>' . e($item['challan_no'] ?? $item['material_type'] ?? $item['note'] ?? '') . '</td><td>' . number_format((float)$amount, 2) . '</td><td><a href="?route=' . e($route) . '&year_id=' . e($yearId) . '&edit=' . e($item['id']) . '">Edit</a> · <a href="?delete=' . e($config['collection']) . '&id=' . e($item['id']) . '&back=' . e($route) . '&year_id=' . e($yearId) . '" onclick="return confirm(\'Delete this transaction?\')">Delete</a></td></tr>'; }
            echo '</table>';
        }
    } elseif ($route === 'reports') {
        pageStart('Sales Reports'); echo yearSelect($yearId, 'reports');
        if ($yearId) {
            $filters = ['from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'party_id' => $_GET['party_id'] ?? '', 'material_type' => $_GET['material_type'] ?? '', 'city' => $_GET['city'] ?? '', 'vehicle_id' => $_GET['vehicle_id'] ?? '', 'driver_id' => $_GET['driver_id'] ?? '', 'sell_by_id' => $_GET['sell_by_id'] ?? '', 'rate' => $_GET['rate'] ?? '', 'challan_no' => $_GET['challan_no'] ?? ''];
            $allSales = array_filter(readCollection('sales'), fn($x) => $x['year_id'] === $yearId);
            $sales = array_filter($allSales, function ($x) use ($filters) { foreach ($filters as $key => $value) { if ($value === '') continue; if ($key === 'from' && $x['date'] < $value) return false; if ($key === 'to' && $x['date'] > $value) return false; if (!in_array($key, ['from','to'], true) && (string)($x[$key] ?? '') !== $value) return false; } return true; });
            $parties = masterItems($yearId, ['bricks_party', 'material_party', 'seth', 'farmer']); $vehicles = masterItems($yearId, ['vehicle', 'mitti_vehicle']); $drivers = masterItems($yearId, ['driver', 'jcb_driver']); $users = masterItems($yearId, ['user']);
            echo '<form class="card grid"><input type="hidden" name="route" value="reports"><input type="hidden" name="year_id" value="' . e($yearId) . '"><div><label>From</label><input type="date" name="from" value="' . e($filters['from']) . '"></div><div><label>To</label><input type="date" name="to" value="' . e($filters['to']) . '"></div><div><label>Party</label><select name="party_id">' . options($parties, $filters['party_id'], 'All parties') . '</select></div><div><label>Material</label><select name="material_type"><option value="">All materials</option><option' . selected($filters['material_type'], 'Bricks') . '>Bricks</option><option' . selected($filters['material_type'], 'Roda') . '>Roda</option></select></div><div><label>City / Village</label><input name="city" value="' . e($filters['city']) . '"></div><div><label>Vehicle</label><select name="vehicle_id">' . options($vehicles, $filters['vehicle_id'], 'All vehicles') . '</select></div><div><label>Driver</label><select name="driver_id">' . options($drivers, $filters['driver_id'], 'All drivers') . '</select></div><div><label>Sell By</label><select name="sell_by_id">' . options($users, $filters['sell_by_id'], 'All users') . '</select></div><div><label>Rate</label><input type="number" step="0.01" name="rate" value="' . e($filters['rate']) . '"></div><div><label>Challan No</label><input name="challan_no" value="' . e($filters['challan_no']) . '"></div><div class="actions"><button>Apply Filters</button></div></form>';
            $total = array_sum(array_map(fn($x) => ($x['quantity'] ?? 0) * ($x['rate'] ?? 0), $sales)); $bricks = array_sum(array_map(fn($x) => ($x['material_type'] ?? '') === 'Bricks' ? ($x['quantity'] ?? 0) : 0, $sales));
            echo '<div class="stats"><div class="stat"><b>Total Sales Amount</b><br>₹ ' . number_format($total, 2) . '</div><div class="stat"><b>Total Bricks Sold</b><br>' . number_format($bricks, 2) . '</div><div class="stat"><b>Matching Sales</b><br>' . count($sales) . '</div></div><div class="card"><table><tr><th>Date</th><th>Challan</th><th>Party</th><th>Material</th><th>City</th><th>Vehicle / Driver</th><th>Qty × Rate</th><th>Amount</th></tr>';
            foreach ($sales as $x) echo '<tr><td>' . e($x['date']) . '</td><td>' . e($x['challan_no']) . '</td><td>' . e(labelFor($x['party_id'])) . '</td><td>' . e($x['material_type']) . '</td><td>' . e($x['city']) . '</td><td>' . e(labelFor($x['vehicle_id'] ?? '')) . ' / ' . e(labelFor($x['driver_id'] ?? '')) . '</td><td>' . e((string)$x['quantity']) . ' × ' . e((string)$x['rate']) . '</td><td>₹ ' . number_format($x['quantity'] * $x['rate'], 2) . '</td></tr>';
            echo '</table></div><h2>Party Outstanding / Payment Received</h2><table><tr><th>Party</th><th>Sales</th><th>Payments Received</th><th>Outstanding</th></tr>';
            $paymentTotals = []; foreach (readCollection('payments') as $p) if ($p['year_id'] === $yearId) $paymentTotals[$p['party_id']] = ($paymentTotals[$p['party_id']] ?? 0) + $p['amount']; $partyTotals = []; foreach ($allSales as $s) $partyTotals[$s['party_id']] = ($partyTotals[$s['party_id']] ?? 0) + $s['quantity'] * $s['rate'];
            foreach (array_unique(array_merge(array_keys($partyTotals), array_keys($paymentTotals))) as $party) { $saleTotal = $partyTotals[$party] ?? 0; $paid = $paymentTotals[$party] ?? 0; echo '<tr><td>' . e(labelFor($party)) . '</td><td>₹ ' . number_format($saleTotal, 2) . '</td><td>₹ ' . number_format($paid, 2) . '</td><td>₹ ' . number_format($saleTotal - $paid, 2) . '</td></tr>'; }
            echo '</table>';
        }
    }
}
pageEnd();
