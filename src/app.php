<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const DATA_DIR = ROOT . '/storage/data';
const UPLOAD_DIR = ROOT . '/storage/uploads';

function boot(): void {
    foreach ([DATA_DIR, UPLOAD_DIR] as $directory) {
        if (!is_dir($directory)) mkdir($directory, 0775, true);
    }
    foreach (['years', 'master_items', 'expenses', 'sales', 'purchases', 'payments', 'sync_sessions'] as $collection) {
        $path = DATA_DIR . "/$collection.json";
        if (!file_exists($path)) file_put_contents($path, "[]\n", LOCK_EX);
    }
}

function masterTypes(): array {
    $person = ['name' => 'Name', 'mobile' => 'Mobile', 'address' => 'Address', 'photo' => 'Photo', 'note' => 'Note'];
    return [
        'user' => ['label' => 'User Master', 'fields' => ['user_name' => 'User Name', 'password' => 'Password', 'role' => 'Role']],
        'patla_contractor' => ['label' => 'Patla Thekedar Master', 'fields' => $person],
        'patla' => ['label' => 'Patla Master', 'fields' => $person],
        'nikasi_contractor' => ['label' => 'Nikasi Thekedar Master', 'fields' => $person],
        'nikasi_labour' => ['label' => 'Nikasi Labour Master', 'fields' => $person],
        'bharai_contractor' => ['label' => 'Bharai Thekedar Master', 'fields' => $person],
        'bharai' => ['label' => 'Bharai Master', 'fields' => $person],
        'jalai_contractor' => ['label' => 'Jalai Thekedar Master', 'fields' => $person],
        'jalai_labour' => ['label' => 'Jalai Labour Master', 'fields' => $person],
        'mehtaji' => ['label' => 'Mehtaji Master', 'fields' => $person],
        'seth' => ['label' => 'Seth Master', 'fields' => ['name' => 'Name', 'note' => 'Note']],
        'rojwala' => ['label' => 'Rojwala Master', 'fields' => $person],
        'driver' => ['label' => 'Driver Master', 'fields' => $person],
        'vehicle' => ['label' => 'Vehicle Master', 'fields' => ['vehicle_number' => 'Vehicle Number', 'vehicle_type' => 'Vehicle Type', 'owner_name' => 'Owner Name']],
        'bricks_party' => ['label' => 'Bricks Party Master', 'fields' => $person],
        'material' => ['label' => 'Material Master', 'fields' => ['name' => 'Name']],
        'material_party' => ['label' => 'Material Party Master', 'fields' => ['name' => 'Name', 'mobile' => 'Mobile', 'address' => 'Address']],
        'farmer' => ['label' => 'Farmer Master', 'fields' => ['name' => 'Name', 'mobile' => 'Mobile', 'address' => 'Address']],
        'mitti_vehicle' => ['label' => 'Mitti Vehicle Master', 'fields' => ['vehicle_number' => 'Vehicle Number', 'vehicle_type' => 'Vehicle Type', 'owner_name' => 'Owner Name']],
        'jcb' => ['label' => 'JCB Master', 'fields' => ['name' => 'Name', 'year' => 'Year', 'owner_name' => 'Owner Name']],
        'jcb_driver' => ['label' => 'JCB Driver Master', 'fields' => ['name' => 'Name', 'mobile' => 'Mobile', 'address' => 'Address']],
        'jcb_helper' => ['label' => 'JCB Helper Master', 'fields' => ['name' => 'Name', 'mobile' => 'Mobile', 'address' => 'Address']],
    ];
}

