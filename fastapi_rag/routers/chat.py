"""
routers/chat.py
POST /chat

Receives a user question (already routed here by chatbot_backend.php
because PHP determined it is a policy/document query, NOT a personal
ERP data query).

Workflow:
  1. Validate input
  2. Retrieve relevant chunks (embedder + ChromaDB HNSW + MySQL text fetch)
  3. If no relevant chunks found → return fallback message (no Gemini call)
  4. Call Gemini with compact RAG prompt (retrieved context + question)
  5. Return answer + source citations as JSON

No ERP personal data is included here (attendance/leaves/profile).
That logic stays entirely in PHP's chatbot_backend.php.
"""

import logging
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

router = APIRouter()
logger = logging.getLogger(__name__)

FALLBACK_MESSAGE = (
    "I couldn't find this information in the uploaded ERP documents. "
    "Please check with HR or refer to the relevant policy document directly."
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
          "status"  : "success" | "no_context" | "error",
          "answer"  : str,
          "sources" : [{"filename": str, "page": int}, ...]
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

    # ── Step 1: Semantic retrieval ────────────────────────────────────────────
    try:
        from services.retriever import retrieve_context
        chunks = retrieve_context(question)
    except Exception as exc:
        logger.error("Retrieval failed: %s", exc)
        return {
            "status" : "error",
            "answer" : "The document search service is temporarily unavailable. Please try again.",
            "sources": [],
        }

    # ── Step 2: No context → return fallback (saves Gemini token) ────────────
    if not chunks:
        logger.info("No relevant chunks found for question. Returning fallback.")
        return {
            "status" : "no_context",
            "answer" : FALLBACK_MESSAGE,
            "sources": [],
        }

    # ── Step 3: Call Gemini with RAG prompt ───────────────────────────────────
    try:
        from services.gemini import call_gemini_rag
        result = call_gemini_rag(question, chunks)
    except Exception as exc:
        logger.error("Gemini RAG call failed: %s", exc)
        return {
            "status" : "error",
            "answer" : "AI service temporarily unavailable. Please try again.",
            "sources": [],
        }

    return {
        "status" : "success",
        "answer" : result["answer"],
        "sources": result["sources"],
    }
