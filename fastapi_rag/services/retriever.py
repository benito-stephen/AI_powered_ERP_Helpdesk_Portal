"""
services/retriever.py
Semantic retrieval using ChromaDB vector search + SentenceTransformer query embeddings.

At query time ONLY:
  1. Embed the user question via local SentenceTransformer (no API call)
  2. Query ChromaDB collection with cosine similarity
  3. Fetch chunk_text + metadata from MySQL for matched chunk IDs
  4. Return ranked list of context chunks

No re-extraction, no re-chunking, no re-embedding of documents.
"""

import logging
from settings import get_settings

logger = logging.getLogger(__name__)


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

    # Step 3 — Fetch chunk text + metadata from MySQL
    from db.mysql_client import execute_query
    chunk_ids    = tuple(c["chunk_id"] for c in candidates)
    score_map    = {c["chunk_id"]: c["score"] for c in candidates}
    placeholders = ", ".join(["%s"] * len(chunk_ids))

    rows = execute_query(
        f"SELECT chunk_id, chunk_text, filename, page_number "
        f"FROM rag_chunks WHERE chunk_id IN ({placeholders})",
        chunk_ids,
        fetch=True,
    )

    # Build result preserving ChromaDB score order
    text_map = {r["chunk_id"]: r for r in rows}
    results  = []
    for cand in candidates:
        row = text_map.get(cand["chunk_id"])
        if row:
            results.append({
                "chunk_id"   : cand["chunk_id"],
                "chunk_text" : row["chunk_text"],
                "filename"   : row["filename"],
                "page_number": row["page_number"],
                "score"      : score_map[cand["chunk_id"]],
            })

    logger.info(
        "Retrieved %d chunk(s) from ChromaDB for: '%s...'",
        len(results), question[:60]
    )
    return results
