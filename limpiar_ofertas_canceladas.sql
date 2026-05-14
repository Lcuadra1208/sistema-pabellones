-- ============================================================
-- LIMPIEZA: Restaurar tramos de ofertas cuyas solicitudes
-- fueron canceladas/rechazadas sin devolver la disponibilidad
-- Ejecutar en phpMyAdmin sobre pabellones_db
-- ============================================================

USE `pabellones_db`;

-- Ver qué ofertas están reservadas por solicitudes canceladas
SELECT o.id, o.fecha_oferta, o.hora_inicio, o.hora_fin,
       p.nombre AS pabellon, s.estado AS estado_solicitud
FROM ofertas_pabellon o
JOIN solicitudes_pabellon s ON s.id = o.id_solicitud
JOIN pabellones p ON p.id = o.id_pabellon
WHERE o.estado = 'reservada'
  AND s.estado IN ('cancelada','rechazada');

-- Insertar nuevas ofertas disponibles para esos tramos
INSERT INTO ofertas_pabellon
  (id_pabellon, id_admin, fecha_oferta, hora_inicio, hora_fin,
   descripcion, precio_normal, precio_oferta, descuento_pct, modo_precio, estado)
SELECT
  o.id_pabellon,
  o.id_admin,
  s.fecha_solicitada,
  s.hora_inicio,
  s.hora_fin,
  'Espacio liberado por cancelación (corrección manual)',
  o.precio_normal,
  o.precio_oferta,
  o.descuento_pct,
  o.modo_precio,
  'disponible'
FROM ofertas_pabellon o
JOIN solicitudes_pabellon s ON s.id = o.id_solicitud
WHERE o.estado = 'reservada'
  AND s.estado IN ('cancelada','rechazada');

-- Verificar resultado
SELECT 'Tramos restaurados:' AS msg, ROW_COUNT() AS total;
