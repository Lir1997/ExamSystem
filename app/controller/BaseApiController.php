<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\Response;

abstract class BaseApiController extends BaseController
{
    protected function paginationParams(int $defaultPageSize = 20, int $maxPageSize = 100): array
    {
        $page = (int) $this->request->get('page', 1);
        $pageSize = (int) $this->request->get('page_size', $defaultPageSize);

        if ($page <= 0) {
            $page = 1;
        }

        if ($pageSize <= 0) {
            $pageSize = $defaultPageSize;
        }

        if ($pageSize > $maxPageSize) {
            $pageSize = $maxPageSize;
        }

        return [$page, $pageSize];
    }

    protected function paginationData(array $items, int $total, int $page, int $pageSize): array
    {
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
            ],
        ];
    }

    protected function payload(): array
    {
        $payload = $this->request->post();

        if ($payload === []) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        return $payload;
    }

    protected function success(array $data = [], string $message = 'ok', int $code = 0): Response
    {
        return api_success($data, $message, $code);
    }

    protected function error(string $message = 'error', int $code = 1, array $data = []): Response
    {
        return api_error($message, $code, $data);
    }

    protected function previewReferenceList(array $values, int $limit = 5): string
    {
        $normalized = [];

        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text === '' || in_array($text, $normalized, true)) {
                continue;
            }

            $normalized[] = $text;
        }

        if ($normalized === []) {
            return '';
        }

        $preview = array_slice($normalized, 0, $limit);
        $suffix = count($normalized) > $limit ? ' 等 ' . count($normalized) . ' 项' : '';

        return implode('、', $preview) . $suffix;
    }

    protected function referenceDetailSuffix(string $label, array $values, int $limit = 5): string
    {
        $preview = $this->previewReferenceList($values, $limit);
        if ($preview === '') {
            return '';
        }

        return '（' . $label . '：' . $preview . '）';
    }

    protected function downloadBinaryTemplate(string $content, string $filename, string $contentType): Response
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'tpl-download-');
        if ($tempFile === false) {
            throw new \RuntimeException('无法创建模板下载文件');
        }

        file_put_contents($tempFile, $content);

        return download($tempFile, $filename)->header([
            'Content-Type' => $contentType,
        ]);
    }

    protected function buildSimpleXlsx(array $rows): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'simple-xlsx-');
        if ($zipPath === false) {
            throw new \RuntimeException('无法创建临时模板文件');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建模板压缩包');
        }

        $sharedStrings = [];
        $sharedStringIndex = [];
        $sheetRowsXml = '';

        foreach ($rows as $rowIndex => $row) {
            $sheetRowsXml .= '<row r="' . ($rowIndex + 1) . '">';
            foreach (array_values($row) as $columnIndex => $value) {
                $text = (string) $value;
                if (!array_key_exists($text, $sharedStringIndex)) {
                    $sharedStringIndex[$text] = count($sharedStrings);
                    $sharedStrings[] = $text;
                }

                $cellRef = $this->excelColumnName($columnIndex) . ($rowIndex + 1);
                $sheetRowsXml .= '<c r="' . $cellRef . '" t="s"><v>' . $sharedStringIndex[$text] . '</v></c>';
            }
            $sheetRowsXml .= '</row>';
        }

        $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
        foreach ($sharedStrings as $text) {
            $sharedStringsXml .= '<si><t>' . htmlspecialchars($text, ENT_XML1) . '</t></si>';
        }
        $sharedStringsXml .= '</sst>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . $sheetRowsXml
            . '</sheetData></worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $rootRelsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        $zip->close();

        $content = file_get_contents($zipPath);
        @unlink($zipPath);

        if (!is_string($content)) {
            throw new \RuntimeException('无法读取生成的模板文件');
        }

        return $content;
    }

    protected function excelColumnName(int $index): string
    {
        $index = max($index, 0);
        $name = '';

        do {
            $name = chr(($index % 26) + 65) . $name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }
}
