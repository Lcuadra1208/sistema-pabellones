<?php
/**
 * exportar_data.php — Genera INSERT SQL de toda la data de pabellones_db
 * Colocar en: C:\xampp\htdocs\pabellones\exportar_data.php
 * Ejecutar en: http://localhost/pabellones/exportar_data.php
 */

// ── Configuración ─────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'pabellones_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Tablas en orden correcto (respetando FK)
$tablas = [
    'roles',
    'instituciones',
    'usuarios',
    'acreditaciones',
    'tipos_pabellon',
    'pabellones',
    'tipos_insumo',
    'insumos',
    'tipos_cirugia',
    'cirugia_insumos',
    'precios_tipo_pabellon',
    'equipo_quirurgico',
    'periodos_pabellon',
    'periodo_pabellon_detalle',
    'bloqueos_pabellon',
    'solicitudes_pabellon',
    'solicitudes_historial',
    'ofertas_pabellon',
    'configuracion_sistema',
    'audit_log',
];

// ── Conexión ──────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

// ── Generar SQL ───────────────────────────────────────────
$fecha    = date('Y-m-d_H-i-s');
$filename = "pabellones_data_{$fecha}.sql";

header('Content-Type: text/plain; charset=utf-8');
header("Content-Disposition: attachment; filename=\"{$filename}\"");

echo "-- ============================================================\n";
echo "-- DATA EXPORT: pabellones_db\n";
echo "-- Generado: " . date('Y-m-d H:i:s') . "\n";
echo "-- ============================================================\n\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n";
echo "SET NAMES utf8mb4;\n\n";

foreach ($tablas as $tabla) {
    // Verificar que la tabla existe
    $existe = $pdo->query("SHOW TABLES LIKE '$tabla'")->fetch();
    if (!$existe) {
        echo "-- Tabla '$tabla' no existe, omitiendo.\n\n";
        continue;
    }

    // Contar filas
    $count = $pdo->query("SELECT COUNT(*) FROM `$tabla`")->fetchColumn();
    
    echo "-- ──────────────────────────────────────────────────────────\n";
    echo "-- Tabla: $tabla ($count filas)\n";
    echo "-- ──────────────────────────────────────────────────────────\n";

    if ($count == 0) {
        echo "-- (sin datos)\n\n";
        continue;
    }

    // Obtener columnas
    $cols_raw = $pdo->query("SHOW COLUMNS FROM `$tabla`")->fetchAll(PDO::FETCH_ASSOC);
    $columnas = array_column($cols_raw, 'Field');
    $cols_sql = '`' . implode('`, `', $columnas) . '`';

    // Obtener datos en lotes de 100
    $offset = 0;
    $batch  = 100;

    while (true) {
        $rows = $pdo->query("SELECT * FROM `$tabla` LIMIT $batch OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) break;

        echo "INSERT INTO `$tabla` ($cols_sql) VALUES\n";

        $values_lines = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $vals[] = 'NULL';
                } elseif (is_numeric($val) && !preg_match('/^0\d/', $val)) {
                    $vals[] = $val;
                } else {
                    // Escapar string
                    $escaped = str_replace(
                        ["\\", "'", "\n", "\r", "\0"],
                        ["\\\\", "\\'", "\\n", "\\r", "\\0"],
                        $val
                    );
                    $vals[] = "'" . $escaped . "'";
                }
            }
            $values_lines[] = '(' . implode(', ', $vals) . ')';
        }
        echo implode(",\n", $values_lines) . ";\n\n";

        $offset += $batch;
        if (count($rows) < $batch) break;
    }
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "\n-- FIN DEL EXPORT\n";
exit;
