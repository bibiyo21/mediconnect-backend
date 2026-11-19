<?php

use App\Shared\Malware\ClamdScanner;
use App\Shared\Malware\ScannerUnavailable;

it('parses clean and infected clamd replies', function () {
    expect(ClamdScanner::parse("stream: OK\0")->infected)->toBeFalse()
        ->and(ClamdScanner::parse("stream: Eicar-Test-Signature FOUND\0")->signature)->toBe('Eicar-Test-Signature');
});

it('treats size-limit and error replies as no verdict (fail closed)', function (string $reply) {
    ClamdScanner::parse($reply);
})->with(["INSTREAM size limit exceeded. ERROR\0", "stream: Can't allocate memory ERROR\0", '', 'garbage'])->throws(ScannerUnavailable::class);

it('raises ScannerUnavailable when clamd cannot be reached', function () {
    (new ClamdScanner('127.0.0.1', 1, 0.5))->scan('hello');
})->throws(ScannerUnavailable::class);
