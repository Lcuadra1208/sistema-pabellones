-- ============================================================
-- LIMPIEZA: solicitudes donde el tipo de cirugía no pertenece
-- a la institución del pabellón asignado.
-- Ejecutar en phpMyAdmin sobre la base pabellones_db
-- ============================================================

USE pabellones_db;

-- 1. Ver cuáles son (antes de eliminar)
SELECT s.id, s.fecha_solicitada, s.hora_inicio, s.hora_fin,
       s.estado, tc.nombre AS cirugia,
       tc.id_institucion AS inst_cirugia,
       s.id_institucion   AS inst_solicitud,
       p.nombre AS pabellon
FROM solicitudes_pabellon s
JOIN tipos_cirugia tc ON tc.id = s.id_tipo_cirugia
LEFT JOIN pabellones p ON p.id = s.id_pabellon
WHERE tc.id_institucion <> s.id_institucion;

-- 2. Recuperar ofertas que quedaron "reservadas" por esas solicitudes
--    (las restauramos a 'disponible' para que vuelvan al calendario)
UPDATE ofertas_pabellon o
JOIN solicitudes_pabellon s ON s.id = o.id_solicitud
JOIN tipos_cirugia tc ON tc.id = s.id_tipo_cirugia
SET o.estado = 'disponible', o.id_solicitud = NULL
WHERE tc.id_institucion <> s.id_institucion;

-- 3. Eliminar las solicitudes inválidas
DELETE s FROM solicitudes_pabellon s
JOIN tipos_cirugia tc ON tc.id = s.id_tipo_cirugia
WHERE tc.id_institucion <> s.id_institucion;

-- Verificar resultado
SELECT 'Solicitudes inválidas restantes:' AS msg,
       COUNT(*) AS total
FROM solicitudes_pabellon s
JOIN tipos_cirugia tc ON tc.id = s.id_tipo_cirugia
WHERE tc.id_institucion <> s.id_institucion;
