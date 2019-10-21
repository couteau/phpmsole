<?php
namespace Cryptodira\PhpMsOle;

/**
 * File-like structure for rading from an individual stream within an OLE compound document
 *
 * @author Stuart C. Naifeh <stuart@cryptodira.org>
 *
 */
class OLEStreamReader
{
    /** @var OLEDocument $ole*/
    private $ole;
    private $streamid;
    private $startingsector;
    private $fat;
    private $blocksize;
    private $readsector;
    private $size;
    private $fatpointer;
    private $buffer;
    private $pos;

    /**
     * Create a new OLE stream reader for a given stream within an OLE compound document
     *
     * @param OLEDocument $ole
     * @param mixed $stream
     * @throws \Exception
     */
    public function __construct($ole, $stream = null)
    {
        $this->ole = $ole;
        if (is_null($stream)) {
            $this->streamid = $ole->GetDocumeantStream();
        }
        elseif (is_string($stream)) {
            if (!$this->streamid = $this->ole->FindStreamByName($stream)) {
                throw new \Exception("Stream {$stream} not found");
            }
        }
        elseif (is_int($stream)) {
            $this->streamid = $stream;
        }
        else {
            throw new \Exception("Invalid stream {$stream}");
        }


        // Use a closure/binding to access the internals of the OLE document -- simulating a C++ friend relationship
        $initialize = function (OLEDocument $ole, $streamid,
                &$startingsector, &$size, &$readsector, &$fat, &$blocksize) {
            if ($ole->RootDir[$streamid]['ObjectType'] != 2) {
                throw new \Exception("Id {$streamid} is not a stream");
            }

            $startingsector = $ole->RootDir[$streamid]['StartingSector'];
            $size = $ole->RootDir[$streamid]['StreamSize'];

            if ($size >= $ole->header['MiniStreamCutoff']) {
                $readsector = \Closure::fromCallable([$ole, 'getSectorData']);
                $fat = $ole->FAT;
                $blocksize = $ole->blocksize;
            }
            else {
                $readsector = \Closure::fromCallable([$ole, 'getMiniSectorData']);
                $fat = $ole->MiniFAT;
                $blocksize = 64;
            }
        };

        $initialize = $initialize->bindTo($this, $ole);
        $initialize($ole, $this->streamid, $this->startingsector, $this->size, $this->readsector, $this->fat, $this->blocksize);

        $this->fatpointer = $this->startingsector;
        $this->pos = 0;
        $this->buffer = null;
    }

    /**
     * Move the file pointer to a specific position within the stream
     *
     * @param int $pos
     */
    private function doSeek($pos)
    {
        $oldsector = intdiv($this->pos, $this->blocksize);
        $newsector = intdiv($pos, $this->blocksize);

        if ($newsector != $oldsector) {
            $this->fatpointer = $this->startingsector;
            for ($i=0; $i<$newsector && $this->fatpointer != OLEDocument::ENDOFCHAIN; $i++)
                $this->fatpointer = $this->fat[$this->fatpointer];

            $this->buffer = null;
        }

        $this->pos = $pos;
    }

    /**
     * Check whether file pointer is at the end of the stream data
     *
     * @return boolean
     */
    public function eof()
    {
        return ($this->pos == $this->size);
    }

    /**
     * Reset the file pointer to the beginning of the stream
     */
    public function Rewind()
    {
        $this->buffer = null;
        $this->fatpointer = $this->startingsector;
        $this->pos = 0;
    }

    /**
     * Move the file pointer to the passed position
     *
     * @param int $pos
     * @param int $seektype
     * @return 0 on success or -1 if the seek would move the file pointer beyond the end of the stream
     */
    public function seek($pos, $seektype = SEEK_SET)
    {
        switch ($seektype) {
            case SEEK_SET:
                break;
            case SEEK_CUR:
                $pos = $this->pos + $pos;
                break;
            case SEEK_END:
                $pos = $this->size + $pos;
                break;
        }

        if ($pos > $this->size) {
            $this->pos = $this->size;
            $this->buffer = null;
            return -1;
        }

        if ($pos != $this->pos)
            $this->doSeek($pos);

        return 0;
    }

    /**
     * Return the current file pointer position within the stream
     * @return int
     */
    public function tell()
    {
        return $this->pos;
    }

    /**
     * Read a maximum of $bytes bytes from the stream at the current position
     *
     * @param int $bytes
     * @return string
     */
    public function read($bytes)
    {
        if ($this->pos == $this->size) {
            return '';
        }

        if (is_null($this->buffer)) {
            $this->buffer = ($this->readsector)($this->fatpointer);
        }

        $sector_offset = $this->pos % $this->blocksize;
        if ($bytes <= $this->blocksize - $sector_offset) {
            $data = substr($this->buffer, $sector_offset, $bytes);
            $this->pos += $bytes;
            if ($sector_offset + $bytes == $this->blocksize) {
                $this->fatpointer = $this->fat[$this->fatpointer];
                $this->buffer = null;
            }
        }
        else {
            $data = substr($this->buffer, $sector_offset);
            $this->buffer = null;
            $bytesread = $this->blocksize - $sector_offset;
            $this->fatpointer = $this->fat[$this->fatpointer];
            while ($this->fatpointer != OLEDocument::ENDOFCHAIN && $bytesread < $bytes) {
                if (($bytes - $bytesread) < $this->blocksize) {
                    $this->buffer = ($this->readsector)($this->fatpointer);
                    $data .= substr($this->buffer, 0, $bytes-$bytesread);
                    $bytesread = $bytes;
                    break;
                }
                else {
                    $data .= ($this->readsector)($this->fatpointer);
                    $bytesread += $this->blocksize;
                }

                $this->fatpointer = $this->fat[$this->fatpointer];
            }
            $this->pos += $bytesread;
        }

        return $data;
    }

    public function readUint1(): int
    {
        $s = $this->read(1);
        if (!$s) {
            throw new \Exception('unable to read integer value');
        }

        return ord($s[0]);
    }

    public function readUint2(): int
    {
        $s = $this->read(2);
        if (strlen($s) != 2) {
            throw new \Exception('unable to read integer value');
        }

        return ord($s[0]) | (ord($s[1]) << 8);
    }

    public function readUint4(): int
    {
        $s = $this->read(4);
        if (strlen($s) != 4) {
            throw new \Exception('unable to read integer value');
        }

        return ord($s[0]) | (ord($s[1]) << 8) | (ord($s[2]) << 16) | (ord($s[3]) << 24);
    }
}


