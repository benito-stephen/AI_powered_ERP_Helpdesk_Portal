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
    file    : UploadFile = File(...),
    user_id : str        = Form(...),
    kb_id   : int        = Form(None),   # erp_portal.knowledge_base.id (optional)
):
    """
    Receive and store an uploaded ERP document.

    Returns:
        {
          "status"      : "success",
          "document_id" : "<UUID>",
          "filename"    : "<stored filename>",
          "message"     : "..."
        }
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

    # Read content into memory to check size
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

    # ── Check for duplicate filename ──────────────────────────────────────────
    existing = execute_query(
        "SELECT document_id FROM rag_documents WHERE original_filename = %s LIMIT 1",
        (original_filename,),
        fetch=True,
    )
    if existing:
        raise HTTPException(
            status_code=409,
            detail=f"A document named '{original_filename}' already exists. "
                   "Please rename the file or delete the existing one first.",
        )

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

    # ── Detect document type ──────────────────────────────────────────────────
    doc_type_map = {".pdf": "pdf", ".docx": "docx", ".txt": "txt"}
    doc_type = doc_type_map.get(extension, "unknown")

    # ── Insert into rag_documents ─────────────────────────────────────────────
    sql = """
        INSERT INTO rag_documents
            (document_id, kb_id, filename, original_filename, file_extension,
             file_size_bytes, storage_path, document_type, uploaded_by,
             processing_status)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, 'uploaded')
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
