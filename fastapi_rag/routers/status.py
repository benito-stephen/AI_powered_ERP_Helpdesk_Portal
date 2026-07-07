"""
routers/status.py
GET /status/{doc_id}

Returns the current processing status for a document.
Used by PHP (rag_status.php AJAX endpoint) to poll pipeline progress
and display it in the Tech Admin dashboard.
"""

import logging
from fastapi import APIRouter, HTTPException
from db.mysql_client import execute_query

router = APIRouter()
logger = logging.getLogger(__name__)

# Human-readable labels for each processing status
STATUS_LABELS = {
    "uploaded"  : "Uploaded – awaiting Tech Admin approval",
    "extracting": "Processing – extracting text from document",
    "chunking"  : "Processing – splitting into knowledge chunks",
    "embedding" : "Processing – generating AI embeddings",
    "ready"     : "Ready – fully indexed for semantic search",
    "error"     : "Error – pipeline failed",
}


@router.get("/status/{doc_id}")
def get_status(doc_id: str):
    """
    Return processing status and pipeline log for a document.

    Returns:
        {
          "document_id"   : str,
          "filename"      : str,
          "status"        : str,
          "status_label"  : str,
          "chunk_count"   : int,
          "error_message" : str | null,
          "log"           : [{"stage", "status", "message", "created_at"}, ...]
        }
    """
    rows = execute_query(
        """
        SELECT document_id, original_filename, processing_status,
               error_message
        FROM rag_documents
        WHERE document_id = %s
        LIMIT 1
        """,
        (doc_id,),
        fetch=True,
    )

    if not rows:
        raise HTTPException(
            status_code=404,
            detail=f"Document '{doc_id}' not found.",
        )

    doc = rows[0]
    status = doc["processing_status"]

    # Chunk count from rag_chunks
    chunk_rows = execute_query(
        "SELECT COUNT(*) AS cnt FROM rag_chunks WHERE document_id = %s",
        (doc_id,),
        fetch=True,
    )
    chunk_count = chunk_rows[0]["cnt"] if chunk_rows else 0

    # Pipeline log
    log_rows = execute_query(
        """
        SELECT stage, status, message, chunk_count, created_at
        FROM rag_processing_log
        WHERE document_id = %s
        ORDER BY id ASC
        """,
        (doc_id,),
        fetch=True,
    )

    formatted_logs = []
    for r in log_rows:
        created_at_val = r["created_at"]
        if hasattr(created_at_val, "strftime"):
            created_at_str = created_at_val.strftime("%Y-%m-%d %H:%M:%S")
        else:
            created_at_str = str(created_at_val) if created_at_val else None

        formatted_logs.append({
            "stage": r["stage"],
            "status": r["status"],
            "message": r["message"],
            "chunk_count": r["chunk_count"],
            "created_at": created_at_str
        })

    return {
        "document_id"  : doc["document_id"],
        "filename"     : doc["original_filename"],
        "status"       : status,
        "status_label" : STATUS_LABELS.get(status, status),
        "chunk_count"  : chunk_count,
        "error_message": doc["error_message"],
        "log"          : formatted_logs,
    }


@router.get("/status")
def list_all_statuses():
    """
    Return a summary of all documents and their processing statuses.
    Used by the Tech Admin dashboard overview panel.
    """
    rows = execute_query(
        """
        SELECT document_id, original_filename, processing_status,
               uploaded_by, uploaded_at,
               (SELECT COUNT(*) FROM rag_chunks rc WHERE rc.document_id = rd.document_id) AS chunk_count
        FROM rag_documents rd
        ORDER BY uploaded_at DESC
        """,
        fetch=True,
    )

    return {
        "total"    : len(rows),
        "documents": [
            {
                **r,
                "status_label": STATUS_LABELS.get(r["processing_status"], r["processing_status"]),
                "uploaded_at" : str(r["uploaded_at"]) if r["uploaded_at"] else None,
            }
            for r in rows
        ],
    }
