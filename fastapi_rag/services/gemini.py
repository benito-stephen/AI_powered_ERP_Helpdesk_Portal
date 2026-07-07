"""
services/gemini.py
Gemini API caller for the RAG pipeline.

Constructs a compact, token-efficient prompt that includes:
  - Retrieved document context (from ChromaDB / MySQL)
  - The user's question

The ERP personal data (attendance, leaves, profile) is NOT included here
because it is handled entirely in PHP (chatbot_backend.php) to minimise
Gemini token usage. Only document-based questions reach this service.
"""

import httpx
import logging
from settings import get_settings

logger = logging.getLogger(__name__)


def _build_context_block(chunks: list[dict]) -> str:
    """Format retrieved chunks into a concise numbered context block."""
    if not chunks:
        return "(No relevant document context found.)"

    lines = []
    for i, chunk in enumerate(chunks, start=1):
        lines.append(
            f"[{i}] Source: {chunk['filename']} (Page {chunk['page_number']})\n"
            f"{chunk['chunk_text']}"
        )
    return "\n\n---\n\n".join(lines)


def _build_prompt(question: str, context: str) -> str:
    """
    Build the RAG prompt sent to Gemini.
    Kept deliberately short to minimise token usage.
    """
    return (
        "You are an ERP Helpdesk Assistant. "
        "Answer ONLY using the provided context below. "
        "If the answer is not in the context, say: "
        "'I couldn't find this information in the uploaded ERP documents.'\n\n"
        "CONTEXT:\n"
        f"{context}\n\n"
        "QUESTION:\n"
        f"{question}\n\n"
        "ANSWER:"
    )


def call_gemini_rag(question: str, chunks: list[dict]) -> dict:
    """
    Send a RAG-enhanced prompt to the Gemini API.

    Args:
        question : User's question string.
        chunks   : Retrieved context chunks from retriever.retrieve_context().

    Returns:
        {
          "answer"  : str,
          "sources" : [{"filename": str, "page": int}, ...]
        }
    """
    cfg = get_settings()

    context = _build_context_block(chunks)
    prompt  = _build_prompt(question, context)

    url = (
        f"https://generativelanguage.googleapis.com/v1beta/models/"
        f"{cfg.gemini_model}:generateContent"
    )

    payload = {
        "contents": [
            {"role": "user", "parts": [{"text": prompt}]}
        ],
        "generationConfig": {
            "temperature"     : 0.2,   # low temperature for factual answers
            "maxOutputTokens" : 400,   # compact answers to save tokens
        },
    }

    headers = {
        "Content-Type" : "application/json",
        "x-goog-api-key": cfg.gemini_api_key,
    }

    try:
        with httpx.Client(timeout=30) as client:
            resp = client.post(url, json=payload, headers=headers)

        if resp.status_code != 200:
            logger.error(
                "Gemini API error %d: %s", resp.status_code, resp.text[:300]
            )
            return {
                "answer" : "AI service temporarily unavailable. Please try again.",
                "sources": [],
            }

        data = resp.json()
        answer = (
            data.get("candidates", [{}])[0]
                .get("content", {})
                .get("parts", [{}])[0]
                .get("text", "Unable to generate response.")
        )

    except Exception as exc:
        logger.error("Gemini API request failed: %s", exc)
        return {
            "answer" : "AI service temporarily unavailable. Please try again.",
            "sources": [],
        }

    # Build deduplicated source citations (filename + page, unique pairs)
    seen  = set()
    sources = []
    for chunk in chunks:
        key = (chunk["filename"], chunk["page_number"])
        if key not in seen:
            seen.add(key)
            sources.append(
                {"filename": chunk["filename"], "page": chunk["page_number"]}
            )

    return {"answer": answer.strip(), "sources": sources}
