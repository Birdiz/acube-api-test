<?php

declare(strict_types=1);

namespace App\Tests\Api\Fixture;

/**
 * Builds real sample files on disk.
 *
 * XLSX and ODS are ZIP containers, and the API is expected to tell them apart
 * from a plain archive by their internal layout — so these are built as proper
 * containers rather than as renamed zips. A fixture that cheats here would let
 * a broken type check pass.
 *
 * One factory method per SourceFormat case, named after its value, so
 * `SampleFile::{$format->value}()` builds a sample of any supported type.
 *
 * The MIME strings below are spelled out rather than read from SourceFormat:
 * these files are built to the published OASIS and OOXML specs, and generating
 * a fixture from the same constant the validator checks against would let a
 * wrong value agree with itself.
 */
final class SampleFile
{
    public const string CSV = 'text/csv';
    public const string JSON = 'application/json';
    public const string XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const string ODS = 'application/vnd.oasis.opendocument.spreadsheet';

    /** The rows every sample encodes, so the four source types carry the same data. */
    public const array ROWS = [
        ['id' => '1', 'name' => 'Ada Lovelace', 'role' => 'analyst'],
        ['id' => '2', 'name' => 'Grace Hopper', 'role' => 'admiral'],
        ['id' => '3', 'name' => 'Alan Turing', 'role' => 'logician'],
    ];

    public static function csv(): string
    {
        $handle = fopen('php://temp', 'r+');
        \assert(false !== $handle);

        fputcsv($handle, array_keys(self::ROWS[0]), escape: '');
        foreach (self::ROWS as $row) {
            fputcsv($handle, $row, escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return self::write('sample.csv', (string) $csv);
    }

    public static function json(): string
    {
        return self::write('sample.json', json_encode(self::ROWS, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    public static function xlsx(): string
    {
        $path = self::path('sample.xlsx');
        $zip = self::openArchive($path);

        // libmagic identifies an XLSX by finding [Content_Types].xml first.
        $zip->addFromString('[Content_Types].xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
              <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
              <Default Extension="xml" ContentType="application/xml"/>
              <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
              <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
            </Types>
            XML);
        $zip->setCompressionName('[Content_Types].xml', \ZipArchive::CM_STORE);

        $zip->addFromString('_rels/.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
            </Relationships>
            XML);

        $zip->addFromString('xl/workbook.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
                      xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
              <sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>
            </workbook>
            XML);

        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
            </Relationships>
            XML);

        $zip->addFromString('xl/worksheets/sheet1.xml', self::spreadsheetMlRows());
        $zip->close();

        return $path;
    }

    public static function ods(): string
    {
        $path = self::path('sample.ods');
        $zip = self::openArchive($path);

        // OpenDocument requires an uncompressed "mimetype" entry, stored first.
        $zip->addFromString('mimetype', self::ODS);
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/manifest.xml', \sprintf(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">
              <manifest:file-entry manifest:full-path="/" manifest:media-type="%s"/>
              <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
            </manifest:manifest>
            XML, self::ODS));

        $zip->addFromString('content.xml', self::openDocumentRows());
        $zip->close();

        return $path;
    }

    /** A type the API must refuse: not one of CSV, JSON, XLSX, ODS. */
    public static function pdf(): string
    {
        $body = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n";

        return self::write('invoice.pdf', $body);
    }

    /** A bare ZIP: shaped like XLSX/ODS from the outside, valid as neither. */
    public static function zip(): string
    {
        $path = self::path('archive.zip');
        $zip = self::openArchive($path);
        $zip->addFromString('readme.txt', "not a spreadsheet\n");
        $zip->close();

        return $path;
    }

    public static function empty(): string
    {
        return self::write('empty.csv', '');
    }

    /** A well-formed CSV of exactly $bytes, for probing the size limit. */
    public static function csvOfSize(int $bytes): string
    {
        $header = "id,filler\n";
        $body = '';

        for ($row = 1; \strlen($header) + \strlen($body) < $bytes; ++$row) {
            $body .= $row.','.str_repeat('x', 64)."\n";
        }

        return self::write(\sprintf('sized-%d.csv', $bytes), substr($header.$body, 0, $bytes));
    }

    public static function cleanUp(): void
    {
        foreach (glob(self::directory().'/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ----------------------------------------------------------------- internals

    private static function spreadsheetMlRows(): string
    {
        $rows = '<row r="1">'.implode('', array_map(
            static fn (string $header): string => \sprintf('<c t="inlineStr"><is><t>%s</t></is></c>', $header),
            array_keys(self::ROWS[0]),
        )).'</row>';

        foreach (self::ROWS as $index => $row) {
            $rows .= \sprintf('<row r="%d">', $index + 2).implode('', array_map(
                static fn (string $value): string => \sprintf('<c t="inlineStr"><is><t>%s</t></is></c>', $value),
                array_values($row),
            )).'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$rows.'</sheetData></worksheet>';
    }

    private static function openDocumentRows(): string
    {
        $cell = static fn (string $value): string => \sprintf(
            '<table:table-cell office:value-type="string"><text:p>%s</text:p></table:table-cell>',
            $value,
        );

        $rows = '<table:table-row>'.implode('', array_map($cell, array_keys(self::ROWS[0]))).'</table:table-row>';
        foreach (self::ROWS as $row) {
            $rows .= '<table:table-row>'.implode('', array_map($cell, array_values($row))).'</table:table-row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content office:version="1.2"'
            .' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
            .'<office:body><office:spreadsheet><table:table table:name="Sheet1">'
            .$rows
            .'</table:table></office:spreadsheet></office:body></office:document-content>';
    }

    private static function openArchive(string $path): \ZipArchive
    {
        @unlink($path);

        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        \assert(true === $opened, \sprintf('Could not create the archive at %s.', $path));

        return $zip;
    }

    private static function write(string $name, string $contents): string
    {
        $path = self::path($name);
        file_put_contents($path, $contents);

        return $path;
    }

    private static function path(string $name): string
    {
        return self::directory().'/'.$name;
    }

    private static function directory(): string
    {
        $directory = sys_get_temp_dir().'/acube-conversion-fixtures';

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory;
    }
}
