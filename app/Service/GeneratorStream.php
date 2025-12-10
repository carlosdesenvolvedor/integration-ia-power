<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\StreamInterface;

class GeneratorStream implements StreamInterface
{
    private \Generator $generator;
    private string $buffer = '';

    public function __construct(\Generator $generator)
    {
        $this->generator = $generator;
    }

    public function __toString(): string
    {
        $text = '';
        while (!$this->eof()) {
            $text .= $this->read(4096);
        }
        return $text;
    }

    public function close(): void
    {
        // No-op
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0; // Not supported
    }

    public function eof(): bool
    {
        return !$this->generator->valid() && $this->buffer === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function rewind(): void
    {
        // Generators cannot be rewound once started usually
        // throw new \RuntimeException('Stream is not rewindable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('Stream is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        if ($this->buffer !== '') {
            $out = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, strlen($out));
            return $out;
        }

        if (!$this->generator->valid()) {
            return '';
        }

        // Get next chunk
        $current = $this->generator->current();
        $this->generator->next();
        
        // If chunk is larger than length, buffer logic
        if (strlen($current) > $length) {
             $out = substr($current, 0, $length);
             $this->buffer = substr($current, $length);
             return $out;
        }
        
        return $current;
    }

    public function getContents(): string
    {
        return $this->__toString();
    }

    public function getMetadata($key = null)
    {
        return null;
    }
}
