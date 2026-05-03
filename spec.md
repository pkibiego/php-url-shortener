# PHP URL Shortener — Specification

> **Version:** 1.0.0  
> **Stack:** Vanilla PHP (no framework, no Composer), JSON file storage  
> **PHP Requirement:** >= 7.4  

---

## 1. Project Overview

A minimal URL shortener API written in plain PHP. Stores shortened URLs in a flat JSON file (`data/links.json`). No database, no external services, no framework. Runs with a single command:

```bash
php -S localhost:8080 -t public/
```

---

## 2. Project Structure

```
php-url-shortener/
├── public/
│   └── index.php          # Front controller — all HTTP requests enter here
├── src/
│   ├── Store.php          # JSON file read/write with atomic rename
│   ├── Shortener.php      # URL validation + short code generation
│   └── Router.php         # Minimal request dispatcher
├── data/
│   └── links.json         # Runtime JSON storage (outside docroot, auto-created)
├── tests/
│   ├── StoreTest.php      # Unit tests for Store
│   └── ShortenerTest.php  # Unit tests for Shortener + Router
├── spec.md                # This document
├── research.md            # Decision log (framework choice, storage strategy, etc.)
└── README.md              # Quick-start guide
```

### Rationale

- **`public/index.php`** — front controller pattern. PHP built-in server routes all requests through one entry point. Keeps URL structure clean (`/{code}` instead of `redirect.php?code=abc`).
- **`src/`** — separates business logic from HTTP bootstrap. Enables unit testing without spinning up a server.
- **`data/links.json`** — placed **outside `public/`** so it is never web-accessible. PHP's built-in router script will 404 unmatched files, but defense in depth matters.

---

## 3. API Endpoints

### 3.1 POST /shorten — Create a short URL

**Request headers:** `Content-Type: application/json`

**Request body schema:**
```json
{
  "url": "string (required) — the long URL to shorten"
}
```

**Response `201 Created`:**
```json
{
  "short_url": "http://localhost:8080/a3f9c21b",
  "code": "a3f9c21b",
  "original_url": "https://example.com/very/long/path",
  "created_at": "2026-05-03T18:30:00Z"
}
```

**Error `422 Unprocessable Entity` (invalid/missing URL):**
```json
{
  "error": "Missing or invalid URL"
}
```

**Error `500 Internal Server Error` (storage failure):**
```json
{
  "error": "Failed to save link"
}
```

---

### 3.2 GET /{code} — Redirect to original URL

**Path parameter:** `code` — 8-character hex short code.

**Success `302 Found`:**
- Response header: `Location: <original_url>`
- Response body: empty

**Error `404 Not Found` (unknown code):**
```json
{
  "error": "Short code not found"
}
```

---

### 3.3 GET /stats/{code} — Retrieve link statistics

**Path parameter:** `code` — 8-character hex short code.

**Response `200 OK`:**
```json
{
  "code": "a3f9c21b",
  "original_url": "https://example.com/very/long/path",
  "clicks": 42,
  "created_at": "2026-05-03T18:30:00Z"
}
```

**Error `404 Not Found` (unknown code):**
```json
{
  "error": "Short code not found"
}
```

---

## 4. JSON Storage Design

### File: `data/links.json`

```json
{
  "a3f9c21b": {
    "url": "https://example.com/very/long/path",
    "clicks": 42,
    "created_at": "2026-05-03T18:30:00Z"
  },
  "b8e2d45f": {
    "url": "https://another.example.com",
    "clicks": 7,
    "created_at": "2026-05-03T19:00:00Z"
  }
}
```

### Atomic Write Strategy

PHP's built-in server is single-threaded, so true concurrency is impossible locally. The code must still use safe writes so it survives a future move to php-fpm + nginx.

**Read (no lock needed for small files):**
```php
$data = json_decode(file_get_contents($path), true) ?? [];
```

**Write (atomic via temp file + rename):**
```php
$tmp = $path . '.' . getmypid() . '.tmp';
file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
rename($tmp, $path);  // POSIX-atomic on Linux/BSD
```

**Why atomic rename over `flock`:**
- `rename()` guarantees the file at `$path` is either the old version or the new version — never a partial write.
- No lock contention, no deadlocks, no stale lock files.
- Crash during write leaves an orphaned temp file (harmless).

