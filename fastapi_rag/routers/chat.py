"""
routers/chat.py
POST /chat

Receives a user question (already routed here by chatbot_backend.php
because PHP determined it is a policy/document query, NOT a personal
ERP data query).

Workflow:
  1. Validate input
  2. Embed the question locally
  3. Check HR override collection first — if match >= threshold, return it immediately
  4. Retrieve relevant document chunks (embedder + ChromaDB HNSW + MySQL text fetch)
  5. If no relevant chunks found → return fallback message (no Gemini call)
  6. Call Gemini with compact RAG prompt (retrieved context + question)
  7. Return answer + best single source citation as JSON

No ERP personal data is included here (attendance/leaves/profile).
That logic stays entirely in PHP's chatbot_backend.php.
"""

import logging
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

router = APIRouter()
logger = logging.getLogger(__name__)

FALLBACK_MESSAGE = (
    "No relevant context was found in the uploaded documents. "
    "Please refer to the HR policies directly."
)


class ChatRequest(BaseModel):
    question : str
    user_id  : str


@router.post("/chat")
def rag_chat(req: ChatRequest):
    """
    RAG-powered chat endpoint.

    Returns:
        {
          "status"      : "success" | "no_context" | "error" | "override",
          "answer"      : str,
          "source_type" : "document" | "override" | "none",
          "source"      : {
              # When source_type == "document":
              "filename"    : str,
              "page"        : int,
              "document_id" : str,
              "score"       : float,
              # When source_type == "override":
              "override_id" : str,
              "score"       : float,
          } | null
        }
    """
    question = req.question.strip()

    if not question:
        raise HTTPException(status_code=400, detail="Question cannot be empty.")

    if len(question) > 1000:
        raise HTTPException(
            status_code=400, detail="Question too long (max 1000 characters)."
        )

    logger.info("RAG chat request from user '%s': '%s...'", req.user_id, question[:60])

    # ── Step 1: Embed the question once ───────────────────────────────────────
    from services.embedder import _embed_text
    try:
        query_vec = _embed_text(question)
    except Exception as exc:
        logger.error("Query embedding failed: %s", exc)
        return {
            "status"      : "error",
            "answer"      : "The document search service is temporarily unavailable.",
            "source_type" : "none",
            "source"      : None,
        }

    # ── Step 2: Check HR override collection first ────────────────────────────
    try:
        from services.retriever import retrieve_override
        override = retrieve_override(question, query_vec)
    except Exception as exc:
        logger.warning("Override retrieval failed (non-fatal): %s", exc)
        override = None

    # ── Step 3: Retrieve document chunks ──────────────────────────────────────
    try:
        from services.retriever import retrieve_context
        chunks = retrieve_context(question)
    except Exception as exc:
        logger.error("Retrieval failed: %s", exc)
        chunks = []

    # Decide: use override if it exists AND its score beats the best document score
    best_doc_score = max((c["score"] for c in chunks), default=0.0)
    use_override   = (
        override is not None and
        override["score"] >= best_doc_score
    )

    if use_override:
        logger.info(
            "Using HR override (score=%.4f vs doc_score=%.4f) as context for: '%s...'",
            override["score"], best_doc_score, question[:60]
        )
        override_chunk = [{
            "chunk_id"   : override["override_id"],
            "chunk_text" : override["override_response"],
            "filename"   : "HR Override",
            "page_number": 1,
            "score"      : override["score"],
        }]
        try:
            from services.gemini import call_gemini_rag
            result = call_gemini_rag(question, override_chunk)
            answer = result["answer"]
        except Exception as exc:
            logger.error("Gemini call for HR override failed, using raw response: %s", exc)
            answer = override["override_response"]

        return {
            "status"     : "override",
            "answer"     : answer,
            "source_type": "override",
            "source"     : {
                "override_id": override["override_id"],
                "score"      : override["score"],
            },
        }

    # ── Step 4: No context → return fallback ──────────────────────────────────
    if not chunks:
        logger.info("No relevant chunks found for question. Returning fallback.")
        return {
            "status"     : "no_context",
            "answer"     : FALLBACK_MESSAGE,
            "source_type": "none",
            "source"     : None,
        }

    # ── Step 5: Call Gemini with RAG prompt ───────────────────────────────────
    try:
        from services.gemini import call_gemini_rag
        result = call_gemini_rag(question, chunks)
    except Exception as exc:
        logger.error("Gemini RAG call failed: %s", exc)
        return {
            "status"     : "error",
            "answer"     : "AI service temporarily unavailable. Please try again.",
            "source_type": "none",
            "source"     : None,
        }

    # ── Step 6: Pick only the BEST single source ──────────────────────────────
    best_chunk = max(chunks, key=lambda c: c["score"]) if chunks else None
    source = None
    if best_chunk:
        source = {
            "filename"   : best_chunk["filename"],
            "page"       : best_chunk["page_number"],
            "physical_page": best_chunk.get("physical_page_number", best_chunk["page_number"]),
            "document_id": best_chunk.get("document_id", ""),
            "score"      : best_chunk["score"],
        }

    return {
        "status"     : "success",
        "answer"     : result["answer"],
        "source_type": "document",
        "source"     : source,
    }
