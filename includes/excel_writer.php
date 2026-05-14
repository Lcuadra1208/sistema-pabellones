<?php
/**
 * ExcelWriter — generador de XLSX nativo sin dependencias externas
 * Genera archivos Excel compatibles con Microsoft Excel y LibreOffice
 */
class ExcelWriter
{
    private array $sheets = [];
    private string $titulo;

    public function __construct(string $titulo = 'Reporte')
    {
        $this->titulo = $titulo;
    }

    public function agregarHoja(string $nombre, array $encabezados, array $filas, array $totales = []): void
    {
        $this->sheets[] = compact('nombre','encabezados','filas','totales');
    }

    public function descargar(string $nombre_archivo = 'reporte'): void
    {
        $xlsx = $this->generar();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$nombre_archivo.'.xlsx"');
        header('Content-Length: '.strlen($xlsx));
        header('Cache-Control: max-age=0');
        echo $xlsx;
        exit;
    }

    private function generar(): string
    {
        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        // _rels/.rels
        $zip->addFromString('_rels/.rels', $this->rootRels());
        // xl/workbook.xml
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        // xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        // xl/styles.xml
        $zip->addFromString('xl/styles.xml', $this->styles());
        // xl/sharedStrings.xml + hojas
        [$shared, $sharedIdx] = $this->buildShared();
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStrings($shared));

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml',
                $this->worksheet($sheet, $sharedIdx));
        }
        $zip->close();
        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    private function buildShared(): array
    {
        $shared = [];
        $idx    = [];
        $add = function(string $s) use (&$shared, &$idx) {
            if (!isset($idx[$s])) {
                $idx[$s] = count($shared);
                $shared[] = $s;
            }
        };
        foreach ($this->sheets as $sheet) {
            foreach ($sheet['encabezados'] as $h) $add((string)$h);
            foreach ($sheet['filas'] as $row) {
                foreach ($row as $cell) {
                    if (!is_numeric($cell)) $add((string)$cell);
                }
            }
            foreach ($sheet['totales'] as $cell) {
                if (!is_numeric($cell)) $add((string)$cell);
            }
        }
        return [$shared, $idx];
    }

    private function worksheet(array $sheet, array $sharedIdx): string
    {
        $cols   = count($sheet['encabezados']);
        $colLtrs= [];
        for ($i = 0; $i < $cols; $i++) $colLtrs[] = $this->colLetter($i);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.
               '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
               '<sheetView workbookViewId="0"><selection activeCell="A1"/></sheetView>'.
               '<sheetData>';

        // Fila de encabezados (estilo 1 = negrita)
        $xml .= '<row r="1">';
        foreach ($sheet['encabezados'] as $ci => $h) {
            $ref = $colLtrs[$ci].'1';
            $si  = $sharedIdx[(string)$h] ?? 0;
            $xml .= '<c r="'.$ref.'" t="s" s="1"><v>'.$si.'</v></c>';
        }
        $xml .= '</row>';

        // Filas de datos
        foreach ($sheet['filas'] as $ri => $row) {
            $rn = $ri + 2;
            $xml .= '<row r="'.$rn.'">';
            $ci2 = 0;
            foreach ($row as $cell) {
                $ref = $colLtrs[$ci2].$rn;
                if (is_numeric($cell) && $cell !== '') {
                    $xml .= '<c r="'.$ref.'"><v>'.htmlspecialchars((string)$cell).'</v></c>';
                } else {
                    $si  = $sharedIdx[(string)$cell] ?? 0;
                    $xml .= '<c r="'.$ref.'" t="s"><v>'.$si.'</v></c>';
                }
                $ci2++;
            }
            $xml .= '</row>';
        }

        // Fila de totales (si existe, estilo 2 = negrita+fondo)
        if ($sheet['totales']) {
            $rn  = count($sheet['filas']) + 2;
            $xml .= '<row r="'.$rn.'">';
            foreach ($sheet['totales'] as $ci => $cell) {
                $ref = $colLtrs[$ci].$rn;
                if (is_numeric($cell) && $cell !== '') {
                    $xml .= '<c r="'.$ref.'" s="2"><v>'.htmlspecialchars((string)$cell).'</v></c>';
                } else {
                    $si  = $sharedIdx[(string)$cell] ?? 0;
                    $xml .= '<c r="'.$ref.'" t="s" s="2"><v>'.$si.'</v></c>';
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function colLetter(int $n): string
    {
        $s = '';
        for ($n++; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $_) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($i+1).'.xml"'.
                       ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
               '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
               '<Default Extension="xml" ContentType="application/xml"/>'.
               '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
               '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
               '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'.
               $sheets.
               '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
               '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
               '</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $s) {
            $sheets .= '<sheet name="'.htmlspecialchars($s['nombre']).'" sheetId="'.($i+1).'" r:id="rId'.($i+1).'"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'.
               ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
               '<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $_) {
            $rels .= '<Relationship Id="rId'.($i+1).'" '.
                     'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '.
                     'Target="worksheets/sheet'.($i+1).'.xml"/>';
        }
        $rels .= '<Relationship Id="rId'.(count($this->sheets)+1).'" '.
                 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '.
                 'Target="styles.xml"/>'.
                 '<Relationship Id="rId'.(count($this->sheets)+2).'" '.
                 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" '.
                 'Target="sharedStrings.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
               $rels.'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
               '<fonts count="2">'.
               '<font><sz val="11"/><name val="Calibri"/></font>'.
               '<font><b/><sz val="11"/><name val="Calibri"/></font>'.
               '</fonts>'.
               '<fills count="3">'.
               '<fill><patternFill patternType="none"/></fill>'.
               '<fill><patternFill patternType="gray125"/></fill>'.
               '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E8FF"/></patternFill></fill>'.
               '</fills>'.
               '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'.
               '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
               '<cellXfs count="3">'.
               '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'.
               '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'.
               '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'.
               '</cellXfs>'.
               '</styleSheet>';
    }

    private function sharedStrings(array $shared): string
    {
        $items = '';
        foreach ($shared as $s) {
            $items .= '<si><t xml:space="preserve">'.htmlspecialchars($s).'</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'.
               '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'.
               ' count="'.count($shared).'" uniqueCount="'.count($shared).'">'.
               $items.'</sst>';
    }
}
