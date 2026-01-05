<?php

namespace iqb\stream;

use iqb\ErrorMessage;
use Iterator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SubStreamTest extends TestCase
{
    const INPUT_FILE = __DIR__ . '/ipsum.txt';

    private $string;
    private $memoryStream;


    public function setUp(): void
    {
        $this->string = \file_get_contents(self::INPUT_FILE);
        $this->memoryStream = \fopen('php://memory', 'r+');
        if (\strlen($this->string) !== ($bytesWritten = \fwrite($this->memoryStream, $this->string))) {
            throw new \RuntimeException('Setup failed!: ' . $bytesWritten);
        }
    }
    
    
    private function generateTestCases(int $length): array
    {
        return [
            "for 0:$length"                               => [0, $length, 0, $length, 31, 32],
            "for 10:" . ($length-10)                      => [10, $length-10, 10, $length-10, 31, 32],
            "for " . ($length-53) . ':53'                 => [$length-53, 53, 0, 55, 31, 32],
            
            // Different seek variants
            "for " . ($length-350) . ':256 and SEEK_CUR'  => [$length-350, 256, 0, 256, 1, 32, SEEK_CUR],
            "for " . ($length-350) . ':256 and SEEK_END'  => [$length-350, 256, -256, 0, 1, 32, SEEK_END],
        ];
    }
    
    
    public function offsetProvider(): Iterator
    {
        $string = file_get_contents(self::INPUT_FILE);
        $length = strlen($string);
        $fileStream = fopen(self::INPUT_FILE, 'r');
        $memoryStream = fopen('php://memory', 'r+');

        if (strlen($string) !== ($bytesWritten = fwrite($memoryStream, $string))) {
            throw new RuntimeException('Setup failed!: ' . $bytesWritten);
        }
        fseek($memoryStream, 0);
        
        $testVariants = $this->generateTestCases($length);
        
        foreach (['Memory' => $memoryStream, 'File' => $fileStream] as $streamName => $stream) {
            foreach ($testVariants as $testVariantName => $testVariant) {
                yield "SubStream on $streamName $testVariantName" => array_merge([$stream, $string], $testVariant);
            }
        }
    }
    
    
    /**
     * @dataProvider offsetProvider
     */
    public function testSubStreamByResourceId($stream, string $referenceString, int $offset, int $length, int $iterationStart, int $iterationLimit, int $iterationStep, int $probeLength, int $seekMode = \SEEK_SET)
    {
        $oldStreamPosition = ftell($stream);
        $subStream = fopen(SUBSTREAM_SCHEME . '://' . $offset . ':' . $length . '/' . (int)$stream, 'r');
        $this->defaultSubstreamTest($subStream, $referenceString, $offset, $length, $iterationStart, $iterationLimit, $iterationStep, $probeLength, $seekMode);
        $this->assertSame($oldStreamPosition, ftell($stream));
    }
    
    
    /**
     * @dataProvider offsetProvider
     */
    public function testSubStreamByContext($stream, string $referenceString, int $offset, int $length, int $iterationStart, int $iterationLimit, int $iterationStep, int $probeLength, int $seekMode = \SEEK_SET)
    {
        $oldStreamPosition = ftell($stream);
        $subStream = fopen(SUBSTREAM_SCHEME . '://' . $offset . ':' . $length, 'r', false, stream_context_create([SUBSTREAM_SCHEME => ['stream' => $stream]]));
        $this->defaultSubstreamTest($subStream, $referenceString, $offset, $length, $iterationStart, $iterationLimit, $iterationStep, $probeLength, $seekMode);
        $this->assertSame($oldStreamPosition, ftell($stream));
    }
    
    
    public function casesWithUrlProvider(): Iterator
    {
        $string = file_get_contents(self::INPUT_FILE);
        $length = strlen($string);
        $fileStream = fopen(self::INPUT_FILE, 'r');
        $memoryStream = fopen('php://memory', 'r+');
        
        if (strlen($string) !== ($bytesWritten = fwrite($memoryStream, $string))) {
            throw new RuntimeException('Setup failed!: ' . $bytesWritten);
        }
        fseek($memoryStream, 0);
        
        $testVariants = $this->generateTestCases($length);
        foreach ($testVariants as $testVariantName => $testVariant) {
            yield "SubStream with url $testVariantName" => array_merge([self::INPUT_FILE, $string], $testVariant);
        }
    }
    
    
    /**
     * @dataProvider casesWithUrlProvider
     */
    public function testSubStreamByUrl(string $url, string $referenceString, int $offset, int $length, int $iterationStart, int $iterationLimit, int $iterationStep, int $probeLength, int $seekMode = \SEEK_SET)
    {
        $subStream = fopen(SUBSTREAM_SCHEME . "://$offset:$length/$url", 'r');
        $this->defaultSubstreamTest($subStream, $referenceString, $offset, $length, $iterationStart, $iterationLimit, $iterationStep, $probeLength, $seekMode);
    }
    
    
    private function defaultSubstreamTest($subStream, string $referenceString, int $offset, int $length, int $iterationStart, int $iterationLimit, int $iterationStep, int $probeLength, int $seekMode = \SEEK_SET)
    {
        $this->assertTrue(is_resource($subStream));

        $referenceName = tempnam(sys_get_temp_dir(), 'phpunit_substream_ref');
        $referenceStream = fopen($referenceName, 'r+');
        $this->assertTrue(is_resource($referenceStream));
        
        try {
            $this->assertSame($length, fwrite($referenceStream, substr($referenceString, $offset, $length)));
            fclose($referenceStream);
            $referenceStream = fopen($referenceName, 'r');
            
            for ($i=$iterationStart; $i<$iterationLimit; $i+=$iterationStep) {
                fseek($referenceStream, $i, $seekMode);
                fseek($subStream, $i, $seekMode);
                $this->assertEquals(fread($referenceStream, $probeLength), fread($subStream, $probeLength), "Iteration: $i");
            }
        } finally {
            unlink($referenceName);
        }
    }


    public function testReadWithoutSeek()
    {
        $offset = 500;
        $length = 1000;

        $subStream = \fopen(SUBSTREAM_SCHEME . '://' . $offset . ':' . $length . '/' . (int)$this->memoryStream, 'r');
        $this->assertTrue(\is_resource($subStream));

        $referenceStream = \fopen('php://memory', 'r+');
        $this->assertSame($length, \fwrite($referenceStream, \substr($this->string, $offset, $length)));
        \fseek($referenceStream, 0);

        $this->assertEquals(\fread($subStream, 2*$length), \fread($referenceStream, 2*$length));
    }
}
