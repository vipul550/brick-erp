# Mobile sync contract

The Android client stores the same six collections locally: `years`, `master_items`, `expenses`, `sales`, `purchases`, and `payments`. Each record has an `_sync` envelope:

```json
{
  "id": "stable-record-id",
  "_sync": {
    "device_id": "phone-unique-id",
    "created_at": 1787551000.123,
    "updated_at": 1787551015.456,
    "revision": 3,
    "deleted": false
  }
}
```

## Morning download

1. Scan the laptop QR.
2. Parse the `brickkiln-sync://pair?api=...` URI.
3. Display the six-character verification value to the user.
4. Request `GET {api}&action=pull`.
5. Replace/merge local records using the record ID and the highest `updated_at` timestamp. Save decoded photos using their supplied filename.

## Night upload

1. Scan a newly generated laptop QR.
2. Upload all locally changed records, including deleted records (delete markers), to `POST {api}&action=push` as JSON.
3. Include new photos as a `photos` object whose values are Base64 file content.
4. Treat the returned `merged_records` value as the sync result. Pull once more to receive changes made on the laptop or other phones.

The receiver accepts only a 10-minute pairing token. It never needs a public IP address; the laptop and phone must be on the same Wi-Fi/hotspot.
