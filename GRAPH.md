# Grafo del repositorio: sistema-pabellones

Aplicación PHP (sin framework) para gestión de pabellones quirúrgicos: solicitudes, ofertas, programación, calendario, mantención y reportes. 44 archivos, ~9.800 líneas.

## 1. Dependencias de archivos

```mermaid
graph LR
  subgraph config
    CFG[config/config.php]
    DB[config/database.php]
  end
  subgraph includes
    INIT[init.php]
    HELP[helpers.php<br/>sesión, roles, CSRF, audit, flash]
    LAY[layout.php<br/>layout_header / footer]
    BRE[brevo.php<br/>email]
    XLS[excel_writer.php]
  end
  INIT --> CFG
  INIT --> DB
  INIT --> HELP

  IDX[index.php<br/>login] --> INIT
  DASH[dashboard.php] --> INIT
  DASH --> LAY
  EXP[exportar_data.php]

  subgraph modules
    AUTH[auth: perfil, logout]
    CAL[calendario: index, disponibilidad]
    CONF[configuracion: index, auditoria]
    MAN[mantencion/index]
    MANT[mantenedores x8]
    OFE[ofertas/index]
    PRO[programacion/index]
    REP[reportes: index, pdf_print]
    SOL[solicitudes: index, bloqueos]
  end
  AUTH --> INIT
  CAL --> INIT
  CONF --> INIT
  MAN --> INIT
  MANT --> INIT
  OFE --> INIT
  PRO --> INIT
  REP --> INIT
  SOL --> INIT
  AUTH & CAL & CONF & MAN & MANT & OFE & PRO & REP & SOL --> LAY
  OFE --> BRE
  SOL --> BRE
  CONF --> BRE
  REP --> XLS
  REP --> PDF[pdf_print.php]
```

Nota: las flechas a `brevo.php` y `excel_writer.php` son aproximadas (hay 3 requires de brevo y 1 de excel_writer); verifique el módulo exacto con `grep -rn brevo modules`.

## 2. Modelo de datos (database.sql)

```mermaid
erDiagram
  instituciones ||--o{ usuarios : tiene
  roles ||--o{ usuarios : asigna
  instituciones ||--o{ pabellones : posee
  tipos_pabellon ||--o{ pabellones : clasifica
  instituciones ||--o{ tipos_pabellon : define
  instituciones ||--o{ tipos_cirugia : define
  tipos_pabellon ||--o{ tipos_cirugia : requiere
  instituciones ||--o{ tipos_insumo : define
  tipos_insumo ||--o{ insumos : clasifica
  tipos_cirugia ||--o{ cirugia_insumos : usa
  insumos ||--o{ cirugia_insumos : en
  tipos_cirugia ||--o{ precios_cirugia : precio
  usuarios ||--o{ acreditaciones : medico
  instituciones ||--o{ acreditaciones : en
  usuarios ||--o{ equipo_quirurgico : medico_y_miembro
  usuarios ||--o{ solicitudes_pabellon : medico
  pabellones ||--o{ solicitudes_pabellon : asignado
  tipos_cirugia ||--o{ solicitudes_pabellon : tipo
  pabellones ||--o{ bloqueos_pabellon : bloqueado
  pabellones ||--o{ ofertas_pabellon : ofertado
  solicitudes_pabellon ||--o{ ofertas_pabellon : origina
  usuarios ||--o{ audit_log : registra
```

## 3. Nodos más centrales

| Nodo | Rol |
|---|---|
| `includes/init.php` | Punto de entrada común, cargado por todas las páginas |
| `includes/layout.php` | Plantilla UI compartida por todos los módulos |
| `includes/helpers.php` | Autenticación por rol, CSRF, auditoría, formato |
| `instituciones` (tabla) | Raíz multi-tenant: casi todas las tablas la referencian |
| `solicitudes_pabellon` | Entidad de negocio central (conecta médico, pabellón, cirugía, ofertas) |

## 4. Archivos más grandes (candidatos a refactor)

`ofertas/index.php` (786), `calendario/index.php` (770), `solicitudes/index.php` (711), `programacion/index.php` (686), `mantencion/index.php` (537).

## 5. Scripts SQL de migración

`migracion_periodos`, `migracion_mantencion`, `migracion_precio_oferta`, `migracion_precios_pabellon`, `migracion_config_email`, más dos de limpieza (`limpiar_ofertas_canceladas`, `limpiar_solicitudes_invalidas`). Son parches sobre `database.sql`.
