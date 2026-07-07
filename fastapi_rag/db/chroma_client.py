"""
db/chroma_client.py
Singleton ChromaDB persistent client + collection accessor.

Uses cosine similarity so retrieval scores are comparable across documents.
Stores embeddings + metadata. Text retrieval is done from MySQL rag_chunks.
"""

import logging
import chromadb
from chromadb.config import Settings as ChromaSettings
from settings import get_settings

logger = logging.getLogger(__name__)

_client: chromadb.ClientAPI | None = None


def get_client() -> chromadb.ClientAPI:
    """Return (or lazily create) the persistent ChromaDB client."""
    global _client
    if _client is None:
        cfg = get_settings()
        _client = chromadb.PersistentClient(
            path=cfg.chroma_path,
            settings=ChromaSettings(anonymized_telemetry=False),
        )
        logger.info("ChromaDB client ready at: %s", cfg.chroma_path)
    return _client


def get_collection() -> chromadb.Collection:
    """
    Get or create the main ERP documents collection (cosine distance).
    """
    cfg = get_settings()
    client = get_client()
    collection = client.get_or_create_collection(
        name=cfg.chroma_collection,
        metadata={"hnsw:space": "cosine"},
    )
    return collection


def upsert_embeddings(chunk_embeddings: list[dict]):
    """
    Store embeddings into ChromaDB.

    Args:
        chunk_embeddings: list of {chunk_id, document_id, embedding (list[float])}
    """
    if not chunk_embeddings:
        return

    collection = get_collection()

    ids        = [c["chunk_id"]    for c in chunk_embeddings]
    embeddings = [c["embedding"]   for c in chunk_embeddings]
    metadatas  = [{"document_id": c["document_id"]} for c in chunk_embeddings]

    collection.upsert(
        ids=ids,
        embeddings=embeddings,
        metadatas=metadatas,
    )
    logger.info("Upserted %d embeddings into ChromaDB.", len(ids))


def delete_document_embeddings(document_id: str) -> int:
    """
    Remove all embeddings for a given document_id from ChromaDB.
    Returns number of deleted entries.
    """
    collection = get_collection()
    try:
        existing = collection.get(
            where={"document_id": document_id},
            include=[],
        )
        if existing["ids"]:
            collection.delete(ids=existing["ids"])
            logger.info("Deleted %d embeddings from ChromaDB for doc %s.",
                        len(existing["ids"]), document_id)
            return len(existing["ids"])
        return 0
    except Exception as exc:
        logger.error("ChromaDB delete error: %s", exc)
        return 0


def similarity_search(query_embedding: list, top_k: int = 5) -> list[dict]:
    """
    Query ChromaDB for most similar chunks using cosine similarity.

    Returns:
        List of {chunk_id, document_id, score} sorted by relevance.
        Score is converted from ChromaDB distance (lower=better) to
        similarity (higher=better) as: similarity = 1 - distance
    """
    collection = get_collection()

    if collection.count() == 0:
        logger.warning("ChromaDB collection is empty — no embeddings stored yet.")
        return []

    results = collection.query(
        query_embeddings=[query_embedding],
        n_results=min(top_k, collection.count()),
        include=["metadatas", "distances"],
    )

    hits = []
    ids       = results["ids"][0]
    distances = results["distances"][0]
    metas     = results["metadatas"][0]

    for chunk_id, distance, meta in zip(ids, distances, metas):
        similarity = round(1.0 - distance, 4)   # cosine distance → similarity
        hits.append({
            "chunk_id"   : chunk_id,
            "document_id": meta.get("document_id", ""),
            "score"      : similarity,
        })

    return hits
