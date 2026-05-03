# PHP URL Shortener API PoC

A proof-of-concept URL shortener API built with vanilla PHP and a JSON file for storage.

## Quick Start

Run the built-in PHP server:
```bash
php -S localhost:8080 public/index.php
```

## API Documentation

### Create Short URL
`POST /api/shorten`
- **Request Body:** `{"url": "https://example.com"}`
- **Response:** `201 Created` with the short code.

### Redirect
`GET /{code}`
- **Response:** `301 Moved Permanently` to the original destination.

### Get Link Details
`GET /api/{code}`
- **Response:** JSON object containing original URL and metadata.

### Get Statistics
`GET /api/stats`
- **Response:** JSON object containing total links created and total clicks.

## Example Usage

```bash
# Shorten a URL
curl -X POST http://localhost:8080/api/shorten \
     -H "Content-Type: application/json" \
     -d '{"url": "https://www.google.com"}'

# Get stats
curl http://localhost:8080/api/stats
```

## Project Structure

```text
php-url-shortener/
├── public/
│   └── index.php       # Entry point & request handling
├── src/
│   ├── Router.php      # Routing logic
│   ├── Shortener.php   # URL hashing and logic
│   └── Store.php       # JSON file persistence
└── data/               # Store for JSON database (created automatically)
```

## Requirements
- PHP 8.0+
- No external dependencies.
