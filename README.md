# Brick Kiln ERP (PHP flat-file edition)

This is a dependency-free PHP 8.1+ application. Data is kept in JSON files under `storage/data`; uploaded photos live in `storage/uploads`.

## Run locally

```powershell
cd brick-kiln-erp
php -S localhost:8080 -t public
```

Open `http://localhost:8080`. Create a year first, then add master records and transactions.

The application writes JSON atomically and uses an exclusive lock for each write. Back up `storage/data` regularly.

## Laptop receiver and mobile sync

Start the receiver so it listens on the local network:

```powershell
php -S 0.0.0.0:8080 -t public
```

Open **Sync Receiver** in the menu. Enter the laptop's IPv4 address (from `ipconfig`) and create the QR code. The pairing QR expires after 10 minutes. A mobile client scans it to download the full JSON vault in the morning, or to upload its changes at night.

The receiver exposes two authenticated, local-network endpoints for the mobile APK:

- `GET ...?route=sync-api&action=pull&session=...&token=...` — full vault and photos
- `POST ...?route=sync-api&action=push&session=...&token=...` — changed records and photos

Records use stable IDs, revisions, modification timestamps, and delete markers so additions and normal edits from multiple devices can merge without replacing the whole data folder. The phone must be on the same Wi-Fi network or laptop hotspot. No internet, Apache, database server, or hosted website is required.
