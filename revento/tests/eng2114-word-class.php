<?php

// Build-time generator for the Unicode L/N class used by the prefix boundary.
// PostgreSQL's locale-dependent POSIX alnum class is not equivalent (e.g. ²).
// Run with the pinned image's PCRE tables; never enumerate Unicode per request.
$segments = [];
$start = null;
$last = null;
$escape = static fn (int $point): string => $point <= 0xffff ? sprintf('\\u%04X', $point) : sprintf('\\U%08X', $point);
$append = static function (int $first, int $final) use (&$segments, $escape): void {
    $segments[] = $first === $final ? $escape($first) : $escape($first) . '-' . $escape($final);
};
for ($point = 1; $point <= 0x10ffff; $point++) {
    $word = !($point >= 0xd800 && $point <= 0xdfff)
        && preg_match('/\A[\p{L}\p{N}]\z/u', mb_chr($point, 'UTF-8')) === 1;
    if ($word) {
        $start ??= $point;
        $last = $point;
    } elseif ($start !== null) {
        $append($start, $last);
        $start = null;
    }
}
if ($start !== null) {
    $append($start, $last);
}
$class = implode('', $segments);
if (($argv[1] ?? '') === '--check') {
    $source = file_get_contents($argv[2] ?? '');
    if (!str_contains($source, "private const FULLTEXT_WORD_CHARACTERS = '" . $class . "';")) {
        throw new RuntimeException('Pinned prefix character class differs from packaged PCRE Unicode tables');
    }
    echo "Verified prefix Unicode class\n";
} else {
    echo json_encode(['pcre' => PCRE_VERSION, 'ranges' => count($segments), 'class' => $class], JSON_THROW_ON_ERROR) . "\n";
}
