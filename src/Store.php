<?php

declare(strict_types=1);

/**
 * JSON file storage with atomic rename.
 * Read is atomic on small files.
 * Write uses temp file + rename() for atomicity.
 */
class Store
{
    private string $path;

    public function __construct(string $path = __DIR__ . '/../data/links.json')
    {
        $this->path = $path;
        $this->ensureDataDir();
    }

    private function ensureDataDir(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Read links from JSON file.
     * Returns array or empty array if file doesn't exist or is invalid.
     */
    public function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Write links to JSON file atomically.
     * Uses temp file + rename() to avoid partial writes.
     */
    public function write(array $links): void
    {
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $this->path);
    }

    /**
     * Check if a short code exists.
     */
    public function exists(string $code): bool
    {
        $links = $this->read();
        return isset($links[$code]);
    }

    /**
     * Get a single link by code.
     * Returns null if not found.
     */
    public function get(string $code): ?array
    {
        $links = $this->read();
        return $links[$code] ?? null;
    }

    /**
     * Add a new link.
     * Returns the stored link data.
     * @throws Exception if write fails
     */
    public function add(string $code, array $link): array
    {
        $links = $this->read();
        $links[$code] = $link;
        $this->write($links);
        return $link;
    }

    /**
     * Increment click count for a link.
     * @throws Exception if write fails
     */
    public function incrementClicks(string $code): int
    {
        $links = $this->read();
        if (!isset($links[$code])) {
            throw new Exception('Short code not found');
        }

        $links[$code]['clicks'] = ($links[$code]['clicks'] ?? 0) + 1;
        $this->write($links);
        return $links[$code]['clicks'];
    }

    /**
     * Get total links count.
     */
    public function getTotalLinks(): int
    {
        $links = $this->read();
        return count($links);
    }

    /**
     * Get total clicks across all links.
     */
    public function getTotalClicks(): int
    {
        $links = $this->read();
        $total = 0;
        foreach ($links as $link) {
            $total += ($link['clicks'] ?? 0);
        }
        return $total;
    }
}
