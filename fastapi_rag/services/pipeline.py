"""
services/pipeline.py
Full document processing pipeline orchestrator (unchanged logic).
Stages: extract → store temp → chunk → verify+clear temp → embed
"""

import logging
from db.mysql_client import execute_query, execute_many

logger = logging.getLogger(__name__)


def _log_stage(document_id, stage, status, message="", chunk_count=None):
    execute_query(
        "INSERT INTO rag_processing_log (document_id, stage, status, message, chunk_count) "
        "VALUES (%s, %s, %s, %s, %s)",
        (document_id, stage, status, message, chunk_count)
    )


def _update_status(document_id, status, error_msg=None):
    if error_msg:
        execute_query(
            "UPDATE rag_documents SET processing_status=%s, error_message=%s WHERE document_id=%s",
            (status, error_msg, document_id)
        )
    else:
        execute_query(
            "UPDATE rag_documents SET processing_status=%s WHERE document_id=%s",
            (status, document_id)
        )


def run_pipeline(document_id: str, file_path: str, filename: str):
    logger.info("Pipeline started for '%s' (%s)", document_id, filename)

    # ── Stage 1: Extraction ───────────────────────────────────────────────────
    try:
        _update_status(document_id, "extracting")
        _log_stage(document_id, "extract", "started")

        from services.extractor import extract_text
        pages = extract_text(file_path)

        ins_sql = """
            INSERT INTO rag_extracted_text
                (document_id, page_number, page_text, char_count)
            VALUES (%s, %s, %s, %s)
        """
        execute_many(ins_sql, [
            (document_id, p["page_number"], p["page_text"], len(p["page_text"]))
            for p in pages
        ])
        _log_stage(document_id, "extract", "completed", f"Extracted {len(pages)} page(s).")

    except Exception as exc:
        msg = f"Extraction failed: {exc}"
        logger.error(msg)
        _update_status(document_id, "error", msg)
        _log_stage(document_id, "extract", "failed", msg)
        return

    # ── Stage 2: Chunking ─────────────────────────────────────────────────────
    try:
        _update_status(document_id, "chunking")
        _log_stage(document_id, "chunk", "started")

        from services.chunker import chunk_pages, save_chunks_to_mysql, clear_extracted_text
        chunks = chunk_pages(pages, document_id, filename)

        if not chunks:
            raise ValueError("Chunking produced 0 chunks.")

        save_chunks_to_mysql(chunks)
        verified = clear_extracted_text(document_id)

        _log_stage(document_id, "chunk", "completed",
                   f"Created and verified {verified} chunk(s).", verified)

    except Exception as exc:
        msg = f"Chunking failed: {exc}"
        logger.error(msg)
        _update_status(document_id, "error", msg)
        _log_stage(document_id, "chunk", "failed", msg)
        return

    # ── Stage 3: Embedding ────────────────────────────────────────────────────
    try:
        _update_status(document_id, "embedding")
        _log_stage(document_id, "embed", "started")

        from db.chroma_client import delete_document_embeddings
        delete_document_embeddings(document_id)   # clear stale ChromaDB embeddings

        from services.embedder import embed_and_store
        stored = embed_and_store(chunks)

        _log_stage(document_id, "embed", "completed",
                   f"Stored {stored} embedding vectors.", stored)

    except Exception as exc:
        msg = f"Embedding failed: {exc}"
        logger.error(msg)
        _update_status(document_id, "error", msg)
        _log_stage(document_id, "embed", "failed", msg)
        return

    # ── Complete ───────────────────────────────────────────────────────────────
    _update_status(document_id, "ready")
    _log_stage(document_id, "complete", "completed",
               f"'{filename}' fully indexed.", len(chunks))
    logger.info("Pipeline COMPLETE for '%s'.", document_id)
