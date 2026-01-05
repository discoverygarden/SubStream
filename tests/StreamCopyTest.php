<?php

namespace iqb\stream;

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . '/SubStreamTest.php');

class StreamCopyTest extends TestCase
{
    public function testStreamCopy()
    {
        $offset = 0;
        $length = filesize(SubStreamTest::INPUT_FILE);
        $desiredLength = 1048576;
        
        $referenceString = $fileContents = substr(file_get_contents(SubStreamTest::INPUT_FILE), $offset, $length);
        
        while (strlen($referenceString) < $desiredLength) {
            $referenceString .= $fileContents;
        }
        $referenceString = substr($referenceString, 0, $desiredLength);
        
        $inputStream = fopen('php://memory', 'r+');
        $outputStream = fopen('php://memory', 'r+');
        
        fwrite($inputStream, $referenceString);
        fseek($inputStream, 0);
        $length = strlen($referenceString);
        
        $subStream = fopen(SUBSTREAM_SCHEME . '://' . $offset . ':' . $length . '/' . (int)$inputStream, 'r');
        $this->assertTrue(is_resource($subStream));

        $this->assertSame($referenceString, stream_get_contents($subStream));
        fseek($subStream, 0, SEEK_SET);
        
        stream_copy_to_stream($subStream, $outputStream);
        fseek($outputStream, 0, SEEK_SET);
        $this->assertSame($referenceString, stream_get_contents($outputStream));
    }
}
