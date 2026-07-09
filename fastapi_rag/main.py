"""
main.py — FastAPI RAG Microservice Entry Point
Embeddings: local SentenceTransformer (no API key needed)
Vector store: MySQL rag_embeddings table

Run: double-click start.bat  OR
     python -m uvicorn main:app --host 127.0.0.1 --port 8000 --reload
"""

import logging
import sys
from pathlib import Path
from contextlib import asynccontextmanager
from logging.handlers import RotatingFileHandler

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

sys.path.insert(0, str(Path(__file__).parent))

from routers import upload, process, chat, status, override
from settings import get_settings

# ── Logging setup ─────────────────────────────────────────────────────────────
LOG_DIR  = Path(__file__).parent / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = LOG_DIR / "rag_service.log"

LOG_FMT  = "%(asctime)s  %(levelname)-8s  %(name)s – %(message)s"
LOG_DATE = "%Y-%m-%d %H:%M:%S"

_formatter = logging.Formatter(LOG_FMT, datefmt=LOG_DATE)

# Console handler
_console = logging.StreamHandler(sys.stdout)
_console.setFormatter(_formatter)

# Rotating file handler – max 5 MB per file, keep 5 backups
_file_handler = RotatingFileHandler(
    LOG_FILE, maxBytes=5 * 1024 * 1024, backupCount=5, encoding="utf-8"
)
_file_handler.setFormatter(_formatter)

logging.basicConfig(level=logging.INFO, handlers=[_console, _file_handler])
logger = logging.getLogger("rag_service")


@asynccontextmanager
async def lifespan(app: FastAPI):
    cfg = get_settings()
    logger.info("=" * 60)
    logger.info("ERP RAG Microservice starting ...")
    logger.info("DB      : %s @ %s:%s", cfg.rag_db_name, cfg.rag_db_host, cfg.rag_db_port)
    logger.info("Embeds  : local SentenceTransformer (%s)", cfg.embedding_model)

    Path(cfg.uploads_dir).mkdir(parents=True, exist_ok=True)

    # Verify MySQL connection
    try:
        from db.mysql_client import execute_query
        execute_query("SELECT 1", fetch=True)
        logger.info("MySQL connection OK.")
    except Exception as exc:
        logger.error("MySQL connection FAILED: %s", exc)

    # Pre-warm local embedding model (avoids slow first request)
    try:
        from services.embedder import get_embedding_model
        get_embedding_model()
        logger.info("SentenceTransformer model loaded and ready.")
    except Exception as exc:
        logger.error("Failed to pre-load embedding model: %s", exc)

    # Pre-warm ChromaDB collection
    try:
        from db.chroma_client import get_collection, get_override_collection
        col = get_collection()
        logger.info("ChromaDB collection '%s' ready. Vectors stored: %d",
                    cfg.chroma_collection, col.count())
        ov_col = get_override_collection()
        logger.info("ChromaDB override collection 'hr_overrides' ready. Entries: %d", ov_col.count())
    except Exception as exc:
        logger.error("ChromaDB init failed: %s", exc)

    logger.info("RAG service READY on http://127.0.0.1:8000")
    logger.info("Docs: http://127.0.0.1:8000/docs")
    logger.info("=" * 60)

    yield

    logger.info("RAG service shutting down.")


app = FastAPI(
    title       = "ERP Helpdesk RAG Service",
    description = "Phase II RAG pipeline – local SentenceTransformer embeddings",
    version     = "2.2.0",
    lifespan    = lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins = ["http://localhost", "http://127.0.0.1"],
    allow_methods = ["GET", "POST"],
    allow_headers = ["*"],
)

app.include_router(upload.router,   tags=["Upload"])
app.include_router(process.router,  tags=["Process"])
app.include_router(chat.router,     tags=["Chat"])
app.include_router(status.router,   tags=["Status"])
app.include_router(override.router, tags=["Override"])


@app.get("/health", tags=["Health"])
def health():
    return {"status": "ok", "version": "2.2.0"}
