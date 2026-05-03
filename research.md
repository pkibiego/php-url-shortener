# PHP URL Shortener API PoC — Research & Recommendation

> **Context:** Build the simplest possible URL shortener API. Stores data in a flat
> JSON file. No database. No external services. Pure PHP.

---

## 1. Vanilla PHP vs Micro-Framework

| Criterion | Vanilla PHP (built-in server) | Slim 4 | Lumen |
|-----------|-------------------------------|--------|-------|
| Dependencies | None (stdlib only) | Composer + 6 transitive deps | Composer + ~40 transitive deps |
| Setup | `php -S localhost:8080` | Composer install + bootstrap | Composer install + bootstrap |
| Routing | Manual `$_SERVER['REQUEST_URI']` + `parse_url` | Built-in, fast, PSR-7 | Built-in, Laravel-style |
| JSON handling | `json_decode`/`json_encode` (stdlib) | Manual (same) | Eloquent ORM (overkill) |
| Learning curve | Zero (every PHP dev knows it) | 15 min to grok | Hours |
| Deployment | Single file or small dir | vendor/ dir (~2MB) | vendor/ dir (~20MB) |
| PoC suitability | ★★★★★ | ★★★☆☆ | ★☆☆☆☆ |

### Decision: **Vanilla PHP**

A PoC needs to be **runnable in 5 seconds with zero setup**. Vanilla PHP requires
only `php -S localhost:8080 -t public/`. No `composer install`. No autoloader.
No config files. If this ever grows, migrating routes to Slim is trivial — Slim
uses PSR-7 request/response, and the route handlers are just callables.

Slim would be a reasonable choice if we expected 20+ routes or needed middleware
stacks. For three endpoints (create, redirect, stats), it's dead weight.

Lumen is outright overkill — it pulls in the Illuminate container, Eloquent (or
Fluent), and a service provider architecture, all for a JSON file store.

---

## 2. Project Structure

```
php-url-shortener/
├── public/
│   └── index.php          # Front controller — all requests enter here
├── src/
│   ├── Store.php           # JSON file read/write with locking
│   ├── Shortener.php       # URL validation + short code generation
│   └── Router.php          # Minimal request router (optional, can inline)
├── data/
│   └── urls.json           # Runtime data file (auto-created)
├── tests/                  # (optional, for TDD)
│   ├── StoreTest.php
│   └── ShortenerTest.php
├── research.md             # This file
└── README.md
```

**Why a front controller?** PHP's built-in server can route all requests through
`public/index.php` with a router script. This avoids scattering `.php` files
around and keeps URL structure clean.

**Why `src/`?** Separates business logic from the entry point. Makes testing
possible (require the class files, no HTTP bootstrap needed).

**Why `data/` outside `public/`?** The JSON file must never be web-accessible.
PHP's built-in server returns 404 for files without a matching route when using
a router script, but keeping it outside the docroot is defense in depth.

---

## 3. JSON Storage & Concurrent Requests

PHP's built-in server is **single-threaded, single-process** — it handles one
request at a time. This means **concurrent writes are impossible with `php -S`**
in production. However, the code should still use proper file locking so it
survives a move to php-fpm + nginx later.

### Locking Strategy

| Approach | Pros | Cons |
|----------|------|------|
| `flock($fp, LOCK_EX)` | OS-level, blocks until lock released | Must keep file handle open; PHP's `flock` is advisory |
| Atomic rename (`temp + rename`) | Immune to partial writes, no lock needed | Slightly more code; only atomic on same filesystem |
| No locking | Simplest | Data corruption under php-fpm concurrency |

### Recommendation: **Atomic rename (write-to-temp-then-rename)**

```php
// Read — no lock needed (read is atomic in practice for small files)
$data = json_decode(file_get_contents($path), true);

// Write — atomic via temp file + rename
$tmp = $path . '.' . getmypid() . '.tmp';
file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
rename($tmp, $path);  // atomic on Linux
```

This is safer than `flock` because:
- `rename()` is **atomic** on Linux/BSD (POSIX guarantee). The file at `$path`
  is either the old version or the new version — never a half-written mess.
- No lock contention, no deadlocks, no stale lock files.
- Survives a crash during write (the temp file is orphaned but harmless).

The one edge case: two writes at the exact same millisecond could both read the
same state, then one `rename` overwrites the other. This is a **lost update**.
Acceptable for a PoC. In production you'd use a DB with transactions.

---

## 4. URL Validation

PHP provides `filter_var($url, FILTER_VALIDATE_URL)` which checks RFC 2396
compliance. For a PoC, this is sufficient.

