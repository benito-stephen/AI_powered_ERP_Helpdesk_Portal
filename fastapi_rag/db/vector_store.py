"""
db/vector_store.py
Pure-MySQL vector store — replaces ChromaDB entirely.

Embeddings are stored as JSON arrays in the rag_embeddings table.
Cosine similarity is computed in pure Python (no numpy needed).

Schema (add to rag_database_setup.sql):
    CREATE TABLE IF NOT EXISTS rag_embeddings (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        chunk_id     VARCHAR(36) NOT NULL UNIQUE,
        document_id  VARCHAR(36) NOT NULL,
        embedding    MEDIUMTEXT  NOT NULL,   -- JSON array of floats
        INDEX idx_doc (document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""

import json
import math
import logging
from db.mysql_client import execute_query, execute_many

logger = logging.getLogger(__name__)


# ── Pure-Python cosine similarity ────────────────────────────────────────────

def _dot(a: list, b: list) -> float:
    return sum(x * y for x, y in zip(a, b))


def _norm(a: list) -> float:
    return math.sqrt(sum(x * x for x in a))


def cosine_similarity(a: list, b: list) -> float:
    denom = _norm(a) * _norm(b)
    if denom == 0:
        return 0.0
    return _dot(a, b) / denom


# ── Storage ───────────────────────────────────────────────────────────────────

def upsert_embeddings(chunk_embeddings: list[dict]):
    """
    Store embeddings in rag_embeddings table.

    Args:
        chunk_embeddings: list of {chunk_id, document_id, embedding (list[float])}
    """
    if not chunk_embeddings:
        return

    sql = """
        INSERT INTO rag_embeddings (chunk_id, document_id, embedding)
        VALUES (%s, %s, %s)
        ON DUPLICATE KEY UPDATE embedding = VALUES(embedding)
    """
    rows = [
        (c["chunk_id"], c["document_id"], json.dumps(c["embedding"]))
        for c in chunk_embeddings
    ]
    execute_many(sql, rows)
    logger.info("Upserted %d embeddings into rag_embeddings.", len(rows))


def delete_document_embeddings(document_id: str) -> int:
    """Remove all embeddings for a document (used when re-processing)."""
    rows = execute_query(
        "SELECT COUNT(*) AS cnt FROM rag_embeddings WHERE document_id = %s",
        (document_id,),
        fetch=True,
    )
    count = rows[0]["cnt"] if rows else 0
    execute_query(
        "DELETE FROM rag_embeddings WHERE document_id = %s",
        (document_id,)
    )
    logger.info("Deleted %d embeddings for document %s.", count, document_id)
    return count


# ── Retrieval ─────────────────────────────────────────────────────────────────

def similarity_search(query_embedding: list, top_k: int = 5, threshold: float = 0.35) -> list[dict]:
    """
    Compute cosine similarity between query_embedding and all stored embeddings.
    Returns top-K results above threshold, sorted by score descending.

    Returns list of: {chunk_id, document_id, score}
    """
    rows = execute_query(
        "SELECT chunk_id, document_id, embedding FROM rag_embeddings",
        fetch=True,
    )

    if not rows:
        return []

    scored = []
    for row in rows:
        try:
            vec = json.loads(row["embedding"])
            score = cosine_similarity(query_embedding, vec)
            if score >= threshold:
                scored.append({
                    "chunk_id"   : row["chunk_id"],
                    "document_id": row["document_id"],
                    "score"      : round(score, 4),
                })
        except Exception:
            continue  # skip corrupt rows

    scored.sort(key=lambda x: x["score"], reverse=True)
    return scored[:top_k]
