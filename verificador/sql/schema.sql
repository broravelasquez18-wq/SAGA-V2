-- ---------------------------------------------------------------------------
-- Esquema del Verificador de documentación por OCR
-- MySQL 8 / InnoDB / utf8mb4
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS lotes_verificacion (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_ficha   VARCHAR(20)  NOT NULL,
  programa       VARCHAR(255) NULL,
  origen_lista   VARCHAR(255) NULL,
  estado         ENUM('en_proceso','verificado','cerrado') NOT NULL DEFAULT 'en_proceso',
  creado_por     INT UNSIGNED NULL,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ficha (codigo_ficha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inscritos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lote_id          INT UNSIGNED NOT NULL,
  tipo_documento   VARCHAR(5)   NOT NULL DEFAULT 'CC',
  numero_documento VARCHAR(20)  NOT NULL,     -- normalizado: solo dígitos
  nombre           VARCHAR(150) NOT NULL,
  estado_sena      VARCHAR(30)  NULL,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inscrito_lote FOREIGN KEY (lote_id)
    REFERENCES lotes_verificacion(id) ON DELETE CASCADE,
  UNIQUE KEY uq_lote_documento (lote_id, numero_documento),
  INDEX idx_documento (numero_documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS documentos_ocr (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lote_id      INT UNSIGNED NOT NULL,
  origen       ENUM('pdf','foto','camara') NOT NULL,
  ruta_imagen  VARCHAR(255) NOT NULL,
  pagina       INT NULL,
  numero_leido VARCHAR(20) NULL,
  zona_fuente  ENUM('mrz','codigo_barras','frontal','desconocida') NULL,
  confianza    DECIMAL(5,2) NULL,
  texto_crudo  TEXT NULL,
  procesado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_lote FOREIGN KEY (lote_id)
    REFERENCES lotes_verificacion(id) ON DELETE CASCADE,
  INDEX idx_numero_leido (numero_leido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS verificaciones (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lote_id           INT UNSIGNED NOT NULL,
  inscrito_id       INT UNSIGNED NOT NULL,
  documento_ocr_id  INT UNSIGNED NULL,
  numero_confirmado VARCHAR(20) NULL,
  estado            ENUM('ok','corregido','no_coincide','falta_documento') NOT NULL,
  corregido_manual  TINYINT(1) NOT NULL DEFAULT 0,
  observacion       VARCHAR(255) NULL,
  confirmado        TINYINT(1) NOT NULL DEFAULT 0,
  verificado_por    INT UNSIGNED NULL,
  verificado_en     DATETIME NULL,
  CONSTRAINT fk_ver_lote     FOREIGN KEY (lote_id)          REFERENCES lotes_verificacion(id) ON DELETE CASCADE,
  CONSTRAINT fk_ver_inscrito FOREIGN KEY (inscrito_id)      REFERENCES inscritos(id)          ON DELETE CASCADE,
  CONSTRAINT fk_ver_doc      FOREIGN KEY (documento_ocr_id) REFERENCES documentos_ocr(id)     ON DELETE SET NULL,
  UNIQUE KEY uq_inscrito (inscrito_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Tabla mínima de usuarios. Por ahora un solo operador; las columnas
-- creado_por / verificado_por de las demás tablas apuntan (lógicamente) aquí.
-- Deja el terreno listo para escalar a roles más adelante.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario      VARCHAR(60)  NOT NULL,
  nombre       VARCHAR(150) NOT NULL,
  clave_hash   VARCHAR(255) NOT NULL,
  rol          VARCHAR(30)  NOT NULL DEFAULT 'operador',
  activo       TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Operador por defecto: usuario `operador`, clave `operador123`.
-- El hash corresponde a password_hash('operador123', PASSWORD_DEFAULT).
-- CÁMBIALO en producción (ver README).
INSERT INTO usuarios (usuario, nombre, clave_hash, rol)
SELECT 'operador', 'Operador de verificación',
       '$2y$10$2H1Zi4.em1LgqGUYbmSEYuEYVQiwA7PLRntg1V8.Go.XGzSrOcA46', 'operador'
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE usuario = 'operador');
