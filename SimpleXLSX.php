<?php

class SimpleXLSX
{
    public $rows = [];
    public $sheets = [];
    public $template = [];

    // Zipped XML file path
    protected $package;
    protected $sharedstrings = [];
    protected $workbook;
    protected $workbook_rels;
    protected $styles;
    public $debug;

    public function __construct($filename, $is_data = false, $debug = false)
    {
        $this->debug = $debug;
        if ($this->debug) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
            echo "Debug: Opening file $filename <br>";
        }

        $this->package = new ZipArchive;

        if ($this->package->open($filename) !== true) {
            if ($this->debug)
                echo "Debug: Failed to open Zip/XLSX <br>";
            return false;
        }

        $this->parsePackage();
    }

    public static function parse($filename, $is_data = false, $debug = false)
    {
        $xlsx = new self($filename, $is_data, $debug);
        if ($xlsx->success())
            return $xlsx;
        $xlsx->package->close();
        return false;
    }

    public function success()
    {
        return is_object($this->package);
    }

    public function rows($worksheetIndex = 0)
    {
        if (($ws = $this->worksheet($worksheetIndex)) === false)
            return false;

        $rows = [];
        $curR = 0;

        // Parse rows
        if (isset($ws->sheetData->row)) {
            foreach ($ws->sheetData->row as $row) {
                $checkRow = (int) $row['r'];
                if ($checkRow > $curR + 1) {
                    for ($i = $curR + 1; $i < $checkRow; $i++) {
                        $rows[$i] = [];
                    }
                }
                $curR = $checkRow;

                $r = [];
                // Process cells
                if (isset($row->c)) {
                    foreach ($row->c as $c) {
                        // Determine column index (A=0, B=1...)
                        $cellIndex = $this->cellIndex((string) $c['r']);

                        $val = (string) $c->v;

                        // Check type
                        $t = (string) $c['t'];
                        if ($t == 's') {
                            $val = isset($this->sharedstrings[$val]) ? $this->sharedstrings[$val] : '';
                        }

                        // Fill empty cells if skipped
                        while (count($r) < $cellIndex) {
                            $r[] = '';
                        }

                        $r[] = $val;
                    }
                }
                $rows[$curR - 1] = $r;
            }
        }

        // Sort by key to ensure order (xml might be ordered but gaps exist)
        ksort($rows);
        return array_values($rows);
    }

    protected function worksheet($worksheetIndex = 0)
    {
        // Try precise name first
        $num = $worksheetIndex + 1;
        $names = [
            'xl/worksheets/sheet' . $num . '.xml',
            'worksheets/sheet' . $num . '.xml',
            'xl/worksheets/sheet' . $num . '.XML',
            'xl/worksheets/sheet1.xml' // Fallback to sheet 1 always?
        ];

        $content = false;
        foreach ($names as $n) {
            if ($this->debug)
                echo "Debug: Trying to finding sheet at $n ... ";
            $content = $this->package->getFromName($n);
            if ($content) {
                if ($this->debug)
                    echo "FOUND!<br>";
                break;
            }
            if ($this->debug)
                echo "Not found.<br>";
        }

        // If still not found, search by index in zip
        if (!$content) {
            if ($this->debug)
                echo "Debug: Searching all files in zip for sheet...<br>";
            for ($i = 0; $i < $this->package->numFiles; $i++) {
                $stat = $this->package->statIndex($i);
                if ($this->debug)
                    echo "File: " . $stat['name'] . "<br>";
                if (strpos($stat['name'], 'sheet' . $num . '.xml') !== false) {
                    $content = $this->package->getFromIndex($i);
                    break;
                }
            }
        }

        if ($content) {
            // FIX: Remove XML Namespaces that prevent SimpleXML from finding children directly
            $content = preg_replace('/xmlns="[^"]+"/', '', $content);
            $content = preg_replace('/xmlns:[a-zA-Z0-9]+="[^"]+"/', '', $content);

            // Fix: Trim potential garbage headers
            $pos = strpos($content, '<worksheet');
            if ($pos !== false) {
                $content = substr($content, $pos);
            }

            // Fix: Suppress warnings
            $old_libxml = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            libxml_use_internal_errors($old_libxml);

            return $xml;
        }

        if ($this->debug)
            echo "Debug: No worksheet found for index $worksheetIndex.<br>";
        return false;
    }

    protected function parsePackage()
    {
        // Shared Strings
        if ($content = $this->package->getFromName('xl/sharedStrings.xml')) {
            // FIX: Remove XML Namespaces
            $content = preg_replace('/xmlns="[^"]+"/', '', $content);
            $content = preg_replace('/xmlns:[a-zA-Z0-9]+="[^"]+"/', '', $content);

            $xml = simplexml_load_string($content);
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $this->sharedstrings[] = (string) $si->t;
                } elseif (isset($si->r)) {
                    // Rich text
                    $str = '';
                    foreach ($si->r as $r) {
                        $str .= (string) $r->t;
                    }
                    $this->sharedstrings[] = $str;
                }
            }
        }
    }

    protected function cellIndex($cell)
    {
        // "A1" -> 0, "B5" -> 1
        $cell = preg_replace('/[0-9]+/', '', $cell);

        $len = strlen($cell);
        $index = 0;

        for ($i = 0; $i < $len; $i++) {
            $index += (ord($cell[$i]) - 64) * pow(26, $len - $i - 1);
        }
        return $index - 1;
    }
}
