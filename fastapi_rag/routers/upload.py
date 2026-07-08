"""
routers/upload.py
POST /upload

Receives an uploaded file from PHP (via cURL multipart/form-data),
validates it, stores it permanently in the uploads/ directory,
creates a rag_documents record, and returns a document_id.

The actual processing pipeline is NOT triggered here — it is triggered
by the Tech Admin clicking "Accept" in dashboard_t.php, which calls
POST /process/{doc_id}.
"""

import uuid
import shutil
import logging
from pathlib import Path
from fastapi import APIRouter, UploadFile, File, Form, HTTPException, BackgroundTasks
from db.mysql_client import execute_query
from settings import get_settings

router = APIRouter()
logger = logging.getLogger(__name__)

ALLOWED_EXTENSIONS = {".pdf", ".docx", ".txt"}
ALLOWED_MIME_TYPES = {
    "application/pdf",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "text/plain",
}


@router.post("/upload")
async def upload_document(
    file        : UploadFile = File(...),
    user_id     : str        = Form(...),
    kb_id       : int        = Form(None),
    page_offset : int        = Form(0),
):
    """
    Receive and store an uploaded ERP document.
    """
    cfg = get_settings()

    # ── Validation ────────────────────────────────────────────────────────────

    if not file.filename:
        raise HTTPException(status_code=400, detail="No filename provided.")

    original_filename = file.filename
    extension = Path(original_filename).suffix.lower()

    if extension not in ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=400,
            detail=f"Unsupported file type '{extension}'. Allowed: .pdf, .docx, .txt",
        )

    content = await file.read()
    file_size = len(content)
    max_bytes = cfg.max_file_size_mb * 1024 * 1024

    if file_size == 0:
        raise HTTPException(status_code=400, detail="Uploaded file is empty.")

    if file_size > max_bytes:
        raise HTTPException(
            status_code=400,
            detail=f"File too large ({file_size // 1024 // 1024} MB). "
                   f"Maximum allowed: {cfg.max_file_size_mb} MB.",
        )

    # ── Document Versioning (Replace existing) ────────────────────────────────
    existing = execute_query(
        "SELECT document_id, storage_path FROM rag_documents WHERE original_filename = %s LIMIT 1",
        (original_filename,),
        fetch=True,
    )
    if existing:
        old_doc_id = existing[0]["document_id"]
        old_path = existing[0]["storage_path"]
        logger.info(f"Duplicate found for {original_filename}. Deleting old version {old_doc_id}...")
        
        # Delete from ChromaDB
        try:
            from db.chroma_client import delete_document_chunks
            delete_document_chunks(old_doc_id)
        except Exception as e:
            logger.warning(f"Failed to delete from ChromaDB for {old_doc_id}: {e}")
        
        # Delete from MySQL
        execute_query("DELETE FROM rag_extracted_text WHERE document_id = %s", (old_doc_id,))
        execute_query("DELETE FROM rag_chunks WHERE document_id = %s", (old_doc_id,))
        execute_query("DELETE FROM rag_embeddings WHERE document_id = %s", (old_doc_id,))
        execute_query("DELETE FROM rag_processing_log WHERE document_id = %s", (old_doc_id,))
        execute_query("DELETE FROM rag_documents WHERE document_id = %s", (old_doc_id,))
        
        # Delete physical file
        try:
            old_file = Path(old_path)
            if old_file.exists():
                old_file.unlink()
        except Exception as e:
            logger.warning(f"Failed to delete old physical file {old_path}: {e}")

    # ── Generate UUID and store file ──────────────────────────────────────────
    document_id = str(uuid.uuid4())
    stored_filename = f"{document_id}{extension}"

    uploads_dir = Path(cfg.uploads_dir)
    uploads_dir.mkdir(parents=True, exist_ok=True)

    file_path = uploads_dir / stored_filename
    file_path.write_bytes(content)

    logger.info(
        "Stored file '%s' as '%s' (%.1f KB).",
        original_filename, stored_filename, file_size / 1024,
    )

    doc_type_map = {".pdf": "pdf", ".docx": "docx", ".txt": "txt"}
    doc_type = doc_type_map.get(extension, "unknown")

    # ── Insert into rag_documents ─────────────────────────────────────────────
    sql = """
        INSERT INTO rag_documents
            (document_id, kb_id, filename, original_filename, file_extension,
             file_size_bytes, storage_path, document_type, uploaded_by,
             processing_status, page_offset)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, 'uploaded', %s)
    """
    execute_query(sql, (
        document_id,
        kb_id,
        stored_filename,
        original_filename,
        extension,
        file_size,
        str(file_path.resolve()),
        doc_type,
        user_id,
        page_offset,
    ))

    # ── Log the upload stage ──────────────────────────────────────────────────
    execute_query(
        """
        INSERT INTO rag_processing_log (document_id, stage, status, message)
        VALUES (%s, 'upload', 'completed', %s)
        """,
        (document_id, f"File '{original_filename}' uploaded and stored ({file_size} bytes)."),
    )

    logger.info("Upload complete. document_id=%s", document_id)

    return {
        "status"      : "success",
        "document_id" : document_id,
        "filename"    : original_filename,
        "message"     : (
            f"Document '{original_filename}' uploaded successfully. "
            "Awaiting Tech Admin approval to begin processing."
        ),
    }
