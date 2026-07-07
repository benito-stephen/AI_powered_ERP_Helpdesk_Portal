"""
routers/process.py
POST /process/{doc_id}

Triggered by the Tech Admin clicking "Accept" in dashboard_t.php.
Kicks off the full RAG pipeline (extract → chunk → embed) as a
FastAPI BackgroundTask so the HTTP response is returned immediately.

The pipeline status can be polled via GET /status/{doc_id}.
"""

import logging
from fastapi import APIRouter, HTTPException, BackgroundTasks
from db.mysql_client import execute_query
from services.pipeline import run_pipeline

router = APIRouter()
logger = logging.getLogger(__name__)


@router.post("/process/{doc_id}")
async def process_document(doc_id: str, background_tasks: BackgroundTasks):
    """
    Trigger the full RAG pipeline for an already-uploaded document.

    Path param:
        doc_id : The UUID from rag_documents.document_id

    Returns immediately with 202 Accepted; pipeline runs in background.
    Poll GET /status/{doc_id} to track progress.
    """

    # Fetch document record
    rows = execute_query(
        "SELECT document_id, original_filename, storage_path, processing_status "
        "FROM rag_documents WHERE document_id = %s LIMIT 1",
        (doc_id,),
        fetch=True,
    )

    if not rows:
        raise HTTPException(
            status_code=404,
            detail=f"Document '{doc_id}' not found in rag_documents.",
        )

    doc = rows[0]

    # Prevent double-processing a ready document (unless forcing re-process)
    if doc["processing_status"] == "ready":
        return {
            "status"     : "already_ready",
            "document_id": doc_id,
            "message"    : (
                f"Document '{doc['original_filename']}' is already fully processed. "
                "No action taken."
            ),
        }

    # Prevent triggering while already in progress
    if doc["processing_status"] in ("extracting", "chunking", "embedding"):
        return {
            "status"     : "in_progress",
            "document_id": doc_id,
            "message"    : (
                f"Pipeline already running for '{doc['original_filename']}' "
                f"(current stage: {doc['processing_status']})."
            ),
        }

    # Queue the pipeline as a background task
    background_tasks.add_task(
        run_pipeline,
        document_id = doc_id,
        file_path   = doc["storage_path"],
        filename    = doc["original_filename"],
    )

    logger.info(
        "Pipeline queued for document '%s' ('%s').",
        doc_id, doc["original_filename"]
    )

    return {
        "status"     : "processing_started",
        "document_id": doc_id,
        "filename"   : doc["original_filename"],
        "message"    : (
            f"Pipeline started for '{doc['original_filename']}'. "
            "Poll GET /status/{doc_id} to track progress."
        ),
    }


@router.post("/process/{doc_id}/reprocess")
async def reprocess_document(doc_id: str, background_tasks: BackgroundTasks):
    """
    Force re-processing a document from scratch (e.g. after file replacement).
    Clears existing chunks and embeddings first.
    """
    rows = execute_query(
        "SELECT document_id, original_filename, storage_path "
        "FROM rag_documents WHERE document_id = %s LIMIT 1",
        (doc_id,),
        fetch=True,
    )

    if not rows:
        raise HTTPException(
            status_code=404,
            detail=f"Document '{doc_id}' not found.",
        )

    doc = rows[0]

    # Clear existing data before re-processing
    execute_query("DELETE FROM rag_chunks WHERE document_id = %s", (doc_id,))
    execute_query("DELETE FROM rag_extracted_text WHERE document_id = %s", (doc_id,))
    execute_query(
        "UPDATE rag_documents SET processing_status = 'uploaded', error_message = NULL "
        "WHERE document_id = %s",
        (doc_id,),
    )

    from services.embedder import delete_document_embeddings
    delete_document_embeddings(doc_id)

    background_tasks.add_task(
        run_pipeline,
        document_id = doc_id,
        file_path   = doc["storage_path"],
        filename    = doc["original_filename"],
    )

    logger.info("Re-processing triggered for document '%s'.", doc_id)

    return {
        "status"     : "reprocessing_started",
        "document_id": doc_id,
        "message"    : f"Re-processing started for '{doc['original_filename']}'.",
    }


@router.post("/delete/{doc_id}")
def delete_document(doc_id: str):
    """
    Remove a document physically and from all databases.
    """
    rows = execute_query(
        "SELECT storage_path FROM rag_documents WHERE document_id = %s LIMIT 1",
        (doc_id,),
        fetch=True
    )
    
    if rows:
        import os
        path = rows[0]["storage_path"]
        if path and os.path.exists(path):
            try:
                os.remove(path)
                logger.info("Deleted physical file: %s", path)
            except Exception as exc:
                logger.error("Failed to delete physical file: %s", exc)

    # Clean up DB
    execute_query("DELETE FROM rag_chunks WHERE document_id = %s", (doc_id,))
    execute_query("DELETE FROM rag_extracted_text WHERE document_id = %s", (doc_id,))
    execute_query("DELETE FROM rag_processing_log WHERE document_id = %s", (doc_id,))
    execute_query("DELETE FROM rag_documents WHERE document_id = %s", (doc_id,))

    # 1. Clean up MySQL vector store embeddings
    from db.vector_store import delete_document_embeddings as delete_mysql_embeddings
    delete_mysql_embeddings(doc_id)

    # 2. Clean up ChromaDB embeddings (if installed/running)
    try:
        from db.chroma_client import delete_document_embeddings as delete_chroma_embeddings
        delete_chroma_embeddings(doc_id)
        logger.info("Attempted ChromaDB vector cleanup for %s", doc_id)
    except Exception:
        pass

    logger.info("Deleted document %s from RAG backend.", doc_id)
    return {"status": "success", "message": f"Document '{doc_id}' successfully removed from RAG."}

