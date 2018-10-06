<?php
/**
 * MIT License.
 *
 * @author Andrey Khrolenok <andrey@khrolenok.ru>
 * @copyright Copyright (c) 2017 Andrey Khrolenok
 * @license MIT
 *
 * @see https://github.com/Limych/foxy-tools
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace FoxyTools;

/**
 * Helper to read and write CSV data files in MS Excel notation.
 */
class MsCsv
{
    /** @var string UTF-8 byte order mark */
    const UTF8_BOM = "\xEF\xBB\xBF";

    /** @var string Set the field delimiter (one character only). */
    public static $delimiter = ';';

    /** @var string Set the field enclosure character (one character only). */
    public static $enclosure = '"';

    /** @var string Set the escape character (one character only). */
    public static $escape = '\\';

    /**
     * Test a value for it is associative array.
     *
     * @param mixed $array
     *
     * @return boolean
     */
    protected static function isAssoc($array)
    {
        if (! \is_array($array) || [] === $array) {
            return false;
        }

        return array_keys($array) !== range(0, \count($array) - 1);
    }

    /**
     * Write UTF-8 BOM to file.
     *
     * @param resource $handle
     */
    public static function fPutBom($handle)
    {
        fwrite($handle, self::UTF8_BOM); // Write BOM
    }

    /**
     * Put CSV record with data headers to file using MS Excel notation.
     *
     * @param resource $handle
     * @param array    $headers
     * @param bool     $putBom
     */
    public static function fPutHeaders($handle, array $headers, $putBom = true)
    {
        if (false !== $putBom) {
            self::fPutBom($handle);
        }
        if (self::isAssoc($headers)) {
            $headers = array_keys($headers);
        }
        self::fPutCsv($handle, $headers);
    }

    /**
     * Write array as CSV record to file using MS Excel notation.
     *
     * @param resource $handle File handler
     * @param array    $fields Array to write to file
     *
     * @return bool|number number of written chars, false on error
     */
    public static function fPutCsv($handle, array $fields)
    {
        if (self::isAssoc($fields)) {
            $fields = array_values($fields);
        }
        for ($i = 0, $n = \count($fields); $i < $n; $i++) {
            if (! is_numeric($fields[$i])) {
                // Quote string and double all quotes inside string
                $fields[$i] = self::$enclosure.str_replace(self::$enclosure, self::$enclosure.self::$enclosure, $fields[$i]).self::$enclosure;
            }
            // If we have a dot inside a number, quote that number too
            if (('.' === self::$delimiter) && (is_numeric($fields[$i]))) {
                $fields[$i] = self::$enclosure.$fields[$i].self::$enclosure;
            }
        }

        $str = implode(self::$delimiter, $fields)."\n";
        fwrite($handle, $str);

        return \mb_strlen($str);
    }

    /**
     * Read one record from CSV file using MS Excel notation.
     *
     * @param resource $handle File handler
     *
     * @return array|null|false An indexed array containing the fields read.<br/><br/>
     *                          A blank line in a CSV file will be returned as an array comprising a single null field, and will not be treated as an error.
     */
    public static function fGetCsv($handle)
    {
        $seek = ftell($handle);
        $input = fgets($handle);
        if ((0 === $seek) && (0 === strncmp($input, self::UTF8_BOM, 3))) {
            // Strip BOM
            $input = mb_substr($input, 3);
        }
        $csv_arr = str_getcsv($input, self::$delimiter, self::$enclosure, self::$escape);

        return $csv_arr;
    }

    /**
     * Parse a CSV string to array using MS Excel notation.
     *
     * @param string $input the string to parse
     *
     * @return array An indexed array containing the fields read.<br/><br/>
     *               A blank line in a CSV file will be returned as an array comprising a single null field, and will not be treated as an error.
     */
    public static function strGetCsv($input)
    {
        $csv_arr = str_getcsv($input, self::$delimiter, self::$enclosure, self::$escape);

        return $csv_arr;
    }

    /**
     * Iterate user function trougth CSV records.
     *
     * @param resource $handle     File handler
     * @param callable $callback
     * @param mixed    $startId
     * @param bool     $getHeaders
     *
     * @return number|null|false returns null if an invalid handle is supplied or false on other errors
     */
    public static function fGetCsvIterated($handle, callable $callback, $startId = null, $getHeaders = false)
    {
        $headers = [];
        if (false !== $getHeaders) {
            $seek = ftell($handle);
            fseek($handle, 0, SEEK_SET);
            if (\is_array($res = self::fGetCsv($handle))) {
                $headers = $res;
            }
            fseek($handle, $seek, SEEK_SET);
        }
        $cnt = 0;
        while (\is_array($record = self::fGetCsv($handle))) {
            if ((null !== $startId) && ($startId > $record[0])) {
                continue;
            }
            if (! empty($headers)) {
                $record = array_combine($headers, $record);
            }
            $cnt++;
            if (false === \call_user_func($callback, $record)) {
                return $cnt;
            }
        }
        if (feof($handle)) {
            return $cnt;
        }

        return $record;
    }

    /**
     * Get last line of text file.
     *
     * @param resource $handle File handler
     *
     * @return string Last line of file
     */
    public static function fGetLastLine($handle)
    {
        $line = '';
        $cursor = -1;
        $seek = ftell($handle);

        /**
         * Trim trailing newline chars of the file.
         */
        $char = null;
        while (true) {
            fseek($handle, $cursor--, SEEK_END);
            $char = fgetc($handle);
            if ("\n" !== $char && "\r" !== $char) {
                break;
            }
            $line = $char.$line;
        }

        /*
         * Read until the start of file or first newline char
         */
        while (false !== $char && "\n" !== $char && "\r" !== $char) {
            $line = $char.$line;
            fseek($handle, $cursor--, SEEK_END);
            $char = fgetc($handle);
        }

        fseek($handle, $seek, SEEK_SET);

        return $line;
    }

    /**
     * Get last record of CSV file using MS Excel notation.
     *
     * @param resource $handle File handler
     *
     * @return array Last line of file
     */
    public static function fGetCsvLastLine($handle)
    {
        $line = '';
        $cursor = -1;
        $seek = ftell($handle);

        /**
         * Trim trailing newline chars of the file.
         */
        $char = null;
        while (true) {
            fseek($handle, $cursor--, SEEK_END);
            $char = fgetc($handle);
            if ("\n" !== $char && "\r" !== $char) {
                break;
            }
            $line = $char.$line;
        }

        /**
         * Read until the start of file or first newline char (but skip quoted new line chars).
         */
        $inQuotes = false;
        while (false !== $char && ($inQuotes || ("\n" !== $char && "\r" !== $char))) {
            if ($char === self::$enclosure) {
                $inQuotes = ! $inQuotes;
            }
            $line = $char.$line;
            fseek($handle, $cursor--, SEEK_END);
            $char = fgetc($handle);
        }

        fseek($handle, $seek, SEEK_SET);

        $record = self::strGetCsv($line);

        return $record;
    }
}
