"""
services/retriever.py
Semantic retrieval using ChromaDB vector search + SentenceTransformer query embeddings.

At query time ONLY:
  1. Embed the user question via local SentenceTransformer (no API call)
  2. Check HR override collection first — if a high-confidence override exists,
     return it immediately (source_type = "override")
  3. Otherwise query main ChromaDB collection with cosine similarity
  4. Fetch chunk_text + metadata from MySQL for matched chunk IDs
  5. Return ranked list of context chunks (source_type = "document")

No re-extraction, no re-chunking, no re-embedding of documents.
"""

import logging
from settings import get_settings

logger = logging.getLogger(__name__)


def retrieve_override(question: str, query_vec: list) -> dict | None:
    """
    Search the HR overrides collection for a matching approved response.

    Returns a dict with keys:
        override_id, override_response, query, score
    or None if no override is above threshold.
    """
    from db.chroma_client import similarity_search_overrides
    from db.mysql_client import execute_query

    hits = similarity_search_overrides(query_vec, top_k=1)
    if not hits:
        return None

    best = hits[0]
    cfg = get_settings()

    # Must beat threshold to be used
    if best["score"] < cfg.similarity_threshold:
        return None

    # Fetch the actual override text from MySQL
    row = execute_query(
        "SELECT query, override_response FROM erp_portal.ai_overrides WHERE override_id = %s LIMIT 1",
        (best["override_id"],),
        fetch=True,
    )
    if not row:
        return None

    return {
        "override_id"      : best["override_id"],
        "override_response": row[0]["override_response"],
        "query"            : row[0]["query"],
        "score"            : best["score"],
        "log_id"           : best["log_id"],
    }


def retrieve_context(question: str) -> list[dict]:
    """
    Retrieve the most relevant document chunks for a question.

    Returns:
        [
          {
            "chunk_id"   : str,
            "chunk_text" : str,
            "filename"   : str,
            "page_number": int,
            "score"      : float,
            "document_id": str,
          },
          ...
        ]
        Empty list if no relevant chunks found above threshold.
    """
    cfg = get_settings()

    # Step 1 — Embed the question locally
    from services.embedder import _embed_text
    try:
        query_vec = _embed_text(question)
    except Exception as exc:
        logger.error("Failed to embed query: %s", exc)
        return []

    # Step 2 — Query ChromaDB for top-K similar chunks
    from db.chroma_client import similarity_search
    candidates = similarity_search(query_vec, top_k=cfg.top_k_chunks)

    # Filter by similarity threshold
    candidates = [c for c in candidates if c["score"] >= cfg.similarity_threshold]

    if not candidates:
        logger.info(
            "No chunks above threshold (%.2f) for: '%s...'",
            cfg.similarity_threshold, question[:60]
        )
        return []

    # Step 3 — Fetch chunk text + metadata + page_offset from MySQL
    from db.mysql_client import execute_query
    chunk_ids    = tuple(c["chunk_id"] for c in candidates)
    score_map    = {c["chunk_id"]: c["score"] for c in candidates}
    placeholders = ", ".join(["%s"] * len(chunk_ids))

    rows = execute_query(
        f"SELECT rc.chunk_id, rc.chunk_text, rc.filename, rc.page_number, rc.document_id, rd.page_offset "
        f"FROM rag_chunks rc "
        f"LEFT JOIN rag_documents rd ON rc.document_id = rd.document_id "
        f"WHERE rc.chunk_id IN ({placeholders})",
        chunk_ids,
        fetch=True,
    )

    # Build result preserving ChromaDB score order
    text_map = {r["chunk_id"]: r for r in rows}
    results  = []
    for cand in candidates:
        row = text_map.get(cand["chunk_id"])
        if row:
            page_num = row["page_number"]
            page_offset = row.get("page_offset") or 0
            physical_page = max(1, page_num - page_offset)
            results.append({
                "chunk_id"   : cand["chunk_id"],
                "chunk_text" : row["chunk_text"],
                "filename"   : row["filename"],
                "page_number": page_num,
                "physical_page_number": physical_page,
                "document_id": row.get("document_id", ""),
                "score"      : score_map[cand["chunk_id"]],
            })

    logger.info(
        "Retrieved %d chunk(s) from ChromaDB for: '%s...'",
        len(results), question[:60]
    )
    return results
