"""
routers/override.py
POST /override

Called by PHP (dashboard_a.php / dashboard_t.php) when Tech Admin approves an
HR-edited AI response and clicks "Push to Override Knowledge".

Workflow:
  1. Embed the override_response text locally (SentenceTransformer)
  2. Upsert embedding into ChromaDB hr_overrides collection
  3. Insert record into erp_portal.ai_overrides table
  4. Return { status, override_id }
"""

import uuid
import logging
from fastapi import APIRouter
from pydantic import BaseModel

router = APIRouter()
logger = logging.getLogger(__name__)


class OverrideRequest(BaseModel):
    log_id           : int
    query            : str
    override_response: str
    created_by       : str


@router.post("/override")
def push_override(req: OverrideRequest):
    """
    Store an HR-approved response as a searchable override embedding.
    """
    if not req.override_response.strip():
        return {"status": "error", "detail": "override_response is empty"}

    # 1. Clean up old overrides for the same log_id from MySQL and ChromaDB
    from db.mysql_client import execute_query
    try:
        existing = execute_query(
            "SELECT override_id FROM erp_portal.ai_overrides WHERE log_id = %s",
            (req.log_id,),
            fetch=True,
        )
        if existing:
            from db.chroma_client import delete_override
            for row in existing:
                delete_override(row["override_id"])
            execute_query(
                "DELETE FROM erp_portal.ai_overrides WHERE log_id = %s",
                (req.log_id,),
            )
    except Exception as exc:
        logger.warning("Failed to clean up old override entries: %s", exc)

    override_id = str(uuid.uuid4())

    # 2. Embed the original query (question) text instead of response
    from services.embedder import _embed_text
    try:
        embedding = _embed_text(req.query)
    except Exception as exc:
        logger.error("Failed to embed override query: %s", exc)
        return {"status": "error", "detail": str(exc)}

    # 3. Upsert into ChromaDB hr_overrides collection
    from db.chroma_client import upsert_override
    try:
        upsert_override(override_id, embedding, req.log_id)
    except Exception as exc:
        logger.error("ChromaDB upsert override failed: %s", exc)
        return {"status": "error", "detail": str(exc)}

    # 4. Insert into MySQL erp_portal.ai_overrides
    try:
        execute_query(
            "INSERT INTO erp_portal.ai_overrides "
            "(log_id, query, override_response, override_id, created_by) "
            "VALUES (%s, %s, %s, %s, %s)",
            (req.log_id, req.query, req.override_response, override_id, req.created_by),
        )
    except Exception as exc:
        logger.error("MySQL insert override failed: %s", exc)
        return {"status": "error", "detail": str(exc)}

    logger.info("Override '%s' stored for log_id=%d by %s", override_id, req.log_id, req.created_by)
    return {"status": "success", "override_id": override_id}
