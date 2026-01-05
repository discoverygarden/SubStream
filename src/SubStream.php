<?php

namespace iqb\stream;

/**
 * A SubStream wraps another stream and provides access to a portion of that stream.
 * A SubStream is read only.
 * The wrapped stream/resource must be seekable for SubStream to work.
 *
 * A substream can be created as follows:
 * 1. Use resource ID in the URL
 * <code>
 *     $streamToWrap = fopen(...);
 *     fopen("iqb.substream://$startindex:$length/$streamToWrap", "r");
 * </code>
 *
 * 2. Pass the resource as part of the stream context:
 * <code>
 * *     $streamToWrap = fopen(...);
 * *     fopen("iqb.substream://$startindex:$length/$streamToWrap", "r", false, stream_context_create([iqb.substream => ["stream" => $streamToWrap]]));
 * * </code>
 */
final class SubStream
{
    /**
     * @var resource
     */
    public $context;

    /**
     * @var resource
     */
    private $handle;

    /**
     * @var int
     */
    private $enforceOffsetMin;

    /**
     * @var int
     */
    private $enforceOffsetMax;

    /**
     * Current offset
     * @var int
     */
    private $offset = 0;


    public function stream_close()
    {
    }


    public function stream_eof()
    {
        return ($this->offset >= $this->enforceOffsetMax);
    }

    /**
     * Parse our path structure.
     *
     * @param string $path
     *   The path to parse.
     *
     * @return array|bool
     *   On success, an array containing:
     *   - the scheme (expecting SUBSTREAM_SCHEME)
     *   - the offset
     *   - the length from the offset
     *   - the resource ID
     *   On failure, boolean FALSE.
     */
    protected function parsePath($path) {
        $matches = [];
        $sep = '/';
        $pattern = implode('', [
          "{$sep}^",
          '([A-Za-z0-9.-]+)',
          preg_quote('://', $sep),
          '(\d+)',
          preg_quote(':', $sep),
          '(\d+)',
          preg_quote('/', $sep),
          "(\d+)\${$sep}",
        ]);
        return preg_match($pattern, $path, $matches) ?
          array_slice($matches, 1) :
          false;
    }

    public function stream_open(string $path, string $mode, int $options): bool
    {
        $errors = ($options & \STREAM_REPORT_ERRORS);
        
        if (!preg_match('/^' . preg_quote(SUBSTREAM_SCHEME, '/') . ':\/\/(?<offset>[0-9]+):(?<length>[0-9]+)(?:\/(?:(?<resourceId>[0-9]+)?|(?<url>.+))?)?$/', $path, $matches)) {
            $errors && trigger_error("Failed to parse URL.", E_USER_ERROR);
            return false;
        }
        
        if (($offset = intval($matches['offset'])) < 0) {
            $errors && trigger_error("Invalid negative offset.", E_USER_ERROR);
            return false;
        }
        
        if (($length = intval($matches['length'])) < 0) {
            $errors && trigger_error("Invalid negative length.", E_USER_ERROR);
            return false;
        }
        
        if ($resourceId = ($matches['resourceId'] ?? null)) {
            $resources = get_resources('stream');
            if (!isset($resources[$resourceId])) {
                $errors && trigger_error("Invalid resource #$resourceId.", E_USER_ERROR);
                return false;
            }
            
            return $this->cloneStream($resources[$resourceId], $offset, $length, $errors);
        }
        
        elseif ($url = $matches['url'] ?? null) {
            $this->handle = fopen($url, $mode);
            if (!$this->handle) {
                $errors && trigger_error("Failed to open '$url'.", E_USER_ERROR);
                return false;
            }
            fseek($this->handle, $offset);
            $this->offset = 0;
            $this->enforceOffsetMin = $offset;
            $this->enforceOffsetMax = $offset + $length;
            return true;
        }
        
        elseif ($this->context && ($contextOptions = stream_context_get_options($this->context))) {
            if (!isset($contextOptions[SUBSTREAM_SCHEME]['stream']) || !is_resource($contextOptions[SUBSTREAM_SCHEME]['stream'])) {
                $errors && trigger_error("No valid stream found in stream context.", E_USER_ERROR);
                return false;
            }
            
            return $this->cloneStream($contextOptions[SUBSTREAM_SCHEME]['stream'], $offset, $length, $errors);
        }
        
        else {
            $errors && trigger_error("No stream resource was provided.", E_USER_ERROR);
            return false;
        }
    }


    private function cloneStream($originalResource, int $offset, int $length, bool $errors): bool
    {
        $meta = stream_get_meta_data($originalResource);
        if (!isset($meta['seekable']) || !$meta['seekable']) {
            $errors && trigger_error("Can only wrap seekable resources.", E_USER_ERROR);
            return false;
        }

        // Copy memory stream as "reopening" is not possible and reset old stream position
        if ($meta['wrapper_type'] === 'PHP' && $meta['stream_type'] === 'MEMORY') {
            $this->handle = fopen($meta['uri'], $meta['mode']);
            $oldStreamPosition = ftell($originalResource);
            stream_copy_to_stream($originalResource, $this->handle, $length, $offset);
            fseek($this->handle, 0);
            $this->enforceOffsetMin = $this->offset = 0;
            $this->enforceOffsetMax = $length;
            fseek($originalResource, $oldStreamPosition);
        }

        // Reopen stream
        else {
            $this->enforceOffsetMin = $this->offset = $offset;
            $this->enforceOffsetMax = $offset + $length;
            $this->handle = fopen($meta['uri'], 'r');
            fseek($this->handle, $this->offset);
        }
        
        return true;
    }


    public function stream_read(int $count)
    {
        $realCount = \min($count, $this->enforceOffsetMax - $this->offset);

        if ($realCount > 0) {
            \fseek($this->handle, $this->offset);
            if (($data = \fread($this->handle, $realCount)) !== false) {
                $this->offset += \strlen($data);
            }

            return $data;
        } else {
            return '';
        }
    }


    public function stream_seek(int $offset, int $whence = \SEEK_SET)
    {
        if ($whence === \SEEK_SET) {
            $newOffset = $this->enforceOffsetMin + $offset;
        } elseif ($whence === \SEEK_CUR) {
            $newOffset = $this->offset + $offset;
        } elseif ($whence === \SEEK_END) {
            $newOffset = $this->enforceOffsetMax + $offset;
        } else {
            return false;
        }

        if (($newOffset < $this->enforceOffsetMin) || ($this->enforceOffsetMax <= $newOffset)) {
            return false;
        }

        $this->offset = $newOffset;
        return true;
    }


    public function stream_tell()
    {
        if ($this->offset < $this->enforceOffsetMin || $this->enforceOffsetMax <= $this->offset) {
            return;
        }

        return $this->offset - $this->enforceOffsetMin;
    }


    public function stream_stat()
    {
        return [
            7 => ($this->enforceOffsetMax - $this->enforceOffsetMin),
            'size' => ($this->enforceOffsetMax - $this->enforceOffsetMin),
        ];
    }
}
