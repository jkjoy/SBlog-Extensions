<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$temporary = sys_get_temp_dir() . '/sblog-extension-tools-' . bin2hex(random_bytes(6));
try {
    if (!mkdir($temporary, 0700, true)) {
        store_fail('Unable to create test directory.');
    }
    $crlf = $temporary . '/crlf.txt';
    $lf = $temporary . '/lf.txt';
    $binary = $temporary . '/binary.dat';
    file_put_contents($crlf, "first\r\nsecond\r\n");
    file_put_contents($lf, "first\nsecond\n");
    file_put_contents($binary, "\x00\r\n\xff");
    if (store_file_bytes($crlf) !== store_file_bytes($lf)) {
        store_fail('Text line-ending normalization is not deterministic.');
    }
    if (store_file_bytes($binary) !== "\x00\r\n\xff") {
        store_fail('Binary package content was modified.');
    }
    $firstZip = $temporary . '/first.zip';
    $secondZip = $temporary . '/second.zip';
    store_package('sample', ['file.txt' => $crlf], $firstZip);
    store_package('sample', ['file.txt' => $lf], $secondZip);
    if (!hash_equals(hash_file('sha256', $firstZip), hash_file('sha256', $secondZip))) {
        store_fail('Equivalent text produced different packages.');
    }
    echo "Packaging self-test passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Self-test failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    store_remove_tree($temporary);
}
