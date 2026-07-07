-- ============================================================
--  ERP RAG Pipeline Database
--  Run this in phpMyAdmin to set up the Phase II RAG database.
--  This is SEPARATE from the main erp_portal database.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `erp_rag`;
USE `erp_rag`;

-- -------------------------------------------------------
-- 1. rag_documents
--    Master record for every document uploaded through
--    the HR admin dashboard and forwarded to FastAPI.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rag_documents` (
  `id`               INT          AUTO_INCREMENT PRIMARY KEY,
  `document_id`      VARCHAR(36)  NOT NULL UNIQUE,   -- UUID assigned by FastAPI
  `kb_id`            INT          DEFAULT NULL,       -- FK to erp_portal.knowledge_base.id
  `filename`         VARCHAR(255) NOT NULL,
  `original_filename`VARCHAR(255) NOT NULL,
  `file_extension`   VARCHAR(10)  NOT NULL,
  `file_size_bytes`  BIGINT       NOT NULL,
  `storage_path`     VARCHAR(512) NOT NULL,
  `document_type`    VARCHAR(50)  DEFAULT 'pdf',
  `uploaded_by`      VARCHAR(50)  NOT NULL,           -- userid from erp_portal.users
  `uploaded_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `processing_status`VARCHAR(50)  DEFAULT 'uploaded', -- uploaded|extracting|chunking|embedding|ready|error
  `error_message`    TEXT         DEFAULT NULL,
  INDEX idx_document_id (`document_id`),
  INDEX idx_kb_id       (`kb_id`),
  INDEX idx_status      (`processing_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------
-- 2. rag_extracted_text
--    Temporary storage for page-wise extracted text.
--    CLEARED automatically by the chunker service after
--    all chunks for a document have been verified and
--    stored in rag_chunks. Acts as an intermediate buffer
--    between extraction and chunking stages.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rag_extracted_text` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `document_id` VARCHAR(36)  NOT NULL,
  `page_number` INT          NOT NULL DEFAULT 1,
  `page_text`   LONGTEXT     NOT NULL,
  `char_count`  INT          NOT NULL DEFAULT 0,
  `extracted_at`DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_doc_page (`document_id`, `page_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------
-- 3. rag_chunks
--    Permanent storage for all text chunks. Each chunk
--    has a UUID that acts as the cross-reference key
--    between MySQL (raw text) and ChromaDB (embedding).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rag_chunks` (
  `id`            INT          AUTO_INCREMENT PRIMARY KEY,
  `chunk_id`      VARCHAR(36)  NOT NULL UNIQUE,    -- UUID – xref key to ChromaDB
  `document_id`   VARCHAR(36)  NOT NULL,
  `filename`      VARCHAR(255) NOT NULL,
  `page_number`   INT          NOT NULL DEFAULT 1,
  `chunk_number`  INT          NOT NULL DEFAULT 1,
  `chunk_text`    MEDIUMTEXT   NOT NULL,
  `char_count`    INT          NOT NULL DEFAULT 0,
  `created_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chunk_id   (`chunk_id`),
  INDEX idx_doc_id     (`document_id`),
  INDEX idx_doc_page   (`document_id`, `page_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------
-- 4. rag_processing_log
--    Stage-by-stage audit trail for every document.
--    Each row records a pipeline stage transition.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rag_processing_log` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `document_id` VARCHAR(36)  NOT NULL,
  `stage`       VARCHAR(50)  NOT NULL,   -- upload|extract|chunk|embed|complete|error
  `status`      VARCHAR(20)  NOT NULL,   -- started|completed|failed
  `message`     TEXT         DEFAULT NULL,
  `chunk_count` INT          DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_doc_stage (`document_id`, `stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------------------
-- 5. rag_embeddings
--    Stores embedding vectors as JSON arrays.
--    Replaces ChromaDB — pure MySQL, no extra services.
--    chunk_id is the cross-reference key to rag_chunks.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rag_embeddings` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `chunk_id`    VARCHAR(36)  NOT NULL UNIQUE,
  `document_id` VARCHAR(36)  NOT NULL,
  `embedding`   MEDIUMTEXT   NOT NULL,   -- JSON array of floats (768-dim)
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_emb_doc (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
