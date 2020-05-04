<?php

namespace iqb\stream;

/**
 * A SubStream wraps another stream and provides access to a portion of that stream.
 * A SubStream is read only.
 * The wrapped stream/resource must be seekable for SubStream to work.
 *
 * The URL schema is: fopen("iqb.substream://$startindex:$length/$resourceId")
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

    public function stream_open(string $path, string $mode, int $options)
    {
        $errors = ($options & \STREAM_REPORT_ERRORS);

        $parsed = $this->parsePath($path);
        if (!$parsed) {
            $errors && \trigger_error('Failed to parse the URL.', \E_USER_ERROR);
            return false;
        }
        list($scheme, $offset, $length, $resourceId) = $parsed;

        if ($scheme !== SUBSTREAM_SCHEME) {
            $errors && \trigger_error("Invalid URL scheme.", \E_USER_ERROR);
            return false;
        }

        if (\function_exists('\get_resources')) {
            $resources = \get_resources('stream');
            if (isset($resources[$resourceId])) {
                $originalResource = $resources[$resourceId];
                $meta = \stream_get_meta_data($originalResource);

                if (!isset($meta['seekable']) || !$meta['seekable']) {
                    $errors && \trigger_error("Can only wrap seekable resources.", \E_USER_ERROR);
                    return false;
                }

                if ($meta['wrapper_type'] === 'PHP' && $meta['stream_type'] === 'MEMORY') {
                    $oldStreamPosition = \ftell($originalResource);
                    $resource = \fopen($meta['uri'], 'w+b');
                    \fseek($originalResource, $this->enforceOffsetMin);
                    \stream_copy_to_stream($originalResource, $resource, $length, $offset);
                    $this->enforceOffsetMin = $this->offset = 0;
                    $this->enforceOffsetMax = $length;
                    \fseek($originalResource, $oldStreamPosition);
                }

                else {
                    $this->enforceOffsetMin = $this->offset = $offset;
                    $this->enforceOffsetMax = $offset + $length;
                    $resource = \fopen($meta['uri'], 'r');
                }
            }
        }

        if (!isset($resource)) {
            $errors && \trigger_error("Resource not available.", \E_USER_ERROR);
            return false;
        }

        $this->handle = $resource;
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
            return false;
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
