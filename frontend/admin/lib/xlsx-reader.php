<?php
/**
 * Framework-free xlsx reader.
 *
 * Reads .xlsx files using only PHP's ZipArchive + SimpleXML — no
 * composer dependency, matches the project's conventions.
 *
 * Public API:
 *   readXlsxRows($path, $sheetIndex = 0): array
 *     Returns rows of an xlsx as associative arrays, keyed by the
 *     header row (row 1).
 *
 *   readXlsxHeaders($path, $sheetIndex = 0): array
 *     Returns just the headers (row 1) as a flat array of strings.
 *
 * Limitations (intentional — kept minimal):
 *   - Reads only one sheet (default: first). Callers needing other
 *     sheets can pass $sheetIndex.
 *   - Cell value types resolved: shared strings (t="s"), inline
 *     strings (t="inlineStr"), numbers (no t attribute), booleans
 *     (t="b"). Dates are NOT converted — they come back as the
 *     serial number or string the file stores. Callers parse as
 *     needed.
 *
 * Used by the admission-tickets flow to parse the Examinee List
 * upload (columns: ID, RegNumber).
 */

if (!function_exists('readXlsxRows')) {

    /**
     * @return array<int, array<string, mixed>>
     */
    function readXlsxRows(string $filePath, int $sheetIndex = 0): array {
        $rows = _xlsxRows($filePath, $sheetIndex);
        if (empty($rows)) {
            return [];
        }
        $headers = array_shift($rows);
        $out = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($headers as $i => $h) {
                $headerLabel = _normalizeHeader($h);
                $assoc[$headerLabel] = $row[$i] ?? '';
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /**
     * @return array<int, string>
     */
    function readXlsxHeaders(string $filePath, int $sheetIndex = 0): array {
        $rows = _xlsxRows($filePath, $sheetIndex);
        if (empty($rows)) {
            return [];
        }
        $headers = array_shift($rows);
        return array_map('_normalizeHeader', $headers);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Read all rows of a sheet (without header normalization).
     * Each row is a 0-indexed array of cell values.
     *
     * @return array<int, array<int, mixed>>
     */
    function _xlsxRows(string $filePath, int $sheetIndex = 0): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension required');
        }
        if (!is_readable($filePath)) {
            throw new RuntimeException('xlsx file not readable: ' . $filePath);
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Failed to open xlsx as zip: ' . $filePath);
        }

        try {
            $shared = _readSharedStrings($zip);
            $sheetFile = _resolveSheetFile($zip, $sheetIndex);
            if ($sheetFile === null) {
                return [];
            }
            $sheetXml = $zip->getFromName($sheetFile);
            if ($sheetXml === false) {
                return [];
            }
            return _parseSheetRows($sheetXml, $shared);
        } finally {
            $zip->close();
        }
    }

    function _readSharedStrings(ZipArchive $zip): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return [];
        }
        $out = [];
        foreach ($sx->si as $si) {
            // Two forms: <si><t>value</t></si> or <si><r><t>p1</t></r><r><t>p2</t></r></si>
            if (isset($si->t)) {
                $out[] = (string) $si->t;
            } else {
                $parts = [];
                foreach ($si->r as $run) {
                    if (isset($run->t)) {
                        $parts[] = (string) $run->t;
                    }
                }
                $out[] = implode('', $parts);
            }
        }
        return $out;
    }

    /**
     * Resolve worksheet XML file path for the Nth sheet.
     * Mirrors the workbook.xml + workbook.xml.rels dance used in
     * registration-sheet-export.php.
     */
    function _resolveSheetFile(ZipArchive $zip, int $sheetIndex): ?string {
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $bookXml = $zip->getFromName('xl/workbook.xml');
        if ($relsXml === false || $bookXml === false) {
            return null;
        }

        // rId -> target file
        $ridToFile = [];
        if (preg_match_all('#<Relationship\s+Id="([^"]+)"[^>]*Target="([^"]+)"#', $relsXml, $m)) {
            foreach ($m[1] as $i => $rid) {
                $target = $m[2][$i];
                if (strpos($target, '/') !== 0) {
                    $target = 'xl/' . $target;
                }
                $ridToFile[$rid] = $target;
            }
        }

        // sheet name + rId, in document order
        if (!preg_match_all('#<sheet\s+[^>]*r:id="([^"]+)"#', $bookXml, $m)) {
            return null;
        }
        $rids = $m[1];
        if (!isset($rids[$sheetIndex])) {
            return null;
        }
        return $ridToFile[$rids[$sheetIndex]] ?? null;
    }

    /**
     * Parse a worksheet XML into 0-indexed rows of cell values.
     *
     * @return array<int, array<int, mixed>>
     */
    function _parseSheetRows(string $sheetXml, array $shared): array {
        $sx = @simplexml_load_string($sheetXml);
        if ($sx === false || !isset($sx->sheetData->row)) {
            return [];
        }

        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            // r attribute is the row number; we want them in order.
            $cells = [];
            // Track max column index to pad short rows.
            $maxCol = 0;
            foreach ($row->c as $c) {
                $ref = (string) $c['r']; // e.g. "A1", "B1"
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $mm)) {
                    continue;
                }
                $colIdx = _columnLettersToIndex($mm[1]);
                $type   = (string) $c['t'];

                $value = '';
                if ($type === 's' && isset($c->v)) {
                    // shared string index
                    $idx = (int) (string) $c->v;
                    $value = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                    $value = (string) $c->is->t;
                } elseif (isset($c->v)) {
                    $value = (string) $c->v;
                    // Numeric strings starting with '0' (e.g. "003", "47610001")
                    // are commonly integer column values that the file stored
                    // as a number. Leave them as-is — caller decides whether
                    // to re-pad. For our use case (reg_no / xlsx_id) callers
                    // should str_pad as needed.
                } elseif ($type === 'b' && isset($c->v)) {
                    $value = ((int) (string) $c->v) ? 'TRUE' : 'FALSE';
                }

                $cells[$colIdx] = $value;
                if ($colIdx > $maxCol) {
                    $maxCol = $colIdx;
                }
            }
            // Pad to max column so headers line up.
            $rowArr = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $rowArr[$i] = $cells[$i] ?? '';
            }
            $rows[] = $rowArr;
        }
        return $rows;
    }

    function _columnLettersToIndex(string $letters): int {
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $n - 1; // 0-indexed
    }

    function _normalizeHeader($h): string {
        return trim((string) $h);
    }
}