**Known limitation:** two writes in the same millisecond could both read the same state, then one `rename` overwrites the other (lost update). Acceptable for a PoC. Production should use SQLite or a real database with transactions.

---

## 5. Short Code Generation

**Algorithm:** `bin2hex(random_bytes(4))`

- **Length:** 8 hexadecimal characters (e.g., `a3f9c21b`).
- **Space:** 4 bytes = 2^32 (~4.3 billion) possibilities.
- **Collision probability:** ~0.1% at 100K URLs, ~10% at 1M URLs.
- **Collision handling:** regenerate in a loop until unique.
- **Security:** `random_bytes()` uses the OS CSPRNG (`/dev/urandom`) — cryptographically safe.

**Implementation sketch:**
```php
function generateCode(array $existing): string
{
    do {
        $code = bin2hex(random_bytes(4));
    } while (isset($existing[$code]));
    return $code;
}
```

**Why not base62 or MD5?**
- Base62 requires a custom encoder — unnecessary code for a PoC. 8 chars vs 6 chars is invisible in practice.
- MD5-of-URL is deterministic (same URL → same code), which leaks privacy and suffers from hash collisions.

---

## 6. URL Validation

**Primary check:** `filter_var($url, FILTER_VALIDATE_URL)`

**Scheme normalization:** if the URL does not start with `http://` or `https://`, prepend `https://` before validation.

```php
function normalizeUrl(string $url): string
{
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function isValidUrl(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

**What it catches:** malformed schemes, missing host, invalid characters.  
**What it does not catch:** DNS resolution failures, redirect loops, SSRF to private IPs. These are documented limitations for the PoC.

---

## 7. Error Handling Strategy

| Scenario | HTTP Status | Response Body | Implementation |
|----------|-------------|---------------|----------------|
| Missing/invalid JSON body | `400` | `{"error":"Invalid JSON body"}` | `json_decode` failure or non-array |
| Missing `url` key | `422` | `{"error":"Missing or invalid URL"}` | isset check + validation |
| URL fails `filter_var` | `422` | `{"error":"Missing or invalid URL"}` | after normalization |
| Short code not found | `404` | `{"error":"Short code not found"}` | isset check in store |
| Storage write failure | `500` | `{"error":"Failed to save link"}` | exception from Store |
| Unknown route | `404` | `{"error":"Not found"}` | default router fallback |

All error responses include `Content-Type: application/json`.

---

## 8. Testing Strategy

### 8.1 Unit Tests (no HTTP server needed)

- **StoreTest.php** — test `Store::read()`, `Store::write()`, `Store::exists()`, `Store::incrementClicks()` using a temporary JSON file in `/tmp`.
- **ShortenerTest.php** — test `normalizeUrl()`, `isValidUrl()`, `generateCode()` uniqueness and length.

Run with:
```bash
php tests/StoreTest.php
php tests/ShortenerTest.php
```

Each test file should be self-executing: include the source class, run assertions, print `PASS`/`FAIL` counts, and exit with code 0 or 1. No external test framework (PHPUnit) required — keeps the project dependency-free.

### 8.2 Manual / Integration Tests

Spin up the built-in server and exercise the endpoints with `curl`:

```bash
# 1. Start server
php -S localhost:8080 -t public/

# 2. Create a short URL
curl -X POST http://localhost:8080/shorten \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com"}'

# 3. Follow redirect
curl -v http://localhost:8080/<code>

# 4. Check stats
curl http://localhost:8080/stats/<code>
```

### 8.3 Edge Cases to Verify

- URL without scheme (`example.com`) → normalized to `https://example.com`.
- Invalid URL (`not-a-url`) → `422`.
- Duplicate long URLs → each request generates a **new** random code.
- Collision scenario — statistically unlikely, but `generateCode` loop must be covered in unit tests by mocking `random_bytes` or injecting a seeded RNG (if possible) or by pre-filling the store.
- Click counter increments correctly on each redirect.

---

## 9. Out-of-Scope (Documented Limitations)

These are intentionally excluded from the PoC but noted for future work:

- High concurrency under php-fpm (atomic rename mitigates but does not eliminate lost updates).
- 10M+ URLs (JSON parse time grows linearly; switch to SQLite).
- Custom short codes / vanity URLs.
- Expiring / time-bombed URLs.
- Rate limiting.
- Authentication / user accounts.
- HTTPS termination (assumed handled by reverse proxy in production).
