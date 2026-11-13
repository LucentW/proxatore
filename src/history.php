<?php
/*
 * Proxatore, a proxy for viewing and embedding content from various platforms.
 * Copyright (C) 2025 OctoSpacc
 *
*/

// History lives in a SQLite DB (HISTORY_DB), entries expire after HISTORY_TTL seconds.
// Every failure here is non-fatal: history is a nice-to-have, never take the page down for it.

function historyDb(): PDO {
    static $db = null;
    if ($db) {
        return $db;
    }
    if (!is_dir(DATA_FOLDER)) {
        mkdir(DATA_FOLDER, 0777, true);
    }
    $db = new PDO('sqlite:' . HISTORY_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA auto_vacuum = FULL'); // only takes effect on a fresh DB: freed pages are returned to the OS on commit
    $db->exec('PRAGMA journal_mode = WAL'); // readers don't block the writer
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('CREATE TABLE IF NOT EXISTS history (
        platform TEXT NOT NULL,
        relativeurl TEXT NOT NULL,
        data TEXT NOT NULL,
        updated INTEGER NOT NULL,
        PRIMARY KEY (platform, relativeurl)
    ) WITHOUT ROWID');
    $db->exec('CREATE INDEX IF NOT EXISTS history_updated ON history (updated)');
    return $db;
}

function historyCutoff(): int {
    return time() - HISTORY_TTL;
}

// drop expired entries and their cached files
function pruneHistory(PDO $db): void {
    $db->prepare('DELETE FROM history WHERE updated < ?')->execute([historyCutoff()]);
    if (is_dir(CACHE_FOLDER)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CACHE_FOLDER, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getMTime() < historyCutoff()) {
                @unlink($file->getPathname());
            }
        }
    }
}

function saveHistory(array $entry): void {
    if (!SAVE_HISTORY) {
        return;
    }
    if ($entry['image'] ?? null) {
        backgroundExec('php cacher.php ' . escapeshellarg($entry['image']));
    }
    try {
        if (inPlatformArray($entry['platform'], PLATFORMS_FAKE404)) {
            $main = searchExactHistory($entry['platform'], implode('/', array_slice(explode('/', $entry['relativeurl']), -1)));
            if (sizeof($main)) {
                unset($main[0]['relativeurl']);
                $entry2 = $entry;
                unset($entry2['relativeurl']);
                if (dataJsonEncode($main[0]) === dataJsonEncode($entry2)) {
                    return;
                }
            }
            // TODO update cache of main page
        }
        $db = historyDb();
        $db->prepare('INSERT INTO history (platform, relativeurl, data, updated) VALUES (?, ?, ?, ?)
            ON CONFLICT (platform, relativeurl) DO UPDATE SET data = excluded.data, updated = excluded.updated')
            ->execute([$entry['platform'], $entry['relativeurl'], dataJsonEncode($entry), time()]);
        if (random_int(1, 100) === 1) { // cheap enough to do now and then, no need for a cron
            pruneHistory($db);
        }
    } catch (Throwable $e) {
        error_log('history: ' . $e->getMessage());
    }
}

function searchHistory(string $query): array {
    $results = $fake404 = [];
    try {
        $stmt = historyDb()->prepare("SELECT data FROM history WHERE updated >= ? AND data LIKE ? ESCAPE '\\' ORDER BY updated DESC");
        $stmt->execute([historyCutoff(), '%' . addcslashes($query, '%_\\') . '%']);
        while (sizeof($results) < SEARCH_LIMIT && ($row = $stmt->fetch())) {
            $entry = json_decode($row['data'], true);
            if (inPlatformArray($entry['platform'], PLATFORMS_FAKE404)) {
                // collapse entries that only differ by their URL
                $entry2 = $entry;
                unset($entry2['relativeurl']);
                $key = dataJsonEncode($entry2);
                if (isset($fake404[$key])) {
                    continue;
                }
                $fake404[$key] = true;
            }
            $results[] = $entry;
        }
    } catch (Throwable $e) {
        error_log('history: ' . $e->getMessage());
    }
    return $results;
}

function searchExactHistory(string $platform, string $relativeUrl): array {
    try {
        $stmt = historyDb()->prepare('SELECT data FROM history WHERE platform = ? AND relativeurl = ? AND updated >= ?');
        $stmt->execute([$platform, $relativeUrl, historyCutoff()]);
        return ($row = $stmt->fetch()) ? [json_decode($row['data'], true)] : [];
    } catch (Throwable $e) {
        error_log('history: ' . $e->getMessage());
        return [];
    }
}

function dataJsonEncode(mixed $data): string {
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
