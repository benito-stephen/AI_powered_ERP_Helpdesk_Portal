"""
services/embedder.py
Embedding generation using local SentenceTransformer.
Storage: ChromaDB (primary vector store) for semantic search.

No API key required — runs entirely on local CPU.
"""

import logging
from functools import lru_cache

logger = logging.getLogger(__name__)


@lru_cache(maxsize=1)
def get_embedding_model():
    """Load and cache the SentenceTransformer model once at startup."""
    from sentence_transformers import SentenceTransformer
    model_name = "all-MiniLM-L6-v2"
    logger.info("Loading local embedding model: %s", model_name)
    model = SentenceTransformer(model_name)
    logger.info("Embedding model loaded. Vector dim: 384")
    return model


def _embed_text(text: str) -> list[float]:
    """Generate a single normalized embedding vector for a text string."""
    model = get_embedding_model()
    vector = model.encode(text, normalize_embeddings=True)
    return vector.tolist()


def _embed_batch(texts: list[str]) -> list[list[float]]:
    """Batch encode multiple texts into normalized embedding vectors."""
    model = get_embedding_model()
    vectors = model.encode(texts, normalize_embeddings=True, batch_size=32)
    return [v.tolist() for v in vectors]


def embed_and_store(chunks: list[dict]) -> int:
    """
    Generate local embeddings for document chunks and store in ChromaDB.

    Args:
        chunks: list of chunk dicts from chunker.chunk_pages()
                Each dict has: chunk_id, document_id, chunk_text, ...

    Returns:
        Number of embeddings stored.
    """
    if not chunks:
        return 0

    texts = [c["chunk_text"] for c in chunks]
    logger.info("Encoding %d chunks locally using SentenceTransformer ...", len(texts))

    embeddings = _embed_batch(texts)

    chunk_embeddings = [
        {
            "chunk_id"   : chunk["chunk_id"],
            "document_id": chunk["document_id"],
            "embedding"  : vec,
        }
        for chunk, vec in zip(chunks, embeddings)
    ]

    # Primary store: ChromaDB
    from db.chroma_client import upsert_embeddings as chroma_upsert
    chroma_upsert(chunk_embeddings)
    logger.info("Stored %d embeddings in ChromaDB.", len(chunk_embeddings))

    return len(chunk_embeddings)
