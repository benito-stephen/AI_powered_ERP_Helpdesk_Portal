"""
settings.py
Central configuration loader using python-dotenv.
All settings are read from .env (never hardcoded).
"""

import os
from functools import lru_cache
from dotenv import load_dotenv

load_dotenv(override=False)


class Settings:
    # Gemini (chat only — embeddings are generated locally via SentenceTransformer)
    gemini_api_key: str = os.getenv("GEMINI_API_KEY", "")
    gemini_model: str   = os.getenv("GEMINI_MODEL", "gemini-2.5-flash")

    # Local embedding model (SentenceTransformer)
    embedding_model: str = os.getenv("EMBEDDING_MODEL", "all-MiniLM-L6-v2")

    # ChromaDB persistent storage
    chroma_path: str       = os.getenv("CHROMA_PATH", "./chromadb")
    chroma_collection: str = os.getenv("CHROMA_COLLECTION", "erp_documents")

    # MySQL – RAG DB
    rag_db_host: str  = os.getenv("RAG_DB_HOST", "localhost")
    rag_db_port: int  = int(os.getenv("RAG_DB_PORT", "3307"))
    rag_db_name: str  = os.getenv("RAG_DB_NAME", "erp_rag")
    rag_db_user: str  = os.getenv("RAG_DB_USER", "root")
    rag_db_password: str = os.getenv("RAG_DB_PASSWORD", "")

    # File storage
    uploads_dir: str      = os.getenv("UPLOADS_DIR", "./uploads")
    max_file_size_mb: int = int(os.getenv("MAX_FILE_SIZE_MB", "20"))

    # Chunking
    chunk_size: int    = int(os.getenv("CHUNK_SIZE", "800"))
    chunk_overlap: int = int(os.getenv("CHUNK_OVERLAP", "150"))

    # Retrieval
    top_k_chunks: int          = int(os.getenv("TOP_K_CHUNKS", "5"))
    similarity_threshold: float = float(os.getenv("SIMILARITY_THRESHOLD", "0.35"))


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