```php
function isValidUrl(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

**What it catches:** malformed schemes, missing host, invalid characters.
**What it doesn't catch:** DNS resolution (do we care?), redirect loops,
URLs to private IPs (SSRF).

For a PoC, we don't need DNS checks. Add a note in the README that this is
a known limitation.

Edge case: `FILTER_VALIDATE_URL` rejects URLs without a scheme. The handler
should prepend `https://` if the user omits it, or return a 422 error asking
for a full URL.

### Recommendation: Prepend `https://` if scheme is missing

```php
if (!preg_match('~^https?://~i', $url)) {
    $url = 'https://' . $url;
}
```

---

## 5. Short Code Generation

| Method | Length | Collision risk (at 1M URLs) | Crypto-safe | Deterministic |
|--------|--------|-----------------------------|-------------|---------------|
| `bin2hex(random_bytes(4))` | 8 chars | ~0.01% | Yes | No |
| `bin2hex(random_bytes(6))` | 12 chars | Negligible | Yes | No |
| `base62_encode(random_bytes(4))` | ~6 chars | ~0.01% | Yes | No |
| `substr(md5($url), 0, 8)` | 8 chars | Higher (hash collision) | No | Yes |
| Auto-increment counter | Varies | Zero | N/A | Yes |

### Recommendation: `bin2hex(random_bytes(4))` → 8-char hex code

```php
function generateCode(): string
{
    return bin2hex(random_bytes(4));  // e.g. "a3f9c21b"
}
```

- **8 characters** is reasonable for a short URL path (`/a3f9c21b`).
- **4 bytes = 2^32 possibilities (~4.3 billion)**. Collision probability at
  100K URLs is ~0.1%. At 1M URLs, ~10%. Acceptable for a PoC.
- If a collision happens, regenerate (loop until unique). With 100K URLs, the
  expected retries is <1.
- `random_bytes()` uses the OS CSPRNG (`/dev/urandom`), cryptographically safe.
- **No external libraries needed.** Pure stdlib.

**Why not base62?** Base62 requires a custom encoder (PHP has no built-in
base62). That's 15 lines of unnecessary code for a PoC. Hex is perfectly
fine — the difference between 6 and 8 chars is invisible in practice.

**Why not MD5?** Hash-of-URL is deterministic: the same long URL always
produces the same short code. This sounds nice but is actually a problem:
- Two users shortening the same URL get the same code (privacy leak — you can
  enumerate URLs by probing short codes).
- Hash collisions are real at scale.
- Random codes are simpler and safer.

---

## 6. API Design (implicit from above)

Three endpoints:

| Method | Path | Purpose | Response |
|--------|------|---------|----------|
| `POST` | `/shorten` | Create short URL | `201 {"short_url": "http://.../abc12345", ...}` |
| `GET` | `/{code}` | Redirect to long URL | `302 Location: https://...` |
| `GET` | `/stats/{code}` | View click count (optional) | `200 {"clicks": 42, ...}` |

Request body for `POST /shorten`:
```json
{"url": "https://example.com/very/long/path"}
```

---

## 7. Final Recommendation

**Vanilla PHP, atomic-rename JSON store, 8-char hex short codes.**

A complete working PoC can fit in **under 150 lines of PHP** across 4 files
(`public/index.php`, `src/Store.php`, `src/Shortener.php`, `.htrouter.php`).
It starts with one command: `php -S localhost:8080 -t public/ .htrouter.php`.

No Composer. No framework. No database. No Docker. Just PHP ≥7.4.

### Rationale Summary

1. **Vanilla PHP** because the alternative (Slim/Lumen) adds setup friction
   without solving any problem we actually have for a 3-endpoint PoC.
2. **Atomic rename** because it's simpler than `flock()` and immune to partial
   writes. Lost-update race is acceptable for a PoC.
3. **`bin2hex(random_bytes(4))`** because it's one function call, no custom
   encoding, collision rate is fine for PoC scale.
4. **`FILTER_VALIDATE_URL` + `https://` prepend** because it's built-in and
   catches 95% of bad input.
5. **Front controller + `src/`** because it's minimal separation of concerns
   without framework ceremony.

### What This Won't Handle (documented limitations)

- High concurrency (single-threaded `php -S`)
- 10M+ URLs (JSON file will get slow to parse; switch to SQLite then)
- Custom short codes (not in PoC scope)
- Expiring URLs (not in PoC scope)
- Rate limiting (not in PoC scope)
- Authentication (not in PoC scope)
