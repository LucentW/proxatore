<?php
/*
 * Proxatore, a proxy for viewing and embedding content from various platforms.
 * Copyright (C) 2025 OctoSpacc
 *
*/

// usage (background, CLI only): php cacher.php <image-url>

if (php_sapi_name() !== 'cli') return;

require 'config.php';
require 'utils.php';

$image = $argv[1] ?? '';
if (isAbsoluteUrl($image)) {
    [$folder, $file] = mediaUrlToPath($image);
    $target = "{$folder}/{$file}";
    // fetchContent refuses non-public hosts
    if (!file_exists($target) && is_string($body = fetchContent($image)['body'])) {
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        file_put_contents($target, $body);
    }
}
// TODO rename image to hash of content and add extra metadata to identify it in json file, to prevent duplication?

function mediaUrlToPath(string $url): array {
    $segments = explode('/', parseAbsoluteUrl($url));
    $folder = CACHE_FOLDER . '/' . urlencode($segments[0]);
    $file = implode('/', array_slice($segments, 1));
    $hash = hash_base64('sha1', $file);
    $ext = extFromUrl($file);
    return [$folder, /* urlencode($file) */ substr(urlencode($file), 0, 32) . "-{$hash}.{$ext}"];
}

function extFromUrl(string $url): string {
    return pathinfo(explode('?', $url)[0], PATHINFO_EXTENSION);
}