function rawCollection(string $name): array {
    $content = @file_get_contents(DATA_DIR . "/$name.json");
    $data = json_decode($content ?: '[]', true);
    return is_array($data) ? $data : [];
}
function readCollection(string $name): array {
    return array_values(array_filter(rawCollection($name), fn($item) => empty($item['_sync']['deleted'])));
}
function writeCollection(string $name, array $data): void {
    $path = DATA_DIR . "/$name.json"; $temp = $path . '.tmp';
    file_put_contents($temp, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($temp, $path);
}
function id(): string { return bin2hex(random_bytes(8)); }
function localDeviceId(): string {
    $path = DATA_DIR . '/device.json';
    $device = json_decode(@file_get_contents($path) ?: '{}', true);
    if (!is_array($device) || empty($device['id'])) {
        $device = ['id' => 'laptop-' . id()];
        file_put_contents($path, json_encode($device), LOCK_EX);
    }
    return $device['id'];
}
function syncTime(): float { return microtime(true); }
function find(string $collection, string $id): ?array { foreach (readCollection($collection) as $item) if (($item['id'] ?? '') === $id) return $item; return null; }
function upsert(string $collection, array $record): void {
    $items = rawCollection($collection); $found = false; $now = syncTime();
    foreach ($items as $i => $item) if ($item['id'] === $record['id']) {
        $record['_sync'] = ['device_id' => localDeviceId(), 'created_at' => $item['_sync']['created_at'] ?? $now, 'updated_at' => $now, 'revision' => ($item['_sync']['revision'] ?? 0) + 1, 'deleted' => false];
        $items[$i] = $record; $found = true;
    }
    if (!$found) $record['_sync'] = ['device_id' => localDeviceId(), 'created_at' => $now, 'updated_at' => $now, 'revision' => 1, 'deleted' => false];
    if (!$found) $items[] = $record; writeCollection($collection, $items);
}
function remove(string $collection, string $id): void {
    $items = rawCollection($collection); $now = syncTime();
    foreach ($items as $i => $item) if ($item['id'] === $id) $items[$i]['_sync'] = ['device_id' => localDeviceId(), 'created_at' => $item['_sync']['created_at'] ?? $now, 'updated_at' => $now, 'revision' => ($item['_sync']['revision'] ?? 0) + 1, 'deleted' => true];
    writeCollection($collection, $items);
}
function mergeRemoteRecord(string $collection, array $incoming): bool {
    if (empty($incoming['id']) || !isset($incoming['_sync']['updated_at'])) return false;
    $items = rawCollection($collection); $incomingTime = (float)$incoming['_sync']['updated_at'];
    foreach ($items as $index => $local) if ($local['id'] === $incoming['id']) {
        if ($incomingTime <= (float)($local['_sync']['updated_at'] ?? 0)) return false;
        $items[$index] = $incoming; writeCollection($collection, $items); return true;
    }
    $items[] = $incoming; writeCollection($collection, $items); return true;
}
function allSyncData(): array {
    $collections = ['years', 'master_items', 'expenses', 'sales', 'purchases', 'payments']; $data = [];
    foreach ($collections as $collection) $data[$collection] = rawCollection($collection);
    $photos = [];
    foreach (glob(UPLOAD_DIR . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $path) $photos[basename($path)] = base64_encode((string)file_get_contents($path));
    return ['schema' => 1, 'device_id' => localDeviceId(), 'generated_at' => syncTime(), 'collections' => $data, 'photos' => $photos];
}
function createSyncSession(string $host): array {
    $session = ['id' => id(), 'token' => bin2hex(random_bytes(24)), 'host' => $host, 'expires_at' => time() + 600, 'created_at' => time()];
    $items = rawCollection('sync_sessions'); $items[] = $session; writeCollection('sync_sessions', $items); return $session;
}
function validSyncSession(string $sessionId, string $token): bool {
    foreach (rawCollection('sync_sessions') as $session) if ($session['id'] === $sessionId && hash_equals($session['token'], $token) && $session['expires_at'] >= time()) return true;
    return false;
}
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function flash(string $message): void { $_SESSION['flash'] = $message; }
function selected(string $actual, string $expected): string { return $actual === $expected ? ' selected' : ''; }
function masterItems(?string $yearId = null, ?array $types = null): array {
    return array_values(array_filter(readCollection('master_items'), function ($item) use ($yearId, $types) {
        return (!$yearId || $item['year_id'] === $yearId) && (!$types || in_array($item['type'], $types, true));
    }));
}
function labelFor(?string $id): string { $item = $id ? find('master_items', $id) : null; return $item['data']['name'] ?? $item['data']['user_name'] ?? $item['data']['vehicle_number'] ?? '—'; }
function options(array $items, string $selectedId = '', string $placeholder = 'Select'): string {
    $html = '<option value="">' . e($placeholder) . '</option>';
    foreach ($items as $item) $html .= '<option value="' . e($item['id']) . '"' . selected($selectedId, $item['id']) . '>' . e(labelFor($item['id'])) . '</option>';
    return $html;
}
function photo(array $existing): string {
    if (empty($_FILES['photo']['name']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) return $existing['photo'] ?? '';
    $extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) throw new RuntimeException('Photo must be JPG, PNG, or WEBP.');
    $name = id() . '.' . $extension;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . '/' . $name)) throw new RuntimeException('Unable to save photo.');
    return $name;
}
