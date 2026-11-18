<?php
error_reporting(E_ALL);
if (!class_exists('TrainerPlan\FIT\FITFileAnalysis')) {
    require __DIR__ . '/../src/FITFileAnalysis.php';
}

/**
 * Files that are not one clean FIT file. Fixtures are built from the demo files
 * at run time, so no real activity is committed.
 */
class CorruptTest extends PHPUnit_Framework_TestCase
{
    private $base_dir;
    private $tmp_files = [];
    private $log_file;
    private $old_error_log;

    public function setUp()
    {
        $this->base_dir = __DIR__ . '/../demo/fit_files/';
        // The parser reports what it skipped with error_log(); capture it.
        $this->log_file = tempnam(sys_get_temp_dir(), 'pffa-log-');
        $this->old_error_log = ini_set('error_log', $this->log_file);
        // Any PHP warning/notice from the parser fails the test.
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public function tearDown()
    {
        restore_error_handler();
        ini_set('error_log', $this->old_error_log);
        foreach (array_merge($this->tmp_files, [$this->log_file]) as $file) {
            is_file($file) && unlink($file);
        }
    }

    /**
     * A download retried into the same un-rewound file stores the interrupted
     * attempts in front of the complete one: [part][part][whole file]. The parts
     * end mid-record and the next part's header was read as a data message of
     * local type 14 ("Undefined array key 14").
     */
    public function testInterruptedRetriesBeforeTheCompleteFile()
    {
        foreach (['road-cycling.fit', 'mountain-biking.fit', 'power-analysis.fit'] as $filename) {
            $whole = file_get_contents($this->base_dir . $filename);
            $expected = new TrainerPlan\FIT\FITFileAnalysis($this->base_dir . $filename);

            $stored = substr($whole, 0, (int) (strlen($whole) * 0.27))
                . substr($whole, 0, (int) (strlen($whole) * 0.43))
                . $whole;
            $pFFA = new TrainerPlan\FIT\FITFileAnalysis($this->tmpFile($stored));

            $this->assertSame(md5(serialize($expected->data_mesgs)), md5(serialize($pFFA->data_mesgs)), "$filename: the complete copy should be read in full");
            $this->assertContains('reading the complete copy', file_get_contents($this->log_file));
        }
    }

    /**
     * Bytes in front of a file that are not an earlier copy of it are not
     * skipped: only a tail with the same header and a matching CRC is used.
     */
    public function testTailWithBadCrcIsNotUsed()
    {
        $whole = file_get_contents($this->base_dir . 'road-cycling.fit');
        $corrupt = $whole;
        $corrupt[strlen($corrupt) - 100] = chr(ord($corrupt[strlen($corrupt) - 100]) ^ 0xFF);
        $stored = substr($whole, 0, 5000) . $corrupt;

        $pFFA = new TrainerPlan\FIT\FITFileAnalysis($this->tmpFile($stored));

        $this->assertNotContains('reading the complete copy', file_get_contents($this->log_file));
        $this->assertGreaterThan(0, count($pFFA->data_mesgs['record']['timestamp']));
    }

    /**
     * A file cut short keeps every whole record before the cut, with no warnings
     * (and no endless loop at end of file).
     */
    public function testTruncatedFileKeepsTheRecordsBeforeTheCut()
    {
        $whole = file_get_contents($this->base_dir . 'road-cycling.fit');
        $full = new TrainerPlan\FIT\FITFileAnalysis($this->base_dir . 'road-cycling.fit');
        $all_timestamps = $full->data_mesgs['record']['timestamp'];

        $pFFA = new TrainerPlan\FIT\FITFileAnalysis($this->tmpFile(substr($whole, 0, (int) (strlen($whole) * 0.6))));
        $timestamps = $pFFA->data_mesgs['record']['timestamp'];

        $this->assertGreaterThan(0.5 * count($all_timestamps), count($timestamps));
        $this->assertLessThan(count($all_timestamps), count($timestamps));
        $this->assertSame(array_slice($all_timestamps, 0, count($timestamps)), $timestamps);
        $this->assertContains('file ends', file_get_contents($this->log_file));
    }

    /**
     * A data message whose local type was never defined: stop there, keep the
     * records before it, one log line, no warnings.
     */
    public function testUndefinedLocalMessageTypeStopsCleanly()
    {
        $timestamp = 1000000000;  // FIT epoch seconds
        $definition = pack('CCCvC', 0x40, 0, 0, 20, 2)  // definition, local 0, little-endian, record, 2 fields
            . pack('CCC', 253, 4, 0x86)                // timestamp, uint32
            . pack('CCC', 3, 1, 0x02);                 // heart_rate, uint8
        $records = '';
        for ($i = 0; $i < 3; ++$i) {
            $records .= pack('CVC', 0x00, $timestamp + $i, 120 + $i);
        }
        $after = pack('CVC', 0x00, $timestamp + 3, 130);  // valid, but after the bad byte
        $data = $definition . $records . chr(0x0E) . str_repeat("\xAA", 5) . $after;
        $header = pack('CCvVa4', 12, 0x10, 2132, strlen($data), '.FIT');

        $pFFA = new TrainerPlan\FIT\FITFileAnalysis($this->tmpFile($header . $data . "\x00\x00"));

        $this->assertSame(
            [$timestamp + 631065600, $timestamp + 631065601, $timestamp + 631065602],
            $pFFA->data_mesgs['record']['timestamp']
        );
        $this->assertContains('local message type 14, which has no definition', file_get_contents($this->log_file));
    }

    private function tmpFile($contents)
    {
        $file = tempnam(sys_get_temp_dir(), 'pffa-');
        file_put_contents($file, $contents);
        $this->tmp_files[] = $file;
        return $file;
    }
}
