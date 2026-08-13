# File Upload API

## POST /api/v1/files/upload.php

`Authorization: Bearer <token>` required. `Content-Type: multipart/form-data` (not JSON — this is the one endpoint in the whole API that isn't a JSON body).

One unified endpoint for the three upload flows that already exist on the web app, dispatched by a `type` form field. Reuses the exact same allowed-extension lists, filename conventions, and destination tables as `api/profile.php` and `api/driver-api.php` — no new upload rules were invented.

**Form fields:**
| Field | Required | Notes |
|---|---|---|
| `file` | always | the file itself |
| `type` | always | `profile`, `vehicle_photo`, or `document` |
| `document_name` | only when `type=document` | e.g. `"Drivers License"`, `"Car Insurance"` |

### type=profile

Any role. Allowed extensions: `jpg`, `jpeg`, `png`. Stored as `profile_<user_id>_<uniqid>.<ext>` in `uploads/`, and immediately written to `users.profile_image` for the calling user.

**Success `data`:** `{ "file_path": "profile_114_65abf2c9e1a34.png" }`

### type=vehicle_photo

**Driver only** (403 `forbidden` otherwise). Allowed extensions: `jpg`, `jpeg`, `png`, `webp`. The vehicle is always looked up as `SELECT id FROM vehicles WHERE driver_id = <token's user id>` — a client can never target another driver's vehicle by supplying a vehicle id. Stored as `vehicle_<vehicle_id>_<uniqid>.<ext>`, inserted into `vehicle_photos (vehicle_id, photo_path)`.

**Error code:** `no_vehicle` (400) — driver has no vehicle row yet; must complete vehicle setup first.

**Success `data`:** `{ "file_path": "vehicle_10_65abf331c9e21.png" }`

### type=document

**Driver only.** Allowed extensions: `pdf`, `doc`, `docx`, `jpg`, `jpeg`, `png`. Requires `document_name`. Same driver-owns-vehicle lookup as `vehicle_photo`. Stored as `doc_<vehicle_id>_<uniqid>.<ext>`, inserted into `vehicle_verifications (public_id, vehicle_id, document_name, document_path)` with a generated `VER-` public id.

**Success `data`:** `{ "document_name": "Drivers License", "file_path": "doc_10_65abf3a01f2b7.pdf" }`

### Common errors

`validation_error` (400) — missing/invalid `type`, or missing `document_name` for a document upload. `upload_error` (400) — no file present or a PHP upload error. `invalid_file_type` (400) — extension not in the allowed list for that `type`. `upload_failed` (400) — `move_uploaded_file()` itself failed (disk/permissions). `no_vehicle` (400) — see above.

### Example (curl)

```bash
curl -X POST https://your-host/merit_drives/api/v1/files/upload.php \
  -H "Authorization: Bearer <token>" \
  -F "type=document" \
  -F "document_name=Drivers License" \
  -F "file=@license.pdf;type=application/pdf"
```
