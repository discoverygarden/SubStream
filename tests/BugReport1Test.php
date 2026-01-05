<?php

namespace iqb\stream;

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/SubStreamTest.php');

/**
 * @url https://github.com/iqb/Morgue/issues/1
 */
class BugReport1Test extends TestCase
{
    public function testBugReport1()
    {
        $testString = 'test string';
        $testFile = tmpfile();
        $outputFile = tmpfile();
        
        $this->assertSame(strlen($testString), fwrite($testFile, $testString));
        rewind($testFile);
        
        $offset = 0;
        $length = 4;
        
        // Substream the first four bytes, which should extract the string `test`.
        $substreamURI = SUBSTREAM_SCHEME . "://$offset:$length/" . (int)$testFile;
        $substreamHandle = fopen($substreamURI, 'rb');
        
        $this->assertSame($length, stream_copy_to_stream($substreamHandle, $outputFile));
        
        rewind($outputFile);
        $this->assertSame(substr($testString, $offset, $length), fread($outputFile, 4096));
    }
}
